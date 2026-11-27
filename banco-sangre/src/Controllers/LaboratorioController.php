<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\CompatibilidadService;
use App\Utils\Response;
use PDO;
use Throwable;

final class LaboratorioController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/laboratorio/cuarentena
     * Lista las donaciones que tienen unidades en CUARENTENA pendientes de tamizaje serológico.
     */
    public function listarEnCuarentena(): never
    {
        $stmt = $this->db->query("
            SELECT d.id AS donacion_id, d.codigo_bolsa_madre, d.fecha_extraccion, d.volumen_extraido_ml,
                   don.id AS donante_id, don.documento_identidad, don.nombres, don.apellidos,
                   don.grupo_sanguineo AS grupo_preliminar,
                   COUNT(u.id) AS total_unidades_cuarentena,
                   GROUP_CONCAT(CONCAT(u.codigo_unidad, ' (', u.tipo_hemocomponente, ')') SEPARATOR ', ') AS detalle_unidades
            FROM donaciones d
            INNER JOIN donantes don ON don.id = d.donante_id
            INNER JOIN inventario_unidades u ON u.donacion_id = d.id
            LEFT JOIN pruebas_laboratorio p ON p.donacion_id = d.id
            WHERE u.estado = 'CUARENTENA'
              AND p.id IS NULL
            GROUP BY d.id, d.codigo_bolsa_madre, d.fecha_extraccion, d.volumen_extraido_ml,
                     don.id, don.documento_identidad, don.nombres, don.apellidos, don.grupo_sanguineo
            ORDER BY d.fecha_extraccion ASC
        ");

        Response::success($stmt->fetchAll());
    }

    /**
     * POST /api/laboratorio/tamizaje
     * Registra resultados serológicos: libera unidades a DISPONIBLE o las DESCARTA e inactiva al donante.
     */
    public function registrarTamizaje(): never
    {
        $laboratoristaId = $_SESSION['usuario']['id'] ?? null;
        if (!$laboratoristaId) {
            Response::error('No autorizado.', 401);
        }

        $body = Response::getJsonBody();

        $donacionId       = trim((string) ($body['donacion_id'] ?? ''));
        $grupoConfirmado  = strtoupper(trim((string) ($body['grupo_confirmado'] ?? '')));

        if ($donacionId === '' || !in_array($grupoConfirmado, CompatibilidadService::GRUPOS_VALIDOS, true)) {
            Response::error('Debe indicar donacion_id y un grupo_confirmado válido.', 400);
        }

        // Marcadores obligatorios (booleanos explícitos)
        $camposBooleanos = [
            'anticuerpos_irregulares',
            'vih_reactivo',
            'hepatitis_b_reactivo',
            'hepatitis_c_reactivo',
            'chagas_reactivo',
            'sifilis_reactivo'
        ];

        foreach ($camposBooleanos as $campo) {
            if (!isset($body[$campo]) || !is_bool($body[$campo])) {
                Response::error("El marcador '{$campo}' debe enviarse como booleano (true o false).", 400);
            }
        }

        $anticuerpos = $body['anticuerpos_irregulares'];
        $vih         = $body['vih_reactivo'];
        $hepB        = $body['hepatitis_b_reactivo'];
        $hepC        = $body['hepatitis_c_reactivo'];
        $chagas      = $body['chagas_reactivo'];
        $sifilis     = $body['sifilis_reactivo'];

        // Regla estricta de bioseguridad: es apto únicamente si TODOS los marcadores son negativos (false)
        $esApto = !($anticuerpos || $vih || $hepB || $hepC || $chagas || $sifilis);

        try {
            $this->db->beginTransaction();

            // 1. Bloquear donación y verificar que no haya sido tamizada previamente
            $stmtDonacion = $this->db->prepare("
                SELECT d.id, d.donante_id, d.codigo_bolsa_madre, don.estado AS estado_donante_actual
                FROM donaciones d
                INNER JOIN donantes don ON don.id = d.donante_id
                WHERE d.id = :id
                FOR UPDATE
            ");
            $stmtDonacion->execute([':id' => $donacionId]);
            $donacion = $stmtDonacion->fetch();

            if (!$donacion) {
                $this->db->rollBack();
                Response::error('Donación no encontrada.', 404);
            }

            $stmtDup = $this->db->prepare("SELECT id FROM pruebas_laboratorio WHERE donacion_id = :id LIMIT 1");
            $stmtDup->execute([':id' => $donacionId]);
            if ($stmtDup->fetch()) {
                $this->db->rollBack();
                Response::error('Esta donación ya cuenta con un tamizaje serológico validado.', 409);
            }

            // Verificar que existan unidades en cuarentena para esta donación
            $stmtCount = $this->db->prepare("
                SELECT COUNT(*) FROM inventario_unidades WHERE donacion_id = :id AND estado = 'CUARENTENA'
            ");
            $stmtCount->execute([':id' => $donacionId]);
            $unidadesEnCuarentena = (int) $stmtCount->fetchColumn();

            if ($unidadesEnCuarentena === 0) {
                $this->db->rollBack();
                Response::error('La donación no tiene unidades fraccionadas en cuarentena. Fraccione la bolsa madre antes de validar.', 422);
            }

            // 2. Insertar registro en pruebas_laboratorio
            $pruebaId = $this->generarUuidV4();
            $stmtInsPrueba = $this->db->prepare("
                INSERT INTO pruebas_laboratorio (
                    id, donacion_id, laboratorista_id, grupo_confirmado,
                    anticuerpos_irregulares, vih_reactivo, hepatitis_b_reactivo,
                    hepatitis_c_reactivo, chagas_reactivo, sifilis_reactivo, resultado_global_apto
                ) VALUES (
                    :id, :donacion_id, :lab_id, :grupo,
                    :ac, :vih, :hepb, :hepc, :chagas, :sifilis, :apto
                )
            ");
            $stmtInsPrueba->execute([
                ':id'          => $pruebaId,
                ':donacion_id' => $donacionId,
                ':lab_id'      => $laboratoristaId,
                ':grupo'       => $grupoConfirmado,
                ':ac'          => $anticuerpos ? 1 : 0,
                ':vih'         => $vih ? 1 : 0,
                ':hepb'        => $hepB ? 1 : 0,
                ':hepc'        => $hepC ? 1 : 0,
                ':chagas'      => $chagas ? 1 : 0,
                ':sifilis'     => $sifilis ? 1 : 0,
                ':apto'        => $esApto ? 1 : 0
            ]);

            // 3. Actualizar inventario según resultado global
            $nuevoEstadoUnidades = $esApto ? 'DISPONIBLE' : 'DESCARTADA';

            $stmtUpdInv = $this->db->prepare("
                UPDATE inventario_unidades
                SET estado = :estado,
                    grupo_sanguineo = :grupo
                WHERE donacion_id = :donacion_id
                  AND estado = 'CUARENTENA'
            ");
            $stmtUpdInv->execute([
                ':estado'      => $nuevoEstadoUnidades,
                ':grupo'       => $grupoConfirmado,
                ':donacion_id' => $donacionId
            ]);

            // 4. Actualizar grupo sanguíneo confirmado del donante y aplicar bloqueo si hubo reactividad
            if ($esApto) {
                $stmtUpdDon = $this->db->prepare("
                    UPDATE donantes SET grupo_sanguineo = :grupo WHERE id = :donante_id
                ");
                $stmtUpdDon->execute([
                    ':grupo'      => $grupoConfirmado,
                    ':donante_id' => $donacion['donante_id']
                ]);
            } else {
                $stmtUpdDon = $this->db->prepare("
                    UPDATE donantes
                    SET grupo_sanguineo = :grupo,
                        estado = 'INACTIVO_PERMANENTE',
                        fecha_habilitacion_proxima = NULL
                    WHERE id = :donante_id
                ");
                $stmtUpdDon->execute([
                    ':grupo'      => $grupoConfirmado,
                    ':donante_id' => $donacion['donante_id']
                ]);

                // Registrar en el Kardex Médico del donante la inhabilitación por tamizaje reactivo
                $marcadoresPositivos = [];
                if ($anticuerpos) $marcadoresPositivos[] = 'Anticuerpos Irregulares';
                if ($vih)         $marcadoresPositivos[] = 'VIH';
                if ($hepB)        $marcadoresPositivos[] = 'Hepatitis B';
                if ($hepC)        $marcadoresPositivos[] = 'Hepatitis C';
                if ($chagas)      $marcadoresPositivos[] = 'Chagas';
                if ($sifilis)     $marcadoresPositivos[] = 'Sífilis';

                $detalleMarcadores = implode(', ', $marcadoresPositivos);

                $stmtKardex = $this->db->prepare("
                    INSERT INTO kardex_historial_donante (
                        id, donante_id, supervisor_id, estado_anterior, estado_nuevo,
                        motivo_clinico, observaciones_medicas
                    ) VALUES (
                        :id, :donante_id, :supervisor_id, :est_ant, 'INACTIVO_PERMANENTE',
                        :motivo, :obs
                    )
                ");
                $stmtKardex->execute([
                    ':id'            => $this->generarUuidV4(),
                    ':donante_id'    => $donacion['donante_id'],
                    ':supervisor_id' => $laboratoristaId,
                    ':est_ant'       => $donacion['estado_donante_actual'],
                    ':motivo'        => "Tamizaje serológico reactivo en bolsa {$donacion['codigo_bolsa_madre']}.",
                    ':obs'           => "Marcadores detectados: {$detalleMarcadores}. Unidades descartadas automáticamente."
                ]);
            }

            $this->db->commit();

            Response::success([
                'mensaje'               => $esApto
                    ? "Tamizaje negativo. {$unidadesEnCuarentena} unidad(es) liberadas a estado DISPONIBLE."
                    : "Tamizaje REACTIVO. {$unidadesEnCuarentena} unidad(es) marcadas como DESCARTADA y donante inactivado permanentemente.",
                'prueba_id'             => $pruebaId,
                'resultado_global_apto' => $esApto,
                'estado_unidades'       => $nuevoEstadoUnidades,
                'unidades_afectadas'    => $unidadesEnCuarentena
            ], 201);
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