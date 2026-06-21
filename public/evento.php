<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/TicketManager.php';
require_once __DIR__ . '/../lib/Mailer.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['slug'] ?? ''));
if (!$slug) { redirect('index.php'); }

// Cargar evento
$stEv = db()->prepare('SELECT * FROM eventos WHERE slug=? AND activo=1');
$stEv->execute([$slug]);
$evento = $stEv->fetch();
if (!$evento) {
    flash('error', 'Evento no encontrado.');
    redirect('index.php');
}

// Comprobar disponibilidad
$stCnt = db()->prepare("SELECT COUNT(*) FROM entradas en JOIN inscripciones i ON i.id=en.inscripcion_id WHERE i.evento_id=? AND i.estado_pago='pagado'");
$stCnt->execute([$evento['id']]);
$totalInscritos = (int)$stCnt->fetchColumn();
$lleno   = $evento['max_inscritos'] && $totalInscritos >= $evento['max_inscritos'];
$cerrado = $evento['fecha_limite_inscripcion'] && strtotime($evento['fecha_limite_inscripcion']) < time();
$archivado = (bool)$evento['archivado'];
$disponible = !$lleno && !$cerrado && !$archivado;

// Campos personalizados
$campos = db()->prepare('SELECT * FROM evento_campos WHERE evento_id=? ORDER BY sort_order ASC');
$campos->execute([$evento['id']]);
$campos = $campos->fetchAll();

// Métodos de pago activos para este evento
$metodosActivos = array_filter(explode(',', $evento['metodos_pago'] ?? ''));

$siteName = getSetting('site_name', 'Eventos');
$isLogged = Auth::isUserLogged();
$user     = $isLogged ? Auth::getUser() : null;
$error    = '';
$step     = 'info'; // info | auth | inscripcion

// ¿El usuario ya tiene inscripción en este evento?
$yaInscrito = false;
if ($isLogged) {
    $chk = db()->prepare("SELECT COUNT(*) FROM inscripciones WHERE evento_id=? AND user_id=? AND estado_pago IN ('pendiente','pagado')");
    $chk->execute([$evento['id'], Auth::userId()]);
    $yaInscrito = (int)$chk->fetchColumn() > 0;
}

// Determinar step según GET
if (isset($_GET['step'])) {
    $step = in_array($_GET['step'], ['auth', 'inscripcion']) ? $_GET['step'] : 'info';
}
if ($isLogged && $step === 'auth') {
    $step = 'inscripcion';
}
if (!$isLogged && $step === 'inscripcion') {
    $step = 'auth';
}

// ── PROCESAR INSCRIPCIÓN ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Auth::checkCsrf();

    // LOGIN desde modal de evento
    if ($_POST['action'] === 'login') {
        $email = trim($_POST['email_login'] ?? '');
        $pass  = trim($_POST['pass_login'] ?? '');
        if (Auth::userLogin($email, $pass)) {
            redirect('evento.php?slug=' . urlencode($slug) . '&step=inscripcion');
        } else {
            $error = 'Email o contraseña incorrectos.';
            $step  = 'auth';
        }
    }

    // REGISTRO desde modal de evento
    if ($_POST['action'] === 'registro') {
        $nombre  = trim($_POST['nombre'] ?? '');
        $email   = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $telefono = trim($_POST['telefono'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $pass2   = $_POST['password2'] ?? '';
        $captcha = $_POST['g-recaptcha-response'] ?? '';

        if (!$nombre || !$email || !$pass)
            $error = 'Completa todos los campos obligatorios.';
        elseif (strlen($pass) < 8)
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        elseif ($pass !== $pass2)
            $error = 'Las contraseñas no coinciden.';
        elseif (getSetting('recaptcha_secret') && !verificarRecaptcha($captcha))
            $error = 'Por favor verifica que no eres un robot.';
        else {
            // ¿Email ya existe?
            $chk = db()->prepare('SELECT id FROM users WHERE email=?');
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error = 'Ya existe una cuenta con ese email. <a href="?slug=' . urlencode($slug) . '&step=auth&modo=login">Inicia sesión</a>.';
                $step = 'auth';
            } else {
                $verifyToken = generateToken(32);
                db()->prepare('INSERT INTO users (name,email,phone,password_hash,email_verify_token,email_verified) VALUES(?,?,?,?,?,0)')
                   ->execute([$nombre, $email, $telefono, password_hash($pass, PASSWORD_BCRYPT), $verifyToken]);
                $uid = db()->lastInsertId();

                // Email verificación (no bloquea el flujo)
                $link = baseUrl() . '/public/verificar.php?token=' . $verifyToken;
                $m = new Mailer();
                $m->send($email, $nombre, 'Verifica tu email — ' . $siteName, Mailer::tplVerificacion(['name' => $nombre], $link));

                Auth::userLogin($email, $pass);
                redirect('evento.php?slug=' . urlencode($slug) . '&step=inscripcion');
            }
        }
        if (!$error) $step = 'auth';
    }

    // INSCRIPCIÓN
    if ($_POST['action'] === 'inscribir' && $isLogged) {
        $numPersonas = max(1, min(20, (int)($_POST['num_personas'] ?? 1)));
        $metodoPago  = $_POST['metodo_pago'] ?? '';

        if (!in_array($metodoPago, $metodosActivos))
            $error = 'Método de pago no válido.';

        $yoAsisto = isset($_POST['yo_asisto']);

        $asistentes = [];
        for ($i = 1; $i <= $numPersonas; $i++) {
            $nombre_asi = trim($_POST["asistente_{$i}_nombre"] ?? '');
            if (!$nombre_asi) { $error = 'El nombre del asistente ' . $i . ' es obligatorio.'; break; }
            $camposAsi = [];
            foreach ($campos as $campo) {
                $aplica = ($i === 1 && $campo['para_titular']) || ($i > 1 && $campo['para_asistentes']);
                if (!$aplica) continue;
                $val = trim($_POST["asistente_{$i}_campo_{$campo['id']}"] ?? '');
                if ($campo['obligatorio'] && !$val) {
                    $error = 'El campo "' . $campo['label'] . '" es obligatorio para el asistente ' . $i . '.';
                    break 2;
                }
                $camposAsi[$campo['nombre']] = $val;
            }
            $asistentes[] = ['nombre' => $nombre_asi, 'campos' => $camposAsi, 'es_titular' => ($i === 1 && $yoAsisto)];
        }

        if (!$error) {
            $precioTotal = $evento['es_gratuito'] ? 0 : (float)$evento['precio'] * $numPersonas;
            $numeroPedido = TicketManager::generarNumeroPedido();

            // Insertar inscripción. Stripe/Redsys se crean como 'fallido' desde el inicio
            // (pasan a 'pagado' solo si el usuario completa el pago en la pasarela); así
            // un pedido abandonado nunca aparece como pendiente real en "Mis pedidos".
            $estadoInicial = in_array($metodoPago, ['stripe', 'redsys']) ? 'fallido' : 'pendiente';
            db()->prepare('INSERT INTO inscripciones (numero_pedido,evento_id,user_id,num_personas,precio_total,metodo_pago,estado_pago) VALUES(?,?,?,?,?,?,?)')
               ->execute([$numeroPedido, $evento['id'], Auth::userId(), $numPersonas, $precioTotal, $metodoPago, $estadoInicial]);
            $inscripcionId = (int)db()->lastInsertId();

            // Insertar entradas
            foreach ($asistentes as $asi) {
                $qrData = TicketManager::generarQRToken($inscripcionId, $evento['id'], $inscripcionId);
                $codigoCorto = TicketManager::generarCodigoCorto();
                // Lo actualizamos después con el ID real
                db()->prepare('INSERT INTO entradas (inscripcion_id,evento_id,qr_token,qr_hash,codigo_corto,nombre_asistente,es_titular,campos_extra) VALUES(?,?,?,?,?,?,?,?)')
                   ->execute([$inscripcionId, $evento['id'], $qrData['token'], $qrData['hash'], $codigoCorto,
                       $asi['nombre'], $asi['es_titular'] ? 1 : 0, json_encode($asi['campos'])]);
                $entradaId = (int)db()->lastInsertId();
                // Regenerar hash con ID real
                $qrData2 = TicketManager::generarQRToken($entradaId, $evento['id'], $inscripcionId);
                db()->prepare('UPDATE entradas SET qr_token=?,qr_hash=? WHERE id=?')
                   ->execute([$qrData2['token'], $qrData2['hash'], $entradaId]);
            }

            // Guardar en sesión para el proceso de pago
            Auth::ensureSession();
            $_SESSION['inscripcion_id']     = $inscripcionId;
            $_SESSION['inscripcion_pedido'] = $numeroPedido;

            // Redirigir según método de pago
            switch ($metodoPago) {
                case 'stripe':
                    redirect('../pago/stripe.php?inscripcion=' . $inscripcionId);
                    break;
                case 'redsys':
                    redirect('../pago/redsys/iniciar.php?inscripcion=' . $inscripcionId);
                    break;
                case 'bizum':
                case 'transferencia':
                    // Email de instrucciones
                    $sts = db()->prepare('SELECT * FROM entradas WHERE inscripcion_id=?');
                    $sts->execute([$inscripcionId]);
                    $entradasDB = $sts->fetchAll();
                    $m = new Mailer();
                    $ins = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
                    $ins->execute([$inscripcionId]);
                    $insRow = $ins->fetch();
                    $m->send($user['email'], $user['name'],
                        'Inscripción pendiente de pago — ' . $evento['nombre'],
                        Mailer::tplPedidoPendiente($insRow, $evento, $user, $entradasDB));
                    db()->prepare('UPDATE inscripciones SET email_pedido_enviado=1 WHERE id=?')->execute([$inscripcionId]);
                    redirect('pedido-pendiente.php?pedido=' . urlencode($numeroPedido));
                    break;
                case 'gratis':
                    procesarInscripcionGratis($inscripcionId, $evento, $user);
                    redirect('mi-cuenta.php?ok=1');
                    break;
            }

            if ($evento['es_gratuito']) {
                procesarInscripcionGratis($inscripcionId, $evento, $user);
                redirect('mi-cuenta.php?ok=1');
            }
        }
        $step = 'inscripcion';
    }
}

function procesarInscripcionGratis(int $inscripcionId, array $evento, array $user): void
{
    db()->prepare("UPDATE inscripciones SET estado_pago='pagado', confirmado_at=NOW() WHERE id=?")
       ->execute([$inscripcionId]);
    $paths = TicketManager::generarPDFsInscripcion($inscripcionId);
    $atts = array_map(fn($p) => ['path' => $p], $paths);
    $ins = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
    $ins->execute([$inscripcionId]);
    $insRow = $ins->fetch();
    $m = new Mailer();
    $m->send($user['email'], $user['name'],
        'Tus entradas — ' . $evento['nombre'],
        Mailer::tplEntradas($insRow, $evento, $user), $atts);
    db()->prepare('UPDATE inscripciones SET email_entradas_enviado=1 WHERE id=?')->execute([$inscripcionId]);
}

function verificarRecaptcha(string $token): bool
{
    $secret = getSetting('recaptcha_secret');
    if (!$secret) return true;
    $res = @file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($secret) . '&response=' . urlencode($token));
    $json = json_decode($res, true);
    return !empty($json['success']);
}

$modo = $_GET['modo'] ?? 'elegir'; // elegir | login | registro
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($evento['nombre']) ?> — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
</head>
<body>

<header class="site-header">
  <div class="inner">
    <a href="index.php" class="site-logo"><?= h($siteName) ?></a>
    <nav class="nav-links">
      <?php if ($isLogged): ?>
        <span style="font-size:13px;color:#888;">Hola, <?= h($user['name'] ?? '') ?></span>
        <a href="mi-cuenta.php">Mi cuenta</a>
        <a href="logout.php">Salir</a>
      <?php else: ?>
        <a href="login.php">Iniciar sesión</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="page-wrap">
  <?php if ($step === 'info'): ?>
  <!-- ── INFO EVENTO (página completa) ── -->
  <div class="container">
    <?php if ($error): ?>
      <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <?php if ($evento['imagen']): ?>
      <img src="../<?= h($evento['imagen']) ?>" alt="<?= h($evento['nombre']) ?>" class="evento-hero">
    <?php endif; ?>

    <div class="evento-detail-grid anim-fade">
      <div>
        <h1 style="font-size:28px;font-weight:800;letter-spacing:-.02em;margin-bottom:14px;"><?= h($evento['nombre']) ?></h1>

        <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:20px;">
          <?php if ($evento['fecha_evento']): ?>
            <span style="font-size:14px;color:#666;">📅 <?= date('d/m/Y H:i', strtotime($evento['fecha_evento'])) ?></span>
          <?php endif; ?>
          <?php if ($evento['lugar']):
            $mapsUrl = $evento['lugar_url'] ?: ('https://www.google.com/maps/search/?api=1&query=' . urlencode($evento['lugar']));
          ?>
            <span style="font-size:14px;color:#666;">📍 <?= h($evento['lugar']) ?>
              <a href="<?= h($mapsUrl) ?>" target="_blank" rel="noopener" style="margin-left:4px;">(Ver en el mapa)</a>
            </span>
          <?php endif; ?>
          <?php if ($evento['max_inscritos']): ?>
            <span style="font-size:14px;color:#888;">👥 <?= $totalInscritos ?> / <?= $evento['max_inscritos'] ?> inscritos</span>
          <?php endif; ?>
        </div>

        <?php if ($evento['descripcion']): ?>
          <div class="card" style="font-size:14px;color:#444;line-height:1.7;"><?= $evento['descripcion'] ?></div>
        <?php endif; ?>
      </div>

      <div class="evento-detail-sidebar">
        <div class="card" style="text-align:center;">
          <?php if (!$evento['es_gratuito']): ?>
            <div style="font-size:32px;font-weight:800;letter-spacing:-.02em;"><?= number_format((float)$evento['precio'], 2, ',', '.') ?> €</div>
            <div style="font-size:12px;color:#888;margin-bottom:18px;">por persona</div>
          <?php else: ?>
            <span class="badge badge-green" style="font-size:14px;padding:6px 16px;margin-bottom:18px;display:inline-block;">Gratuito</span>
          <?php endif; ?>

          <?php if (!$disponible): ?>
            <div class="alert alert-warning" style="text-align:left;">
              <?php if ($lleno): ?>⚠️ Este evento está completo.
              <?php elseif ($cerrado): ?>⚠️ El plazo de inscripción ha finalizado.
              <?php else: ?>⚠️ Este evento ya no está disponible.
              <?php endif; ?>
            </div>
          <?php elseif ($yaInscrito): ?>
            <div class="alert alert-info" style="text-align:left;">✓ Ya estás inscrito en este evento. <a href="mi-cuenta.php">Ver mis entradas →</a></div>
            <a href="evento.php?slug=<?= urlencode($slug) ?>&step=inscripcion"
               class="btn btn-full btn-outline" style="margin-top:10px;">
              ➕ Si quieres comprar más entradas, pincha aquí
            </a>
          <?php else: ?>
            <a href="evento.php?slug=<?= urlencode($slug) ?>&step=<?= $isLogged ? 'inscripcion' : 'auth' ?>"
               class="btn btn-full">
              🎫 Inscribirme en este evento
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php else: ?>
  <div class="container-sm">

    <?php if ($error): ?>
      <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <?php if ($step === 'auth'): ?>
    <!-- ── AUTH (login / registro) ── -->
    <div class="card anim-fade">
      <a href="evento.php?slug=<?= urlencode($slug) ?>" style="font-size:13px;color:#888;text-decoration:none;">← Volver al evento</a>
      <h2 style="font-size:20px;font-weight:700;margin:14px 0 6px;"><?= h($evento['nombre']) ?></h2>
      <p style="font-size:13px;color:#888;margin-bottom:20px;">Para inscribirte necesitas una cuenta</p>

      <?php if ($modo === 'elegir'): ?>
        <!-- ELEGIR -->
        <div style="display:flex;flex-direction:column;gap:12px;">
          <a href="?slug=<?= urlencode($slug) ?>&step=auth&modo=login" class="btn btn-full btn-outline">Ya tengo cuenta — Iniciar sesión</a>
          <a href="?slug=<?= urlencode($slug) ?>&step=auth&modo=registro" class="btn btn-full">Soy nuevo — Crear cuenta</a>
        </div>
        <p style="text-align:center;font-size:12px;color:#aaa;margin-top:16px;">
          ℹ️ Crear una cuenta no implica inscribirse. Después podrás elegir a cuántas personas inscribes.
        </p>

      <?php elseif ($modo === 'login'): ?>
        <!-- LOGIN -->
        <p style="font-size:13px;color:#888;margin-bottom:14px;"><a href="?slug=<?= urlencode($slug) ?>&step=auth&modo=elegir">← Volver</a></p>
        <form method="POST" action="">
          <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
          <input type="hidden" name="action" value="login">
          <div class="field"><label>Email</label><input type="email" name="email_login" required autofocus value="<?= h($_POST['email_login'] ?? '') ?>"></div>
          <div class="field"><label>Contraseña</label><input type="password" name="pass_login" required></div>
          <button type="submit" class="btn btn-full">Iniciar sesión</button>
        </form>
        <p style="text-align:center;font-size:13px;margin-top:14px;">
          <a href="recuperar-password.php" style="color:#888;">¿Olvidaste tu contraseña?</a>
        </p>
        <p style="text-align:center;font-size:13px;margin-top:8px;">
          ¿No tienes cuenta? <a href="?slug=<?= urlencode($slug) ?>&step=auth&modo=registro">Créala aquí</a>
        </p>

      <?php elseif ($modo === 'registro'): ?>
        <!-- REGISTRO -->
        <p style="font-size:13px;color:#888;margin-bottom:14px;"><a href="?slug=<?= urlencode($slug) ?>&step=auth&modo=elegir">← Volver</a></p>
        <div class="alert alert-info" style="font-size:13px;">
          ℹ️ Estás creando tu cuenta. Una vez creada, podrás inscribirte al evento.
        </div>
        <form method="POST" action="">
          <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
          <input type="hidden" name="action" value="registro">
          <div class="field"><label>Nombre completo *</label><input type="text" name="nombre" required autofocus value="<?= h($_POST['nombre'] ?? '') ?>"></div>
          <div class="field"><label>Email *</label><input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"></div>
          <div class="field"><label>Teléfono</label><input type="tel" name="telefono" value="<?= h($_POST['telefono'] ?? '') ?>"></div>
          <div class="field-row">
            <div class="field"><label>Contraseña * (mín. 8 caracteres)</label><input type="password" name="password" required minlength="8"></div>
            <div class="field"><label>Repetir contraseña *</label><input type="password" name="password2" required minlength="8"></div>
          </div>
          <?php if ($sk = getSetting('recaptcha_sitekey')): ?>
            <div class="g-recaptcha" data-sitekey="<?= h($sk) ?>" style="margin-bottom:14px;"></div>
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
          <?php endif; ?>
          <button type="submit" class="btn btn-full">Crear cuenta e inscribirme</button>
        </form>
        <p style="text-align:center;font-size:13px;margin-top:14px;">
          ¿Ya tienes cuenta? <a href="?slug=<?= urlencode($slug) ?>&step=auth&modo=login">Inicia sesión</a>
        </p>
      <?php endif; ?>
    </div>

    <?php elseif ($step === 'inscripcion' && $isLogged): ?>
    <!-- ── FORMULARIO INSCRIPCIÓN ── -->
    <?php
    $maxPlazas = $evento['max_inscritos'] ? max(1, $evento['max_inscritos'] - $totalInscritos) : 20;
    ?>
    <div class="card anim-fade">
      <a href="evento.php?slug=<?= urlencode($slug) ?>" style="font-size:13px;color:#888;text-decoration:none;">← Volver al evento</a>
      <h2 style="font-size:18px;font-weight:700;margin:14px 0 4px;"><?= h($evento['nombre']) ?></h2>
      <p style="font-size:13px;color:#888;margin-bottom:18px;">
        <?= $evento['es_gratuito'] ? 'Gratuito' : number_format((float)$evento['precio'], 2, ',', '.') . ' € por persona' ?>
      </p>

      <div class="alert alert-warning" style="font-size:13px;">
        ⚠️ <strong>Importante:</strong> Si tú también vas a asistir, debes incluirte como una de las personas inscritas.
        Crear una cuenta no equivale a estar inscrito.
      </div>
    </div>

    <form method="POST" action="" id="formInscripcion">
      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
      <input type="hidden" name="action" value="inscribir">

      <!-- NÚMERO DE PERSONAS -->
      <div class="card">
        <div class="card-title">¿Cuántas personas inscribes?</div>
        <div class="field">
          <select name="num_personas" id="numPersonas" onchange="actualizarPersonas(this.value)">
            <?php for ($i = 1; $i <= min($maxPlazas, 20); $i++): ?>
              <option value="<?= $i ?>" <?= ($i === 1) ? 'selected' : '' ?>><?= $i ?> persona<?= $i > 1 ? 's' : '' ?></option>
            <?php endfor; ?>
          </select>
          <p class="hint">Marca la casilla de abajo si tú también vas a asistir.</p>
        </div>
        <div class="field">
          <label style="display:flex;align-items:center;gap:8px;font-size:14px;text-transform:none;font-weight:normal;">
            <input type="checkbox" name="yo_asisto" id="yo_asisto" checked>
            Yo también asisto (rellena la persona 1 con mis datos)
          </label>
        </div>
      </div>

      <!-- ASISTENTES -->
      <div id="asistentesContainer">
        <?php for ($i = 1; $i <= min($maxPlazas, 20); $i++):
          $esTitular = ($i === 1);
          $display = $i <= 1 ? '' : 'display:none';
        ?>
        <div class="card asistente-block <?= $esTitular ? 'titular' : '' ?>" id="asistente_<?= $i ?>" style="<?= $display ?>">
          <div class="asistente-label">
            <span><?= $esTitular ? '👤 Persona 1' : "👤 Persona {$i}" ?></span>
          </div>
          <div class="field">
            <label>Nombre completo *</label>
            <input type="text" name="asistente_<?= $i ?>_nombre" id="asistente_<?= $i ?>_nombre_input"
                   value="<?= $esTitular ? h($user['name']) : '' ?>"
                   <?= $i > 1 ? 'disabled' : '' ?>>
          </div>
          <?php foreach ($campos as $campo):
            $aplica = ($esTitular && $campo['para_titular']) || (!$esTitular && $campo['para_asistentes']);
            if (!$aplica) continue;
          ?>
          <div class="field">
            <label><?= h($campo['label']) ?><?= $campo['obligatorio'] ? ' *' : '' ?></label>
            <?php if ($campo['tipo'] === 'select' && $campo['opciones']): ?>
              <select name="asistente_<?= $i ?>_campo_<?= $campo['id'] ?>" <?= ($i > 1 ? 'disabled' : '') ?>>
                <option value="">Selecciona...</option>
                <?php foreach (explode("\n", $campo['opciones']) as $op): ?>
                  <option value="<?= h(trim($op)) ?>"><?= h(trim($op)) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($campo['tipo'] === 'textarea'): ?>
              <textarea name="asistente_<?= $i ?>_campo_<?= $campo['id'] ?>" <?= ($i > 1 ? 'disabled' : '') ?>></textarea>
            <?php elseif ($campo['tipo'] === 'checkbox'): ?>
              <label style="display:flex;align-items:center;gap:8px;font-size:14px;text-transform:none;font-weight:normal;">
                <input type="checkbox" name="asistente_<?= $i ?>_campo_<?= $campo['id'] ?>" value="Sí" <?= ($i > 1 ? 'disabled' : '') ?>>
                <?= h($campo['label']) ?>
              </label>
            <?php else: ?>
              <input type="<?= h($campo['tipo']) ?>" name="asistente_<?= $i ?>_campo_<?= $campo['id'] ?>"
                     <?= $campo['obligatorio'] ? 'required' : '' ?> <?= ($i > 1 ? 'disabled' : '') ?>>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endfor; ?>
      </div>

      <!-- MÉTODO DE PAGO -->
      <?php if (!$evento['es_gratuito']): ?>
      <div class="card">
        <div class="card-title">Método de pago</div>
        <div class="metodo-pago-list">
          <?php
          $metodosInfo = [
              'stripe'        => ['icon' => '💳', 'nombre' => 'Tarjeta de crédito/débito', 'desc' => 'Pago seguro con Stripe'],
              'redsys'        => ['icon' => '🏧', 'nombre' => 'Tarjeta (Redsys/TPV)', 'desc' => 'TPV bancario seguro'],
              'bizum'         => ['icon' => '📱', 'nombre' => 'Bizum', 'desc' => 'Transferencia Bizum al organizador'],
              'transferencia' => ['icon' => '🏦', 'nombre' => 'Transferencia bancaria', 'desc' => 'Transferencia bancaria al IBAN del organizador'],
          ];
          $primero = true;
          foreach ($metodosActivos as $mid):
            if (!isset($metodosInfo[$mid])) continue;
            $info = $metodosInfo[$mid];
          ?>
          <label class="metodo-pago-opt <?= $primero ? 'selected' : '' ?>"
                 onclick="document.querySelectorAll('.metodo-pago-opt').forEach(e=>e.classList.remove('selected'));this.classList.add('selected')">
            <input type="radio" name="metodo_pago" value="<?= h($mid) ?>" <?= $primero ? 'checked' : '' ?>>
            <span class="metodo-pago-icon"><?= $info['icon'] ?></span>
            <div class="metodo-pago-info">
              <div class="nombre"><?= h($info['nombre']) ?></div>
              <div class="desc"><?= h($info['desc']) ?></div>
            </div>
          </label>
          <?php $primero = false; endforeach; ?>
        </div>
      </div>
      <?php else: ?>
        <input type="hidden" name="metodo_pago" value="gratis">
      <?php endif; ?>

      <!-- TOTAL Y BOTÓN -->
      <div class="card" style="text-align:center;background:linear-gradient(135deg,#1a1a1a,#2d2d2d);color:#fff;">
        <div style="font-size:11px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Total a pagar</div>
        <div id="precioTotal" style="font-size:40px;font-weight:800;letter-spacing:-.03em;">
          <?= $evento['es_gratuito'] ? 'Gratis' : number_format((float)$evento['precio'], 2, ',', '.') . ' €' ?>
        </div>
        <div style="font-size:12px;color:rgba(255,255,255,.5);margin-top:4px;" id="precioDetalle">
          <?= !$evento['es_gratuito'] ? number_format((float)$evento['precio'], 2, ',', '.') . ' € × 1 persona' : '' ?>
        </div>
      </div>

      <button type="submit" class="btn btn-full" id="btnInscribir" style="font-size:16px;padding:16px;">
        🎫 Confirmar inscripción
      </button>
      <p style="text-align:center;font-size:12px;color:#aaa;margin-top:10px;">
        Recibirás las entradas con código QR por email al confirmar el pago.
      </p>
    </form>

    <script>
    var precioUnitario = <?= (float)$evento['precio'] ?>;
    var esGratis = <?= $evento['es_gratuito'] ? 'true' : 'false' ?>;
    var maxPersonas = <?= min($maxPlazas, 20) ?>;
    var nombreUsuario = <?= json_encode($user['name']) ?>;

    function actualizarPersonas(n) {
        n = parseInt(n);
        for (var i = 1; i <= maxPersonas; i++) {
            var block = document.getElementById('asistente_' + i);
            if (!block) continue;
            block.style.display = (i <= n) ? '' : 'none';
            // Habilitar/deshabilitar inputs (el campo de nombre de la persona 1 lo controla "yo_asisto")
            block.querySelectorAll('input,select,textarea').forEach(function(el) {
                if (el.id === 'asistente_1_nombre_input') return;
                el.disabled = (i > n);
            });
        }
        if (!esGratis) {
            var total = precioUnitario * n;
            document.getElementById('precioTotal').textContent = total.toFixed(2).replace('.', ',') + ' €';
            document.getElementById('precioDetalle').textContent = precioUnitario.toFixed(2).replace('.', ',') + ' € × ' + n + ' persona' + (n > 1 ? 's' : '');
        }
    }
    actualizarPersonas(1);

    function toggleYoAsisto() {
        var chk = document.getElementById('yo_asisto');
        var input = document.getElementById('asistente_1_nombre_input');
        if (chk.checked) {
            input.value = nombreUsuario;
            input.readOnly = true;
        } else {
            input.value = '';
            input.readOnly = false;
        }
    }
    document.getElementById('yo_asisto').addEventListener('change', toggleYoAsisto);
    toggleYoAsisto();

    document.getElementById('formInscripcion').addEventListener('submit', function() {
        var btn = document.getElementById('btnInscribir');
        btn.disabled = true;
        btn.textContent = 'Procesando...';
    });
    </script>

    <?php endif; ?>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
