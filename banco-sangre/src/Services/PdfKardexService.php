<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use Picqer\Barcode\BarcodeGeneratorPNG;
use RuntimeException;

final class PdfKardexService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Genera y transmite en PDF la etiqueta térmica (100x70 mm) con código de barras Code 128
     * para adherir a una bolsa de hemocomponente.
     */
    public function generarEtiquetaUnidadPdf(string $unidadId): never
    {
        $stmt = $this->db->prepare("
            SELECT u.id, u.codigo_unidad, u.tipo_hemocomponente, u.grupo_sanguineo,
                   u.volumen_ml, u.fecha_extraccion, u.fecha_caducidad, u.estado,
                   d.codigo_bolsa_madre
            FROM inventario_unidades u
            INNER JOIN donaciones d ON d.id = u.donacion_id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $unidadId]);
        $unidad = $stmt->fetch();

        if (!$unidad) {
            throw new RuntimeException('Unidad de inventario no encontrada para impresión de etiqueta.');
        }

        // Generar imagen PNG en base64 del código de barras Code 128
        $generator = new BarcodeGeneratorPNG();
        $barcodeBinary = $generator->getBarcode($unidad['codigo_unidad'], $generator::TYPE_CODE_128, 2, 48);
        $barcodeBase64 = base64_encode($barcodeBinary);

        $grupoFormateado = $this->formatearGrupoSanguineo($unidad['grupo_sanguineo']);
        $componenteLabel = str_replace('_', ' ', $unidad['tipo_hemocomponente']);
        $fechaExt        = date('d/m/Y H:i', strtotime($unidad['fecha_extraccion']));
        $fechaCad        = date('d/m/Y H:i', strtotime($unidad['fecha_caducidad']));
        $selloSerologia  = $unidad['estado'] === 'DISPONIBLE'
            ? 'TAMIZAJE SEROLÓGICO: NO REACTIVO (APTA)'
            : 'ESTADO ACTUAL: ' . $unidad['estado'];

        $html = "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <style>
                @page { margin: 8px; }
                body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #0f172a; margin: 0; }
                .box { border: 1.5px solid #0f172a; padding: 6px; border-radius: 4px; }
                .header { border-bottom: 1px solid #0f172a; padding-bottom: 3px; margin-bottom: 4px; font-weight: bold; font-size: 10px; }
                .grupo-box { float: right; border: 2px solid #0f172a; padding: 2px 8px; font-size: 16px; font-weight: bold; background: #f1f5f9; }
                .barcode { text-align: center; margin: 6px 0 4px 0; }
                .code-text { font-family: monospace; font-size: 10px; font-weight: bold; letter-spacing: 1px; }
                table { width: 100%; border-collapse: collapse; margin-top: 4px; }
                td { padding: 2px 0; font-size: 8.5px; }
                .serologia { margin-top: 5px; padding: 3px; text-align: center; font-weight: bold; font-size: 8px; border: 1px solid #0f172a; }
            </style>
        </head>
        <body>
            <div class='box'>
                <div class='grupo-box'>{$grupoFormateado}</div>
                <div class='header'>BANCO DE SANGRE<br><span style='font-size: 8px; font-weight: normal;'>{$componenteLabel}</span></div>
                <div class='barcode'>
                    <img src='data:image/png;base64,{$barcodeBase64}' style='width: 88%; height: 38px;' /><br>
                    <span class='code-text'>{$unidad['codigo_unidad']}</span>
                </div>
                <table>
                    <tr>
                        <td><strong>Volumen:</strong> {$unidad['volumen_ml']} mL</td>
                        <td><strong>Bolsa Madre:</strong> {$unidad['codigo_bolsa_madre']}</td>
                    </tr>
                    <tr>
                        <td><strong>Extracción:</strong> {$fechaExt}</td>
                        <td><strong>Caducidad:</strong> {$fechaCad}</td>
                    </tr>
                </table>
                <div class='serologia'>{$selloSerologia}</div>
            </div>
        </body>
        </html>";

        $dompdf = $this->crearInstanciaDompdf();
        $dompdf->loadHtml($html);
        // Tamaño etiqueta térmica: 100mm x 70mm en puntos (1 mm = 2.83465 pt)
        $dompdf->setPaper([0, 0, 283.46, 198.43]);
        $dompdf->render();

        $dompdf->stream("Etiqueta_{$unidad['codigo_unidad']}.pdf", ['Attachment' => false]);
        exit;
    }

    /**
     * Genera y descarga el Kardex Médico completo de un donante en PDF tamaño Carta.
     */
    public function generarKardexDonantePdf(string $donanteId): never
    {
        $stmtDon = $this->db->prepare("
            SELECT id, documento_identidad, tipo_documento, nombres, apellidos,
                   fecha_nacimiento, TIMESTAMPDIFF(YEAR, fecha_nacimiento, CURDATE()) AS edad,
                   sexo, grupo_sanguineo, peso_kg, telefono_whatsapp, email,
                   estado, fecha_ultima_donacion, fecha_habilitacion_proxima, created_at
            FROM donantes
            WHERE id = :id
            LIMIT 1
        ");
        $stmtDon->execute([':id' => $donanteId]);
        $donante = $stmtDon->fetch();

        if (!$donante) {
            throw new RuntimeException('Donante no encontrado para generar Kardex PDF.');
        }

        // Historial de cambios de estado / pausas médicas
        $stmtKardex = $this->db->prepare("
            SELECT k.estado_anterior, k.estado_nuevo, k.motivo_clinico,
                   k.fecha_inicio_pausa, k.fecha_fin_estimada_pausa, k.observaciones_medicas,
                   k.created_at, u.nombre_completo AS supervisor_nombre
            FROM kardex_historial_donante k
            INNER JOIN usuarios u ON u.id = k.supervisor_id
            WHERE k.donante_id = :id
            ORDER BY k.created_at DESC
        ");
        $stmtKardex->execute([':id' => $donanteId]);
        $historialEstados = $stmtKardex->fetchAll();

        // Historial de donaciones efectivas
        $stmtDonaciones = $this->db->prepare("
            SELECT d.codigo_bolsa_madre, d.tipo_vinculo, d.volumen_extraido_ml,
                   d.fecha_extraccion, d.reaccion_adversa, p.resultado_global_apto
            FROM donaciones d
            LEFT JOIN pruebas_laboratorio p ON p.donacion_id = d.id
            WHERE d.donante_id = :id
            ORDER BY d.fecha_extraccion DESC
        ");
        $stmtDonaciones->execute([':id' => $donanteId]);
        $donaciones = $stmtDonaciones->fetchAll();

        $grupoLabel = $donante['grupo_sanguineo']
            ? $this->formatearGrupoSanguineo($donante['grupo_sanguineo'])
            : 'Pendiente de confirmación';

        $filasHistorial = '';
        if (empty($historialEstados)) {
            $filasHistorial = "<tr><td colspan='5' style='text-align:center; color:#64748b;'>Sin registros de pausas o cambios clínicos.</td></tr>";
        } else {
            foreach ($historialEstados as $h) {
                $fecha = date('d/m/Y H:i', strtotime($h['created_at']));
                $pausa = $h['fecha_fin_estimada_pausa'] ? date('d/m/Y', strtotime($h['fecha_fin_estimada_pausa'])) : '-';
                $motivo = htmlspecialchars($h['motivo_clinico'], ENT_QUOTES, 'UTF-8');
                $sup = htmlspecialchars($h['supervisor_nombre'], ENT_QUOTES, 'UTF-8');
                $filasHistorial .= "
                    <tr>
                        <td>{$fecha}</td>
                        <td>{$h['estado_anterior']} &rarr; <strong>{$h['estado_nuevo']}</strong></td>
                        <td>{$motivo}</td>
                        <td>{$pausa}</td>
                        <td>{$sup}</td>
                    </tr>";
            }
        }

        $filasDonaciones = '';
        if (empty($donaciones)) {
            $filasDonaciones = "<tr><td colspan='5' style='text-align:center; color:#64748b;'>No registra donaciones efectivas aún.</td></tr>";
        } else {
            foreach ($donaciones as $don) {
                $fechaExt = date('d/m/Y H:i', strtotime($don['fecha_extraccion']));
                $serologia = $don['resultado_global_apto'] === null
                    ? 'En proceso'
                    : ((int) $don['resultado_global_apto'] === 1 ? 'Apta (No reactiva)' : 'Reactiva / Descartada');
                $filasDonaciones .= "
                    <tr>
                        <td>{$fechaExt}</td>
                        <td><strong>{$don['codigo_bolsa_madre']}</strong></td>
                        <td>{$don['tipo_vinculo']}</td>
                        <td>{$don['volumen_extraido_ml']} mL</td>
                        <td>{$serologia}</td>
                    </tr>";
            }
        }

        $fechaEmision = date('d/m/Y H:i');
        $nombreCompleto = htmlspecialchars("{$donante['nombres']} {$donante['apellidos']}", ENT_QUOTES, 'UTF-8');

        $html = "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1e293b; }
                .header { border-bottom: 2px solid #b91c1c; padding-bottom: 10px; margin-bottom: 16px; }
                .title { font-size: 18px; font-weight: bold; color: #b91c1c; margin: 0; }
                .subtitle { font-size: 11px; color: #475569; margin-top: 4px; }
                .section-title { background: #f1f5f9; padding: 6px 10px; font-weight: bold; border-left: 4px solid #b91c1c; margin: 16px 0 8px 0; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
                th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
                th { background: #f8fafc; font-weight: bold; color: #334155; }
                .footer { position: fixed; bottom: 0; width: 100%; font-size: 9px; color: #64748b; text-align: right; border-top: 1px solid #e2e8f0; padding-top: 4px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1 class='title'>KARDEX CLÍNICO DE DONANTE</h1>
                <div class='subtitle'>Sistema de Gestión de Banco de Sangre &bull; Emitido el {$fechaEmision}</div>
            </div>

            <div class='section-title'>1. Datos de Identificación y Estado Clínico</div>
            <table>
                <tr>
                    <th style='width: 22%;'>Nombre Completo</th>
                    <td style='width: 38%;'>{$nombreCompleto}</td>
                    <th style='width: 20%;'>Grupo Sanguíneo</th>
                    <td style='width: 20%; font-size: 13px; font-weight: bold; color: #b91c1c;'>{$grupoLabel}</td>
                </tr>
                <tr>
                    <th>Documento</th>
                    <td>{$donante['tipo_documento']}: {$donante['documento_identidad']}</td>
                    <th>Estado Actual</th>
                    <td><strong>{$donante['estado']}</strong></td>
                </tr>
                <tr>
                    <th>Edad / Sexo</th>
                    <td>{$donante['edad']} años ({$donante['sexo']})</td>
                    <th>Peso Registrado</th>
                    <td>{$donante['peso_kg']} kg</td>
                </tr>
                <tr>
                    <th>Teléfono / WhatsApp</th>
                    <td>{$donante['telefono_whatsapp']}</td>
                    <th>Próxima Habilitación</th>
                    <td>" . ($donante['fecha_habilitacion_proxima'] ?: 'Inmediata') . "</td>
                </tr>
            </table>

            <div class='section-title'>2. Historial de Donaciones Efectivas</div>
            <table>
                <thead>
                    <tr>
                        <th>Fecha Extracción</th>
                        <th>Código Bolsa Madre</th>
                        <th>Modalidad</th>
                        <th>Volumen</th>
                        <th>Tamizaje Laboratorio</th>
                    </tr>
                </thead>
                <tbody>{$filasDonaciones}</tbody>
            </table>

            <div class='section-title'>3. Bitácora Clínica (Evaluaciones, Pausas y Reactivaciones)</div>
            <table>
                <thead>
                    <tr>
                        <th style='width: 16%;'>Fecha</th>
                        <th style='width: 24%;'>Transición de Estado</th>
                        <th style='width: 30%;'>Motivo Clínico</th>
                        <th style='width: 12%;'>Fin Pausa</th>
                        <th style='width: 18%;'>Supervisor</th>
                    </tr>
                </thead>
                <tbody>{$filasHistorial}</tbody>
            </table>

            <div class='footer'>Documento confidencial de uso médico interno - Trazabilidad de Hemovigilancia</div>
        </body>
        </html>";

        $dompdf = $this->crearInstanciaDompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $dompdf->stream("Kardex_{$donante['documento_identidad']}.pdf", ['Attachment' => true]);
        exit;
    }

    private function crearInstanciaDompdf(): Dompdf
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        return new Dompdf($options);
    }

    private function formatearGrupoSanguineo(string $grupo): string
    {
        return str_replace(['_POS', '_NEG'], ['+', '-'], $grupo);
    }
}