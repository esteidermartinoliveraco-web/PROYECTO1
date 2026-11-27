<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Utils\Response;
use PDO;

final class AuthController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * POST /api/auth/login
     * Autentica al usuario y crea la sesión clínica.
     */
    public function login(): never
    {
        $body = Response::getJsonBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::error('Debe ingresar su correo electrónico y contraseña.', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('El formato del correo electrónico no es válido.', 400);
        }

        $stmt = $this->db->prepare("
            SELECT id, email, password_hash, nombre_completo, rol, activo
            FROM usuarios
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => mb_strtolower($email)]);
        $usuario = $stmt->fetch();

        // Verificación uniforme para no revelar si el correo existe o no
        if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
            Response::error('Credenciales incorrectas.', 401);
        }

        if (!(bool) $usuario['activo']) {
            Response::error('Esta cuenta de usuario se encuentra desactivada. Contacte al administrador.', 403);
        }

        // Si el usuario es DONANTE, adjuntar también su donante_id para facilitar consultas en el frontend
        $donanteId = null;
        if ($usuario['rol'] === 'DONANTE') {
            $stmtDon = $this->db->prepare("SELECT id, estado FROM donantes WHERE usuario_id = :uid LIMIT 1");
            $stmtDon->execute([':uid' => $usuario['id']]);
            $donante = $stmtDon->fetch();
            if ($donante) {
                $donanteId = $donante['id'];
            }
        }

        // Regenerar ID de sesión tras login exitoso (seguridad contra Session Fixation)
        session_regenerate_id(true);

        $_SESSION['usuario'] = [
            'id'              => $usuario['id'],
            'email'           => $usuario['email'],
            'nombre_completo' => $usuario['nombre_completo'],
            'rol'             => $usuario['rol'],
            'donante_id'      => $donanteId,
            'login_at'        => date('c')
        ];

        Response::success([
            'mensaje' => 'Inicio de sesión exitoso.',
            'usuario' => $_SESSION['usuario']
        ]);
    }

    /**
     * GET /api/auth/me
     * Devuelve los datos del usuario autenticado actualmente.
     */
    public function me(): never
    {
        $usuario = $_SESSION['usuario'] ?? null;
        if (!$usuario) {
            Response::error('Sesión no activa.', 401);
        }

        Response::success(['usuario' => $usuario]);
    }

    /**
     * POST /api/auth/logout
     * Cierra la sesión y elimina la cookie del navegador.
     */
    public function logout(): never
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        Response::success(['mensaje' => 'Sesión cerrada correctamente.']);
    }
}