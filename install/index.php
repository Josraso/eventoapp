<?php
/**
 * Instalador de EventoApp
 */

// Sesión siempre al principio
if (session_status() === PHP_SESSION_NONE) session_start();

// Bloquear si ya está instalado
if (file_exists(__DIR__ . '/../config.php')) {
    $ok = false;
    try {
        require_once __DIR__ . '/../config.php';
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
        $pdo->query('SELECT id FROM admin_users LIMIT 1');
        $ok = true;
    } catch(Exception $e) {}
    if ($ok) {
        die('<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Ya instalado</title>
<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f4f4f6;}</style></head><body>
<div style="text-align:center;background:#fff;border-radius:16px;padding:48px;border:1px solid #e0e0e0;max-width:400px;">
<div style="font-size:52px;margin-bottom:16px;">✅</div>
<h2>EventoApp ya está instalado</h2>
<p style="color:#888;margin:12px 0 24px;">La aplicación está lista y funcionando.</p>
<a href="../admin/" style="background:#6366f1;color:#fff;text-decoration:none;padding:12px 24px;border-radius:9px;font-weight:600;">Ir al panel admin</a>
</div></body></html>');
    }
}

$step  = (int)($_GET['step'] ?? 1);
$error = '';
$data  = $_SESSION['install'] ?? [];

// Comprobaciones de requisitos
$checks = [
    'PHP >= 7.4'                        => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO MySQL'                          => extension_loaded('pdo_mysql'),
    'OpenSSL'                            => extension_loaded('openssl'),
    'mbstring'                           => extension_loaded('mbstring'),
    'GD / Imagick'                       => extension_loaded('gd') || extension_loaded('imagick'),
    'Vendor existe (composer install)'   => is_dir(__DIR__ . '/../vendor'),
    'storage/ escribible o creatable'    => (is_dir(__DIR__.'/../storage') && is_writable(__DIR__.'/../storage'))
                                            || is_writable(__DIR__.'/..'),
];
$allPassed = !in_array(false, $checks);

// ── PASO 2: BD ─────────────────────────────────────────────────────────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'db_host' => trim($_POST['db_host'] ?? 'localhost'),
        'db_port' => (int)($_POST['db_port'] ?? 3306),
        'db_name' => trim($_POST['db_name'] ?? ''),
        'db_user' => trim($_POST['db_user'] ?? ''),
        'db_pass' => $_POST['db_pass'] ?? '',
    ];
    try {
        $pdo = new PDO(
            "mysql:host={$d['db_host']};port={$d['db_port']};charset=utf8mb4",
            $d['db_user'], $d['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$d['db_name']}`");
        $_SESSION['install'] = array_merge($data, $d);
        header('Location: ?step=3'); exit;
    } catch(Exception $e) {
        $error = 'No se pudo conectar a la BD: ' . $e->getMessage();
    }
}

// ── PASO 3: CONFIG ─────────────────────────────────────────────────────────────
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d          = $_SESSION['install'] ?? [];
    $siteUrl    = rtrim(trim($_POST['site_url'] ?? ''), '/');
    $siteName   = trim($_POST['site_name'] ?? 'Eventos');
    $adminUser  = trim($_POST['admin_user'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_pass'] ?? '';
    $adminPass2 = $_POST['admin_pass2'] ?? '';

    if (!$adminUser || !$adminEmail || !$adminPass)
        $error = 'Completa todos los campos.';
    elseif (strlen($adminPass) < 8)
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    elseif ($adminPass !== $adminPass2)
        $error = 'Las contraseñas no coinciden.';
    elseif (empty($d['db_name']))
        $error = 'Faltan datos de BD. Vuelve al paso 2.';
    else {
        try {
            $secret = bin2hex(random_bytes(32));
            $config = '<?php' . PHP_EOL
                . '// Generado por el instalador — ' . date('Y-m-d H:i:s') . PHP_EOL
                . "define('DB_HOST', " . var_export($d['db_host'], true) . ");" . PHP_EOL
                . "define('DB_PORT', " . (int)$d['db_port'] . ");" . PHP_EOL
                . "define('DB_NAME', " . var_export($d['db_name'], true) . ");" . PHP_EOL
                . "define('DB_USER', " . var_export($d['db_user'], true) . ");" . PHP_EOL
                . "define('DB_PASS', " . var_export($d['db_pass'], true) . ");" . PHP_EOL
                . "define('APP_SECRET', " . var_export($secret, true) . ");" . PHP_EOL
                . "define('APP_BASE_URL', " . var_export($siteUrl, true) . ");" . PHP_EOL;

            file_put_contents(__DIR__ . '/../config.php', $config);

            require_once __DIR__ . '/../lib/db.php';
            runMigrations();

            db()->prepare('INSERT INTO admin_users (username,name,email,password_hash,role) VALUES(?,?,?,?,?)')
               ->execute([$adminUser, $adminUser, $adminEmail, password_hash($adminPass, PASSWORD_BCRYPT), 'superadmin']);

            setSetting('site_name',       $siteName);
            setSetting('app_base_url',    $siteUrl);
            setSetting('mail_method',     'sendmail');
            setSetting('mail_from_email', $adminEmail);
            setSetting('mail_from_name',  $siteName);

            foreach ([
                __DIR__.'/../storage'          => 0755,
                __DIR__.'/../storage/entradas' => 0750,
                __DIR__.'/../storage/imagenes' => 0755,
                __DIR__.'/../logs'             => 0750,
            ] as $dir => $perm) {
                if (!is_dir($dir)) mkdir($dir, $perm, true);
                chmod($dir, $perm);
            }
            file_put_contents(__DIR__.'/../storage/.htaccess', "Options -Indexes\n\n<FilesMatch \"\\.(php|phtml|php\\d?)\$\">\n    Require all denied\n</FilesMatch>\n");
            file_put_contents(__DIR__.'/../storage/entradas/.htaccess', "Require all denied\n");
            file_put_contents(__DIR__.'/../logs/.htaccess',    "Options -Indexes\nRequire all denied\n");

            unset($_SESSION['install']);
            header('Location: ?step=4'); exit;
        } catch(Exception $e) {
            $error = 'Error durante la instalación: ' . $e->getMessage();
            @unlink(__DIR__ . '/../config.php');
        }
    }
}

$steps = ['Requisitos', 'Base de datos', 'Configuración', '¡Listo!'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalador EventoApp</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f0f3;color:#1a1a1a;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;}
.wrap{width:100%;max-width:580px;}
.head{text-align:center;margin-bottom:28px;}
.head h1{font-size:28px;font-weight:800;letter-spacing:-.03em;margin-bottom:6px;}
.head p{font-size:14px;color:#888;}
.steps{display:flex;gap:4px;margin-bottom:28px;}
.step-pill{flex:1;text-align:center;padding:8px 4px;border-radius:8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
.step-pill.done{background:#e6f9ee;color:#1a7a3a;}
.step-pill.active{background:#6366f1;color:#fff;}
.step-pill.todo{background:#f0f0f0;color:#bbb;}
.card{background:#fff;border-radius:14px;border:1px solid #e4e4e8;padding:28px;}
h2{font-size:18px;font-weight:700;margin-bottom:6px;}
p.sub{font-size:13px;color:#888;margin-bottom:20px;}
label{font-size:11px;font-weight:700;color:#777;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em;}
input,select{width:100%;border:1.5px solid #e0e0e0;border-radius:9px;padding:10px 13px;font-size:14px;outline:none;font-family:inherit;color:#1a1a1a;background:#fff;transition:border-color .15s;margin-bottom:14px;}
input:focus,select:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.08);}
.field-row{display:flex;gap:10px;}
.field-row input{margin-bottom:0;}
.btn{display:block;width:100%;background:#6366f1;color:#fff;border:none;border-radius:9px;padding:13px;font-size:15px;font-weight:700;cursor:pointer;margin-top:6px;font-family:inherit;}
.btn:hover{background:#4f52e0;}
.check-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f0f0;font-size:14px;}
.check-row:last-child{border-bottom:none;}
.ok{color:#1a7a3a;font-weight:700;}
.ko{color:#c0392b;font-weight:700;}
.err{background:#fff1f0;border:1.5px solid #fcc;border-radius:9px;padding:12px 14px;font-size:13px;color:#c0392b;margin-bottom:16px;}
.next-btn{display:block;text-decoration:none;background:#6366f1;color:#fff;padding:13px 24px;border-radius:9px;font-weight:700;font-size:15px;text-align:center;width:100%;margin-top:16px;border:none;cursor:pointer;font-family:inherit;}
.next-btn:hover{background:#4f52e0;}
.success-box{text-align:center;padding:20px 0;}
.success-box .icon{font-size:64px;margin-bottom:16px;}
.sect{border-top:1px solid #f0f0f0;margin:16px 0 14px;padding-top:16px;font-size:12px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.06em;}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>🎫 EventoApp</h1>
    <p>Instalador — sigue los pasos para configurar la aplicación</p>
  </div>

  <div class="steps">
    <?php foreach ($steps as $i => $s):
      $n   = $i + 1;
      $cls = $n < $step ? 'done' : ($n === $step ? 'active' : 'todo');
    ?>
    <div class="step-pill <?= $cls ?>">
      <?= $n < $step ? '✓ ' : $n . '. ' ?><?= $s ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card">

  <?php if ($step === 1): ?>
    <!-- PASO 1: REQUISITOS -->
    <h2>Comprobación de requisitos</h2>
    <p class="sub">Verificando que el servidor cumple los requisitos mínimos.</p>
    <?php foreach ($checks as $label => $ok): ?>
      <div class="check-row">
        <span><?= htmlspecialchars($label) ?></span>
        <span class="<?= $ok ? 'ok' : 'ko' ?>"><?= $ok ? '✓ OK' : '✗ Falta' ?></span>
      </div>
    <?php endforeach; ?>

    <?php if ($allPassed): ?>
      <a href="?step=2" class="next-btn">Continuar →</a>
    <?php else: ?>
      <div class="err" style="margin-top:16px;">
        ⚠️ Algunos requisitos no se cumplen. Instala las extensiones faltantes
        o ejecuta <code>composer install</code> y recarga la página.
      </div>
      <a href="?step=1" class="next-btn" style="background:#888;">Volver a comprobar</a>
    <?php endif; ?>

  <?php elseif ($step === 2): ?>
    <!-- PASO 2: BASE DE DATOS -->
    <h2>Base de datos</h2>
    <p class="sub">Datos de conexión MySQL. Si la base de datos no existe, se creará automáticamente.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="field-row">
        <div style="flex:2"><label>Host</label><input type="text" name="db_host" value="localhost" required></div>
        <div style="flex:1"><label>Puerto</label><input type="number" name="db_port" value="3306"></div>
      </div>
      <label>Nombre de la base de datos</label>
      <input type="text" name="db_name" required placeholder="eventoapp">
      <label>Usuario MySQL</label>
      <input type="text" name="db_user" required placeholder="root">
      <label>Contraseña MySQL</label>
      <input type="password" name="db_pass" placeholder="(vacía si local)">
      <button type="submit" class="btn">Conectar y continuar →</button>
    </form>

  <?php elseif ($step === 3): ?>
    <!-- PASO 3: CONFIGURACIÓN -->
    <h2>Configuración</h2>
    <p class="sub">URL de tu sitio y cuenta de superadministrador.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <label>Nombre del sitio</label>
      <input type="text" name="site_name" value="Eventos" required>
      <label>URL base (sin / al final)</label>
      <?php
        $proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path     = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $guessUrl = $proto . '://' . $host . $path;
      ?>
      <input type="url" name="site_url" value="<?= htmlspecialchars($guessUrl) ?>" required>

      <div class="sect">Superadministrador</div>
      <label>Usuario</label>
      <input type="text" name="admin_user" required autofocus>
      <label>Email</label>
      <input type="email" name="admin_email" required>
      <div class="field-row">
        <div style="flex:1"><label>Contraseña (mín. 8 caracteres)</label><input type="password" name="admin_pass" required minlength="8"></div>
        <div style="flex:1"><label>Repetir contraseña</label><input type="password" name="admin_pass2" required minlength="8"></div>
      </div>
      <button type="submit" class="btn">Instalar →</button>
    </form>

  <?php elseif ($step === 4): ?>
    <!-- PASO 4: LISTO -->
    <div class="success-box">
      <div class="icon">🎉</div>
      <h2 style="font-size:22px;margin-bottom:10px;">¡Instalación completada!</h2>
      <p style="color:#888;margin-bottom:24px;">EventoApp está listo y funcionando.</p>
      <div style="background:#f0f4ff;border-radius:10px;padding:16px;text-align:left;font-size:13px;margin-bottom:20px;">
        <strong>Próximos pasos recomendados:</strong>
        <ol style="margin-top:8px;padding-left:18px;line-height:2.2;">
          <li>Elimina o protege el directorio <code>install/</code></li>
          <li>Configura el SMTP en <strong>Ajustes</strong> para el envío de emails</li>
          <li>Añade tus claves de Stripe y/o Redsys</li>
          <li>Crea tu primer evento</li>
        </ol>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <a href="../admin/" class="next-btn">Ir al panel admin →</a>
        <a href="../public/" class="next-btn" style="background:#1a1a1a;">Ver web pública →</a>
      </div>
    </div>

  <?php endif; ?>

  </div><!-- /card -->
</div><!-- /wrap -->
</body>
</html>
