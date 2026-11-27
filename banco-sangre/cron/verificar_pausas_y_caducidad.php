<?php

declare(strict_types=1);

// Asegurar ejecución exclusiva desde línea de comandos (CLI)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado. Este script solo puede ejecutarse vía CLI.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

// Cargar variables de entorno desde .env si no están definidas en el entorno del contenedor
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($val));
    }
}

$db = Database::getConnection();
$inicio = date('Y-m-d H:i:s');
echo "[{$inicio}] Iniciando mantenimiento nocturno del Banco de Sangre...\n";

try {
    $db->beginTransaction();

    // 1. Obtener un usuario ADMIN para firmar los registros automáticos en el Kardex
    $stmtAdmin = $db->query("SELECT id FROM usuarios WHERE rol = 'ADMIN' AND activo = 1 LIMIT 1");
    $adminId = $stmtAdmin->fetchColumn();

    // 2. Reactivar donantes cuya pausa médica finalizó hoy o antes
    $stmtPausados = $db->query("
        SELECT id, nombres, apellidos, fecha_habilitacion_proxima
        FROM donantes
        WHERE estado = 'EN_PAUSA'
          AND fecha_habilitacion_proxima IS NOT NULL
          AND fecha_habilitacion_proxima <= CURDATE()
        FOR UPDATE
    ");
    $donantesReactivar = $stmtPausados->fetchAll();

    if (!empty($donantesReactivar)) {
        $stmtUpdDon = $db->prepare("UPDATE donantes SET estado = 'ACTIVO' WHERE id = :id");
        $stmtInsKardex = $db->prepare("
            INSERT INTO kardex_historial_donante (
                id, donante_id, supervisor_id, estado_anterior, estado_nuevo,
                motivo_clinico, observaciones_medicas
            ) VALUES (
                UUID(), :donante_id, :supervisor_id, 'EN_PAUSA', 'ACTIVO',
                'Reactivación automática por cumplimiento de período de pausa médica.',
                'Procesado automáticamente por tarea programada del sistema.'
            )
        ");

        foreach ($donantesReactivar as $donante) {
            $stmtUpdDon->execute([':id' => $donante['id']]);
            if ($adminId) {
                $stmtInsKardex->execute([
                    ':donante_id'    => $donante['id'],
                    ':supervisor_id' => $adminId
                ]);
            }
        }
    }
    $totalReactivados = count($donantesReactivar);

    // 3. Marcar como CADUCADA toda unidad vencida en inventario_unidades
    $stmtCaducadas = $db->exec("
        UPDATE inventario_unidades
        SET estado = 'CADUCADA'
        WHERE estado IN ('DISPONIBLE', 'CUARENTENA')
          AND fecha_caducidad < NOW()
    ");

    // 4. Expirar alertas enviadas hace más de 48 horas sin respuesta
    $stmtAlertas = $db->exec("
        UPDATE alertas_donantes
        SET estado = 'EXPIRADA'
        WHERE estado IN ('ENVIADA', 'ENTREGADA')
          AND fecha_envio < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ");

    $db->commit();

    echo " -> Donantes reactivados: {$totalReactivados}\n";
    echo " -> Unidades marcadas como CADUCADA: {$stmtCaducadas}\n";
    echo " -> Alertas expiradas sin respuesta: {$stmtAlertas}\n";
    echo "[" . date('Y-m-d H:i:s') . "] Mantenimiento finalizado con éxito.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[ERROR CRON] ' . $e->getMessage() . "\n");
    exit(1);
}