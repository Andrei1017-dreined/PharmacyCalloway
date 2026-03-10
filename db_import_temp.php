<?php
/**
 * Temporary DB import script – imports azure_import.sql into pharmacycalloway-database.
 * DELETE THIS FILE and azure_import.sql after import.
 */
set_time_limit(600);
header('Content-Type: text/plain; charset=utf-8');

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

echo "=== DB Import ===\n";
echo "Host: {$host}\n";
echo "Target DB: pharmacycalloway-database\n\n";

$sqlFile = __DIR__ . '/azure_import.sql';
if (!file_exists($sqlFile)) {
    echo "ERROR: azure_import.sql not found!\n";
    exit(1);
}
echo "SQL file size: " . filesize($sqlFile) . " bytes\n";

try {
    $c = mysqli_init();
    $c->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    $c->ssl_set(null, null, null, null, null);
    $c->real_connect($host, $user, $pass, 'pharmacycalloway-database', 3306, null, MYSQLI_CLIENT_SSL);
    $c->set_charset('utf8mb4');
    echo "Connected OK\n\n";

    // Drop existing 3 tables from partial previous run
    foreach (['online_order_items', 'pos_notifications', 'online_orders'] as $t) {
        $c->query("DROP TABLE IF EXISTS `{$t}`");
    }
    echo "Dropped partial tables\n";

    // Read and execute the SQL dump
    $sql = file_get_contents($sqlFile);

    // Remove DEFINER clauses
    $sql = preg_replace('/\/\*!\d+ DEFINER=`[^`]*`@`[^`]*`[^*]*\*\//', '', $sql);

    // Execute multi-query
    echo "Executing import...\n";
    if ($c->multi_query($sql)) {
        $qCount = 0;
        do {
            if ($result = $c->store_result()) {
                $result->free();
            }
            $qCount++;
        } while ($c->more_results() && $c->next_result());
        echo "Executed {$qCount} query batches\n";
    }

    if ($c->error) {
        echo "Last error: {$c->error}\n";
    }

    // Verify tables
    $tr = $c->query("SHOW TABLES");
    echo "\nTables after import:\n";
    $count = 0;
    while ($row = $tr->fetch_row()) {
        echo "  - {$row[0]}\n";
        $count++;
    }
    echo "\nTotal: {$count} tables\n";
    $c->close();
    echo "\n=== IMPORT COMPLETE ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
