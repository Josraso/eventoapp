<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/TicketManager.php';

Auth::userCheck();
$user     = Auth::getUser();
$siteName = getSetting('site_name', 'Eventos');
$logoPath = getSetting('logo_path');
$logoUrl  = ($logoPath && file_exists(__DIR__.'/../'.$logoPath)) ? '../'.$logoPath : null;
$flash    = getFlash();
$error    = '';
$success  = '';

// ── ENVIAR ENTRADA INDIVIDUAL ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Auth::checkCsrf();

    if ($_POST['action'] === 'enviar_entrada') {
        $entradaId  = (int)($_POST['entrada_id'] ?? 0);
        $emailDest  = filter_var(trim($_POST['email_destino'] ?? ''), FILTER_VALIDATE_EMAIL);

        // Verificar que la entrada pertenece al usuario
        $stE = db()->prepare('SELECT e.*, i.user_id, i.numero_pedido, i.evento_id as evid FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE e.id=?');
        $stE->execute([$entradaId]);
        $entrada = $stE->fetch();

        if (!$entrada || $entrada['user_id'] != Auth::userId()) {
            $error = 'No tienes permiso para enviar esta entrada.';
        } elseif (!$emailDest) {
            $error = 'Introduce un email válido.';
        } else {
            // Generar PDF si no existe
            if (!$entrada['pdf_path'] || !file_exists(__DIR__ . '/../' . $entrada['pdf_path'])) {
                $stIns = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
                $stIns->execute([$entrada['inscripcion_id']]);
                $inscripcion = $stIns->fetch();
                $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
                $stEv->execute([$entrada['evid']]);
                $evento = $stEv->fetch();
                $pdfContent = TicketManager::generarPDF($entrada, $evento, $inscripcion);
                $pdfPath = TicketManager::guardarPDF($entradaId, $pdfContent);
            } else {
                $pdfPath = __DIR__ . '/../' . $entrada['pdf_path'];
            }

            $stEv2 = db()->prepare('SELECT * FROM eventos WHERE id=?');
            $stEv2->execute([$entrada['evid']]);
            $evData = $stEv2->fetch();

            $stIns2 = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
            $stIns2->execute([$entrada['inscripcion_id']]);
            $insData = $stIns2->fetch();

            $m = new Mailer();
            $res = $m->send(
                $emailDest,
                $entrada['nombre_asistente'],
                'Tu entrada — ' . $evData['nombre'],
                Mailer::tplEntradaIndividual($entrada, $evData, $insData),
                [['path' => $pdfPath, 'name' => 'entrada_' . $entrada['nombre_asistente'] . '.pdf']]
            );
            if ($res['ok']) {
                $success = 'Entrada enviada correctamente a ' . $emailDest;
            } else {
                $error = 'Error al enviar: ' . $res['error'];
            }
        }
    }

    if ($_POST['action'] === 'reenviar_verificacion') {
        if ($user['email_verified']) {
            $success = 'Tu email ya está verificado.';
        } else {
            $verifyToken = generateToken(32);
            db()->prepare('UPDATE users SET email_verify_token=? WHERE id=?')->execute([$verifyToken, $user['id']]);
            $link = baseUrl() . '/public/verificar.php?token=' . $verifyToken;
            $m = new Mailer();
            $res = $m->send($user['email'], $user['name'], 'Verifica tu email — ' . $siteName, Mailer::tplVerificacion($user, $link));
            if ($res['ok']) {
                $success = 'Te hemos enviado un nuevo email de verificación a ' . $user['email'];
            } else {
                $error = 'No se pudo enviar el email: ' . $res['error'];
            }
        }
    }

    if ($_POST['action'] === 'cambiar_password') {
        $cur  = $_POST['cur'] ?? '';
        $new  = $_POST['new'] ?? '';
        $new2 = $_POST['new2'] ?? '';
        if (!password_verify($cur, $user['password_hash'])) {
            $error = 'La contraseña actual no es correcta.';
        } elseif (strlen($new) < 8) {
            $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
        } elseif ($new !== $new2) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
               ->execute([password_hash($new, PASSWORD_BCRYPT), Auth::userId()]);
            $success = 'Contraseña actualizada correctamente.';
        }
    }
}

// Cargar inscripciones del usuario
$stIns = db()->prepare("
    SELECT i.*, e.nombre as evento_nombre, e.fecha_evento, e.lugar, e.slug as evento_slug, e.es_gratuito
    FROM inscripciones i
    JOIN eventos e ON e.id = i.evento_id
    WHERE i.user_id = ? AND i.estado_pago != 'fallido'
    ORDER BY i.created_at DESC
");
$stIns->execute([Auth::userId()]);
$inscripciones = $stIns->fetchAll();

// Agrupar por evento (un usuario puede tener varios pedidos del mismo evento)
$porEvento = [];
foreach ($inscripciones as $ins) {
    $porEvento[$ins['evento_id']]['evento_nombre'] ??= $ins['evento_nombre'];
    $porEvento[$ins['evento_id']]['fecha_evento']  ??= $ins['fecha_evento'];
    $porEvento[$ins['evento_id']]['lugar']         ??= $ins['lugar'];
    $porEvento[$ins['evento_id']]['pedidos'][]      = $ins;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi cuenta — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
<style>
.tabs { display: flex; gap: 4px; border-bottom: 2px solid #e8e8e8; margin-bottom: 24px; }
.tab { padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; color: #888; transition: color .15s; background: none; border-top: none; border-left: none; border-right: none; font-family: inherit; }
.tab.active { color: #1a1a1a; border-bottom-color: #1a1a1a; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }
.entrada-item { border: 1px solid #e4e4e8; border-radius: 10px; padding: 14px 16px; margin-bottom: 10px; }
.entrada-nombre { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
.entrada-token { font-family: monospace; font-size: 11px; color: #aaa; }
.enviar-form { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid #f0f0f0; }
.enviar-form.open { display: block; }
.pedido-box { border: 1px solid #e4e4e8; border-radius: 10px; padding: 16px 18px; }
.canjeadas-toggle { font-size: 12px; color: #888; cursor: pointer; margin-top: 8px; user-select: none; }
.canjeadas-toggle:hover { color: #1a1a1a; }
</style>
</head>
<body>

<header class="site-header">
  <div class="inner">
    <a href="index.php" class="site-logo"><?php if ($logoUrl): ?><img src="<?= h($logoUrl) ?>"><?php else: ?><?= h($siteName) ?><?php endif; ?></a>
    <nav class="nav-links">
      <a href="index.php">Eventos</a>
      <a href="logout.php">Cerrar sesión</a>
    </nav>
  </div>
</header>

<main class="page-wrap">
  <div class="container">
    <h1 style="font-size:24px;font-weight:800;margin-bottom:6px;">Mi cuenta</h1>
    <p style="font-size:14px;color:#888;margin-bottom:24px;">Hola, <strong><?= h($user['name']) ?></strong>
      <?php if (!$user['email_verified']): ?>
        <span class="badge badge-orange">Email sin verificar</span>
        <form method="POST" style="display:inline-block;margin-left:6px;">
          <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
          <input type="hidden" name="action" value="reenviar_verificacion">
          <button type="submit" class="btn btn-sm btn-outline" style="vertical-align:middle;">Reenviar email de verificación</button>
        </form>
      <?php endif; ?>
    </p>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type']==='ok'?'success':$flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

    <div class="tabs">
      <button class="tab active" onclick="showTab('inscripciones',this)">Mis inscripciones</button>
      <button class="tab" onclick="showTab('cuenta',this)">Datos de cuenta</button>
    </div>

    <!-- TAB: INSCRIPCIONES -->
    <div class="tab-panel active" id="tab-inscripciones">
      <?php if (empty($inscripciones)): ?>
        <div class="card" style="text-align:center;padding:48px 24px;">
          <div style="font-size:48px;margin-bottom:14px;">🎫</div>
          <div style="font-size:17px;font-weight:700;margin-bottom:8px;">Sin inscripciones</div>
          <p style="color:#888;margin-bottom:20px;">Todavía no te has inscrito a ningún evento.</p>
          <a href="index.php" class="btn">Ver eventos disponibles</a>
        </div>
      <?php else: ?>
        <?php foreach ($porEvento as $grupo): ?>
        <div class="card" style="margin-bottom:20px;padding:0;overflow:hidden;">
          <div style="background:#fafafa;border-bottom:1px solid #f0f0f0;padding:16px 24px;">
            <div style="font-size:17px;font-weight:800;"><?= h($grupo['evento_nombre']) ?></div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:4px;">
              <?php if ($grupo['fecha_evento']): ?>
                <span style="font-size:13px;color:#888;">📅 <?= date('d/m/Y H:i', strtotime($grupo['fecha_evento'])) ?></span>
              <?php endif; ?>
              <?php if ($grupo['lugar']): ?>
                <span style="font-size:13px;color:#888;">📍 <?= h($grupo['lugar']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div style="padding:20px 24px;display:flex;flex-direction:column;gap:16px;">
          <?php foreach ($grupo['pedidos'] as $i => $ins):
            $stEnt = db()->prepare('SELECT * FROM entradas WHERE inscripcion_id=? ORDER BY es_titular DESC, id ASC');
            $stEnt->execute([$ins['id']]);
            $entradas = $stEnt->fetchAll();
            $entradasActivas = array_filter($entradas, fn($e) => !$e['usado']);
            $entradasCanjeadas = array_filter($entradas, fn($e) => $e['usado']);
            $stCons = db()->prepare('SELECT c.*, p.nombre as producto_nombre FROM consumiciones c JOIN productos_consumicion p ON p.id=c.producto_id WHERE c.inscripcion_id=? ORDER BY c.id ASC');
            $stCons->execute([$ins['id']]);
            $consumiciones = $stCons->fetchAll();
            $consActivas = array_filter($consumiciones, fn($c) => !$c['usado']);
            $consCanjeadas = array_filter($consumiciones, fn($c) => $c['usado']);
            $badgeClass = match($ins['estado_pago']) {
                'pagado'      => 'badge-green',
                'pendiente'   => 'badge-orange',
                'cancelado'   => 'badge-red',
                default       => 'badge-gray',
            };
            $estadoLabel = match($ins['estado_pago']) {
                'pagado'    => 'Pagado',
                'pendiente' => 'Pendiente',
                'cancelado' => 'Cancelado',
                default     => $ins['estado_pago'],
            };
          ?>
          <div class="pedido-box">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <div style="font-size:12px;color:#aaa;">Pedido: <?= h($ins['numero_pedido']) ?> &middot; <?= date('d/m/Y', strtotime($ins['created_at'])) ?></div>
            <div style="text-align:right;">
              <span class="badge <?= $badgeClass ?>"><?= $estadoLabel ?></span>
              <?php if (!$ins['es_gratuito']): ?>
                <div style="font-size:14px;font-weight:700;margin-top:4px;"><?= number_format((float)$ins['precio_total'], 2, ',', '.') ?> €</div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($ins['estado_pago'] === 'pendiente' && in_array($ins['metodo_pago'], ['bizum','transferencia'])): ?>
            <div class="alert alert-warning" style="font-size:13px;margin-bottom:14px;">
              ⏳ Pago pendiente de confirmación. Revisa tu email con las instrucciones de pago.
            </div>
          <?php endif; ?>

          <?php if ($ins['estado_pago'] === 'pagado' && !empty($entradas)): ?>
            <div style="font-size:12px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">
              Entradas (<?= count($entradasActivas) ?>)
            </div>
            <?php if (empty($entradasActivas)): ?>
              <p style="font-size:13px;color:#aaa;margin-bottom:10px;">Todas las entradas de este pedido ya han sido canjeadas.</p>
            <?php endif; ?>
            <?php foreach ($entradasActivas as $ent): ?>
            <div class="entrada-item">
              <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div>
                  <div class="entrada-nombre"><?= h($ent['nombre_asistente']) ?> <?= $ent['es_titular'] ? '<span class="badge badge-blue">titular</span>' : '' ?></div>
                  <div class="entrada-token">QR: <?= h(substr($ent['qr_token'], 0, 16)) ?>...<?= !empty($ent['codigo_corto']) ? ' &middot; Código: <strong>' . h($ent['codigo_corto']) . '</strong>' : '' ?></div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                  <button type="button" class="btn btn-sm btn-success" onclick="mostrarQR('entrada', <?= $ent['id'] ?>, '<?= h(addslashes($ent['nombre_asistente'])) ?>')">📱 Mostrar QR</button>
                  <a href="descargar-entrada.php?id=<?= $ent['id'] ?>" target="_blank" class="btn btn-sm btn-outline">⬇ Descargar</a>
                  <button class="btn btn-sm" onclick="toggleEnviar(<?= $ent['id'] ?>)">📧 Enviar</button>
                </div>
              </div>
              <!-- Formulario envío -->
              <div class="enviar-form" id="enviar-<?= $ent['id'] ?>">
                <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                  <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                  <input type="hidden" name="action" value="enviar_entrada">
                  <input type="hidden" name="entrada_id" value="<?= $ent['id'] ?>">
                  <div class="field" style="flex:1;min-width:200px;margin-bottom:0;">
                    <label>Email del asistente</label>
                    <input type="email" name="email_destino" required placeholder="asistente@email.com">
                  </div>
                  <button type="submit" class="btn btn-sm btn-success">Enviar entrada</button>
                  <button type="button" class="btn btn-sm btn-outline" onclick="toggleEnviar(<?= $ent['id'] ?>)">Cancelar</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>

            <?php if (!empty($entradasCanjeadas)): ?>
            <div class="canjeadas-toggle" onclick="toggleCanjeadas('entCanj-<?= $ins['id'] ?>')">▾ Ver entradas canjeadas (<?= count($entradasCanjeadas) ?>)</div>
            <div id="entCanj-<?= $ins['id'] ?>" style="display:none;margin-top:8px;">
              <?php foreach ($entradasCanjeadas as $ent): ?>
              <div class="entrada-item" style="opacity:.6;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                  <div>
                    <div class="entrada-nombre"><?= h($ent['nombre_asistente']) ?> <?= $ent['es_titular'] ? '<span class="badge badge-blue">titular</span>' : '' ?></div>
                    <div class="entrada-token">QR: <?= h(substr($ent['qr_token'], 0, 16)) ?>...</div>
                  </div>
                  <div style="text-align:right;">
                    <span class="badge badge-green">✓ Canjeada</span>
                    <?php if ($ent['usado_at']): ?>
                      <div style="font-size:11px;color:#aaa;margin-top:2px;"><?= date('d/m/Y H:i', strtotime($ent['usado_at'])) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($ins['estado_pago'] === 'pagado' && !empty($consumiciones)): ?>
            <div style="font-size:12px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;margin-top:14px;">
              Consumiciones (<?= count($consActivas) ?>)
            </div>
            <?php if (empty($consActivas)): ?>
              <p style="font-size:13px;color:#aaa;margin-bottom:10px;">Todas las consumiciones de este pedido ya han sido canjeadas.</p>
            <?php endif; ?>
            <?php foreach ($consActivas as $cons): ?>
            <div class="entrada-item">
              <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div>
                  <div class="entrada-nombre"><?= h($cons['producto_nombre']) ?></div>
                  <div class="entrada-token">QR: <?= h(substr($cons['qr_token'], 0, 16)) ?>...<?= !empty($cons['codigo_corto']) ? ' &middot; Código: <strong>' . h($cons['codigo_corto']) . '</strong>' : '' ?></div>
                </div>
                <div style="text-align:right;">
                  <span class="badge badge-gray">Pendiente</span>
                  <div style="margin-top:6px;">
                    <button type="button" class="btn btn-sm btn-success" onclick="mostrarQR('consumicion', <?= $cons['id'] ?>, '<?= h(addslashes($cons['producto_nombre'])) ?>')">📱 Mostrar QR</button>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>

            <?php if (!empty($consCanjeadas)): ?>
            <div class="canjeadas-toggle" onclick="toggleCanjeadas('consCanj-<?= $ins['id'] ?>')">▾ Ver consumiciones canjeadas (<?= count($consCanjeadas) ?>)</div>
            <div id="consCanj-<?= $ins['id'] ?>" style="display:none;margin-top:8px;">
              <?php foreach ($consCanjeadas as $cons): ?>
              <div class="entrada-item" style="opacity:.6;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                  <div>
                    <div class="entrada-nombre"><?= h($cons['producto_nombre']) ?></div>
                    <div class="entrada-token">QR: <?= h(substr($cons['qr_token'], 0, 16)) ?>...</div>
                  </div>
                  <div style="text-align:right;">
                    <span class="badge badge-green">✓ Canjeada</span>
                    <?php if ($cons['usado_at']): ?>
                      <div style="font-size:11px;color:#aaa;margin-top:2px;"><?= date('d/m/Y H:i', strtotime($cons['usado_at'])) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          <?php endif; ?>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- TAB: CUENTA -->
    <div class="tab-panel" id="tab-cuenta">
      <div class="card">
        <div class="card-title">Mis datos</div>
        <table style="width:100%;font-size:14px;">
          <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Nombre</td><td style="font-weight:600;padding:8px 0;border-bottom:1px solid #f0f0f0;text-align:right;"><?= h($user['name']) ?></td></tr>
          <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Email</td><td style="font-weight:600;padding:8px 0;border-bottom:1px solid #f0f0f0;text-align:right;"><?= h($user['email']) ?> <?= $user['email_verified'] ? '✓' : '<span class="badge badge-orange">Sin verificar</span>' ?></td></tr>
          <tr><td style="color:#888;padding:8px 0;">Teléfono</td><td style="font-weight:600;padding:8px 0;text-align:right;"><?= h($user['phone'] ?: '—') ?></td></tr>
        </table>
      </div>

      <div class="card">
        <div class="card-title">Cambiar contraseña</div>
        <form method="POST">
          <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
          <input type="hidden" name="action" value="cambiar_password">
          <div class="field"><label>Contraseña actual</label><input type="password" name="cur" required style="max-width:300px;"></div>
          <div class="field-row" style="max-width:460px;">
            <div class="field"><label>Nueva contraseña</label><input type="password" name="new" required minlength="8"></div>
            <div class="field"><label>Repetir</label><input type="password" name="new2" required minlength="8"></div>
          </div>
          <button type="submit" class="btn btn-sm">Cambiar contraseña</button>
        </form>
      </div>
    </div>
  </div>
</main>

<div id="qrModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:24px;max-width:340px;width:90%;text-align:center;">
    <div id="qrModalTitulo" style="font-size:15px;font-weight:700;margin-bottom:14px;"></div>
    <img id="qrModalImg" src="" style="width:100%;max-width:280px;border-radius:8px;">
    <button type="button" class="btn btn-sm btn-outline" style="margin-top:16px;width:100%;" onclick="cerrarQR()">Cerrar</button>
  </div>
</div>

<script>
function showTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + id).classList.add('active');
    btn.classList.add('active');
}
function toggleEnviar(id) {
    var el = document.getElementById('enviar-' + id);
    el.classList.toggle('open');
}
function mostrarQR(tipo, id, nombre) {
    document.getElementById('qrModalTitulo').textContent = nombre;
    document.getElementById('qrModalImg').src = 'qr-imagen.php?tipo=' + tipo + '&id=' + id;
    document.getElementById('qrModal').style.display = 'flex';
}
function cerrarQR() {
    document.getElementById('qrModal').style.display = 'none';
}
function toggleCanjeadas(id) {
    var el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
</body>
</html>
