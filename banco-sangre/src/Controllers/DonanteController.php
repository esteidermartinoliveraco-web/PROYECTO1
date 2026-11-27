<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use DateTimeImmutable;
use PDO;
use Throwable;

final class DonanteController
{
    private PDO $db;

    private const TIPOS_DOCUMENTO_VALIDOS = ['CI', 'PASAPORTE', 'DNI', 'CARNET_EXTRANJERIA'];
    private const GRUPOS_SANGUINEOS_VALIDOS = ['A_POS', 'A_NEG', 'B_POS', 'B_NEG', 'AB_POS', 'AB_NEG', 'O_POS', 'O_NEG'];
    private const ESTADOS_VALIDOS = ['PENDIENTE_EVALUACION', 'ACTIVO', 'EN_PAUSA', 'INACTIVO_PERMANENTE'];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/donantes?estado=ACTIVO&grupo=O_NEG&q=Juan
     * Lista donantes con filtros opcionales para recepción y supervisión.
     */
    public function listar(): never
    {
        $estado = trim((string) ($_GET['estado'] ?? ''));
        $grupo  = trim((string) ($_GET['grupo'] ?? ''));
        $busqueda = trim((string) ($_GET['q'] ?? ''));

        $sql = "
            SELECT id, documento_identidad, tipo_documento, nombres, apellidos,
                   TIMESTAMPDIFF(YEAR, fecha_nacimiento, CURDATE()) AS edad,
                   sexo, grupo_sanguineo, peso_kg, telefono_whatsapp, zona_geografica,
                   estado, fecha_ultima_donacion, fecha_habilitacion_proxima, created_at
            FROM donantes
            WHERE 1 = 1
        ";
        $params = [];

        if ($estado !== '' && in_array($estado, self::ESTADOS_VALIDOS, true)) {
            $sql .= " AND estado = :estado";
            $params[':estado'] = $estado;
        }

        if ($grupo !== '' && in_array($grupo, self::GRUPOS_SANGUINEOS_VALIDOS, true)) {
            $sql .= " AND grupo_sanguineo = :grupo";
            $params[':grupo'] = $grupo;
        }

        if ($busqueda !== '') {
            $sql .= " AND (documento_identidad LIKE :q OR nombres LIKE :q OR apellidos LIKE :q)";
            $params[':q'] = '%' . $busqueda . '%';
        }

        $sql .= " ORDER BY created_at DESC LIMIT 100";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        Response::success($stmt->fetchAll());
    }

    /**
     * GET /api/donantes/{id}
     */
    public function obtenerPorId(string $id): never
    {
        $this->verificarAccesoPropioSiEsDonante($id);

        $stmt = $this->db->prepare("
            SELECT *, TIMESTAMPDIFF(YEAR, fecha_nacimiento, CURDATE()) AS edad
            FROM donantes
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $donante = $stmt->fetch();

        if (!$donante) {
            Response::error('Donante no encontrado.', 404);
        }

        Response::success($donante);
    }

    /**
     * GET /api/donantes/ci/{documento}
     * Búsqueda rápida en recepción cuando llega un donante voluntario o independiente.
     */
    public function buscarPorCi(string $documento): never
    {
        $docLimpio = trim($documento);
        if ($docLimpio === '') {
            Response::error('Debe proporcionar un número de documento.', 400);
        }

        $stmt = $this->db->prepare("
            SELECT *, TIMESTAMPDIFF(YEAR, fecha_nacimiento, CURDATE()) AS edad
            FROM donantes
            WHERE documento_identidad = :doc
            LIMIT 1
        ");
        $stmt->execute([':doc' => $docLimpio]);
        $donante = $stmt->fetch();

        if (!$donante) {
            Response::error('No existe ningún donante registrado con ese documento.', 404);
        }

        Response::success($donante);
    }

    /**
     * POST /api/donantes
     * Registra un nuevo donante (voluntario desde portal o cargado en recepción).
     */
    public function registrar(): never
    {
        $body = Response::getJsonBody();

        $tipoDoc    = strtoupper(trim((string) ($body['tipo_documento'] ?? 'CI')));
        $documento  = trim((string) ($body['documento_identidad'] ?? ''));
        $nombres    = trim((string) ($body['nombres'] ?? ''));
        $apellidos  = trim((string) ($body['apellidos'] ?? ''));
        $fechaNac   = trim((string) ($body['fecha_nacimiento'] ?? ''));
        $sexo       = strtoupper(trim((string) ($body['sexo'] ?? '')));
        $grupo      = !empty($body['grupo_sanguineo']) ? strtoupper(trim((string) $body['grupo_sanguineo'])) : null;
        $pesoKg     = (float) ($body['peso_kg'] ?? 0);
        $telefono   = trim((string) ($body['telefono_whatsapp'] ?? ''));
        $email      = !empty($body['email']) ? mb_strtolower(trim((string) $body['email'])) : null;
        $direccion  = !empty($body['direccion']) ? trim((string) $body['direccion']) : null;
        $zona       = !empty($body['zona_geografica']) ? trim((string) $body['zona_geografica']) : null;
        $password   = (string) ($body['password'] ?? ''); // Opcional: si desea acceso al portal

        // 1. Validaciones de campos obligatorios y catálogos
        if ($documento === '' || $nombres === '' || $apellidos === '' || $fechaNac === '' || $telefono === '') {
            Response::error('Faltan campos obligatorios (documento, nombres, apellidos, fecha_nacimiento, telefono_whatsapp).', 400);
        }

        if (!in_array($tipoDoc, self::TIPOS_DOCUMENTO_VALIDOS, true)) {
            Response::error('Tipo de documento no válido.', 400);
        }

        if (!in_array($sexo, ['M', 'F'], true)) {
            Response::error('El sexo debe ser M o F.', 400);
        }

        if ($grupo !== null && !in_array($grupo, self::GRUPOS_SANGUINEOS_VALIDOS, true)) {
            Response::error('Grupo sanguíneo no válido.', 400);
        }

        // 2. Reglas clínicas de admisión: Edad (18 a 65 años) y Peso (>= 50 kg)
        $fechaNacObj = DateTimeImmutable::createFromFormat('Y-m-d', $fechaNac);
        if (!$fechaNacObj) {
            Response::error('Formato de fecha de nacimiento inválido (use YYYY-MM-DD).', 400);
        }

        $edad = $fechaNacObj->diff(new DateTimeImmutable('today'))->y;
        if ($edad < 18 || $edad > 65) {
            Response::error("Edad fuera de rango clínico para donación ({$edad} años). Debe tener entre 18 y 65 años.", 422);
        }

        if ($pesoKg < 50.00) {
            Response::error("Peso insuficiente ({$pesoKg} kg). El peso mínimo obligatorio para donar es 50 kg.", 422);
        }

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('El correo electrónico proporcionado no es válido.', 400);
        }

        // 3. Verificar duplicidad de documento
        $stmtDup = $this->db->prepare("SELECT id FROM donantes WHERE documento_identidad = :doc LIMIT 1");
        $stmtDup->execute([':doc' => $documento]);
        if ($stmtDup->fetch()) {
            Response::error('Ya existe un donante registrado con ese documento de identidad.', 409);
        }

        try {
            $this->db->beginTransaction();

            $usuarioId = null;

            // Si envió contraseña y correo, crear cuenta de usuario con rol DONANTE
            if ($password !== '' && $email !== null) {
                if (strlen($password) < 8) {
                    $this->db->rollBack();
                    Response::error('La contraseña debe tener al menos 8 caracteres.', 400);
                }

                $stmtCheckEmail = $this->db->prepare("SELECT id FROM usuarios WHERE email = :email LIMIT 1");
                $stmtCheckEmail->execute([':email' => $email]);
                if ($stmtCheckEmail->fetch()) {
                    $this->db->rollBack();
                    Response::error('El correo electrónico ya está asociado a otra cuenta.', 409);
                }

                $usuarioId = $this->generarUuidV4();
                $stmtUser = $this->db->prepare("
                    INSERT INTO usuarios (id, email, password_hash, nombre_completo, rol, activo)
                    VALUES (:id, :email, :hash, :nombre, 'DONANTE', 1)
                ");
                $stmtUser->execute([
                    ':id'     => $usuarioId,
                    ':email'  => $email,
                    ':hash'   => password_hash($password, PASSWORD_BCRYPT),
                    ':nombre' => "{$nombres} {$apellidos}"
                ]);
            }

            $donanteId = $this->generarUuidV4();
            $stmtDon = $this->db->prepare("
                INSERT INTO donantes (
                    id, usuario_id, documento_identidad, tipo_documento, nombres, apellidos,
                    fecha_nacimiento, sexo, grupo_sanguineo, peso_kg, telefono_whatsapp,
                    email, direccion, zona_geografica, estado
                ) VALUES (
                    :id, :usuario_id, :doc, :tipo_doc, :nombres, :apellidos,
                    :fecha_nac, :sexo, :grupo, :peso, :tel,
                    :email, :dir, :zona, 'PENDIENTE_EVALUACION'
                )
            ");
            $stmtDon->execute([
                ':id'         => $donanteId,
                ':usuario_id' => $usuarioId,
                ':doc'        => $documento,
                ':tipo_doc'   => $tipoDoc,
                ':nombres'    => $nombres,
                ':apellidos'  => $apellidos,
                ':fecha_nac'  => $fechaNac,
                ':sexo'       => $sexo,
                ':grupo'      => $grupo,
                ':peso'       => $pesoKg,
                ':tel'        => $telefono,
                ':email'      => $email,
                ':dir'        => $direccion,
                ':zona'       => $zona
            ]);

            $this->db->commit();

            Response::success([
                'mensaje'    => 'Donante registrado correctamente. Pendiente de entrevista pre-donación.',
                'donante_id' => $donanteId,
                'estado'     => 'PENDIENTE_EVALUACION'
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * PUT /api/donantes/{id}/estado
     * Cambia el estado del donante (ACTIVO, EN_PAUSA, INACTIVO_PERMANENTE) y registra en Kardex.
     */
    public function cambiarEstado(string $id): never
    {
        $supervisorId = $_SESSION['usuario']['id'] ?? null;
        if (!$supervisorId) {
            Response::error('No autorizado.', 401);
        }

        $body = Response::getJsonBody();
        $nuevoEstado   = strtoupper(trim((string) ($body['estado_nuevo'] ?? '')));
        $motivoClinico = trim((string) ($body['motivo_clinico'] ?? ''));
        $observaciones = !empty($body['observaciones_medicas']) ? trim((string) $body['observaciones_medicas']) : null;
        $fechaFinPausa = !empty($body['fecha_fin_estimada_pausa']) ? trim((string) $body['fecha_fin_estimada_pausa']) : null;

        if (!in_array($nuevoEstado, self::ESTADOS_VALIDOS, true)) {
            Response::error('El estado destino no es válido.', 400);
        }

        if ($motivoClinico === '') {
            Response::error('El motivo clínico es obligatorio para registrar en el Kardex del donante.', 400);
        }

        if ($nuevoEstado === 'EN_PAUSA') {
            if ($fechaFinPausa === null || !DateTimeImmutable::createFromFormat('Y-m-d', $fechaFinPausa)) {
                Response::error('Para poner a un donante EN_PAUSA debe indicar una fecha_fin_estimada_pausa válida (YYYY-MM-DD).', 400);
            }
            if ($fechaFinPausa <= date('Y-m-d')) {
                Response::error('La fecha de fin de pausa debe ser posterior al día de hoy.', 422);
            }
        }

        try {
            $this->db->beginTransaction();

            // Bloquear fila del donante para evitar cambios concurrentes
            $stmtLock = $this->db->prepare("SELECT id, estado FROM donantes WHERE id = :id FOR UPDATE");
            $stmtLock->execute([':id' => $id]);
            $donante = $stmtLock->fetch();

            if (!$donante) {
                $this->db->rollBack();
                Response::error('Donante no encontrado.', 404);
            }

            $estadoAnterior = $donante['estado'];

            if ($estadoAnterior === $nuevoEstado) {
                $this->db->rollBack();
                Response::error("El donante ya se encuentra en estado {$nuevoEstado}.", 409);
            }

            // Regla clínica: un diferimiento permanente requiere intervención de ADMIN si se intenta revertir
            $rolActual = $_SESSION['usuario']['rol'] ?? '';
            if ($estadoAnterior === 'INACTIVO_PERMANENTE' && $rolActual !== 'ADMIN') {
                $this->db->rollBack();
                Response::error('Un donante con diferimiento permanente (INACTIVO_PERMANENTE) solo puede ser reevaluado por un Administrador del sistema.', 403);
            }

            // Determinar nueva fecha_habilitacion_proxima según el estado
            $fechaHabilitacion = match ($nuevoEstado) {
                'ACTIVO'              => date('Y-m-d'),
                'EN_PAUSA'            => $fechaFinPausa,
                'INACTIVO_PERMANENTE' => null,
                default               => null
            };

            $stmtUpd = $this->db->prepare("
                UPDATE donantes
                SET estado = :estado,
                    fecha_habilitacion_proxima = :fecha_hab
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':estado'    => $nuevoEstado,
                ':fecha_hab' => $fechaHabilitacion,
                ':id'        => $id
            ]);

            // Insertar registro inmutable en el Kardex Médico
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
                ':donante_id'    => $id,
                ':supervisor_id' => $supervisorId,
                ':est_ant'       => $estadoAnterior,
                ':est_nvo'       => $nuevoEstado,
                ':motivo'        => $motivoClinico,
                ':inicio_pausa'  => $nuevoEstado === 'EN_PAUSA' ? date('Y-m-d') : null,
                ':fin_pausa'     => $nuevoEstado === 'EN_PAUSA' ? $fechaFinPausa : null,
                ':obs'           => $observaciones
            ]);

            $this->db->commit();

            Response::success([
                'mensaje'         => "Estado del donante actualizado a {$nuevoEstado} y registrado en Kardex.",
                'donante_id'      => $id,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo'    => $nuevoEstado
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * GET /api/donantes/{id}/kardex
     * Devuelve el historial clínico completo: cambios de estado, entrevistas y donaciones.
     */
    public function verKardex(string $id): never
    {
        $this->verificarAccesoPropioSiEsDonante($id);

        $stmtDon = $this->db->prepare("
            SELECT id, documento_identidad, tipo_documento, nombres, apellidos,
                   fecha_nacimiento, TIMESTAMPDIFF(YEAR, fecha_nacimiento, CURDATE()) AS edad,
                   sexo, grupo_sanguineo, peso_kg, estado, fecha_ultima_donacion, fecha_habilitacion_proxima
            FROM donantes
            WHERE id = :id
            LIMIT 1
        ");
        $stmtDon->execute([':id' => $id]);
        $donante = $stmtDon->fetch();

        if (!$donante) {
            Response::error('Donante no encontrado.', 404);
        }

        // 1. Historial de estados y pausas médicas
        $stmtKardex = $this->db->prepare("
            SELECT k.id, k.estado_anterior, k.estado_nuevo, k.motivo_clinico,
                   k.fecha_inicio_pausa, k.fecha_fin_estimada_pausa, k.observaciones_medicas,
                   k.created_at, u.nombre_completo AS supervisor_nombre
            FROM kardex_historial_donante k
            INNER JOIN usuarios u ON u.id = k.supervisor_id
            WHERE k.donante_id = :id
            ORDER BY k.created_at DESC
        ");
        $stmtKardex->execute([':id' => $id]);
        $historialEstados = $stmtKardex->fetchAll();

        // 2. Historial de entrevistas pre-donación
        $stmtEnt = $this->db->prepare("
            SELECT e.id, e.tipo_vinculo, e.fecha_entrevista, e.peso_registrado_kg,
                   e.presion_arterial, e.hemoglobina_g_dl, e.temperatura_c,
                   e.aprobada, e.motivo_rechazo, e.fecha_evaluacion,
                   u.nombre_completo AS evaluador_nombre
            FROM entrevistas_predonacion e
            LEFT JOIN usuarios u ON u.id = e.evaluador_id
            WHERE e.donante_id = :id
            ORDER BY e.fecha_entrevista DESC
        ");
        $stmtEnt->execute([':id' => $id]);
        $entrevistas = $stmtEnt->fetchAll();

        // 3. Historial de donaciones efectivas
        $stmtDonaciones = $this->db->prepare("
            SELECT d.id, d.tipo_vinculo, d.codigo_bolsa_madre, d.volumen_extraido_ml,
                   d.fecha_extraccion, d.reaccion_adversa,
                   p.resultado_global_apto, p.grupo_confirmado
            FROM donaciones d
            LEFT JOIN pruebas_laboratorio p ON p.donacion_id = d.id
            WHERE d.donante_id = :id
            ORDER BY d.fecha_extraccion DESC
        ");
        $stmtDonaciones->execute([':id' => $id]);
        $donaciones = $stmtDonaciones->fetchAll();

        Response::success([
            'donante'           => $donante,
            'historial_estados' => $historialEstados,
            'entrevistas'       => $entrevistas,
            'donaciones'        => $donaciones
        ]);
    }

    /**
     * Garantiza que si el usuario autenticado tiene rol DONANTE, solo consulte su propio ID.
     */
    private function verificarAccesoPropioSiEsDonante(string $donanteIdSolicitado): void
    {
        $usuario = $_SESSION['usuario'] ?? null;
        if (!$usuario) {
            Response::error('No autenticado.', 401);
        }

        if (($usuario['rol'] ?? '') === 'DONANTE') {
            $miDonanteId = $usuario['donante_id'] ?? '';
            if ($miDonanteId !== $donanteIdSolicitado) {
                Response::error('No tiene permiso para consultar el historial de otro donante.', 403);
            }
        }
    }

    /**
     * Genera un UUID v4 estándar compatible con CHAR(36) en MySQL.
     */
    private function generarUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

public function descargarKardexPdf(string $id): never
{
    $this->verificarAccesoPropioSiEsDonante($id);
    (new \App\Services\PdfKardexService())->generarKardexDonantePdf($id);
}