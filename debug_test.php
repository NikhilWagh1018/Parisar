<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "PHP version: " . PHP_VERSION . "\n";
echo "Testing constants.php load...\n";

try {
    require_once __DIR__ . '/config/constants.php';
    echo "constants.php OK\n";
} catch (Throwable $e) {
    echo "ERROR in constants.php: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

try {
    require_once __DIR__ . '/config/auth_guard.php';
    echo "auth_guard.php OK\n";
} catch (Throwable $e) {
    echo "ERROR in auth_guard.php: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

phpinfo(INFO_ALL);
