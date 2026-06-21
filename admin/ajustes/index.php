<?php
$pageTitle = 'Ajustes';
require_once __DIR__ . '/../_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    Auth::adminCheck('superadmin');

    $campos = [
        'site_name','site_footer','app_base_url','mail_method','mail_from_email','mail_from_name',
        'mail_footer','smtp_host','smtp_port','smtp_user','smtp_pass','smtp_secure',
        'stripe_public_key','stripe_secret_key',
        'redsys_fuc','redsys_terminal','redsys_secret_key','redsys_currency','redsys_environment',
        'bizum_telefono','transferencia_iban','transferencia_banco',
        'recaptcha_sitekey','recaptcha_secret',
    ];
    foreach ($campos as $k) {
        if (isset($_POST[$k])) setSetting($k, trim($_POST[$k]));
    }

    // Subir logo
    if (!empty($_FILES['logo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','gif','webp','svg'])) {
            $dir = __DIR__ . '/../../storage/';
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            $fname = 'logo.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dir . $fname)) {
                setSetting('logo_path', 'storage/' . $fname);
            }
        }
    }

    Auth::logAction('ajustes_guardar','Ajustes actualizados');
    flash('ok','Ajustes guardados correctamente.');
    header('Location: ' . $base . '/admin/ajustes/index.php'); exit;
}

$s = fn($k,$d='') => h(getSetting($k,$d));
$isSuperAdmin = (Auth::adminRole() === 'superadmin');

// Test SMTP
$smtpTestResult = '';
if (isset($_GET['test_smtp']) && $isSuperAdmin) {
    require_once __DIR__ . '/../../lib/Mailer.php';
    $m = new Mailer();
    $res = $m->send(getSetting('mail_from_email'), 'Test', 'Test SMTP — ' . getSetting('site_name'), '<p>Este es un email de prueba. Si lo recibes, el SMTP está correctamente configurado.</p>');
    $smtpTestResult = $res['ok'] ? '<div class="alert alert-success">✓ Email de prueba enviado a ' . getSetting('mail_from_email') . '</div>' : '<div class="alert alert-error">Error: ' . h($res['error']) . '</div>';
}
?>

<?= $smtpTestResult ?>

<?php if (!$isSuperAdmin): ?>
<div class="alert alert-info">Solo los superadmins pueden modificar los ajustes.</div>
<?php else: ?>

<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;">

    <!-- COL 1 -->
    <div>
      <div class="card">
        <div class="card-title">General</div>
        <div class="field"><label>Nombre del sitio</label><input type="text" name="site_name" value="<?= $s('site_name','Eventos') ?>"></div>
        <div class="field"><label>URL base (con https://)</label><input type="url" name="app_base_url" value="<?= $s('app_base_url') ?>" placeholder="https://tudominio.com"></div>
        <div class="field"><label>Pie de página (emails)</label><textarea name="mail_footer" rows="3"><?= $s('mail_footer') ?></textarea></div>
        <div class="field"><label>Pie de página web</label><textarea name="site_footer" rows="2"><?= $s('site_footer') ?></textarea></div>
        <div class="field">
          <label>Logo del sitio</label>
          <?php $logo = getSetting('logo_path'); if ($logo && file_exists(__DIR__.'/../../'.$logo)): ?>
            <img src="../../<?= h($logo) ?>" style="max-height:50px;margin-bottom:8px;display:block;">
          <?php endif; ?>
          <input type="file" name="logo" accept="image/*">
        </div>
      </div>

      <div class="card">
        <div class="card-title">Email</div>
        <div class="field">
          <label>Método de envío</label>
          <select name="mail_method">
            <option value="sendmail" <?= getSetting('mail_method')==='sendmail'?'selected':'' ?>>Sendmail (servidor)</option>
            <option value="smtp" <?= getSetting('mail_method')==='smtp'?'selected':'' ?>>SMTP</option>
          </select>
        </div>
        <div class="field-row">
          <div class="field"><label>Email remitente</label><input type="email" name="mail_from_email" value="<?= $s('mail_from_email') ?>"></div>
          <div class="field"><label>Nombre remitente</label><input type="text" name="mail_from_name" value="<?= $s('mail_from_name') ?>"></div>
        </div>
        <div class="field"><label>Host SMTP</label><input type="text" name="smtp_host" value="<?= $s('smtp_host') ?>" placeholder="smtp.gmail.com"></div>
        <div class="field-row">
          <div class="field"><label>Puerto SMTP</label><input type="number" name="smtp_port" value="<?= $s('smtp_port','587') ?>"></div>
          <div class="field"><label>Seguridad</label>
            <select name="smtp_secure">
              <option value="tls" <?= getSetting('smtp_secure')==='tls'?'selected':'' ?>>TLS (587)</option>
              <option value="ssl" <?= getSetting('smtp_secure')==='ssl'?'selected':'' ?>>SSL (465)</option>
            </select>
          </div>
        </div>
        <div class="field-row">
          <div class="field"><label>Usuario SMTP</label><input type="text" name="smtp_user" value="<?= $s('smtp_user') ?>"></div>
          <div class="field"><label>Contraseña SMTP</label><input type="password" name="smtp_pass" value="<?= $s('smtp_pass') ?>" autocomplete="new-password"></div>
        </div>
        <a href="?test_smtp=1" class="btn btn-sm btn-outline">📧 Enviar email de prueba</a>
      </div>

      <div class="card">
        <div class="card-title">reCAPTCHA v2</div>
        <p style="font-size:13px;color:#888;margin-bottom:12px;">Opcional. Protege los formularios de registro. Obtén las claves en <a href="https://www.google.com/recaptcha" target="_blank">google.com/recaptcha</a>.</p>
        <div class="field"><label>Site Key (pública)</label><input type="text" name="recaptcha_sitekey" value="<?= $s('recaptcha_sitekey') ?>"></div>
        <div class="field"><label>Secret Key (privada)</label><input type="password" name="recaptcha_secret" value="<?= $s('recaptcha_secret') ?>" autocomplete="new-password"></div>
      </div>
    </div>

    <!-- COL 2 -->
    <div>
      <div class="card">
        <div class="card-title">Stripe</div>
        <div class="field"><label>Clave pública (pk_...)</label><input type="text" name="stripe_public_key" value="<?= $s('stripe_public_key') ?>"></div>
        <div class="field"><label>Clave secreta (sk_...)</label><input type="password" name="stripe_secret_key" value="<?= $s('stripe_secret_key') ?>" autocomplete="new-password"></div>
      </div>

      <div class="card">
        <div class="card-title">Redsys / TPV</div>
        <div class="field-row">
          <div class="field"><label>FUC (Código comercio)</label><input type="text" name="redsys_fuc" value="<?= $s('redsys_fuc') ?>"></div>
          <div class="field"><label>Terminal</label><input type="text" name="redsys_terminal" value="<?= $s('redsys_terminal','1') ?>"></div>
        </div>
        <div class="field"><label>Clave secreta SHA-256</label><input type="password" name="redsys_secret_key" value="<?= $s('redsys_secret_key') ?>" autocomplete="new-password"></div>
        <div class="field-row">
          <div class="field"><label>Moneda</label><input type="text" name="redsys_currency" value="<?= $s('redsys_currency','978') ?>"><p class="hint">978 = EUR</p></div>
          <div class="field"><label>Entorno</label>
            <select name="redsys_environment">
              <option value="test" <?= getSetting('redsys_environment')==='test'?'selected':'' ?>>Test</option>
              <option value="prod" <?= getSetting('redsys_environment')==='prod'?'selected':'' ?>>Producción</option>
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-title">Bizum (pago manual)</div>
        <div class="field"><label>Teléfono Bizum del organizador</label><input type="tel" name="bizum_telefono" value="<?= $s('bizum_telefono') ?>" placeholder="+34 600 000 000"></div>
      </div>

      <div class="card">
        <div class="card-title">Transferencia bancaria</div>
        <div class="field"><label>IBAN</label><input type="text" name="transferencia_iban" value="<?= $s('transferencia_iban') ?>" placeholder="ES00 0000 0000 0000 0000 0000"></div>
        <div class="field"><label>Banco / titular</label><input type="text" name="transferencia_banco" value="<?= $s('transferencia_banco') ?>" placeholder="Banco XYZ - Nombre Titular"></div>
      </div>

      <button type="submit" class="btn" style="width:100%;padding:14px;font-size:15px;">💾 Guardar ajustes</button>
    </div>
  </div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../_footer.php'; ?>
