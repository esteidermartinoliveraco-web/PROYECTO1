<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\StorageService;
use App\Utils\Response;
use PDO;
use RuntimeException;
use Throwable;

final class EntrevistaController
{
    private PDO $db;
    private StorageService $storage;

    private const VINCULOS_VALIDOS = ['VOLUNTARIA_BANCO', 'REPOSICION_PACIENTE', 'DIRIGIDA_PACIENTE'];

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->storage = new StorageService();
    }

    /**
     * GET /api/entrevistas/pendientes
     * Bandeja de formularios capturados que esperan revisión del Supervisor Médico.
     */
    public function listarPendientes(): never
    {
        $stmt = $this->db->query("
            SELECT e.id, e.donante_id, e.tipo_vinculo, e.fecha_entrevista,
                   e.peso_registrado_kg, e.presion_arterial, e.hemoglobina_g_dl, e.temperatura_c,
                   e.tipo_archivo, (e.formulario_reverso_url IS NOT NULL) AS tiene_reverso,
                   d.documento_identidad, d.nombres, d.apellidos, d.sexo, d.grupo_sanguineo,
                   TIMESTAMPDIFF(YEAR, d.fecha_nacimiento, CURDATE()) AS edad,
                   s.codigo_solicitud,
                   u.nombre_completo AS capturado_por
            FROM entrevistas_predonacion e
            INNER JOIN donantes d ON d.id = e.donante_id
            INNER JOIN usuarios u ON u.id = e.capturado_por_id
            LEFT JOIN solicitudes_sangre s ON s.id = e.solicitud_asociada_id
            WHERE e.aprobada IS NULL
            ORDER BY e.fecha_entrevista ASC
        ");

        Response::success($stmt->fetchAll());
    }

    /**
     * POST /api/entrevistas
     * Recibe multipart/form-data: signos vitales + foto anverso (req) + foto reverso (opt).
     */
    public function registrarConFotos(): never
    {
        $usuario = $_SESSION['usuario'] ?? null;
        if (!$usuario) {
            Response::error('Debe iniciar sesión para registrar una entrevista.', 401);
        }

        $donanteId   = trim((string) ($_POST['donante_id'] ?? ''));
        $tipoVinculo = strtoupper(trim((string) ($_POST['tipo_vinculo'] ?? 'VOLUNTARIA_BANCO')));
        $solicitudId = !empty($_POST['solicitud_asociada_id']) ? trim((string) $_POST['solicitud_asociada_id']) : null;

        $pesoKg      = (float) ($_POST['peso_registrado_kg'] ?? 0);
        $presion     = trim((string) ($_POST['presion_arterial'] ?? ''));
        $hemoglobina = (float) ($_POST['hemoglobina_g_dl'] ?? 0);
        $temperatura = (float) ($_POST['temperatura_c'] ?? 0);

        if ($donanteId === '' || $presion === '') {
            Response::error('Faltan datos obligatorios (donante_id, presion_arterial).', 400);
        }

        if (!in_array($tipoVinculo, self::VINCULOS_VALIDOS, true)) {
            Response::error('Tipo de vínculo de donación no válido.', 400);
        }

        if ($pesoKg < 50.0) {
            Response::error("Peso registrado insuficiente ({$pesoKg} kg). Mínimo requerido: 50.0 kg.", 422);
        }

        if ($hemoglobina < 11.0 || $hemoglobina > 20.0) {
            Response::error("Valor de hemoglobina fuera de rango plausible ({$hemoglobina} g/dL).", 422);
        }

        if ($temperatura < 35.0 || $temperatura > 38.5) {
            Response::error("Temperatura registrada fuera de rango ({$temperatura} °C).", 422);
        }

        if (!isset($_FILES['anverso'])) {
            Response::error('Debe adjuntar la fotografía o PDF del anverso del formulario firmado.', 400);
        }

        // Verificar existencia del donante y que no esté bloqueado permanentemente
        $stmtDon = $this->db->prepare("SELECT id, estado FROM donantes WHERE id = :id LIMIT 1");
        $stmtDon->execute([':id' => $donanteId]);
        $donante = $stmtDon->fetch();

        if (!$donante) {
            Response::error('El donante indicado no existe.', 404);
        }

        if ($donante['estado'] === 'INACTIVO_PERMANENTE') {
            Response::error('El donante tiene un diferimiento permanente y no puede iniciar entrevistas.', 403);
        }

        $archivoAnverso = null;
        $archivoReverso = null;

        try {
            $archivoAnverso = $this->storage->guardarArchivo($_FILES['anverso'], 'formularios');

            if (isset($_FILES['reverso']) && ($_FILES['reverso']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $archivoReverso = $this->storage->guardarArchivo($_FILES['reverso'], 'formularios');
            }

            // Si hay anverso y reverso, combinar ambos hashes para sello único de integridad
            $hashIntegridad = $archivoReverso !== null
                ? hash('sha256', $archivoAnverso['sha256'] . ':' . $archivoReverso['sha256'])
                : $archivoAnverso['sha256'];

            $this->db->beginTransaction();

            $entrevistaId = $this->generarUuidV4();
            $stmtIns = $this->db->prepare("
                INSERT INTO entrevistas_predonacion (
                    id, donante_id, capturado_por_id, solicitud_asociada_id, tipo_vinculo,
                    peso_registrado_kg, presion_arterial, hemoglobina_g_dl, temperatura_c,
                    formulario_anverso_url, formulario_reverso_url, tipo_archivo, integridad_sha256
                ) VALUES (
                    :id, :donante_id, :capturado_por, :solicitud_id, :vinculo,
                    :peso, :presion, :hb, :temp,
                    :anverso_url, :reverso_url, :tipo_archivo, :sha256
                )
            ");
            $stmtIns->execute([
                ':id'            => $entrevistaId,
                ':donante_id'    => $donanteId,
                ':capturado_por' => $usuario['id'],
                ':solicitud_id'  => $solicitudId,
                ':vinculo'       => $tipoVinculo,
                ':peso'          => $pesoKg,
                ':presion'       => $presion,
                ':hb'            => $hemoglobina,
                ':temp'          => $temperatura,
                ':anverso_url'   => $archivoAnverso['ruta_relativa'],
                ':reverso_url'   => $archivoReverso['ruta_relativa'] ?? null,
                ':tipo_archivo'  => $archivoAnverso['tipo_enum'],
                ':sha256'        => $hashIntegridad
            ]);

            // Actualizar último peso conocido del donante
            $stmtUpdPeso = $this->db->prepare("UPDATE donantes SET peso_kg = :peso WHERE id = :id");
            $stmtUpdPeso->execute([':peso' => $pesoKg, ':id' => $donanteId]);

            $this->db->commit();

            Response::success([
                'mensaje'       => 'Formulario pre-donación capturado y enviado a revisión del Supervisor.',
                'entrevista_id' => $entrevistaId,
                'sha256'        => $hashIntegridad
            ], 201);
        } catch (RuntimeException $e) {
            if ($archivoAnverso) $this->storage->eliminarArchivo($archivoAnverso['ruta_relativa']);
            if ($archivoReverso) $this->storage->eliminarArchivo($archivoReverso['ruta_relativa']);
            Response::error($e->getMessage(), 400);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($archivoAnverso) $this->storage->eliminarArchivo($archivoAnverso['ruta_relativa']);
            if ($archivoReverso) $this->storage->eliminarArchivo($archivoReverso['ruta_relativa']);
            throw $e;
        }
    }

    /**
     * GET /api/entrevistas/{id}/foto/{lado}
     * Sirve de forma segura la imagen del anverso o reverso al visor del Supervisor.
     */
    public function verFotoProtegida(string $id, string $lado): never
    {
        if (!in_array($lado, ['anverso', 'reverso'], true)) {
            Response::error('Lado del formulario no válido (use anverso o reverso).', 400);
        }

        $columna = $lado === 'anverso' ? 'formulario_anverso_url' : 'formulario_reverso_url';
        $stmt = $this->db->prepare("SELECT {$columna} AS ruta FROM entrevistas_predonacion WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $fila = $stmt->fetch();

        if (!$fila || empty($fila['ruta'])) {
            Response::error('La imagen solicitada no existe para esta entrevista.', 404);
        }

        $this->storage->transmitirArchivo($fila['ruta']);
    }

    /**
     * PUT /api/entrevistas/{id}/evaluar
     * Dictamen del Supervisor Médico: aprueba (habilita donante) o rechaza (pausa o inactiva).
     */
    public function evaluar(string $id): never
    {
        $supervisorId = $_SESSION['usuario']['id'] ?? null;
        if (!$supervisorId) {
            Response::error('No autorizado.', 401);
        }

        $body = Response::getJsonBody();
        $aprobada      = isset($body['aprobada']) ? (bool) $body['aprobada'] : null;
        $motivoRechazo = !empty($body['motivo_rechazo']) ? trim((string) $body['motivo_rechazo']) : null;
        $observaciones = !empty($body['observaciones_evaluador']) ? trim((string) $body['observaciones_evaluador']) : null;
        $accionEstado  = strtoupper(trim((string) ($body['nuevo_estado_donante'] ?? ''))); // ACTIVO, EN_PAUSA, INACTIVO_PERMANENTE
        $fechaFinPausa = !empty($body['fecha_fin_estimada_pausa']) ? trim((string) $body['fecha_fin_estimada_pausa']) : null;

        if ($aprobada === null) {
            Response::error('Debe especificar el dictamen (aprobada: true o false).', 400);
        }

        if (!$aprobada && ($motivoRechazo === null || $motivoRechazo === '')) {
            Response::error('Debe indicar el motivo clínico del rechazo.', 400);
        }

        try {
            $this->db->beginTransaction();

            $stmtLock = $this->db->prepare("
                SELECT e.id, e.donante_id, e.aprobada, d.estado AS estado_donante_actual
                FROM entrevistas_predonacion e
                INNER JOIN donantes d ON d.id = e.donante_id
                WHERE e.id = :id
                FOR UPDATE
            ");
            $stmtLock->execute([':id' => $id]);
            $registro = $stmtLock->fetch();

            if (!$registro) {
                $this->db->rollBack();
                Response::error('Entrevista no encontrada.', 404);
            }

            if ($registro['aprobada'] !== null) {
                $this->db->rollBack();
                Response::error('Esta entrevista ya fue evaluada anteriormente.', 409);
            }

            // 1. Actualizar dictamen en entrevistas_predonacion
            $stmtUpdEnt = $this->db->prepare("
                UPDATE entrevistas_predonacion
                SET aprobada = :aprobada,
                    evaluador_id = :evaluador,
                    motivo_rechazo = :motivo,
                    observaciones_evaluador = :obs,
                    fecha_evaluacion = NOW()
                WHERE id = :id
            ");
            $stmtUpdEnt->execute([
                ':aprobada'  => $aprobada ? 1 : 0,
                ':evaluador' => $supervisorId,
                ':motivo'    => $aprobada ? null : $motivoRechazo,
                ':obs'       => $observaciones,
                ':id'        => $id
            ]);

            // 2. Determinar estado resultante del donante
            $estadoAnterior = $registro['estado_donante_actual'];
            $nuevoEstado = $aprobada
                ? 'ACTIVO'
                : (in_array($accionEstado, ['EN_PAUSA', 'INACTIVO_PERMANENTE'], true) ? $accionEstado : 'EN_PAUSA');

            $fechaHabilitacion = match ($nuevoEstado) {
                'ACTIVO'              => date('Y-m-d'),
                'EN_PAUSA'            => $fechaFinPausa ?: date('Y-m-d', strtotime('+15 days')),
                'INACTIVO_PERMANENTE' => null,
                default               => null
            };

            $stmtUpdDon = $this->db->prepare("
                UPDATE donantes
                SET estado = :estado,
                    fecha_habilitacion_proxima = :fecha_hab
                WHERE id = :donante_id
            ");
            $stmtUpdDon->execute([
                ':estado'     => $nuevoEstado,
                ':fecha_hab'  => $fechaHabilitacion,
                ':donante_id' => $registro['donante_id']
            ]);

            // 3. Registrar trazabilidad en el Kardex Médico del donante
            $motivoKardex = $aprobada
                ? 'Entrevista pre-donación aprobada por supervisión médica.'
                : "Entrevista rechazada: {$motivoRechazo}";

            $stmtKardex = $this->db->prepare("
                INSERT INTO kardex_historial_donante (
                    id, donante_id, supervisor_id, estado_anterior, estado_nuevo,
                    motivo_clinico, fecha_inicio_pausa, fecha_fin_estimada_pausa, observaciones_medicas
                ) VALUES (
                    :id, :donante_id, :supervisor_id, :est_ant, :est_nvo,
                    :motivo, :inicio_pausa, :fin_pausa, :obs
                )
            ");
            $stmtKardex->execute([
                ':id'            => $this->generarUuidV4(),
                ':donante_id'    => $registro['donante_id'],
                ':supervisor_id' => $supervisorId,
                ':est_ant'       => $estadoAnterior,
                ':est_nvo'       => $nuevoEstado,
                ':motivo'        => $motivoKardex,
                ':inicio_pausa'  => $nuevoEstado === 'EN_PAUSA' ? date('Y-m-d') : null,
                ':fin_pausa'     => $nuevoEstado === 'EN_PAUSA' ? $fechaHabilitacion : null,
                ':obs'           => $observaciones
            ]);

            $this->db->commit();

            Response::success([
                'mensaje'              => $aprobada ? 'Entrevista aprobada. Donante habilitado para extracción.' : 'Entrevista rechazada y estado actualizado en Kardex.',
                'entrevista_id'        => $id,
                'aprobada'             => $aprobada,
                'nuevo_estado_donante' => $nuevoEstado
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function generarUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}