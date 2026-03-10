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

    // Drop ALL existing tables to start fresh (handles partial imports)
    $c->query("SET FOREIGN_KEY_CHECKS = 0");
    $tableResult = $c->query("SHOW TABLES");
    while ($row = $tableResult->fetch_row()) {
        $c->query("DROP TABLE IF EXISTS `{$row[0]}`");
        echo "Dropped: {$row[0]}\n";
    }
    $c->query("SET FOREIGN_KEY_CHECKS = 1");
    echo "Cleaned existing tables\n";

    // Try mysql CLI first (faster and handles complex dumps better)
    $mysqlBin = null;
    foreach (['/usr/bin/mysql', '/usr/local/bin/mysql', 'mysql'] as $candidate) {
        $check = shell_exec("which {$candidate} 2>/dev/null");
        if ($check) {
            $mysqlBin = trim($check);
            break;
        }
    }

    if ($mysqlBin) {
        echo "Using mysql CLI: {$mysqlBin}\n";
        $cmd = sprintf(
            '%s -h %s -u %s -p%s --ssl --default-character-set=utf8mb4 %s < %s 2>&1',
            escapeshellarg($mysqlBin),
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg('pharmacycalloway-database'),
            escapeshellarg($sqlFile)
        );
        $output = shell_exec($cmd);
        echo "CLI output: " . ($output ?: '(none)') . "\n";
    } else {
        echo "mysql CLI not found, using PHP multi_query approach...\n";

        // Read and clean the SQL dump
        $sql = file_get_contents($sqlFile);
        $sql = preg_replace('/\/\*!\d+ DEFINER=`[^`]*`@`[^`]*`[^*]*\*\//', '', $sql);

        // Split on semicolons that appear at end of line (crude but works for mysqldump output)
        $statements = preg_split('/;\s*\n/', $sql);
        $executed = 0;
        $errors = 0;
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0) continue;
            try {
                $c->query($stmt);
                $executed++;
            } catch (Exception $innerE) {
                $errors++;
                if ($errors <= 5) {
                    echo "Error on statement: " . substr($stmt, 0, 100) . "...\nMsg: {$innerE->getMessage()}\n\n";
                }
            }
        }
        echo "Executed: {$executed}, Errors: {$errors}\n";
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
