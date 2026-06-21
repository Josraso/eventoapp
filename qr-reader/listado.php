<?php
// listado.php — API AJAX listado entradas para portero
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::ensureSession();

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode([]);
    exit;
}

$eventoId = (int)($_GET['evento_id'] ?? 0);
$eventosPortero = Auth::porteroEventos();
if (!$eventoId || !in_array($eventoId, $eventosPortero)) {
    echo json_encode([]);
    exit;
}

$st = db()->prepare("
    SELECT e.id, e.nombre_asistente, e.es_titular, e.usado, e.usado_at,
           i.numero_pedido, u.name as comprador_nombre
    FROM entradas e
    JOIN inscripciones i ON i.id = e.inscripcion_id
    JOIN users u ON u.id = i.user_id
    WHERE e.evento_id = ? AND i.estado_pago = 'pagado'
    ORDER BY e.usado ASC, e.nombre_asistente ASC
");
$st->execute([$eventoId]);
$rows = $st->fetchAll();

$result = array_map(fn($r) => [
    'id'        => (int)$r['id'],
    'nombre'    => $r['nombre_asistente'],
    'titular'   => (bool)$r['es_titular'],
    'usado'     => (bool)$r['usado'],
    'usado_at'  => $r['usado_at'] ? date('H:i', strtotime($r['usado_at'])) : '',
    'pedido'    => $r['numero_pedido'],
    'comprador' => $r['comprador_nombre'],
], $rows);

echo json_encode($result);
