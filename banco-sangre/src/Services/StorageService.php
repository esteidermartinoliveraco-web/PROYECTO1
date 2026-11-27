<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class StorageService
{
    private string $baseStoragePath;

    private const MIME_PERMITIDOS = [
        'image/jpeg'      => ['ext' => 'jpg',  'enum' => 'IMAGE_JPEG'],
        'image/png'       => ['ext' => 'png',  'enum' => 'IMAGE_PNG'],
        'image/webp'      => ['ext' => 'webp', 'enum' => 'IMAGE_WEBP'],
        'application/pdf' => ['ext' => 'pdf',  'enum' => 'PDF'],
    ];

    public function __construct(?string $basePath = null)
    {
        $this->baseStoragePath = $basePath ?? dirname(__DIR__, 2) . '/storage';
    }

    /**
     * Valida y almacena un archivo subido vía $_FILES en una subcarpeta segura.
     *
     * @param array<string, mixed> $file Elemento de $_FILES
     * @param string $subcarpeta 'formularios' o 'ordenes'
     * @return array{ruta_relativa: string, tipo_enum: string, sha256: string, mime: string}
     */
    public function guardarArchivo(array $file, string $subcarpeta = 'formularios'): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException('Parámetros de archivo inválidos.');
        }

        match ($file['error']) {
            UPLOAD_ERR_OK         => null,
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => throw new RuntimeException('El archivo excede el tamaño máximo permitido (20 MB).'),
            UPLOAD_ERR_NO_FILE    => throw new RuntimeException('No se recibió ningún archivo.'),
            default               => throw new RuntimeException('Error desconocido al subir el archivo.')
        };

        $tmpPath = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Origen de archivo no válido.');
        }

        // Inspección MIME real del contenido binario (no confiar en la extensión del cliente)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeReal = $finfo->file($tmpPath);

        if ($mimeReal === false || !isset(self::MIME_PERMITIDOS[$mimeReal])) {
            throw new RuntimeException('Formato de archivo no permitido. Solo se aceptan imágenes (JPEG, PNG, WebP) o PDF.');
        }

        $metaTipo = self::MIME_PERMITIDOS[$mimeReal];
        $sha256 = hash_file('sha256', $tmpPath);
        if ($sha256 === false) {
            throw new RuntimeException('No se pudo calcular la firma de integridad SHA-256.');
        }

        $directorioDestino = $this->baseStoragePath . '/' . trim($subcarpeta, '/');
        if (!is_dir($directorioDestino) && !mkdir($directorioDestino, 0775, true) && !is_dir($directorioDestino)) {
            throw new RuntimeException('No se pudo preparar el directorio de almacenamiento.');
        }

        $nombreArchivo = sprintf('%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(8)), $metaTipo['ext']);
        $rutaAbsoluta = $directorioDestino . '/' . $nombreArchivo;
        $rutaRelativa = trim($subcarpeta, '/') . '/' . $nombreArchivo;

        if (!move_uploaded_file($tmpPath, $rutaAbsoluta)) {
            throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
        }

        return [
            'ruta_relativa' => $rutaRelativa,
            'tipo_enum'     => $metaTipo['enum'],
            'sha256'        => $sha256,
            'mime'          => $mimeReal
        ];
    }

    /**
     * Elimina un archivo guardado (útil para rollback si falla la inserción en MySQL).
     */
    public function eliminarArchivo(string $rutaRelativa): void
    {
        $rutaAbsoluta = $this->resolverRutaSegura($rutaRelativa);
        if ($rutaAbsoluta !== null && file_exists($rutaAbsoluta)) {
            @unlink($rutaAbsoluta);
        }
    }

    /**
     * Envía el archivo protegido al navegador tras validar permisos en el controlador.
     */
    public function transmitirArchivo(string $rutaRelativa): never
    {
        $rutaAbsoluta = $this->resolverRutaSegura($rutaRelativa);
        if ($rutaAbsoluta === null || !is_file($rutaAbsoluta)) {
            http_response_code(404);
            exit('Archivo clínico no encontrado.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($rutaAbsoluta) ?: 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($rutaAbsoluta));
        header('Content-Disposition: inline; filename="' . basename($rutaAbsoluta) . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        readfile($rutaAbsoluta);
        exit;
    }

    /**
     * Previene ataques Directory Traversal asegurando que la ruta permanezca dentro de storage/.
     */
    private function resolverRutaSegura(string $rutaRelativa): ?string
    {
        $baseReal = realpath($this->baseStoragePath);
        $objetivoReal = realpath($this->baseStoragePath . '/' . ltrim($rutaRelativa, '/'));

        if ($baseReal === false || $objetivoReal === false || !str_starts_with($objetivoReal, $baseReal)) {
            return null;
        }

        return $objetivoReal;
    }
}