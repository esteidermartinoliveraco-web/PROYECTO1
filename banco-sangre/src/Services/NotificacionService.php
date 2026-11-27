<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class NotificacionService
{
    private string $appUrl;
    private bool $smtpEnabled;
    private string $whatsappToken;
    private string $whatsappPhoneId;

    public function __construct()
    {
        $this->appUrl          = rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/');
        $this->smtpEnabled     = filter_var(getenv('SMTP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $this->whatsappToken   = trim((string) (getenv('WHATSAPP_TOKEN') ?: ''));
        $this->whatsappPhoneId = trim((string) (getenv('WHATSAPP_PHONE_ID') ?: ''));
    }

    /**
     * Construye el enlace único que el donante abrirá en su celular para aceptar la alerta.
     */
    public function construirEnlaceRespuesta(string $alertaId): string
    {
        return "{$this->appUrl}/responder.html?alerta={$alertaId}";
    }

    /**
     * Envía convocatoria urgente por correo electrónico usando PHPMailer.
     *
     * @param array<string, mixed> $donante
     * @param array<string, mixed> $solicitud
     */
    public function enviarAlertaEmail(array $donante, array $solicitud, string $alertaId): bool
    {
        $emailDestino = trim((string) ($donante['email'] ?? ''));
        if ($emailDestino === '' || !filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $enlace = $this->construirEnlaceRespuesta($alertaId);
        $nombreDonante = htmlspecialchars((string) $donante['nombres'], ENT_QUOTES, 'UTF-8');
        $codigoSol     = htmlspecialchars((string) $solicitud['codigo_solicitud'], ENT_QUOTES, 'UTF-8');
        $grupoPac      = htmlspecialchars((string) $solicitud['paciente_grupo'], ENT_QUOTES, 'UTF-8');
        $hospital      = htmlspecialchars((string) $solicitud['hospital_clinica'], ENT_QUOTES, 'UTF-8');
        $urgencia      = htmlspecialchars((string) $solicitud['nivel_urgencia'], ENT_QUOTES, 'UTF-8');

        // Modo simulación en desarrollo local si SMTP no está activo
        if (!$this->smtpEnabled) {
            error_log("[SIMULACIÓN EMAIL] Para: {$emailDestino} | Solicitud: {$codigoSol} | Enlace: {$enlace}");
            return true;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = getenv('SMTP_HOST') ?: 'localhost';
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('SMTP_USER') ?: '';
            $mail->Password   = getenv('SMTP_PASS') ?: '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) (getenv('SMTP_PORT') ?: 587);
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(getenv('SMTP_USER') ?: 'no-reply@bancosangre.local', getenv('SMTP_FROM_NAME') ?: 'Banco de Sangre');
            $mail->addAddress($emailDestino, $nombreDonante);

            $mail->isHTML(true);
            $mail->Subject = "[{$urgencia}] Solicitud de Donación de Sangre ({$grupoPac}) - {$codigoSol}";
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; max-width: 560px; margin: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;'>
                    <h2 style='color: #b91c1c; margin-top: 0;'>Convocatoria de Donación de Sangre</h2>
                    <p>Hola <strong>{$nombreDonante}</strong>,</p>
                    <p>Tu grupo sanguíneo es compatible con un paciente que requiere transfusión en <strong>{$hospital}</strong>.</p>
                    <ul>
                        <li><strong>Código de Solicitud:</strong> {$codigoSol}</li>
                        <li><strong>Grupo Requerido:</strong> {$grupoPac}</li>
                        <li><strong>Nivel de Urgencia:</strong> {$urgencia}</li>
                    </ul>
                    <p>Puedes elegir donar directamente para este paciente o realizar tu donación voluntaria al banco de sangre:</p>
                    <p style='text-align: center; margin: 24px 0;'>
                        <a href='{$enlace}' style='background-color: #dc2626; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                            Responder Convocatoria
                        </a>
                    </p>
                    <p style='font-size: 12px; color: #64748b;'>Si el botón no funciona, copia este enlace: {$enlace}</p>
                </div>
            ";

            $mail->send();
            return true;
        } catch (MailException $e) {
            error_log('Error PHPMailer: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Envía alerta por WhatsApp Business Cloud API (o registra en log si no hay token configurado).
     *
     * @param array<string, mixed> $donante
     * @param array<string, mixed> $solicitud
     */
    public function enviarAlertaWhatsApp(array $donante, array $solicitud, string $alertaId): bool
    {
        $telefono = preg_replace('/[^0-9]/', '', (string) ($donante['telefono_whatsapp'] ?? ''));
        if ($telefono === '') {
            return false;
        }

        $enlace = $this->construirEnlaceRespuesta($alertaId);

        if ($this->whatsappToken === '' || $this->whatsappPhoneId === '') {
            error_log("[SIMULACIÓN WHATSAPP] Tel: +{$telefono} | Solicitud: {$solicitud['codigo_solicitud']} | Enlace: {$enlace}");
            return true;
        }

        $url = "https://graph.facebook.com/v20.0/{$this->whatsappPhoneId}/messages";
        $mensajeTexto = sprintf(
            "Hola %s, el Banco de Sangre necesita tu ayuda (%s). Se requiere sangre compatible para el paciente de la solicitud %s en %s. Confirma si puedes asistir aquí: %s",
            $donante['nombres'],
            $solicitud['nivel_urgencia'],
            $solicitud['codigo_solicitud'],
            $solicitud['hospital_clinica'],
            $enlace
        );

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => $telefono,
            'type'              => 'text',
            'text'              => ['body' => $mensajeTexto]
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->whatsappToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("Error WhatsApp API ({$httpCode}): " . (string) $response);
            return false;
        }

        return true;
    }
}