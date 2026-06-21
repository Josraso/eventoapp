<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
Auth::ensureSession();

// Ya logado → al dashboard
if (!empty($_SESSION['admin_id'])) {
    $base = defined('APP_BASE_URL') ? rtrim(APP_BASE_URL, '/') : '';
    header('Location: ' . $base . '/admin/index.php');
    exit;
}

$siteName = getSetting('site_name', 'Eventos');
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Token CSRF manual para el login (sin sesión previa)
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Token inválido, recarga la página.';
    } elseif (Auth::adminLogin($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        if (Auth::adminRole() === 'portero') {
            Auth::adminLogout();
            $error = 'Los porteros acceden desde: <a href="../qr-reader/" style="color:#4ade80">/qr-reader/</a>';
        } else {
            $base = defined('APP_BASE_URL') ? rtrim(APP_BASE_URL, '/') : '';
            header('Location: ' . $base . '/admin/index.php');
            exit;
        }
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}

// Generar CSRF para el form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — <?= htmlspecialchars($siteName, ENT_QUOTES) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f0f10;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;}
.card{background:#1a1a1c;border:1px solid #2a2a2e;border-radius:16px;padding:40px;width:360px;max-width:95vw;}
h1{font-size:22px;font-weight:800;margin-bottom:6px;}
.sub{font-size:13px;color:#666;margin-bottom:24px;}
label{font-size:11px;font-weight:700;color:#666;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;margin-top:14px;}
input{width:100%;background:#0f0f10;border:1.5px solid #2a2a2e;border-radius:9px;padding:11px 13px;font-size:14px;color:#fff;outline:none;font-family:inherit;}
input:focus{border-color:#6366f1;}
.btn{width:100%;background:#6366f1;color:#fff;border:none;border-radius:9px;padding:13px;font-size:15px;font-weight:700;cursor:pointer;margin-top:20px;font-family:inherit;}
.btn:hover{background:#4f52e0;}
.err{background:#3a0a0a;border:1px solid #c0392b;border-radius:8px;padding:10px;font-size:13px;color:#ff6b6b;margin-bottom:14px;}
</style>
</head>
<body>
<div class="card">
  <h1>⚙️ Panel Admin</h1>
  <p class="sub"><?= htmlspecialchars($siteName, ENT_QUOTES) ?></p>
  <?php if ($error): ?><div class="err"><?= $error ?></div><?php endif; ?>
  <form method="POST" action="">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <label>Usuario</label>
    <input type="text" name="username" required autofocus autocomplete="username">
    <label>Contraseña</label>
    <input type="password" name="password" required autocomplete="current-password">
    <button type="submit" class="btn">Entrar</button>
  </form>
</div>
</body>
</html>
