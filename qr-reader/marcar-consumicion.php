<?php
// marcar-consumicion.php — Marcar/desmarcar consumición manualmente desde el listado (sin QR)
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::ensureSession();

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['ok' => false, 'error' => 'no_auth']);
    exit;
}

$consumicionId = (int)($_POST['consumicion_id'] ?? 0);
$accion        = $_POST['accion'] ?? 'marcar'; // marcar | desmarcar

$st = db()->prepare('SELECT * FROM consumiciones WHERE id=?');
$st->execute([$consumicionId]);
$consumicion = $st->fetch();

if (!$consumicion) {
    echo json_encode(['ok' => false, 'error' => 'no_encontrada']);
    exit;
}

// Verificar que el camarero tiene acceso a este evento
$eventosCamarero = Auth::camareroEventos();
if (!in_array($consumicion['evento_id'], $eventosCamarero)) {
    echo json_encode(['ok' => false, 'error' => 'sin_acceso']);
    exit;
}

if ($accion === 'desmarcar') {
    db()->prepare('UPDATE consumiciones SET usado=0, usado_at=NULL, usado_por=NULL WHERE id=?')
       ->execute([$consumicionId]);
} else {
    if (!$consumicion['usado']) {
        db()->prepare('UPDATE consumiciones SET usado=1, usado_at=NOW(), usado_por=? WHERE id=?')
           ->execute([Auth::adminId(), $consumicionId]);
        db()->prepare('INSERT INTO qr_logs (consumicion_id,evento_id,admin_id,resultado,ip) VALUES(?,?,?,?,?)')
           ->execute([$consumicionId, $consumicion['evento_id'], Auth::adminId(), 'ok', $_SERVER['REMOTE_ADDR'] ?? '']);
    }
}

$stStats = db()->prepare("SELECT COUNT(*) FROM consumiciones WHERE evento_id=? AND usado=1");
$stStats->execute([$consumicion['evento_id']]);
$usados = (int)$stStats->fetchColumn();
$stTotal = db()->prepare("SELECT COUNT(*) FROM consumiciones WHERE evento_id=?");
$stTotal->execute([$consumicion['evento_id']]);
$totalCons = (int)$stTotal->fetchColumn();

echo json_encode([
    'ok'    => true,
    'usado' => $accion !== 'desmarcar',
    'stats' => ['usados' => $usados, 'total' => $totalCons, 'pendientes' => $totalCons - $usados],
]);
