<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/RedsysAPI.php';

Auth::userCheck();

$inscripcionId = (int)($_GET['inscripcion'] ?? 0);
$stIns = db()->prepare('SELECT i.*, e.nombre as evento_nombre FROM inscripciones i JOIN eventos e ON e.id=i.evento_id WHERE i.id=? AND i.user_id=?');
$stIns->execute([$inscripcionId, Auth::userId()]);
$ins = $stIns->fetch();

if (!$ins || $ins['estado_pago'] !== 'pendiente') {
    redirect(baseUrl() . '/public/mi-cuenta.php');
}

$orderRef = RedsysAPI::generateOrderRef();
db()->prepare('UPDATE inscripciones SET redsys_order=? WHERE id=?')->execute([$orderRef, $inscripcionId]);

Auth::ensureSession();
$_SESSION['redsys_inscripcion_id'] = $inscripcionId;
$_SESSION['redsys_order'] = $orderRef;

$r = new RedsysAPI();
$r->setParameter('DS_MERCHANT_AMOUNT',           (string)(int)round((float)$ins['precio_total'] * 100));
$r->setParameter('DS_MERCHANT_ORDER',            $orderRef);
$r->setParameter('DS_MERCHANT_MERCHANTCODE',     getSetting('redsys_fuc'));
$r->setParameter('DS_MERCHANT_CURRENCY',         getSetting('redsys_currency', '978'));
$r->setParameter('DS_MERCHANT_TRANSACTIONTYPE',  '0');
$r->setParameter('DS_MERCHANT_TERMINAL',         getSetting('redsys_terminal', '1'));
$r->setParameter('DS_MERCHANT_MERCHANTURL',      baseUrl() . '/pago/redsys/notify.php');
$r->setParameter('DS_MERCHANT_URLOK',            baseUrl() . '/pago/redsys/ok.php');
$r->setParameter('DS_MERCHANT_URLKO',            baseUrl() . '/pago/redsys/ko.php');
$r->setParameter('DS_MERCHANT_CONSUMERLANGUAGE', '001');
$r->setParameter('DS_MERCHANT_PRODUCTDESCRIPTION', substr($ins['evento_nombre'], 0, 125));
$r->setParameter('DS_MERCHANT_TITULAR',          Auth::userName());

$params = $r->createMerchantParameters();
$sig    = $r->generateMerchantSignature(getSetting('redsys_secret_key'), $params, $orderRef);
$url    = getSetting('redsys_environment', 'test') === 'prod'
    ? 'https://sis.redsys.es/sis/realizarPago'
    : 'https://sis-t.redsys.es:25443/sis/realizarPago';
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Redirigiendo...</title>
<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f4f4f6;}
.box{text-align:center;}.spinner{width:36px;height:36px;border:3px solid #e0e0e0;border-top-color:#1a1a1a;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 16px;}
@keyframes spin{to{transform:rotate(360deg);}}</style>
</head><body>
<div class="box"><div class="spinner"></div><p>Redirigiendo al pago seguro...</p></div>
<form id="rf" method="POST" action="<?= h($url) ?>">
  <input type="hidden" name="Ds_SignatureVersion"   value="HMAC_SHA256_V1">
  <input type="hidden" name="Ds_MerchantParameters" value="<?= h($params) ?>">
  <input type="hidden" name="Ds_Signature"          value="<?= h($sig) ?>">
</form>
<script>document.getElementById('rf').submit();</script>
</body></html>
