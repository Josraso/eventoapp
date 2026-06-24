<?php
// listado-consumicion.php — API AJAX listado consumiciones para camarero
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::ensureSession();

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode([]);
    exit;
}

$eventoId = (int)($_GET['evento_id'] ?? 0);
$eventosCamarero = Auth::camareroEventos();
if (!$eventoId || !in_array($eventoId, $eventosCamarero)) {
    echo json_encode([]);
    exit;
}

$st = db()->prepare("
    SELECT c.id, c.usado, c.usado_at, c.origen, c.nombre_comprador, c.codigo_corto,
           p.nombre as producto_nombre, i.numero_pedido
    FROM consumiciones c
    JOIN productos_consumicion p ON p.id = c.producto_id
    LEFT JOIN inscripciones i ON i.id = c.inscripcion_id
    WHERE c.evento_id = ?
    ORDER BY c.usado ASC, p.nombre ASC
");
$st->execute([$eventoId]);
$rows = $st->fetchAll();

$result = array_map(fn($r) => [
    'id'        => (int)$r['id'],
    'nombre'    => $r['producto_nombre'],
    'usado'     => (bool)$r['usado'],
    'usado_at'  => $r['usado_at'] ? date('H:i', strtotime($r['usado_at'])) : '',
    'pedido'    => $r['numero_pedido'] ?: ($r['nombre_comprador'] ?: 'Venta en caja'),
    'comprador' => $r['nombre_comprador'],
    'codigo'    => $r['codigo_corto'],
], $rows);

echo json_encode($result);
