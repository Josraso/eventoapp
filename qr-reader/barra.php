<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

// Sesión de 30 días para camarero (gestionada por Auth::startSession)
Auth::ensureSession();

// Login de camarero
$error = '';
if (!empty($_POST['action']) && $_POST['action'] === 'login_camarero') {
    if (Auth::adminLogin($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        if (!in_array(Auth::adminRole(), ['camarero', 'admin', 'superadmin'])) {
            Auth::adminLogout();
            $error = 'Sin permisos de camarero.';
        }
    } else {
        $error = 'Credenciales incorrectas.';
    }
}

$isLogged = !empty($_SESSION['admin_id']);
$siteName = getSetting('site_name', 'Eventos');

// Eventos asignados al camarero
$eventosAsignados = [];
if ($isLogged) {
    $eids = Auth::camareroEventos();
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
$productos = [];
if ($eventoId && $isLogged) {
    $eids = Auth::camareroEventos();
    if (in_array($eventoId, $eids)) {
        $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
        $stEv->execute([$eventoId]);
        $eventoActual = $stEv->fetch();
        if ($eventoActual) {
            $stProd = db()->prepare('SELECT * FROM productos_consumicion WHERE evento_id=? AND activo=1 ORDER BY sort_order ASC');
            $stProd->execute([$eventoId]);
            $productos = $stProd->fetchAll();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no">
<title>Lector de barra — <?= h($siteName) ?></title>
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
.field input, .field select { width: 100%; background: #0a0a0a; border: 1.5px solid #333; border-radius: 10px; padding: 11px 13px; font-size: 14px; color: #fff; outline: none; margin-bottom: 12px; }
.field input:focus, .field select:focus { border-color: #a78bfa; }
.btn { width: 100%; background: #a78bfa; color: #0a0a0a; border: none; border-radius: 10px; padding: 13px; font-size: 15px; font-weight: 700; cursor: pointer; }
.alert-err { background: #3a0a0a; border: 1px solid #c0392b; border-radius: 8px; padding: 11px; font-size: 13px; color: #ff6b6b; margin-bottom: 14px; }

/* SELECTOR EVENTO */
.evento-btn { display: block; width: 100%; background: #1a1a1a; border: 1.5px solid #333; border-radius: 12px; padding: 16px; margin-bottom: 12px; text-align: left; cursor: pointer; color: #fff; font-family: inherit; }
.evento-btn:hover { border-color: #a78bfa; background: #111; }
.evento-btn .nombre { font-size: 15px; font-weight: 700; }
.evento-btn .fecha { font-size: 12px; color: #888; margin-top: 3px; }

/* ESCÁNER */
#qr-container { background: #000; border-radius: 16px; overflow: hidden; position: relative; margin-bottom: 20px; aspect-ratio: 1; }
#qr-video { width: 100%; height: 100%; object-fit: cover; }
.scan-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
.scan-frame { width: 65%; aspect-ratio: 1; border: 3px solid rgba(167,139,250,.8); border-radius: 12px; box-shadow: 0 0 0 4000px rgba(0,0,0,.3); }
.scan-line { position: absolute; width: 65%; height: 2px; background: linear-gradient(90deg, transparent, #a78bfa, transparent); animation: scanline 2s ease-in-out infinite; }
@keyframes scanline { 0%,100% { top: 17%; } 50% { top: 83%; } }

/* RESULTADO */
.resultado { border-radius: 14px; padding: 24px; margin-bottom: 20px; text-align: center; animation: fadeIn .3s ease; }
@keyframes fadeIn { from { opacity:0; transform: scale(.95); } to { opacity:1; transform: scale(1); } }
.resultado.ok { background: #1a0a2a; border: 2px solid #a78bfa; }
.resultado.ya-usado { background: #2a1a0a; border: 2px solid #f59e0b; }
.resultado.ya_usado { background: #2a1a0a; border: 2px solid #f59e0b; }
.resultado.invalido { background: #2a0a0a; border: 2px solid #ef4444; }
.resultado .icono { font-size: 52px; margin-bottom: 12px; }
.resultado .titulo { font-size: 22px; font-weight: 800; margin-bottom: 8px; }
.resultado.ok .titulo { color: #a78bfa; }
.resultado.ya_usado .titulo, .resultado.ya-usado .titulo { color: #f59e0b; }
.resultado.invalido .titulo { color: #ef4444; }
.resultado .detalle { font-size: 14px; color: #ccc; line-height: 1.6; }
.resultado .nombre-grande { font-size: 28px; font-weight: 800; color: #fff; margin: 10px 0 6px; }

/* STATS */
.stats { display: flex; gap: 10px; margin-bottom: 20px; }
.stat { flex: 1; background: #1a1a1a; border-radius: 10px; padding: 14px; text-align: center; }
.stat .val { font-size: 26px; font-weight: 800; color: #a78bfa; }
.stat .lbl { font-size: 11px; color: #666; margin-top: 3px; }

/* TABS */
.tabs { display: flex; gap: 4px; margin-bottom: 16px; }
.tab { flex: 1; background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 10px; font-size: 13px; font-weight: 600; cursor: pointer; color: #888; }
.tab.active { background: #a78bfa; color: #000; border-color: #a78bfa; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* LISTADO */
.entrada-row { background: #1a1a1a; border-radius: 10px; padding: 12px 14px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.entrada-row .nombre { font-size: 14px; font-weight: 600; }
.entrada-row .meta { font-size: 12px; color: #666; margin-top: 2px; }
.dot-ok { width: 8px; height: 8px; background: #a78bfa; border-radius: 50%; flex-shrink: 0; }
.dot-no { width: 8px; height: 8px; background: #333; border-radius: 50%; flex-shrink: 0; }
.search-input { width: 100%; background: #1a1a1a; border: 1.5px solid #333; border-radius: 10px; padding: 11px 14px; font-size: 14px; color: #fff; outline: none; margin-bottom: 12px; }
.search-input:focus { border-color: #a78bfa; }
.btn-cambiar { background: none; border: 1px solid #333; border-radius: 8px; padding: 6px 12px; font-size: 12px; color: #888; cursor: pointer; }
.btn-marcar { background: #a78bfa; border: none; border-radius: 8px; padding: 8px 14px; font-size: 12px; font-weight: 700; color: #0a0a0a; cursor: pointer; flex-shrink: 0; }
.btn-desmarcar { background: none; border: 1px solid #555; border-radius: 8px; padding: 8px 14px; font-size: 12px; color: #aaa; cursor: pointer; flex-shrink: 0; }

/* CAJA */
.caja-card { background: #1a1a1a; border-radius: 12px; padding: 18px; }
</style>
</head>
<body>

<?php if (!$isLogged): ?>
<!-- LOGIN CAMARERO -->
<div class="content">
  <div class="login-card">
    <h2>🍹 Acceso barra</h2>
    <?php if ($error): ?><div class="alert-err"><?= h($error) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="login_camarero">
      <div class="field"><label>Usuario</label><input type="text" name="username" required autofocus autocomplete="username"></div>
      <div class="field"><label>Contraseña</label><input type="password" name="password" required autocomplete="current-password"></div>
      <button type="submit" class="btn">Entrar</button>
    </form>
  </div>
</div>

<?php elseif (!$eventoActual): ?>
<!-- SELECCIONAR EVENTO -->
<div class="header">
  <h1>🍹 <?= h($siteName) ?></h1>
  <div class="info"><?= h(Auth::adminName()) ?> &nbsp;|&nbsp; <a href="logout-portero.php" style="color:#888;font-size:12px;">Salir</a></div>
</div>
<div class="content" style="margin-top:20px;">
  <p style="color:#888;font-size:14px;margin-bottom:16px;">Selecciona el evento a gestionar:</p>
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
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- PANEL PRINCIPAL -->
<?php
$stStats = db()->prepare("SELECT COUNT(*) FROM consumiciones WHERE evento_id=? AND usado=1");
$stStats->execute([$eventoId]);
$usados = (int)$stStats->fetchColumn();
$stTotal = db()->prepare("SELECT COUNT(*) FROM consumiciones WHERE evento_id=?");
$stTotal->execute([$eventoId]);
$total = (int)$stTotal->fetchColumn();
?>
<div class="header">
  <div>
    <h1><?= h($eventoActual['nombre']) ?></h1>
    <div class="info">Camarero: <?= h(Auth::adminName()) ?></div>
  </div>
  <button class="btn-cambiar" onclick="location.href='barra.php'">Cambiar evento</button>
</div>

<div class="content">
  <div class="tabs">
    <button class="tab active" onclick="showTab('escaner',this)">📷 Escáner</button>
    <button class="tab" onclick="showTab('listado',this)">📋 Listado</button>
    <button class="tab" onclick="showTab('caja',this)">💶 Venta en caja</button>
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
      Apunta la cámara al código QR del ticket de consumición
    </div>

    <div style="margin-bottom:16px;">
      <input type="text" id="input-manual" class="search-input" placeholder="O introduce el código de 6 caracteres..." maxlength="6" style="text-transform:uppercase;letter-spacing:.1em;text-align:center;font-weight:700;" autocomplete="off">
    </div>
  </div>

  <!-- LISTADO -->
  <div class="tab-panel" id="panel-listado">
    <input type="text" class="search-input" id="search-listado" placeholder="Buscar por producto o pedido..." oninput="filtrarListado()">
    <div class="tabs" style="margin-bottom:12px;">
      <button class="tab active" id="filtro-todos" onclick="setFiltro('todos', this)">Todos</button>
      <button class="tab" id="filtro-pendientes" onclick="setFiltro('pendientes', this)">Pendientes</button>
      <button class="tab" id="filtro-validados" onclick="setFiltro('validados', this)">Validados</button>
    </div>
    <div id="listado-container">
      <p style="color:#666;text-align:center;padding:20px;">Cargando...</p>
    </div>
  </div>

  <!-- VENTA EN CAJA -->
  <div class="tab-panel" id="panel-caja">
    <div class="caja-card">
      <p style="font-size:13px;color:#888;margin-bottom:14px;">Registra una venta pagada en efectivo en el mostrador. No requiere cuenta de usuario.</p>
      <form method="POST" action="venta-caja.php" target="_blank">
        <input type="hidden" name="evento_id" value="<?= $eventoId ?>">
        <div class="field">
          <label>Producto</label>
          <select name="producto_id" required>
            <?php foreach ($productos as $p): ?>
              <option value="<?= $p['id'] ?>"><?= h($p['nombre']) ?> — <?= number_format((float)$p['precio'], 2) ?> €</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Cantidad</label>
          <input type="number" name="cantidad" value="1" min="1" max="50" required>
        </div>
        <div class="field">
          <label>Nombre del comprador (opcional)</label>
          <input type="text" name="nombre_comprador" placeholder="Opcional">
        </div>
        <button type="submit" class="btn">💶 Cobrar y generar tickets</button>
      </form>
      <?php if (empty($productos)): ?><p style="font-size:12px;color:#aaa;margin-top:10px;">Este evento no tiene productos de barra activos.</p><?php endif; ?>
    </div>
  </div>
</div>

<script src="vendor/jsQR.js"></script>
<script>
var eventoId = <?= $eventoId ?>;
var scanning = true;
var lastToken = '';
var cooldown = false;

var video = document.getElementById('qr-video');
var canvas = document.createElement('canvas');
var ctx = canvas.getContext('2d');

function avisoCamara(msg) {
    var aviso = document.getElementById('aviso-camara');
    if (!aviso) {
        aviso = document.createElement('p');
        aviso.id = 'aviso-camara';
        aviso.style.cssText = 'color:#888;text-align:center;padding:10px 20px;font-size:13px;';
        document.getElementById('qr-container').insertAdjacentElement('afterend', aviso);
    }
    aviso.innerHTML = msg;
}

function iniciarCamara() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        avisoCamara('Este navegador no permite acceder a la cámara. Usa el campo manual.');
        return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(function(stream) {
            video.srcObject = stream;
            video.play();
            requestAnimationFrame(scanFrame);
        })
        .catch(function(err) {
            avisoCamara('Cámara no disponible (' + (err.name || 'error') + '). Usa el campo manual.');
        });
}

iniciarCamara();

function scanFrame() {
    if (!scanning || cooldown) { requestAnimationFrame(scanFrame); return; }
    try {
        if (typeof jsQR === 'function' && video.readyState === video.HAVE_ENOUGH_DATA && video.videoWidth > 0) {
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
            if (code && code.data) {
                var token = extraerToken(code.data);
                if (token && token !== lastToken) {
                    lastToken = token;
                    validarToken(token);
                }
            }
        }
    } catch (e) {
        console.error('Error escaneando frame:', e);
    }
    requestAnimationFrame(scanFrame);
}

function extraerToken(data) {
    var m = data.match(/[?&]t=([a-f0-9]+)/i);
    if (m) return m[1];
    if (/^[a-f0-9]{48}$/.test(data.trim())) return data.trim();
    return null;
}

function validarToken(token) {
    cooldown = true;
    fetch('validar-consumicion.php', {
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
    var titulos = { ok: 'CONSUMICIÓN VÁLIDA', ya_usado: 'YA CANJEADA', invalido: 'NO VÁLIDA' };

    var html = '<div class="resultado ' + tipo + '">';
    html += '<div class="icono">' + iconos[tipo] + '</div>';
    html += '<div class="titulo">' + titulos[tipo] + '</div>';
    if (data.producto) html += '<div class="nombre-grande">' + esc(data.producto) + '</div>';
    if (data.evento) html += '<div class="detalle">' + esc(data.evento) + '</div>';
    if (tipo === 'ya_usado' && data.usado_at)
        html += '<div class="detalle" style="margin-top:8px;color:#f59e0b;">Canjeada: ' + esc(data.usado_at) + '</div>';
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

document.getElementById('input-manual').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && this.value.trim()) {
        var token = extraerToken(this.value.trim()) || this.value.trim();
        validarToken(token);
        this.value = '';
    }
});

function showTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.header + .content > .tabs .tab').forEach(b => b.classList.remove('active'));
    document.getElementById('panel-' + id).classList.add('active');
    btn.classList.add('active');
    if (id === 'listado') cargarListado();
}

var listadoData = [];
var filtroActual = 'todos';

function cargarListado() {
    fetch('listado-consumicion.php?evento_id=' + eventoId)
        .then(r => r.json())
        .then(function(data) {
            listadoData = data;
            filtrarListado();
        });
}

function setFiltro(filtro, btn) {
    filtroActual = filtro;
    document.querySelectorAll('#panel-listado .tabs .tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filtrarListado();
}

function renderListado(data) {
    var html = '';
    data.forEach(function(e) {
        html += '<div class="entrada-row">';
        html += '<div class="' + (e.usado ? 'dot-ok' : 'dot-no') + '"></div>';
        html += '<div style="flex:1"><div class="nombre">' + esc(e.nombre) + '</div>';
        html += '<div class="meta">' + esc(e.pedido) + (e.usado_at ? ' · ✓ ' + esc(e.usado_at) : '') + '</div></div>';
        if (e.usado) {
            html += '<button class="btn-desmarcar" onclick="marcarConsumicion(' + e.id + ',\'desmarcar\')">Desmarcar</button>';
        } else {
            html += '<button class="btn-marcar" onclick="marcarConsumicion(' + e.id + ',\'marcar\')">✓ Marcar canjeada</button>';
        }
        html += '</div>';
    });
    document.getElementById('listado-container').innerHTML = html || '<p style="color:#666;text-align:center;padding:20px;">Sin consumiciones.</p>';
}

function filtrarListado() {
    var q = document.getElementById('search-listado').value.toLowerCase();
    var filtrado = listadoData.filter(function(e) {
        var coincideTexto = e.nombre.toLowerCase().includes(q) || e.pedido.toLowerCase().includes(q);
        var coincideFiltro = filtroActual === 'todos'
            || (filtroActual === 'validados' && e.usado)
            || (filtroActual === 'pendientes' && !e.usado);
        return coincideTexto && coincideFiltro;
    });
    renderListado(filtrado);
}

function marcarConsumicion(id, accion) {
    fetch('marcar-consumicion.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'consumicion_id=' + id + '&accion=' + accion
    })
    .then(r => r.json())
    .then(function(data) {
        if (!data.ok) { alert('No se pudo actualizar la consumición.'); return; }
        var item = listadoData.find(function(e) { return e.id === id; });
        if (item) {
            item.usado = data.usado;
            item.usado_at = data.usado ? 'ahora' : '';
        }
        actualizarStats(data.stats);
        filtrarListado();
    })
    .catch(function() { alert('Error de conexión.'); });
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
