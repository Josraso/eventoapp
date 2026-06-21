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
        $fecha_evento = $_POST['fecha_evento'] ?: null;
        $lugar       = trim($_POST['lugar'] ?? '');
        $lugar_url   = trim($_POST['lugar_url'] ?? '');
        $precio      = (float)str_replace(',', '.', $_POST['precio'] ?? '0');
        $es_gratuito = isset($_POST['es_gratuito']) ? 1 : 0;
        $max_inscritos = (int)($_POST['max_inscritos'] ?? 0) ?: null;
        $fecha_limite = $_POST['fecha_limite'] ?: null;
        $metodos     = array_filter($_POST['metodos_pago'] ?? ['stripe']);
        $metodos_str = implode(',', $metodos);
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
                db()->prepare('UPDATE eventos SET nombre=?,slug=?,descripcion=?,imagen=?,fecha_evento=?,lugar=?,lugar_url=?,precio=?,es_gratuito=?,max_inscritos=?,fecha_limite_inscripcion=?,metodos_pago=?,campo_qr_extra=?,activo=?,archivado=?,sort_order=?,admin_id=?,updated_at=NOW() WHERE id=?')
                   ->execute([$nombre,$slug,$descripcion,$imagen,$fecha_evento,$lugar,$lugar_url,$precio,$es_gratuito,$max_inscritos,$fecha_limite,$metodos_str,$campo_qr,$activo,$archivado,$sort_order,$admin_id_evento,$id]);
            } else {
                db()->prepare('INSERT INTO eventos (nombre,slug,descripcion,imagen,fecha_evento,lugar,lugar_url,precio,es_gratuito,max_inscritos,fecha_limite_inscripcion,metodos_pago,campo_qr_extra,activo,sort_order,admin_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$nombre,$slug,$descripcion,$imagen,$fecha_evento,$lugar,$lugar_url,$precio,$es_gratuito,$max_inscritos,$fecha_limite,$metodos_str,$campo_qr,$activo,$sort_order,$admin_id_evento]);
                $id = (int)db()->lastInsertId();
            }

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

<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
  <input type="hidden" name="action" value="guardar">

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
        <div class="field-row">
          <div class="field">
            <label>Fecha y hora del evento</label>
            <input type="datetime-local" name="fecha_evento"
                   value="<?= $evento['fecha_evento'] ? date('Y-m-d\TH:i', strtotime($evento['fecha_evento'])) : '' ?>">
          </div>
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

      <button type="submit" class="btn" style="width:100%;padding:14px;font-size:15px;">
        💾 Guardar evento
      </button>
    </div>
  </div>
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
</script>

<?php require_once __DIR__ . '/../_footer.php'; ?>
