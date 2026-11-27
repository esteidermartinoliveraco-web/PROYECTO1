<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\CompatibilidadService;
use App\Services\NotificacionService;
use App\Utils\Response;
use DateTimeImmutable;
use PDO;
use Throwable;

final class AlertaController
{
    private PDO $db;
    private CompatibilidadService $compatibilidad;
    private NotificacionService $notificacion;

    private const RESPUESTAS_VALIDAS = ['ACEPTADA_DIRIGIDA', 'ACEPTADA_BANCO', 'RECHAZADA'];

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->compatibilidad = new CompatibilidadService();
        $this->notificacion = new NotificacionService();
    }

    /**
     * POST /api/alertas/disparar/{solicitudId}
     * Filtra donantes activos compatibles y habilitados, registra alertas y envía notificaciones.
     */
    public function dispararParaSolicitud(string $solicitudId): never
    {
        $body = Response::getJsonBody();
        $zonaFiltro = !empty($body['zona_geografica']) ? trim((string) $body['zona_geografica']) : null;
        $limiteEnvios = min(max((int) ($body['limite'] ?? 50), 1), 200);

        // 1. Obtener datos de la solicitud y del paciente
        $stmtSol = $this->db->prepare("
            SELECT s.id, s.codigo_solicitud, s.hospital_clinica, s.tipo_hemocomponente,
                   s.unidades_requeridas, s.unidades_cubiertas, s.nivel_urgencia, s.estado,
                   p.grupo_sanguineo AS paciente_grupo
            FROM solicitudes_sangre s
            INNER JOIN pacientes p ON p.id = s.paciente_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmtSol->execute([':id' => $solicitudId]);
        $solicitud = $stmtSol->fetch();

        if (!$solicitud) {
            Response::error('Solicitud de sangre no encontrada.', 404);
        }

        if (in_array($solicitud['estado'], ['CUBIERTA', 'CANCELADA'], true)) {
            Response::error("No se pueden emitir alertas para una solicitud en estado {$solicitud['estado']}.", 409);
        }

        // 2. Obtener grupos compatibles según la matriz ABO/Rh
        $gruposCompatibles = $this->compatibilidad->obtenerDonantesCompatibles(
            $solicitud['paciente_grupo'],
            $solicitud['tipo_hemocomponente']
        );
        $placeholders = implode(',', array_fill(0, count($gruposCompatibles), '?'));

        // 3. Buscar donantes ACTIVOS, compatibles, con descanso cumplido y que no hayan sido alertados aún por esta solicitud
        $sqlDonantes = "
            SELECT d.id, d.nombres, d.apellidos, d.grupo_sanguineo, d.telefono_whatsapp, d.email, d.zona_geografica
            FROM donantes d
            WHERE d.estado = 'ACTIVO'
              AND d.grupo_sanguineo IN ({$placeholders})
              AND (d.fecha_habilitacion_proxima IS NULL OR d.fecha_habilitacion_proxima <= CURDATE())
              AND NOT EXISTS (
                  SELECT 1 FROM alertas_donantes a
                  WHERE a.solicitud_id = ? AND a.donante_id = d.id
              )
        ";

        $params = array_merge($gruposCompatibles, [$solicitudId]);

        if ($zonaFiltro !== null) {
            $sqlDonantes .= " AND d.zona_geografica = ?";
            $params[] = $zonaFiltro;
        }

        // Priorizar donantes del mismo grupo exacto del paciente y luego donantes universales
        $sqlDonantes .= " ORDER BY (d.grupo_sanguineo = ?) DESC, d.fecha_ultima_donacion ASC LIMIT {$limiteEnvios}";
        $params[] = $solicitud['paciente_grupo'];

        $stmtDon = $this->db->prepare($sqlDonantes);
        $stmtDon->execute($params);
        $donantesAptos = $stmtDon->fetchAll();

        if (empty($donantesAptos)) {
            Response::success([
                'mensaje'          => 'No se encontraron nuevos donantes activos y compatibles disponibles para notificar.',
                'alertas_enviadas' => 0
            ]);
        }

        $enviadas = 0;
        $enlacesGenerados = [];

        try {
            $this->db->beginTransaction();

            $stmtInsAlerta = $this->db->prepare("
                INSERT INTO alertas_donantes (id, solicitud_id, donante_id, canal_envio, estado, fecha_envio)
                VALUES (:id, :solicitud_id, :donante_id, :canal, 'ENVIADA', NOW())
            ");

            foreach ($donantesAptos as $donante) {
                $alertaId = $this->generarUuidV4();
                $canal = !empty($donante['telefono_whatsapp']) ? 'WHATSAPP' : 'EMAIL';

                $stmtInsAlerta->execute([
                    ':id'           => $alertaId,
                    ':solicitud_id' => $solicitudId,
                    ':donante_id'   => $donante['id'],
                    ':canal'        => $canal
                ]);

                // Despachar notificación por los canales disponibles
                if (!empty($donante['telefono_whatsapp'])) {
                    $this->notificacion->enviarAlertaWhatsApp($donante, $solicitud, $alertaId);
                }
                if (!empty($donante['email'])) {
                    $this->notificacion->enviarAlertaEmail($donante, $solicitud, $alertaId);
                }

                $enviadas++;
                $enlacesGenerados[] = [
                    'donante' => "{$donante['nombres']} {$donante['apellidos']}",
                    'grupo'   => $donante['grupo_sanguineo'],
                    'enlace'  => $this->notificacion->construirEnlaceRespuesta($alertaId)
                ];
            }

            // Actualizar estado de la solicitud a EN_BUSQUEDA si estaba PENDIENTE
            if ($solicitud['estado'] === 'PENDIENTE') {
                $stmtUpdSol = $this->db->prepare("UPDATE solicitudes_sangre SET estado = 'EN_BUSQUEDA' WHERE id = :id");
                $stmtUpdSol->execute([':id' => $solicitudId]);
            }

            $this->db->commit();

            Response::success([
                'mensaje'          => "Se enviaron {$enviadas} alertas a donantes compatibles.",
                'alertas_enviadas' => $enviadas,
                'detalle_envios'   => $enlacesGenerados
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * POST /api/alertas/{alertaId}/responder
     * Endpoint público: el donante acepta (dirigida al paciente o al banco) o rechaza la alerta.
     */
    public function responderAlerta(string $alertaId): never
    {
        $body = Response::getJsonBody();
        $respuesta     = strtoupper(trim((string) ($body['respuesta'] ?? '')));
        $citaProgramada = !empty($body['cita_programada']) ? trim((string) $body['cita_programada']) : null;

        if (!in_array($respuesta, self::RESPUESTAS_VALIDAS, true)) {
            Response::error('Respuesta no válida. Debe ser ACEPTADA_DIRIGIDA, ACEPTADA_BANCO o RECHAZADA.', 400);
        }

        if ($respuesta !== 'RECHAZADA') {
            if ($citaProgramada === null || !DateTimeImmutable::createFromFormat('Y-m-d H:i', $citaProgramada)) {
                Response::error('Debe indicar la fecha y hora estimada de asistencia (formato YYYY-MM-DD HH:MM).', 400);
            }
        }

        $stmt = $this->db->prepare("
            SELECT a.id, a.estado, a.solicitud_id, s.codigo_solicitud, s.estado AS estado_solicitud
            FROM alertas_donantes a
            INNER JOIN solicitudes_sangre s ON s.id = a.solicitud_id
            WHERE a.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $alertaId]);
        $alerta = $stmt->fetch();

        if (!$alerta) {
            Response::error('La convocatoria o alerta indicada no existe.', 404);
        }

        if (in_array($alerta['estado'], self::RESPUESTAS_VALIDAS, true)) {
            Response::error("Esta alerta ya fue respondida anteriormente con estado: {$alerta['estado']}.", 409);
        }

        $stmtUpd = $this->db->prepare("
            UPDATE alertas_donantes
            SET estado = :estado,
                fecha_respuesta = NOW(),
                cita_programada = :cita
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':estado' => $respuesta,
            ':cita'   => $respuesta !== 'RECHAZADA' ? ($citaProgramada . ':00') : null,
            ':id'     => $alertaId
        ]);

        $mensajeConfirmacion = match ($respuesta) {
            'ACEPTADA_DIRIGIDA' => "Gracias. Tu cita quedó vinculada directamente al paciente de la solicitud {$alerta['codigo_solicitud']}.",
            'ACEPTADA_BANCO'    => 'Gracias. Tu cita quedó agendada como donación voluntaria para el depósito general del banco de sangre.',
            default             => 'Hemos registrado tu respuesta. Gracias por mantenerte en nuestra red de donantes.'
        };

        Response::success([
            'mensaje'          => $mensajeConfirmacion,
            'estado_alerta'    => $respuesta,
            'codigo_solicitud' => $alerta['codigo_solicitud'],
            'cita_programada'  => $citaProgramada
        ]);
    }

    /**
     * GET /api/stream
     * Transmisión en tiempo real (Server-Sent Events) para el panel del Supervisor y Recepción.
     */
    public function streamEventos(): never
    {
        // Crítico en PHP: liberar el bloqueo del archivo de sesión para no congelar otras peticiones del usuario
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Asegura que Nginx no retenga el búfer

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');

        $ultimaMarcaTiempo = date('Y-m-d H:i:s', strtotime('-5 seconds'));
        $inicioCiclo = time();
        $duracionMaximaSegundos = 25; // Reconexión automática limpia gestionada por EventSource en el navegador

        while ((time() - $inicioCiclo) < $duracionMaximaSegundos) {
            if (connection_aborted()) {
                break;
            }

            // 1. Consultar donantes que acaban de aceptar una alerta
            $stmtResp = $this->db->prepare("
                SELECT a.id AS alerta_id, a.estado, a.cita_programada, a.fecha_respuesta,
                       d.nombres, d.apellidos, d.grupo_sanguineo,
                       s.codigo_solicitud
                FROM alertas_donantes a
                INNER JOIN donantes d ON d.id = a.donante_id
                INNER JOIN solicitudes_sangre s ON s.id = a.solicitud_id
                WHERE a.estado IN ('ACEPTADA_DIRIGIDA', 'ACEPTADA_BANCO')
                  AND a.fecha_respuesta > :desde
                ORDER BY a.fecha_respuesta ASC
            ");
            $stmtResp->execute([':desde' => $ultimaMarcaTiempo]);
            $nuevasRespuestas = $stmtResp->fetchAll();

            // 2. Consultar nuevas solicitudes urgentes o de emergencia vital
            $stmtSol = $this->db->prepare("
                SELECT s.id, s.codigo_solicitud, s.nivel_urgencia, s.tipo_hemocomponente, s.unidades_requeridas,
                       p.grupo_sanguineo, s.created_at
                FROM solicitudes_sangre s
                INNER JOIN pacientes p ON p.id = s.paciente_id
                WHERE s.created_at > :desde
                ORDER BY s.created_at ASC
            ");
            $stmtSol->execute([':desde' => $ultimaMarcaTiempo]);
            $nuevasSolicitudes = $stmtSol->fetchAll();

            $ultimaMarcaTiempo = date('Y-m-d H:i:s');

            if (!empty($nuevasRespuestas)) {
                echo "event: alerta_aceptada\n";
                echo 'data: ' . json_encode($nuevasRespuestas, JSON_UNESCAPED_UNICODE) . "\n\n";
            }

            if (!empty($nuevasSolicitudes)) {
                echo "event: nueva_solicitud\n";
                echo 'data: ' . json_encode($nuevasSolicitudes, JSON_UNESCAPED_UNICODE) . "\n\n";
            }

            // Heartbeat para mantener viva la conexión frente a proxies
            echo ": ping " . time() . "\n\n";

            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();

            sleep(3);
        }

        exit;
    }

    private function generarUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}