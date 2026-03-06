<?php
/**
 * Email Notification Service
 * Handles all email notifications using PHPMailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php'; // For PHPMailer via Composer
}
require_once 'db_connection.php';

class EmailService {
    private const OWNER_ALERT_EMAIL = 'pharmacycalloway@gmail.com';
    private $conn;
    private $mailer;
    private $fromEmail;
    private $fromName;
    private $smtpSettings = [];
    private $lastError = '';
    
    public function __construct($conn) {
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new Exception('PHPMailer is not installed. Run composer install in the project root.');
        }

        $this->conn = $conn;
        $this->mailer = new PHPMailer(true);
        
        // Load SMTP settings from database
        $this->loadSettings();
        
        // Configure PHPMailer
        $this->configureSMTP();
    }
    
    private function loadSettings() {
        // Try to load from settings table (supports both smtp_* and email_* keys)
        $query = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN (
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name',
            'email_host', 'email_port', 'email_username', 'email_password', 'email_from_address', 'email_from_name', 'email_encryption'
        )";
        $result = $this->conn->query($query);
        
        $settings = [
            'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
            'port' => (int) (getenv('SMTP_PORT') ?: 465),
            'username' => getenv('SMTP_USER') ?: '',
            'password' => getenv('SMTP_PASS') ?: '',
            'from_email' => getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USER') ?: 'noreply@callowaypharmacy.com'),
            'from_name' => getenv('SMTP_FROM_NAME') ?: 'Calloway Pharmacy',
            'encryption' => strtolower(getenv('SMTP_ENCRYPTION') ?: 'ssl')
        ];
        
        if ($result && $result->num_rows > 0) {
            $raw = [];
            while ($row = $result->fetch_assoc()) {
                $raw[$row['setting_key']] = $row['setting_value'];
            }

            $settings['host'] = trim($raw['smtp_host'] ?? $raw['email_host'] ?? $settings['host']);
            $settings['port'] = (int) ($raw['smtp_port'] ?? $raw['email_port'] ?? $settings['port']);
            $settings['username'] = trim($raw['smtp_username'] ?? $raw['email_username'] ?? $settings['username']);

            $passwordValue = $raw['smtp_password'] ?? ($raw['email_password'] ?? $settings['password']);
            // Decrypt using AES-256-CBC encryption
            require_once __DIR__ . '/CryptoManager.php';
            $decryptedPassword = CryptoManager::decrypt($passwordValue);
            // Fallback to raw value if decryption fails (for backward compatibility with base64)
            if ($decryptedPassword === false) {
                $decryptedPassword = base64_decode((string) $passwordValue, true);
                if ($decryptedPassword === false) {
                    $decryptedPassword = $passwordValue;
                }
            }
            $settings['password'] = ($decryptedPassword !== '' ? $decryptedPassword : $passwordValue);

            $settings['from_email'] = trim($raw['smtp_from_email'] ?? $raw['email_from_address'] ?? $settings['from_email']);
            $settings['from_name'] = trim($raw['smtp_from_name'] ?? $raw['email_from_name'] ?? $settings['from_name']);
            $settings['encryption'] = strtolower(trim($raw['email_encryption'] ?? $settings['encryption']));
        }

        if ($settings['username'] === '' && filter_var((string) $settings['from_email'], FILTER_VALIDATE_EMAIL)) {
            $settings['username'] = (string) $settings['from_email'];
        }

        if ($settings['from_email'] === '') {
            $settings['from_email'] = $settings['username'] ?: 'noreply@callowaypharmacy.com';
        }

        $hostLower = strtolower((string) $settings['host']);
        if (strpos($hostLower, 'gmail.com') !== false) {
            $settings['username'] = trim((string) $settings['username']);
            $settings['password'] = preg_replace('/\s+/', '', (string) $settings['password']);
        }
        
        if (trim((string) $settings['host']) === '') {
            $settings['host'] = 'smtp.gmail.com';
        }

        $settings['encryption'] = 'ssl';
        $settings['port'] = 465;

        $this->smtpSettings = $settings;
        $this->fromEmail = $settings['from_email'];
        $this->fromName = $settings['from_name'];
        
        return $settings;
    }
    
    private function configureSMTP() {
        $settings = !empty($this->smtpSettings) ? $this->smtpSettings : $this->loadSettings();
        
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $settings['host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->AuthType = 'LOGIN';
            $this->mailer->Username = $settings['username'];
            $this->mailer->Password = $settings['password'];
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $this->mailer->SMTPAutoTLS = false;
            $this->mailer->Port = (int) $settings['port'];
            $this->mailer->Timeout = 20;
            $this->mailer->SMTPKeepAlive = false;
            $this->mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];

            // Ensure UTF-8 headers/body so emojis render correctly in inbox previews
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->Encoding = PHPMailer::ENCODING_BASE64;
            
            // Set from address
            $this->mailer->setFrom($this->fromEmail, $this->fromName);
            
            // HTML format
            $this->mailer->isHTML(true);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Email configuration error: " . $e->getMessage());
        }
    }

    public function getLastError() {
        return (string) $this->lastError;
    }

    private function buildAppUrl($path) {
        $path = ltrim($path, '/');

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');

        $basePath = trim(str_replace('\\', '/', $scriptDir));
        if ($basePath === '.' || $basePath === '/') {
            $basePath = '';
        } else {
            $basePath = rtrim($basePath, '/');
        }

        return $protocol . $host . $basePath . '/' . $path;
    }

    public function sendTestEmail($toEmail) {
        try {
            $this->mailer->Subject = '✅ Test Email - Calloway Pharmacy';
            $this->mailer->Body = $this->getEmailTemplate('test_email', [
                'toEmail' => $toEmail,
                'timeSent' => date('Y-m-d H:i:s')
            ]);
            $this->mailer->addAddress($toEmail);
            $this->mailer->send();
            $this->mailer->clearAddresses();
            return true;
        } catch (Exception $e) {
            $details = trim((string) ($this->mailer->ErrorInfo ?? ''));
            $this->lastError = $details !== '' ? $details : $e->getMessage();
            error_log("Test email error: " . $this->lastError);
            return false;
        }
    }

    public function sendReceiptEmail($toEmail, $saleReference, $totalAmount, $pdfContent, $attachmentName) {
        try {
            $this->mailer->Subject = 'Receipt #' . $saleReference . ' - Calloway Pharmacy';
            $body = '<h2>Thank you for your purchase!</h2>';
            $body .= '<p>Please find your receipt attached.</p>';
            $body .= '<p><strong>Receipt #:</strong> ' . htmlspecialchars($saleReference) . '</p>';
            $body .= '<p><strong>Total:</strong> ₱' . number_format((float) $totalAmount, 2) . '</p>';
            $this->mailer->Body = $body;
            $this->mailer->addAddress($toEmail);
            $this->mailer->addStringAttachment($pdfContent, $attachmentName);
            $this->mailer->send();
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            return true;
        } catch (Exception $e) {
            error_log("Receipt email error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send Low Stock Alert
     */
    public function sendLowStockAlert($products) {
        if (empty($products)) return false;
        
        $alertRecipients = $this->getLowStockAlertRecipients();
        if (empty($alertRecipients)) return false;
        
        try {
            $this->mailer->Subject = '⚠️ Low Stock Alert - Calloway Pharmacy';
            
            $body = $this->getEmailTemplate('low_stock', [
                'products' => $products,
                'count' => count($products)
            ]);
            
            $this->mailer->Body = $body;
            
            foreach ($alertRecipients as $email) {
                $this->mailer->addAddress($email);
            }
            
            $this->mailer->send();
            $this->mailer->clearAddresses();
            
            return true;
        } catch (Exception $e) {
            error_log("Low stock email error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get low stock alert recipients.
     * Always includes the owner email and optional configured alert email.
     */
    private function getLowStockAlertRecipients() {
        return $this->getSystemAlertRecipients();
    }

    /**
     * Get system alert recipients.
     * Always includes owner email and optional configured alert email.
     */
    private function getSystemAlertRecipients() {
        $emails = [self::OWNER_ALERT_EMAIL];

        $settingsQuery = "SELECT setting_value FROM settings WHERE setting_key = 'alert_email' LIMIT 1";
        $settingsResult = $this->conn->query($settingsQuery);
        if ($settingsResult && $settingsResult->num_rows > 0) {
            $row = $settingsResult->fetch_assoc();
            $configuredAlertEmail = trim((string)($row['setting_value'] ?? ''));
            if ($configuredAlertEmail !== '') {
                $emails[] = $configuredAlertEmail;
            }
        }

        $normalized = [];
        foreach ($emails as $email) {
            $cleanEmail = strtolower(trim((string)$email));
            if ($cleanEmail !== '' && filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                $normalized[$cleanEmail] = true;
            }
        }

        return array_keys($normalized);
    }
    
    /**
     * Send Expiry Warning
     */
    public function sendExpiryWarning($products, $days = 30) {
        if (empty($products)) return false;
        
        $recipients = $this->getSystemAlertRecipients();
        if (empty($recipients)) return false;
        
        try {
            $this->mailer->Subject = '📅 Product Expiry Warning - Calloway Pharmacy';
            
            $body = $this->getEmailTemplate('expiry_warning', [
                'products' => $products,
                'days' => $days,
                'count' => count($products)
            ]);
            
            $this->mailer->Body = $body;
            
            foreach ($recipients as $email) {
                $this->mailer->addAddress($email);
            }
            
            $this->mailer->send();
            $this->mailer->clearAddresses();
            
            return true;
        } catch (Exception $e) {
            error_log("Expiry warning email error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send Daily Sales Summary
     */
    public function sendDailySummary($date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        $recipients = $this->getSystemAlertRecipients();
        if (empty($recipients)) return false;
        
        // Get sales data
        $query = "SELECT 
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(total), 0) as total_sales,
                    AVG(total) as avg_transaction
                  FROM sales 
                  WHERE DATE(created_at) = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $salesData = $result->fetch_assoc();
        
        // Get top products
        $productNameExpr = $this->columnExists('products', 'name') ? 'p.name' : 'p.product_name';
        $query = "SELECT {$productNameExpr} AS product_name, SUM(si.quantity) as qty
              FROM sale_items si
              JOIN products p ON si.product_id = p.product_id
              JOIN sales s ON si.sale_id = s.sale_id
              WHERE DATE(s.created_at) = ?
              GROUP BY si.product_id, {$productNameExpr}
              ORDER BY qty DESC
              LIMIT 5";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $topProducts = [];
        while ($row = $result->fetch_assoc()) {
            $topProducts[] = $row;
        }
        
        try {
            $this->mailer->Subject = '📊 Daily Sales Summary - ' . date('M d, Y', strtotime($date));
            
            $body = $this->getEmailTemplate('daily_summary', [
                'date' => $date,
                'salesData' => $salesData,
                'topProducts' => $topProducts
            ]);
            
            $this->mailer->Body = $body;
            
            foreach ($recipients as $email) {
                $this->mailer->addAddress($email);
            }
            
            $this->mailer->send();
            $this->mailer->clearAddresses();
            
            return true;
        } catch (Exception $e) {
            error_log("Daily summary email error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send Password Reset Email
     */
    public function sendPasswordReset($email, $username, $resetToken) {
        $this->lastError = '';
        $resetLink = $this->buildAppUrl('reset_password.php?token=' . urlencode($resetToken));
        $body = $this->getEmailTemplate('password_reset', [
            'username' => $username,
            'resetLink' => $resetLink
        ]);

        $this->mailer->Subject = '🔐 Password Reset Request - Calloway Pharmacy';
        $this->mailer->Body = $body;

        try {
            $this->mailer->addAddress($email, $username);
            
            $this->mailer->send();
            $this->mailer->clearAddresses();
            
            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Password reset email primary send error: " . $e->getMessage());

            if (stripos((string) $this->mailer->Host, 'gmail.com') !== false) {
                try {
                    $this->mailer->clearAddresses();

                    $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $this->mailer->SMTPAutoTLS = false;
                    $this->mailer->Port = 465;

                    $this->mailer->addAddress($email, $username);
                    $this->mailer->send();
                    $this->mailer->clearAddresses();
                    return true;
                } catch (Exception $fallbackError) {
                    $this->lastError = $fallbackError->getMessage();
                    error_log("Password reset email fallback send error: " . $fallbackError->getMessage());
                }
            }

            return false;
        }
    }
    
    /**
     * Send Welcome Email
     */
    public function sendWelcomeEmail($email, $username, $fullName, $tempPassword) {
        try {
            $this->mailer->Subject = '👋 Welcome to Calloway Pharmacy';
            
            $body = $this->getEmailTemplate('welcome', [
                'username' => $username,
                'fullName' => $fullName,
                'tempPassword' => $tempPassword
            ]);
            
            $this->mailer->Body = $body;
            $this->mailer->addAddress($email, $fullName);
            
            $this->mailer->send();
            $this->mailer->clearAddresses();
            
            return true;
        } catch (Exception $e) {
            error_log("Welcome email error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get admin emails
     */
    private function getAdminEmails() {
        $emails = [];

        try {
            $query = null;

            if ($this->columnExists('users', 'role')) {
                $query = "SELECT email FROM users WHERE role = 'Admin' AND email IS NOT NULL AND email != ''";
            } elseif ($this->columnExists('users', 'role_name')) {
                $query = "SELECT email FROM users WHERE LOWER(role_name) IN ('admin', 'administrator') AND email IS NOT NULL AND email != ''";
            } elseif ($this->columnExists('users', 'role_id') && $this->tableExists('roles') && $this->columnExists('roles', 'role_name')) {
                $query = "SELECT u.email
                          FROM users u
                          INNER JOIN roles r ON r.role_id = u.role_id
                          WHERE LOWER(r.role_name) IN ('admin', 'administrator')
                          AND u.email IS NOT NULL AND u.email != ''";
            }

            if ($query !== null) {
                $result = $this->conn->query($query);
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $emails[] = $row['email'];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('Admin email lookup error: ' . $e->getMessage());
        }

        return $emails;
    }

    private function tableExists($table) {
        $stmt = $this->conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }

    private function columnExists($table, $column) {
        $stmt = $this->conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result && $result->num_rows > 0;
    }
    
    /**
     * Get email template
     */
    private function getEmailTemplate($type, $data) {
        $template = $this->getBaseTemplate();
        
        switch ($type) {
            case 'low_stock':
                $content = $this->getLowStockContent($data);
                break;
            case 'expiry_warning':
                $content = $this->getExpiryWarningContent($data);
                break;
            case 'daily_summary':
                $content = $this->getDailySummaryContent($data);
                break;
            case 'password_reset':
                $content = $this->getPasswordResetContent($data);
                break;
            case 'welcome':
                $content = $this->getWelcomeContent($data);
                break;
            case 'test_email':
                $content = $this->getTestEmailContent($data);
                break;
            case 'periodic_report':
                $content = $this->getPeriodicReportContent($data);
                break;
            default:
                $content = '';
        }
        
        return str_replace('{{CONTENT}}', $content, $template);
    }
    
    private function getBaseTemplate() {
        $year = date('Y');
        return '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>Calloway Pharmacy</title>
<!--[if mso]><style>table{border-collapse:collapse;}td,th{font-family:Arial,sans-serif;}</style><![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f0f4f8;font-family:\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;-webkit-font-smoothing:antialiased;">
<!-- Outer wrapper -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f4f8;">
<tr><td align="center" style="padding:32px 16px;">

<!-- Email card -->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

<!-- Header gradient bar -->
<tr>
<td style="background:linear-gradient(135deg,#1e40af 0%,#2563eb 50%,#3b82f6 100%);padding:0;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <tr>
    <td style="padding:32px 40px 28px;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="padding-right:14px;vertical-align:middle;">
          <div style="width:44px;height:44px;background:rgba(255,255,255,0.2);border-radius:12px;text-align:center;line-height:44px;font-size:22px;">🏥</div>
        </td>
        <td style="vertical-align:middle;">
          <span style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">Calloway Pharmacy</span>
        </td>
      </tr></table>
    </td>
  </tr>
  </table>
</td>
</tr>

<!-- Content area -->
<tr>
<td style="padding:36px 40px 32px;color:#1e293b;font-size:15px;line-height:1.7;">
{{CONTENT}}
</td>
</tr>

<!-- Divider -->
<tr>
<td style="padding:0 40px;">
  <div style="height:1px;background:linear-gradient(to right,transparent,#e2e8f0,transparent);"></div>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="padding:24px 40px 32px;text-align:center;">
  <p style="margin:0 0 4px;font-size:12px;color:#94a3b8;">&copy; ' . $year . ' Calloway Pharmacy. All rights reserved.</p>
  <p style="margin:0;font-size:11px;color:#cbd5e1;">This is an automated message — please do not reply directly.</p>
</td>
</tr>

</table>
<!-- /Email card -->

</td></tr>
</table>
<!-- /Outer wrapper -->
</body>
</html>';
    }
    
    private function getLowStockContent($data) {
        $count = (int)$data['count'];
        $html = '<div style="margin-bottom:24px;">';
        $html .= '<div style="display:inline-block;background:#fef2f2;border-radius:12px;padding:6px 16px 6px 12px;margin-bottom:12px;">';
        $html .= '<span style="font-size:14px;font-weight:600;color:#dc2626;">⚠️ LOW STOCK ALERT</span></div>';
        $html .= '<h2 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#1e293b;">' . $count . ' Product' . ($count !== 1 ? 's' : '') . ' Need Restocking</h2>';
        $html .= '<p style="margin:0;color:#64748b;font-size:14px;">These items are running dangerously low. Order from suppliers as soon as possible.</p>';
        $html .= '</div>';

        // Table
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin:0 0 20px;">';
        $html .= '<tr style="background:#f8fafc;">';
        $html .= '<th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Product</th>';
        $html .= '<th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Stock</th>';
        $html .= '<th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Reorder Lvl</th>';
        $html .= '</tr>';

        foreach ($data['products'] as $i => $p) {
            $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
            $stockVal = (int)$p['stock_quantity'];
            $stockColor = $stockVal <= 0 ? '#dc2626' : ($stockVal <= 5 ? '#ea580c' : '#d97706');
            $html .= '<tr style="background:' . $bg . ';">';
            $html .= '<td style="padding:12px 16px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">' . htmlspecialchars($p['product_name']) . '</td>';
            $html .= '<td style="padding:12px 16px;text-align:center;border-bottom:1px solid #f1f5f9;"><span style="display:inline-block;background:' . $stockColor . ';color:#fff;font-size:13px;font-weight:700;padding:3px 12px;border-radius:20px;">' . $stockVal . '</span></td>';
            $html .= '<td style="padding:12px 16px;text-align:center;font-size:14px;color:#64748b;border-bottom:1px solid #f1f5f9;">' . (int)($p['reorder_level'] ?? 20) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<p style="margin:0;font-size:13px;color:#94a3b8;">💡 Tip: Review your reorder levels in Inventory Management to prevent future stockouts.</p>';
        return $html;
    }
    
    private function getExpiryWarningContent($data) {
        $count = (int)$data['count'];
        $days = (int)$data['days'];
        $html = '<div style="margin-bottom:24px;">';
        $html .= '<div style="display:inline-block;background:#fffbeb;border-radius:12px;padding:6px 16px 6px 12px;margin-bottom:12px;">';
        $html .= '<span style="font-size:14px;font-weight:600;color:#d97706;">📅 EXPIRY WARNING</span></div>';
        $html .= '<h2 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#1e293b;">' . $count . ' Product' . ($count !== 1 ? 's' : '') . ' Expiring Soon</h2>';
        $html .= '<p style="margin:0;color:#64748b;font-size:14px;">These items will expire within the next <strong style="color:#d97706;">' . $days . ' days</strong>. Consider discounting, returning, or disposing of them.</p>';
        $html .= '</div>';

        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin:0 0 20px;">';
        $html .= '<tr style="background:#f8fafc;">';
        $html .= '<th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Product</th>';
        $html .= '<th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Batch</th>';
        $html .= '<th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Expiry Date</th>';
        $html .= '<th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Stock</th>';
        $html .= '</tr>';

        foreach ($data['products'] as $i => $p) {
            $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
            $expDate = date('M d, Y', strtotime($p['expiry_date']));
            $daysLeft = (int)((strtotime($p['expiry_date']) - time()) / 86400);
            $urgency = $daysLeft <= 7 ? '#dc2626' : ($daysLeft <= 14 ? '#ea580c' : '#d97706');
            $html .= '<tr style="background:' . $bg . ';">';
            $html .= '<td style="padding:12px 16px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">' . htmlspecialchars($p['product_name']) . '</td>';
            $html .= '<td style="padding:12px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">' . htmlspecialchars($p['batch_number'] ?? 'N/A') . '</td>';
            $html .= '<td style="padding:12px 16px;text-align:center;border-bottom:1px solid #f1f5f9;"><span style="font-size:13px;font-weight:600;color:' . $urgency . ';">' . $expDate . '</span></td>';
            $html .= '<td style="padding:12px 16px;text-align:center;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">' . (int)$p['stock_quantity'] . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<p style="margin:0;font-size:13px;color:#94a3b8;">💡 Tip: Set up automatic expiry enforcement in Inventory Settings to handle near-expiry products automatically.</p>';
        return $html;
    }
    
    private function getDailySummaryContent($data) {
        $dateStr = date('F d, Y', strtotime($data['date']));
        $txn = (int)($data['salesData']['transaction_count'] ?? 0);
        $total = (float)($data['salesData']['total_sales'] ?? 0);
        $avg = (float)($data['salesData']['avg_transaction'] ?? 0);

        $html = '<div style="margin-bottom:24px;">';
        $html .= '<div style="display:inline-block;background:#eff6ff;border-radius:12px;padding:6px 16px 6px 12px;margin-bottom:12px;">';
        $html .= '<span style="font-size:14px;font-weight:600;color:#2563eb;">📊 DAILY SUMMARY</span></div>';
        $html .= '<h2 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#1e293b;">Sales Report — ' . $dateStr . '</h2>';
        $html .= '</div>';

        // KPI Cards row
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;"><tr>';
        // Transactions
        $html .= '<td width="33%" style="padding:0 6px 0 0;">';
        $html .= '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px;text-align:center;">';
        $html .= '<div style="font-size:28px;font-weight:800;color:#16a34a;">' . $txn . '</div>';
        $html .= '<div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Transactions</div>';
        $html .= '</div></td>';
        // Total Sales
        $html .= '<td width="34%" style="padding:0 3px;">';
        $html .= '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:16px;text-align:center;">';
        $html .= '<div style="font-size:28px;font-weight:800;color:#2563eb;">&#8369;' . number_format($total, 2) . '</div>';
        $html .= '<div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Total Sales</div>';
        $html .= '</div></td>';
        // Avg Transaction
        $html .= '<td width="33%" style="padding:0 0 0 6px;">';
        $html .= '<div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;padding:16px;text-align:center;">';
        $html .= '<div style="font-size:28px;font-weight:800;color:#7c3aed;">&#8369;' . number_format($avg, 2) . '</div>';
        $html .= '<div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Avg Transaction</div>';
        $html .= '</div></td>';
        $html .= '</tr></table>';

        if (!empty($data['topProducts'])) {
            $html .= '<h3 style="margin:0 0 12px;font-size:16px;font-weight:700;color:#1e293b;">🏆 Top Products</h3>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">';
            $html .= '<tr style="background:#f8fafc;">';
            $html .= '<th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">#</th>';
            $html .= '<th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Product</th>';
            $html .= '<th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Units Sold</th>';
            $html .= '</tr>';
            $rank = 1;
            foreach ($data['topProducts'] as $i => $p) {
                $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
                $medal = $rank === 1 ? '🥇 ' : ($rank === 2 ? '🥈 ' : ($rank === 3 ? '🥉 ' : ''));
                $html .= '<tr style="background:' . $bg . ';">';
                $html .= '<td style="padding:10px 16px;font-size:14px;color:#64748b;border-bottom:1px solid #f1f5f9;">' . $medal . $rank . '</td>';
                $html .= '<td style="padding:10px 16px;font-size:14px;font-weight:600;color:#334155;border-bottom:1px solid #f1f5f9;">' . htmlspecialchars($p['product_name']) . '</td>';
                $html .= '<td style="padding:10px 16px;text-align:center;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">' . (int)$p['qty'] . '</td>';
                $html .= '</tr>';
                $rank++;
            }
            $html .= '</table>';
        }

        return $html;
    }
    
    private function getPasswordResetContent($data) {
        $html = '<div style="text-align:center;margin-bottom:28px;">';
        $html .= '<div style="display:inline-block;width:64px;height:64px;background:linear-gradient(135deg,#2563eb,#7c3aed);border-radius:50%;line-height:64px;font-size:28px;margin-bottom:16px;">🔐</div>';
        $html .= '<h2 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#1e293b;">Password Reset Request</h2>';
        $html .= '<p style="margin:0;color:#64748b;font-size:14px;">We received a request to reset your password.</p>';
        $html .= '</div>';

        $html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:24px;">';
        $html .= '<p style="margin:0 0 4px;font-size:13px;color:#64748b;">Account</p>';
        $html .= '<p style="margin:0;font-size:16px;font-weight:700;color:#1e293b;">' . htmlspecialchars($data['username']) . '</p>';
        $html .= '</div>';

        $html .= '<div style="text-align:center;margin-bottom:24px;">';
        $html .= '<a href="' . htmlspecialchars($data['resetLink']) . '" style="display:inline-block;padding:14px 40px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#ffffff;text-decoration:none;font-weight:700;font-size:16px;border-radius:12px;box-shadow:0 4px 12px rgba(37,99,235,0.3);">Reset My Password</a>';
        $html .= '</div>';

        $html .= '<p style="font-size:13px;color:#94a3b8;text-align:center;margin:0 0 8px;">If the button doesn\'t work, copy and paste this link:</p>';
        $html .= '<div style="background:#f1f5f9;border-radius:8px;padding:12px;word-break:break-all;margin-bottom:24px;">';
        $html .= '<a href="' . htmlspecialchars($data['resetLink']) . '" style="font-size:12px;color:#2563eb;text-decoration:none;">' . htmlspecialchars($data['resetLink']) . '</a>';
        $html .= '</div>';

        $html .= '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 16px;">';
        $html .= '<p style="margin:0;font-size:13px;color:#dc2626;"><strong>⏰ This link expires in 1 hour.</strong></p>';
        $html .= '<p style="margin:4px 0 0;font-size:13px;color:#64748b;">If you didn\'t request this reset, you can safely ignore this email.</p>';
        $html .= '</div>';

        return $html;
    }
    
    private function getWelcomeContent($data) {
        $html = '<div style="text-align:center;margin-bottom:28px;">';
        $html .= '<div style="display:inline-block;width:64px;height:64px;background:linear-gradient(135deg,#16a34a,#22c55e);border-radius:50%;line-height:64px;font-size:28px;margin-bottom:16px;">👋</div>';
        $html .= '<h2 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#1e293b;">Welcome to Calloway Pharmacy!</h2>';
        $html .= '<p style="margin:0;color:#64748b;font-size:14px;">Hello <strong style="color:#1e293b;">' . htmlspecialchars($data['fullName']) . '</strong>, your account is ready.</p>';
        $html .= '</div>';

        $html .= '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:20px;margin-bottom:24px;">';
        $html .= '<p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#16a34a;text-transform:uppercase;letter-spacing:0.05em;">YOUR LOGIN CREDENTIALS</p>';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">';
        $html .= '<tr><td style="padding:8px 0;font-size:13px;color:#64748b;width:140px;">Username</td>';
        $html .= '<td style="padding:8px 0;font-size:15px;font-weight:700;color:#1e293b;">' . htmlspecialchars($data['username']) . '</td></tr>';
        $html .= '<tr><td style="padding:8px 0;font-size:13px;color:#64748b;">Temporary Password</td>';
        $html .= '<td style="padding:8px 0;"><span style="display:inline-block;background:#dcfce7;padding:4px 14px;border-radius:8px;font-size:15px;font-weight:700;font-family:monospace;color:#15803d;letter-spacing:0.5px;">' . htmlspecialchars($data['tempPassword']) . '</span></td></tr>';
        $html .= '</table></div>';

        $html .= '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;margin-bottom:24px;">';
        $html .= '<p style="margin:0;font-size:13px;color:#92400e;">🔒 <strong>Important:</strong> Please change your password after your first login for security.</p>';
        $html .= '</div>';

        $html .= '<div style="text-align:center;">';
        $html .= '<a href="' . htmlspecialchars($this->buildAppUrl('login.php')) . '" style="display:inline-block;padding:14px 40px;background:linear-gradient(135deg,#16a34a,#15803d);color:#ffffff;text-decoration:none;font-weight:700;font-size:16px;border-radius:12px;box-shadow:0 4px 12px rgba(22,163,74,0.3);">Login Now</a>';
        $html .= '</div>';

        return $html;
    }

    private function getTestEmailContent($data) {
        $html = '<div style="text-align:center;margin-bottom:28px;">';
        $html .= '<div style="display:inline-block;width:64px;height:64px;background:linear-gradient(135deg,#16a34a,#22c55e);border-radius:50%;line-height:64px;font-size:28px;margin-bottom:16px;">✅</div>';
        $html .= '<h2 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#1e293b;">SMTP Test Successful</h2>';
        $html .= '<p style="margin:0;color:#64748b;font-size:14px;">Your email settings are configured correctly.</p>';
        $html .= '</div>';

        $html .= '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:20px;margin-bottom:20px;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
        $html .= '<tr><td style="padding:8px 0;font-size:13px;color:#64748b;width:120px;">Recipient</td>';
        $html .= '<td style="padding:8px 0;font-size:14px;font-weight:600;color:#1e293b;">' . htmlspecialchars($data['toEmail']) . '</td></tr>';
        $html .= '<tr><td style="padding:8px 0;font-size:13px;color:#64748b;">Sent At</td>';
        $html .= '<td style="padding:8px 0;font-size:14px;font-weight:600;color:#1e293b;">' . htmlspecialchars($data['timeSent']) . '</td></tr>';
        $html .= '</table></div>';

        $html .= '<p style="margin:0;font-size:13px;color:#94a3b8;text-align:center;">All email features (alerts, reports, password resets) are now operational.</p>';
        return $html;
    }

    private function getPeriodicReportContent($data) {
        $freq = htmlspecialchars($data['frequency']);
        $period = htmlspecialchars($data['periodStr']);
        $sales = $data['salesData'];
        $topProducts = $data['topProducts'];
        $txn = (int)($sales['transaction_count'] ?? 0);
        $total = (float)($sales['total_sales'] ?? 0);
        $avg = (float)($sales['avg_transaction'] ?? 0);

        $html = '<div style="margin-bottom:24px;">';
        $html .= '<div style="display:inline-block;background:#eff6ff;border-radius:12px;padding:6px 16px 6px 12px;margin-bottom:12px;">';
        $html .= '<span style="font-size:14px;font-weight:600;color:#2563eb;">📊 ' . strtoupper($freq) . ' REPORT</span></div>';
        $html .= '<h2 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#1e293b;">' . $freq . ' Store Report</h2>';
        $html .= '<p style="margin:0;color:#64748b;font-size:14px;">Performance summary for <strong style="color:#1e293b;">' . $period . '</strong></p>';
        $html .= '</div>';

        // KPI Cards row
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;"><tr>';
        $html .= '<td width="33%" style="padding:0 6px 0 0;">';
        $html .= '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px;text-align:center;">';
        $html .= '<div style="font-size:28px;font-weight:800;color:#16a34a;">' . $txn . '</div>';
        $html .= '<div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Transactions</div>';
        $html .= '</div></td>';
        $html .= '<td width="34%" style="padding:0 3px;">';
        $html .= '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:16px;text-align:center;">';
        $html .= '<div style="font-size:28px;font-weight:800;color:#2563eb;">&#8369;' . number_format($total, 2) . '</div>';
        $html .= '<div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Total Sales</div>';
        $html .= '</div></td>';
        $html .= '<td width="33%" style="padding:0 0 0 6px;">';
        $html .= '<div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;padding:16px;text-align:center;">';
        $html .= '<div style="font-size:28px;font-weight:800;color:#7c3aed;">&#8369;' . number_format($avg, 2) . '</div>';
        $html .= '<div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Avg Transaction</div>';
        $html .= '</div></td>';
        $html .= '</tr></table>';

        // Inventory health alerts
        $lowStock = (int)($data['lowStockCount'] ?? 0);
        $expiring = (int)($data['expiringCount'] ?? 0);
        if ($lowStock > 0 || $expiring > 0) {
            $html .= '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:16px 20px;margin-bottom:24px;">';
            $html .= '<p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#92400e;">⚡ Inventory Alerts</p>';
            if ($lowStock > 0) {
                $html .= '<p style="margin:0 0 4px;font-size:14px;color:#92400e;">⚠️ <strong>' . $lowStock . '</strong> product' . ($lowStock !== 1 ? 's' : '') . ' running low on stock</p>';
            }
            if ($expiring > 0) {
                $html .= '<p style="margin:0;font-size:14px;color:#92400e;">📅 <strong>' . $expiring . '</strong> product' . ($expiring !== 1 ? 's' : '') . ' expiring within 30 days</p>';
            }
            $html .= '</div>';
        }

        // Top products
        if (!empty($topProducts)) {
            $html .= '<h3 style="margin:0 0 12px;font-size:16px;font-weight:700;color:#1e293b;">🏆 Top Products</h3>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin:0 0 20px;">';
            $html .= '<tr style="background:#f8fafc;">';
            $html .= '<th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">#</th>';
            $html .= '<th style="padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Product</th>';
            $html .= '<th style="padding:12px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Sold</th>';
            $html .= '<th style="padding:12px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;">Revenue</th>';
            $html .= '</tr>';
            $rank = 1;
            foreach ($topProducts as $i => $p) {
                $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
                $medal = $rank === 1 ? '🥇 ' : ($rank === 2 ? '🥈 ' : ($rank === 3 ? '🥉 ' : ''));
                $html .= '<tr style="background:' . $bg . ';">';
                $html .= '<td style="padding:10px 16px;font-size:14px;color:#64748b;border-bottom:1px solid #f1f5f9;">' . $medal . $rank . '</td>';
                $html .= '<td style="padding:10px 16px;font-size:14px;font-weight:600;color:#334155;border-bottom:1px solid #f1f5f9;">' . htmlspecialchars($p['product_name']) . '</td>';
                $html .= '<td style="padding:10px 16px;text-align:center;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">' . (int)$p['qty'] . '</td>';
                $html .= '<td style="padding:10px 16px;text-align:right;font-size:14px;font-weight:600;color:#1e293b;border-bottom:1px solid #f1f5f9;">&#8369;' . number_format((float)($p['revenue'] ?? 0), 2) . '</td>';
                $html .= '</tr>';
                $rank++;
            }
            $html .= '</table>';
        } else {
            $html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:24px;text-align:center;margin-bottom:20px;">';
            $html .= '<p style="margin:0;font-size:14px;color:#94a3b8;">No product sales recorded in this period.</p>';
            $html .= '</div>';
        }

        $html .= '<p style="margin:20px 0 0;font-size:13px;color:#94a3b8;text-align:center;">';
        $html .= '⚙️ You can change report frequency or disable report emails in <strong>System Settings → Alerts</strong>.';
        $html .= '</p>';

        return $html;
    }

    /**
     * Send periodic sales report (daily / weekly / monthly).
     * Called by email_cron.php based on report_frequency setting.
     */
    public function sendPeriodicReport($startDate, $endDate, $frequency = 'daily') {
        $recipients = $this->getSystemAlertRecipients();
        if (empty($recipients)) return false;

        $freqLabel = ucfirst($frequency);

        // Sales summary for the period
        $query = "SELECT
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(total), 0) as total_sales,
                    COALESCE(AVG(total), 0) as avg_transaction
                  FROM sales
                  WHERE DATE(created_at) BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $salesData = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Top products for the period
        $productNameExpr = $this->columnExists('products', 'name') ? 'p.name' : 'p.product_name';
        $query = "SELECT {$productNameExpr} AS product_name, SUM(si.quantity) as qty,
                         SUM(si.line_total) as revenue
                  FROM sale_items si
                  JOIN products p ON si.product_id = p.product_id
                  JOIN sales s ON si.sale_id = s.sale_id
                  WHERE DATE(s.created_at) BETWEEN ? AND ?
                  GROUP BY si.product_id, {$productNameExpr}
                  ORDER BY revenue DESC
                  LIMIT 10";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $topProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Low stock count
        $lowStockCount = 0;
        if ($this->columnExists('products', 'reorder_level')) {
            $res = $this->conn->query("SELECT COUNT(*) as cnt FROM products WHERE is_active = 1 AND stock_quantity <= reorder_level");
            if ($res) $lowStockCount = (int)$res->fetch_assoc()['cnt'];
        }

        // Expiring count (30 days)
        $expiringCount = 0;
        if ($this->columnExists('products', 'expiry_date')) {
            $res = $this->conn->query("SELECT COUNT(*) as cnt FROM products WHERE is_active = 1 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
            if ($res) $expiringCount = (int)$res->fetch_assoc()['cnt'];
        }

        try {
            $periodStr = ($startDate === $endDate)
                ? date('F d, Y', strtotime($startDate))
                : date('M d', strtotime($startDate)) . ' – ' . date('M d, Y', strtotime($endDate));

            $this->mailer->Subject = "📊 {$freqLabel} Store Report – {$periodStr}";

            $body = $this->getEmailTemplate('periodic_report', [
                'frequency'     => $freqLabel,
                'startDate'     => $startDate,
                'endDate'       => $endDate,
                'periodStr'     => $periodStr,
                'salesData'     => $salesData,
                'topProducts'   => $topProducts,
                'lowStockCount' => $lowStockCount,
                'expiringCount' => $expiringCount,
            ]);

            $this->mailer->Body = $body;
            foreach ($recipients as $email) {
                $this->mailer->addAddress($email);
            }
            $this->mailer->send();
            $this->mailer->clearAddresses();
            return true;
        } catch (Exception $e) {
            error_log("{$freqLabel} report email error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a custom HTML email to any recipient
     */
    public function sendCustomEmail($toEmail, $subject, $htmlBody) {
        try {
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $htmlBody;
            $this->mailer->addAddress($toEmail);
            $this->mailer->send();
            $this->mailer->clearAddresses();
            return true;
        } catch (Exception $e) {
            $details = trim((string) ($this->mailer->ErrorInfo ?? ''));
            $this->lastError = $details !== '' ? $details : $e->getMessage();
            error_log("Custom email error: " . $this->lastError);
            return false;
        }
    }
}
