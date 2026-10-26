<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
if (!empty($_SESSION['funcionario_id'])) {
    ponto_log((int)$_SESSION['funcionario_id'], 'logout');
}
session_destroy();
header('Location: /login.php');
