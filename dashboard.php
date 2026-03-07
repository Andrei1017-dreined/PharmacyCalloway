<?php
/**
 * Dashboard - Main Home Page (Optimized)
 * Overview of system metrics, quick actions, and recent activity
 * Uses caching for improved performance
 */

require_once 'db_connection.php';
require_once 'Auth.php';
require_once 'CacheManager.php';

$auth = new Auth($conn);
$auth->requireAuth('login.php');

$currentUser = $auth->getCurrentUser();

// Block customer role from dashboard — cashiers can see limited view
if (strtolower($currentUser['role_name'] ?? '') === 'customer') {
    header('Location: onlineordering.php');
    exit;
}

$isCashier = (strtolower($currentUser['role_name'] ?? '') === 'cashier');

$page_title = 'Dashboard';

// Get today's date
$today = date('Y-m-d');
$thisMonth = date('Y-m');

/**
 * Get dashboard statistics with caching
 * Cache expires every 2 minutes for near real-time data
 */
function getDashboardStats($conn, $today, $thisMonth) {
    $cacheKey = 'dashboard_stats_' . $today;
    
    return cache()->remember($cacheKey, function() use ($conn, $today, $thisMonth) {
        $stats = [];
        
        // Get expiry threshold from settings (cached)
        $expiryThreshold = 30;
        $s_stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'expiry_alert_days' LIMIT 1");
        if ($s_stmt) {
            $s_stmt->execute();
            if ($s_res = $s_stmt->get_result()->fetch_assoc()) {
                $expiryThreshold = (int) $s_res['setting_value'];
            }
            $s_stmt->close();
        }
        
        $expiryDate = date('Y-m-d', strtotime("+$expiryThreshold days"));

        // Get low stock threshold from settings (consistent with inventory_api.php)
        $lowStockThreshold = 20;
        $ls_stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'low_stock_threshold' LIMIT 1");
        if ($ls_stmt) {
            $ls_stmt->execute();
            if ($ls_res = $ls_stmt->get_result()->fetch_assoc()) {
                $lowStockThreshold = (int) $ls_res['setting_value'];
            }
            $ls_stmt->close();
        }
        
        // Single optimized query for product stats
        $productQuery = "SELECT 
            COUNT(*) as total_products,
            SUM(CASE WHEN stock_quantity <= ? THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN expiry_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as expiring_soon
            FROM products 
            WHERE is_active = 1";
        
        $stmt = $conn->prepare($productQuery);
        $stmt->bind_param('iss', $lowStockThreshold, $today, $expiryDate);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $stats['total_products'] = (int)$result['total_products'];
        $stats['low_stock'] = (int)$result['low_stock'];
        $stats['expiring_soon'] = (int)$result['expiring_soon'];
        
        // Single optimized query for sales stats
        $salesQuery = "SELECT 
            COALESCE(SUM(CASE WHEN DATE(created_at) = ? THEN total ELSE 0 END), 0) as today_sales,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = ? THEN total ELSE 0 END), 0) as month_sales,
            COUNT(*) as total_customers,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = ? THEN 1 ELSE 0 END), 0) as month_transactions
            FROM sales";
        
        $stmt = $conn->prepare($salesQuery);
        $stmt->bind_param('sss', $today, $thisMonth, $thisMonth);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $stats['today_sales'] = (float)$result['today_sales'];
        $stats['month_sales'] = (float)$result['month_sales'];
        $stats['total_customers'] = (int)$result['total_customers'];
        $stats['month_transactions'] = (int)$result['month_transactions'];
        
        return $stats;
    }, 120); // 2 minutes cache
}

/**
 * Get recent transactions with caching
 */
function getRecentTransactions($conn) {
    return cache()->remember('recent_transactions', function() use ($conn) {
        $transactions = [];
        $stmt = $conn->prepare("
            SELECT sale_id, sale_reference, total, payment_method, created_at, cashier
            FROM sales
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        $stmt->close();
        return $transactions;
    }, 60); // 1 minute cache
}

/**
 * Get top selling products with caching
 */
function getTopProducts($conn, $thisMonth) {
    $cacheKey = 'top_products_' . $thisMonth;
    
    return cache()->remember($cacheKey, function() use ($conn, $thisMonth) {
        $products = [];
        $stmt = $conn->prepare("
            SELECT p.name as product_name, SUM(si.quantity) as total_sold, SUM(si.line_total) as revenue
            FROM sale_items si
            JOIN products p ON si.product_id = p.product_id
            JOIN sales s ON si.sale_id = s.sale_id
            WHERE DATE_FORMAT(s.created_at, '%Y-%m') = ?
            GROUP BY p.product_id, p.name
            ORDER BY total_sold DESC
            LIMIT 5
        ");
        $stmt->bind_param('s', $thisMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $stmt->close();
        return $products;
    }, 300); // 5 minutes cache
}

/**
 * Get sales trend with caching (optimized single query)
 */
function getSalesTrend($conn) {
    return cache()->remember('sales_trend_7days', function() use ($conn) {
        $trend = [];
        
        // Single query for all 7 days
        $stmt = $conn->prepare("
            SELECT DATE(created_at) as sale_date, COALESCE(SUM(total), 0) as total
            FROM sales
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(created_at)
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $salesByDate = [];
        while ($row = $result->fetch_assoc()) {
            $salesByDate[$row['sale_date']] = (float)$row['total'];
        }
        $stmt->close();
        
        // Fill in all 7 days
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $trend[] = [
                'date' => date('M d', strtotime($date)),
                'amount' => $salesByDate[$date] ?? 0
            ];
        }
        
        return $trend;
    }, 120); // 2 minutes cache
}

// Fetch all data using optimized cached functions
$stats = getDashboardStats($conn, $today, $thisMonth);
$recentTransactions = getRecentTransactions($conn);
$topProducts = getTopProducts($conn, $thisMonth);
$salesTrend = getSalesTrend($conn);
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-hero {
            background: var(--primary-color);
            color: white;
            padding: 2rem 1.75rem;
            margin-top: 0;
            border-radius: 0 0 24px 24px;
            margin-bottom: 1.75rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px -4px rgba(var(--c-brand-rgb, 37, 99, 235), 0.35);
        }

        .hero-bg-icon {
            position: absolute;
            right: 5%;
            top: 50%;
            transform: translateY(-50%) rotate(-10deg);
            font-size: 15rem;
            opacity: 0.1;
            pointer-events: none;
        }

        .welcome-text h1 {
            font-size: 1.5rem;
            font-weight: 800;
            margin: 0 0 0.35rem;
            letter-spacing: -0.02em;
            color: #ffffff;
            line-height: 1.3;
        }

        .welcome-text p {
            font-size: 0.95rem;
            opacity: 0.9;
            margin: 0;
            font-weight: 400;
            color: rgba(255,255,255,0.88);
        }

        .dashboard-container {
            max-width: 1400px;
            margin: -3rem auto 2rem;
            padding: 0 2rem;
            position: relative;
            z-index: 10;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card-premium {
            padding: 1.25rem;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: 1px solid var(--c-border, rgba(226, 232, 240, 1));
        }

        .stat-card-premium:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            background: rgba(var(--primary-rgb), 0.08);
            color: var(--primary-color);
            flex-shrink: 0;
        }

        .stat-content h3 {
            font-size: 0.9rem;
            color: var(--text-light);
            margin: 0 0 0.25rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .stat-content .value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-color);
            line-height: 1;
            letter-spacing: -0.02em;
        }

        .dashboard-main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            min-width: 0;
        }

        .dashboard-main-grid > * {
            min-width: 0;
        }

        @media (max-width: 1024px) {
            .dashboard-main-grid {
                grid-template-columns: 1fr;
            }
        }

        .quick-actions-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .action-btn-glass {
            background: var(--c-surface, var(--card-bg));
            border: 1px solid var(--c-border, var(--input-border));
            padding: 0.6rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.84rem;
            color: var(--c-text-secondary, var(--text-color));
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            transition: all 0.2s ease;
            text-decoration: none;
            box-shadow: var(--shadow-xs, 0 1px 2px rgba(0,0,0,0.04));
        }

        [data-theme="dark"] .action-btn-glass {
            background: rgba(30, 41, 59, 0.8);
        }

        .action-btn-glass:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Dark mode fixes for dashboard */
        [data-theme="dark"] .stat-card-premium {
            border-color: rgba(255, 255, 255, 0.08);
        }

        [data-theme="dark"] .table-enhanced {
            background: rgba(15, 23, 42, 0.9);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        [data-theme="dark"] .table-enhanced tbody td {
            color: #e2e8f0;
        }

        [data-theme="dark"] .table-enhanced tbody tr {
            border-bottom-color: rgba(255, 255, 255, 0.06);
        }

        [data-theme="dark"] .table-enhanced tbody td span[style*="font-family: monospace"] {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .table-responsive td,
        [data-theme="dark"] .table-responsive th {
            color: #e2e8f0;
        }

        [data-theme="dark"] .table-responsive td[style*="font-weight: 700"] {
            color: #93c5fd;
        }

        [data-theme="dark"] .table-responsive td[style*="color: var(--text-light)"] {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .dashboard-container .glass-panel {
            background: rgba(15, 23, 42, 0.85);
            color: #e2e8f0;
        }

        [data-theme="dark"] .stat-content .value {
            color: #f1f5f9;
        }

        [data-theme="dark"] .stat-content h3 {
            color: #94a3b8;
        }
    </style>
</head>

<body>
    <?php include 'header-component.php'; ?>

    <!-- Hero Section -->
    <div class="dashboard-hero">
        <i class="fas fa-heartbeat hero-bg-icon"></i>
        <div class="welcome-text">
            <h1>Good <?php echo (date('H') < 12 ? 'Morning' : (date('H') < 18 ? 'Afternoon' : 'Evening')); ?>,
                <?php echo htmlspecialchars($currentUser['username']); ?>!</h1>
            <p>Here's what's happening in your pharmacy today.</p>
        </div>
    </div>

    <!-- Main Container -->
    <div class="dashboard-container">

        <!-- Stats Grid -->
        <div class="stats-grid">
            <!-- Total Products -->
            <div class="stat-card-premium glass-panel">
                <div class="stat-content">
                    <h3>Total Products</h3>
                    <div class="value"><?php echo number_format($stats['total_products']); ?></div>
                </div>
                <div class="stat-icon-wrapper" style="background: rgba(37, 99, 235, 0.1); color: var(--primary-color);">
                    <i class="fas fa-boxes"></i>
                </div>
            </div>

            <!-- Low Stock -->
            <a href="inventory_management.php?filter=low_stock" style="text-decoration: none;">
                <div class="stat-card-premium glass-panel" style="border-left: 4px solid var(--accent-color);">
                    <div class="stat-content">
                        <h3>Low Stock</h3>
                        <div class="value" style="color: var(--accent-color);">
                            <?php echo number_format($stats['low_stock']); ?></div>
                    </div>
                    <div class="stat-icon-wrapper"
                        style="background: rgba(245, 158, 11, 0.1); color: var(--accent-color);">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </a>

            <!-- Expiring Soon -->
            <a href="medicine-locator.php" style="text-decoration: none;">
                <div class="stat-card-premium glass-panel"
                    style="<?php echo $stats['expiring_soon'] > 0 ? 'border-left: 4px solid var(--danger-color);' : ''; ?>">
                    <div class="stat-content">
                        <h3>Expiring Soon</h3>
                        <div class="value"
                            style="<?php echo $stats['expiring_soon'] > 0 ? 'color: var(--danger-color);' : ''; ?>">
                            <?php echo number_format($stats['expiring_soon']); ?>
                        </div>
                    </div>
                    <div class="stat-icon-wrapper"
                        style="background: rgba(239, 68, 68, 0.1); color: var(--danger-color);">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </a>

            <!-- Today's Sales -->
            <div class="stat-card-premium glass-panel">
                <div class="stat-content">
                    <h3>Today's Sales</h3>
                    <div class="value" style="color: var(--secondary-color);">
                        ₱<?php echo number_format($stats['today_sales'], 2); ?></div>
                </div>
                <div class="stat-icon-wrapper"
                    style="background: rgba(16, 185, 129, 0.1); color: var(--secondary-color);">
                    <i class="fas fa-cash-register"></i>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions-bar">
            <a href="pos.php" class="action-btn-glass"><i class="fas fa-shopping-cart"></i> New Sale (POS)</a>
            <?php if (!$isCashier): ?>
            <a href="inventory_management.php?action=add" class="action-btn-glass"><i class="fas fa-plus"></i> Add
                Product</a>
            <a href="reports.php" class="action-btn-glass"><i class="fas fa-chart-line"></i> View Reports</a>
            <?php else: ?>
            <a href="medicine-locator.php" class="action-btn-glass"><i class="fas fa-search-location"></i> Medicine &amp; Expiry</a>
            <a href="order_status.php" class="action-btn-glass"><i class="fas fa-receipt"></i> Order Status</a>
            <?php endif; ?>
        </div>

        <div class="dashboard-main-grid">
            <!-- Left Column: Recent Transactions -->
            <div class="glass-panel" style="padding: 1.5rem; border-radius: 16px;">
                <div class="chart-header">
                    <h3 class="chart-title"><i class="fas fa-history" style="color: var(--primary-color);"></i> Recent
                        Transactions</h3>
                    <a href="reports.php"
                        style="font-size: 0.9rem; color: var(--primary-color); text-decoration: none; font-weight: 600;">View
                        All</a>
                </div>

                <div class="table-responsive">
                    <table class="table-enhanced">
                        <thead>
                            <tr>
                                <th>Ref #</th>
                                <th>Total</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTransactions)): ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; color: var(--text-light);">No transactions
                                        yet today.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentTransactions as $tx): ?>
                                    <tr>
                                        <td>
                                            <span style="font-weight: 600; font-family: monospace; color: var(--text-color);">
                                                <?php echo htmlspecialchars($tx['sale_reference']); ?>
                                            </span>
                                        </td>
                                        <td style="font-weight: 700; color: var(--primary-color);">₱<?php echo number_format($tx['total'], 2); ?></td>
                                        <td style="color: var(--text-light); font-size: 0.9rem;">
                                            <?php echo date('g:i A', strtotime($tx['created_at'])); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Column: Top Products & Month Stats -->
            <div style="display: flex; flex-direction: column; gap: 2rem;">
                <!-- Month Sales Summary -->
                <div class="glass-panel"
                    style="padding: 1.5rem; border-radius: 16px; background: var(--primary-color); color: white;">
                    <h3 style="margin: 0 0 1rem; opacity: 0.9; font-weight: 500;">This Month's Revenue</h3>
                    <div style="font-size: 2.5rem; font-weight: 800; margin-bottom: 0.5rem;">
                        ₱<?php echo number_format($stats['month_sales'], 2); ?>
                    </div>
                    <div style="opacity: 0.8; font-size: 0.9rem;">
                        <i class="fas fa-receipt"></i> <?php echo number_format($stats['month_transactions']); ?> Transaction<?php echo $stats['month_transactions'] !== 1 ? 's' : ''; ?> This Month
                    </div>
                    <div style="opacity: 0.6; font-size: 0.78rem; margin-top: 0.3rem;">
                        <i class="fas fa-database"></i> <?php echo number_format($stats['total_customers']); ?> All-Time
                    </div>
                </div>

                <!-- Top Products -->
                <div class="glass-panel" style="padding: 1.5rem; border-radius: 16px;">
                    <h3 class="chart-title" style="margin-bottom: 1rem;"><i class="fas fa-trophy"
                            style="color: var(--accent-color);"></i> Top Sellers</h3>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <?php if (empty($topProducts)): ?>
                            <div style="text-align: center; opacity: 0.5;">No sales data yet.</div>
                        <?php else: ?>
                            <?php foreach ($topProducts as $index => $prod): ?>
                                <div
                                    style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 0.5rem; border-bottom: 1px solid var(--divider-color);">
                                    <div style="display: flex; align-items: center; gap: 0.8rem;">
                                        <span
                                            style="background: var(--bg-color); color: var(--text-light); width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 0.8rem; font-weight: 700;">
                                            <?php echo $index + 1; ?>
                                        </span>
                                        <span style="font-weight: 600; color: var(--text-color);">
                                            <?php echo htmlspecialchars($prod['product_name']); ?>
                                        </span>
                                    </div>
                                    <span style="font-weight: 700; color: var(--secondary-color);">
                                        <?php echo number_format($prod['total_sold']); ?> sold
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="theme.js"></script>
    <script src="shared-polish.js"></script>
</body>

</html>