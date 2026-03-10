<?php
/**
 * Temporary database diagnostic – lists available databases.
 * DELETE THIS FILE after fixing DB_NAME in Azure App Settings.
 */
header('Content-Type: text/plain; charset=utf-8');

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$ssl  = filter_var(getenv('DB_SSL'), FILTER_VALIDATE_BOOLEAN)
     || strpos($host, '.mysql.database.azure.com') !== false;

echo "=== DB Diagnostic ===\n";
echo "Host: {$host}\n";
echo "User: {$user}\n";
echo "SSL:  " . ($ssl ? 'yes' : 'no') . "\n\n";

try {
    if ($ssl) {
        $c = mysqli_init();
        $c->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
        $c->ssl_set(null, null, null, null, null);
        $c->real_connect($host, $user, $pass, null, 3306, null, MYSQLI_CLIENT_SSL);
    } else {
        $c = new mysqli($host, $user, $pass);
    }

    $r = $c->query("SHOW DATABASES");
    echo "Databases:\n";
    while ($row = $r->fetch_row()) {
        echo "  - {$row[0]}\n";
    }
    $c->close();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
