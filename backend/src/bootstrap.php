<?php
declare(strict_types=1);

/**
 * Bootstraps the application: constants, PSR-4 style autoloader, configuration,
 * timezone and a last-resort error handler that always answers in JSON.
 */

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'ETicket\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once BASE_PATH . '/src/Core/Exceptions.php'; // several small classes in one file

use ETicket\Core\Config;

Config::load(BASE_PATH . '/.env');
date_default_timezone_set(Config::get('APP_TZ', 'Asia/Manila'));
