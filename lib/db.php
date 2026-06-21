<?php
/**
 * DB + Settings + funciones globales
 */

$_cfg = __DIR__ . '/../config.php';
if (!file_exists($_cfg)) {
    $inst = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/install/') !== false) ? '' : '<a href="/install/">Instalar ahora</a>';
    die('<div style="font-family:sans-serif;padding:40px;max-width:500px;margin:60px auto;border:1px solid #fcc;border-radius:10px;background:#fff1f0"><h2>Falta config.php</h2><p style="margin-top:10px;">Ejecuta el instalador. ' . $inst . '</p></div>');
}
require_once $_cfg;

set_exception_handler(function (Throwable $e) {
    error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    echo '<div style="font-family:sans-serif;padding:30px;max-width:600px;margin:40px auto;border:1px solid #fcc;border-radius:8px;background:#fff1f0">'
        . '<h2>Ha ocurrido un error</h2>'
        . '<p style="margin-top:8px;">Inténtalo de nuevo. Si el problema persiste, contacta con el administrador.</p>'
        . '</div>';
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log($err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) http_response_code(500);
        echo '<div style="font-family:sans-serif;padding:30px;max-width:600px;margin:40px auto;border:1px solid #fcc;border-radius:8px;background:#fff1f0">'
            . '<h2>Ha ocurrido un error</h2>'
            . '<p style="margin-top:8px;">Inténtalo de nuevo. Si el problema persiste, contacta con el administrador.</p>'
            . '</div>';
    }
});

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        die('<div style="font-family:sans-serif;padding:30px;max-width:600px;margin:40px auto;border:1px solid #fcc;border-radius:8px;background:#fff1f0"><h2>Error de conexión a la BD</h2><p style="margin-top:8px;">' . htmlspecialchars($e->getMessage()) . '</p></div>');
    }
    return $pdo;
}

function getSetting(string $key, string $default = ''): string
{
    static $cache = [];
    if (!isset($cache[$key])) {
        try {
            $st = db()->prepare('SELECT value FROM settings WHERE `key`=?');
            $st->execute([$key]);
            $val = $st->fetchColumn();
            $cache[$key] = ($val !== false) ? $val : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
    return $cache[$key];
}

function setSetting(string $key, string $value): void
{
    static $cache = [];
    $cache[$key] = $value;
    db()->prepare('INSERT INTO settings (`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')
       ->execute([$key, $value]);
}

function generateToken(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function baseUrl(): string
{
    $base = getSetting('app_base_url');
    if ($base) return rtrim($base, '/');
    if (defined('APP_BASE_URL')) return rtrim(APP_BASE_URL, '/');
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $msg): void
{
    if (class_exists('Auth')) Auth::ensureSession();
    elseif (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array
{
    if (class_exists('Auth')) Auth::ensureSession();
    elseif (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function runMigrations(): void
{
    try {
        $pdo = db();

        // TABLA SETTINGS
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(100) NOT NULL UNIQUE,
            `value` TEXT,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TABLA ADMIN_USERS
        $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(100) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `email` VARCHAR(300) NOT NULL,
            `name` VARCHAR(200) DEFAULT '',
            `role` ENUM('superadmin','admin','portero') DEFAULT 'admin',
            `active` TINYINT(1) DEFAULT 1,
            `last_login` DATETIME DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TABLA USERS (clientes)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL,
            `email` VARCHAR(300) NOT NULL UNIQUE,
            `phone` VARCHAR(30) DEFAULT '',
            `password_hash` VARCHAR(255) NOT NULL,
            `email_verified` TINYINT(1) DEFAULT 0,
            `email_verify_token` VARCHAR(64) DEFAULT NULL,
            `reset_token` VARCHAR(64) DEFAULT NULL,
            `reset_token_expires` DATETIME DEFAULT NULL,
            `active` TINYINT(1) DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TABLA EVENTOS
        $pdo->exec("CREATE TABLE IF NOT EXISTS `eventos` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `slug` VARCHAR(100) NOT NULL UNIQUE,
            `nombre` VARCHAR(200) NOT NULL,
            `descripcion` TEXT DEFAULT NULL,
            `imagen` VARCHAR(300) DEFAULT NULL,
            `fecha_evento` DATETIME DEFAULT NULL,
            `lugar` VARCHAR(300) DEFAULT NULL,
            `precio` DECIMAL(10,2) DEFAULT 0.00,
            `es_gratuito` TINYINT(1) DEFAULT 0,
            `max_inscritos` INT UNSIGNED DEFAULT NULL,
            `fecha_limite_inscripcion` DATETIME DEFAULT NULL,
            `metodos_pago` VARCHAR(200) DEFAULT 'stripe',
            `campo_qr_extra` VARCHAR(100) DEFAULT NULL,
            `activo` TINYINT(1) DEFAULT 1,
            `archivado` TINYINT(1) DEFAULT 0,
            `fecha_archivo` DATETIME DEFAULT NULL,
            `sort_order` INT UNSIGNED DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TABLA EVENTO_CAMPOS (campos personalizados)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `evento_campos` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `evento_id` INT UNSIGNED NOT NULL,
            `nombre` VARCHAR(100) NOT NULL,
            `label` VARCHAR(200) NOT NULL,
            `tipo` ENUM('text','email','tel','number','select','textarea','checkbox','date') DEFAULT 'text',
            `opciones` TEXT DEFAULT NULL,
            `obligatorio` TINYINT(1) DEFAULT 0,
            `para_titular` TINYINT(1) DEFAULT 1,
            `para_asistentes` TINYINT(1) DEFAULT 1,
            `sort_order` INT UNSIGNED DEFAULT 0,
            FOREIGN KEY (`evento_id`) REFERENCES `eventos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TABLA INSCRIPCIONES
        $pdo->exec("CREATE TABLE IF NOT EXISTS `inscripciones` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `numero_pedido` VARCHAR(50) NOT NULL UNIQUE,
            `evento_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `num_personas` INT UNSIGNED DEFAULT 1,
            `precio_total` DECIMAL(10,2) DEFAULT 0.00,
            `metodo_pago` ENUM('stripe','redsys','bizum','transferencia') NOT NULL,
            `estado_pago` ENUM('pendiente','pagado','cancelado','reembolsado') DEFAULT 'pendiente',
            `stripe_payment_intent` VARCHAR(200) DEFAULT NULL,
            `redsys_order` VARCHAR(50) DEFAULT NULL,
            `redsys_auth` VARCHAR(100) DEFAULT NULL,
            `confirmado_por` INT UNSIGNED DEFAULT NULL,
            `confirmado_at` DATETIME DEFAULT NULL,
            `notas_admin` TEXT DEFAULT NULL,
            `email_pedido_enviado` TINYINT(1) DEFAULT 0,
            `email_entradas_enviado` TINYINT(1) DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`evento_id`) REFERENCES `eventos`(`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TABLA ENTRADAS (una por persona)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `entradas` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `inscripcion_id` INT UNSIGNED NOT NULL,
            `evento_id` INT UNSIGNED NOT NULL,
            `qr_token` VARCHAR(64) NOT NULL UNIQUE,
            `qr_hash` VARCHAR(128) NOT NULL,
            `nombre_asistente` VARCHAR(200) NOT NULL,
            `es_titular` TINYINT(1) DEFAULT 0,
            `campos_extra` JSON DEFAULT NULL,
            `usado` TINYINT(1) DEFAULT 0,
            `usado_at` DATETIME DEFAULT NULL,
            `usado_por` INT UNSIGNED DEFAULT NULL,
            `pdf_path` VARCHAR(300) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`inscripcion_id`) REFERENCES `inscripciones`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`evento_id`) REFERENCES `eventos`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TABLA QR_LOGS
        $pdo->exec("CREATE TABLE IF NOT EXISTS `qr_logs` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `entrada_id` INT UNSIGNED NOT NULL,
            `evento_id` INT UNSIGNED NOT NULL,
            `admin_id` INT UNSIGNED DEFAULT NULL,
            `resultado` ENUM('ok','ya_usado','invalido') NOT NULL,
            `ip` VARCHAR(45) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`entrada_id`) REFERENCES `entradas`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TABLA PORTERO_EVENTOS (asignaciones de portero a eventos)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `portero_eventos` (
            `admin_id` INT UNSIGNED NOT NULL,
            `evento_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`admin_id`,`evento_id`),
            FOREIGN KEY (`admin_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`evento_id`) REFERENCES `eventos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TABLA ADMIN_LOG
        $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_log` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `action` VARCHAR(200) DEFAULT '',
            `detail` TEXT DEFAULT NULL,
            `ip` VARCHAR(45) DEFAULT '',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Columna codigo_corto en entradas (código manual de 6 caracteres, alternativa al QR largo)
        $col = $pdo->query("SHOW COLUMNS FROM entradas LIKE 'codigo_corto'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE entradas ADD COLUMN codigo_corto VARCHAR(8) NULL AFTER qr_hash");
            $pdo->exec("ALTER TABLE entradas ADD UNIQUE KEY uq_entradas_codigo_corto (codigo_corto)");
        }

        // Columna admin_id en eventos (aislamiento multi-admin: cada evento pertenece a un admin)
        $col = $pdo->query("SHOW COLUMNS FROM eventos LIKE 'admin_id'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE eventos ADD COLUMN admin_id INT UNSIGNED NULL AFTER id");
            $pdo->exec("ALTER TABLE eventos ADD INDEX idx_eventos_admin_id (admin_id)");
            try {
                $pdo->exec("ALTER TABLE eventos ADD CONSTRAINT fk_eventos_admin_id FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL");
            } catch (Exception $e) {
                // Si falla el FK (motor/orden de tablas), seguimos sin él; el índice ya filtra
            }
        }

        // Columna lugar_url en eventos (enlace a Google Maps del lugar del evento)
        $col = $pdo->query("SHOW COLUMNS FROM eventos LIKE 'lugar_url'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE eventos ADD COLUMN lugar_url VARCHAR(500) NULL AFTER lugar");
        }

        // Valor 'fallido' en estado_pago de inscripciones (pedidos abandonados en pasarela)
        $col = $pdo->query("SHOW COLUMNS FROM inscripciones LIKE 'estado_pago'")->fetch();
        if ($col && stripos($col['Type'], "'fallido'") === false) {
            $pdo->exec("ALTER TABLE inscripciones MODIFY estado_pago ENUM('pendiente','pagado','cancelado','reembolsado','fallido') DEFAULT 'pendiente'");
        }

        // Auto-archivar eventos por fecha
        $pdo->exec("UPDATE eventos SET archivado=1, fecha_archivo=NOW()
            WHERE archivado=0 AND activo=1
            AND fecha_evento IS NOT NULL AND fecha_evento < DATE_SUB(NOW(), INTERVAL 1 DAY)");

        // Sanear permisos de carpetas de imágenes públicas (logo, imágenes de eventos),
        // por si quedaron creadas con permisos demasiado restrictivos (0750) que impiden
        // que el servidor web las sirva.
        $storageDir = __DIR__ . '/../storage';
        if (is_dir($storageDir)) {
            @chmod($storageDir, 0755);
            foreach (glob($storageDir . '/logo.*') ?: [] as $f) @chmod($f, 0644);
        }
        $imgDir = $storageDir . '/imagenes';
        if (is_dir($imgDir)) {
            @chmod($imgDir, 0755);
            foreach (glob($imgDir . '/*') ?: [] as $f) if (is_file($f)) @chmod($f, 0644);
        }

        // Generar codigo_corto para entradas antiguas que no lo tengan
        $stSinCodigo = $pdo->query("SELECT id FROM entradas WHERE codigo_corto IS NULL LIMIT 500");
        $idsSinCodigo = $stSinCodigo->fetchAll(PDO::FETCH_COLUMN);
        if ($idsSinCodigo) {
            require_once __DIR__ . '/TicketManager.php';
            $stUpd = $pdo->prepare('UPDATE entradas SET codigo_corto=? WHERE id=?');
            foreach ($idsSinCodigo as $entId) {
                $stUpd->execute([TicketManager::generarCodigoCorto(), $entId]);
            }
        }

    } catch (Exception $e) {
        // Ignorar durante instalación
    }
}

runMigrations();
