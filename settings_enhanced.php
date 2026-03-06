<?php
/**
 * Enhanced System Settings
 * Comprehensive configuration for all system aspects
 */

require_once 'db_connection.php';
require_once 'Auth.php';

$auth = new Auth($conn);
$auth->requireAuth('login.php');

if (!$auth->hasPermission('settings.view')) {
    die('<h1>Access Denied</h1><p>You do not have permission to access settings.</p>');
}

$currentUser = $auth->getCurrentUser();
$page_title = 'System Settings';

// Load all settings
$settings_query = "SELECT setting_key, setting_value, category FROM settings";
$settings_result = $conn->query($settings_query);
$settings = [];
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <script>
    // Apply theme immediately to prevent flash
    (function() {
      const theme = localStorage.getItem('calloway_theme') || 'light';
      document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Calloway Pharmacy</title>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="shared-polish.css">
    <link rel="stylesheet" href="polish.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="custom-modal.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="custom-modal.js?v=2"></script>
    <style>
        .settings-container {
            max-width: 1200px;
            margin: 80px auto 2rem;
            padding: 0 1.5rem 2rem;
        }
        .settings-header {
            background: var(--primary-color);
            border-radius: 16px;
            padding: 2rem 2.5rem;
            margin-bottom: 1.75rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .settings-header::before {
            content: '';
            position: absolute;
            top: -60%; right: -10%;
            width: 280px; height: 280px;
            background: rgba(255,255,255,0.07);
            border-radius: 50%;
            pointer-events: none;
        }
        .settings-header::after {
            content: '';
            position: absolute;
            bottom: -40%; left: 10%;
            width: 180px; height: 180px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
            pointer-events: none;
        }
        .settings-header h1 {
            margin: 0 0 0.3rem;
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            position: relative;
        }
        .settings-header h1 i { margin-right: 0.35rem; }
        .settings-header p {
            margin: 0;
            opacity: 0.85;
            font-size: 0.925rem;
            position: relative;
        }
        .settings-tabs {
            display: flex;
            gap: 0.35rem;
            margin-bottom: 1.75rem;
            padding: 0.375rem;
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--input-border);
            overflow-x: auto;
            scrollbar-width: thin;
        }
        .settings-tabs::-webkit-scrollbar { height: 4px; }
        .settings-tabs::-webkit-scrollbar-thumb { background: var(--input-border); border-radius: 4px; }
        
        .tab-button {
            padding: 0.6rem 1.1rem;
            background: transparent;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-light);
            transition: all 0.2s ease;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .tab-button:hover {
            background: var(--hover-bg);
            color: var(--text-color);
        }
        .tab-button.active {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 2px 10px rgba(var(--primary-rgb), 0.3);
        }
        .tab-button i { font-size: 0.95rem; width: 18px; text-align: center; }
        .tab-content { display: none; }
        .tab-content.active {
            display: block;
            animation: settingsFadeUp 0.35s ease both;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 1.5rem;
        }
        .settings-card {
            background: var(--card-bg);
            padding: 1.75rem 2rem;
            border-radius: 14px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--input-border);
            transition: box-shadow 0.3s ease, transform 0.3s ease;
        }
        .settings-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        .settings-card h3 {
            margin: 0 0 1.5rem;
            font-size: 1.05rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--input-border);
            color: var(--text-color);
        }
        .settings-card h3 .card-icon {
            width: 34px; height: 34px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: white;
            flex-shrink: 0;
        }
        .card-icon.blue { background: #2563eb; }
        .card-icon.green { background: #059669; }
        .card-icon.purple { background: #7c3aed; }
        .card-icon.amber { background: #d97706; }
        .card-icon.red { background: #dc2626; }
        .card-icon.teal { background: #0d9488; }
        .card-icon.slate { background: #475569; }
        
        .form-group {
            margin-bottom: 1.35rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-color);
        }
        .form-group small {
            display: block;
            margin-top: 0.3rem;
            color: var(--text-light);
            font-size: 0.8rem;
            line-height: 1.4;
        }
        .form-group input[type="text"],
        .form-group input[type="tel"],
        .form-group input[type="email"],
        .form-group input[type="url"],
        .form-group input[type="number"],
        .form-group input[type="password"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1.5px solid var(--input-border);
            border-radius: 8px;
            font-size: 0.925rem;
            font-family: inherit;
            background: var(--bg-color);
            color: var(--text-color);
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.12);
        }
        .form-group textarea { resize: vertical; }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        /* Toggle Switch */
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            gap: 1rem;
        }
        .toggle-row .toggle-info .toggle-label {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-color);
        }
        .toggle-row .toggle-info small {
            display: block;
            color: var(--text-light);
            font-size: 0.8rem;
            margin-top: 0.15rem;
        }
        .toggle-switch {
            position: relative;
            width: 44px; height: 24px;
            flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
        .toggle-switch .slider {
            position: absolute; inset: 0;
            background: var(--input-border);
            border-radius: 24px;
            cursor: pointer;
            transition: background 0.25s;
        }
        .toggle-switch .slider::before {
            content: '';
            position: absolute;
            width: 18px; height: 18px;
            left: 3px; top: 3px;
            background: white;
            border-radius: 50%;
            transition: transform 0.25s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .toggle-switch input:checked + .slider { background: var(--primary-color); }
        .toggle-switch input:checked + .slider::before { transform: translateX(20px); }
        
        .btn {
            padding: 0.6rem 1.25rem;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: inherit;
            line-height: 1.4;
        }
        .btn:active { transform: scale(0.97); }
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(var(--primary-rgb), 0.35);
        }
        .btn-secondary {
            background: var(--hover-bg);
            color: var(--text-color);
            border: 1.5px solid var(--input-border);
        }
        .btn-secondary:hover { background: var(--input-border); }
        .btn-success {
            background: var(--secondary-color);
            color: white;
        }
        .btn-success:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(16,185,129,0.35);
        }
        .btn-danger {
            background: var(--danger-color);
            color: white;
        }
        .btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(239,68,68,0.35);
        }
        .btn.saving { pointer-events: none; opacity: 0.7; }
        
        .backup-list {
            max-height: 280px;
            overflow-y: auto;
            border: 1.5px solid var(--input-border);
            border-radius: 10px;
            padding: 0.75rem;
        }
        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.65rem 0.75rem;
            margin-bottom: 0.4rem;
            background: var(--bg-color);
            border-radius: 8px;
            font-size: 0.875rem;
            transition: background 0.2s;
        }
        .backup-item:hover { background: var(--hover-bg); }
        .backup-item:last-child { margin-bottom: 0; }
        .info-box {
            background: rgba(var(--primary-rgb), 0.06);
            border: 1.5px solid rgba(var(--primary-rgb), 0.2);
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.5;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }
        .info-box i {
            color: var(--primary-color);
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .success-box {
            background: rgba(16, 185, 129, 0.08);
            border: 1.5px solid var(--secondary-color);
            border-radius: 8px;
            padding: 0.85rem 1rem;
            margin-top: 1rem;
            display: none;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .success-box.show {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: settingsFadeUp 0.3s ease;
        }
        /* Settings Toast */
        .settings-toast {
            position: fixed;
            top: 80px; right: 1.5rem;
            background: var(--card-bg);
            border: 1px solid var(--input-border);
            border-left: 4px solid var(--secondary-color);
            border-radius: 10px;
            padding: 0.85rem 1.25rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 1100;
            transform: translateX(calc(100% + 2rem));
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.9rem;
            font-weight: 600;
            max-width: 360px;
        }
        .settings-toast.show { transform: translateX(0); }
        .settings-toast .toast-check { color: var(--secondary-color); font-size: 1.15rem; }
        /* System Info Tiles */
        .sys-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .sys-info-tile {
            background: var(--bg-color);
            border: 1px solid var(--input-border);
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            transition: border-color 0.2s;
        }
        .sys-info-tile:hover { border-color: var(--primary-color); }
        .sys-info-tile .tile-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-light);
            margin-bottom: 0.35rem;
        }
        .sys-info-tile .tile-value {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-color);
            word-break: break-all;
        }
        /* Theme Picker */
        .theme-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 0.75rem;
        }
        .theme-option {
            padding: 1.5rem 1rem;
            border: 2px solid var(--input-border);
            border-radius: 12px;
            cursor: pointer;
            text-align: center;
            transition: all 0.25s;
            position: relative;
        }
        .theme-option:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }
        .theme-option.selected {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.15);
        }
        .theme-option .theme-icon { font-size: 2rem; margin-bottom: 0.5rem; }
        .theme-option .theme-name { font-weight: 700; font-size: 0.9rem; }
        .theme-option .theme-check {
            position: absolute;
            top: 8px; right: 8px;
            width: 22px; height: 22px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
        }
        .theme-option.selected .theme-check { display: flex; }
        .theme-option.light-opt { background: #ffffff; color: #1e293b; }
        .theme-option.dark-opt { background: #1e293b; color: #f1f5f9; }
        /* Animations */
        @keyframes settingsFadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* Responsive */
        @media (max-width: 768px) {
            .settings-container { padding: 0 1rem 2rem; }
            .settings-header { padding: 1.5rem 1.25rem; border-radius: 12px; }
            .settings-header h1 { font-size: 1.35rem; }
            .settings-grid { grid-template-columns: 1fr; }
            .settings-card { padding: 1.25rem; }
            .tab-button { padding: 0.5rem 0.85rem; font-size: 0.8rem; }
            .tab-button span { display: none; }
            .sys-info-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'header-component.php'; ?>
    
    <div class="settings-container">
        <div class="settings-header">
            <h1><i class="fa-solid fa-gear"></i> System Settings</h1>
            <p>Configure all aspects of your pharmacy management system</p>
        </div>
        
        <!-- Settings Tabs -->
        <div class="settings-tabs">
            <button class="tab-button active" onclick="showTab('company', this)">
                <i class="fa-solid fa-building"></i> <span>Company</span>
            </button>
            <button class="tab-button" onclick="showTab('tax', this)">
                <i class="fa-solid fa-percent"></i> <span>Tax & Currency</span>
            </button>
            <button class="tab-button" onclick="showTab('email', this)">
                <i class="fa-solid fa-envelope"></i> <span>Email</span>
            </button>
            <button class="tab-button" onclick="showTab('receipt', this)">
                <i class="fa-solid fa-receipt"></i> <span>Receipts</span>
            </button>
            <button class="tab-button" onclick="showTab('alerts', this)">
                <i class="fa-solid fa-bell"></i> <span>Alerts</span>
            </button>
            <button class="tab-button" onclick="showTab('backup', this)">
                <i class="fa-solid fa-database"></i> <span>Backup</span>
            </button>
            <button class="tab-button" onclick="showTab('system', this)">
                <i class="fa-solid fa-server"></i> <span>System</span>
            </button>
        </div>
        
        <!-- Company Information Tab -->
        <div id="company-tab" class="tab-content active">
            <div class="settings-card">
                <h3><span class="card-icon blue"><i class="fa-solid fa-building"></i></span> Company Information</h3>
                <form id="companyForm" onsubmit="saveCompanySettings(event)">
                    <div class="form-group">
                        <label for="company_name">Company Name *</label>
                        <input type="text" id="company_name" name="company_name" 
                               value="<?php echo htmlspecialchars($settings['company_name'] ?? 'Calloway Pharmacy'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="company_address">Address</label>
                        <textarea id="company_address" name="company_address" rows="3"><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></textarea>
                        <small>Full business address for receipts and documents</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="company_phone">Phone Number</label>
                            <input type="tel" id="company_phone" name="company_phone" 
                                   value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="company_email">Email</label>
                            <input type="email" id="company_email" name="company_email" 
                                   value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="company_website">Website</label>
                        <input type="url" id="company_website" name="company_website" 
                               value="<?php echo htmlspecialchars($settings['company_website'] ?? ''); ?>" 
                               placeholder="https://www.example.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="company_logo">Logo URL</label>
                        <input type="text" id="company_logo" name="company_logo" 
                               value="<?php echo htmlspecialchars($settings['company_logo'] ?? ''); ?>" 
                               placeholder="logo.png">
                        <small>Path to your company logo image</small>
                    </div>
                    
                    <?php if ($auth->hasPermission('settings.edit')): ?>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Company Info</button>
                    <?php endif; ?>
                    
                    <div class="success-box" id="company-success">
                        ✅ Company information saved successfully!
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tax & Currency Tab -->
        <div id="tax-tab" class="tab-content">
            <div class="settings-grid">
                <div class="settings-card">
                    <h3><span class="card-icon green"><i class="fa-solid fa-percent"></i></span> Tax Configuration</h3>
                    <form id="taxForm" onsubmit="saveTaxSettings(event)">
                        <div class="form-group">
                            <label for="tax_rate">Tax Rate (%)</label>
                            <input type="number" id="tax_rate" name="tax_rate" step="0.01" min="0" max="100"
                                   value="<?php echo htmlspecialchars($settings['tax_rate'] ?? '12.00'); ?>">
                            <small>Default tax rate applied to all sales</small>
                        </div>
                        
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Enable Tax Calculation</div>
                                <small>Turn off to disable tax on all transactions</small>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="enable_tax" name="enable_tax" 
                                       <?php echo ($settings['enable_tax'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <?php if ($auth->hasPermission('settings.edit')): ?>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Tax Settings</button>
                        <?php endif; ?>
                        
                        <div class="success-box" id="tax-success">
                            ✅ Tax settings saved successfully!
                        </div>
                    </form>
                </div>
                
                <div class="settings-card">
                    <h3><span class="card-icon amber"><i class="fa-solid fa-coins"></i></span> Currency Settings</h3>
                    <form id="currencyForm" onsubmit="saveCurrencySettings(event)">
                        <div class="form-group">
                            <label for="currency">Currency Code</label>
                            <select id="currency" name="currency">
                                <option value="PHP" <?php echo ($settings['currency'] ?? 'PHP') == 'PHP' ? 'selected' : ''; ?>>PHP - Philippine Peso</option>
                                <option value="USD" <?php echo ($settings['currency'] ?? '') == 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                <option value="EUR" <?php echo ($settings['currency'] ?? '') == 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                <option value="GBP" <?php echo ($settings['currency'] ?? '') == 'GBP' ? 'selected' : ''; ?>>GBP - British Pound</option>
                                <option value="JPY" <?php echo ($settings['currency'] ?? '') == 'JPY' ? 'selected' : ''; ?>>JPY - Japanese Yen</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="currency_symbol">Currency Symbol</label>
                            <input type="text" id="currency_symbol" name="currency_symbol" 
                                   value="<?php echo htmlspecialchars($settings['currency_symbol'] ?? '₱'); ?>">
                            <small>Symbol displayed on prices (e.g., ₱, $, €)</small>
                        </div>
                        
                        <?php if ($auth->hasPermission('settings.edit')): ?>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Currency</button>
                        <?php endif; ?>
                        
                        <div class="success-box" id="currency-success">
                            ✅ Currency settings saved successfully!
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Email Server Tab -->
        <div id="email-tab" class="tab-content">
            <div class="settings-card">
                <h3><span class="card-icon purple"><i class="fa-solid fa-envelope"></i></span> Email Server Configuration</h3>
                <div class="info-box">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        <strong>SMTP Configuration</strong><br>
                        Configure your email server settings to send automated emails for alerts, reports, and notifications.
                    </div>
                </div>
                
                <form id="emailForm" onsubmit="saveEmailSettings(event)">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email_host">SMTP Host *</label>
                            <input type="text" id="email_host" name="email_host" 
                                   value="<?php echo htmlspecialchars($settings['email_host'] ?? ''); ?>" 
                                   placeholder="smtp.gmail.com">
                        </div>
                        
                        <div class="form-group">
                            <label for="email_port">SMTP Port *</label>
                            <input type="number" id="email_port" name="email_port" 
                                   value="465" readonly>
                            <small>SSL mode uses port 465.</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email_username">Username/Email *</label>
                            <input type="text" id="email_username" name="email_username" 
                                   value="<?php echo htmlspecialchars($settings['email_username'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="email_password">Password *</label>
                            <input type="password" id="email_password" name="email_password" 
                                   placeholder="Enter password">
                            <small>Password is encrypted before storage</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email_from_name">From Name</label>
                            <input type="text" id="email_from_name" name="email_from_name" 
                                   value="<?php echo htmlspecialchars($settings['email_from_name'] ?? 'Calloway Pharmacy'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="email_from_address">From Email</label>
                            <input type="email" id="email_from_address" name="email_from_address" 
                                   value="<?php echo htmlspecialchars($settings['email_from_address'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email_encryption">Encryption</label>
                        <select id="email_encryption" name="email_encryption">
                            <option value="ssl" selected>SSL (Forced)</option>
                        </select>
                        <small>SMTP is optimized for SSL-only mode (port 465).</small>
                    </div>
                    
                    <?php if ($auth->hasPermission('settings.edit')): ?>
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Email Settings</button>
                        <button type="button" class="btn btn-secondary" onclick="testEmail()"><i class="fa-solid fa-paper-plane"></i> Send Test</button>
                    </div>
                    <?php endif; ?>
                    
                    <div class="success-box" id="email-success">
                        ✅ Email settings saved successfully!
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Receipt Settings Tab -->
        <div id="receipt-tab" class="tab-content">
            <div class="settings-card">
                <h3><span class="card-icon teal"><i class="fa-solid fa-receipt"></i></span> Receipt Customization</h3>
                <form id="receiptForm" onsubmit="saveReceiptSettings(event)">
                    <div class="form-group">
                        <label for="receipt_header">Receipt Header</label>
                        <textarea id="receipt_header" name="receipt_header" rows="2"><?php echo htmlspecialchars($settings['receipt_header'] ?? 'Thank you for shopping with us!'); ?></textarea>
                        <small>Message displayed at the top of receipts</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="receipt_footer">Receipt Footer</label>
                        <textarea id="receipt_footer" name="receipt_footer" rows="2"><?php echo htmlspecialchars($settings['receipt_footer'] ?? 'Please come again!'); ?></textarea>
                        <small>Message displayed at the bottom of receipts</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="toggle-row" style="padding: 0.5rem 0;">
                            <div class="toggle-info">
                                <div class="toggle-label">Show Company Logo</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="receipt_show_logo" name="receipt_show_logo" 
                                       <?php echo ($settings['receipt_show_logo'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="toggle-row" style="padding: 0.5rem 0;">
                            <div class="toggle-info">
                                <div class="toggle-label">Show Receipt Barcode</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="receipt_show_barcode" name="receipt_show_barcode" 
                                       <?php echo ($settings['receipt_show_barcode'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="receipt_width">Receipt Width</label>
                        <select id="receipt_width" name="receipt_width">
                            <option value="58mm" <?php echo ($settings['receipt_width'] ?? '') == '58mm' ? 'selected' : ''; ?>>58mm (Small)</option>
                            <option value="80mm" <?php echo ($settings['receipt_width'] ?? '80mm') == '80mm' ? 'selected' : ''; ?>>80mm (Standard)</option>
                            <option value="A4" <?php echo ($settings['receipt_width'] ?? '') == 'A4' ? 'selected' : ''; ?>>A4 (Full Page)</option>
                        </select>
                        <small>Paper size for thermal printers</small>
                    </div>
                    
                    <?php if ($auth->hasPermission('settings.edit')): ?>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Receipt Settings</button>
                    <?php endif; ?>
                    
                    <div class="success-box" id="receipt-success">
                        ✅ Receipt settings saved successfully!
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Alerts Tab -->
        <div id="alerts-tab" class="tab-content">
            <div class="settings-card">
                <h3><span class="card-icon amber"><i class="fa-solid fa-bell"></i></span> Alert Configuration</h3>
                <form id="alertsForm" onsubmit="saveAlertsSettings(event)">
                    <div class="form-group">
                        <label for="alert_email">Owner / Alert Email Address</label>
                        <input type="email" id="alert_email" name="alert_email" 
                               value="<?php echo htmlspecialchars($settings['alert_email'] ?? ''); ?>"
                               placeholder="owner@example.com">
                        <small>All alert and report emails are sent to this address</small>
                    </div>

                    <hr style="border:none; border-top:1px solid var(--divider-color, #e2e8f0); margin:1.5rem 0;">

                    <div class="toggle-row">
                        <div class="toggle-info">
                            <div class="toggle-label">Enable Email Alerts (Master)</div>
                            <small>Turn off to stop ALL automated emails (alerts + reports)</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="enable_email_alerts" name="enable_email_alerts" 
                                   <?php echo ($settings['enable_email_alerts'] ?? '0') == '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div id="alertDetailsSection">
                        <hr style="border:none; border-top:1px solid var(--divider-color, #e2e8f0); margin:1.5rem 0;">
                        <p style="font-weight:600; margin-bottom:0.75rem; color:var(--text-color);">Individual Alert Types</p>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Low Stock Alerts</div>
                                <small>Email when products fall below the reorder level</small>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="enable_low_stock_alerts" name="enable_low_stock_alerts" 
                                       <?php echo ($settings['enable_low_stock_alerts'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="margin-top:0.5rem;">
                            <label for="low_stock_threshold">Low Stock Threshold</label>
                            <input type="number" id="low_stock_threshold" name="low_stock_threshold" min="0"
                                   value="<?php echo htmlspecialchars($settings['low_stock_threshold'] ?? '20'); ?>">
                            <small>Alert when product quantity falls below this number</small>
                        </div>

                        <div class="toggle-row" style="margin-top:1rem;">
                            <div class="toggle-info">
                                <div class="toggle-label">Expiry Alerts</div>
                                <small>Email when products are about to expire</small>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="enable_expiry_alerts" name="enable_expiry_alerts" 
                                       <?php echo ($settings['enable_expiry_alerts'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="margin-top:0.5rem;">
                            <label for="expiry_alert_days">Expiry Alert (Days)</label>
                            <input type="number" id="expiry_alert_days" name="expiry_alert_days" min="1"
                                   value="<?php echo htmlspecialchars($settings['expiry_alert_days'] ?? '30'); ?>">
                            <small>Alert when products are expiring within this many days</small>
                        </div>

                        <hr style="border:none; border-top:1px solid var(--divider-color, #e2e8f0); margin:1.5rem 0;">

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Store Report Emails</div>
                                <small>Receive periodic sales & inventory summary reports</small>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="enable_report_emails" name="enable_report_emails" 
                                       <?php echo ($settings['enable_report_emails'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="margin-top:0.5rem;">
                            <label for="report_frequency">Report Frequency</label>
                            <select id="report_frequency" name="report_frequency" style="width:100%; padding:0.65rem 1rem; border:1px solid var(--input-border, #cbd5e1); border-radius:10px; background:var(--bg-color, #fff); color:var(--text-color, #333); font-size:0.95rem;">
                                <option value="daily"   <?php echo ($settings['report_frequency'] ?? 'daily') === 'daily'   ? 'selected' : ''; ?>>Daily (every day)</option>
                                <option value="weekly"  <?php echo ($settings['report_frequency'] ?? '') === 'weekly'  ? 'selected' : ''; ?>>Weekly (every Monday)</option>
                                <option value="monthly" <?php echo ($settings['report_frequency'] ?? '') === 'monthly' ? 'selected' : ''; ?>>Monthly (1st of each month)</option>
                            </select>
                            <small>How often the store performance report is emailed</small>
                        </div>
                    </div>
                    
                    <?php if ($auth->hasPermission('settings.edit')): ?>
                    <div style="margin-top:1.5rem;">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Alert Settings</button>
                    </div>
                    <?php endif; ?>
                    
                    <div class="success-box" id="alerts-success">
                        ✅ Alert settings saved successfully!
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Backup & Restore Tab -->
        <div id="backup-tab" class="tab-content">
            <div class="settings-grid">
                <div class="settings-card">
                    <h3><span class="card-icon green"><i class="fa-solid fa-database"></i></span> Database Backup</h3>
                    <div class="info-box">
                        <i class="fa-solid fa-circle-info"></i>
                        <div>
                            <strong>Automatic Backups</strong><br>
                            Products are automatically backed up when modified. Create manual backups for complete database snapshots.
                        </div>
                    </div>
                    
                    <?php if ($auth->hasPermission('settings.backup')): ?>
                    <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem;">
                        <button class="btn btn-success" onclick="createManualBackup()">
                            <i class="fa-solid fa-box-archive"></i> Create Full Backup
                        </button>
                        <button class="btn btn-secondary" onclick="loadBackupList()">
                            <i class="fa-solid fa-arrows-rotate"></i> Refresh
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Recent Backups</label>
                        <div class="backup-list" id="backupList">
                            <p style="text-align: center; padding: 1rem; opacity: 0.7;">Loading backups...</p>
                        </div>
                    </div>
                    
                    <div class="success-box" id="backup-success">
                        ✅ Backup created successfully!
                    </div>
                </div>
                
                <div class="settings-card">
                    <h3><span class="card-icon slate"><i class="fa-solid fa-sliders"></i></span> Backup Configuration</h3>
                    <form id="backupConfigForm" onsubmit="saveBackupConfig(event)">
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Enable Automatic Backups</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="auto_backup_enabled" name="auto_backup_enabled" 
                                       <?php echo ($settings['auto_backup_enabled'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label for="backup_frequency">Backup Frequency</label>
                            <select id="backup_frequency" name="backup_frequency">
                                <option value="daily" <?php echo ($settings['backup_frequency'] ?? 'daily') == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                <option value="weekly" <?php echo ($settings['backup_frequency'] ?? '') == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="monthly" <?php echo ($settings['backup_frequency'] ?? '') == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="backup_retention_days">Retention Period (Days)</label>
                            <input type="number" id="backup_retention_days" name="backup_retention_days" min="1"
                                   value="<?php echo htmlspecialchars($settings['backup_retention_days'] ?? '30'); ?>">
                            <small>Automatically delete backups older than this</small>
                        </div>
                        
                        <?php if ($auth->hasPermission('settings.edit')): ?>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Backup Config</button>
                        <?php endif; ?>
                        
                        <div class="success-box" id="backup-config-success">
                            ✅ Backup configuration saved!
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- System Tab -->
        <div id="system-tab" class="tab-content">
            <div class="settings-grid">
                <div class="settings-card">
                    <h3><span class="card-icon blue"><i class="fa-solid fa-circle-info"></i></span> System Information</h3>
                    <div class="sys-info-grid">
                        <div class="sys-info-tile">
                            <div class="tile-label">Version</div>
                            <div class="tile-value"><?php echo $settings['system_version'] ?? '1.0.0'; ?></div>
                        </div>
                        <div class="sys-info-tile">
                            <div class="tile-label">PHP</div>
                            <div class="tile-value"><?php echo PHP_VERSION; ?></div>
                        </div>
                        <div class="sys-info-tile">
                            <div class="tile-label">MySQL</div>
                            <div class="tile-value"><?php echo $conn->server_info; ?></div>
                        </div>
                        <div class="sys-info-tile">
                            <div class="tile-label">Server</div>
                            <div class="tile-value"><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="sys-info-tile">
                            <div class="tile-label">Last Backup</div>
                            <div class="tile-value" id="lastBackupDate"><?php echo $settings['last_backup_date'] ?? 'Never'; ?></div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem;">
                        <button class="btn btn-danger" onclick="customConfirm('Logout', 'This will log you out. Continue?', 'logout', { confirmText: 'Yes, Logout', cancelText: 'Stay' }).then(ok => { if(ok) window.location.href='logout.php'; })">
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </button>
                    </div>
                </div>
                
                <div class="settings-card">
                    <h3><span class="card-icon purple"><i class="fa-solid fa-palette"></i></span> Appearance</h3>
                    <div class="form-group">
                        <label>Theme</label>
                        <div class="theme-options">
                            <div class="theme-option light-opt" onclick="setTheme('light')" id="light-theme">
                                <div class="theme-check"><i class="fa-solid fa-check"></i></div>
                                <div class="theme-icon">☀️</div>
                                <div class="theme-name">Light</div>
                            </div>
                            <div class="theme-option dark-opt" onclick="setTheme('dark')" id="dark-theme">
                                <div class="theme-check"><i class="fa-solid fa-check"></i></div>
                                <div class="theme-icon">🌙</div>
                                <div class="theme-name">Dark</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Notification -->
    <div class="settings-toast" id="settingsToast">
        <i class="fa-solid fa-circle-check toast-check"></i>
        <span id="toastMessage">Settings saved!</span>
    </div>

    <script src="theme.js"></script>
    <script src="global-polish.js"></script>
    <script>
        // Tab Management
        function showTab(tabName, btn) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
            document.getElementById(tabName + '-tab').classList.add('active');
            if (btn) btn.classList.add('active');
        }
        
        // Toast notification
        function showSettingsToast(message) {
            const toast = document.getElementById('settingsToast');
            const msg = document.getElementById('toastMessage');
            msg.textContent = message || 'Settings saved!';
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }
        
        // Save functions
        async function saveSettings(formId, action, successId, toastMsg) {
            const form = document.getElementById(formId);
            const btn = form.querySelector('button[type="submit"]');
            const formData = new FormData(form);
            formData.append('action', action);
            
            // Handle checkboxes + toggle switches
            form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                formData.set(checkbox.name, checkbox.checked ? '1' : '0');
            });
            // Also grab toggles in the same card
            const card = form.closest('.settings-card');
            if (card) {
                card.querySelectorAll('.toggle-row input[type="checkbox"]').forEach(cb => {
                    formData.set(cb.name, cb.checked ? '1' : '0');
                });
            }
            
            if (btn) btn.classList.add('saving');
            
            try {
                const response = await fetch('api_settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSettingsToast(toastMsg || 'Settings saved successfully!');
                    const successBox = document.getElementById(successId);
                    if (successBox) {
                        successBox.classList.add('show');
                        setTimeout(() => successBox.classList.remove('show'), 3000);
                    }
                } else {
                    customAlert('Settings Error', 'Error: ' + result.message, 'error');
                }
            } catch (error) {
                customAlert('Settings Error', 'Error saving settings: ' + error.message, 'error');
            } finally {
                if (btn) btn.classList.remove('saving');
            }
        }
        
        function saveCompanySettings(e) {
            e.preventDefault();
            saveSettings('companyForm', 'save_company_info', 'company-success');
        }
        
        function saveTaxSettings(e) {
            e.preventDefault();
            saveSettings('taxForm', 'save_tax_settings', 'tax-success');
        }
        
        function saveCurrencySettings(e) {
            e.preventDefault();
            const formData = new FormData(document.getElementById('currencyForm'));
            formData.append('action', 'save_tax_settings');
            formData.append('tax_rate', document.getElementById('tax_rate').value);
            formData.append('enable_tax', document.getElementById('enable_tax').checked ? '1' : '0');
            
            fetch('api_settings.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(result => {
                if (result.success) {
                    const successBox = document.getElementById('currency-success');
                    successBox.classList.add('show');
                    setTimeout(() => successBox.classList.remove('show'), 3000);
                }
            });
        }
        
        function saveEmailSettings(e) {
            e.preventDefault();
            saveSettings('emailForm', 'save_email_settings', 'email-success');
        }
        
        function saveReceiptSettings(e) {
            e.preventDefault();
            saveSettings('receiptForm', 'save_receipt_settings', 'receipt-success');
        }
        
        function saveAlertsSettings(e) {
            e.preventDefault();
            saveSettings('alertsForm', 'save_alert_settings', 'alerts-success');
        }

        // Show/hide alert details based on master toggle
        (function() {
            const master = document.getElementById('enable_email_alerts');
            const details = document.getElementById('alertDetailsSection');
            if (master && details) {
                function toggle() { details.style.display = master.checked ? '' : 'none'; }
                toggle();
                master.addEventListener('change', toggle);
            }
        })();
        
        function saveBackupConfig(e) {
            e.preventDefault();
            saveSettings('backupConfigForm', 'save_backup_config', 'backup-config-success');
        }
        
        // Test Email
        async function testEmail() {
            const testEmail = await customPrompt(
                'Test Email',
                'Enter email address to send test email:',
                'info',
                { inputType: 'email', placeholder: 'name@example.com', confirmText: 'Send Test' }
            );
            if (!testEmail) return;
            
            const formData = new FormData();
            formData.append('action', 'test_email');
            formData.append('test_email', testEmail);
            
            try {
                const response = await fetch('api_settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                customAlert('Email Test', result.message, result.success ? 'success' : 'error');
            } catch (error) {
                customAlert('Email Error', 'Error: ' + error.message, 'error');
            }
        }
        
        // Backup functions
        async function createManualBackup() {
            const ok = await customConfirm('Create Backup', 'Create a full database backup? This may take a moment.', 'backup', { confirmText: 'Yes, Backup Now', cancelText: 'Cancel' });
            if (!ok) return;
            
            const formData = new FormData();
            formData.append('action', 'create_backup');
            
            try {
                const response = await fetch('api_settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('backup-success').classList.add('show');
                    setTimeout(() => document.getElementById('backup-success').classList.remove('show'), 3000);
                    loadBackupList();
                } else {
                    customAlert('Backup Error', 'Error: ' + result.message, 'error');
                }
            } catch (error) {
                customAlert('Backup Error', 'Error creating backup: ' + error.message, 'error');
            }
        }
        
        function getBackupButtonView() {
            return {
                buttonClass: 'btn btn-secondary',
                iconClass: 'fa-solid fa-download',
                label: 'Download'
            };
        }

        async function loadBackupList() {
            const listEl = document.getElementById('backupList');
            if (!listEl) return;

            listEl.innerHTML = '<p style="text-align: center; padding: 1rem; opacity: 0.7;">Loading backups...</p>';

            try {
                const response = await fetch('list_backups.php', { cache: 'no-store' });
                const result = await response.json();

                if (!result.success || !Array.isArray(result.backups)) {
                    listEl.innerHTML = '<p style="text-align: center; padding: 1rem; opacity: 0.7;">Unable to load backups</p>';
                    return;
                }

                if (result.backups.length === 0) {
                    listEl.innerHTML = '<p style="text-align: center; padding: 1rem; opacity: 0.7;">No backups found</p>';
                    return;
                }

                const escapeHtml = (value) => {
                    const div = document.createElement('div');
                    div.textContent = value == null ? '' : String(value);
                    return div.innerHTML;
                };

                const isAdminUser = <?php echo json_encode((($currentUser['role_name'] ?? '') === 'admin') || (($currentUser['role'] ?? '') === 'admin')); ?>;

                listEl.innerHTML = result.backups.slice(0, 10).map((backup) => {
                    const view = getBackupButtonView();
                    return `
                    <div class="backup-item" style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;padding:0.7rem 0.8rem;border-bottom:1px solid var(--divider-color);">
                        <div style="min-width:0;">
                            <div style="font-weight:600;font-size:0.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(backup.name)}</div>
                            <div style="font-size:0.78rem;opacity:0.75;">${escapeHtml(backup.date)} • ${escapeHtml(backup.size)}</div>
                        </div>
                        <button type="button" class="${view.buttonClass}" style="padding:0.45rem 0.7rem;font-size:0.78rem;white-space:nowrap;${isAdminUser ? '' : 'opacity:0.6;cursor:not-allowed;'}" onclick="downloadBackup('${escapeHtml(backup.name).replace(/'/g, '&#39;')}')" ${isAdminUser ? '' : 'disabled'}>
                            <i class="${view.iconClass}"></i> ${view.label}
                        </button>
                    </div>
                    `;
                }).join('');

                const lastBackupEl = document.getElementById('lastBackupDate');
                if (lastBackupEl && result.backups[0] && result.backups[0].date) {
                    lastBackupEl.textContent = result.backups[0].date;
                }
            } catch (error) {
                listEl.innerHTML = '<p style="text-align: center; padding: 1rem; opacity: 0.7;">Error loading backups</p>';
            }
        }

        // ── Backup Download ─────────────

        function notifyBackup(message, type = 'info') {
            if (typeof showToast === 'function') {
                showToast(message, type);
                return;
            }

            if (typeof customAlert === 'function') {
                const title = type === 'success' ? 'Success' : 'Notice';
                const alertType = type === 'success' ? 'success' : 'info';
                customAlert(title, message, alertType);
            }
        }

        async function downloadBackup(fileName) {
            if (!fileName) return;

            const isAdminUser = <?php echo json_encode((($currentUser['role_name'] ?? '') === 'admin') || (($currentUser['role'] ?? '') === 'admin')); ?>;
            if (!isAdminUser) {
                notifyBackup('Only admins can download database backups.', 'info');
                return;
            }

            const ok = await customConfirm(
                'Download Backup',
                'Download this backup file now?\n\nFile: ' + fileName,
                'backup',
                { confirmText: 'Download Now', cancelText: 'Cancel' }
            );
            if (!ok) return;

            window.location.href = 'download_backup.php?file=' + encodeURIComponent(fileName);
            notifyBackup('Download started.', 'success');
        }

        // Theme handling
        function setTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('calloway_theme', theme);
            localStorage.setItem('theme', theme);
            updateThemeUI();
        }
        
        function updateThemeUI() {
            const theme = localStorage.getItem('calloway_theme') || localStorage.getItem('theme') || 'light';
            document.querySelectorAll('.theme-option').forEach(el => el.classList.remove('selected'));
            const activeEl = document.getElementById(theme + '-theme');
            if (activeEl) activeEl.classList.add('selected');
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            loadBackupList();
            updateThemeUI();
        });
    </script>
</body>
</html>
