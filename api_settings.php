<?php
/**
 * Settings API
 * Handle all system settings operations
 */

header('Content-Type: application/json');
require_once 'db_connection.php';
require_once 'Auth.php';

$auth = new Auth($conn);
$auth->requireAuth();

// Get action from request
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_all_settings':
            getAllSettings($conn);
            break;
            
        case 'save_company_info':
            if (!$auth->hasPermission('settings.edit')) {
                throw new Exception('No permission to edit settings');
            }
            saveCompanyInfo($conn);
            break;
            
        case 'save_tax_settings':
            if (!$auth->hasPermission('settings.edit')) {
                throw new Exception('No permission to edit settings');
            }
            saveTaxSettings($conn);
            break;
            
        case 'save_email_settings':
            if (!$auth->hasPermission('settings.edit')) {
                throw new Exception('No permission to edit settings');
            }
            saveEmailSettings($conn);
            break;
            
        case 'save_receipt_settings':
            if (!$auth->hasPermission('settings.edit')) {
                throw new Exception('No permission to edit settings');
            }
            saveReceiptSettings($conn);
            break;
            
        case 'save_alert_settings':
            if (!$auth->hasPermission('settings.edit')) {
                throw new Exception('No permission to edit settings');
            }
            saveAlertSettings($conn);
            break;
            
        case 'test_email':
            if (!$auth->hasPermission('settings.edit')) {
                throw new Exception('No permission to edit settings');
            }
            testEmail($conn);
            break;
            
        case 'test_low_stock_alert':
            if (!$auth->hasPermission('settings.edit')) {
                throw new Exception('No permission to edit settings');
            }
            testLowStockAlert($conn);
            break;
            
        case 'create_backup':
            if (!$auth->hasPermission('settings.backup')) {
                throw new Exception('No permission to create backups');
            }
            createDatabaseBackup($conn);
            break;
            
        case 'restore_backup':
            if (!$auth->hasPermission('settings.backup')) {
                throw new Exception('No permission to restore backups');
            }
            restoreBackup($conn);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getAllSettings($conn) {
    $query = "SELECT * FROM settings ORDER BY setting_key";
    $result = $conn->query($query);
    
    $settings = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = [
                'value' => $row['setting_value'],
                'category' => $row['category']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'settings' => $settings
    ]);
}

function saveCompanyInfo($conn) {
    $company_name = $_POST['company_name'] ?? '';
    $company_address = $_POST['company_address'] ?? '';
    $company_phone = $_POST['company_phone'] ?? '';
    $company_email = $_POST['company_email'] ?? '';
    $company_website = $_POST['company_website'] ?? '';
    $company_logo = $_POST['company_logo'] ?? '';
    
    $settings = [
        'company_name' => $company_name,
        'company_address' => $company_address,
        'company_phone' => $company_phone,
        'company_email' => $company_email,
        'company_website' => $company_website,
        'company_logo' => $company_logo
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value, category) VALUES (?, ?, 'company') ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param('sss', $key, $value, $value);
        $stmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Company information saved successfully'
    ]);
}

function saveTaxSettings($conn) {
    $tax_rate = $_POST['tax_rate'] ?? '0.00';
    $currency = $_POST['currency'] ?? 'PHP';
    $currency_symbol = $_POST['currency_symbol'] ?? '₱';
    $enable_tax = $_POST['enable_tax'] ?? '1';
    
    $settings = [
        'tax_rate' => $tax_rate,
        'currency' => $currency,
        'currency_symbol' => $currency_symbol,
        'enable_tax' => $enable_tax
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value, category) VALUES (?, ?, 'tax') ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param('sss', $key, $value, $value);
        $stmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Tax settings saved successfully'
    ]);
}

function saveEmailSettings($conn) {
    $email_host = $_POST['email_host'] ?? '';
    $email_port = $_POST['email_port'] ?? '465';
    $email_username = $_POST['email_username'] ?? '';
    $email_password = $_POST['email_password'] ?? '';
    $email_from_name = $_POST['email_from_name'] ?? '';
    $email_from_address = $_POST['email_from_address'] ?? '';
    $email_encryption = 'ssl';

    $email_host = trim((string) $email_host);
    if ($email_host === '') {
        $email_host = 'smtp.gmail.com';
    }

    $email_port = '465';
    
    if ($email_password === '') {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'email_password' LIMIT 1");
        if ($stmt && $stmt->execute()) {
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $email_password = $row['setting_value'];
            }
        }

        if ($email_password === '') {
            $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_password' LIMIT 1");
            if ($stmt && $stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $email_password = $row['setting_value'];
                }
            }
        }
    } else {
        $hostLower = strtolower((string) $email_host);
        if (strpos($hostLower, 'gmail.com') !== false) {
            $email_password = preg_replace('/\s+/', '', $email_password);
        }
        // Use proper AES-256-CBC encryption for credentials
        require_once __DIR__ . '/CryptoManager.php';
        $email_password = CryptoManager::encrypt($email_password);
    }

    $fromEmail = $email_from_address !== '' ? $email_from_address : $email_username;
    $fromName = $email_from_name !== '' ? $email_from_name : 'Calloway Pharmacy';
    if ($email_username === '' && $fromEmail !== '') {
        $email_username = $fromEmail;
    }

    $settings = [
        'email_host' => $email_host,
        'email_port' => $email_port,
        'email_username' => $email_username,
        'email_password' => $email_password,
        'email_from_name' => $fromName,
        'email_from_address' => $fromEmail,
        'email_encryption' => $email_encryption,
        'smtp_host' => $email_host,
        'smtp_port' => $email_port,
        'smtp_username' => $email_username,
        'smtp_password' => $email_password,
        'smtp_from_name' => $fromName,
        'smtp_from_email' => $fromEmail
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value, category) VALUES (?, ?, 'email') ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param('sss', $key, $value, $value);
        $stmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Email settings saved successfully'
    ]);
}

function saveReceiptSettings($conn) {
    $receipt_header = $_POST['receipt_header'] ?? '';
    $receipt_footer = $_POST['receipt_footer'] ?? '';
    $show_logo = $_POST['show_logo'] ?? '1';
    $show_barcode = $_POST['show_barcode'] ?? '1';
    $receipt_width = $_POST['receipt_width'] ?? '80mm';
    
    $settings = [
        'receipt_header' => $receipt_header,
        'receipt_footer' => $receipt_footer,
        'receipt_show_logo' => $show_logo,
        'receipt_show_barcode' => $show_barcode,
        'receipt_width' => $receipt_width
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value, category) VALUES (?, ?, 'receipt') ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param('sss', $key, $value, $value);
        $stmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Receipt settings saved successfully'
    ]);
}

function saveAlertSettings($conn) {
    $low_stock_threshold = $_POST['low_stock_threshold'] ?? '20';
    $expiry_alert_days = $_POST['expiry_alert_days'] ?? '30';
    $enable_email_alerts = $_POST['enable_email_alerts'] ?? '0';
    $enable_low_stock_alerts = $_POST['enable_low_stock_alerts'] ?? '0';
    $enable_expiry_alerts = $_POST['enable_expiry_alerts'] ?? '0';
    $enable_report_emails = $_POST['enable_report_emails'] ?? '0';
    $report_frequency = $_POST['report_frequency'] ?? 'daily';
    $alert_email = $_POST['alert_email'] ?? '';

    // Validate report frequency
    if (!in_array($report_frequency, ['daily', 'weekly', 'monthly'], true)) {
        $report_frequency = 'daily';
    }
    
    $settings = [
        'low_stock_threshold' => $low_stock_threshold,
        'expiry_alert_days' => $expiry_alert_days,
        'enable_email_alerts' => $enable_email_alerts,
        'enable_low_stock_alerts' => $enable_low_stock_alerts,
        'enable_expiry_alerts' => $enable_expiry_alerts,
        'enable_report_emails' => $enable_report_emails,
        'report_frequency' => $report_frequency,
        'alert_email' => $alert_email
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value, category) VALUES (?, ?, 'alerts') ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param('sss', $key, $value, $value);
        $stmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Alert settings saved successfully'
    ]);
}

function testEmail($conn) {
    $test_email = $_POST['test_email'] ?? '';
    
    if (empty($test_email)) {
        throw new Exception('Test email address is required');
    }
    
    require_once 'email_service.php';

    $emailService = new EmailService($conn);
    $isSent = $emailService->sendTestEmail($test_email);

    if (!$isSent) {
        $smtpError = trim($emailService->getLastError());
        $baseMessage = 'Failed to send test email. Please verify SMTP host, SSL port 465, Gmail address, and App Password.';
        throw new Exception($smtpError !== '' ? ($baseMessage . ' SMTP error: ' . $smtpError) : $baseMessage);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Test email sent successfully to ' . $test_email
    ]);
}

function createDatabaseBackup($conn) {
    $backup_dir = __DIR__ . '/backups';
    if (!is_dir($backup_dir) && !mkdir($backup_dir, 0755, true)) {
        throw new Exception('Failed to create backup directory');
    }

    $filename = 'full_backup_' . date('Y-m-d_His') . '.sql';
    $backup_file = $backup_dir . '/' . $filename;

    $sql = "-- Calloway Pharmacy Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $objects_result = $conn->query("SHOW FULL TABLES");
    if (!$objects_result) {
        throw new Exception('Failed to enumerate database objects');
    }

    while ($object = $objects_result->fetch_array(MYSQLI_NUM)) {
        $object_name = $object[0];
        $object_type = strtoupper((string) ($object[1] ?? 'BASE TABLE'));

        if ($object_type === 'VIEW') {
            $create_view_result = $conn->query("SHOW CREATE VIEW `{$object_name}`");
            if ($create_view_result) {
                $create_view_row = $create_view_result->fetch_assoc();
                $create_view_sql = $create_view_row['Create View'] ?? (array_values($create_view_row)[1] ?? '');

                if ($create_view_sql !== '') {
                    $sql .= "DROP VIEW IF EXISTS `{$object_name}`;\n";
                    $sql .= $create_view_sql . ";\n\n";
                } else {
                    $sql .= "-- Skipped view `{$object_name}`: unable to read CREATE VIEW statement\n\n";
                }
            } else {
                $sql .= "-- Skipped view `{$object_name}`: " . $conn->real_escape_string($conn->error) . "\n\n";
            }
            continue;
        }

        $sql .= "DROP TABLE IF EXISTS `{$object_name}`;\n";

        $create_result = $conn->query("SHOW CREATE TABLE `{$object_name}`");
        if (!$create_result) {
            $sql .= "-- Skipped table `{$object_name}` schema: " . $conn->real_escape_string($conn->error) . "\n\n";
            continue;
        }

        $create_row = $create_result->fetch_assoc();
        $create_sql = $create_row['Create Table'] ?? (array_values($create_row)[1] ?? '');
        if ($create_sql === '') {
            $sql .= "-- Skipped table `{$object_name}` schema: empty definition\n\n";
            continue;
        }
        $sql .= $create_sql . ";\n\n";

        $data_result = $conn->query("SELECT * FROM `{$object_name}`");
        if (!$data_result) {
            $sql .= "-- Skipped table `{$object_name}` data: " . $conn->real_escape_string($conn->error) . "\n\n";
            continue;
        }

        if ($data_result->num_rows > 0) {
            while ($row = $data_result->fetch_assoc()) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $conn->real_escape_string($value) . "'";
                    }
                }
                $sql .= "INSERT INTO `{$object_name}` VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    if (file_put_contents($backup_file, $sql) === false) {
        throw new Exception('Failed to write backup file');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Database backup created successfully',
        'file' => 'backups/' . $filename
    ]);
}

function restoreBackup($conn) {
    $backup_file = $_POST['backup_file'] ?? '';
    
    if (empty($backup_file) || !file_exists($backup_file)) {
        throw new Exception('Backup file not found');
    }
    
    // Security: ensure backup file is inside the backups directory
    $realPath = realpath($backup_file);
    $allowedDir = realpath(__DIR__ . '/backups');
    if ($realPath === false || $allowedDir === false || strpos($realPath, $allowedDir) !== 0) {
        throw new Exception('Invalid backup file path');
    }
    
    // Read SQL file
    $sql = file_get_contents($backup_file);
    
    // Execute SQL and track errors
    $errors = [];
    if ($conn->multi_query($sql)) {
        $stmtNum = 0;
        do {
            $stmtNum++;
            if ($conn->error) {
                $errors[] = "Statement #$stmtNum: " . $conn->error;
            }
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->next_result());
        
        if (!empty($errors)) {
            error_log('Backup restore had errors: ' . implode('; ', $errors));
            echo json_encode([
                'success' => false,
                'message' => 'Database restore completed with ' . count($errors) . ' error(s). Check logs for details.'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'Database restored successfully'
            ]);
        }
    } else {
        throw new Exception('Failed to restore database: ' . $conn->error);
    }
}

function testLowStockAlert($conn) {
    require_once 'email_service.php';
    
    // Detect column names dynamically (same logic as email_cron.php)
    $nameCol = 'name';
    $stockCol = 'stock_quantity';
    $reorderCol = 'reorder_level';
    
    $colCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'product_name'");
    if ($colCheck && $colCheck->num_rows > 0) $nameCol = 'product_name';
    
    $colCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'quantity'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $colCheck2 = $conn->query("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
        if (!$colCheck2 || $colCheck2->num_rows === 0) $stockCol = 'quantity';
    }
    
    // Get low stock threshold from settings
    $threshold = 20;
    $tRes = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'low_stock_threshold' LIMIT 1");
    if ($tRes && $row = $tRes->fetch_assoc()) {
        $threshold = intval($row['setting_value']) ?: 20;
    }
    
    // Build active condition
    $activeCondition = '';
    $colCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'is_active'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $activeCondition = "AND is_active = 1";
    }
    
    // Build reorder condition
    $reorderCondition = "$stockCol < $threshold";
    $colCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'reorder_level'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $reorderCondition = "($stockCol <= reorder_level OR $stockCol < $threshold)";
    }
    
    $query = "SELECT $nameCol as product_name, $stockCol as stock_quantity FROM products WHERE $reorderCondition $activeCondition ORDER BY $stockCol ASC LIMIT 50";
    $result = $conn->query($query);
    
    $products = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
    }
    
    if (empty($products)) {
        echo json_encode([
            'success' => false,
            'message' => 'No low-stock products found (threshold: ' . $threshold . '). All products are well-stocked!'
        ]);
        return;
    }
    
    $emailService = new EmailService($conn);
    $sent = $emailService->sendLowStockAlert($products);
    
    if ($sent) {
        echo json_encode([
            'success' => true,
            'message' => '✅ Low stock alert sent! Found ' . count($products) . ' low-stock product(s). Email delivered to pharmacy owner.'
        ]);
    } else {
        $error = $emailService->getLastError();
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send low stock alert email. ' . ($error ? 'Error: ' . $error : 'Check SMTP settings.')
        ]);
    }
}
?>
