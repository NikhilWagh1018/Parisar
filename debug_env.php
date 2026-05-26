<?php
// TEMPORARY DEBUG FILE — DELETE AFTER USE
// Shows which DB env vars Railway is providing (no passwords shown)

$vars = [
    'MYSQLHOST', 'MYSQLPORT', 'MYSQLDATABASE', 'MYSQLUSER', 'MYSQLPASSWORD',
    'MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD',
    'MYSQL_URL', 'DATABASE_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS',
];

$result = [];
foreach ($vars as $var) {
    $val = getenv($var);
    if ($val !== false) {
        // Show value but mask passwords
        if (stripos($var, 'pass') !== false || stripos($var, 'url') !== false) {
            $result[$var] = '*** SET (length=' . strlen($val) . ')';
        } else {
            $result[$var] = $val;
        }
    } else {
        $result[$var] = 'NOT SET';
    }
}

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
