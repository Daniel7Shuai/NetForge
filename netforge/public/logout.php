<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Auth;
use NetForge\Csrf;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::check()) {
    Auth::logout();
}
header('Location: login.php');
