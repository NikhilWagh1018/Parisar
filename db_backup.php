<?php
// ═══════════════════════════════════════════════════════════════
//  db_backup.php — One-time database backup script
//  DELETE THIS FILE immediately after use!
// ═══════════════════════════════════════════════════════════════

define('BACKUP_SECRET', 'nick2026backup');

if (($_GET['token'] ?? '') !== BACKUP_SECRET) {
    http_response_code(403);
    die('Forbidden.');
}

// Try all possible env variable names Railway might use
function env(string ...$keys): string {
    foreach ($keys as $key) {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? '';
        if ($val !== '') return $val;
    }
    return '';
}

$host = env('DB_HOST',     'MYSQLHOST',     'MYSQL_HOST');
$port = env('DB_PORT',     'MYSQLPORT',     'MYSQL_PORT')     ?: '3306';
$user = env('DB_USER',     'MYSQLUSER',     'MYSQL_USER');
$pass = env('DB_PASS',     'MYSQLPASSWORD', 'MYSQL_PASSWORD', 'DB_PASSWORD');
$db   = env('DB_NAME',     'MYSQLDATABASE', 'MYSQL_DATABASE', 'DB_DATABASE');

if (!$db)   die('ERROR: Could not read database name. Keys tried: DB_NAME, MYSQLDATABASE');
if (!$host) die('ERROR: Could not read database host. Keys tried: DB_HOST, MYSQLHOST');

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('DB connection failed: ' . $e->getMessage());
}

$filename = "parisar_backup_" . date('Y-m-d_H-i-s') . ".sql";
header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Pragma: no-cache');

$output = "-- Parisar CycleAudit Database Backup\n";
$output .= "-- Generated: " . date('Y-m-d H:i:s') . " UTC\n";
$output .= "-- Database: {$db} @ {$host}:{$port}\n\n";
$output .= "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\n\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $output .= "-- Table: `{$table}`\n";
    $output .= "DROP TABLE IF EXISTS `{$table}`;\n";
    $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
    $output .= $createRow[1] . ";\n\n";

    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 0) {
        $rowSqls = [];
        foreach ($rows as $row) {
            $values = array_map(fn($val) => $val === null ? 'NULL' : $pdo->quote($val), array_values($row));
            $rowSqls[] = '(' . implode(', ', $values) . ')';
            if (count($rowSqls) >= 500) {
                $output .= "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $rowSqls) . ";\n";
                echo $output; $output = ''; $rowSqls = [];
            }
        }
        if ($rowSqls) {
            $output .= "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $rowSqls) . ";\n";
        }
        $output .= "\n";
    }
}

$output .= "SET FOREIGN_KEY_CHECKS=1;\n-- Backup complete.\n";
echo $output;
