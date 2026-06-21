<?php
if (!defined('ADMIN_GUARD')) define('ADMIN_GUARD', true);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');

$siteName  = getSetting('site_name', 'Eventos');
$logoPath  = getSetting('logo_path');
$logoUrl   = ($logoPath && file_exists(__DIR__.'/../'.$logoPath)) ? rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/') . '/' . $logoPath : null;
$adminRole = Auth::adminRole();
$adminName = Auth::adminName();
$base      = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

$currentPath = $_SERVER['PHP_SELF'] ?? '';

$nav = [
    ['url' => $base.'/admin/index.php',               'icon' => '📊', 'label' => 'Dashboard',      'match' => '/admin/index.php'],
    ['url' => $base.'/admin/eventos/index.php',        'icon' => '🎫', 'label' => 'Eventos',        'match' => '/admin/eventos/'],
    ['url' => $base.'/admin/inscripciones/index.php',  'icon' => '📋', 'label' => 'Pedidos',        'match' => '/admin/inscripciones/'],
    ['url' => $base.'/admin/inscritos/index.php',      'icon' => '🧍', 'label' => 'Inscritos',      'match' => '/admin/inscritos/'],
    ['url' => $base.'/admin/usuarios/index.php',       'icon' => '👤', 'label' => 'Usuarios',       'match' => '/admin/usuarios/'],
    ['url' => $base.'/admin/porteros/index.php',       'icon' => '🚪', 'label' => 'Porteros',       'match' => '/admin/porteros/'],
    ['url' => $base.'/admin/camareros/index.php',      'icon' => '🍹', 'label' => 'Camareros',      'match' => '/admin/camareros/'],
    ['url' => $base.'/admin/ajustes/index.php',        'icon' => '⚙️', 'label' => 'Ajustes',        'match' => '/admin/ajustes/'],
];
if ($adminRole === 'superadmin') {
    $nav[] = ['url' => $base.'/admin/administradores/index.php', 'icon' => '🛡️', 'label' => 'Administradores', 'match' => '/admin/administradores/'];
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($pageTitle ?? 'Admin') ?> — <?= h($siteName) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f0f3;color:#1a1a1a;min-height:100vh;display:flex;}
.sidebar{width:220px;min-height:100vh;background:#1a1a1c;color:#fff;display:flex;flex-direction:column;position:fixed;top:0;left:0;z-index:100;}
.sidebar-head{padding:20px 18px 14px;border-bottom:1px solid #2a2a2e;}
.sidebar-logo{font-size:15px;font-weight:800;color:#fff;text-decoration:none;display:flex;align-items:center;gap:8px;}
.sidebar-logo .dot{width:8px;height:8px;background:#6366f1;border-radius:50%;flex-shrink:0;}
.sidebar-sub{font-size:11px;color:#555;margin-top:3px;}
.sidebar-nav{flex:1;padding:12px 8px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;font-size:13px;font-weight:500;color:#888;text-decoration:none;transition:background .15s,color .15s;margin-bottom:2px;}
.nav-item:hover{background:#252528;color:#fff;}
.nav-item.active{background:#6366f1;color:#fff;}
.nav-item .icon{font-size:15px;width:20px;text-align:center;}
.sidebar-foot{padding:14px 18px;border-top:1px solid #2a2a2e;}
.sidebar-user{font-size:12px;color:#555;margin-bottom:8px;}
.sidebar-user strong{color:#888;display:block;}
.logout-btn{display:block;font-size:12px;color:#555;text-decoration:none;padding:6px 10px;border-radius:6px;background:#111;}
.logout-btn:hover{color:#fff;}
.main{margin-left:220px;flex:1;min-height:100vh;}
.topbar{background:#fff;border-bottom:1px solid #e8e8e8;padding:14px 28px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar h1{font-size:18px;font-weight:700;letter-spacing:-.02em;}
.topbar .actions{display:flex;gap:10px;align-items:center;}
.content{padding:24px 28px;}
.card{background:#fff;border-radius:12px;border:1px solid #e4e4e8;padding:22px 24px;margin-bottom:16px;}
.card-title{font-size:11px;font-weight:700;color:#bbb;text-transform:uppercase;letter-spacing:.08em;margin-bottom:14px;}
.table-wrap{overflow-x:auto;}
table.admin{width:100%;border-collapse:collapse;font-size:13px;}
table.admin th{text-align:left;font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.06em;padding:10px 14px;border-bottom:2px solid #f0f0f0;}
table.admin td{padding:11px 14px;border-bottom:1px solid #f8f8f8;vertical-align:middle;}
table.admin tr:last-child td{border-bottom:none;}
table.admin tr:hover td{background:#fafafa;}
.field{margin-bottom:14px;}
.field label{font-size:11px;font-weight:700;color:#777;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em;}
.field input,.field select,.field textarea{width:100%;border:1.5px solid #e0e0e0;border-radius:9px;padding:10px 12px;font-size:14px;outline:none;transition:border-color .15s;background:#fff;font-family:inherit;color:#1a1a1a;}
.field input:focus,.field select:focus,.field textarea:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.08);}
.field textarea{min-height:80px;resize:vertical;}
.field .hint{font-size:11px;color:#aaa;margin-top:4px;}
.field-row{display:flex;gap:12px;}
.field-row .field{flex:1;}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;background:#6366f1;color:#fff;border:none;border-radius:9px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;transition:opacity .15s;font-family:inherit;}
.btn:hover{opacity:.88;}
.btn:disabled{opacity:.45;cursor:not-allowed;}
.btn-sm{padding:6px 14px;font-size:12px;border-radius:7px;}
.btn-outline{background:transparent;border:1.5px solid #e0e0e0;color:#333;}
.btn-outline:hover{border-color:#aaa;background:#fafafa;opacity:1;}
.btn-danger{background:#c0392b;}
.btn-success{background:#1a7a3a;}
.btn-warning{background:#c87f00;}
.alert{border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px;display:flex;align-items:flex-start;gap:8px;}
.alert-error{background:#fff1f0;border:1.5px solid #fcc;color:#c0392b;}
.alert-success{background:#e6f9ee;border:1.5px solid #b7e4c7;color:#1a7a3a;}
.alert-info{background:#f0f4ff;border:1.5px solid #c5d3f0;color:#1a3a7a;}
.alert-warning{background:#fffbeb;border:1.5px solid #f0d98a;color:#8a6a00;}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-green{background:#e6f9ee;color:#1a7a3a;}
.badge-orange{background:#fffbeb;color:#8a5a00;}
.badge-red{background:#fff1f0;color:#c0392b;}
.badge-gray{background:#f0f0f0;color:#666;}
.badge-blue{background:#f0f4ff;color:#1a3a7a;}
.badge-purple{background:#f3f0ff;color:#5a1aaa;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:20px;}
.stat-box{background:#fff;border:1px solid #e4e4e8;border-radius:12px;padding:18px 20px;}
.stat-box .val{font-size:30px;font-weight:800;letter-spacing:-.03em;line-height:1;}
.stat-box .lbl{font-size:12px;color:#888;margin-top:4px;}
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.filters select,.filters input{border:1.5px solid #e0e0e0;border-radius:8px;padding:8px 12px;font-size:13px;outline:none;background:#fff;font-family:inherit;}
.filters select:focus,.filters input:focus{border-color:#6366f1;}
@media(max-width:768px){.sidebar{display:none;}.main{margin-left:0;}.content{padding:16px;}.field-row{flex-direction:column;}}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-head">
    <a href="<?= h($base) ?>/admin/index.php" class="sidebar-logo">
      <?php if ($logoUrl): ?>
        <img src="<?= h($logoUrl) ?>" style="max-height:28px;max-width:140px;object-fit:contain;">
      <?php else: ?>
        <span class="dot"></span> <?= h($siteName) ?>
      <?php endif; ?>
    </a>
    <div class="sidebar-sub">Panel de administración</div>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($nav as $n):
      $active = strpos($currentPath, $n['match']) !== false;
    ?>
    <a href="<?= h($n['url']) ?>" class="nav-item <?= $active ? 'active' : '' ?>">
      <span class="icon"><?= $n['icon'] ?></span>
      <?= h($n['label']) ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-foot">
    <div class="sidebar-user">
      <strong><?= h($adminName) ?></strong>
      <?= ucfirst($adminRole) ?>
    </div>
    <a href="<?= h($base) ?>/admin/perfil.php" class="logout-btn" style="margin-bottom:6px;">👤 Mi perfil</a>
    <a href="<?= h($base) ?>/admin/logout.php" class="logout-btn">Cerrar sesión →</a>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <h1><?= h($pageTitle ?? '') ?></h1>
    <div class="actions">
      <a href="<?= h($base) ?>/public/index.php" target="_blank" style="font-size:13px;color:#888;text-decoration:none;">Ver web ↗</a>
    </div>
  </div>
  <div class="content">
    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type']==='ok' ? 'success' : $flash['type'] ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>
