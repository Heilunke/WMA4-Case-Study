<?php
declare(strict_types=1);

/**
 * One-shot database setup (CLI):
 *
 *   php backend/database/setup.php             create DB + tables + demo data
 *   php backend/database/setup.php --no-seed   create DB + empty tables only
 *
 * WARNING: this DROPS and recreates the six tables (plus login_attempts). Run it on a fresh/dev database.
 */

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use ETicket\Core\Config;
use ETicket\Core\Database;

$seed = !in_array('--no-seed', $argv, true);
$name = Config::get('DB_NAME', 'eticket');
if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $name)) {
    fwrite(STDERR, "DB_NAME may only contain letters, digits and underscores.\n");
    exit(1);
}

// 1) connect to the server (no database yet) and create the database
$server = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'), Config::get('DB_PORT', '3306')),
    Config::get('DB_USER', 'root'),
    Config::get('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("USE `$name`");

// 2) run schema.sql (minus its own CREATE DATABASE / USE, which are for phpMyAdmin imports)
$sql = file_get_contents(__DIR__ . '/schema.sql');
$sql = preg_replace('/--.*$/m', '', $sql);                       // strip comments
$sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $server->exec($statement);
}
echo "Schema created in database `$name`.\n";

// 3) demo data, created through the real services so the business rules apply
if ($seed) {
    Database::reset();
    require __DIR__ . '/Seeder.php';
    (new Seeder())->run();
    echo "Demo data inserted.\n";
    echo "  Administrator: admin@eticket.test / admin123\n";
    echo "  Attendee:      attendee@eticket.test / attendee123\n";
    echo "Change these passwords before any real deployment.\n";
}
