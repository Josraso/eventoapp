<?php
// marcar.php — Marcar/desmarcar entrada manualmente desde el listado (sin QR)
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::ensureSession();

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['ok' => false, 'error' => 'no_auth']);
    exit;
}

$entradaId = (int)($_POST['entrada_id'] ?? 0);
$accion    = $_POST['accion'] ?? 'marcar'; // marcar | desmarcar

$st = db()->prepare("
    SELECT e.*, i.estado_pago
    FROM entradas e
    JOIN inscripciones i ON i.id = e.inscripcion_id
    WHERE e.id = ?
");
$st->execute([$entradaId]);
$entrada = $st->fetch();

if (!$entrada || $entrada['estado_pago'] !== 'pagado') {
    echo json_encode(['ok' => false, 'error' => 'no_encontrada']);
    exit;
}

// Verificar que el portero tiene acceso a este evento
$eventosPortero = Auth::porteroEventos();
if (!in_array($entrada['evento_id'], $eventosPortero)) {
    echo json_encode(['ok' => false, 'error' => 'sin_acceso']);
    exit;
}

if ($accion === 'desmarcar') {
    db()->prepare('UPDATE entradas SET usado=0, usado_at=NULL, usado_por=NULL WHERE id=?')
       ->execute([$entradaId]);
} else {
    if (!$entrada['usado']) {
        db()->prepare('UPDATE entradas SET usado=1, usado_at=NOW(), usado_por=? WHERE id=?')
           ->execute([Auth::adminId(), $entradaId]);
        db()->prepare('INSERT INTO qr_logs (entrada_id,evento_id,admin_id,resultado,ip) VALUES(?,?,?,?,?)')
           ->execute([$entradaId, $entrada['evento_id'], Auth::adminId(), 'ok', $_SERVER['REMOTE_ADDR'] ?? '']);
    }
}

$stStats = db()->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id=? AND usado=1");
$stStats->execute([$entrada['evento_id']]);
$usados = (int)$stStats->fetchColumn();
$stTotal = db()->prepare("SELECT COUNT(*) FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE e.evento_id=? AND i.estado_pago='pagado'");
$stTotal->execute([$entrada['evento_id']]);
$totalEnt = (int)$stTotal->fetchColumn();

echo json_encode([
    'ok'    => true,
    'usado' => $accion !== 'desmarcar',
    'stats' => ['usados' => $usados, 'total' => $totalEnt, 'pendientes' => $totalEnt - $usados],
]);
