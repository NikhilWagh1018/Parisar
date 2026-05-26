<?php
// ═══════════════════════════════════════════════════════════════
//  db_backup.php — One-time database backup script
//  Upload to your project root, visit it once, then DELETE IT.
//  ⚠️  Delete this file immediately after use!
// ═══════════════════════════════════════════════════════════════

// Block access unless a secret token is passed
// Change 'nick2026backup' to something only you know
define('BACKUP_SECRET', 'nick2026backup');

if (($_GET['token'] ?? '') !== BACKUP_SECRET) {
    http_response_code(403);
    die('Forbidden. Pass ?token=YOUR_SECRET in the URL.');
}

// Load DB credentials from environment (same as your app)
$host = $_ENV['MYSQLHOST']     ?? $_SERVER['MYSQLHOST']     ?? getenv('MYSQLHOST')     ?? '127.0.0.1';
$port = $_ENV['MYSQLPORT']     ?? $_SERVER['MYSQLPORT']     ?? getenv('MYSQLPORT')     ?? '3306';
$user = $_ENV['MYSQLUSER']     ?? $_SERVER['MYSQLUSER']     ?? getenv('MYSQLUSER')     ?? 'root';
$pass = $_ENV['MYSQLPASSWORD'] ?? $_SERVER['MYSQLPASSWORD'] ?? getenv('MYSQLPASSWORD') ?? '';
$db   = $_ENV['MYSQLDATABASE'] ?? $_SERVER['MYSQLDATABASE'] ?? getenv('MYSQLDATABASE') ?? '';

if (!$db) {
    die('ERROR: Could not read database name from environment variables.');
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('DB connection failed: ' . $e->getMessage());
}

$filename = "parisar_backup_" . date('Y-m-d_H-i-s') . ".sql";

// Send as downloadable file
header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Pragma: no-cache');

$output = '';

// ── Header ────────────────────────────────────────────────────
$output .= "-- Parisar CycleAudit Database Backup\n";
$output .= "-- Generated: " . date('Y-m-d H:i:s') . " UTC\n";
$output .= "-- Database: {$db}\n";
$output .= "-- Host: {$host}:{$port}\n";
$output .= "--\n\n";
$output .= "SET FOREIGN_KEY_CHECKS=0;\n";
$output .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
$output .= "SET NAMES utf8mb4;\n\n";

// ── Get all tables ────────────────────────────────────────────
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    // DROP + CREATE
    $output .= "-- ────────────────────────────────────────\n";
    $output .= "-- Table: `{$table}`\n";
    $output .= "-- ────────────────────────────────────────\n";
    $output .= "DROP TABLE IF EXISTS `{$table}`;\n";

    $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
    $output .= $createRow[1] . ";\n\n";

    // Data
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 0) {
        $output .= "INSERT INTO `{$table}` VALUES\n";
        $rowSqls = [];

        foreach ($rows as $row) {
            $values = array_map(function ($val) use ($pdo) {
                if ($val === null) return 'NULL';
                return $pdo->quote($val);
            }, array_values($row));

            $rowSqls[] = '(' . implode(', ', $values) . ')';

            // Flush every 500 rows to avoid memory issues
            if (count($rowSqls) >= 500) {
                $output .= implode(",\n", $rowSqls) . ";\n";
                $output .= "INSERT INTO `{$table}` VALUES\n";
                echo $output;
                $output = '';
                $rowSqls = [];
            }
        }

        if (count($rowSqls) > 0) {
            $output .= implode(",\n", $rowSqls) . ";\n";
        }
        $output .= "\n";
    }
}

$output .= "SET FOREIGN_KEY_CHECKS=1;\n";
$output .= "-- Backup complete.\n";

echo $output;
exit;
