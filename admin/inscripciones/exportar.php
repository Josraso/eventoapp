<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');

$eventoFiltro = (int)($_GET['evento_id'] ?? 0);
$estadoFiltro = $_GET['estado'] ?? '';
$q            = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($eventoFiltro) { $where[] = 'i.evento_id=?'; $params[] = $eventoFiltro; }
if ($estadoFiltro) { $where[] = 'i.estado_pago=?'; $params[] = $estadoFiltro; }
if ($q) {
    $qlike = '%'.$q.'%';
    $where[] = '(i.numero_pedido LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR EXISTS(SELECT 1 FROM entradas en WHERE en.inscripcion_id=i.id AND en.nombre_asistente LIKE ?))';
    array_push($params, $qlike, $qlike, $qlike, $qlike);
}
$whereStr = implode(' AND ', $where);

// Cargamos campos personalizados del evento filtrado
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

// Nombre del fichero
$fname = 'inscripciones';
if ($eventoFiltro) {
    $stEv = db()->prepare('SELECT nombre FROM eventos WHERE id=?');
    $stEv->execute([$eventoFiltro]);
    $evNombre = preg_replace('/[^a-z0-9]/i','_', $stEv->fetchColumn());
    $fname .= '_' . $evNombre;
}
$fname .= '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');
// BOM para Excel
fwrite($out, "\xEF\xBB\xBF");

// Cabeceras
$headers = ['Pedido','Evento','Fecha evento','Titular nombre','Titular email','Titular teléfono',
            'Nombre asistente','Número asistente','Es titular',
            'Método pago','Estado','Total (€)','Confirmado','Fecha inscripción'];
foreach ($campos as $c) { $headers[] = $c['label']; }
$headers[] = 'QR validado';
$headers[] = 'QR validado fecha';
$headers[] = 'Notas admin';
fputcsv($out, $headers, ';');

// Una fila por entrada (asistente)
foreach ($inscripciones as $ins) {
    $stEnt = db()->prepare('SELECT * FROM entradas WHERE inscripcion_id=? ORDER BY es_titular DESC, id ASC');
    $stEnt->execute([$ins['id']]);
    $entradas = $stEnt->fetchAll();
    $numAsi = 0;
    foreach ($entradas as $ent) {
        $numAsi++;
        $extras = !empty($ent['campos_extra']) ? (is_string($ent['campos_extra']) ? json_decode($ent['campos_extra'],true) : $ent['campos_extra']) : [];
        $row = [
            $ins['numero_pedido'],
            $ins['evento_nombre'],
            $ins['fecha_evento'] ? date('d/m/Y H:i',strtotime($ins['fecha_evento'])) : '',
            $ins['user_name'],
            $ins['user_email'],
            $ins['user_phone'] ?? '',
            $ent['nombre_asistente'],
            $numAsi,
            $ent['es_titular'] ? 'Sí' : 'No',
            $ins['metodo_pago'],
            $ins['estado_pago'],
            number_format((float)$ins['precio_total'],2,',','.'),
            $ins['confirmado_at'] ? date('d/m/Y H:i',strtotime($ins['confirmado_at'])) : '',
            date('d/m/Y H:i',strtotime($ins['created_at'])),
        ];
        foreach ($campos as $c) { $row[] = $extras[$c['nombre']] ?? ''; }
        $row[] = $ent['usado'] ? 'Sí' : 'No';
        $row[] = $ent['usado_at'] ? date('d/m/Y H:i',strtotime($ent['usado_at'])) : '';
        $row[] = $ins['notas_admin'] ?? '';
        fputcsv($out, $row, ';');
    }
}
fclose($out);
exit;
