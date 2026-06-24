<?php
// redsys/notify.php — Notificación silenciosa de Redsys
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/RedsysAPI.php';
require_once __DIR__ . '/../../lib/TicketManager.php';
require_once __DIR__ . '/../../lib/Mailer.php';

function rlog($m) {
    $d = __DIR__ . '/../../logs';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    $f = $d . '/redsys.log';
    if (file_exists($f) && filesize($f) > 1048576) @rename($f, $f . '.' . date('Ymd_His') . '.bak');
    file_put_contents($f, date('[Y-m-d H:i:s] ') . $m . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$params = $_POST['Ds_MerchantParameters'] ?? '';
$sig    = $_POST['Ds_Signature'] ?? '';
if (!$params || !$sig) { rlog('Sin parámetros. IP:' . ($_SERVER['REMOTE_ADDR'] ?? '')); http_response_code(400); exit('KO'); }

$r    = new RedsysAPI();
$data = $r->getDecodedMerchantParameters($params);
$ord  = $data['DS_ORDER'] ?? $data['DS_MERCHANT_ORDER'] ?? '';
$resp = $data['DS_RESPONSE'] ?? '9999';
$auth = $data['DS_AUTHORISATIONCODE'] ?? '';

rlog("Notif Order:$ord Resp:$resp");
if (!$r->validateResponse(getSetting('redsys_secret_key'), $params, $sig, $ord)) {
    rlog("Firma inválida Order:$ord"); http_response_code(400); exit('KO');
}

$stIns = db()->prepare('SELECT * FROM inscripciones WHERE redsys_order=?');
$stIns->execute([$ord]);
$ins = $stIns->fetch();
if (!$ins) { rlog("No encontrada Order:$ord"); http_response_code(200); exit('OK'); }
if ($ins['estado_pago'] !== 'fallido') { rlog("Ya procesada $ord"); http_response_code(200); exit('OK'); }

if (RedsysAPI::isResponseOk($resp)) {
    db()->prepare("UPDATE inscripciones SET estado_pago='pagado', redsys_auth=?, confirmado_at=NOW() WHERE id=?")
       ->execute([$auth, $ins['id']]);
    // Generar PDFs y enviar email
    $paths = TicketManager::generarPDFsInscripcion($ins['id']);
    $stUser = db()->prepare('SELECT * FROM users WHERE id=?');
    $stUser->execute([$ins['user_id']]);
    $user = $stUser->fetch();
    $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
    $stEv->execute([$ins['evento_id']]);
    $evento = $stEv->fetch();
    $atts = array_map(fn($p) => ['path' => $p], $paths);
    $m = new Mailer();
    $res = $m->send($user['email'], $user['name'], 'Confirmación de tu pedido de ' . mb_strtolower(etiquetaPedido($ins['id'])) . ' — ' . $evento['nombre'], Mailer::tplEntradas($ins, $evento, $user), $atts);
    if ($res['ok']) db()->prepare('UPDATE inscripciones SET email_entradas_enviado=1 WHERE id=?')->execute([$ins['id']]);
    rlog("$ord -> OK, email: " . ($res['ok'] ? 'OK' : 'ERR:' . $res['error']));
} else {
    db()->prepare("UPDATE inscripciones SET estado_pago='fallido' WHERE id=?")->execute([$ins['id']]);
    rlog("$ord -> FALLIDO resp:$resp");
}

http_response_code(200); echo 'OK';
