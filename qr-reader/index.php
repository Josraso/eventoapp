<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

// Sesión de 30 días para portero
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(2592000, '/; SameSite=Lax', '', !empty($_SERVER['HTTPS']), true);
    session_start();
}

// Login de portero
$error = '';
if (!empty($_POST['action']) && $_POST['action'] === 'login_portero') {
    if (Auth::adminLogin($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        if (!in_array(Auth::adminRole(), ['portero', 'admin', 'superadmin'])) {
            Auth::adminLogout();
            $error = 'Sin permisos de portero.';
        }
    } else {
        $error = 'Credenciales incorrectas.';
    }
}

$isLogged = !empty($_SESSION['admin_id']);
$siteName = getSetting('site_name', 'Eventos');

// Eventos asignados al portero
$eventosAsignados = [];
if ($isLogged) {
    $eids = Auth::porteroEventos();
    if (!empty($eids)) {
        $placeholders = implode(',', array_fill(0, count($eids), '?'));
        $stEvs = db()->prepare("SELECT * FROM eventos WHERE id IN ($placeholders) AND activo=1 ORDER BY fecha_evento ASC");
        $stEvs->execute($eids);
        $eventosAsignados = $stEvs->fetchAll();
    }
}

// Evento seleccionado
$eventoId = (int)($_GET['evento'] ?? (count($eventosAsignados) === 1 ? $eventosAsignados[0]['id'] : 0));
$eventoActual = null;
if ($eventoId && $isLogged) {
    $eids = Auth::porteroEventos();
    if (in_array($eventoId, $eids)) {
        $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
        $stEv->execute([$eventoId]);
        $eventoActual = $stEv->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no">
<title>Lector QR — <?= h($siteName) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0a0a0a; color: #fff; min-height: 100vh; }
.header { background: #1a1a1a; padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #333; }
.header h1 { font-size: 16px; font-weight: 700; }
.header .info { font-size: 12px; color: #888; }
.content { max-width: 500px; margin: 0 auto; padding: 20px; }

/* LOGIN */
.login-card { background: #1a1a1a; border-radius: 14px; border: 1px solid #333; padding: 32px; margin-top: 60px; }
.login-card h2 { font-size: 20px; font-weight: 700; margin-bottom: 18px; }
.field label { font-size: 12px; font-weight: 600; color: #888; display: block; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
.field input { width: 100%; background: #0a0a0a; border: 1.5px solid #333; border-radius: 10px; padding: 11px 13px; font-size: 14px; color: #fff; outline: none; margin-bottom: 12px; }
.field input:focus { border-color: #4ade80; }
.btn { width: 100%; background: #4ade80; color: #0a0a0a; border: none; border-radius: 10px; padding: 13px; font-size: 15px; font-weight: 700; cursor: pointer; }
.alert-err { background: #3a0a0a; border: 1px solid #c0392b; border-radius: 8px; padding: 11px; font-size: 13px; color: #ff6b6b; margin-bottom: 14px; }

/* SELECTOR EVENTO */
.evento-btn { display: block; width: 100%; background: #1a1a1a; border: 1.5px solid #333; border-radius: 12px; padding: 16px; margin-bottom: 12px; text-align: left; cursor: pointer; color: #fff; font-family: inherit; transition: border-color .15s, background .15s; }
.evento-btn:hover { border-color: #4ade80; background: #111; }
.evento-btn .nombre { font-size: 15px; font-weight: 700; }
.evento-btn .fecha { font-size: 12px; color: #888; margin-top: 3px; }

/* ESCÁNER */
#qr-container { background: #000; border-radius: 16px; overflow: hidden; position: relative; margin-bottom: 20px; aspect-ratio: 1; }
#qr-video { width: 100%; height: 100%; object-fit: cover; }
.scan-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
.scan-frame { width: 65%; aspect-ratio: 1; border: 3px solid rgba(74,222,128,.8); border-radius: 12px; box-shadow: 0 0 0 4000px rgba(0,0,0,.3); }
.scan-line { position: absolute; width: 65%; height: 2px; background: linear-gradient(90deg, transparent, #4ade80, transparent); animation: scanline 2s ease-in-out infinite; }
@keyframes scanline { 0%,100% { top: 17%; } 50% { top: 83%; } }

/* RESULTADO */
.resultado { border-radius: 14px; padding: 24px; margin-bottom: 20px; text-align: center; animation: fadeIn .3s ease; }
@keyframes fadeIn { from { opacity:0; transform: scale(.95); } to { opacity:1; transform: scale(1); } }
.resultado.ok { background: #0a2a0a; border: 2px solid #4ade80; }
.resultado.ya-usado { background: #2a1a0a; border: 2px solid #f59e0b; }
.resultado.invalido { background: #2a0a0a; border: 2px solid #ef4444; }
.resultado .icono { font-size: 52px; margin-bottom: 12px; }
.resultado .titulo { font-size: 22px; font-weight: 800; margin-bottom: 8px; }
.resultado.ok .titulo { color: #4ade80; }
.resultado.ya-usado .titulo { color: #f59e0b; }
.resultado.invalido .titulo { color: #ef4444; }
.resultado .detalle { font-size: 14px; color: #ccc; line-height: 1.6; }
.resultado .nombre-grande { font-size: 26px; font-weight: 800; color: #fff; margin: 10px 0 6px; }
.resultado .campo-extra { font-size: 16px; color: #aaa; margin-bottom: 4px; }

/* STATS */
.stats { display: flex; gap: 10px; margin-bottom: 20px; }
.stat { flex: 1; background: #1a1a1a; border-radius: 10px; padding: 14px; text-align: center; }
.stat .val { font-size: 26px; font-weight: 800; color: #4ade80; }
.stat .lbl { font-size: 11px; color: #666; margin-top: 3px; }

/* TABS */
.tabs { display: flex; gap: 4px; margin-bottom: 16px; }
.tab { flex: 1; background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 10px; font-size: 13px; font-weight: 600; cursor: pointer; color: #888; }
.tab.active { background: #4ade80; color: #000; border-color: #4ade80; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* LISTADO */
.entrada-row { background: #1a1a1a; border-radius: 10px; padding: 12px 14px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.entrada-row .nombre { font-size: 14px; font-weight: 600; }
.entrada-row .meta { font-size: 12px; color: #666; margin-top: 2px; }
.dot-ok { width: 8px; height: 8px; background: #4ade80; border-radius: 50%; flex-shrink: 0; }
.dot-no { width: 8px; height: 8px; background: #333; border-radius: 50%; flex-shrink: 0; }
.search-input { width: 100%; background: #1a1a1a; border: 1.5px solid #333; border-radius: 10px; padding: 11px 14px; font-size: 14px; color: #fff; outline: none; margin-bottom: 12px; }
.search-input:focus { border-color: #4ade80; }
.btn-scan-nuevo { width: 100%; background: #1a1a1a; border: 2px dashed #333; border-radius: 12px; padding: 16px; font-size: 14px; color: #888; cursor: pointer; text-align: center; margin-bottom: 16px; }
.btn-scan-nuevo:hover { border-color: #4ade80; color: #4ade80; }
.btn-cambiar { background: none; border: 1px solid #333; border-radius: 8px; padding: 6px 12px; font-size: 12px; color: #888; cursor: pointer; }
</style>
</head>
<body>

<?php if (!$isLogged): ?>
<!-- LOGIN PORTERO -->
<div class="content">
  <div class="login-card">
    <h2>🎫 Acceso portero</h2>
    <?php if ($error): ?><div class="alert-err"><?= h($error) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="login_portero">
      <div class="field"><label>Usuario</label><input type="text" name="username" required autofocus autocomplete="username"></div>
      <div class="field"><label>Contraseña</label><input type="password" name="password" required autocomplete="current-password"></div>
      <button type="submit" class="btn">Entrar</button>
    </form>
  </div>
</div>

<?php elseif (!$eventoActual): ?>
<!-- SELECCIONAR EVENTO -->
<div class="header">
  <h1>🎫 <?= h($siteName) ?></h1>
  <div class="info"><?= h(Auth::adminName()) ?> &nbsp;|&nbsp; <a href="logout-portero.php" style="color:#888;font-size:12px;">Salir</a></div>
</div>
<div class="content" style="margin-top:20px;">
  <p style="color:#888;font-size:14px;margin-bottom:16px;">Selecciona el evento a validar:</p>
  <?php if (empty($eventosAsignados)): ?>
    <p style="color:#666;text-align:center;margin-top:40px;">No tienes eventos asignados.</p>
  <?php else: ?>
    <?php foreach ($eventosAsignados as $ev): ?>
      <a href="?evento=<?= $ev['id'] ?>" style="text-decoration:none;">
        <div class="evento-btn">
          <div class="nombre"><?= h($ev['nombre']) ?></div>
          <?php if ($ev['fecha_evento']): ?>
            <div class="fecha">📅 <?= date('d/m/Y H:i', strtotime($ev['fecha_evento'])) ?></div>
          <?php endif; ?>
          <?php if ($ev['lugar']): ?>
            <div class="fecha">📍 <?= h($ev['lugar']) ?></div>
          <?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ESCÁNER PRINCIPAL -->
<?php
$stStats = db()->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id=? AND usado=1");
$stStats->execute([$eventoId]);
$usados = (int)$stStats->fetchColumn();
$stTotal = db()->prepare("SELECT COUNT(*) FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE e.evento_id=? AND i.estado_pago='pagado'");
$stTotal->execute([$eventoId]);
$total = (int)$stTotal->fetchColumn();
?>
<div class="header">
  <div>
    <h1><?= h($eventoActual['nombre']) ?></h1>
    <div class="info">Portero: <?= h(Auth::adminName()) ?></div>
  </div>
  <button class="btn-cambiar" onclick="location.href='index.php'">Cambiar evento</button>
</div>

<div class="content">
  <div class="tabs">
    <button class="tab active" onclick="showTab('escaner',this)" id="tab-escaner">📷 Escáner</button>
    <button class="tab" onclick="showTab('listado',this)" id="tab-listado">📋 Listado</button>
  </div>

  <!-- ESCÁNER -->
  <div class="tab-panel active" id="panel-escaner">
    <div class="stats">
      <div class="stat"><div class="val"><?= $usados ?></div><div class="lbl">Validadas</div></div>
      <div class="stat"><div class="val"><?= $total ?></div><div class="lbl">Total</div></div>
      <div class="stat"><div class="val"><?= $total - $usados ?></div><div class="lbl">Pendientes</div></div>
    </div>

    <div id="resultado-container"></div>

    <div id="qr-container">
      <video id="qr-video" playsinline autoplay muted></video>
      <div class="scan-overlay">
        <div class="scan-frame"></div>
        <div class="scan-line"></div>
      </div>
    </div>

    <div style="text-align:center;font-size:12px;color:#555;margin-bottom:16px;">
      Apunta la cámara al código QR de la entrada
    </div>

    <!-- Input manual -->
    <div style="margin-bottom:16px;">
      <input type="text" id="input-manual" class="search-input" placeholder="O introduce el token manualmente..." autocomplete="off">
    </div>
  </div>

  <!-- LISTADO -->
  <div class="tab-panel" id="panel-listado">
    <input type="text" class="search-input" id="search-listado" placeholder="Buscar por nombre..." oninput="filtrarListado(this.value)">
    <div id="listado-container">
      <p style="color:#666;text-align:center;padding:20px;">Cargando...</p>
    </div>
  </div>
</div>

<script src="https://unpkg.com/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
var eventoId = <?= $eventoId ?>;
var scanning = true;
var lastToken = '';
var cooldown = false;

// ── CÁMARA ────────────────────────────────────────────────────────────────────
var video = document.getElementById('qr-video');
var canvas = document.createElement('canvas');
var ctx = canvas.getContext('2d');

navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
    .then(function(stream) {
        video.srcObject = stream;
        video.play();
        requestAnimationFrame(scanFrame);
    })
    .catch(function(err) {
        document.getElementById('qr-container').innerHTML =
            '<p style="color:#888;text-align:center;padding:40px 20px;">Cámara no disponible.<br>Usa el campo manual.</p>';
    });

function scanFrame() {
    if (!scanning || cooldown) { requestAnimationFrame(scanFrame); return; }
    if (video.readyState === video.HAVE_ENOUGH_DATA) {
        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0);
        var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
        if (code && code.data) {
            var token = extraerToken(code.data);
            if (token && token !== lastToken) {
                lastToken = token;
                validarToken(token);
            }
        }
    }
    requestAnimationFrame(scanFrame);
}

function extraerToken(data) {
    // Puede ser URL con ?t= o el token directamente
    var m = data.match(/[?&]t=([a-f0-9]+)/i);
    if (m) return m[1];
    if (/^[a-f0-9]{48}$/.test(data.trim())) return data.trim();
    return null;
}

// ── VALIDACIÓN ────────────────────────────────────────────────────────────────
function validarToken(token) {
    cooldown = true;
    fetch('validar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'token=' + encodeURIComponent(token) + '&evento_id=' + eventoId
    })
    .then(r => r.json())
    .then(function(data) {
        mostrarResultado(data);
        actualizarStats(data.stats);
        setTimeout(function() {
            ocultarResultado();
            lastToken = '';
            cooldown = false;
        }, 4000);
    })
    .catch(function() {
        cooldown = false;
    });
}

function mostrarResultado(data) {
    var tipo = data.resultado; // ok | ya_usado | invalido
    var iconos = { ok: '✅', ya_usado: '⚠️', invalido: '❌' };
    var titulos = { ok: 'ENTRADA VÁLIDA', ya_usado: 'YA VALIDADA', invalido: 'NO VÁLIDA' };

    var html = '<div class="resultado ' + tipo + '">';
    html += '<div class="icono">' + iconos[tipo] + '</div>';
    html += '<div class="titulo">' + titulos[tipo] + '</div>';
    if (data.nombre) html += '<div class="nombre-grande">' + esc(data.nombre) + '</div>';
    if (data.evento) html += '<div class="detalle">' + esc(data.evento) + '</div>';
    if (data.campo_extra) html += '<div class="campo-extra">' + esc(data.campo_extra) + '</div>';
    if (tipo === 'ya_usado' && data.usado_at)
        html += '<div class="detalle" style="margin-top:8px;color:#f59e0b;">Validada: ' + esc(data.usado_at) + '</div>';
    html += '</div>';
    document.getElementById('resultado-container').innerHTML = html;
}

function ocultarResultado() {
    document.getElementById('resultado-container').innerHTML = '';
}

function actualizarStats(stats) {
    if (!stats) return;
    document.querySelector('.stat:nth-child(1) .val').textContent = stats.usados;
    document.querySelector('.stat:nth-child(3) .val').textContent = stats.pendientes;
}

// ── INPUT MANUAL ──────────────────────────────────────────────────────────────
document.getElementById('input-manual').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && this.value.trim()) {
        var token = extraerToken(this.value.trim()) || this.value.trim();
        validarToken(token);
        this.value = '';
    }
});

// ── TABS ──────────────────────────────────────────────────────────────────────
function showTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
    document.getElementById('panel-' + id).classList.add('active');
    btn.classList.add('active');
    if (id === 'listado') cargarListado();
}

// ── LISTADO ───────────────────────────────────────────────────────────────────
var listadoData = [];
function cargarListado() {
    fetch('listado.php?evento_id=' + eventoId)
        .then(r => r.json())
        .then(function(data) {
            listadoData = data;
            renderListado(data);
        });
}

function renderListado(data) {
    var html = '';
    data.forEach(function(e) {
        html += '<div class="entrada-row">';
        html += '<div class="' + (e.usado ? 'dot-ok' : 'dot-no') + '"></div>';
        html += '<div style="flex:1"><div class="nombre">' + esc(e.nombre) + '</div>';
        html += '<div class="meta">' + esc(e.pedido) + (e.usado_at ? ' · ✓ ' + esc(e.usado_at) : '') + '</div></div>';
        html += '</div>';
    });
    document.getElementById('listado-container').innerHTML = html || '<p style="color:#666;text-align:center;padding:20px;">Sin entradas.</p>';
}

function filtrarListado(q) {
    q = q.toLowerCase();
    var filtrado = listadoData.filter(function(e) {
        return e.nombre.toLowerCase().includes(q) || e.pedido.toLowerCase().includes(q);
    });
    renderListado(filtrado);
}

function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
</script>
<?php endif; ?>
</body>
</html>
