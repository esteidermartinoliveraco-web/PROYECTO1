<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Utils\Response;
use App\Controllers\AuthController;
use App\Controllers\DonanteController;
use App\Controllers\EntrevistaController;
use App\Controllers\SolicitudController;
use App\Controllers\InventarioController;
use App\Controllers\LaboratorioController;
use App\Controllers\AlertaController;

// ============================================================================
// 1. CONFIGURACIÓN GLOBAL DE ERRORES Y SESIÓN SEGURA
// ============================================================================
$isDebug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

set_exception_handler(function (Throwable $e) use ($isDebug): void {
    error_log(sprintf('[%s] %s en %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    $mensaje = $isDebug ? $e->getMessage() : 'Error interno del servidor. Contacte al administrador.';
    Response::error($mensaje, 500);
});

// Configurar parámetros seguros de cookie de sesión antes de iniciarla
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 28800, // 8 horas (turno clínico estándar)
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_name('BANCOSANGRE_SID');
    session_start();
}

// ============================================================================
// 2. OBTENER MÉTODO HTTP Y RUTA LIMPIA
// ============================================================================
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri    = rtrim($uri, '/') ?: '/';

// Soporte para preflight CORS si se prueba desde otro puerto
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Si la petición NO empieza con /api, servir la aplicación web (SPA/PWA HTML base)
if (!str_starts_with($uri, '/api')) {
    $appHtml = __DIR__ . '/app.html';
    if (file_exists($appHtml)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($appHtml);
        exit;
    }
    Response::success([
        'sistema' => 'Banco de Sangre API & PWA',
        'estado'  => 'Operativo',
        'version' => '1.0.0'
    ]);
}

// ============================================================================
// 3. TABLA DE RUTAS REST Y PERMISOS POR ROL (RBAC)
// ============================================================================
// Formato: [MÉTODO, PATRÓN_URI, [ClaseControlador, 'metodo'], ROLES_PERMITIDOS]
// ROLES_PERMITIDOS = [] -> Ruta pública (sin sesión requerida)
// ROLES_PERMITIDOS = ['*'] -> Cualquier usuario autenticado

$routes = [
    // --- Autenticación ---
    ['POST', '/api/auth/login',  [AuthController::class, 'login'],  []],
    ['POST', '/api/auth/logout', [AuthController::class, 'logout'], ['*']],
    ['GET',  '/api/auth/me',     [AuthController::class, 'me'],     ['*']],

    // --- Donantes y Kardex Médico ---
    ['GET',  '/api/donantes',                 [DonanteController::class, 'listar'],        ['ADMIN', 'SUPERVISOR', 'RECEPCION']],
    ['GET',  '/api/donantes/{id}',            [DonanteController::class, 'obtenerPorId'],  ['ADMIN', 'SUPERVISOR', 'RECEPCION', 'DONANTE']],
    ['GET',  '/api/donantes/ci/{documento}',  [DonanteController::class, 'buscarPorCi'],   ['ADMIN', 'SUPERVISOR', 'RECEPCION']],
    ['POST', '/api/donantes',                 [DonanteController::class, 'registrar'],     []], // Permite autoregistro voluntario o por recepción
    ['PUT',  '/api/donantes/{id}/estado',     [DonanteController::class, 'cambiarEstado'], ['ADMIN', 'SUPERVISOR']], // Pausas o inactivación permanente
    ['GET',  '/api/donantes/{id}/kardex',     [DonanteController::class, 'verKardex'],     ['ADMIN', 'SUPERVISOR', 'DONANTE']],
    ['GET',  '/api/donantes/{id}/kardex-pdf', [DonanteController::class, 'descargarKardexPdf'], ['ADMIN', 'SUPERVISOR', 'DONANTE']],

    // --- Entrevistas Pre-Donación (Captura con Cámara / Fotos) ---
    ['GET',  '/api/entrevistas/pendientes',   [EntrevistaController::class, 'listarPendientes'], ['ADMIN', 'SUPERVISOR']],
    ['POST', '/api/entrevistas',              [EntrevistaController::class, 'registrarConFotos'], ['ADMIN', 'SUPERVISOR', 'RECEPCION', 'DONANTE']],
    ['GET',  '/api/entrevistas/{id}/foto/{lado}', [EntrevistaController::class, 'verFotoProtegida'], ['ADMIN', 'SUPERVISOR', 'RECEPCION']],
    ['PUT',  '/api/entrevistas/{id}/evaluar', [EntrevistaController::class, 'evaluar'],          ['ADMIN', 'SUPERVISOR']],

    // --- Solicitudes de Sangre (Familiares y Recepción) ---
    ['GET',  '/api/solicitudes',              [SolicitudController::class, 'listar'],          ['ADMIN', 'SUPERVISOR', 'RECEPCION', 'LABORATORISTA']],
    ['GET',  '/api/solicitudes/{codigo}',     [SolicitudController::class, 'consultarPorCodigo'], []], // Público para seguimiento del familiar/donante
    ['POST', '/api/solicitudes',              [SolicitudController::class, 'crear'],           []], // Familiar registra pedido con orden médica

    // --- Alertas y Tiempo Real (SSE) ---
    ['POST', '/api/alertas/disparar/{solicitudId}', [AlertaController::class, 'dispararParaSolicitud'], ['ADMIN', 'SUPERVISOR', 'RECEPCION']],
    ['POST', '/api/alertas/{alertaId}/responder',   [AlertaController::class, 'responderAlerta'],       []], // Donante acepta (dirigida o al banco) desde enlace móvil
    ['GET',  '/api/stream',                         [AlertaController::class, 'streamEventos'],         ['ADMIN', 'SUPERVISOR', 'RECEPCION']],

    // --- Inventario, Extracción y Despacho FEFO ---
    ['GET',  '/api/inventario/stock',         [InventarioController::class, 'resumenStock'],      ['*']],
    ['POST', '/api/inventario/extraccion',    [InventarioController::class, 'registrarExtraccion'], ['ADMIN', 'SUPERVISOR', 'RECEPCION', 'LABORATORISTA']],
    ['POST', '/api/inventario/fraccionar',    [InventarioController::class, 'fraccionarBolsa'],   ['ADMIN', 'LABORATORISTA']],
    ['POST', '/api/inventario/reservar',      [InventarioController::class, 'reservarFefo'],      ['ADMIN', 'SUPERVISOR', 'LABORATORISTA']],
    ['POST', '/api/inventario/despachar',     [InventarioController::class, 'confirmarDespacho'], ['ADMIN', 'SUPERVISOR', 'LABORATORISTA']],
    ['GET',  '/api/inventario/{id}/etiqueta', [InventarioController::class, 'imprimirEtiqueta'],  ['ADMIN', 'LABORATORISTA']],

    // --- Laboratorio y Tamizaje Serológico ---
    ['GET',  '/api/laboratorio/cuarentena',   [LaboratorioController::class, 'listarEnCuarentena'], ['ADMIN', 'SUPERVISOR', 'LABORATORISTA']],
    ['POST', '/api/laboratorio/tamizaje',     [LaboratorioController::class, 'registrarTamizaje'],  ['ADMIN', 'LABORATORISTA']],
];

// ============================================================================
// 4. DESPACHO DE RUTA Y VALIDACIÓN DE SEGURIDAD
// ============================================================================
foreach ($routes as [$routeMethod, $routePattern, $handler, $allowedRoles]) {
    if ($method !== $routeMethod) {
        continue;
    }

    // Convertir parámetros {param} en grupos nombrados de expresión regular
    $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $routePattern);
    $regex = '#^' . $regex . '$#';

    if (preg_match($regex, $uri, $matches)) {
        // Extraer únicamente los parámetros nombrados (ej. ['id' => 'uuid...'])
        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

        // Verificar autenticación y autorización si la ruta no es pública
        if (!empty($allowedRoles)) {
            $usuarioSesion = $_SESSION['usuario'] ?? null;

            if ($usuarioSesion === null) {
                Response::error('No autenticado. Inicie sesión para continuar.', 401);
            }

            $rolUsuario = $usuarioSesion['rol'] ?? '';
            if (!in_array('*', $allowedRoles, true) && !in_array($rolUsuario, $allowedRoles, true)) {
                Response::error('Acceso denegado. No tiene permisos suficientes para esta operación.', 403);
            }
        }

        // Instanciar controlador e invocar método pasando los parámetros de la URL
        [$controllerClass, $actionMethod] = $handler;

        if (!class_exists($controllerClass) || !method_exists($controllerClass, $actionMethod)) {
            Response::error("Controlador o acción no implementada: {$controllerClass}::{$actionMethod}", 501);
        }

        $controller = new $controllerClass();
        $controller->$actionMethod(...array_values($params));
        exit;
    }
}

// Si ninguna ruta coincidió
Response::error("Endpoint no encontrado: {$method} {$uri}", 404);