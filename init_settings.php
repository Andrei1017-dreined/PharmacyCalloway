<?php
/**
 * Initialize Settings Table
 * Run this once to create the settings table and populate default values
 */

require_once 'db_connection.php';

echo "Creating settings table...\n";

// Create settings table
$createTableSQL = "
CREATE TABLE IF NOT EXISTS settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    category VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_key (setting_key)
) ENGINE=InnoDB;
";

if ($conn->query($createTableSQL)) {
    echo "✓ Settings table created successfully\n";
} else {
    echo "✗ Error creating table: " . $conn->error . "\n";
}

// Insert default settings
$defaultSettings = [
    // Company Information
    ['company_name', 'Calloway Pharmacy', 'company', 'Company/Store name'],
    ['company_address', '', 'company', 'Company address'],
    ['company_phone', '', 'company', 'Company phone number'],
    ['company_email', '', 'company', 'Company email address'],
    ['company_website', '', 'company', 'Company website URL'],
    ['company_logo', '', 'company', 'Company logo path/URL'],
    
    // Tax & Currency
    ['tax_rate', '12.00', 'tax', 'Default tax rate percentage'],
    ['currency', 'PHP', 'tax', 'Currency code'],
    ['currency_symbol', '₱', 'tax', 'Currency symbol'],
    ['enable_tax', '1', 'tax', 'Enable/disable tax calculation'],
    
    // Email Settings
    ['email_host', '', 'email', 'SMTP server host'],
    ['email_port', '465', 'email', 'SMTP server port'],
    ['email_username', '', 'email', 'SMTP username'],
    ['email_password', '', 'email', 'SMTP password (encrypted)'],
    ['email_from_name', 'Calloway Pharmacy', 'email', 'From name for emails'],
    ['email_from_address', '', 'email', 'From email address'],
    ['email_encryption', 'ssl', 'email', 'Email encryption (ssl)'],
    
    // Receipt Settings
    ['receipt_header', 'Thank you for shopping with us!', 'receipt', 'Receipt header message'],
    ['receipt_footer', 'Please come again!', 'receipt', 'Receipt footer message'],
    ['receipt_show_logo', '1', 'receipt', 'Show logo on receipt'],
    ['receipt_show_barcode', '1', 'receipt', 'Show barcode on receipt'],
    ['receipt_width', '80mm', 'receipt', 'Receipt paper width'],
    
    // Alert Settings
    ['low_stock_threshold', '20', 'alerts', 'Low stock alert threshold'],
    ['expiry_alert_days', '30', 'alerts', 'Days before expiry to alert'],
    ['enable_email_alerts', '0', 'alerts', 'Master toggle: enable email alerts'],
    ['enable_low_stock_alerts', '1', 'alerts', 'Send email when stock is low'],
    ['enable_expiry_alerts', '1', 'alerts', 'Send email when products are expiring'],
    ['enable_report_emails', '1', 'alerts', 'Send periodic sales report emails'],
    ['report_frequency', 'daily', 'alerts', 'Report email frequency: daily, weekly, or monthly'],
    ['alert_email', '', 'alerts', 'Email address for alerts'],
    
    // Backup Settings
    ['auto_backup_enabled', '1', 'backup', 'Enable automatic backups'],
    ['backup_frequency', 'daily', 'backup', 'Backup frequency'],
    ['backup_retention_days', '30', 'backup', 'Days to keep backups'],
    ['last_backup_date', '', 'backup', 'Last backup timestamp']
];

echo "\nInserting default settings...\n";

$stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value, category, description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value=setting_value");

foreach ($defaultSettings as $setting) {
    $stmt->bind_param('ssss', $setting[0], $setting[1], $setting[2], $setting[3]);
    if ($stmt->execute()) {
        echo "✓ {$setting[0]}\n";
    } else {
        echo "✗ Error inserting {$setting[0]}: " . $stmt->error . "\n";
    }
}

echo "\n✓ Settings initialization complete!\n";
echo "You can now use the System Settings page.\n";

$conn->close();
?>
