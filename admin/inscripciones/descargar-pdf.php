<?php
if (!defined('ADMIN_GUARD')) define('ADMIN_GUARD', true);

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/TicketManager.php';

Auth::adminCheck('superadmin', 'admin');

$id = (int)($_GET['id'] ?? 0);
$stIns = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
$stIns->execute([$id]);
$ins = $stIns->fetch();

if (!$ins || $ins['estado_pago'] !== 'pagado') {
    http_response_code(404);
    die('Pedido no encontrado.');
}

if (!$ins['pdf_path'] || !file_exists(__DIR__ . '/../../' . $ins['pdf_path'])) {
    TicketManager::generarPDFsInscripcion($id);
    $stIns->execute([$id]);
    $ins = $stIns->fetch();
}
$pdfContent = file_get_contents(__DIR__ . '/../../' . $ins['pdf_path']);

$nombre = preg_replace('/[^a-z0-9]/i', '_', $ins['numero_pedido']);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="pedido_' . $nombre . '.pdf"');
header('Content-Length: ' . strlen($pdfContent));
echo $pdfContent;
exit;
