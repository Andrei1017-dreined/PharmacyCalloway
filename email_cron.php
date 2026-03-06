<?php
/**
 * Email Notification Cron Job
 * Run this script to send automated alerts & reports.
 * 
 * Respects system settings:
 *   enable_email_alerts  – master toggle (0 = all off)
 *   enable_low_stock_alerts – send low-stock emails
 *   enable_expiry_alerts    – send expiry-warning emails
 *   enable_report_emails    – send periodic sales reports
 *   report_frequency        – daily | weekly | monthly
 *
 * Setup: Run via Windows Task Scheduler or cron (recommended: once per day)
 * Command: php email_cron.php
 */

require_once 'db_connection.php';
require_once 'email_service.php';

function columnExists($conn, $table, $column) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result && $result->num_rows > 0;
}

/**
 * Load all alert-related settings from the database.
 */
function loadAlertSettings($conn) {
    $defaults = [
        'enable_email_alerts'    => '0',
        'enable_low_stock_alerts'=> '1',
        'enable_expiry_alerts'   => '1',
        'enable_report_emails'   => '1',
        'report_frequency'       => 'daily',
        'low_stock_threshold'    => '20',
        'expiry_alert_days'      => '30',
        'alert_email'            => '',
    ];

    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $types = str_repeat('s', count($keys));

    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $stmt->bind_param($types, ...$keys);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $defaults[$row['setting_key']] = $row['setting_value'];
    }
    $stmt->close();
    return $defaults;
}

/**
 * Determine if a periodic report should run today based on frequency.
 */
function shouldSendReport($frequency) {
    $dayOfWeek = (int)date('N'); // 1=Mon … 7=Sun
    $dayOfMonth = (int)date('j');

    switch ($frequency) {
        case 'weekly':
            return $dayOfWeek === 1; // Every Monday
        case 'monthly':
            return $dayOfMonth === 1; // 1st of each month
        case 'daily':
        default:
            return true;
    }
}

/**
 * Get the report period start date for the given frequency.
 */
function getReportStartDate($frequency) {
    switch ($frequency) {
        case 'weekly':
            return date('Y-m-d', strtotime('-7 days'));
        case 'monthly':
            return date('Y-m-d', strtotime('first day of last month'));
        case 'daily':
        default:
            return date('Y-m-d', strtotime('-1 day'));
    }
}

function getReportEndDate($frequency) {
    switch ($frequency) {
        case 'weekly':
            return date('Y-m-d', strtotime('-1 day'));
        case 'monthly':
            return date('Y-m-d', strtotime('last day of last month'));
        case 'daily':
        default:
            return date('Y-m-d', strtotime('-1 day'));
    }
}

// ─── START ───────────────────────────────────────────
echo "=== Calloway Pharmacy – Email Cron ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Load settings first
    $cfg = loadAlertSettings($conn);

    // Master toggle check
    if ($cfg['enable_email_alerts'] !== '1') {
        echo "Email alerts are DISABLED in System Settings. Exiting.\n";
        $conn->close();
        exit(0);
    }

    $emailService = new EmailService($conn);

    // Detect column names once
    $productNameCol = columnExists($conn, 'products', 'name') ? 'name' : (columnExists($conn, 'products', 'product_name') ? 'product_name' : null);
    $stockCol       = columnExists($conn, 'products', 'stock_quantity') ? 'stock_quantity' : (columnExists($conn, 'products', 'quantity') ? 'quantity' : null);
    $reorderExists  = columnExists($conn, 'products', 'reorder_level');
    $expiryCol      = columnExists($conn, 'products', 'expiry_date') ? 'expiry_date' : (columnExists($conn, 'products', 'expiration_date') ? 'expiration_date' : null);
    $batchExists    = columnExists($conn, 'products', 'batch_number');
    $isActiveExists = columnExists($conn, 'products', 'is_active');

    if ($productNameCol === null || $stockCol === null) {
        throw new Exception('Products table is missing required stock columns for email alerts.');
    }

    $activeFilter = $isActiveExists ? 'is_active = 1 AND ' : '';

    // ── 1. Low Stock Alerts ──────────────────────────
    if ($cfg['enable_low_stock_alerts'] === '1') {
        echo "[Low Stock] Checking...\n";
        $reorderSelect = $reorderExists ? 'reorder_level' : '10 AS reorder_level';
        $lowStockCondition = $reorderExists ? "$stockCol <= reorder_level" : "$stockCol <= 10";

        $query = "SELECT product_id, $productNameCol AS product_name, $stockCol AS stock_quantity, $reorderSelect
                  FROM products
                  WHERE {$activeFilter}{$lowStockCondition}
                  ORDER BY $stockCol ASC";

        $result = $conn->query($query);
        $lowStockProducts = [];

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $lowStockProducts[] = $row;
            }
        }

        if (!empty($lowStockProducts)) {
            echo "  Found " . count($lowStockProducts) . " low stock products. Sending alert...\n";
            echo $emailService->sendLowStockAlert($lowStockProducts) ? "  ✓ Sent!\n" : "  ✗ Failed.\n";
        } else {
            echo "  All stock levels OK.\n";
        }
    } else {
        echo "[Low Stock] Alerts disabled in settings – skipped.\n";
    }

    // ── 2. Expiry Alerts ─────────────────────────────
    if ($cfg['enable_expiry_alerts'] === '1') {
        $alertDays = (int)($cfg['expiry_alert_days'] ?: 30);
        echo "[Expiry] Checking next $alertDays days...\n";

        if ($expiryCol !== null) {
            $today = date('Y-m-d');
            $futureDate = date('Y-m-d', strtotime("+{$alertDays} days"));

            $batchSelect = $batchExists ? 'batch_number' : "'' AS batch_number";

            $query = "SELECT product_id, $productNameCol AS product_name, $batchSelect, $expiryCol AS expiry_date, $stockCol AS stock_quantity
                      FROM products
                      WHERE {$activeFilter}$expiryCol BETWEEN '$today' AND '$futureDate'
                      ORDER BY $expiryCol ASC";

            $result = $conn->query($query);
            $expiringProducts = [];
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $expiringProducts[] = $row;
                }
            }

            if (!empty($expiringProducts)) {
                echo "  Found " . count($expiringProducts) . " expiring products. Sending warning...\n";
                echo $emailService->sendExpiryWarning($expiringProducts, $alertDays) ? "  ✓ Sent!\n" : "  ✗ Failed.\n";
            } else {
                echo "  No products expiring within $alertDays days.\n";
            }
        } else {
            echo "  Skipping: no expiry_date column found.\n";
        }
    } else {
        echo "[Expiry] Alerts disabled in settings – skipped.\n";
    }

    // ── 3. Periodic Sales Report ─────────────────────
    if ($cfg['enable_report_emails'] === '1') {
        $freq = $cfg['report_frequency'] ?: 'daily';
        echo "[Report] Frequency: $freq\n";

        if (shouldSendReport($freq)) {
            $periodStart = getReportStartDate($freq);
            $periodEnd   = getReportEndDate($freq);
            echo "  Generating $freq report ($periodStart to $periodEnd)...\n";
            echo $emailService->sendPeriodicReport($periodStart, $periodEnd, $freq) ? "  ✓ Sent!\n" : "  ✗ Failed.\n";
        } else {
            echo "  Not scheduled for today (next: " .
                 ($freq === 'weekly' ? 'Monday' : '1st of next month') . ").\n";
        }
    } else {
        echo "[Report] Report emails disabled in settings – skipped.\n";
    }

    echo "\n=== Cron completed successfully! ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Email cron job error: " . $e->getMessage());
}

$conn->close();
