<?php
/**
 * Performance Index Migration
 * Run once to add missing indexes that speed up reports and product queries.
 * Safe to re-run — uses IF NOT EXISTS / checks before creating.
 */

require_once 'db_connection.php';

header('Content-Type: text/plain; charset=utf-8');

function indexExists($conn, $table, $indexName) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
    $stmt->bind_param("ss", $table, $indexName);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

$indexes = [
    ['sales',        'idx_sales_created_at',        'created_at'],
    ['sale_items',   'idx_sale_items_product',       'product_id'],
    ['sale_items',   'idx_sale_items_sale',          'sale_id'],
    ['online_orders','idx_online_orders_created',    'created_at'],
    ['products',     'idx_products_active_stock',    'is_active, stock_quantity'],
    ['products',     'idx_products_expiry',          'expiry_date'],
];

$created = 0;
$skipped = 0;

foreach ($indexes as [$table, $name, $columns]) {
    // Check table exists first
    $check = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$table'");
    if (!$check || $check->num_rows === 0) {
        echo "SKIP  $table.$name — table does not exist\n";
        $skipped++;
        continue;
    }

    if (indexExists($conn, $table, $name)) {
        echo "EXISTS $table.$name\n";
        $skipped++;
        continue;
    }

    $sql = "CREATE INDEX `$name` ON `$table` ($columns)";
    try {
        $conn->query($sql);
        echo "CREATED $table.$name ($columns)\n";
        $created++;
    } catch (Exception $e) {
        echo "ERROR $table.$name — " . $e->getMessage() . "\n";
    }
}

echo "\nDone. Created: $created, Skipped/Existing: $skipped\n";
