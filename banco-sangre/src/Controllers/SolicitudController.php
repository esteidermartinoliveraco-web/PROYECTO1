<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\CompatibilidadService;
use App\Services\StorageService;
use App\Utils\Response;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class SolicitudController
{
    private PDO $db;
    private CompatibilidadService $compatibilidad;
    private StorageService $storage;

    private const HEMOCOMPONENTES_VALIDOS = [
        'SANGRE_TOTAL', 'GLOBULOS_ROJOS', 'PLASMA_FRESCO', 'PLAQUETAS', 'CRIOPRECIPITADO'
    ];
    private const URGENCIAS_VALIDAS = ['PROGRAMADA', 'URGENTE', 'EMERGENCIA_VITAL'];
    private const ESTADOS_VALIDOS = ['PENDIENTE', 'EN_BUSQUEDA', 'PARCIALMENTE_CUBIERTA', 'CUBIERTA', 'CANCELADA'];

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->compatibilidad = new CompatibilidadService();
        $this->storage = new StorageService();
    }

    /**
     * GET /api/solicitudes?estado=PENDIENTE
     * Lista solicitudes ordenadas por criticidad clínica para el personal del banco.
     */
    public function listar(): never
    {
        $estado = strtoupper(trim((string) ($_GET['estado'] ?? '')));

        $sql = "
            SELECT s.id, s.codigo_solicitud, s.familiar_nombre, s.familiar_telefono, s.familiar_parentesco,
                   s.hospital_clinica, s.medico_solicitante, s.diagnostico,
                   s.tipo_hemocomponente, s.unidades_requeridas, s.unidades_cubiertas,
                   s.nivel_urgencia, s.estado, s.created_at,
                   p.documento_identidad AS paciente_documento,
                   p.nombres AS paciente_nombres,
                   p.apellidos AS paciente_apellidos,
                   p.grupo_sanguineo AS paciente_grupo
            FROM solicitudes_sangre s
            INNER JOIN pacientes p ON p.id = s.paciente_id
            WHERE 1 = 1
        ";
        $params = [];

        if ($estado !== '' && in_array($estado, self::ESTADOS_VALIDOS, true)) {
            $sql .= " AND s.estado = :estado";
            $params[':estado'] = $estado;
        } else {
            // Por defecto mostrar las solicitudes activas (no canceladas ni cerradas)
            $sql .= " AND s.estado IN ('PENDIENTE', 'EN_BUSQUEDA', 'PARCIALMENTE_CUBIERTA')";
        }

        $sql .= "
            ORDER BY FIELD(s.nivel_urgencia, 'EMERGENCIA_VITAL', 'URGENTE', 'PROGRAMADA'),
                     s.created_at ASC
            LIMIT 100
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        Response::success($stmt->fetchAll());
    }

    /**
     * GET /api/solicitudes/{codigo}
     * Consulta pública por código de solicitud (ej. SOL-2026-4F9A) para familiares y donantes independientes.
     */
    public function consultarPorCodigo(string $codigo): never
    {
        $codigoLimpio = strtoupper(trim($codigo));

        $stmt = $this->db->prepare("
            SELECT s.id, s.codigo_solicitud, s.hospital_clinica, s.medico_solicitante,
                   s.tipo_hemocomponente, s.unidades_requeridas, s.unidades_cubiertas,
                   (s.unidades_requeridas - s.unidades_cubiertas) AS unidades_faltantes,
                   s.nivel_urgencia, s.estado, s.created_at,
                   p.nombres AS paciente_nombres,
                   p.apellidos AS paciente_apellidos,
                   p.grupo_sanguineo AS paciente_grupo
            FROM solicitudes_sangre s
            INNER JOIN pacientes p ON p.id = s.paciente_id
            WHERE s.codigo_solicitud = :codigo
            LIMIT 1
        ");
        $stmt->execute([':codigo' => $codigoLimpio]);
        $solicitud = $stmt->fetch();

        if (!$solicitud) {
            Response::error("No se encontró ninguna solicitud con el código {$codigoLimpio}.", 404);
        }

        // Indicar qué grupos de donantes pueden acudir a donar para este paciente
        $gruposCompatibles = $this->compatibilidad->obtenerDonantesCompatibles(
            $solicitud['paciente_grupo'],
            $solicitud['tipo_hemocomponente']
        );

        // Contar cuántos donantes ya aceptaron venir por esta solicitud
        $stmtAceptadas = $this->db->prepare("
            SELECT COUNT(*) FROM alertas_donantes
            WHERE solicitud_id = :sid AND estado IN ('ACEPTADA_DIRIGIDA', 'ACEPTADA_BANCO')
        ");
        $stmtAceptadas->execute([':sid' => $solicitud['id']]);
        $donantesConfirmados = (int) $stmtAceptadas->fetchColumn();

        Response::success([
            'solicitud'            => $solicitud,
            'grupos_compatibles'   => $gruposCompatibles,
            'donantes_confirmados' => $donantesConfirmados
        ]);
    }

    /**
     * POST /api/solicitudes
     * Registra una solicitud de sangre realizada por un familiar (acepta multipart/form-data o JSON).
     */
    public function crear(): never
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $datos = str_contains($contentType, 'application/json')
            ? Response::getJsonBody()
            : $_POST;

        // Datos del Paciente
        $pacDocumento = trim((string) ($datos['paciente_documento'] ?? ''));
        $pacNombres   = trim((string) ($datos['paciente_nombres'] ?? ''));
        $pacApellidos = trim((string) ($datos['paciente_apellidos'] ?? ''));
        $pacFechaNac  = trim((string) ($datos['paciente_fecha_nacimiento'] ?? ''));
        $pacGrupo     = strtoupper(trim((string) ($datos['paciente_grupo_sanguineo'] ?? '')));
        $pacHistoria  = !empty($datos['historia_clinica_externa']) ? trim((string) $datos['historia_clinica_externa']) : null;

        // Datos del Familiar Solicitante
        $famNombre     = trim((string) ($datos['familiar_nombre'] ?? ''));
        $famDocumento  = trim((string) ($datos['familiar_documento'] ?? ''));
        $famTelefono   = trim((string) ($datos['familiar_telefono'] ?? ''));
        $famParentesco = trim((string) ($datos['familiar_parentesco'] ?? ''));

        // Datos Clínicos del Pedido
        $hospital      = trim((string) ($datos['hospital_clinica'] ?? ''));
        $medico        = trim((string) ($datos['medico_solicitante'] ?? ''));
        $diagnostico   = trim((string) ($datos['diagnostico'] ?? ''));
        $hemocomp      = strtoupper(trim((string) ($datos['tipo_hemocomponente'] ?? 'GLOBULOS_ROJOS')));
        $unidadesReq   = (int) ($datos['unidades_requeridas'] ?? 0);
        $urgencia      = strtoupper(trim((string) ($datos['nivel_urgencia'] ?? 'URGENTE')));

        // 1. Validaciones de integridad
        if ($pacDocumento === '' || $pacNombres === '' || $pacApellidos === '' || $pacFechaNac === '') {
            Response::error('Faltan datos obligatorios del paciente (documento, nombres, apellidos, fecha_nacimiento).', 400);
        }

        if ($famNombre === '' || $famDocumento === '' || $famTelefono === '' || $famParentesco === '') {
            Response::error('Faltan datos obligatorios del familiar responsable.', 400);
        }

        if ($hospital === '' || $medico === '' || $diagnostico === '') {
            Response::error('Debe especificar el hospital/clínica, médico solicitante y diagnóstico.', 400);
        }

        if (!in_array($pacGrupo, CompatibilidadService::GRUPOS_VALIDOS, true)) {
            Response::error('El grupo sanguíneo del paciente no es válido.', 400);
        }

        if (!in_array($hemocomp, self::HEMOCOMPONENTES_VALIDOS, true)) {
            Response::error('El tipo de hemocomponente solicitado no es válido.', 400);
        }

        if ($unidadesReq < 1 || $unidadesReq > 20) {
            Response::error('La cantidad de unidades requeridas debe estar entre 1 y 20.', 422);
        }

        if (!in_array($urgencia, self::URGENCIAS_VALIDAS, true)) {
            Response::error('Nivel de urgencia no válido.', 400);
        }

        if (!DateTimeImmutable::createFromFormat('Y-m-d', $pacFechaNac)) {
            Response::error('Formato de fecha de nacimiento del paciente inválido (use YYYY-MM-DD).', 400);
        }

        $archivoOrden = null;

        try {
            // Si el familiar subió foto o PDF de la orden médica, guardarla de forma segura
            if (isset($_FILES['orden_medica']) && ($_FILES['orden_medica']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $archivoOrden = $this->storage->guardarArchivo($_FILES['orden_medica'], 'ordenes');
            }

            $this->db->beginTransaction();

            // 2. Buscar o registrar al Paciente
            $stmtPac = $this->db->prepare("SELECT id, grupo_sanguineo FROM pacientes WHERE documento_identidad = :doc LIMIT 1");
            $stmtPac->execute([':doc' => $pacDocumento]);
            $pacienteExistente = $stmtPac->fetch();

            if ($pacienteExistente) {
                $pacienteId = $pacienteExistente['id'];
            } else {
                $pacienteId = $this->generarUuidV4();
                $stmtInsPac = $this->db->prepare("
                    INSERT INTO pacientes (
                        id, documento_identidad, nombres, apellidos, fecha_nacimiento, grupo_sanguineo, historia_clinica_externa
                    ) VALUES (
                        :id, :doc, :nombres, :apellidos, :fecha_nac, :grupo, :historia
                    )
                ");
                $stmtInsPac->execute([
                    ':id'        => $pacienteId,
                    ':doc'       => $pacDocumento,
                    ':nombres'   => $pacNombres,
                    ':apellidos' => $pacApellidos,
                    ':fecha_nac' => $pacFechaNac,
                    ':grupo'     => $pacGrupo,
                    ':historia'  => $pacHistoria
                ]);
            }

            // 3. Generar código legible único (ej. SOL-2026-8C1D)
            $codigoSolicitud = sprintf('SOL-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(2))));
            $solicitudId = $this->generarUuidV4();

            $stmtInsSol = $this->db->prepare("
                INSERT INTO solicitudes_sangre (
                    id, codigo_solicitud, paciente_id,
                    familiar_nombre, familiar_documento, familiar_telefono, familiar_parentesco,
                    hospital_clinica, medico_solicitante, diagnostico, orden_medica_url,
                    tipo_hemocomponente, unidades_requeridas, unidades_cubiertas, nivel_urgencia, estado
                ) VALUES (
                    :id, :codigo, :paciente_id,
                    :fam_nom, :fam_doc, :fam_tel, :fam_par,
                    :hospital, :medico, :diagnostico, :orden_url,
                    :hemocomp, :unidades_req, 0, :urgencia, 'PENDIENTE'
                )
            ");
            $stmtInsSol->execute([
                ':id'           => $solicitudId,
                ':codigo'       => $codigoSolicitud,
                ':paciente_id'  => $pacienteId,
                ':fam_nom'      => $famNombre,
                ':fam_doc'      => $famDocumento,
                ':fam_tel'      => $famTelefono,
                ':fam_par'      => $famParentesco,
                ':hospital'     => $hospital,
                ':medico'       => $medico,
                ':diagnostico'  => $diagnostico,
                ':orden_url'    => $archivoOrden['ruta_relativa'] ?? null,
                ':hemocomp'     => $hemocomp,
                ':unidades_req' => $unidadesReq,
                ':urgencia'     => $urgencia
            ]);

            $this->db->commit();

            // 4. Consultar disponibilidad inmediata en el depósito usando la matriz ABO/Rh
            $gruposCompatibles = $this->compatibilidad->obtenerDonantesCompatibles($pacGrupo, $hemocomp);
            $placeholders = implode(',', array_fill(0, count($gruposCompatibles), '?'));

            $stmtStock = $this->db->prepare("
                SELECT COUNT(*)
                FROM inventario_unidades
                WHERE estado = 'DISPONIBLE'
                  AND tipo_hemocomponente = ?
                  AND fecha_caducidad > NOW()
                  AND grupo_sanguineo IN ({$placeholders})
            ");
            $stmtStock->execute(array_merge([$hemocomp], $gruposCompatibles));
            $stockCompatibleDisponible = (int) $stmtStock->fetchColumn();

            Response::success([
                'mensaje'                     => 'Solicitud de sangre registrada correctamente.',
                'solicitud_id'                => $solicitudId,
                'codigo_solicitud'            => $codigoSolicitud,
                'unidades_requeridas'         => $unidadesReq,
                'stock_compatible_disponible' => $stockCompatibleDisponible,
                'requiere_alerta_donantes'    => $stockCompatibleDisponible < $unidadesReq,
                'grupos_compatibles'          => $gruposCompatibles
            ], 201);
        } catch (RuntimeException $e) {
            if ($archivoOrden) $this->storage->eliminarArchivo($archivoOrden['ruta_relativa']);
            Response::error($e->getMessage(), 400);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($archivoOrden) $this->storage->eliminarArchivo($archivoOrden['ruta_relativa']);
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