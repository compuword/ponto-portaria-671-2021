<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

header('Location: ' . (empty($_SESSION['funcionario_id']) ? '/login.php' : '/bater.php'));
