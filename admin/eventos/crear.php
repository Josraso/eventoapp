<?php
// crear.php — simplemente redirige a editar.php sin ID
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

$pageTitle = 'Nuevo evento';
// editar.php maneja el caso id=0
include __DIR__ . '/editar.php';
