<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin','admin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');
$esSuperAdmin = Auth::adminRole() === 'superadmin';

$error = '';

// Un camarero es "propio" de un admin si lo creó él, o si está asignado a al
// menos un evento que ese admin posee (visibilidad compartida entre admins
// cuando el mismo camarero trabaja eventos de varios organizadores).
function camareroEsPropio(int $cid): bool
{
    if (Auth::adminRole() === 'superadmin') return true;
    $st = db()->prepare("SELECT COUNT(*) FROM admin_users c
        WHERE c.id=? AND c.role='camarero'
        AND (c.creado_por=? OR EXISTS(
            SELECT 1 FROM portero_eventos pe JOIN eventos e ON e.id=pe.evento_id
            WHERE pe.admin_id=c.id AND e.admin_id=?
        ))");
    $st->execute([$cid, Auth::adminId(), Auth::adminId()]);
    return (int)$st->fetchColumn() > 0;
}

// Eventos asignables: solo los propios para un admin normal, todos para superadmin
function eventosAsignablesCamarero(): array
{
    if (Auth::adminRole() === 'superadmin') {
        return db()->query("SELECT id, nombre FROM eventos WHERE activo=1 AND archivado=0 ORDER BY nombre")->fetchAll();
    }
    $st = db()->prepare("SELECT id, nombre FROM eventos WHERE activo=1 AND archivado=0 AND admin_id=? ORDER BY nombre");
    $st->execute([Auth::adminId()]);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $username = trim($_POST['username'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pass     = $_POST['password'] ?? '';
        $eidsRaw  = array_map('intval', $_POST['eventos'] ?? []);
        $eidsOk   = array_column(eventosAsignablesCamarero(), 'id');
        $eids     = array_intersect($eidsRaw, $eidsOk);
        if (!$username || !$pass) { $error = 'Usuario y contraseña son obligatorios.'; }
        else {
            $chk = db()->prepare('SELECT id FROM admin_users WHERE username=?');
            $chk->execute([$username]);
            if ($chk->fetch()) { $error = 'Ese usuario ya existe.'; }
            else {
                db()->prepare('INSERT INTO admin_users (username,name,email,password_hash,role,creado_por) VALUES(?,?,?,?,?,?)')
                   ->execute([$username,$name,$email,password_hash($pass,PASSWORD_BCRYPT),'camarero',Auth::adminId()]);
                $cid = (int)db()->lastInsertId();
                foreach ($eids as $eid) {
                    db()->prepare('INSERT IGNORE INTO portero_eventos (admin_id,evento_id) VALUES(?,?)')->execute([$cid,$eid]);
                }
                flash('ok','Camarero creado.');
                header('Location: ' . $base . '/admin/camareros/index.php'); exit;
            }
        }
    }
    if ($action === 'toggle_activo') {
        $cid = (int)$_POST['cid'];
        if (camareroEsPropio($cid)) {
            db()->prepare('UPDATE admin_users SET active=NOT active WHERE id=? AND role="camarero"')->execute([$cid]);
            flash('ok','Estado actualizado.');
        } else {
            flash('error','No tienes permiso sobre este camarero.');
        }
        header('Location: ' . $base . '/admin/camareros/index.php'); exit;
    }
    if ($action === 'eliminar') {
        $cid = (int)$_POST['cid'];
        if (camareroEsPropio($cid)) {
            db()->prepare('DELETE FROM admin_users WHERE id=? AND role="camarero"')->execute([$cid]);
            flash('ok','Camarero eliminado.');
        } else {
            flash('error','No tienes permiso sobre este camarero.');
        }
        header('Location: ' . $base . '/admin/camareros/index.php'); exit;
    }
    if ($action === 'asignar_eventos') {
        $cid = (int)$_POST['cid'];
        if (!camareroEsPropio($cid)) {
            flash('error','No tienes permiso sobre este camarero.');
            header('Location: ' . $base . '/admin/camareros/index.php'); exit;
        }
        $eidsRaw = array_map('intval', $_POST['eventos'] ?? []);
        $eidsOk  = array_column(eventosAsignablesCamarero(), 'id');
        $eids    = array_intersect($eidsRaw, $eidsOk);
        // Solo tocamos las asignaciones de MIS eventos: si el camarero también
        // está asignado a eventos de otro admin, esas filas no se borran.
        if ($eidsOk) {
            $in = implode(',', array_fill(0, count($eidsOk), '?'));
            db()->prepare("DELETE FROM portero_eventos WHERE admin_id=? AND evento_id IN ($in)")
               ->execute(array_merge([$cid], $eidsOk));
        }
        foreach ($eids as $eid) {
            db()->prepare('INSERT IGNORE INTO portero_eventos (admin_id,evento_id) VALUES(?,?)')->execute([$cid,$eid]);
        }
        flash('ok','Asignación actualizada.');
        header('Location: ' . $base . '/admin/camareros/index.php'); exit;
    }
}

$camareros = $esSuperAdmin
    ? db()->query("SELECT c.*, GROUP_CONCAT(e.nombre ORDER BY e.nombre SEPARATOR ', ') as eventos_asignados
        FROM admin_users c
        LEFT JOIN portero_eventos pe ON pe.admin_id=c.id
        LEFT JOIN eventos e ON e.id=pe.evento_id
        WHERE c.role='camarero'
        GROUP BY c.id ORDER BY c.username")->fetchAll()
    : (function() {
        // El nombre de los eventos solo se muestra si son míos; si el camarero
        // también está en eventos de otro admin, se indica el conteo sin revelar
        // de qué evento se trata (es información del otro organizador).
        $st = db()->prepare("SELECT c.*,
                GROUP_CONCAT(DISTINCT CASE WHEN e.admin_id=? THEN e.nombre END ORDER BY e.nombre SEPARATOR ', ') as eventos_asignados,
                COUNT(DISTINCT CASE WHEN e.admin_id IS NOT NULL AND e.admin_id!=? THEN e.id END) as otros_eventos
            FROM admin_users c
            LEFT JOIN portero_eventos pe ON pe.admin_id=c.id
            LEFT JOIN eventos e ON e.id=pe.evento_id
            WHERE c.role='camarero' AND (c.creado_por=? OR EXISTS(
                SELECT 1 FROM portero_eventos pe2 JOIN eventos e2 ON e2.id=pe2.evento_id
                WHERE pe2.admin_id=c.id AND e2.admin_id=?
            ))
            GROUP BY c.id ORDER BY c.username");
        $st->execute([Auth::adminId(), Auth::adminId(), Auth::adminId(), Auth::adminId()]);
        return $st->fetchAll();
    })();

$eventos = eventosAsignablesCamarero();
$cidEditar = (int)($_GET['editar'] ?? 0);

$pageTitle = 'Camareros';
require_once __DIR__ . '/../_header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="alert alert-info" style="font-size:13px;">
  📲 Los camareros deben entrar desde esta URL para vender y validar consumiciones: <strong><?= h($base) ?>/qr-reader/barra.php</strong>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">
  <div>
    <div class="card" style="padding:0;">
      <div class="table-wrap">
        <table class="admin">
          <thead><tr><th>Usuario</th><th>Nombre</th><th>Eventos asignados</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($camareros)): ?>
              <tr><td colspan="5" style="text-align:center;color:#aaa;padding:30px;">Sin camareros aún.</td></tr>
            <?php else: ?>
              <?php foreach ($camareros as $c):
                $evAsig = db()->prepare('SELECT evento_id FROM portero_eventos WHERE admin_id=?');
                $evAsig->execute([$c['id']]);
                $evAsigIds = $evAsig->fetchAll(PDO::FETCH_COLUMN);
              ?>
              <tr>
                <td><strong><?= h($c['username']) ?></strong></td>
                <td><?= h($c['name'] ?: '—') ?></td>
                <td style="font-size:12px;color:#888;max-width:180px;">
                  <?= h($c['eventos_asignados'] ?: 'Ninguno') ?>
                  <?php if (!$esSuperAdmin && !empty($c['otros_eventos'])): ?>
                  <br><span style="color:#aaa;">+ <?= (int)$c['otros_eventos'] ?> evento(s) de otro organizador</span>
                  <?php endif; ?>
                </td>
                <td><?= $c['active']?'<span class="badge badge-green">Activo</span>':'<span class="badge badge-red">Inactivo</span>' ?></td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="<?= h($base) ?>/admin/camareros/index.php?editar=<?= $c['id'] ?>" class="btn btn-sm btn-outline">✏️ Editar</a>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                      <input type="hidden" name="action" value="toggle_activo">
                      <input type="hidden" name="cid" value="<?= $c['id'] ?>">
                      <button class="btn btn-sm btn-outline"><?= $c['active']?'Desactivar':'Activar' ?></button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este camarero?')">
                      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                      <input type="hidden" name="action" value="eliminar">
                      <input type="hidden" name="cid" value="<?= $c['id'] ?>">
                      <button class="btn btn-sm btn-danger">🗑</button>
                    </form>
                  </div>
                  <?php if ($cidEditar === (int)$c['id']): ?>
                  <form method="POST" style="margin-top:12px;background:#f8f8f8;border-radius:8px;padding:14px;">
                    <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="asignar_eventos">
                    <input type="hidden" name="cid" value="<?= $c['id'] ?>">
                    <div style="font-size:12px;font-weight:700;color:#888;margin-bottom:10px;">Eventos asignados:</div>
                    <?php foreach ($eventos as $ev): ?>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:6px;">
                      <input type="checkbox" name="eventos[]" value="<?= $ev['id'] ?>" <?= in_array($ev['id'],$evAsigIds)?'checked':'' ?>>
                      <?= h($ev['nombre']) ?>
                    </label>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-sm" style="margin-top:10px;">Guardar asignación</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Nuevo camarero</div>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
      <input type="hidden" name="action" value="crear">
      <div class="field"><label>Usuario *</label><input type="text" name="username" required></div>
      <div class="field"><label>Nombre</label><input type="text" name="name"></div>
      <div class="field"><label>Email</label><input type="email" name="email"></div>
      <div class="field"><label>Contraseña *</label><input type="password" name="password" required minlength="6"></div>
      <div class="field">
        <label>Eventos asignados</label>
        <?php foreach ($eventos as $ev): ?>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:6px;">
          <input type="checkbox" name="eventos[]" value="<?= $ev['id'] ?>">
          <?= h($ev['nombre']) ?>
        </label>
        <?php endforeach; ?>
        <?php if (empty($eventos)): ?><p style="font-size:12px;color:#aaa;">Sin eventos activos.</p><?php endif; ?>
      </div>
      <button type="submit" class="btn btn-full">Crear camarero</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../_footer.php'; ?>
