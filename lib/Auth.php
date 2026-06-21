<?php
require_once __DIR__ . '/db.php';

class Auth
{
    // ── ADMIN ─────────────────────────────────────────────────────────────────

    public static function adminLogin(string $username, string $password): bool
    {
        if (session_status() === PHP_SESSION_NONE) self::startSession();
        $st = db()->prepare('SELECT * FROM admin_users WHERE username=? AND active=1');
        $st->execute([$username]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) return false;

        $_SESSION['admin_id']   = $u['id'];
        $_SESSION['admin_role'] = $u['role'];
        $_SESSION['admin_name'] = $u['name'] ?: $u['username'];
        session_regenerate_id(true);

        db()->prepare('UPDATE admin_users SET last_login=NOW() WHERE id=?')->execute([$u['id']]);
        self::logAction('admin_login', 'Login admin: ' . $username);
        return true;
    }

    public static function adminCheck(string ...$roles): void
    {
        if (session_status() === PHP_SESSION_NONE) self::startSession();
        if (empty($_SESSION['admin_id'])) {
            $base = defined('APP_BASE_URL') ? rtrim(APP_BASE_URL, '/') : '';
            header('Location: ' . $base . '/admin/login.php');
            exit;
        }
        if (!empty($roles) && !in_array($_SESSION['admin_role'], $roles)) {
            http_response_code(403);
            die('<h2 style="font-family:sans-serif;padding:40px">Sin permisos suficientes.</h2>');
        }
    }

    public static function adminId(): int
    {
        return (int)($_SESSION['admin_id'] ?? 0);
    }

    public static function adminRole(): string
    {
        return $_SESSION['admin_role'] ?? '';
    }

    public static function adminName(): string
    {
        return $_SESSION['admin_name'] ?? '';
    }

    public static function adminLogout(): void
    {
        if (session_status() === PHP_SESSION_NONE) self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    // ── USUARIO PÚBLICO ───────────────────────────────────────────────────────

    public static function userLogin(string $email, string $password): bool
    {
        if (session_status() === PHP_SESSION_NONE) self::startSession();
        $st = db()->prepare('SELECT * FROM users WHERE email=? AND active=1');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) return false;

        $_SESSION['user_id']    = $u['id'];
        $_SESSION['user_name']  = $u['name'];
        $_SESSION['user_email'] = $u['email'];
        session_regenerate_id(true);
        return true;
    }

    public static function userCheck(): void
    {
        if (session_status() === PHP_SESSION_NONE) self::startSession();
        if (empty($_SESSION['user_id'])) {
            $back = urlencode($_SERVER['REQUEST_URI'] ?? '');
            $base = defined('APP_BASE_URL') ? rtrim(APP_BASE_URL, '/') : '';
            header('Location: ' . $base . '/public/login.php?back=' . $back);
            exit;
        }
    }

    public static function userId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function userName(): string
    {
        return $_SESSION['user_name'] ?? '';
    }

    public static function userEmail(): string
    {
        return $_SESSION['user_email'] ?? '';
    }

    public static function isUserLogged(): bool
    {
        if (session_status() === PHP_SESSION_NONE) self::startSession();
        return !empty($_SESSION['user_id']);
    }

    public static function userLogout(): void
    {
        if (session_status() === PHP_SESSION_NONE) self::startSession();
        unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);
    }

    public static function getUser(): ?array
    {
        if (!self::isUserLogged()) return null;
        $st = db()->prepare('SELECT * FROM users WHERE id=?');
        $st->execute([self::userId()]);
        return $st->fetch() ?: null;
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    public static function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function checkCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) self::startSession();
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            die('Token CSRF inválido. Recarga la página e inténtalo de nuevo.');
        }
    }

    // ── LOG ───────────────────────────────────────────────────────────────────

    public static function logAction(string $action, string $detail = ''): void
    {
        try {
            $uid = $_SESSION['admin_id'] ?? null;
            db()->prepare('INSERT INTO admin_log (user_id,action,detail,ip) VALUES(?,?,?,?)')
               ->execute([$uid, $action, $detail, $_SERVER['REMOTE_ADDR'] ?? '']);
        } catch (Exception $e) {}
    }

    // ── SESIÓN ────────────────────────────────────────────────────────────────

    public static function ensureSession(): void
    {
        self::startSession();
    }

    private static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;
        $isPortero = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/qr-reader/') !== false;
        // Nombre de cookie distinto para portero: así su sesión nunca se mezcla
        // con la del admin ni con la del cliente, aunque compartan navegador.
        session_name($isPortero ? 'eventoapp_portero' : 'eventoapp_sess');
        $lifetime  = $isPortero ? 2592000 : 0; // 30 días para portero
        $secure    = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // ── PORTERO ───────────────────────────────────────────────────────────────

    public static function porteroEventos(): array
    {
        $id = self::adminId();
        if (!$id) return [];
        if (self::adminRole() !== 'portero') {
            return db()->query('SELECT id FROM eventos WHERE activo=1 AND archivado=0')->fetchAll(PDO::FETCH_COLUMN);
        }
        $st = db()->prepare('SELECT evento_id FROM portero_eventos WHERE admin_id=?');
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    // ── CAMARERO ──────────────────────────────────────────────────────────────

    public static function camareroEventos(): array
    {
        $id = self::adminId();
        if (!$id) return [];
        if (self::adminRole() !== 'camarero') {
            return db()->query('SELECT id FROM eventos WHERE activo=1 AND archivado=0')->fetchAll(PDO::FETCH_COLUMN);
        }
        $st = db()->prepare('SELECT evento_id FROM portero_eventos WHERE admin_id=?');
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }
}
