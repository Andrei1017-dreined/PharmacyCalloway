<?php
/**
 * Reports Data API
 * Provides data for reports and analytics
 */

require_once 'db_connection.php';
require_once 'Auth.php';

$auth = new Auth($conn);

// Check authentication
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$action = $_GET['action'] ?? '';
$startDate = $_GET['start'] ?? date('Y-m-d');
$endDate = $_GET['end'] ?? date('Y-m-d');
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

// Default API responses to JSON; export actions override with CSV headers.
if (!in_array($action, ['export', 'export_all'], true)) {
    header('Content-Type: application/json');
}

// Validate date formats (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $endDate = date('Y-m-d');

// Convert dates to timestamps for comparison (more efficient for index usage)
// Start: beginning of startDate, End: beginning of next day
$startTimestamp = date('Y-m-d H:i:s', strtotime($startDate . ' 00:00:00'));
$endTimestamp = date('Y-m-d H:i:s', strtotime($endDate . ' 23:59:59')); // Include entire end date
// For queries: use >= startTimestamp AND < next day
$endDateNextDay = date('Y-m-d H:i:s', strtotime($endDate . ' +1 day'));

function tableExists($conn, $table) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function columnExists($conn, $table, $column) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

switch($action) {
    case 'metrics':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        $periodStart = new DateTime($startDate);
        $periodEnd = new DateTime($endDate);
        $periodDays = $periodStart->diff($periodEnd)->days + 1;
        $prevStart = (clone $periodStart)->modify("-{$periodDays} days");
        $prevEnd = (clone $periodStart)->modify('-1 day');

        $prevStartTs = $prevStart->format('Y-m-d 00:00:00');
        $prevEndNextTs = (clone $prevEnd)->modify('+1 day')->format('Y-m-d 00:00:00');
        
        // Get sales metrics
        $query = "SELECT 
                    COUNT(DISTINCT s.sale_id) as sales_count,
                    SUM(s.total) as revenue,
                    SUM(si.quantity) as products_sold,
                    AVG(s.total) as avg_transaction,
                    SUM((si.unit_price - COALESCE(p.cost_price, 0)) * si.quantity) as gross_profit
                  FROM sales s
                  LEFT JOIN sale_items si ON s.sale_id = si.sale_id
                  LEFT JOIN products p ON si.product_id = p.product_id
                  WHERE s.created_at >= ? AND s.created_at < ?
                  ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        $prevQuery = "SELECT SUM(total) as revenue FROM sales WHERE created_at >= ? AND created_at < ?";
        $stmt = $conn->prepare($prevQuery);
        $stmt->bind_param("ss", $prevStartTs, $prevEndNextTs);
        $stmt->execute();
        $prevRevenue = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $currentRevenue = floatval($data['revenue'] ?? 0);
        $previousRevenue = floatval($prevRevenue['revenue'] ?? 0);
        $growthPct = null;
        if ($previousRevenue > 0) {
            $growthPct = (($currentRevenue - $previousRevenue) / $previousRevenue) * 100;
        }
        $grossProfit = floatval($data['gross_profit'] ?? 0);
        $grossMarginPct = $currentRevenue > 0 ? ($grossProfit / $currentRevenue) * 100 : 0;

        $data['revenue_growth_pct'] = $growthPct;
        $data['gross_margin_pct'] = $grossMarginPct;

        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        break;
        
    case 'top_products':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $query = "SELECT 
                    p.product_id,
                    p.name as product_name,
                    SUM(si.quantity) as total_quantity,
                    SUM(si.line_total) as total_revenue
                  FROM sale_items si
                  JOIN products p ON si.product_id = p.product_id
                  JOIN sales s ON si.sale_id = s.sale_id
                  WHERE s.created_at >= ? AND s.created_at < ?
                  
                  GROUP BY p.product_id, p.name
                  ORDER BY total_revenue DESC
                  LIMIT ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssi", $startTimestamp, $endDateNextDay, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        break;
        
    case 'category_sales':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $query = "SELECT 
                    COALESCE(c.category_name, p.category, 'Uncategorized') as category_name,
                    SUM(si.line_total) as total_revenue,
                    SUM(si.quantity) as total_quantity
                  FROM sale_items si
                  JOIN products p ON si.product_id = p.product_id
                  LEFT JOIN categories c ON p.category_id = c.category_id
                  JOIN sales s ON si.sale_id = s.sale_id
                  WHERE s.created_at >= ? AND s.created_at < ?
                  
                  GROUP BY category_name
                  ORDER BY total_revenue DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        break;

    case 'sales_trend':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        $query = "SELECT 
                    DATE(s.created_at) as sale_date,
                    SUM(s.total) as revenue,
                    COUNT(*) as order_count
                  FROM sales s
                  WHERE s.created_at >= ? AND s.created_at < ?
                  GROUP BY DATE(s.created_at)
                  ORDER BY sale_date ASC";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'payment_mix':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        $query = "SELECT 
                    COALESCE(payment_method, 'Unknown') as payment_method,
                    COUNT(*) as order_count,
                    SUM(total) as total_amount
                  FROM sales
                  WHERE created_at >= ? AND created_at < ?
                  GROUP BY payment_method
                  ORDER BY total_amount DESC";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'top_products_profit':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        $query = "SELECT 
                    p.product_id,
                    p.name as product_name,
                    SUM(si.quantity) as total_quantity,
                    SUM(si.line_total) as total_revenue,
                    SUM((si.unit_price - COALESCE(p.cost_price, 0)) * si.quantity) as gross_profit,
                    CASE 
                        WHEN SUM(si.line_total) > 0 THEN (SUM((si.unit_price - COALESCE(p.cost_price, 0)) * si.quantity) / SUM(si.line_total)) * 100
                        ELSE 0
                    END as margin_pct
                  FROM sale_items si
                  JOIN products p ON si.product_id = p.product_id
                  JOIN sales s ON si.sale_id = s.sale_id
                  WHERE s.created_at >= ? AND s.created_at < ?
                  GROUP BY p.product_id, p.name
                  ORDER BY gross_profit DESC
                  LIMIT ?";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssi", $startTimestamp, $endDateNextDay, $limit);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'inventory_risk':
        if (!$auth->hasPermission('reports.inventory')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        if (!columnExists($conn, 'products', 'expiry_date')) {
            echo json_encode(['success' => true, 'data' => [
                'risk_30_value' => 0,
                'risk_60_value' => 0,
                'risk_90_value' => 0,
                'low_stock_count' => 0
            ]]);
            break;
        }

                $hasReorder = columnExists($conn, 'products', 'reorder_level');
                $hasSellingPrice = columnExists($conn, 'products', 'selling_price');
                $priceExpr = $hasSellingPrice ? 'COALESCE(selling_price, price)' : 'price';
                $lowStockExpr = $hasReorder ? 'SUM(CASE WHEN stock_quantity <= reorder_level THEN 1 ELSE 0 END)' : '0';

                $query = "SELECT 
                                        SUM(CASE WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN stock_quantity * $priceExpr ELSE 0 END) as risk_30_value,
                                        SUM(CASE WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN stock_quantity * $priceExpr ELSE 0 END) as risk_60_value,
                                        SUM(CASE WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN stock_quantity * $priceExpr ELSE 0 END) as risk_90_value,
                                        $lowStockExpr as low_stock_count
                                    FROM products
                                    WHERE is_active = 1";

        $result = $conn->query($query);
        $data = $result ? $result->fetch_assoc() : [];

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'dead_stock':
        if (!$auth->hasPermission('reports.inventory')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

                $query = "SELECT p.product_id, p.name as product_name, p.stock_quantity,
                                 recent.last_sale_date
                          FROM products p
                          LEFT JOIN (
                              SELECT si.product_id, MAX(DATE(s.created_at)) as last_sale_date
                              FROM sale_items si
                              JOIN sales s ON si.sale_id = s.sale_id
                              GROUP BY si.product_id
                          ) recent ON p.product_id = recent.product_id
                          WHERE p.is_active = 1 AND p.stock_quantity > 0
                            AND (recent.last_sale_date IS NULL OR recent.last_sale_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY))
                          ORDER BY recent.last_sale_date IS NULL DESC, recent.last_sale_date ASC
                          LIMIT ?";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'slow_movers':
        if (!$auth->hasPermission('reports.inventory')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        $query = "SELECT 
                    p.product_id,
                    p.name as product_name,
                    p.stock_quantity,
                    COALESCE(SUM(CASE WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN si.quantity ELSE 0 END), 0) as qty_90_days
                  FROM products p
                  LEFT JOIN sale_items si ON p.product_id = si.product_id
                  LEFT JOIN sales s ON si.sale_id = s.sale_id
                  WHERE p.is_active = 1
                  GROUP BY p.product_id, p.name, p.stock_quantity
                  HAVING qty_90_days > 0 AND qty_90_days < 5
                  ORDER BY qty_90_days ASC
                  LIMIT ?";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'order_status':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        if (!tableExists($conn, 'online_orders')) {
            echo json_encode(['success' => true, 'data' => []]);
            break;
        }

        $query = "SELECT status, COUNT(*) as order_count
                  FROM online_orders
                  WHERE created_at >= ? AND created_at < ?
                  GROUP BY status
                  ORDER BY order_count DESC";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'operational_stats':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        if (!tableExists($conn, 'online_orders')) {
            echo json_encode(['success' => true, 'data' => ['avg_cycle_minutes' => 0]]);
            break;
        }

        $query = "SELECT 
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, picked_up_at)) as avg_cycle_minutes
                  FROM online_orders
                  WHERE picked_up_at IS NOT NULL
                    AND created_at >= ? AND created_at < ?";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'rx_stats':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        if (!tableExists($conn, 'online_orders') || !columnExists($conn, 'online_orders', 'requires_rx_approval')) {
            echo json_encode(['success' => true, 'data' => ['approval_rate' => 0, 'recent_logs' => []]]);
            break;
        }

        $query = "SELECT 
                    COUNT(*) as total_rx,
                    SUM(CASE WHEN pharmacist_approved_at IS NOT NULL THEN 1 ELSE 0 END) as approved_rx,
                    SUM(CASE WHEN pharmacist_approved_at IS NULL THEN 1 ELSE 0 END) as pending_rx,
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, pharmacist_approved_at)) as avg_approval_minutes
                  FROM online_orders
                  WHERE requires_rx_approval = 1
                    AND created_at >= ? AND created_at < ?";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $approvalRate = 0;
        if (!empty($data['total_rx'])) {
            $approvalRate = (floatval($data['approved_rx']) / floatval($data['total_rx'])) * 100;
        }
        $data['approval_rate'] = $approvalRate;

        $recentLogs = [];
        if (tableExists($conn, 'rx_approval_log')) {
            // Include both online-order Rx approvals and POS Rx dispensing
            $logQuery = "SELECT 
                            l.action,
                            l.created_at,
                            p.name as product_name,
                            COALESCE(u.full_name, u.username, 'POS Cashier') as pharmacist_name
                          FROM rx_approval_log l
                          LEFT JOIN products p ON l.product_id = p.product_id
                          LEFT JOIN users u ON l.pharmacist_id = u.user_id
                          WHERE l.created_at >= ? AND l.created_at < ?
                          ORDER BY l.created_at DESC
                          LIMIT ?";
            $stmt = $conn->prepare($logQuery);
            $stmt->bind_param("ssi", $startTimestamp, $endDateNextDay, $limit);
            $stmt->execute();
            $recentLogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        $data['recent_logs'] = $recentLogs;

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'customer_stats':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        if (!tableExists($conn, 'online_orders')) {
            echo json_encode(['success' => true, 'data' => ['repeat_rate' => 0, 'top_customers' => []]]);
            break;
        }

                $hasCustomerId = columnExists($conn, 'online_orders', 'customer_id');
                $fallbackColumn = columnExists($conn, 'online_orders', 'email') ? 'email' : 'customer_name';
                $customerRefExpr = $hasCustomerId
                        ? "COALESCE(NULLIF(customer_id, 0), $fallbackColumn)"
                        : "COALESCE(NULLIF($fallbackColumn, ''), customer_name)";

                // Single query for both total and repeat customer counts
                $combinedQuery = "SELECT 
                                    COUNT(*) as total_customers,
                                    SUM(CASE WHEN cnt >= 2 THEN 1 ELSE 0 END) as repeat_customers
                                  FROM (
                                      SELECT $customerRefExpr as customer_ref, COUNT(*) as cnt
                                      FROM online_orders
                                      WHERE created_at >= ? AND created_at < ?
                                      GROUP BY customer_ref
                                  ) t";

        $stmt = $conn->prepare($combinedQuery);
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $combinedRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalCustomers = intval($combinedRow['total_customers'] ?? 0);
        $repeatCustomers = intval($combinedRow['repeat_customers'] ?? 0);
        $repeatRate = $totalCustomers > 0 ? ($repeatCustomers / $totalCustomers) * 100 : 0;

        $topQuery = "SELECT 
                        customer_name,
                        email,
                        COUNT(*) as order_count,
                        SUM(total_amount) as total_spent,
                        DATE(MAX(created_at)) as last_order_date
                      FROM online_orders
                      WHERE created_at >= ? AND created_at < ?
                      GROUP BY customer_name, email
                      ORDER BY total_spent DESC
                      LIMIT ?";

        $stmt = $conn->prepare($topQuery);
        $stmt->bind_param("ssi", $startTimestamp, $endDateNextDay, $limit);
        $stmt->execute();
        $topCustomers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode([
            'success' => true,
            'data' => [
                'total_customers' => $totalCustomers,
                'repeat_customers' => $repeatCustomers,
                'repeat_rate' => $repeatRate,
                'top_customers' => $topCustomers
            ]
        ]);
        break;

    case 'loyalty_stats':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        if (!tableExists($conn, 'loyalty_members')) {
            echo json_encode(['success' => true, 'data' => ['points_total' => 0, 'member_count' => 0]]);
            break;
        }

        $query = "SELECT COUNT(*) as member_count, SUM(points) as points_total FROM loyalty_members";
        $result = $conn->query($query);
        $data = $result ? $result->fetch_assoc() : ['member_count' => 0, 'points_total' => 0];

        echo json_encode(['success' => true, 'data' => $data]);
        break;
        
    case 'inventory_value':
        if (!$auth->hasPermission('reports.inventory')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $query = "SELECT 
                    SUM(stock_quantity * cost_price) as total_cost_value,
                    SUM(stock_quantity * selling_price) as total_selling_value
                  FROM products
                  WHERE is_active = 1";
        
        $result = $conn->query($query);
        $data = $result->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        break;
        
    case 'top_cashiers':
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        // Ensure index on sales.created_at for performance
        // Query: SELECT index usage will benefit from removal of DATE() function
        
        $query = "SELECT 
                    s.cashier as cashier_name,
                    COUNT(s.sale_id) as sales_count,
                    SUM(s.total) as total_revenue
                  FROM sales s
                  WHERE s.created_at >= ? AND s.created_at < ?
                  
                  GROUP BY s.cashier
                  ORDER BY total_revenue DESC
                  LIMIT ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssi", $startTimestamp, $endDateNextDay, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        break;
        
    case 'export':
        // Permission check for exports
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $type = $_GET['type'] ?? 'all';
        
        // Set CSV headers
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel renders special characters correctly
        fwrite($output, "\xEF\xBB\xBF");
        
        switch($type) {
            case 'all':
                // Keep backward compatibility for clients that still call action=export&type=all.
                fputcsv($output, ['CALLOWAY PHARMACY - COMPREHENSIVE REPORT']);
                fputcsv($output, ['Period: ' . $startDate . ' to ' . $endDate]);
                fputcsv($output, []);

                fputcsv($output, ['=== SUMMARY METRICS ===']);
                fputcsv($output, ['Metric', 'Value']);

                $query = "SELECT 
                            COUNT(DISTINCT s.sale_id) as sales_count,
                            SUM(s.total) as revenue,
                            SUM(si.quantity) as products_sold,
                            AVG(s.total) as avg_transaction
                          FROM sales s
                          LEFT JOIN sale_items si ON s.sale_id = si.sale_id
                          WHERE s.created_at >= ? AND s.created_at < ?";

                $stmt = $conn->prepare($query);
                $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
                $stmt->execute();
                $metrics = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                fputcsv($output, ['Total Revenue', 'PHP ' . number_format((float)($metrics['revenue'] ?? 0), 2)]);
                fputcsv($output, ['Total Sales', (int)($metrics['sales_count'] ?? 0)]);
                fputcsv($output, ['Products Sold', (int)($metrics['products_sold'] ?? 0)]);
                fputcsv($output, ['Avg Transaction', 'PHP ' . number_format((float)($metrics['avg_transaction'] ?? 0), 2)]);
                fputcsv($output, []);
                break;

            case 'top-products':
                fputcsv($output, ['Rank', 'Product Name', 'Quantity Sold', 'Revenue']);
                
                $query = "SELECT 
                            p.name as product_name,
                            SUM(si.quantity) as total_quantity,
                            SUM(si.line_total) as total_revenue
                          FROM sale_items si
                          JOIN products p ON si.product_id = p.product_id
                          JOIN sales s ON si.sale_id = s.sale_id
                          WHERE s.created_at >= ? AND s.created_at < ?
                          
                          GROUP BY p.product_id, p.name
                          ORDER BY total_revenue DESC";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $rank = 1;
                while ($row = $result->fetch_assoc()) {
                    fputcsv($output, [$rank++, $row['product_name'], $row['total_quantity'], $row['total_revenue']]);
                }
                break;
                
            case 'category-sales':
                fputcsv($output, ['Category', 'Sales', 'Revenue']);
                
                $query = "SELECT 
                            c.category_name,
                            SUM(si.quantity) as total_quantity,
                            SUM(si.line_total) as total_revenue
                          FROM sale_items si
                          JOIN products p ON si.product_id = p.product_id
                          JOIN categories c ON p.category_id = c.category_id
                          JOIN sales s ON si.sale_id = s.sale_id
                          WHERE s.created_at >= ? AND s.created_at < ?
                          
                          GROUP BY c.category_id, c.category_name
                          ORDER BY total_revenue DESC";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    fputcsv($output, [$row['category_name'], $row['total_quantity'], $row['total_revenue']]);
                }
                break;
                
            case 'low-stock':
                fputcsv($output, ['Product', 'Stock Quantity', 'Reorder Level']);
                
                $query = "SELECT name, stock_quantity, reorder_level FROM products 
                          WHERE is_active = 1 AND stock_quantity <= reorder_level 
                          ORDER BY stock_quantity ASC";
                $result = $conn->query($query);
                
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        fputcsv($output, [$row['name'], $row['stock_quantity'], $row['reorder_level']]);
                    }
                }
                break;
                
            case 'expiring':
                fputcsv($output, ['Product', 'Expiry Date', 'Days Until Expiry']);
                
                $query = "SELECT name, expiry_date, DATEDIFF(expiry_date, CURDATE()) as days_until_expiry 
                          FROM products 
                          WHERE is_active = 1 AND expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) <= 90
                          ORDER BY days_until_expiry ASC";
                $result = $conn->query($query);
                
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        fputcsv($output, [$row['name'], $row['expiry_date'], $row['days_until_expiry']]);
                    }
                }
                break;
                
            case 'cashiers':
                fputcsv($output, ['Cashier', 'Sales Count', 'Total Revenue']);
                
                $query = "SELECT 
                            s.cashier as cashier_name,
                            COUNT(s.sale_id) as sales_count,
                            SUM(s.total) as total_revenue
                          FROM sales s
                          WHERE s.created_at >= ? AND s.created_at < ?
                          
                          GROUP BY s.cashier
                          ORDER BY total_revenue DESC";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    fputcsv($output, [$row['cashier_name'], $row['sales_count'], $row['total_revenue']]);
                }
                break;
        }
        
        fclose($output);
        exit;
        
    case 'export_all':
        // Permission check for exports
        if (!$auth->hasPermission('reports.sales')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        $filename = 'Calloway_Comprehensive_Report_' . $startDate . '_to_' . $endDate . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens with correct encoding (₱ symbol)
        fwrite($output, "\xEF\xBB\xBF");

        $cur = 'PHP '; // safe peso sign for CSV

        // ── Title ──
        fputcsv($output, ['CALLOWAY PHARMACY - COMPREHENSIVE REPORT']);
        fputcsv($output, ['Report Period', $startDate . ' to ' . $endDate]);
        fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 1 – KEY PERFORMANCE INDICATORS
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== KEY PERFORMANCE INDICATORS =====']);
        fputcsv($output, ['Metric', 'Value']);

        // Revenue + profit metrics
        $query = "SELECT
                    COUNT(DISTINCT s.sale_id) as sales_count,
                    COALESCE(SUM(s.total), 0) as revenue,
                    COALESCE(SUM(si.quantity), 0) as products_sold,
                    COALESCE(AVG(s.total), 0) as avg_transaction,
                    COALESCE(SUM((si.unit_price - COALESCE(p.cost_price, 0)) * si.quantity), 0) as gross_profit
                  FROM sales s
                  LEFT JOIN sale_items si ON s.sale_id = si.sale_id
                  LEFT JOIN products p ON si.product_id = p.product_id
                  WHERE s.created_at >= ? AND s.created_at < ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $revenue = (float)($m['revenue'] ?? 0);
        $grossProfit = (float)($m['gross_profit'] ?? 0);
        $grossMargin = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;

        // Previous period for growth calc
        $periodStart = new DateTime($startDate);
        $periodEnd = new DateTime($endDate);
        $periodDays = $periodStart->diff($periodEnd)->days + 1;
        $prevStart = (clone $periodStart)->modify("-{$periodDays} days");
        $prevStartTs = $prevStart->format('Y-m-d 00:00:00');
        $prevEndNextTs = $periodStart->format('Y-m-d 00:00:00');
        $stmtPrev = $conn->prepare("SELECT COALESCE(SUM(total),0) as revenue FROM sales WHERE created_at >= ? AND created_at < ?");
        $stmtPrev->bind_param("ss", $prevStartTs, $prevEndNextTs);
        $stmtPrev->execute();
        $prevRev = (float)($stmtPrev->get_result()->fetch_assoc()['revenue'] ?? 0);
        $stmtPrev->close();
        $growthPct = $prevRev > 0 ? (($revenue - $prevRev) / $prevRev) * 100 : 0;

        fputcsv($output, ['Total Revenue', $cur . number_format($revenue, 2)]);
        fputcsv($output, ['Revenue Growth (vs prev period)', number_format($growthPct, 1) . '%']);
        fputcsv($output, ['Gross Profit', $cur . number_format($grossProfit, 2)]);
        fputcsv($output, ['Gross Margin', number_format($grossMargin, 1) . '%']);
        fputcsv($output, ['Total Transactions', (int)($m['sales_count'] ?? 0)]);
        fputcsv($output, ['Avg Transaction', $cur . number_format((float)($m['avg_transaction'] ?? 0), 2)]);
        fputcsv($output, ['Products Sold (units)', (int)($m['products_sold'] ?? 0)]);

        // Expiry risk value
        if (columnExists($conn, 'products', 'expiry_date')) {
            $priceCol = columnExists($conn, 'products', 'selling_price') ? 'COALESCE(selling_price, price)' : 'price';
            $riskQ = "SELECT COALESCE(SUM(stock_quantity * $priceCol), 0) as risk_30
                      FROM products WHERE is_active = 1 AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
            $riskRow = $conn->query($riskQ)->fetch_assoc();
            fputcsv($output, ['Expiry Risk Value (30 days)', $cur . number_format((float)$riskRow['risk_30'], 2)]);
        }

        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 2 – DAILY SALES TREND
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== DAILY SALES TREND =====']);
        fputcsv($output, ['Date', 'Orders', 'Revenue']);

        $stmt = $conn->prepare("SELECT DATE(created_at) as sale_date, COUNT(*) as cnt, SUM(total) as rev
                                FROM sales WHERE created_at >= ? AND created_at < ?
                                GROUP BY DATE(created_at) ORDER BY sale_date ASC");
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $trendRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($trendRows as $r) {
            fputcsv($output, [$r['sale_date'], $r['cnt'], $cur . number_format((float)$r['rev'], 2)]);
        }
        if (empty($trendRows)) fputcsv($output, ['No sales in this period', '', '']);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 3 – TOP SELLING PRODUCTS (by Revenue)
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== TOP SELLING PRODUCTS =====']);
        fputcsv($output, ['Rank', 'Product Name', 'Qty Sold', 'Revenue']);

        $stmt = $conn->prepare("SELECT p.name, SUM(si.quantity) as qty, SUM(si.line_total) as rev
                                FROM sale_items si
                                JOIN products p ON si.product_id = p.product_id
                                JOIN sales s ON si.sale_id = s.sale_id
                                WHERE s.created_at >= ? AND s.created_at < ?
                                GROUP BY p.product_id, p.name ORDER BY rev DESC LIMIT 20");
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $topProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $rank = 1;
        foreach ($topProducts as $r) {
            fputcsv($output, [$rank++, $r['name'], $r['qty'], $cur . number_format((float)$r['rev'], 2)]);
        }
        if (empty($topProducts)) fputcsv($output, ['', 'No product sales in this period', '', '']);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 4 – TOP PRODUCTS BY PROFIT
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== TOP PRODUCTS BY PROFIT =====']);
        fputcsv($output, ['Rank', 'Product Name', 'Gross Profit', 'Margin %']);

        $stmt = $conn->prepare("SELECT p.name,
                                  SUM((si.unit_price - COALESCE(p.cost_price,0)) * si.quantity) as gp,
                                  CASE WHEN SUM(si.line_total)>0 THEN (SUM((si.unit_price - COALESCE(p.cost_price,0))*si.quantity)/SUM(si.line_total))*100 ELSE 0 END as margin
                                FROM sale_items si
                                JOIN products p ON si.product_id = p.product_id
                                JOIN sales s ON si.sale_id = s.sale_id
                                WHERE s.created_at >= ? AND s.created_at < ?
                                GROUP BY p.product_id, p.name ORDER BY gp DESC LIMIT 20");
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $profitRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $rank = 1;
        foreach ($profitRows as $r) {
            fputcsv($output, [$rank++, $r['name'], $cur . number_format((float)$r['gp'], 2), number_format((float)$r['margin'], 1) . '%']);
        }
        if (empty($profitRows)) fputcsv($output, ['', 'No profit data in this period', '', '']);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 5 – CATEGORY SALES BREAKDOWN
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== CATEGORY SALES BREAKDOWN =====']);
        fputcsv($output, ['Category', 'Qty Sold', 'Revenue']);

        $stmt = $conn->prepare("SELECT COALESCE(c.category_name, p.category, 'Uncategorized') as cat,
                                  SUM(si.quantity) as qty, SUM(si.line_total) as rev
                                FROM sale_items si
                                JOIN products p ON si.product_id = p.product_id
                                LEFT JOIN categories c ON p.category_id = c.category_id
                                JOIN sales s ON si.sale_id = s.sale_id
                                WHERE s.created_at >= ? AND s.created_at < ?
                                GROUP BY cat ORDER BY rev DESC");
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $catRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($catRows as $r) {
            fputcsv($output, [$r['cat'], $r['qty'], $cur . number_format((float)$r['rev'], 2)]);
        }
        if (empty($catRows)) fputcsv($output, ['No category data', '', '']);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 6 – PAYMENT METHOD MIX
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== PAYMENT METHOD MIX =====']);
        fputcsv($output, ['Payment Method', 'Transactions', 'Total Amount']);

        $stmt = $conn->prepare("SELECT COALESCE(payment_method,'Unknown') as pm, COUNT(*) as cnt, SUM(total) as amt
                                FROM sales WHERE created_at >= ? AND created_at < ?
                                GROUP BY payment_method ORDER BY amt DESC");
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $payRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($payRows as $r) {
            fputcsv($output, [$r['pm'], $r['cnt'], $cur . number_format((float)$r['amt'], 2)]);
        }
        if (empty($payRows)) fputcsv($output, ['No payment data', '', '']);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 7 – ONLINE ORDER STATUS
        // ══════════════════════════════════════════════
        if (tableExists($conn, 'online_orders')) {
            fputcsv($output, ['===== ONLINE ORDER STATUS =====']);
            fputcsv($output, ['Status', 'Count', 'Share %']);

            $stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM online_orders
                                    WHERE created_at >= ? AND created_at < ? GROUP BY status ORDER BY cnt DESC");
            $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
            $stmt->execute();
            $orderRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $totalOrders = array_sum(array_column($orderRows, 'cnt'));
            foreach ($orderRows as $r) {
                $pct = $totalOrders > 0 ? ($r['cnt'] / $totalOrders) * 100 : 0;
                fputcsv($output, [$r['status'], $r['cnt'], number_format($pct, 1) . '%']);
            }
            if (empty($orderRows)) fputcsv($output, ['No online orders', '', '']);
            fputcsv($output, []);
        }

        // ══════════════════════════════════════════════
        // SECTION 8 – LOW STOCK ALERTS
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== LOW STOCK ALERTS =====']);
        fputcsv($output, ['Product', 'Current Stock', 'Reorder Level', 'Status']);

        $lowStockQuery = "SELECT name, stock_quantity, reorder_level FROM products
                          WHERE is_active = 1 AND stock_quantity <= reorder_level ORDER BY stock_quantity ASC";
        $lowResult = $conn->query($lowStockQuery);
        $lowCount = 0;
        if ($lowResult) {
            while ($r = $lowResult->fetch_assoc()) {
                $status = $r['stock_quantity'] <= 0 ? 'OUT OF STOCK' : ($r['stock_quantity'] <= 5 ? 'CRITICAL' : 'LOW');
                fputcsv($output, [$r['name'], $r['stock_quantity'], $r['reorder_level'], $status]);
                $lowCount++;
            }
        }
        if ($lowCount === 0) fputcsv($output, ['All stock levels are healthy', '', '', '']);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 9 – EXPIRING PRODUCTS
        // ══════════════════════════════════════════════
        if (columnExists($conn, 'products', 'expiry_date')) {
            fputcsv($output, ['===== EXPIRING PRODUCTS (next 90 days) =====']);
            fputcsv($output, ['Product', 'Expiry Date', 'Days Left', 'Stock Qty', 'Status']);

            $expResult = $conn->query("SELECT name, expiry_date, stock_quantity,
                                        DATEDIFF(expiry_date, CURDATE()) as days_left
                                       FROM products
                                       WHERE is_active = 1 AND expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) <= 90
                                       ORDER BY days_left ASC");
            $expCount = 0;
            if ($expResult) {
                while ($r = $expResult->fetch_assoc()) {
                    $d = (int)$r['days_left'];
                    $status = $d < 0 ? 'EXPIRED' : ($d <= 7 ? 'URGENT' : ($d <= 30 ? 'WARNING' : 'MONITOR'));
                    fputcsv($output, [$r['name'], $r['expiry_date'], $d, $r['stock_quantity'], $status]);
                    $expCount++;
                }
            }
            if ($expCount === 0) fputcsv($output, ['No products expiring within 90 days', '', '', '', '']);
            fputcsv($output, []);
        }

        // ══════════════════════════════════════════════
        // SECTION 10 – DEAD STOCK (90+ days no sale)
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== DEAD STOCK (90+ days unsold) =====']);
        fputcsv($output, ['Product', 'Stock Qty', 'Last Sale Date']);

        $stmt = $conn->prepare("SELECT p.name, p.stock_quantity, recent.last_sale_date
                                FROM products p
                                LEFT JOIN (
                                    SELECT si.product_id, MAX(DATE(s.created_at)) as last_sale_date
                                    FROM sale_items si JOIN sales s ON si.sale_id = s.sale_id GROUP BY si.product_id
                                ) recent ON p.product_id = recent.product_id
                                WHERE p.is_active = 1 AND p.stock_quantity > 0
                                  AND (recent.last_sale_date IS NULL OR recent.last_sale_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY))
                                ORDER BY recent.last_sale_date IS NULL DESC, recent.last_sale_date ASC LIMIT 30");
        $stmt->execute();
        $deadRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($deadRows as $r) {
            fputcsv($output, [$r['name'], $r['stock_quantity'], $r['last_sale_date'] ?? 'Never sold']);
        }
        if (empty($deadRows)) fputcsv($output, ['No dead stock found', '', '']);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 11 – SLOW MOVERS
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== SLOW MOVERS (< 5 units in 90 days) =====']);
        fputcsv($output, ['Product', 'Qty Sold (90d)', 'Current Stock']);

        $stmt = $conn->prepare("SELECT p.name, p.stock_quantity,
                                  COALESCE(SUM(CASE WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN si.quantity ELSE 0 END),0) as qty_90
                                FROM products p
                                LEFT JOIN sale_items si ON p.product_id = si.product_id
                                LEFT JOIN sales s ON si.sale_id = s.sale_id
                                WHERE p.is_active = 1
                                GROUP BY p.product_id, p.name, p.stock_quantity
                                HAVING qty_90 > 0 AND qty_90 < 5
                                ORDER BY qty_90 ASC LIMIT 30");
        $stmt->execute();
        $slowRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($slowRows as $r) {
            fputcsv($output, [$r['name'], $r['qty_90'], $r['stock_quantity']]);
        }
        if (empty($slowRows)) fputcsv($output, ['No slow movers found', '', '']);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 12 – TOP CUSTOMERS
        // ══════════════════════════════════════════════
        if (tableExists($conn, 'online_orders')) {
            fputcsv($output, ['===== TOP CUSTOMERS =====']);
            fputcsv($output, ['Customer', 'Email', 'Orders', 'Total Spent', 'Last Order']);

            $stmt = $conn->prepare("SELECT customer_name, email, COUNT(*) as cnt, SUM(total_amount) as spent,
                                      DATE(MAX(created_at)) as last_dt
                                    FROM online_orders
                                    WHERE created_at >= ? AND created_at < ?
                                    GROUP BY customer_name, email ORDER BY spent DESC LIMIT 20");
            $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
            $stmt->execute();
            $custRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($custRows as $r) {
                fputcsv($output, [$r['customer_name'], $r['email'], $r['cnt'], $cur . number_format((float)$r['spent'], 2), $r['last_dt']]);
            }
            if (empty($custRows)) fputcsv($output, ['No customer orders in this period', '', '', '', '']);
            fputcsv($output, []);
        }

        // ══════════════════════════════════════════════
        // SECTION 13 – CASHIER PERFORMANCE
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== CASHIER PERFORMANCE =====']);
        fputcsv($output, ['Cashier', 'Transactions', 'Revenue']);

        $stmt = $conn->prepare("SELECT s.cashier, COUNT(s.sale_id) as cnt, SUM(s.total) as rev
                                FROM sales s WHERE s.created_at >= ? AND s.created_at < ?
                                GROUP BY s.cashier ORDER BY rev DESC");
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $cashierRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($cashierRows as $r) {
            fputcsv($output, [$r['cashier'], $r['cnt'], $cur . number_format((float)$r['rev'], 2)]);
        }
        if (empty($cashierRows)) fputcsv($output, ['No cashier data', '', '']);
        fputcsv($output, []);

        // ══════════════════════════════════════════════
        // SECTION 14 – ALL TRANSACTIONS (detail)
        // ══════════════════════════════════════════════
        fputcsv($output, ['===== TRANSACTION DETAILS =====']);
        fputcsv($output, ['Sale ID', 'Date', 'Cashier', 'Payment Method', 'Total']);

        $stmt = $conn->prepare("SELECT sale_id, created_at, cashier, payment_method, total
                                FROM sales WHERE created_at >= ? AND created_at < ?
                                ORDER BY created_at DESC");
        $stmt->bind_param("ss", $startTimestamp, $endDateNextDay);
        $stmt->execute();
        $txnResult = $stmt->get_result();
        $txnCount = 0;
        while ($r = $txnResult->fetch_assoc()) {
            fputcsv($output, [$r['sale_id'], $r['created_at'], $r['cashier'], $r['payment_method'], $cur . number_format((float)$r['total'], 2)]);
            $txnCount++;
        }
        $stmt->close();
        if ($txnCount === 0) fputcsv($output, ['No transactions in this period', '', '', '', '']);
        fputcsv($output, []);

        // ── Footer ──
        fputcsv($output, []);
        fputcsv($output, ['--- END OF REPORT ---']);
        fputcsv($output, ['Total sections: 14', 'Transactions exported: ' . $txnCount]);

        fclose($output);
        exit;
        
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action'
        ]);
}
?>
