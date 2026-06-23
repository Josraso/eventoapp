<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');

$adminRole = Auth::adminRole();
$id     = (int)($_GET['id'] ?? 0);
$evento = null;
$campos = [];
$base   = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

if ($id) {
    $st = db()->prepare('SELECT * FROM eventos WHERE id=?');
    $st->execute([$id]);
    $evento = $st->fetch();
    if (!$evento) { flash('error', 'Evento no encontrado.'); header('Location: ' . $base . '/admin/eventos/index.php'); exit; }
    if ($adminRole !== 'superadmin' && (int)$evento['admin_id'] !== Auth::adminId()) {
        flash('error', 'No tienes permiso sobre este evento.'); header('Location: ' . $base . '/admin/eventos/index.php'); exit;
    }

    $stC = db()->prepare('SELECT * FROM evento_campos WHERE evento_id=? ORDER BY sort_order ASC');
    $stC->execute([$id]);
    $campos = $stC->fetchAll();

    $stP = db()->prepare('SELECT * FROM productos_consumicion WHERE evento_id=? ORDER BY sort_order ASC');
    $stP->execute([$id]);
    $productos = $stP->fetchAll();

    $fechasEvento = fechasEvento($id);
} else {
    $productos = [];
    $fechasEvento = [];
}

$listaAdmins = $adminRole === 'superadmin'
    ? db()->query("SELECT id, name, username FROM admin_users WHERE role IN ('admin','superadmin') ORDER BY name ASC")->fetchAll()
    : [];

$pageTitle = $evento ? 'Editar evento: ' . $evento['nombre'] : 'Nuevo evento';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();

    $action = $_POST['action'] ?? 'guardar';

    if ($action === 'guardar') {
        // Datos básicos
        $nombre     = trim($_POST['nombre'] ?? '');
        $slug       = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', trim($_POST['slug'] ?? $nombre))));
        $descripcion = trim($_POST['descripcion'] ?? '');
        $fechas      = array_filter($_POST['fechas'] ?? []);
        $lugar       = trim($_POST['lugar'] ?? '');
        $lugar_url   = trim($_POST['lugar_url'] ?? '');
        $precio      = (float)str_replace(',', '.', $_POST['precio'] ?? '0');
        $es_gratuito = isset($_POST['es_gratuito']) ? 1 : 0;
        $max_inscritos = (int)($_POST['max_inscritos'] ?? 0) ?: null;
        $fecha_limite = $_POST['fecha_limite'] ?: null;
        $metodos     = array_filter($_POST['metodos_pago'] ?? ['stripe']);
        $metodos_str = implode(',', $metodos);
        $metodosBarra     = array_filter($_POST['metodos_pago_barra'] ?? ['stripe']);
        $metodosBarra_str = implode(',', $metodosBarra);
        $campo_qr    = trim($_POST['campo_qr_extra'] ?? '');
        $activo      = isset($_POST['activo']) ? 1 : 0;
        $archivado   = isset($_POST['archivado']) ? 1 : 0;
        $sort_order  = (int)($_POST['sort_order'] ?? 0);
        // Asignación de admin propietario: solo el superadmin puede reasignar;
        // un admin normal siempre es propietario de los eventos que crea.
        if ($adminRole === 'superadmin') {
            $admin_id_evento = (int)($_POST['admin_id'] ?? 0) ?: null;
        } else {
            $admin_id_evento = $id ? (int)$evento['admin_id'] : Auth::adminId();
        }

        if (!$nombre) $error = 'El nombre es obligatorio.';
        elseif (!$slug) $error = 'El slug es obligatorio.';
        elseif (!$fechas) $error = 'Debes indicar al menos una fecha del evento.';
        else {
            // Verificar slug único
            $chk = db()->prepare('SELECT id FROM eventos WHERE slug=? AND id!=?');
            $chk->execute([$slug, $id ?: 0]);
            if ($chk->fetch()) $error = 'El slug ya está en uso por otro evento.';
        }

        // Subir imagen si hay
        $imagen = $evento['imagen'] ?? null;
        if (!empty($_FILES['imagen']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $error = 'Formato de imagen no válido.';
            } else {
                $dir = __DIR__ . '/../../storage/imagenes/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                chmod($dir, 0755);
                $fname = 'evento_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], $dir . $fname)) {
                    chmod($dir . $fname, 0644);
                    $imagen = 'storage/imagenes/' . $fname;
                }
            }
        }

        if (!$error) {
            if ($id) {
                $admin_id_anterior = (int)$evento['admin_id'];
                db()->prepare('UPDATE eventos SET nombre=?,slug=?,descripcion=?,imagen=?,lugar=?,lugar_url=?,precio=?,es_gratuito=?,max_inscritos=?,fecha_limite_inscripcion=?,metodos_pago=?,metodos_pago_barra=?,campo_qr_extra=?,activo=?,archivado=?,sort_order=?,admin_id=?,updated_at=NOW() WHERE id=?')
                   ->execute([$nombre,$slug,$descripcion,$imagen,$lugar,$lugar_url,$precio,$es_gratuito,$max_inscritos,$fecha_limite,$metodos_str,$metodosBarra_str,$campo_qr,$activo,$archivado,$sort_order,$admin_id_evento,$id]);

                // Si se reasigna el propietario del evento, los porteros y camareros
                // asignados a este evento pasan a tener también ese admin como
                // creado_por. La visibilidad real ya es dinámica (porteroEsPropio()/
                // camareroEsPropio() consultan los eventos asignados), pero esto deja
                // al nuevo admin como gestor de pleno derecho aunque el portero/camarero
                // pierda más adelante el acceso a todos sus eventos.
                if ($admin_id_evento && $admin_id_evento !== $admin_id_anterior) {
                    db()->prepare("UPDATE admin_users SET creado_por=? WHERE role IN ('portero','camarero') AND id IN (SELECT admin_id FROM portero_eventos WHERE evento_id=?)")
                       ->execute([$admin_id_evento, $id]);
                }
            } else {
                db()->prepare('INSERT INTO eventos (nombre,slug,descripcion,imagen,lugar,lugar_url,precio,es_gratuito,max_inscritos,fecha_limite_inscripcion,metodos_pago,metodos_pago_barra,campo_qr_extra,activo,sort_order,admin_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$nombre,$slug,$descripcion,$imagen,$lugar,$lugar_url,$precio,$es_gratuito,$max_inscritos,$fecha_limite,$metodos_str,$metodosBarra_str,$campo_qr,$activo,$sort_order,$admin_id_evento]);
                $id = (int)db()->lastInsertId();
            }

            guardarFechasEvento($id, $fechas);

            // Guardar campos personalizados: borrar los que no estén en el POST
            $camposPost = $_POST['campos'] ?? [];
            $idsExistentes = [];
            foreach ($camposPost as $c) {
                $cid = (int)($c['id'] ?? 0);
                $cnombre = trim($c['nombre'] ?? '');
                $clabel  = trim($c['label'] ?? '');
                if (!$cnombre || !$clabel) continue;
                $ctipo   = in_array($c['tipo'] ?? '', ['text','email','tel','number','select','textarea','checkbox','date']) ? $c['tipo'] : 'text';
                $copciones = trim($c['opciones'] ?? '');
                $cobligatorio = isset($c['obligatorio']) ? 1 : 0;
                $cpara_titular = isset($c['para_titular']) ? 1 : 0;
                $cpara_asistentes = isset($c['para_asistentes']) ? 1 : 0;
                $csort = (int)($c['sort'] ?? 0);

                if ($cid) {
                    db()->prepare('UPDATE evento_campos SET nombre=?,label=?,tipo=?,opciones=?,obligatorio=?,para_titular=?,para_asistentes=?,sort_order=? WHERE id=? AND evento_id=?')
                       ->execute([$cnombre,$clabel,$ctipo,$copciones,$cobligatorio,$cpara_titular,$cpara_asistentes,$csort,$cid,$id]);
                    $idsExistentes[] = $cid;
                } else {
                    db()->prepare('INSERT INTO evento_campos (evento_id,nombre,label,tipo,opciones,obligatorio,para_titular,para_asistentes,sort_order) VALUES(?,?,?,?,?,?,?,?,?)')
                       ->execute([$id,$cnombre,$clabel,$ctipo,$copciones,$cobligatorio,$cpara_titular,$cpara_asistentes,$csort]);
                    $idsExistentes[] = (int)db()->lastInsertId();
                }
            }
            // Borrar campos eliminados
            if (!empty($idsExistentes)) {
                $ph = implode(',', array_fill(0, count($idsExistentes), '?'));
                $args = array_merge($idsExistentes, [$id]);
                db()->prepare("DELETE FROM evento_campos WHERE evento_id=? AND id NOT IN ($ph)")->execute(array_merge([$id], $idsExistentes));
            } else {
                db()->prepare('DELETE FROM evento_campos WHERE evento_id=?')->execute([$id]);
            }

            // Guardar productos de barra: borrar los que no estén en el POST
            $productosPost = $_POST['productos'] ?? [];
            $idsProdExistentes = [];
            foreach ($productosPost as $p) {
                $pid = (int)($p['id'] ?? 0);
                $pnombre = trim($p['nombre'] ?? '');
                if (!$pnombre) continue;
                $pprecio = (float)str_replace(',', '.', $p['precio'] ?? '0');
                $pactivo = isset($p['activo']) ? 1 : 0;
                $psort   = (int)($p['sort'] ?? 0);

                if ($pid) {
                    db()->prepare('UPDATE productos_consumicion SET nombre=?,precio=?,activo=?,sort_order=? WHERE id=? AND evento_id=?')
                       ->execute([$pnombre,$pprecio,$pactivo,$psort,$pid,$id]);
                    $idsProdExistentes[] = $pid;
                } else {
                    db()->prepare('INSERT INTO productos_consumicion (evento_id,nombre,precio,activo,sort_order) VALUES(?,?,?,?,?)')
                       ->execute([$id,$pnombre,$pprecio,$pactivo,$psort]);
                    $idsProdExistentes[] = (int)db()->lastInsertId();
                }
            }
            if (!empty($idsProdExistentes)) {
                $ph = implode(',', array_fill(0, count($idsProdExistentes), '?'));
                db()->prepare("DELETE FROM productos_consumicion WHERE evento_id=? AND id NOT IN ($ph)")->execute(array_merge([$id], $idsProdExistentes));
            } else {
                db()->prepare('DELETE FROM productos_consumicion WHERE evento_id=?')->execute([$id]);
            }

            Auth::logAction('evento_' . ($id ? 'editar' : 'crear'), 'Evento ID:' . $id . ' nombre:' . $nombre);
            flash('ok', 'Evento guardado correctamente.');
            header('Location: ' . $base . '/admin/eventos/editar.php?id=' . $id); exit;
        }
    }
}
?>
<?php require_once __DIR__ . '/../_header.php'; ?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
  <a href="<?= h($base) ?>/admin/eventos/index.php" style="font-size:13px;color:#888;text-decoration:none;">← Volver a eventos</a>
  <?php if ($id): ?>
    <a href="<?= h($base) ?>/public/evento.php?slug=<?= urlencode($evento['slug']) ?>" target="_blank" style="font-size:13px;color:#6366f1;">Ver en web ↗</a>
  <?php endif; ?>
</div>

<style>
.evento-tabs{display:flex;gap:4px;border-bottom:2px solid #e8e8e8;margin-bottom:20px;}
.evento-tab{padding:10px 18px;font-size:14px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:#888;background:none;border-top:none;border-left:none;border-right:none;font-family:inherit;}
.evento-tab.active{color:#1a1a1a;border-bottom-color:#6366f1;}
.evento-tab-panel{display:none;}
.evento-tab-panel.active{display:block;}
</style>

<div class="evento-tabs">
  <button type="button" class="evento-tab active" onclick="showEventoTab('general',this)">General</button>
  <button type="button" class="evento-tab" onclick="showEventoTab('barra',this)">🍹 Productos de barra<?= !empty($productos) ? ' ('.count($productos).')' : '' ?></button>
</div>

<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
  <input type="hidden" name="action" value="guardar">

  <div class="evento-tab-panel active" id="evento-tab-general">
  <div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start;">

    <!-- COLUMNA PRINCIPAL -->
    <div>
      <div class="card">
        <div class="card-title">Información del evento</div>
        <div class="field">
          <label>Nombre del evento *</label>
          <input type="text" name="nombre" required value="<?= h($evento['nombre'] ?? '') ?>"
                 oninput="generarSlug(this.value)">
        </div>
        <div class="field">
          <label>Slug (URL) *</label>
          <input type="text" name="slug" id="slug-input" required
                 value="<?= h($evento['slug'] ?? '') ?>"
                 pattern="[a-z0-9\-]+"
                 title="Solo letras minúsculas, números y guiones">
          <p class="hint">Se usa en la URL del evento. Solo letras minúsculas, números y guiones.</p>
        </div>
        <div class="field">
          <label>Descripción</label>
          <textarea name="descripcion" id="descripcion-editor" rows="5"><?= h($evento['descripcion'] ?? '') ?></textarea>
        </div>
        <div class="field">
          <label>Fecha(s) y hora del evento</label>
          <p class="hint" style="margin-top:-4px;margin-bottom:8px;">Añade una fecha si el evento es de un solo día, o varias si dura más días o tiene varias sesiones.</p>
          <div id="fechas-container">
            <?php if (empty($fechasEvento)): $fechasEvento = ['']; endif; ?>
            <?php foreach ($fechasEvento as $fi => $f): ?>
            <div class="fecha-block" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
              <input type="datetime-local" name="fechas[]" required
                     value="<?= $f ? date('Y-m-d\TH:i', strtotime($f)) : '' ?>" style="flex:1;">
              <button type="button" onclick="quitarFecha(this)" class="btn btn-sm btn-danger" style="padding:6px 10px;font-size:11px;">✕</button>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="button" onclick="addFecha()" class="btn btn-sm btn-outline">+ Añadir fecha</button>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Límite inscripción</label>
            <input type="datetime-local" name="fecha_limite"
                   value="<?= $evento['fecha_limite_inscripcion'] ? date('Y-m-d\TH:i', strtotime($evento['fecha_limite_inscripcion'])) : '' ?>">
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Lugar / Sede</label>
            <input type="text" name="lugar" value="<?= h($evento['lugar'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Enlace a Google Maps (opcional)</label>
            <input type="url" name="lugar_url" value="<?= h($evento['lugar_url'] ?? '') ?>" placeholder="https://maps.google.com/...">
            <p class="hint">Si lo dejas en blanco, se generará automáticamente a partir del texto del lugar.</p>
          </div>
        </div>
      </div>

      <?php if ($adminRole === 'superadmin'): ?>
      <div class="card">
        <div class="card-title">Propietario del evento</div>
        <div class="field">
          <label>Administrador asignado</label>
          <select name="admin_id">
            <option value="">— Sin asignar —</option>
            <?php foreach ($listaAdmins as $a): ?>
              <option value="<?= $a['id'] ?>" <?= (int)($evento['admin_id'] ?? 0)===$a['id']?'selected':'' ?>>
                <?= h($a['name'] ?: $a['username']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="hint">Solo el administrador asignado (y el superadmin) podrá ver y gestionar este evento.</p>
        </div>
      </div>
      <?php endif; ?>

      <!-- CAMPOS PERSONALIZADOS -->
      <div class="card">
        <div class="card-title">Campos personalizados</div>
        <p style="font-size:13px;color:#888;margin-bottom:16px;">Define qué información adicional se pide en el formulario de inscripción.</p>

        <div id="campos-container">
          <?php foreach ($campos as $ci => $campo): ?>
          <div class="campo-block" style="border:1px solid #e4e4e8;border-radius:10px;padding:16px;margin-bottom:12px;position:relative;">
            <div style="position:absolute;top:10px;right:10px;">
              <button type="button" onclick="this.closest('.campo-block').remove()" class="btn btn-sm btn-danger" style="padding:4px 10px;font-size:11px;">✕</button>
            </div>
            <input type="hidden" name="campos[<?= $ci ?>][id]" value="<?= (int)$campo['id'] ?>">
            <div class="field-row">
              <div class="field">
                <label>Nombre interno (sin espacios)</label>
                <input type="text" name="campos[<?= $ci ?>][nombre]" value="<?= h($campo['nombre']) ?>" required pattern="[a-zA-Z0-9_]+">
              </div>
              <div class="field">
                <label>Etiqueta visible</label>
                <input type="text" name="campos[<?= $ci ?>][label]" value="<?= h($campo['label']) ?>" required>
              </div>
              <div class="field">
                <label>Tipo</label>
                <select name="campos[<?= $ci ?>][tipo]" onchange="toggleOpciones(this)">
                  <?php foreach (['text','email','tel','number','select','textarea','checkbox','date'] as $t): ?>
                    <option value="<?= $t ?>" <?= $campo['tipo']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="campo-opciones" style="<?= $campo['tipo']==='select'?'':'display:none' ?>">
              <div class="field">
                <label>Opciones (una por línea)</label>
                <textarea name="campos[<?= $ci ?>][opciones]" rows="3"><?= h($campo['opciones'] ?? '') ?></textarea>
              </div>
            </div>
            <div class="field-row">
              <div class="field">
                <label style="text-transform:none;font-size:13px;font-weight:normal;display:flex;align-items:center;gap:6px;">
                  <input type="checkbox" name="campos[<?= $ci ?>][obligatorio]" value="1" <?= $campo['obligatorio']?'checked':'' ?>>
                  Obligatorio
                </label>
              </div>
              <div class="field">
                <label style="text-transform:none;font-size:13px;font-weight:normal;display:flex;align-items:center;gap:6px;">
                  <input type="checkbox" name="campos[<?= $ci ?>][para_titular]" value="1" <?= $campo['para_titular']?'checked':'' ?>>
                  Para titular
                </label>
              </div>
              <div class="field">
                <label style="text-transform:none;font-size:13px;font-weight:normal;display:flex;align-items:center;gap:6px;">
                  <input type="checkbox" name="campos[<?= $ci ?>][para_asistentes]" value="1" <?= $campo['para_asistentes']?'checked':'' ?>>
                  Para asistentes
                </label>
              </div>
              <div class="field">
                <label>Orden</label>
                <input type="number" name="campos[<?= $ci ?>][sort]" value="<?= (int)$campo['sort_order'] ?>" style="max-width:60px;">
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" onclick="addCampo()" class="btn btn-sm btn-outline">+ Añadir campo</button>
      </div>

    </div>

    <!-- COLUMNA LATERAL -->
    <div>
      <div class="card">
        <div class="card-title">Precio y pago</div>
        <div class="field">
          <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:14px;font-weight:600;">
            <input type="checkbox" name="es_gratuito" value="1" <?= ($evento['es_gratuito'] ?? 0)?'checked':'' ?>
                   onchange="document.getElementById('precio-wrap').style.display=this.checked?'none':''">
            Evento gratuito
          </label>
        </div>
        <div id="precio-wrap" <?= ($evento['es_gratuito'] ?? 0)?'style="display:none"':'' ?>>
          <div class="field">
            <label>Precio por persona (€)</label>
            <input type="number" name="precio" step="0.01" min="0"
                   value="<?= h(number_format((float)($evento['precio'] ?? 0), 2, '.', '')) ?>">
          </div>
        </div>
        <div class="field">
          <label>Métodos de pago activos</label>
          <?php
          $metodosActivos = array_filter(explode(',', $evento['metodos_pago'] ?? 'stripe'));
          foreach (['stripe'=>'💳 Stripe','redsys'=>'🏧 Redsys','bizum'=>'📱 Bizum','transferencia'=>'🏦 Transferencia'] as $k=>$v):
          ?>
          <label style="display:flex;align-items:center;gap:8px;font-size:14px;text-transform:none;font-weight:normal;margin-bottom:8px;">
            <input type="checkbox" name="metodos_pago[]" value="<?= $k ?>" <?= in_array($k,$metodosActivos)?'checked':'' ?>>
            <?= $v ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-title">Opciones</div>
        <div class="field">
          <label>Aforo máximo</label>
          <input type="number" name="max_inscritos" min="0" value="<?= h($evento['max_inscritos'] ?? '') ?>">
          <p class="hint">Deja en blanco para ilimitado.</p>
        </div>
        <div class="field">
          <label>Orden (menor = primero)</label>
          <input type="number" name="sort_order" value="<?= (int)($evento['sort_order'] ?? 0) ?>">
        </div>
        <div class="field">
          <label>Campo extra en validación QR</label>
          <input type="text" name="campo_qr_extra" value="<?= h($evento['campo_qr_extra'] ?? '') ?>"
                 placeholder="Nombre del campo personalizado">
          <p class="hint">El valor de este campo se mostrará en el escáner del portero.</p>
        </div>
        <div class="field">
          <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:14px;font-weight:600;margin-bottom:8px;">
            <input type="checkbox" name="activo" value="1" <?= ($evento['activo'] ?? 1)?'checked':'' ?>>
            Visible en la web
          </label>
          <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:14px;font-weight:600;">
            <input type="checkbox" name="archivado" value="1" <?= ($evento['archivado'] ?? 0)?'checked':'' ?>>
            Archivado
          </label>
        </div>
      </div>

      <div class="card">
        <div class="card-title">Imagen del evento</div>
        <?php if (!empty($evento['imagen']) && file_exists(__DIR__ . '/../../' . $evento['imagen'])): ?>
          <img src="../../<?= h($evento['imagen']) ?>" style="width:100%;border-radius:8px;margin-bottom:12px;">
        <?php endif; ?>
        <div class="field">
          <label>Subir imagen (JPG, PNG, WebP)</label>
          <input type="file" name="imagen" accept="image/*">
        </div>
      </div>

    </div>
  </div>
  </div><!-- /evento-tab-general -->

  <div class="evento-tab-panel" id="evento-tab-barra">
    <div class="card">
      <div class="card-title">Productos de barra</div>
      <p style="font-size:13px;color:#888;margin-bottom:16px;">Define las consumiciones (agua, cerveza, refrescos…) que se pueden vender online o en caja para este evento.</p>

      <div id="productos-container">
        <?php foreach ($productos as $pi => $producto): ?>
        <div class="producto-block" style="border:1px solid #e4e4e8;border-radius:10px;padding:16px;margin-bottom:12px;position:relative;">
          <div style="position:absolute;top:10px;right:10px;">
            <button type="button" onclick="this.closest('.producto-block').remove()" class="btn btn-sm btn-danger" style="padding:4px 10px;font-size:11px;">✕</button>
          </div>
          <input type="hidden" name="productos[<?= $pi ?>][id]" value="<?= (int)$producto['id'] ?>">
          <div class="field-row">
            <div class="field">
              <label>Nombre del producto</label>
              <input type="text" name="productos[<?= $pi ?>][nombre]" value="<?= h($producto['nombre']) ?>" required>
            </div>
            <div class="field">
              <label>Precio (€)</label>
              <input type="number" name="productos[<?= $pi ?>][precio]" step="0.01" min="0" value="<?= h(number_format((float)$producto['precio'], 2, '.', '')) ?>">
            </div>
            <div class="field">
              <label style="text-transform:none;font-size:13px;font-weight:normal;display:flex;align-items:center;gap:6px;">
                <input type="checkbox" name="productos[<?= $pi ?>][activo]" value="1" <?= $producto['activo']?'checked':'' ?>>
                Activo
              </label>
            </div>
            <div class="field">
              <label>Orden</label>
              <input type="number" name="productos[<?= $pi ?>][sort]" value="<?= (int)$producto['sort_order'] ?>" style="max-width:60px;">
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" onclick="addProducto()" class="btn btn-sm btn-outline">+ Añadir producto</button>
    </div>

    <div class="card">
      <div class="card-title">Métodos de pago para la barra</div>
      <p style="font-size:13px;color:#888;margin-bottom:14px;">Pueden ser distintos a los de las entradas. Se usan cuando alguien compra consumiciones sin entrada.</p>
      <?php
      $metodosBarraActivos = array_filter(explode(',', $evento['metodos_pago_barra'] ?? 'stripe'));
      foreach (['stripe'=>'💳 Stripe','redsys'=>'🏧 Redsys','bizum'=>'📱 Bizum','transferencia'=>'🏦 Transferencia'] as $k=>$v):
      ?>
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;text-transform:none;font-weight:normal;margin-bottom:8px;">
        <input type="checkbox" name="metodos_pago_barra[]" value="<?= $k ?>" <?= in_array($k,$metodosBarraActivos)?'checked':'' ?>>
        <?= $v ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div><!-- /evento-tab-barra -->

  <button type="submit" class="btn" style="width:100%;padding:14px;font-size:15px;margin-top:4px;">
    💾 Guardar evento
  </button>
</form>

<script src="<?= h($base) ?>/admin/assets/vendor/tinymce/tinymce.min.js"></script>
<script>
tinymce.init({
    selector: '#descripcion-editor',
    height: 280,
    menubar: false,
    plugins: 'lists link',
    toolbar: 'bold italic underline | bullist numlist | link | removeformat',
    branding: false,
    license_key: 'gpl'
});

function showEventoTab(id, btn) {
    document.querySelectorAll('.evento-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.evento-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('evento-tab-' + id).classList.add('active');
    btn.classList.add('active');
}

function addFecha() {
    var html = '<div class="fecha-block" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">'
        + '<input type="datetime-local" name="fechas[]" required style="flex:1;">'
        + '<button type="button" onclick="quitarFecha(this)" class="btn btn-sm btn-danger" style="padding:6px 10px;font-size:11px;">✕</button>'
        + '</div>';
    document.getElementById('fechas-container').insertAdjacentHTML('beforeend', html);
}
function quitarFecha(btn) {
    var cont = document.getElementById('fechas-container');
    if (cont.querySelectorAll('.fecha-block').length > 1) {
        btn.closest('.fecha-block').remove();
    }
}

var campoIdx = <?= count($campos) ?>;

function generarSlug(nombre) {
    var slugInput = document.getElementById('slug-input');
    if (slugInput.dataset.manual === '1') return;
    var s = nombre.toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
        .replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
    slugInput.value = s;
}
document.getElementById('slug-input').addEventListener('input', function() {
    this.dataset.manual = '1';
});

function toggleOpciones(sel) {
    var block = sel.closest('.campo-block');
    block.querySelector('.campo-opciones').style.display = sel.value === 'select' ? '' : 'none';
}

function addCampo() {
    var c = campoIdx++;
    var html = '<div class="campo-block" style="border:1px solid #e4e4e8;border-radius:10px;padding:16px;margin-bottom:12px;position:relative;">'
        + '<div style="position:absolute;top:10px;right:10px;"><button type="button" onclick="this.closest(\'.campo-block\').remove()" class="btn btn-sm btn-danger" style="padding:4px 10px;font-size:11px;">✕</button></div>'
        + '<input type="hidden" name="campos[' + c + '][id]" value="0">'
        + '<div class="field-row">'
        + '<div class="field"><label>Nombre interno</label><input type="text" name="campos[' + c + '][nombre]" required pattern="[a-zA-Z0-9_]+"></div>'
        + '<div class="field"><label>Etiqueta</label><input type="text" name="campos[' + c + '][label]" required></div>'
        + '<div class="field"><label>Tipo</label><select name="campos[' + c + '][tipo]" onchange="toggleOpciones(this)">'
        + ['text','email','tel','number','select','textarea','checkbox','date'].map(t => '<option value="' + t + '">' + t + '</option>').join('')
        + '</select></div></div>'
        + '<div class="campo-opciones" style="display:none"><div class="field"><label>Opciones (una por línea)</label><textarea name="campos[' + c + '][opciones]" rows="3"></textarea></div></div>'
        + '<div class="field-row">'
        + '<div class="field"><label style="text-transform:none;font-size:13px;font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="campos[' + c + '][obligatorio]" value="1"> Obligatorio</label></div>'
        + '<div class="field"><label style="text-transform:none;font-size:13px;font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="campos[' + c + '][para_titular]" value="1" checked> Para titular</label></div>'
        + '<div class="field"><label style="text-transform:none;font-size:13px;font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="campos[' + c + '][para_asistentes]" value="1" checked> Para asistentes</label></div>'
        + '<div class="field"><label>Orden</label><input type="number" name="campos[' + c + '][sort]" value="' + c + '" style="max-width:60px;"></div>'
        + '</div></div>';
    document.getElementById('campos-container').insertAdjacentHTML('beforeend', html);
}

var productoIdx = <?= count($productos) ?>;

function addProducto() {
    var p = productoIdx++;
    var html = '<div class="producto-block" style="border:1px solid #e4e4e8;border-radius:10px;padding:16px;margin-bottom:12px;position:relative;">'
        + '<div style="position:absolute;top:10px;right:10px;"><button type="button" onclick="this.closest(\'.producto-block\').remove()" class="btn btn-sm btn-danger" style="padding:4px 10px;font-size:11px;">✕</button></div>'
        + '<input type="hidden" name="productos[' + p + '][id]" value="0">'
        + '<div class="field-row">'
        + '<div class="field"><label>Nombre del producto</label><input type="text" name="productos[' + p + '][nombre]" required></div>'
        + '<div class="field"><label>Precio (€)</label><input type="number" name="productos[' + p + '][precio]" step="0.01" min="0" value="0.00"></div>'
        + '<div class="field"><label style="text-transform:none;font-size:13px;font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="productos[' + p + '][activo]" value="1" checked> Activo</label></div>'
        + '<div class="field"><label>Orden</label><input type="number" name="productos[' + p + '][sort]" value="' + p + '" style="max-width:60px;"></div>'
        + '</div></div>';
    document.getElementById('productos-container').insertAdjacentHTML('beforeend', html);
}
</script>

<?php require_once __DIR__ . '/../_footer.php'; ?>
