<?php

declare(strict_types=1);

namespace App\Utils;

final class Response
{
    /**
     * Envía una respuesta JSON estandarizada y detiene la ejecución.
     */
    public static function json(bool $ok, mixed $data = null, ?string $error = null, int $statusCode = 200): never
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode([
            'ok'        => $ok,
            'data'      => $data,
            'error'     => $error,
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    /**
     * Respuesta exitosa (200 OK o 201 Created).
     */
    public static function success(mixed $data = null, int $statusCode = 200): never
    {
        self::json(true, $data, null, $statusCode);
    }

    /**
     * Respuesta de error controlado (400, 401, 403, 404, 409, 422, 500).
     */
    public static function error(string $message, int $statusCode = 400, mixed $details = null): never
    {
        self::json(false, $details, $message, $statusCode);
    }

    /**
     * Lee el cuerpo JSON de peticiones POST/PUT/PATCH de forma segura.
     *
     * @return array<string, mixed>
     */
    public static function getJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            self::error('El cuerpo de la petición no es un JSON válido.', 400);
        }

        return $decoded;
    }
}