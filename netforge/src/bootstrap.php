<?php
declare(strict_types=1);

define('NF_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $prefix = 'NetForge\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$configFile = NF_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    $configFile = NF_ROOT . '/config/config.example.php';
}
NetForge\Config::load(require $configFile);

date_default_timezone_set((string) NetForge\Config::get('app.timezone', 'Asia/Kuala_Lumpur'));
error_reporting(E_ALL);
ini_set('display_errors', NetForge\Config::get('app.debug') ? '1' : '0');

session_name((string) NetForge\Config::get('app.session_name', 'netforge_sid'));
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

/** Escape for HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
