<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Auth::adminCheck('superadmin', 'admin');

$eventoFiltro = (int)($_GET['evento_id'] ?? 0);
$estadoFiltro = $_GET['estado'] ?? '';
$q            = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if (Auth::adminRole() !== 'superadmin') { $where[] = 'e.admin_id=?'; $params[] = Auth::adminId(); }
if ($eventoFiltro) { $where[] = 'i.evento_id=?'; $params[] = $eventoFiltro; }
if ($estadoFiltro) {
    $where[] = 'i.estado_pago=?'; $params[] = $estadoFiltro;
} else {
    $where[] = "i.estado_pago='pagado'";
}
if ($q) {
    $qlike = '%'.$q.'%';
    $where[] = '(i.numero_pedido LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR EXISTS(SELECT 1 FROM entradas en WHERE en.inscripcion_id=i.id AND en.nombre_asistente LIKE ?))';
    array_push($params, $qlike, $qlike, $qlike, $qlike);
}
$whereStr = implode(' AND ', $where);

$campos = [];
if ($eventoFiltro) {
    $stC = db()->prepare('SELECT * FROM evento_campos WHERE evento_id=? ORDER BY sort_order');
    $stC->execute([$eventoFiltro]);
    $campos = $stC->fetchAll();
}

$stIns = db()->prepare("
    SELECT i.*, e.nombre as evento_nombre, e.fecha_evento, e.lugar,
           u.name as user_name, u.email as user_email, u.phone as user_phone
    FROM inscripciones i
    JOIN eventos e ON e.id=i.evento_id
    JOIN users u ON u.id=i.user_id
    WHERE $whereStr
    ORDER BY i.created_at DESC
");
$stIns->execute($params);
$inscripciones = $stIns->fetchAll();

$fname = 'inscritos';
if ($eventoFiltro) {
    $stEv = db()->prepare('SELECT nombre FROM eventos WHERE id=?');
    $stEv->execute([$eventoFiltro]);
    $evNombre = preg_replace('/[^a-z0-9]/i', '_', $stEv->fetchColumn());
    $fname .= '_' . $evNombre;
}
$fname .= '_' . date('Ymd_His') . '.xlsx';

$headers = ['Pedido','Evento','Fecha evento','Titular nombre','Titular email','Titular teléfono',
            'Nombre asistente','Número asistente','Es titular',
            'Método pago','Estado','Total (€)','Confirmado','Fecha inscripción'];
foreach ($campos as $c) { $headers[] = $c['label']; }
$headers[] = 'QR validado';
$headers[] = 'QR validado fecha';
$headers[] = 'Notas admin';

$sheet = new Spreadsheet();
$ws = $sheet->getActiveSheet();
$ws->setTitle('Inscritos');
$ws->fromArray($headers, null, 'A1');
$lastCol = $ws->getCellByColumnAndRow(count($headers), 1)->getColumn();
$ws->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

$row = 2;
foreach ($inscripciones as $ins) {
    $stEnt = db()->prepare('SELECT * FROM entradas WHERE inscripcion_id=? ORDER BY es_titular DESC, id ASC');
    $stEnt->execute([$ins['id']]);
    $entradas = $stEnt->fetchAll();
    $numAsi = 0;
    foreach ($entradas as $ent) {
        $numAsi++;
        $extras = !empty($ent['campos_extra']) ? (is_string($ent['campos_extra']) ? json_decode($ent['campos_extra'], true) : $ent['campos_extra']) : [];
        $data = [
            $ins['numero_pedido'],
            $ins['evento_nombre'],
            $ins['fecha_evento'] ? date('d/m/Y H:i', strtotime($ins['fecha_evento'])) : '',
            $ins['user_name'],
            $ins['user_email'],
            $ins['user_phone'] ?? '',
            $ent['nombre_asistente'],
            $numAsi,
            $ent['es_titular'] ? 'Sí' : 'No',
            $ins['metodo_pago'],
            $ins['estado_pago'],
            number_format((float)$ins['precio_total'], 2, ',', '.'),
            $ins['confirmado_at'] ? date('d/m/Y H:i', strtotime($ins['confirmado_at'])) : '',
            date('d/m/Y H:i', strtotime($ins['created_at'])),
        ];
        foreach ($campos as $c) { $data[] = $extras[$c['nombre']] ?? ''; }
        $data[] = $ent['usado'] ? 'Sí' : 'No';
        $data[] = $ent['usado_at'] ? date('d/m/Y H:i', strtotime($ent['usado_at'])) : '';
        $data[] = $ins['notas_admin'] ?? '';
        $ws->fromArray($data, null, 'A' . $row);
        $row++;
    }
}

foreach (range('A', $ws->getHighestColumn()) as $col) {
    $ws->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($sheet);
$writer->save('php://output');
exit;
