<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\CompatibilidadService;
use App\Utils\Response;
use DateTimeImmutable;
use PDO;
use Throwable;

final class InventarioController
{
    private PDO $db;
    private CompatibilidadService $compatibilidad;

    private const HEMOCOMPONENTES_VALIDOS = [
        'SANGRE_TOTAL', 'GLOBULOS_ROJOS', 'PLASMA_FRESCO', 'PLAQUETAS', 'CRIOPRECIPITADO'
    ];

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->compatibilidad = new CompatibilidadService();
    }

    /**
     * GET /api/inventario/stock
     * Resumen consolidado de unidades por grupo sanguíneo, componente y estado + alertas de caducidad.
     */
    public function resumenStock(): never
    {
        $stmtStock = $this->db->query("
            SELECT grupo_sanguineo,
                   tipo_hemocomponente,
                   SUM(CASE WHEN estado = 'DISPONIBLE' AND fecha_caducidad > NOW() THEN 1 ELSE 0 END) AS disponibles,
                   SUM(CASE WHEN estado = 'CUARENTENA' THEN 1 ELSE 0 END) AS en_cuarentena,
                   SUM(CASE WHEN estado = 'RESERVADA' THEN 1 ELSE 0 END) AS reservadas
            FROM inventario_unidades
            WHERE estado IN ('DISPONIBLE', 'CUARENTENA', 'RESERVADA')
            GROUP BY grupo_sanguineo, tipo_hemocomponente
            ORDER BY grupo_sanguineo ASC, tipo_hemocomponente ASC
        ");

        // Unidades disponibles próximas a vencer en los próximos 5 días (prioridad FEFO)
        $stmtProximas = $this->db->query("
            SELECT id, codigo_unidad, tipo_hemocomponente, grupo_sanguineo,
                   volumen_ml, fecha_caducidad, ubicacion_equipo,
                   TIMESTAMPDIFF(HOUR, NOW(), fecha_caducidad) AS horas_restantes
            FROM inventario_unidades
            WHERE estado = 'DISPONIBLE'
              AND fecha_caducidad > NOW()
              AND fecha_caducidad <= DATE_ADD(NOW(), INTERVAL 5 DAY)
            ORDER BY fecha_caducidad ASC
            LIMIT 25
        ");

        Response::success([
            'matriz_stock'         => $stmtStock->fetchAll(),
            'proximas_a_caducar'   => $stmtProximas->fetchAll()
        ]);
    }

    /**
     * POST /api/inventario/extraccion
     * Registra la extracción de sangre de un donante con entrevista aprobada y aplica ventana de descanso.
     */
    public function registrarExtraccion(): never
    {
        $flebotomistaId = $_SESSION['usuario']['id'] ?? null;
        if (!$flebotomistaId) {
            Response::error('No autorizado.', 401);
        }

        $body = Response::getJsonBody();
        $entrevistaId    = trim((string) ($body['entrevista_id'] ?? ''));
        $volumenMl       = (int) ($body['volumen_extraido_ml'] ?? 450);
        $reaccionAdversa = !empty($body['reaccion_adversa']) ? trim((string) $body['reaccion_adversa']) : null;
        $codigoBolsa     = !empty($body['codigo_bolsa_madre'])
            ? strtoupper(trim((string) $body['codigo_bolsa_madre']))
            : sprintf('BM-%s-%s', date('Ymd'), strtoupper(bin2hex(random_bytes(3))));

        if ($entrevistaId === '') {
            Response::error('Debe especificar el entrevista_id aprobada.', 400);
        }

        if ($volumenMl < 250 || $volumenMl > 550) {
            Response::error('El volumen extraído debe estar entre 250 ml y 550 ml.', 422);
        }

        try {
            $this->db->beginTransaction();

            // 1. Validar entrevista aprobada y bloquear fila del donante
            $stmtEnt = $this->db->prepare("
                SELECT e.id, e.donante_id, e.solicitud_asociada_id, e.tipo_vinculo, e.aprobada,
                       d.sexo, d.estado AS estado_donante, d.fecha_habilitacion_proxima
                FROM entrevistas_predonacion e
                INNER JOIN donantes d ON d.id = e.donante_id
                WHERE e.id = :id
                FOR UPDATE
            ");
            $stmtEnt->execute([':id' => $entrevistaId]);
            $entrevista = $stmtEnt->fetch();

            if (!$entrevista) {
                $this->db->rollBack();
                Response::error('Entrevista pre-donación no encontrada.', 404);
            }

            if ((int) $entrevista['aprobada'] !== 1) {
                $this->db->rollBack();
                Response::error('La entrevista pre-donación no está aprobada por el supervisor médico.', 422);
            }

            // Verificar que la entrevista no haya sido usada ya en otra extracción
            $stmtDupEnt = $this->db->prepare("SELECT id FROM donaciones WHERE entrevista_id = :id LIMIT 1");
            $stmtDupEnt->execute([':id' => $entrevistaId]);
            if ($stmtDupEnt->fetch()) {
                $this->db->rollBack();
                Response::error('Esta entrevista ya tiene una extracción registrada.', 409);
            }

            // 2. Insertar registro en donaciones
            $donacionId = $this->generarUuidV4();
            $stmtIns = $this->db->prepare("
                INSERT INTO donaciones (
                    id, donante_id, entrevista_id, solicitud_id, flebotomista_id,
                    tipo_vinculo, codigo_bolsa_madre, volumen_extraido_ml, fecha_extraccion, reaccion_adversa
                ) VALUES (
                    :id, :donante_id, :entrevista_id, :solicitud_id, :flebotomista_id,
                    :vinculo, :codigo_bolsa, :volumen, NOW(), :reaccion
                )
            ");
            $stmtIns->execute([
                ':id'              => $donacionId,
                ':donante_id'      => $entrevista['donante_id'],
                ':entrevista_id'   => $entrevistaId,
                ':solicitud_id'    => $entrevista['solicitud_asociada_id'],
                ':flebotomista_id' => $flebotomistaId,
                ':vinculo'         => $entrevista['tipo_vinculo'],
                ':codigo_bolsa'    => $codigoBolsa,
                ':volumen'         => $volumenMl,
                ':reaccion'        => $reaccionAdversa
            ]);

            // 3. Calcular ventana de recuperación (90 días M / 120 días F) y actualizar donante
            $proximaFecha = $this->compatibilidad->calcularProximaFechaHabilitacion($entrevista['sexo']);

            $stmtUpdDon = $this->db->prepare("
                UPDATE donantes
                SET fecha_ultima_donacion = NOW(),
                    fecha_habilitacion_proxima = :proxima_fecha
                WHERE id = :donante_id
            ");
            $stmtUpdDon->execute([
                ':proxima_fecha' => $proximaFecha,
                ':donante_id'    => $entrevista['donante_id']
            ]);

            $this->db->commit();

            Response::success([
                'mensaje'                    => 'Extracción registrada correctamente. Proceda a fraccionar y etiquetar las unidades.',
                'donacion_id'                => $donacionId,
                'codigo_bolsa_madre'         => $codigoBolsa,
                'fecha_habilitacion_proxima' => $proximaFecha
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * POST /api/inventario/fraccionar
     * Fracciona una bolsa madre en hemocomponentes y los ingresa al inventario en estado CUARENTENA.
     * Payload: { "donacion_id": "uuid", "unidades": [ { "tipo_hemocomponente": "GLOBULOS_ROJOS", "volumen_ml": 280, "ubicacion_equipo": "REFRI-01-A" }, ... ] }
     */
    public function fraccionarBolsa(): never
    {
        $body = Response::getJsonBody();
        $donacionId = trim((string) ($body['donacion_id'] ?? ''));
        $unidades   = $body['unidades'] ?? [];

        if ($donacionId === '' || !is_array($unidades) || empty($unidades)) {
            Response::error('Debe indicar donacion_id y al menos una unidad fraccionada.', 400);
        }

        try {
            $this->db->beginTransaction();

            $stmtDon = $this->db->prepare("
                SELECT d.id, d.codigo_bolsa_madre, d.fecha_extraccion, don.grupo_sanguineo
                FROM donaciones d
                INNER JOIN donantes don ON don.id = d.donante_id
                WHERE d.id = :id
                FOR UPDATE
            ");
            $stmtDon->execute([':id' => $donacionId]);
            $donacion = $stmtDon->fetch();

            if (!$donacion) {
                $this->db->rollBack();
                Response::error('Donación no encontrada.', 404);
            }

            // Evitar fraccionamiento duplicado sobre la misma bolsa madre
            $stmtCheck = $this->db->prepare("SELECT COUNT(*) FROM inventario_unidades WHERE donacion_id = :id");
            $stmtCheck->execute([':id' => $donacionId]);
            if ((int) $stmtCheck->fetchColumn() > 0) {
                $this->db->rollBack();
                Response::error('Esta bolsa madre ya fue fraccionada anteriormente.', 409);
            }

            $grupoPreliminar = $donacion['grupo_sanguineo'] ?: 'O_POS';
            $fechaExtraccion = new DateTimeImmutable($donacion['fecha_extraccion']);

            $stmtInsUnidad = $this->db->prepare("
                INSERT INTO inventario_unidades (
                    id, donacion_id, codigo_unidad, tipo_hemocomponente, grupo_sanguineo,
                    volumen_ml, fecha_extraccion, fecha_caducidad, ubicacion_equipo, estado
                ) VALUES (
                    :id, :donacion_id, :codigo, :tipo, :grupo,
                    :volumen, :fecha_ext, :fecha_cad, :ubicacion, 'CUARENTENA'
                )
            ");

            $unidadesCreadas = [];
            foreach ($unidades as $idx => $item) {
                $tipo      = strtoupper(trim((string) ($item['tipo_hemocomponente'] ?? '')));
                $volumen   = (int) ($item['volumen_ml'] ?? 0);
                $ubicacion = trim((string) ($item['ubicacion_equipo'] ?? 'CUARENTENA-GENERAL'));

                if (!in_array($tipo, self::HEMOCOMPONENTES_VALIDOS, true)) {
                    $this->db->rollBack();
                    Response::error("Tipo de hemocomponente no válido en el ítem #" . ($idx + 1), 400);
                }

                if ($volumen <= 0) {
                    $this->db->rollBack();
                    Response::error("Volumen inválido en el ítem #" . ($idx + 1), 400);
                }

                $sufijo = match ($tipo) {
                    'GLOBULOS_ROJOS'  => 'GR',
                    'PLASMA_FRESCO'   => 'PF',
                    'PLAQUETAS'       => 'PL',
                    'CRIOPRECIPITADO' => 'CR',
                    default           => 'ST'
                };

                $unidadId = $this->generarUuidV4();
                $codigoUnidad = sprintf('%s-%s-%d', $donacion['codigo_bolsa_madre'], $sufijo, $idx + 1);
                $fechaCaducidad = $this->compatibilidad->calcularFechaCaducidad($tipo, $fechaExtraccion);

                $stmtInsUnidad->execute([
                    ':id'          => $unidadId,
                    ':donacion_id' => $donacionId,
                    ':codigo'      => $codigoUnidad,
                    ':tipo'        => $tipo,
                    ':grupo'       => $grupoPreliminar,
                    ':volumen'     => $volumen,
                    ':fecha_ext'   => $donacion['fecha_extraccion'],
                    ':fecha_cad'   => $fechaCaducidad,
                    ':ubicacion'   => $ubicacion
                ]);

                $unidadesCreadas[] = [
                    'unidad_id'           => $unidadId,
                    'codigo_unidad'       => $codigoUnidad,
                    'tipo_hemocomponente' => $tipo,
                    'fecha_caducidad'     => $fechaCaducidad,
                    'estado'              => 'CUARENTENA'
                ];
            }

            $this->db->commit();

            Response::success([
                'mensaje'  => 'Bolsa fraccionada exitosamente. Todas las unidades ingresaron en estado CUARENTENA.',
                'unidades' => $unidadesCreadas
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * POST /api/inventario/reservar
     * Selecciona y bloquea unidades compatibles bajo regla FEFO (fecha_caducidad ASC FOR UPDATE).
     */
    public function reservarFefo(): never
    {
        $usuarioId = $_SESSION['usuario']['id'] ?? null;
        if (!$usuarioId) {
            Response::error('No autorizado.', 401);
        }

        $body = Response::getJsonBody();
        $solicitudId   = trim((string) ($body['solicitud_id'] ?? ''));
        $cantidad      = (int) ($body['cantidad'] ?? 1);
        $pruebaCruzada = (bool) ($body['prueba_cruzada_compatible'] ?? false);

        if ($solicitudId === '' || $cantidad < 1) {
            Response::error('Debe indicar solicitud_id y cantidad >= 1.', 400);
        }

        if (!$pruebaCruzada) {
            Response::error('Por seguridad transfusional, debe confirmar prueba_cruzada_compatible = true antes de reservar.', 422);
        }

        try {
            $this->db->beginTransaction();

            // 1. Obtener datos de la solicitud y el grupo del paciente
            $stmtSol = $this->db->prepare("
                SELECT s.id, s.codigo_solicitud, s.tipo_hemocomponente, s.unidades_requeridas,
                       s.unidades_cubiertas, s.estado, p.grupo_sanguineo AS paciente_grupo
                FROM solicitudes_sangre s
                INNER JOIN pacientes p ON p.id = s.paciente_id
                WHERE s.id = :id
                FOR UPDATE
            ");
            $stmtSol->execute([':id' => $solicitudId]);
            $solicitud = $stmtSol->fetch();

            if (!$solicitud) {
                $this->db->rollBack();
                Response::error('Solicitud de sangre no encontrada.', 404);
            }

            if (in_array($solicitud['estado'], ['CUBIERTA', 'CANCELADA'], true)) {
                $this->db->rollBack();
                Response::error("La solicitud ya se encuentra en estado {$solicitud['estado']}.", 409);
            }

            // 2. Obtener grupos compatibles según la matriz ABO/Rh
            $gruposCompatibles = $this->compatibilidad->obtenerDonantesCompatibles(
                $solicitud['paciente_grupo'],
                $solicitud['tipo_hemocomponente']
            );
            $placeholders = implode(',', array_fill(0, count($gruposCompatibles), '?'));

            // 3. Selección FEFO con bloqueo pesimista FOR UPDATE (prioriza isogrupo en empate de fecha)
            $sqlFefo = "
                SELECT id, codigo_unidad, tipo_hemocomponente, grupo_sanguineo, volumen_ml, fecha_caducidad, ubicacion_equipo
                FROM inventario_unidades
                WHERE estado = 'DISPONIBLE'
                  AND tipo_hemocomponente = ?
                  AND fecha_caducidad > NOW()
                  AND grupo_sanguineo IN ({$placeholders})
                ORDER BY fecha_caducidad ASC, (grupo_sanguineo = ?) DESC
                LIMIT {$cantidad}
                FOR UPDATE
            ";

            $paramsFefo = array_merge([$solicitud['tipo_hemocomponente']], $gruposCompatibles, [$solicitud['paciente_grupo']]);
            $stmtFefo = $this->db->prepare($sqlFefo);
            $stmtFefo->execute($paramsFefo);
            $unidadesSeleccionadas = $stmtFefo->fetchAll();

            if (count($unidadesSeleccionadas) < $cantidad) {
                $disponibles = count($unidadesSeleccionadas);
                $this->db->rollBack();
                Response::error("Stock insuficiente en depósito para reservar {$cantidad} unidad(es). Disponibles actualmente: {$disponibles}.", 409);
            }

            $stmtUpdUnidad = $this->db->prepare("UPDATE inventario_unidades SET estado = 'RESERVADA' WHERE id = :id");
            $stmtInsAsig = $this->db->prepare("
                INSERT INTO asignaciones_despacho (
                    id, solicitud_id, unidad_id, usuario_despacho_id, prueba_cruzada_compatible, fecha_reserva
                ) VALUES (
                    :id, :solicitud_id, :unidad_id, :usuario_id, 1, NOW()
                )
            ");

            $reservadas = [];
            foreach ($unidadesSeleccionadas as $unidad) {
                $stmtUpdUnidad->execute([':id' => $unidad['id']]);

                $asignacionId = $this->generarUuidV4();
                $stmtInsAsig->execute([
                    ':id'           => $asignacionId,
                    ':solicitud_id' => $solicitudId,
                    ':unidad_id'    => $unidad['id'],
                    ':usuario_id'   => $usuarioId
                ]);

                $reservadas[] = [
                    'asignacion_id'   => $asignacionId,
                    'unidad_id'       => $unidad['id'],
                    'codigo_unidad'   => $unidad['codigo_unidad'],
                    'grupo_sanguineo' => $unidad['grupo_sanguineo'],
                    'fecha_caducidad' => $unidad['fecha_caducidad'],
                    'ubicacion'       => $unidad['ubicacion_equipo']
                ];
            }

            $this->db->commit();

            Response::success([
                'mensaje'             => "Se reservaron {$cantidad} unidad(es) bajo criterio FEFO.",
                'codigo_solicitud'    => $solicitud['codigo_solicitud'],
                'unidades_reservadas' => $reservadas
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * POST /api/inventario/despachar
     * Confirma la entrega física de las unidades reservadas y actualiza la cobertura de la solicitud.
     */
    public function confirmarDespacho(): never
    {
        $body = Response::getJsonBody();
        $solicitudId = trim((string) ($body['solicitud_id'] ?? ''));
        $recibidoPor = trim((string) ($body['recibido_por'] ?? ''));

        if ($solicitudId === '' || $recibidoPor === '') {
            Response::error('Debe indicar solicitud_id y el nombre de quien recibe las unidades (recibido_por).', 400);
        }

        try {
            $this->db->beginTransaction();

            $stmtSol = $this->db->prepare("
                SELECT id, codigo_solicitud, unidades_requeridas, unidades_cubiertas
                FROM solicitudes_sangre
                WHERE id = :id
                FOR UPDATE
            ");
            $stmtSol->execute([':id' => $solicitudId]);
            $solicitud = $stmtSol->fetch();

            if (!$solicitud) {
                $this->db->rollBack();
                Response::error('Solicitud no encontrada.', 404);
            }

            // Buscar todas las asignaciones pendientes de entrega física para esta solicitud
            $stmtAsig = $this->db->prepare("
                SELECT a.id AS asignacion_id, a.unidad_id
                FROM asignaciones_despacho a
                INNER JOIN inventario_unidades u ON u.id = a.unidad_id
                WHERE a.solicitud_id = :sid
                  AND a.fecha_despacho IS NULL
                  AND u.estado = 'RESERVADA'
                FOR UPDATE
            ");
            $stmtAsig->execute([':sid' => $solicitudId]);
            $pendientes = $stmtAsig->fetchAll();

            if (empty($pendientes)) {
                $this->db->rollBack();
                Response::error('No existen unidades en estado RESERVADA pendientes de despacho para esta solicitud.', 409);
            }

            $stmtUpdAsig = $this->db->prepare("
                UPDATE asignaciones_despacho
                SET fecha_despacho = NOW(),
                    recibido_por = :recibido
                WHERE id = :id
            ");
            $stmtUpdUni = $this->db->prepare("
                UPDATE inventario_unidades SET estado = 'TRANSFUNDIDA' WHERE id = :id
            ");

            foreach ($pendientes as $item) {
                $stmtUpdAsig->execute([':recibido' => $recibidoPor, ':id' => $item['asignacion_id']]);
                $stmtUpdUni->execute([':id' => $item['unidad_id']]);
            }

            $cantidadDespachada = count($pendientes);
            $nuevasCubiertas = min(
                (int) $solicitud['unidades_requeridas'],
                (int) $solicitud['unidades_cubiertas'] + $cantidadDespachada
            );
            $nuevoEstadoSol = $nuevasCubiertas >= (int) $solicitud['unidades_requeridas']
                ? 'CUBIERTA'
                : 'PARCIALMENTE_CUBIERTA';

            $stmtUpdSol = $this->db->prepare("
                UPDATE solicitudes_sangre
                SET unidades_cubiertas = :cubiertas,
                    estado = :estado
                WHERE id = :id
            ");
            $stmtUpdSol->execute([
                ':cubiertas' => $nuevasCubiertas,
                ':estado'    => $nuevoEstadoSol,
                ':id'        => $solicitudId
            ]);

            $this->db->commit();

            Response::success([
                'mensaje'             => "Despacho confirmado: {$cantidadDespachada} unidad(es) entregadas a {$recibidoPor}.",
                'codigo_solicitud'    => $solicitud['codigo_solicitud'],
                'unidades_cubiertas'  => $nuevasCubiertas,
                'unidades_requeridas' => (int) $solicitud['unidades_requeridas'],
                'estado_solicitud'    => $nuevoEstadoSol
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
public function imprimirEtiqueta(string $id): never
{
    (new \App\Services\PdfKardexService())->generarEtiquetaUnidadPdf($id);
}