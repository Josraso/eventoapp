<?php
// logout.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
Auth::userLogout();
flash('ok', 'Has cerrado sesión correctamente.');
redirect('index.php');
