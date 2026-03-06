<?php
/**
 * Pharmacy Online Ordering - Connected to Database
 * Fetches real medicine data from the products table
 */
// Load config FIRST so session security settings apply before session_start()
require_once 'db_connection.php';
require_once 'ImageHelper.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure tables exist — only run once per session to avoid repeated DDL on every page load
if (empty($_SESSION['_online_tables_checked'])) {
    $conn->query("CREATE TABLE IF NOT EXISTS online_orders (
        order_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NULL,
        customer_name VARCHAR(100) NOT NULL,
        contact_number VARCHAR(20) NULL,
        email VARCHAR(100) NULL,
        status ENUM('Pending','Confirmed','Preparing','Ready','Completed','Cancelled') DEFAULT 'Pending',
        total_amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50) DEFAULT 'Cash on Pickup',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS online_order_items (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(255) NOT NULL,
        quantity INT NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES online_orders(order_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS pos_notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        type VARCHAR(50) DEFAULT 'online_order',
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES online_orders(order_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $_SESSION['_online_tables_checked'] = true;
}

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = $isLoggedIn && (($_SESSION['role_name'] ?? '') === 'admin');
$customerName = $isLoggedIn ? ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer') : '';

function normalizeImagePathSegments($path) {
    $path = str_replace('\\', '/', (string)$path);
    $segments = explode('/', $path);
    foreach ($segments as &$segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        $segment = rawurlencode(rawurldecode($segment));
    }
    unset($segment);
    return implode('/', $segments);
}

function normalizeImageUrl($url) {
    $url = trim((string)$url);
    if ($url === '' || stripos($url, 'data:') === 0) {
        return $url;
    }

    $query = '';
    $fragment = '';

    $hashPos = strpos($url, '#');
    if ($hashPos !== false) {
        $fragment = substr($url, $hashPos);
        $url = substr($url, 0, $hashPos);
    }

    $queryPos = strpos($url, '?');
    if ($queryPos !== false) {
        $query = substr($url, $queryPos);
        $url = substr($url, 0, $queryPos);
    }

    if (preg_match('#^(https?:)?//#i', $url)) {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url . $query . $fragment;
        }

        $scheme = isset($parts['scheme']) ? ($parts['scheme'] . '://') : '//';
        $auth = '';
        if (isset($parts['user'])) {
            $auth = $parts['user'];
            if (isset($parts['pass'])) {
                $auth .= ':' . $parts['pass'];
            }
            $auth .= '@';
        }

        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? (':' . $parts['port']) : '';
        $path = normalizeImagePathSegments($parts['path'] ?? '');
        $finalQuery = isset($parts['query']) ? ('?' . $parts['query']) : $query;
        $finalFragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : $fragment;

        return $scheme . $auth . $host . $port . $path . $finalQuery . $finalFragment;
    }

    return normalizeImagePathSegments($url) . $query . $fragment;
}

// Fetch categories for filter buttons
$catQuery = "SELECT category_id, category_name FROM categories ORDER BY category_name ASC";
$catResult = $conn->query($catQuery);
$categories = [];
if ($catResult) {
    while ($row = $catResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Build map of category_name => first product image (optimized: one row per category)
$catImageMap = [];
$imgQuery = "SELECT c.category_name,
                    (SELECT p2.image_url FROM products p2
                     WHERE p2.category_id = c.category_id
                       AND p2.is_active = 1 AND p2.stock_quantity > 0
                       AND (p2.expiry_date IS NULL OR p2.expiry_date >= CURDATE())
                       AND p2.image_url IS NOT NULL AND p2.image_url != ''
                     ORDER BY p2.name ASC LIMIT 1) as image_url,
                    (SELECT p3.name FROM products p3
                     WHERE p3.category_id = c.category_id
                       AND p3.is_active = 1 AND p3.stock_quantity > 0
                       AND (p3.expiry_date IS NULL OR p3.expiry_date >= CURDATE())
                       AND p3.image_url IS NOT NULL AND p3.image_url != ''
                     ORDER BY p3.name ASC LIMIT 1) as product_name
             FROM categories c";
$imgResult = $conn->query($imgQuery);
if ($imgResult) {
    while ($imgRow = $imgResult->fetch_assoc()) {
        if (!empty($imgRow['image_url'])) {
            $catImageMap[$imgRow['category_name']] = resolveProductImageUrl((string)$imgRow['image_url'], (string)($imgRow['product_name'] ?? ''));
        }
    }
}

// Fetch products that are active, in stock, and not expired
$prodQuery = "
    SELECT 
        p.product_id,
        p.name,
        p.description,
        p.selling_price,
        p.stock_quantity,
        p.sku,
        p.image_url,
        c.category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.is_active = 1
            AND p.stock_quantity > 0
            AND (p.expiry_date IS NULL OR p.expiry_date >= CURDATE())
    ORDER BY p.name ASC
";
$prodResult = $conn->query($prodQuery);
$products = [];
if ($prodResult) {
    while ($row = $prodResult->fetch_assoc()) {
        $row['image_url'] = resolveProductImageUrl((string)($row['image_url'] ?? ''), (string)($row['name'] ?? ''));
        $products[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Online Ordering - Calloway Pharmacy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="custom-modal.css">
    <script defer src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" onerror="console.warn('QRCode CDN failed to load, using fallback');"></script>
    <script src="custom-modal.js"></script>
    <script src="theme.js"></script>
    <style>
        :root {
            --bg-color: #f0f4f8;
            --text-color: #333333;
            --primary-blue: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-bg: #e0ecff;
            --card-bg: #ffffff;
            --accent: #4FC3F7;
            --shadow: rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.1);
            --text-light: #6b7280;
            --danger: #ef4444;
            --success: #22c55e;
            --warning: #f59e0b;
        }

        [data-theme="dark"] {
            --bg-color: #0f172a;
            --text-color: #e2e8f0;
            --primary-blue: #3b82f6;
            --primary-dark: #2563eb;
            --secondary-bg: #1e293b;
            --card-bg: #1e293b;
            --accent: #4FC3F7;
            --shadow: rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.3);
            --text-light: #94a3b8;
            --danger: #f87171;
            --success: #4ade80;
            --warning: #fbbf24;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
            min-height: 100vh;
            margin: 0;
            padding: 60px 0 0;
        }

        /* ─── Topbar Adjustments ─── */
        /* Note: Header layout is now handled by header-component.php flexbox */
        
        .topbar .browse-toolbar-row {
            width: 100%;
            max-width: 600px;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .topbar .toolbar-logo {
            display: none; /* Logo is already in header-component */
        }

        /* Mobile: compact topbar search */
        @media (max-width: 768px) {
            .topbar .browse-toolbar-row {
                max-width: 100%;
                gap: 0.5rem;
            }
            .topbar .search-bar input {
                padding: 0.55rem 0.75rem 0.55rem 2.2rem;
                font-size: 0.8rem;
                border-radius: 10px;
            }
            .topbar .search-bar i {
                left: 0.65rem;
                font-size: 0.8rem;
            }
            /* On this page, hide brand text on mobile — search bar + buttons need the space */
            .topbar-brand-text { display: none; }
            .topbar-logo-img { width: 34px; height: 34px; }
            .topbar { padding: 0 0.75rem; }
        }

        .topbar .search-bar {
            flex: 1;
            width: 100%;
        }

        .topbar .search-bar input {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .topbar .search-bar input::placeholder {
            color: rgba(255,255,255,0.7);
        }

        .topbar .search-bar i {
            color: rgba(255,255,255,0.7);
        }

        .topbar-wishlist-btn, .topbar-cart-btn {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            height: 38px;
            border-radius: 20px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 600;
            gap: 0.5rem;
            padding: 0 1rem;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .topbar-wishlist-btn:hover, .topbar-cart-btn:hover { 
            background: rgba(255,255,255,0.2); 
            transform: translateY(-1px); 
        }
        
        .wishlist-count-badge, .cart-count-badge {
            background: #ef4444; 
            color: white;
            font-size: 0.75rem;
            font-weight: 800;
            padding: 0.1rem 0.45rem;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
            line-height: 1.3;
        }

        /* Wishlist Panel (mirrors cart panel) */
        .wishlist-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1200;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        .wishlist-overlay.open { opacity: 1; visibility: visible; }

        .wishlist-panel {
            position: fixed;
            top: 0;
            left: -460px;
            width: 440px;
            max-width: 90vw;
            height: 100vh;
            background: var(--card-bg);
            z-index: 1201;
            display: flex;
            flex-direction: column;
            box-shadow: 8px 0 30px rgba(0,0,0,0.15);
            transition: left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .wishlist-panel.open { left: 0; }

        .wishlist-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.08);
            background: var(--secondary-bg);
        }
        .wishlist-panel-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #ef4444;
        }

        .wishlist-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.2rem;
        }

        .wishlist-empty {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-light);
        }
        .wishlist-empty i { font-size: 3rem; opacity: 0.25; margin-bottom: 1rem; display: block; }
        .wishlist-empty p { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.3rem; color: var(--text-color); }

        .wishlist-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 12px;
            margin-bottom: 0.8rem;
            transition: all 0.2s;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .wishlist-item:hover { border-color: rgba(37,99,235,0.15); }

        .wishlist-item-img {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            background: var(--secondary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }
        .wishlist-item-img img { width: 100%; height: 100%; object-fit: cover; }
        .wishlist-item-img i { font-size: 1.5rem; color: var(--text-light); }

        .wishlist-item-details {
            flex: 1;
            min-width: 0;
        }
        .wishlist-item-name {
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 0.2rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .wishlist-item-price {
            font-weight: 800;
            color: var(--primary-blue);
            font-size: 1rem;
        }

        .wishlist-item-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.3rem;
        }
        .wishlist-item-actions button {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid rgba(0,0,0,0.1);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 0.85rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .wishlist-item-actions .wl-cart-btn:hover { background: var(--primary-blue); color: white; border-color: var(--primary-blue); }
        .wishlist-item-actions .wl-remove-btn:hover { background: var(--danger); color: white; border-color: var(--danger); }

        @media (max-width: 768px) {
            .wishlist-panel { width: 100%; max-width: 100vw; left: -100%; }
            .topbar-wishlist-btn span:not(.wishlist-count-badge) { display: none; }
            .topbar-wishlist-btn, .topbar-cart-btn {
                min-width: 44px;
                height: 34px;
                border-radius: 999px;
                padding: 0 0.55rem;
            }
        }

        .topbar-cart-btn {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            height: 38px;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 600;
            gap: 0.4rem;
            padding: 0 0.8rem;
            transition: all 0.2s;
            justify-self: end;
            white-space: nowrap;
        }
        .topbar-cart-btn:hover { background: rgba(255,255,255,0.25); transform: scale(1.05); }
        .topbar-cart-btn .cart-count-badge {
            background: white;
            color: var(--primary-blue, #2563eb);
            font-size: 0.75rem;
            font-weight: 800;
            padding: 0.1rem 0.45rem;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
            line-height: 1.3;
        }

        /* ─── My Orders Button & Panel ─── */
        .topbar-orders-btn {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            height: 38px;
            border-radius: 20px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 600;
            gap: 0.5rem;
            padding: 0 1rem;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .topbar-orders-btn:hover { background: rgba(255,255,255,0.25); transform: scale(1.05); }

        .orders-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1200;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        .orders-overlay.open { opacity: 1; visibility: visible; }

        .orders-panel {
            position: fixed;
            top: 0;
            right: -500px;
            width: 480px;
            max-width: 95vw;
            height: 100vh;
            background: var(--card-bg);
            z-index: 1201;
            display: flex;
            flex-direction: column;
            box-shadow: -8px 0 30px rgba(0,0,0,0.15);
            transition: right 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .orders-panel.open { right: 0; }

        .orders-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.08);
            background: var(--secondary-bg);
            flex-shrink: 0;
        }
        .orders-panel-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-blue);
            margin: 0;
        }

        .orders-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.2rem;
        }

        .orders-empty {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-light);
        }
        .orders-empty i { font-size: 3rem; opacity: 0.25; margin-bottom: 1rem; display: block; }
        .orders-empty p { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.3rem; color: var(--text-color); }

        .orders-loading {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-light);
        }

        .order-card {
            background: var(--bg-color);
            border-radius: 14px;
            padding: 1rem 1.2rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            transition: all 0.25s;
        }
        .order-card:hover { border-color: rgba(37,99,235,0.2); box-shadow: 0 2px 10px rgba(0,0,0,0.06); }

        .order-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.65rem;
        }
        .order-card-ref {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--primary-blue);
            font-family: 'Courier New', monospace;
        }

        .order-status-badge {
            padding: 0.2rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .order-status-badge.pending { background: #fef3c7; color: #92400e; }
        .order-status-badge.confirmed { background: #dbeafe; color: #1e40af; }
        .order-status-badge.preparing { background: #e0e7ff; color: #3730a3; }
        .order-status-badge.ready { background: #d1fae5; color: #065f46; }
        .order-status-badge.completed { background: #dcfce7; color: #166534; }
        .order-status-badge.cancelled { background: #fee2e2; color: #991b1b; }

        .order-card-items {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            line-height: 1.5;
        }
        .order-card-items .item-line {
            display: flex;
            justify-content: space-between;
            padding: 0.15rem 0;
        }

        .order-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 0.6rem;
            border-top: 1px solid rgba(0,0,0,0.06);
            margin-top: 0.4rem;
        }
        .order-card-total {
            font-weight: 800;
            font-size: 1.05rem;
            color: var(--text-color);
        }
        .order-card-date {
            font-size: 0.78rem;
            color: var(--text-light);
        }

        /* Order status tracker steps */
        .order-tracker {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 0.75rem 0 0.5rem;
            padding: 0 0.5rem;
            position: relative;
        }
        .order-tracker::before {
            content: '';
            position: absolute;
            top: 14px;
            left: 28px;
            right: 28px;
            height: 3px;
            background: rgba(0,0,0,0.08);
            z-index: 0;
        }
        .tracker-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 1;
            gap: 0.3rem;
        }
        .tracker-dot {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            color: var(--text-light);
            transition: all 0.3s;
        }
        .tracker-dot.active {
            background: var(--primary-blue);
            color: white;
            box-shadow: 0 2px 8px rgba(37,99,235,0.3);
        }
        .tracker-dot.done {
            background: #22c55e;
            color: white;
        }
        .tracker-dot.cancelled-dot {
            background: #ef4444;
            color: white;
        }
        .tracker-label {
            font-size: 0.62rem;
            font-weight: 600;
            color: var(--text-light);
            text-align: center;
            max-width: 55px;
        }
        .tracker-label.active { color: var(--primary-blue); }
        .tracker-label.done { color: #22c55e; }

        @media (max-width: 768px) {
            .orders-panel { width: 100%; max-width: 100vw; right: -100%; }
            .topbar-orders-btn span:not(.orders-count-badge) { display: none; }
            .topbar-orders-btn { min-width: 44px; height: 34px; border-radius: 999px; padding: 0 0.55rem; }
        }


        .btn {
            padding: 0.6rem 1.2rem;
            background-color: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            transition: background 0.2s, transform 0.2s;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
        }

        /* ─── Browse Toolbar (search + categories in one row) ─── */
        .browse-toolbar {
            max-width: 1400px;
            margin: 0 auto;
            margin-top: 60px;
            padding: 1.2rem 2rem;
            background: var(--card-bg);
            box-shadow: var(--shadow-md);
            display: none;
        }

        .browse-toolbar-row {
            display: flex;
            align-items: center;
            gap: 1.2rem;
            justify-content: center;
        }

        .toolbar-logo {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 800;
            font-size: 1.5rem;
            color: var(--primary-blue);
            white-space: nowrap;
            flex-shrink: 0;
            letter-spacing: -0.01em;
        }

        .search-bar {
            position: relative;
            flex: 1;
            max-width: 82%;
        }

        .search-bar input {
            width: 100%;
            padding: 0.9rem 1.2rem 0.9rem 3rem;
            border: 2px solid var(--input-border, rgba(0,0,0,0.12));
            border-radius: 14px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 1.05rem;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .search-bar input::placeholder {
            color: var(--text-light);
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 1rem;
        }

        /* ─── Admin Controls ─── */
        .admin-actions {
            display: flex;
            gap: 0.4rem;
            margin-top: 0.5rem;
        }

        .admin-btn {
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 0.72rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s;
        }

        .admin-btn-edit {
            background: rgba(37,99,235,0.1);
            color: var(--primary-blue);
        }
        .admin-btn-edit:hover { background: var(--primary-blue); color: white; }

        .admin-btn-delete {
            background: rgba(239,68,68,0.1);
            color: var(--danger);
        }
        .admin-btn-delete:hover { background: var(--danger); color: white; }

        .admin-btn-img {
            background: rgba(16,185,129,0.1);
            color: var(--success);
        }
        .admin-btn-img:hover { background: var(--success); color: white; }

        .admin-category-actions {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            display: flex;
            gap: 0.3rem;
            z-index: 2;
        }

        .category-card { position: relative; }

        /* ─── Admin Modal ─── */
        .admin-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }
        .admin-modal-overlay.active { display: flex; }

        .admin-modal {
            background: var(--card-bg);
            border-radius: 16px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .admin-modal-header {
            padding: 1rem 1.25rem;
            background: var(--primary-blue);
            color: white;
            font-weight: 700;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .admin-modal-header button {
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.8;
        }
        .admin-modal-header button:hover { opacity: 1; }

        .admin-modal-body {
            padding: 1.25rem;
        }

        .admin-modal-body label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
            color: var(--text-color);
        }

        .admin-modal-body input,
        .admin-modal-body textarea,
        .admin-modal-body select {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--input-border, #e2e8f0);
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            font-size: 0.9rem;
            margin-bottom: 0.8rem;
        }

        .admin-modal-body textarea { resize: vertical; min-height: 60px; }

        .admin-modal-body .img-preview {
            width: 100%;
            max-height: 150px;
            object-fit: contain;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: var(--bg-color);
        }

        .admin-modal-footer {
            padding: 0.75rem 1.25rem;
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            border-top: 1px solid rgba(0,0,0,0.08);
        }

        .admin-modal-footer button {
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .admin-save-btn { background: var(--primary-blue); color: white; }
        .admin-save-btn:hover { background: var(--primary-dark); }
        .admin-cancel-btn { background: var(--bg-color); color: var(--text-color); }
        .admin-cancel-btn:hover { background: var(--card-bg); }

        /* ─── Category Cards Grid (initial view) ─── */
        .category-cards-section {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 2rem 2rem;
        }

        .category-cards-section h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 1.2rem;
        }

        .category-cards-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.2rem;
        }

        .category-cards-section {
            max-width: 1400px;
        }

        .category-card {
            padding: 2.2rem 1.5rem;
        }

        .category-card-icon {
            font-size: 2.8rem;
        }

        .category-card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 2rem 1.2rem;
            text-align: center;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            box-shadow: 0 2px 8px var(--shadow);
            border: 2px solid transparent;
        }

        .category-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px var(--shadow);
            border-color: var(--primary-blue);
        }

        .category-card-icon {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
        }

        .category-card-name {
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 0.3rem;
        }

        .category-card-count {
            font-size: 0.78rem;
            color: var(--text-light);
        }

        .category-card-img {
            width: 100%;
            height: 120px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 0.75rem;
        }

        .category-card-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
            transition: transform 0.3s ease;
        }

        .category-card:hover .category-card-img img {
            transform: scale(1.05);
        }

        /* ─── QR Loyalty Promo Banner ─── */
        .qr-promo-banner {
            max-width: 1400px;
            margin: 0 auto 0;
            padding: 0 2rem;
        }
        .qr-promo-inner {
            background: #1d4ed8;
            border-radius: 18px;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(99,102,241,0.3);
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .qr-promo-inner:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(99,102,241,0.45);
        }
        .qr-promo-inner::before {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 160px;
            height: 160px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }
        .qr-promo-inner::after {
            content: '';
            position: absolute;
            bottom: -30px;
            left: 30%;
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }
        .qr-promo-icon {
            font-size: 2.8rem;
            flex-shrink: 0;
            z-index: 1;
            background: rgba(255,255,255,0.15);
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
        }
        .qr-promo-text {
            flex: 1;
            z-index: 1;
        }
        .qr-promo-text h3 {
            margin: 0 0 0.25rem;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .qr-promo-text p {
            margin: 0;
            font-size: 0.88rem;
            opacity: 0.9;
            line-height: 1.4;
        }
        .qr-promo-cta {
            z-index: 1;
            flex-shrink: 0;
            background: rgba(255,255,255,0.2);
            border: 2px solid rgba(255,255,255,0.5);
            color: #fff;
            padding: 0.55rem 1.3rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            transition: background 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .qr-promo-cta:hover {
            background: rgba(255,255,255,0.35);
        }
        .qr-promo-sparkles {
            position: absolute;
            top: 8px;
            right: 80px;
            font-size: 1.2rem;
            opacity: 0.5;
            animation: sparkle 2s ease-in-out infinite;
            z-index: 1;
        }
        @keyframes sparkle {
            0%, 100% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.2); }
        }
        @media (max-width: 640px) {
            .qr-promo-inner {
                flex-direction: column;
                text-align: center;
                padding: 1.2rem 1rem;
                gap: 0.8rem;
            }
            .qr-promo-icon { width: 56px; height: 56px; font-size: 2rem; }
            .qr-promo-text h3 { font-size: 1rem; }
        }

        /* ─── Product Grid ─── */
        .products-section {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 2rem 4rem;
            display: none;
        }

        .products-section.visible { display: block; }

        /* Category Title (big, like Southstar) */
        .category-title-banner {
            text-align: center;
            padding: 1.5rem 0 0.5rem;
        }
        .category-title-banner h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-color);
            margin: 0;
        }

        .products-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.06);
        }

        .products-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .products-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-to-categories {
            background: none;
            border: 1px solid var(--primary-blue);
            color: var(--primary-blue);
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.2s;
        }
        .back-to-categories:hover { background: var(--primary-blue); color: white; }

        /* View toggle buttons */
        .view-toggle-btns {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .view-toggle-btns button {
            width: 34px;
            height: 34px;
            border: 1px solid rgba(0,0,0,0.12);
            background: var(--card-bg);
            color: var(--text-light);
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .view-toggle-btns button.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }
        .view-toggle-btns button:hover:not(.active) {
            border-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .products-header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sort-control {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.85rem;
            color: var(--text-light);
        }
        .sort-control select {
            padding: 0.4rem 0.6rem;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 0.85rem;
            cursor: pointer;
        }

        .product-count {
            color: var(--text-light);
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
        }

        /* ─── List View (Southstar-style horizontal cards) ─── */
        .products-grid.list-view {
            grid-template-columns: 1fr;
            gap: 0;
        }

        .products-grid.list-view .product-card {
            flex-direction: row;
            border-radius: 0;
            border: none;
            border-bottom: 1px solid rgba(0,0,0,0.08);
            box-shadow: none;
            padding: 1.5rem 1rem;
            gap: 1.5rem;
            align-items: flex-start;
        }

        .products-grid.list-view .product-card:hover {
            transform: none;
            box-shadow: none;
            background: rgba(37, 99, 235, 0.03);
        }

        .products-grid.list-view .product-image {
            width: 180px;
            min-width: 180px;
            height: 180px;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            flex-shrink: 0;
        }

        [data-theme="dark"] .products-grid.list-view .product-image {
            background: var(--secondary-bg);
        }

        .products-grid.list-view .product-content {
            padding: 0;
            flex: 1;
        }

        .products-grid.list-view .product-name {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
        }

        .products-grid.list-view .product-desc {
            -webkit-line-clamp: 3;
            margin-bottom: 0.8rem;
            font-size: 0.85rem;
        }

        .products-grid.list-view .product-price {
            font-size: 1.2rem;
            margin-bottom: 0.6rem;
        }

        .products-grid.list-view .product-footer {
            border-top: none;
            padding-top: 0;
            margin-top: 0.5rem;
            justify-content: flex-start;
            gap: 0.75rem;
        }

        .products-grid.list-view .add-cart-btn {
            width: auto;
            height: auto;
            border-radius: 8px;
            padding: 0.6rem 1.4rem;
            font-size: 0.9rem;
            font-weight: 700;
            background: var(--primary-blue);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .products-grid.list-view .add-cart-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }
        .products-grid.list-view .add-cart-btn .btn-text { display: inline; }
        .products-grid.list-view .add-cart-btn i.fa-plus { display: none; }

        .products-grid.list-view .wishlist-btn {
            display: flex;
        }

        /* ─── Wishlist heart button (hidden in grid, visible in list) ─── */
        .wishlist-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1px solid rgba(0,0,0,0.12);
            background: var(--card-bg);
            color: var(--text-light);
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .wishlist-btn:hover {
            color: #ef4444;
            border-color: #ef4444;
            background: rgba(239,68,68,0.06);
        }
        .wishlist-btn.active {
            color: #ef4444;
            background: rgba(239,68,68,0.1);
            border-color: #ef4444;
        }

        /* ─── Grid View (default card style) ─── */
        .product-card {
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px var(--shadow);
            transition: transform 0.15s, box-shadow 0.15s;
            display: flex;
            flex-direction: column;
            border: 1px solid transparent;
        }

        .product-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 24px var(--shadow);
            border-color: rgba(37, 99, 235, 0.15);
        }

        .product-image {
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            background: #eff6ff;
        }

        [data-theme="dark"] .product-image {
            background: #1e293b;
        }

        .product-content {
            padding: 1.2rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-category-tag {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--primary-blue);
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .product-name {
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            line-height: 1.3;
        }

        .product-desc {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-bottom: 0.6rem;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Hide btn-text and cart-plus icon in grid view, show in list view */
        .add-cart-btn .btn-text { display: none; }
        .add-cart-btn i.fa-cart-plus { display: none; }
        .products-grid.list-view .add-cart-btn i.fa-cart-plus { display: inline; }

        .product-stock {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            margin-bottom: auto;
        }

        .stock-good { color: var(--success); }
        .stock-low { color: var(--warning); }
        .stock-out { color: var(--danger); }

        .out-of-stock-badge {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--danger);
            background: rgba(239,68,68,0.1);
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .product-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 0.8rem;
            border-top: 1px solid rgba(0,0,0,0.06);
        }

        .product-price {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--primary-blue);
        }

        .add-cart-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid var(--primary-blue);
            background: transparent;
            color: var(--primary-blue);
            font-size: 1rem;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .add-cart-btn:hover {
            background: var(--primary-blue);
            color: white;
            transform: rotate(90deg) scale(1.1);
        }

        .add-cart-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ─── Empty State ─── */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-state i { font-size: 3rem; opacity: 0.25; margin-bottom: 1rem; }
        .empty-state h3 { margin-bottom: 0.5rem; }
        .empty-state p { color: var(--text-light); }

        /* ─── Floating Cart Button ─── */
        .floating-cart {
            position: fixed;
            bottom: 2rem;
            left: 2rem;
            z-index: 200;
            display: none;
        }

        .floating-cart button {
            background: var(--primary-blue);
            color: white;
            padding: 0.9rem 1.8rem;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 24px rgba(37,99,235,0.4);
            display: flex;
            align-items: center;
            gap: 0.6rem;
            transition: all 0.3s ease;
        }

        .floating-cart button:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 32px rgba(37,99,235,0.5);
        }

        .cart-badge {
            background: white;
            color: var(--primary-blue);
            padding: 0.15rem 0.55rem;
            border-radius: 20px;
            font-weight: 800;
            font-size: 0.85rem;
        }

        /* ─── Toast ─── */
        .toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .toast {
            background: var(--card-bg);
            color: var(--text-color);
            padding: 0.8rem 1.2rem;
            border-radius: 10px;
            box-shadow: 0 4px 16px var(--shadow);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            animation: slideIn 0.3s ease;
            border-left: 4px solid var(--success);
        }

        .toast.info { border-left-color: var(--primary-blue); }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(100%); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* (footer styled inline via mega-footer class) */

        /* ─── Cart Sidebar Panel ─── */
        .cart-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1200;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .cart-overlay.open {
            opacity: 1;
            visibility: visible;
        }

        .cart-panel {
            position: fixed;
            top: 0;
            right: -460px;
            width: 440px;
            max-width: 90vw;
            height: 100vh;
            background: var(--card-bg);
            z-index: 1201;
            display: flex;
            flex-direction: column;
            box-shadow: -8px 0 30px rgba(0,0,0,0.15);
            transition: right 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .cart-panel.open {
            right: 0;
        }

        .cart-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.08);
            background: var(--secondary-bg);
        }

        .cart-panel-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-blue);
        }

        .cart-close-btn {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: var(--text-light);
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .cart-close-btn:hover {
            background: rgba(239,68,68,0.1);
            color: var(--danger);
        }

        .cart-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.2rem;
        }

        .cart-empty {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-light);
        }

        .cart-empty i {
            font-size: 3rem;
            opacity: 0.25;
            margin-bottom: 1rem;
            display: block;
        }

        .cart-empty p {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
            color: var(--text-color);
        }

        .cart-empty span {
            font-size: 0.85rem;
        }

        /* Cart Item */
        .cart-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 12px;
            margin-bottom: 0.8rem;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .cart-item:hover {
            border-color: rgba(37,99,235,0.15);
        }

        .cart-item-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: var(--secondary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .cart-item-details {
            flex: 1;
            min-width: 0;
        }

        .cart-item-name {
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 0.2rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cart-item-price-info {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-bottom: 0.4rem;
        }

        .cart-item-subtotal {
            font-weight: 800;
            color: var(--primary-blue);
            font-size: 1rem;
        }

        .cart-item-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.3rem;
        }

        .qty-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: 1px solid rgba(0,0,0,0.1);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .qty-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .qty-btn.remove:hover {
            background: var(--danger);
            border-color: var(--danger);
        }

        .cart-item-qty {
            font-weight: 700;
            font-size: 0.95rem;
            min-width: 20px;
            text-align: center;
        }

        /* Cart Footer */
        .cart-panel-footer {
            padding: 1.2rem 1.5rem;
            border-top: 1px solid rgba(0,0,0,0.08);
            background: var(--secondary-bg);
            position: sticky;
            bottom: 0;
            z-index: 2;
        }

        .cart-summary {
            margin-bottom: 1rem;
        }

        .cart-summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.3rem 0;
            font-size: 0.9rem;
            color: var(--text-light);
        }

        .cart-summary-row.total {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--text-color);
            padding: 0.6rem 0;
            border-top: 2px solid rgba(0,0,0,0.08);
            margin-top: 0.3rem;
        }

        .cart-summary-row.total span:last-child {
            color: var(--primary-blue);
        }

        .cart-summary-row.items-count {
            font-size: 0.8rem;
        }

        .cart-checkout-btn {
            width: 100%;
            padding: 0.9rem;
            background: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .cart-checkout-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .cart-clear-btn {
            width: 100%;
            padding: 0.6rem;
            background: transparent;
            color: var(--danger);
            border: 1px solid var(--danger);
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }

        .cart-clear-btn:hover {
            background: var(--danger);
            color: white;
        }

        /* ─── Product Detail Modal ─── */
        .product-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }
        .product-modal-overlay.active { display: flex; }

        .product-modal {
            background: var(--card-bg);
            border-radius: 20px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
            animation: slideUp 0.3s ease;
            display: flex;
            flex-direction: row;
        }

        .product-modal-image {
            width: 400px;
            min-width: 400px;
            min-height: 400px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 20px 0 0 20px;
            overflow: hidden;
            flex-shrink: 0;
        }
        [data-theme="dark"] .product-modal-image {
            background: var(--secondary-bg);
        }
        .product-modal-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            max-height: 450px;
        }
        .product-modal-image i {
            font-size: 5rem;
            color: var(--text-light);
        }

        .product-modal-details {
            flex: 1;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            min-width: 0;
            position: relative;
        }

        .product-modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--bg-color);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: var(--text-light);
            transition: all 0.2s;
            z-index: 1;
        }
        .product-modal-close:hover {
            background: rgba(239,68,68,0.1);
            color: var(--danger);
        }

        .pm-category {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--primary-blue);
            font-weight: 700;
        }

        .pm-name {
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.25;
            color: var(--text-color);
            margin-bottom: 0.25rem;
        }

        .pm-price {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-color);
            margin-bottom: 0.25rem;
        }

        .pm-stock {
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            margin-bottom: 0.5rem;
        }

        .pm-wishlist-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            border: 1px solid rgba(0,0,0,0.12);
            background: var(--card-bg);
            color: var(--text-color);
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            width: fit-content;
            margin-bottom: 0.5rem;
        }
        .pm-wishlist-btn:hover {
            color: #ef4444;
            border-color: #ef4444;
        }

        .pm-qty-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }
        .pm-qty-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-color);
        }
        .pm-qty-controls {
            display: flex;
            align-items: center;
            border: 1px solid rgba(0,0,0,0.15);
            border-radius: 8px;
            overflow: hidden;
        }
        .pm-qty-controls button {
            width: 38px;
            height: 38px;
            border: none;
            background: var(--bg-color);
            color: var(--text-color);
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
        }
        .pm-qty-controls button:hover {
            background: var(--primary-blue);
            color: white;
        }
        .pm-qty-controls span {
            width: 50px;
            text-align: center;
            font-weight: 700;
            font-size: 1rem;
            color: var(--text-color);
        }

        .pm-add-cart-btn {
            width: 100%;
            padding: 0.85rem;
            background: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            margin-bottom: 1rem;
        }
        .pm-add-cart-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }
        .pm-add-cart-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .pm-section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 0.4rem;
            padding-top: 0.75rem;
            border-top: 1px solid rgba(0,0,0,0.08);
        }

        .pm-about-list {
            padding-left: 1.2rem;
            margin-bottom: 0.5rem;
        }
        .pm-about-list ul {
            list-style: disc;
            padding-left: 0.5rem;
            margin: 0;
        }
        .pm-about-list ul li {
            font-size: 0.88rem;
            color: var(--text-color);
            line-height: 1.7;
            padding: 0.1rem 0;
        }

        .pm-extra-details {
            font-size: 0.86rem;
            color: var(--text-color);
            line-height: 1.7;
            margin-bottom: 0.75rem;
        }
        .pm-extra-details .pm-detail-label {
            font-weight: 700;
            color: var(--text-color);
            text-transform: uppercase;
            font-size: 0.82rem;
        }
        .pm-extra-details .pm-detail-row {
            margin-bottom: 0.35rem;
        }

        .pm-senior-discount {
            border-top: 1px solid rgba(0,0,0,0.08);
            border-bottom: 1px solid rgba(0,0,0,0.08);
            margin: 0.5rem 0;
        }
        .pm-senior-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-color);
        }
        .pm-senior-header:hover { opacity: 0.8; }
        .pm-chevron {
            transition: transform 0.2s;
            font-size: 0.8rem;
            color: var(--text-light);
        }
        .pm-senior-discount.open .pm-chevron {
            transform: rotate(180deg);
        }
        .pm-senior-body {
            display: none;
            padding: 0 0 0.75rem;
            font-size: 0.85rem;
            color: var(--text-light);
            line-height: 1.6;
        }
        .pm-senior-body p { margin: 0 0 0.4rem; }
        .pm-senior-discount.open .pm-senior-body {
            display: block;
        }

        .pm-faq-link {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-top: 0.5rem;
        }
        .pm-faq-link a {
            color: var(--primary-blue);
            font-weight: 700;
            text-decoration: none;
        }
        .pm-faq-link a:hover { text-decoration: underline; }

        .pm-sku {
            font-size: 0.78rem;
            color: var(--text-light);
            margin-top: 0.25rem;
        }

        @media (max-width: 768px) {
            .product-modal {
                flex-direction: column;
                max-width: 95vw;
            }
            .product-modal-image {
                width: 100%;
                min-width: unset;
                min-height: 250px;
                max-height: 300px;
                border-radius: 20px 20px 0 0;
            }
            .product-modal-details {
                padding: 1.25rem;
            }
            .pm-name { font-size: 1.2rem; }
            .pm-price { font-size: 1.3rem; }
        }

        /* ─── Checkout Modal ─── */
        .checkout-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }
        .checkout-overlay.active { display: flex; }

        .checkout-modal {
            background: var(--card-bg);
            border-radius: 20px;
            width: 100%;
            max-width: 520px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .checkout-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .checkout-header h2 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-color);
        }

        .checkout-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: var(--text-light);
            cursor: pointer;
            padding: 0.3rem;
            border-radius: 8px;
            transition: all 0.15s;
        }
        .checkout-close:hover { background: var(--bg-color); color: var(--text-color); }

        .checkout-step { padding: 1.5rem; }

        .checkout-subtitle {
            font-size: 0.95rem;
            color: var(--text-color);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkout-items {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 1rem;
        }

        .checkout-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.7rem 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.88rem;
        }
        .checkout-item:last-child { border-bottom: none; }

        .checkout-item-name {
            font-weight: 600;
            color: var(--text-color);
        }
        .checkout-item-qty {
            color: var(--text-light);
            font-size: 0.8rem;
        }
        .checkout-item-price {
            font-weight: 700;
            color: var(--primary-blue);
        }

        .checkout-total-section {
            margin-bottom: 1.25rem;
        }

        .checkout-total-row {
            display: flex;
            justify-content: space-between;
            padding: 0.4rem 0;
            font-size: 0.9rem;
            color: var(--text-light);
        }
        .checkout-total-row.grand {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-color);
            border-top: 2px solid var(--border-color);
            padding-top: 0.6rem;
            margin-top: 0.3rem;
        }

        .checkout-notice {
            display: flex;
            gap: 0.75rem;
            padding: 1rem;
            background: #fef3cd;
            border: 1px solid #ffc107;
            border-radius: 12px;
            margin-bottom: 1.25rem;
            font-size: 0.85rem;
            color: #856404;
        }
        .checkout-notice i { font-size: 1.2rem; margin-top: 2px; color: #f59e0b; }
        .checkout-notice p { margin: 0.2rem 0 0; line-height: 1.4; }

        [data-theme="dark"] .checkout-notice {
            background: rgba(245, 158, 11, 0.12);
            border-color: rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }

        .payment-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .payment-option input { display: none; }

        .payment-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.4rem;
            padding: 1rem 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .payment-option input:checked + .payment-card {
            border-color: var(--primary-blue);
            background: rgba(37, 99, 235, 0.06);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .payment-card:hover {
            border-color: var(--primary-blue);
            transform: translateY(-1px);
        }

        .payment-label {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-color);
        }
        .payment-desc {
            font-size: 0.72rem;
            color: var(--text-light);
        }

        .checkout-guest-notice {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: rgba(59, 130, 246, 0.08);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 10px;
            font-size: 0.82rem;
            color: var(--text-light);
            margin-bottom: 1rem;
        }
        .checkout-guest-notice i { color: var(--primary-blue); }

        .checkout-place-btn {
            width: 100%;
            padding: 1rem;
            background: #22c55e;
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        .checkout-place-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4); }
        .checkout-place-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }

        /* Order success */
        .order-success { text-align: center; padding: 2rem 0; }
        .success-icon { font-size: 4rem; color: #22c55e; margin-bottom: 1rem; }
        .order-success h3 { font-size: 1.3rem; color: var(--text-color); margin-bottom: 0.5rem; }
        .order-ref { font-size: 1rem; color: var(--primary-blue); font-weight: 700; margin-bottom: 1.5rem; }

        .success-details {
            text-align: left;
            background: var(--bg-color);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        .success-details p {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.5rem 0;
            font-size: 0.88rem;
            color: var(--text-color);
        }
        .success-details i { color: var(--primary-blue); width: 18px; text-align: center; }

        .checkout-done-btn {
            width: 100%;
            padding: 0.9rem;
            background: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        .checkout-done-btn:hover { transform: translateY(-1px); }

        /* ─── Floating Chat Button ─── */
        .chat-float {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 300;
        }

        .chat-float-btn {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--primary-blue);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            box-shadow: 0 4px 16px rgba(37,99,235,0.4);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .chat-float-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 24px rgba(37,99,235,0.5);
        }

        .chat-popup {
            position: absolute;
            bottom: 70px;
            right: 0;
            width: 340px;
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18);
            display: none;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-popup.open { display: flex; }

        .chat-popup-header {
            padding: 1rem 1.2rem;
            background: var(--primary-blue);
            color: white;
            font-weight: 700;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .chat-popup-close {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.1rem;
            opacity: 0.8;
        }
        .chat-popup-close:hover { opacity: 1; }

        .chat-popup-body {
            padding: 1.5rem 1.2rem;
            text-align: center;
        }

        .chat-popup-body p {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .chat-popup-body .chat-contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 0;
            font-size: 0.88rem;
            color: var(--text-color);
        }

        .chat-popup-body .chat-contact-item i {
            color: var(--primary-blue);
            width: 20px;
            text-align: center;
        }

        .chat-popup-body .chat-contact-item a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
        }

        /* ─── Mega Footer (Southstar-style) ─── */
        .mega-footer {
            background: #0a1628;
            color: #c8d6e5;
            padding: 3rem 2rem 0;
            margin-top: 2rem;
            border-top: 3px solid var(--primary-blue);
        }

        .mega-footer-grid {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr 1fr;
            gap: 2.5rem;
        }

        .mega-footer h3 {
            color: white;
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1.2rem;
        }

        .footer-col p,
        .footer-col li,
        .footer-col a {
            font-size: 0.85rem;
            color: #8899a8;
            line-height: 1.7;
        }

        .footer-col a {
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-col a:hover { color: white; }

        .footer-col ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-col ul li { margin-bottom: 0.3rem; }

        .footer-contact-item {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            margin-bottom: 0.6rem;
            font-size: 0.85rem;
            color: #8899a8;
        }

        .footer-contact-item i {
            margin-top: 0.25rem;
            color: var(--primary-blue);
            width: 16px;
            text-align: center;
            flex-shrink: 0;
        }

        /* (newsletter removed) */

        .footer-bottom {
            border-top: 1px solid #1a2a3e;
            margin-top: 2.5rem;
            padding: 1rem 0;
            text-align: center;
        }

        .footer-bottom-links {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }

        .footer-bottom-links a {
            font-size: 0.8rem;
            color: #8899a8;
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-bottom-links a:hover { color: white; }

        .footer-copyright {
            font-size: 0.78rem;
            color: #5a6f83;
            padding: 0.8rem 0;
            border-top: 1px solid #1a2a3e;
            margin-top: 0.5rem;
        }

        /* ─── Footer Modal Popup Styles ─── */
        .footer-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            backdrop-filter: blur(4px);
            animation: fadeInOverlay 0.25s ease;
        }
        .footer-modal-overlay.active { display: flex; }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideUpModal {
            from { opacity: 0; transform: translateY(30px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .footer-modal {
            background: white;
            border-radius: 16px;
            width: 100%;
            max-width: 600px;
            max-height: 85vh;
            overflow-y: auto;
            padding: 2rem;
            position: relative;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUpModal 0.3s ease;
        }

        .footer-modal-close {
            position: absolute;
            top: 0.8rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.8rem;
            color: #94a3b8;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
            z-index: 1;
        }
        .footer-modal-close:hover { color: #ef4444; }

        .footer-modal-icon {
            text-align: center;
            font-size: 2.5rem;
            color: var(--primary-blue, #2563eb);
            margin-bottom: 0.5rem;
        }

        .footer-modal h2 {
            text-align: center;
            font-size: 1.35rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1.2rem;
        }

        .footer-modal-body {
            font-size: 0.9rem;
            color: #475569;
            line-height: 1.75;
        }

        .footer-modal-body h4 {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e293b;
            margin: 1.2rem 0 0.5rem;
        }
        .footer-modal-body h4 i { color: var(--primary-blue, #2563eb); margin-right: 0.4rem; }

        .footer-modal-body ul {
            padding-left: 1.3rem;
            margin: 0.4rem 0 0.8rem;
        }
        .footer-modal-body ul li { margin-bottom: 0.35rem; }

        .footer-modal-divider {
            border-top: 1px solid #e2e8f0;
            margin: 1.2rem 0;
        }

        /* FAQ accordion */
        .faq-item { border-bottom: 1px solid #f1f5f9; }
        .faq-question {
            padding: 0.8rem 0;
            cursor: pointer;
            font-weight: 600;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: color 0.2s;
        }
        .faq-question:hover { color: var(--primary-blue, #2563eb); }
        .faq-arrow { font-size: 0.7rem; transition: transform 0.25s; flex-shrink: 0; }
        .faq-question.open .faq-arrow { transform: rotate(90deg); }
        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
            color: #64748b;
            font-size: 0.88rem;
            line-height: 1.65;
            padding: 0 0 0 1.2rem;
        }
        .faq-answer.open {
            max-height: 300px;
            padding-bottom: 0.8rem;
        }

        /* Order status guide dots */
        .status-guide-item {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 0.5rem;
            font-size: 0.88rem;
        }
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .status-dot.pending { background: #f59e0b; }
        .status-dot.processing { background: #3b82f6; }
        .status-dot.ready { background: #10b981; }
        .status-dot.completed { background: #22c55e; }
        .status-dot.cancelled { background: #ef4444; }

        /* Contact method cards */
        .contact-method-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 1rem;
            background: #f8fafc;
            border-radius: 10px;
            margin-bottom: 0.6rem;
        }
        .contact-method-card i { font-size: 1.3rem; color: var(--primary-blue, #2563eb); width: 30px; text-align: center; }

        /* Contact info grid */
        .contact-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.8rem;
            margin-bottom: 0.8rem;
        }
        .contact-info-card {
            text-align: center;
            padding: 1rem 0.5rem;
            background: #f8fafc;
            border-radius: 10px;
        }
        .contact-info-card i { font-size: 1.5rem; color: var(--primary-blue, #2563eb); margin-bottom: 0.4rem; }
        .contact-info-card h4 { font-size: 0.8rem; margin: 0.3rem 0 0.2rem; color: #64748b; }
        .contact-info-card p { font-size: 0.88rem; margin: 0; color: #1e293b; }

        /* Store hours table */
        .hours-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        .hours-table td {
            padding: 0.4rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .hours-table td:last-child { text-align: right; font-weight: 600; color: #1e293b; }

        .footer-modal-form-group label {
            font-size: 0.88rem;
            font-weight: 600;
            color: #334155;
        }

        /* Footer modal mobile */
        @media (max-width: 600px) {
            .footer-modal { padding: 1.5rem 1.2rem; max-height: 90vh; }
            .contact-info-grid { grid-template-columns: 1fr; }
        }

        /* Small grid view (5 cols) */
        .products-grid.small-grid-view {
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
        }
        .products-grid.small-grid-view .product-image {
            height: 120px;
        }
        .products-grid.small-grid-view .product-name {
            font-size: 0.9rem;
        }
        .products-grid.small-grid-view .product-price {
            font-size: 1.05rem;
        }

        /* ─── Responsive ─── */
        @media (max-width: 1100px) {
            .products-grid { grid-template-columns: repeat(3, 1fr); }
            .products-grid.small-grid-view { grid-template-columns: repeat(4, 1fr); }
            .category-cards-grid { grid-template-columns: repeat(3, 1fr); }
            .mega-footer-grid { grid-template-columns: repeat(3, 1fr); }
        }

        @media (max-width: 768px) {
            .browse-toolbar .browse-toolbar-row { flex-direction: column; align-items: stretch; }
            .browse-toolbar .search-bar { width: 100%; max-width: 100%; }
            .products-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .products-grid.small-grid-view { grid-template-columns: repeat(3, 1fr); }
            .products-grid.list-view .product-card { flex-direction: column; }
            .products-grid.list-view .product-image { width: 100%; min-width: unset; height: 140px; }
            .products-header { flex-direction: column; gap: 0.75rem; align-items: stretch; }
            .products-header-right { justify-content: space-between; }
            .category-cards-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .cart-panel { width: 100%; max-width: 100vw; right: -100%; }
            .topbar-cart-btn span:not(.cart-count-badge) { display: none; }
            .topbar .browse-toolbar-row { max-width: 430px; }
            .mega-footer-grid { grid-template-columns: 1fr 1fr; }

            /* Mobile category section optimization */
            .category-cards-section {
                padding: 1rem 0.75rem 1.5rem;
            }
            .category-cards-section h2 {
                font-size: 1.1rem;
                margin-bottom: 0.8rem;
            }
            .category-card {
                padding: 1rem 0.75rem;
                border-radius: 12px;
            }
            .category-card-icon {
                font-size: 1.8rem;
                margin-bottom: 0.4rem;
            }
            .category-card-name {
                font-size: 0.82rem;
            }
            .category-card-count {
                font-size: 0.7rem;
            }
            .category-card-img {
                height: 80px;
                border-radius: 8px;
                margin-bottom: 0.5rem;
            }

            /* Product cards mobile */
            .product-image { height: 130px; }
            .product-content { padding: 0.8rem; }
            .product-name { font-size: 0.9rem; }
            .product-price { font-size: 1.1rem; }
            .product-desc { font-size: 0.75rem; margin-bottom: 0.4rem; }
            .product-footer { margin-top: 0.6rem; padding-top: 0.6rem; }
            .add-cart-btn { width: 36px; height: 36px; font-size: 0.9rem; }

            /* Products section */
            .products-section { padding: 1rem 0.75rem; }
            .products-header h2 { font-size: 1.1rem; }

            /* QR promo banner */
            .qr-promo-banner { margin: 0 0.75rem; border-radius: 12px; }

            /* Cart panel full width on mobile */
            .cart-panel-header { padding: 1rem; }
            .cart-panel-body { padding: 0.75rem; }

            /* Category title banner */
            .category-title-banner { padding: 1.5rem 1rem; }
            .category-title-banner h1 { font-size: 1.5rem; }
        }

        @media (max-width: 480px) {
            .products-grid { grid-template-columns: 1fr 1fr; gap: 0.6rem; }
            .products-grid.small-grid-view { grid-template-columns: repeat(2, 1fr); }
            .category-cards-grid { grid-template-columns: 1fr 1fr; gap: 0.6rem; }
            .mega-footer-grid { grid-template-columns: 1fr; }
            .category-title-banner h1 { font-size: 1.4rem; }

            /* Tighter mobile small screen */
            .category-cards-section { padding: 0.75rem 0.5rem 1rem; }
            .category-card { padding: 0.75rem 0.5rem; border-radius: 10px; }
            .category-card-icon { font-size: 1.5rem; margin-bottom: 0.3rem; }
            .category-card-name { font-size: 0.78rem; }
            .category-card-img { height: 65px; border-radius: 8px; }

            /* Product cards even tighter */
            .product-image { height: 110px; font-size: 2.5rem; }
            .product-content { padding: 0.6rem; }
            .product-name { font-size: 0.82rem; }
            .product-price { font-size: 1rem; }
            .product-category-tag { font-size: 0.62rem; }
            .product-footer { margin-top: 0.4rem; padding-top: 0.4rem; }
            .add-cart-btn { width: 32px; height: 32px; font-size: 0.85rem; }

            /* Topbar search compact */
            .topbar .browse-toolbar-row { max-width: 100%; }
            .search-bar input { padding: 0.6rem 0.8rem 0.6rem 2.5rem; font-size: 0.85rem; border-radius: 10px; }
            .search-bar i { left: 0.75rem; font-size: 0.85rem; }

            /* Body padding */
            body { padding-top: 56px; }
        }

        @media (max-width: 360px) {
            /* Very small screens (e.g. iPhone SE) */
            .category-cards-grid { gap: 0.5rem; }
            .category-card { padding: 0.6rem 0.4rem; }
            .category-card-name { font-size: 0.72rem; }
            .category-card-icon { font-size: 1.3rem; }
            .product-image { height: 90px; }
            .product-content { padding: 0.5rem; }
            .product-name { font-size: 0.78rem; }
            .product-price { font-size: 0.95rem; }
            .products-grid { gap: 0.5rem; }
        }


    </style>
</head>
<body>
    <!-- Toast container -->
    <div class="toast-container" id="toastContainer"></div>

    <?php include 'header-component.php'; ?>

    <!-- Inject cart button into topbar -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Updated header injection for new Flexbox component
        const topbarCenter = document.getElementById('topbarCenter');
        const topbarExtras = document.getElementById('topbarRightExtras');
        
        if (topbarCenter && topbarExtras) {
            // Wishlist button
            const wlBtn = document.createElement('button');
            wlBtn.className = 'topbar-wishlist-btn';
            wlBtn.onclick = function() { toggleWishlistPanel(); };
            wlBtn.innerHTML = '<i class="fas fa-heart"></i> <span>Wishlist</span> <span class="wishlist-count-badge" id="headerWishlistCount">0</span>';
            topbarExtras.appendChild(wlBtn);

            // Cart button
            const cartBtn = document.createElement('button');
            cartBtn.className = 'topbar-cart-btn';
            cartBtn.onclick = function() { toggleCartPanel(); };
            cartBtn.innerHTML = '<i class="fas fa-shopping-basket"></i> <span>My Cart</span> <span class="cart-count-badge" id="headerCartCount">0</span>';
            topbarExtras.appendChild(cartBtn);

            <?php if ($isLoggedIn): ?>
            // My Orders button
            const ordersBtn = document.createElement('button');
            ordersBtn.className = 'topbar-orders-btn';
            ordersBtn.onclick = function() { toggleOrdersPanel(); };
            ordersBtn.innerHTML = '<i class="fas fa-clipboard-list"></i> <span>My Orders</span>';
            topbarExtras.appendChild(ordersBtn);
            <?php endif; ?>

            // Move Search Bar to Center
            const toolbarRow = document.querySelector('.browse-toolbar-row');
            if (toolbarRow) {
                topbarCenter.appendChild(toolbarRow);
                // Also hide the separate browse-toolbar container to avoid layout issues
                const toolbarContainer = document.querySelector('.browse-toolbar');
                if (toolbarContainer) toolbarContainer.style.display = 'none';
            }
        }
    });
    </script>

    <!-- Browse Toolbar: Logo + Search bar -->
    <div class="browse-toolbar">
        <div class="browse-toolbar-row">
            <div class="toolbar-logo">
                <a href="index.php" style="color:inherit;text-decoration:none;">Calloway Pharmacy</a>
            </div>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search medicines, vitamins, supplements..." oninput="filterProducts()">
            </div>
        </div>
    </div>

    <!-- Category Cards (default landing view) -->
    <div class="category-cards-section" id="categoryCardsSection">
        <h2><i class="fas fa-th-large"></i> Browse Categories</h2>
        <div class="category-cards-grid">
            <?php
            $catIcons = [
                'Pain Relief' => 'fa-pills', 'Antibiotics' => 'fa-capsules',
                'Vitamins' => 'fa-lemon', 'Cold & Flu' => 'fa-head-side-mask',
                'Supplements' => 'fa-leaf', 'Skin Care' => 'fa-hand-sparkles',
                'First Aid' => 'fa-kit-medical', 'Baby Care' => 'fa-baby',
            ];
            $catColors = [
                'Pain Relief' => '#ef4444', 'Antibiotics' => '#3b82f6',
                'Vitamins' => '#f59e0b', 'Cold & Flu' => '#10b981',
                'Supplements' => '#8b5cf6', 'Skin Care' => '#ec4899',
                'First Aid' => '#f97316', 'Baby Care' => '#06b6d4',
            ];
            $categorySorted = [];
            foreach ($categories as $cat):
                $catName = $cat['category_name'];
                $icon = $catIcons[$catName] ?? 'fa-prescription-bottle-medical';
                $color = $catColors[$catName] ?? '#64748b';
                $catCount = count(array_filter($products, fn($p) => ($p['category_name'] ?? '') === $catName));
            $categorySorted[] = ['cat' => $cat, 'icon' => $icon, 'color' => $color, 'count' => $catCount];
            endforeach;
            // Sort categories by product count descending
            usort($categorySorted, fn($a, $b) => $b['count'] - $a['count']);
            foreach ($categorySorted as $cs):
                $cat = $cs['cat'];
                $catName = $cat['category_name'];
                $icon = $cs['icon'];
                $color = $cs['color'];
                if ($cs['count'] === 0) continue; // hide empty categories
            ?>
                <div class="category-card" onclick="showCategoryProducts('<?php echo htmlspecialchars($catName, ENT_QUOTES); ?>')">
                    <?php if ($isAdmin): ?>
                    <div class="admin-category-actions" onclick="event.stopPropagation()">
                        <button class="admin-btn admin-btn-edit" onclick="editCategory(<?php echo intval($cat['category_id']); ?>, '<?php echo htmlspecialchars($catName, ENT_QUOTES); ?>')" title="Edit"><i class="fas fa-pen"></i></button>
                        <button class="admin-btn admin-btn-delete" onclick="deleteCategory(<?php echo intval($cat['category_id']); ?>, '<?php echo htmlspecialchars($catName, ENT_QUOTES); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($catImageMap[$catName])): ?>
                    <div class="category-card-img"><img src="<?php echo htmlspecialchars($catImageMap[$catName]); ?>" alt="<?php echo htmlspecialchars($catName); ?>" loading="lazy"></div>
                    <?php else: ?>
                    <div class="category-card-icon"><i class="fas <?php echo $icon; ?>" style="color:<?php echo $color; ?>"></i></div>
                    <?php endif; ?>
                    <div class="category-card-name"><?php echo htmlspecialchars($catName); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- QR Loyalty Promo Banner -->
    <div class="qr-promo-banner" id="qrPromoBanner">
        <div class="qr-promo-inner" onclick="window.location.href='<?php echo $isLoggedIn ? 'loyalty_qr.php' : 'login.php'; ?>'">
            <span class="qr-promo-sparkles">&#10024;</span>
            <div class="qr-promo-icon">
                <i class="fas fa-qrcode"></i>
            </div>
            <div class="qr-promo-text">
                <h3><i class="fas fa-gift" style="margin-right:6px;"></i> Earn Loyalty Points on Every Order!</h3>
                <p>Scan your QR code at checkout and collect points for exclusive discounts. <?php echo $isLoggedIn ? 'Check your balance and rewards now!' : 'Sign in to start earning rewards!'; ?></p>
            </div>
            <a class="qr-promo-cta" href="<?php echo $isLoggedIn ? 'loyalty_qr.php' : 'login.php'; ?>">
                <?php echo $isLoggedIn ? 'View My QR &rarr;' : 'Sign In &rarr;'; ?>
            </a>
        </div>
    </div>

    <!-- Products Grid (hidden initially, shown when category clicked) -->
    <div class="products-section">
        <!-- Big Category Title -->
        <div class="category-title-banner" id="categoryTitleBanner" style="display:none;">
            <button class="back-to-categories" onclick="showCategories()" style="margin:0 auto 0.75rem;display:inline-flex;"><i class="fas fa-arrow-left"></i> Back to Categories</button>
            <h1 id="categoryBannerName">Category</h1>
        </div>
        <div class="products-header">
            <div class="products-header-left">
                <div class="view-toggle-btns">
                    <button id="viewGridBtn" class="active" onclick="setView('grid')" title="Grid view"><i class="fas fa-th-large"></i></button>
                    <button id="viewSmallGridBtn" onclick="setView('small-grid')" title="Small grid"><i class="fas fa-th"></i></button>
                    <button id="viewListBtn" onclick="setView('list')" title="List view"><i class="fas fa-bars"></i></button>
                </div>
            </div>
            <div class="products-header-right">
                <div class="sort-control">
                    <label>Sort by:</label>
                    <select id="sortSelect" onchange="sortProducts()">
                        <option value="featured">Featured</option>
                        <option value="name-asc">Name A-Z</option>
                        <option value="name-desc">Name Z-A</option>
                        <option value="price-asc">Price: Low to High</option>
                        <option value="price-desc">Price: High to Low</option>
                    </select>
                </div>
                <span class="product-count" id="productCount"><?php echo count($products); ?> products</span>
            </div>
        </div>
        <div class="products-grid" id="productsGrid">
            <?php if (empty($products)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h3>No products found</h3>
                    <p>The inventory is currently empty. Please check back later.</p>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <?php
                        $stock = intval($product['stock_quantity']);
                        $stockClass = $stock <= 0 ? 'stock-out' : ($stock <= 10 ? 'stock-low' : 'stock-good');
                        $stockLabel = $stock <= 0 ? 'Out of stock' : ($stock <= 10 ? "Low stock ({$stock} left)" : "{$stock} available");
                        $stockIcon  = $stock <= 0 ? 'fa-times-circle' : ($stock <= 10 ? 'fa-exclamation-triangle' : 'fa-check-circle');
                        $emoji = getCategoryEmoji($product['category_name'] ?? '');
                    ?>
                    <div class="product-card"
                         data-name="<?php echo htmlspecialchars(strtolower($product['name']), ENT_QUOTES); ?>"
                         data-category="<?php echo htmlspecialchars($product['category_name'] ?? 'General', ENT_QUOTES); ?>"
                         data-desc="<?php echo htmlspecialchars(strtolower($product['description'] ?? ''), ENT_QUOTES); ?>"
                         data-product-id="<?php echo intval($product['product_id']); ?>"
                         onclick="openProductModal(<?php echo intval($product['product_id']); ?>)"
                         style="cursor:pointer;">
                        <div class="product-image">
                            <?php if (!empty($product['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <?php echo $emoji; ?>
                            <?php endif; ?>
                        </div>
                        <div class="product-content">
                            <div class="product-category-tag"><?php echo htmlspecialchars($product['category_name'] ?? 'General'); ?></div>
                            <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                            <?php if (!empty($product['description'])): ?>
                                <div class="product-desc"><?php echo htmlspecialchars($product['description']); ?></div>
                            <?php endif; ?>
                            <div class="product-stock <?php echo $stockClass; ?>" data-stock="<?php echo $stock; ?>">
                                <i class="fas <?php echo $stockIcon; ?>"></i> <?php echo $stockLabel; ?>
                            </div>
                            <div class="product-footer">
                                <div class="product-price">₱<?php echo number_format(floatval($product['selling_price']), 2); ?></div>
                                <?php if ($stock <= 0): ?>
                                <span class="out-of-stock-badge">Out of stock</span>
                                <?php endif; ?>
                                <button class="add-cart-btn"
                                        <?php echo $stock <= 0 ? 'disabled' : ''; ?>
                                        onclick="event.stopPropagation(); openProductModal(<?php echo intval($product['product_id']); ?>)"
                                        title="<?php echo $stock <= 0 ? 'Out of stock' : 'Add to cart'; ?>">
                                    <i class="fas fa-plus"></i>
                                    <i class="fas fa-cart-plus"></i><span class="btn-text">Add To Cart</span>
                                </button>
                                <button class="wishlist-btn" onclick="event.stopPropagation(); toggleWishlist(this, <?php echo intval($product['product_id']); ?>)" title="Add to wishlist">
                                    <i class="far fa-heart"></i>
                                </button>
                            </div>
                            <?php if ($isAdmin): ?>
                            <div class="admin-actions">
                                <button class="admin-btn admin-btn-edit" onclick="event.stopPropagation(); editProduct(<?php echo intval($product['product_id']); ?>)" title="Edit Product"><i class="fas fa-pen"></i> Edit</button>
                                <button class="admin-btn admin-btn-img" onclick="event.stopPropagation(); editProductImage(<?php echo intval($product['product_id']); ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>')" title="Change Image"><i class="fas fa-image"></i> Image</button>
                                <button class="admin-btn admin-btn-delete" onclick="event.stopPropagation(); deleteProduct(<?php echo intval($product['product_id']); ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>')" title="Delete Product"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Floating Cart Badge (hidden - moved to header) -->
    <div class="floating-cart" id="floatingCart" style="display:none !important">
        <button onclick="toggleCartPanel()">
            <i class="fas fa-shopping-basket"></i>
            <span>My Cart</span>
            <span class="cart-badge" id="cartCount">0</span>
        </button>
    </div>

    <!-- Wishlist Sidebar Panel -->
    <div class="wishlist-overlay" id="wishlistOverlay" onclick="toggleWishlistPanel()"></div>
    <div class="wishlist-panel" id="wishlistPanel">
        <div class="wishlist-panel-header">
            <h2><i class="fas fa-heart"></i> My Wishlist</h2>
            <button class="cart-close-btn" onclick="toggleWishlistPanel()"><i class="fas fa-times"></i></button>
        </div>
        <div class="wishlist-panel-body" id="wishlistPanelBody">
            <div class="wishlist-empty">
                <i class="far fa-heart"></i>
                <p>Your wishlist is empty</p>
                <span>Browse products and tap the heart to save them here</span>
            </div>
        </div>
    </div>

    <!-- Cart Sidebar Panel -->
    <div class="cart-overlay" id="cartOverlay" onclick="toggleCartPanel()"></div>
    <div class="cart-panel" id="cartPanel">
        <div class="cart-panel-header">
            <h2><i class="fas fa-shopping-cart"></i> Your Cart</h2>
            <button class="cart-close-btn" onclick="toggleCartPanel()"><i class="fas fa-times"></i></button>
        </div>
        <div class="cart-panel-body" id="cartPanelBody">
            <div class="cart-empty" id="cartEmpty">
                <i class="fas fa-shopping-basket"></i>
                <p>Your cart is empty</p>
                <span>Add some medicines to get started</span>
            </div>
        </div>
        <div class="cart-panel-footer" id="cartPanelFooter" style="display:none;">
            <div class="cart-summary">
                <div class="cart-summary-row">
                    <span>Subtotal</span>
                    <span id="cartSubtotal">₱0.00</span>
                </div>
                <div class="cart-summary-row total">
                    <span>Total</span>
                    <span id="cartTotal">₱0.00</span>
                </div>
                <div class="cart-summary-row items-count">
                    <span>Total Items</span>
                    <span id="cartTotalItems">0</span>
                </div>
            </div>
            <button class="cart-checkout-btn" onclick="checkout()"><i class="fas fa-credit-card"></i> Proceed to Checkout</button>
            <button class="cart-clear-btn" onclick="clearCart()"><i class="fas fa-trash-alt"></i> Clear Cart</button>
        </div>
    </div>

    <!-- My Orders Sidebar Panel -->
    <?php if ($isLoggedIn): ?>
    <div class="orders-overlay" id="ordersOverlay" onclick="toggleOrdersPanel()"></div>
    <div class="orders-panel" id="ordersPanel">
        <div class="orders-panel-header">
            <h2><i class="fas fa-clipboard-list"></i> My Orders</h2>
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <button onclick="loadMyOrders()" style="background:none;border:1px solid rgba(0,0,0,0.12);border-radius:8px;padding:0.4rem 0.7rem;cursor:pointer;font-size:0.8rem;color:var(--text-color);display:flex;align-items:center;gap:0.3rem;" title="Refresh orders">
                    <i class="fas fa-sync-alt"></i>
                </button>
                <button class="cart-close-btn" onclick="toggleOrdersPanel()"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="orders-panel-body" id="ordersPanelBody">
            <div class="orders-loading">
                <i class="fas fa-spinner fa-spin" style="font-size:2rem;opacity:0.3;"></i>
                <p style="margin-top:0.8rem;">Loading your orders...</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Checkout Modal -->
    <div class="checkout-overlay" id="checkoutOverlay" onclick="closeCheckoutModal(event)">
        <div class="checkout-modal" onclick="event.stopPropagation()">
            <div class="checkout-header">
                <h2><i class="fas fa-receipt"></i> Checkout</h2>
                <button class="checkout-close" onclick="closeCheckoutModal()"><i class="fas fa-times"></i></button>
            </div>

            <!-- Step 1: Order Summary -->
            <div class="checkout-step" id="checkoutStep1">
                <h3 class="checkout-subtitle"><i class="fas fa-shopping-bag"></i> Order Summary</h3>
                <div class="checkout-items" id="checkoutItemsList"></div>

                <!-- Senior/PWD Discount Claim — verified at pickup, NOT applied now -->
                <div id="seniorDiscountCard" style="margin:0.75rem 0; padding:0.85rem 1rem; background:rgba(34,197,94,0.08); border:2px solid rgba(34,197,94,0.25); border-radius:12px; transition: all 0.2s;">
                    <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;font-weight:700;color:#16a34a;font-size:0.95rem;">
                        <input type="checkbox" id="seniorDiscountCheck" onchange="updateSeniorDiscount()" style="width:20px;height:20px;accent-color:#22c55e;cursor:pointer;flex-shrink:0;">
                        <i class="fas fa-id-card" style="font-size:1.1rem;"></i> I am a Senior Citizen / PWD
                    </label>
                    <p style="margin:0.4rem 0 0 2.5rem;font-size:0.78rem;color:#4ade80;line-height:1.4;">20% discount per RA 9994 & RA 10754. <strong>You must present a valid SC/PWD ID at pickup for the discount to be applied.</strong></p>
                    <div id="seniorClaimNotice" style="display:none; margin:0.5rem 0 0 2.5rem; padding:0.5rem 0.75rem; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.3); border-radius:8px; font-size:0.78rem; color:#f59e0b; line-height:1.4;">
                        <i class="fas fa-clock"></i> <strong>Discount pending verification</strong> — The 20% discount will be applied by the cashier after verifying your SC/PWD ID at pickup. Your order total shown is the full price.
                    </div>
                </div>

                <div class="checkout-total-section">
                    <div class="checkout-total-row">
                        <span>Subtotal</span>
                        <span id="checkoutSubtotal">₱0.00</span>
                    </div>
                    <div class="checkout-total-row" id="seniorDiscountRow" style="display: none; color: #f59e0b;">
                        <span><i class="fas fa-id-card"></i> SC/PWD Discount (20%)</span>
                        <span id="seniorDiscountAmount" style="font-size:0.8rem;">Pending ID verification</span>
                    </div>
                    <div class="checkout-total-row" id="pointsDiscountRow" style="display: none; color: #22c55e;">
                        <span><i class="fas fa-gift"></i> Points Discount</span>
                        <span id="pointsDiscountAmount">-₱0.00</span>
                    </div>
                    <div class="checkout-total-row grand">
                        <span>Total</span>
                        <span id="checkoutTotal">₱0.00</span>
                    </div>
                </div>

                <div class="checkout-notice">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Pickup Only</strong>
                        <p>Delivery is not available at the moment. You can only pick up your medicine at our physical store — <strong>Calloway Pharmacy</strong>.</p>
                    </div>
                </div>

                <h3 class="checkout-subtitle"><i class="fas fa-wallet"></i> Payment Method</h3>
                <div class="payment-options">
                    <label class="payment-option">
                        <input type="radio" name="paymentMethod" value="Cash on Pickup" checked>
                        <div class="payment-card">
                            <i class="fas fa-money-bill-wave" style="color:#22c55e; font-size:1.5rem;"></i>
                            <span class="payment-label">Cash on Pickup</span>
                            <span class="payment-desc">Pay when you pick up</span>
                        </div>
                    </label>
                    <?php if ($isLoggedIn): ?>
                    <label class="payment-option">
                        <input type="radio" name="paymentMethod" value="Loyalty Points">
                        <div class="payment-card">
                            <i class="fas fa-gift" style="color:#7c3aed; font-size:1.5rem;"></i>
                            <span class="payment-label">Loyalty Points</span>
                            <span class="payment-desc">Use points for full payment</span>
                        </div>
                    </label>
                    <?php endif; ?>
                    <label class="payment-option" style="opacity:0.4; pointer-events:none; position:relative;">
                        <input type="radio" name="paymentMethod" value="GCash" disabled>
                        <div class="payment-card">
                            <i class="fas fa-mobile-screen-button" style="color:#0070f0; font-size:1.5rem;"></i>
                            <span class="payment-label">GCash</span>
                            <span class="payment-desc">Coming soon</span>
                        </div>
                        <span style="position:absolute;top:0.4rem;right:0.6rem;font-size:0.65rem;background:#ef4444;color:#fff;padding:0.15rem 0.5rem;border-radius:99px;font-weight:700;">UNAVAILABLE</span>
                    </label>
                </div>

                <?php if (!$isLoggedIn): ?>
                <div class="checkout-guest-notice">
                    <i class="fas fa-info-circle"></i>
                    <span>You are ordering as a guest. <a href="login.php" style="color:var(--primary-color); font-weight:600;">Login</a> for a better experience.</span>
                </div>
                <?php endif; ?>

                <?php if ($isLoggedIn): ?>
                <!-- Loyalty Points Redemption -->
                <div class="loyalty-section" id="loyaltySection" style="margin: 1rem 0; padding: 1rem; background: #667eea; border-radius: 12px; color: white;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                        <div style="display: flex; align-items: center; gap: 0.6rem;">
                            <i class="fas fa-gift" style="font-size: 1.3rem;"></i>
                            <span style="font-weight: 700; font-size: 1rem;">Loyalty Points</span>
                        </div>
                        <span id="availablePoints" style="font-size: 1.2rem; font-weight: 700;">Loading...</span>
                    </div>
                    <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 0.75rem;">1 point = ₱1 discount</div>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="number" id="pointsToRedeem" placeholder="Points to use" min="0" max="0" value="0" 
                            step="0.01"
                            style="flex: 1; padding: 0.6rem; border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; background: rgba(255,255,255,0.15); color: white; font-size: 0.9rem;"
                            oninput="updatePointsDiscount()">
                        <button onclick="useMaxPoints()" style="padding: 0.6rem 1rem; background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; color: white; font-weight: 600; cursor: pointer; font-size: 0.85rem; transition: all 0.2s;">
                            Use Max
                        </button>
                    </div>
                    <div id="pointsError" style="color: #ffcccb; font-size: 0.8rem; margin-top: 0.5rem; display: none;"></div>
                </div>
                <?php endif; ?>

                <?php if ($isLoggedIn): ?>
                <button class="checkout-place-btn" onclick="placeOrder()">
                    <i class="fas fa-check-circle"></i> Place Order
                </button>
                <?php else: ?>
                <button class="checkout-place-btn" onclick="window.location.href='login.php?redirect=onlineordering.php'" style="background: #3b82f6;">
                    <i class="fas fa-sign-in-alt"></i> Login to Place Order
                </button>
                <p style="text-align: center; font-size: 0.85rem; color: var(--text-light); margin-top: 0.75rem;">
                    Don't have an account? <a href="login.php?redirect=onlineordering.php" style="color: var(--primary-blue); font-weight: 600;">Sign up</a>
                </p>
                <?php endif; ?>
            </div>

            <!-- Step 2: Order Confirmation Receipt -->
            <div class="checkout-step" id="checkoutStep2" style="display:none;">
                <div class="receipt-container" style="text-align: center; color: var(--text-color);">
                    <div class="receipt-header" style="margin-bottom: 1.5rem;">
                        <div class="success-icon" style="font-size: 3rem; color: #22c55e; margin-bottom: 0.5rem;"><i class="fas fa-check-circle"></i></div>
                        <h3 style="font-size: 1.5rem; margin-bottom: 0.2rem;">Order Placed!</h3>
                        <p style="color: var(--text-light); font-size: 0.9rem;">Thank you for ordering with Calloway Pharmacy</p>
                    </div>

                    <div class="receipt-card" style="background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; text-align: left;">
                        <div class="receipt-row" style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="color: var(--text-light);">Status</span>
                            <span style="color: #f59e0b; font-weight: 600; background: rgba(245, 158, 11, 0.1); padding: 0.1rem 0.6rem; border-radius: 12px; font-size: 0.85rem;">Pending</span>
                        </div>
                        <div class="receipt-row" style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="color: var(--text-light);">Order Ref</span>
                            <span id="receiptRef" style="font-weight: 600; font-family: monospace; letter-spacing: 0.05em;"></span>
                        </div>
                        <div class="receipt-row" style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                            <span style="color: var(--text-light);">Date</span>
                            <span id="receiptDate" style="font-weight: 500;"></span>
                        </div>

                        <div style="border-top: 1px dashed var(--border-color); margin: 0.8rem 0;"></div>

                        <div id="receiptItemsList" style="margin-bottom: 1rem; font-size: 0.9rem;">
                            <!-- Populated by JS -->
                        </div>

                        <div style="border-top: 1px dashed var(--border-color); margin: 0.8rem 0;"></div>

                        <div class="receipt-total-row" style="display: flex; justify-content: space-between; font-weight: 800; font-size: 1.1rem; color: var(--text-color);">
                            <span>Total Amount</span>
                            <span id="receiptTotal"></span>
                        </div>

                        <div id="receiptQrSection" style="margin-top: 1.5rem; text-align: center;">
                            <p style="font-size: 0.8rem; color: var(--text-light); margin-bottom: 0.5rem;">Scan for Pickup</p>
                            <div id="receiptQrCode" style="display: inline-block; padding: 10px; background: white; border-radius: 8px;"></div>
                        </div>
                    </div>

                    <button class="checkout-done-btn" onclick="closeCheckoutModal(); location.reload();" style="width: 100%; padding: 0.9rem; background: var(--primary-blue); color: white; border: none; border-radius: 10px; font-weight: 600; font-size: 1rem; cursor: pointer;">
                        <i class="fas fa-thumbs-up"></i> Got it!
                    </button>
                    <!-- Print receipt trigger could be added here later -->
                </div>
            </div>
        </div>
    </div>

    <!-- Product Detail Modal -->
    <div class="product-modal-overlay" id="productModalOverlay" onclick="closeProductModal(event)">
        <div class="product-modal" onclick="event.stopPropagation()">
            <div class="product-modal-image" id="pmImage"></div>
            <div class="product-modal-details">
                <button class="product-modal-close" onclick="closeProductModal()"><i class="fas fa-times"></i></button>
                <div class="pm-category" id="pmCategory"></div>
                <div class="pm-name" id="pmName"></div>
                <div class="pm-price" id="pmPrice"></div>
                <div class="pm-stock" id="pmStock"></div>
                <button class="pm-wishlist-btn" id="pmWishlistBtn" onclick="toggleWishlist(this, window._pmProductId)">
                    <i class="far fa-heart"></i> Add to Wishlist
                </button>
                <div class="pm-qty-row">
                    <span class="pm-qty-label">Quantity</span>
                    <div class="pm-qty-controls">
                        <button onclick="pmChangeQty(-1)"><i class="fas fa-minus"></i></button>
                        <span id="pmQty">1</span>
                        <button onclick="pmChangeQty(1)"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
                <button class="pm-add-cart-btn" id="pmAddCartBtn" onclick="pmAddToCart()">
                    <i class="fas fa-cart-plus"></i> Add To Cart
                </button>
                <button class="pm-add-cart-btn" id="pmBuyNowBtn" onclick="pmBuyNow()" style="background: #22c55e; margin-top: 0.5rem;">
                    <i class="fas fa-bolt"></i> Buy Now
                </button>
                <div class="pm-section-title">About the Product</div>
                <div class="pm-about-list" id="pmAboutList"></div>
                <div class="pm-extra-details" id="pmExtraDetails"></div>
                <div class="pm-sku" id="pmSku"></div>
                <div class="pm-senior-discount">
                    <div class="pm-senior-header" onclick="this.parentElement.classList.toggle('open')">
                        <span><i class="far fa-check-square"></i> Senior Citizen / PWD discount</span>
                        <i class="fas fa-chevron-down pm-chevron"></i>
                    </div>
                    <div class="pm-senior-body">
                        <p>Senior Citizens and Persons with Disability (PWD) are entitled to a <strong>20% discount</strong> on medicines per Philippine law (RA 9994 & RA 10754).</p>
                        <p>Indicate your SC/PWD status during checkout. <strong>The discount will be applied by the cashier after verifying your valid ID at pickup.</strong></p>
                    </div>
                </div>
                <div class="pm-faq-link">Visit our <a href="#">FAQs</a> page for further details</div>
            </div>
        </div>
    </div>

    <script>
    // Build product data map for the modal
    const productDataMap = {};
    <?php foreach ($products as $p): ?>
    productDataMap[<?php echo intval($p['product_id']); ?>] = {
        id: <?php echo intval($p['product_id']); ?>,
        name: <?php echo json_encode($p['name']); ?>,
        description: <?php echo json_encode($p['description'] ?? ''); ?>,
        price: <?php echo floatval($p['selling_price']); ?>,
        stock: <?php echo intval($p['stock_quantity']); ?>,
        sku: <?php echo json_encode($p['sku'] ?? ''); ?>,
        image: <?php echo json_encode($p['image_url'] ?? ''); ?>,
        category: <?php echo json_encode($p['category_name'] ?? 'General'); ?>
    };
    <?php endforeach; ?>

    window._pmProductId = null;
    window._pmQty = 1;
    window._pmStock = 0;

    function openProductModal(productId) {
        const p = productDataMap[productId];
        if (!p) return;

        window._pmProductId = p.id;
        window._pmQty = 1;
        window._pmStock = p.stock;

        // Image
        const imgDiv = document.getElementById('pmImage');
        if (p.image) {
            imgDiv.innerHTML = '<img src="' + escapeHtml(p.image) + '" alt="' + escapeHtml(p.name) + '">';
        } else {
            imgDiv.innerHTML = '<i class="fas fa-prescription-bottle-medical"></i>';
        }

        // Details
        document.getElementById('pmCategory').textContent = p.category;
        document.getElementById('pmName').textContent = p.name;
        document.getElementById('pmPrice').textContent = '₱' + p.price.toFixed(2);

        // Stock
        const stockEl = document.getElementById('pmStock');
        if (p.stock <= 0) {
            stockEl.className = 'pm-stock stock-out';
            stockEl.innerHTML = '<i class="fas fa-times-circle"></i> Out of stock';
        } else if (p.stock <= 10) {
            stockEl.className = 'pm-stock stock-low';
            stockEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Low stock (' + p.stock + ' left)';
        } else {
            stockEl.className = 'pm-stock stock-good';
            stockEl.innerHTML = '<i class="fas fa-check-circle"></i> ' + p.stock + ' available';
        }

        // Qty
        document.getElementById('pmQty').textContent = 1;

        // Add to cart button
        const addBtn = document.getElementById('pmAddCartBtn');
        addBtn.disabled = (p.stock <= 0);

        // Buy now button
        const buyBtn = document.getElementById('pmBuyNowBtn');
        if (buyBtn) buyBtn.disabled = (p.stock <= 0);

        // Wishlist button sync
        const wlBtn = document.getElementById('pmWishlistBtn');
        if (isInWishlist(p.id)) {
            wlBtn.classList.add('active');
            wlBtn.innerHTML = '<i class="fas fa-heart"></i> In Wishlist';
        } else {
            wlBtn.classList.remove('active');
            wlBtn.innerHTML = '<i class="far fa-heart"></i> Add to Wishlist';
        }

        // Parse rich description
        const desc = p.description || '';
        const detailLabels = ['INDICATIONS', 'CONTRA INDICATIONS', 'CONTRAINDICATIONS', 'PRECAUTIONS', 'SIDE EFFECTS', 'DRUG INTERACTIONS'];
        let mainParts = [];
        let detailSections = [];

        // Split by known labels
        let remaining = desc;
        const labelRegex = /(INDICATIONS|CONTRA\s*INDICATIONS|CONTRAINDICATIONS|PRECAUTIONS|SIDE\s*EFFECTS|DRUG\s*INTERACTIONS)\s*[:]/gi;
        const parts = remaining.split(labelRegex);

        if (parts.length > 1) {
            // First part is bullet-point description
            mainParts = parts[0].split(/[,.]/).map(s => s.trim()).filter(s => s.length > 1);
            for (let i = 1; i < parts.length; i += 2) {
                const label = parts[i];
                const value = (parts[i+1] || '').trim();
                if (value) detailSections.push({ label: label.toUpperCase(), value: value });
            }
        } else {
            // No structured labels — split description into bullet points by sentence/comma
            mainParts = desc.split(/[.]/).map(s => s.trim()).filter(s => s.length > 2);
        }

        // Render About the Product bullet list
        const aboutList = document.getElementById('pmAboutList');
        if (mainParts.length > 0) {
            aboutList.innerHTML = '<ul>' + mainParts.map(s => '<li>' + escapeHtml(s) + '</li>').join('') + '</ul>';
        } else {
            aboutList.innerHTML = '<ul><li>No description available.</li></ul>';
        }

        // Render extra detail sections (INDICATIONS, SIDE EFFECTS etc.)
        const extraEl = document.getElementById('pmExtraDetails');
        if (detailSections.length > 0) {
            extraEl.innerHTML = detailSections.map(s =>
                '<div class="pm-detail-row"><span class="pm-detail-label">' + escapeHtml(s.label) + ':</span> ' + escapeHtml(s.value) + '</div>'
            ).join('');
        } else {
            extraEl.innerHTML = '';
        }

        // SKU
        const skuEl = document.getElementById('pmSku');
        skuEl.textContent = p.sku ? 'SKU: ' + p.sku : '';

        document.getElementById('productModalOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeProductModal(event) {
        if (event && event.target !== event.currentTarget) return;
        document.getElementById('productModalOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }

    function pmChangeQty(delta) {
        let qty = window._pmQty + delta;
        if (qty < 1) qty = 1;
        if (qty > window._pmStock && window._pmStock > 0) qty = window._pmStock;
        window._pmQty = qty;
        document.getElementById('pmQty').textContent = qty;
    }

    function pmAddToCart() {
        const p = productDataMap[window._pmProductId];
        if (!p) return;
        const qty = window._pmQty;

        const existing = cart.find(item => item.id === p.id);
        if (existing) {
            const newQty = existing.quantity + qty;
            if (p.stock > 0 && newQty > p.stock) {
                showToast('Only ' + p.stock + ' in stock for ' + p.name, 'info');
                return;
            }
            existing.quantity = newQty;
        } else {
            cart.push({ id: p.id, name: p.name, price: p.price, quantity: qty });
        }
        saveCart();
        showToast('Added ' + qty + 'x ' + p.name + ' to cart (' + p.stock + ' in stock)', 'success');
        closeProductModal();
        if (!document.getElementById('cartPanel').classList.contains('open')) {
            toggleCartPanel();
        } else {
            renderCartPanel();
        }
    }

    // Buy Now: add to cart + immediately open checkout
    function pmBuyNow() {
        const p = productDataMap[window._pmProductId];
        if (!p || p.stock <= 0) return;
        const qty = window._pmQty;

        // Clear cart and add only this item
        cart = [{ id: p.id, name: p.name, price: p.price, quantity: qty }];
        saveCart();
        closeProductModal();
        // Go straight to checkout
        checkout();
    }

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('productModalOverlay').classList.contains('active')) {
            closeProductModal();
        }
    });
    </script>

    <!-- Customer Care Chat Button -->
    <div class="chat-float" id="chatFloat">
        <div class="chat-popup" id="chatPopup">
            <div class="chat-popup-header">
                <span><i class="fas fa-headset"></i> Customer Care</span>
                <button class="chat-popup-close" onclick="toggleChat()"><i class="fas fa-times"></i></button>
            </div>
            <div class="chat-popup-body">
                <p>Hi! How can we help you today? Reach out to us through any of these channels:</p>
                <div class="chat-contact-item"><i class="fas fa-phone"></i> <a href="tel:88332273">8833 2273</a></div>
                <div class="chat-contact-item"><i class="fas fa-envelope"></i> <a href="mailto:callowaypharmacy@gmail.com">callowaypharmacy@gmail.com</a></div>
                <div class="chat-contact-item"><i class="fab fa-facebook-messenger"></i> <a href="#">Message us on Facebook</a></div>
            </div>
        </div>
        <button class="chat-float-btn" onclick="toggleChat()" title="Customer Care">
            <i class="fas fa-comment-dots" id="chatIcon"></i>
        </button>
    </div>

    <!-- Mega Footer -->
    <div class="mega-footer">
        <div class="mega-footer-grid">
            <div class="footer-col">
                <h3>Calloway Pharmacy Inc.</h3>
                <div class="footer-contact-item">
                    <i class="fas fa-location-dot"></i>
                    <span>051 J. Corona St, Tanauan City, Batangas</span>
                </div>
                <div class="footer-contact-item">
                    <i class="fas fa-phone"></i>
                    <span>8833 2273</span>
                </div>
                <div class="footer-contact-item">
                    <i class="fas fa-envelope"></i>
                    <span>callowaypharmacy@gmail.com</span>
                </div>
            </div>
            <div class="footer-col">
                <h3>About Us</h3>
                <ul>
                    <li><a href="javascript:void(0)" onclick="openFooterModal('aboutModal')">About Calloway Pharmacy</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h3>Get Help</h3>
                <ul>
                    <li><a href="javascript:void(0)" onclick="<?php echo $isLoggedIn ? 'toggleOrdersPanel()' : 'openFooterModal(\'orderStatusModal\')'; ?>">Order Status</a></li>
                    <li><a href="javascript:void(0)" onclick="openFooterModal('faqModal')">FAQs</a></li>
                    <li><a href="javascript:void(0)" onclick="openFooterModal('privacyModal')">Privacy Policy</a></li>
                    <li><a href="javascript:void(0)" onclick="openFooterModal('contactModal')">Contact Us</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h3>Services</h3>
                <ul>
                    <li><a href="javascript:void(0)" onclick="openFooterModal('aboutModal')">Order & Pick-up</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="footer-bottom-links">
                <a href="javascript:void(0)" onclick="openFooterModal('refundModal')">Refund policy</a>
                <a href="javascript:void(0)" onclick="openFooterModal('privacyModal')">Privacy policy</a>
                <a href="javascript:void(0)" onclick="openFooterModal('termsModal')">Terms of service</a>
                <a href="javascript:void(0)" onclick="openFooterModal('contactInfoModal')">Contact information</a>
            </div>
            <div class="footer-copyright">&copy; <?php echo date('Y'); ?>, Calloway Pharmacy</div>
        </div>
    </div>

    <!-- ═══════ Footer Modal Popups ═══════ -->

    <!-- About Calloway Pharmacy Modal -->
    <div class="footer-modal-overlay" id="aboutModal">
        <div class="footer-modal">
            <button class="footer-modal-close" onclick="closeFooterModal('aboutModal')">&times;</button>
            <div class="footer-modal-icon"><i class="fas fa-store-alt"></i></div>
            <h2>About Calloway Pharmacy</h2>
            <div class="footer-modal-body">
                <p>Calloway Pharmacy Inc. is your trusted neighborhood pharmacy located at <strong>051 J. Corona St, Tanauan City, Batangas</strong>. We have been serving the community with quality pharmaceutical products and exceptional customer care.</p>
                <h4><i class="fas fa-star"></i> Our Services</h4>
                <ul>
                    <li><strong>Prescription Dispensing</strong> &mdash; Accurate and timely filling of prescriptions by licensed pharmacists</li>
                    <li><strong>Online Ordering & Pick-up</strong> &mdash; Browse and order medicines online, then pick up at our store</li>
                    <li><strong>Over-the-Counter Medicines</strong> &mdash; Wide selection of OTC pain relievers, cold & flu remedies, vitamins, and supplements</li>
                    <li><strong>Health & Wellness Products</strong> &mdash; Skin care, baby care, first aid supplies, and personal care items</li>
                    <li><strong>Loyalty Rewards Program</strong> &mdash; Earn points with every purchase and redeem exclusive discounts</li>
                    <li><strong>Medicine Consultation</strong> &mdash; Free consultations with our in-house pharmacists</li>
                    <li><strong>Expiry Monitoring</strong> &mdash; We ensure all products on our shelves are safe and within date</li>
                </ul>
                <h4><i class="fas fa-clock"></i> Store Hours</h4>
                <p>Monday &ndash; Saturday: 8:00 AM &ndash; 9:00 PM<br>Sunday: 9:00 AM &ndash; 6:00 PM</p>
                <h4><i class="fas fa-bullseye"></i> Our Mission</h4>
                <p>To provide accessible, affordable, and quality pharmaceutical products and services to every Filipino family in Tanauan City and beyond.</p>
            </div>
        </div>
    </div>

    <!-- Order Status Modal -->
    <div class="footer-modal-overlay" id="orderStatusModal">
        <div class="footer-modal">
            <button class="footer-modal-close" onclick="closeFooterModal('orderStatusModal')">&times;</button>
            <div class="footer-modal-icon"><i class="fas fa-box-open"></i></div>
            <h2>Order Status</h2>
            <div class="footer-modal-body">
                <p>Track the status of your online orders placed through Calloway Pharmacy.</p>
                <div class="footer-modal-form-group">
                    <label for="orderTrackInput"><i class="fas fa-search"></i> Enter your Order Number or Reference Code</label>
                    <input type="text" id="orderTrackInput" placeholder="e.g. ORD-20250213-001" style="width:100%;padding:0.7rem 1rem;border:1px solid #d1d5db;border-radius:8px;font-size:0.95rem;margin-top:0.4rem;">
                    <button onclick="trackOrderStatus()" style="margin-top:0.7rem;width:100%;padding:0.7rem;background:var(--primary-blue,#2563eb);color:white;border:none;border-radius:8px;font-size:0.95rem;font-weight:600;cursor:pointer;"><i class="fas fa-search"></i> Track Order</button>
                </div>
                <div id="orderTrackResult" style="margin-top:1rem;"></div>
                <div class="footer-modal-divider"></div>
                <h4>Order Status Guide</h4>
                <div class="status-guide-item"><span class="status-dot pending"></span><strong>Pending</strong> &mdash; Your order has been received and is waiting for processing</div>
                <div class="status-guide-item"><span class="status-dot processing"></span><strong>Processing</strong> &mdash; Our pharmacist is preparing your order</div>
                <div class="status-guide-item"><span class="status-dot ready"></span><strong>Ready for Pick-up</strong> &mdash; Your order is ready! Visit our store to collect it</div>
                <div class="status-guide-item"><span class="status-dot completed"></span><strong>Completed</strong> &mdash; Order has been collected. Thank you!</div>
                <div class="status-guide-item"><span class="status-dot cancelled"></span><strong>Cancelled</strong> &mdash; Order was cancelled</div>
            </div>
        </div>
    </div>

    <!-- FAQs Modal -->
    <div class="footer-modal-overlay" id="faqModal">
        <div class="footer-modal">
            <button class="footer-modal-close" onclick="closeFooterModal('faqModal')">&times;</button>
            <div class="footer-modal-icon"><i class="fas fa-question-circle"></i></div>
            <h2>Frequently Asked Questions</h2>
            <div class="footer-modal-body">
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)"><i class="fas fa-chevron-right faq-arrow"></i> How do I place an online order?</div>
                    <div class="faq-answer">Browse our product categories, add items to your cart, and proceed to checkout. You will receive an order confirmation with your reference number. Pick up your order at our store once it's ready.</div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)"><i class="fas fa-chevron-right faq-arrow"></i> Do I need a prescription?</div>
                    <div class="faq-answer">Over-the-counter medicines can be purchased directly. Prescription medicines require a valid prescription which you must present upon pick-up at our store.</div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)"><i class="fas fa-chevron-right faq-arrow"></i> How does the loyalty program work?</div>
                                <div class="faq-answer">Earn 25 loyalty points for every ₱500 spent on qualifying purchases. Accumulate points to unlock exclusive discounts and rewards. Visit our <a href="loyalty_qr.php" style="color:var(--primary-blue);">Loyalty & QR page</a> to scan and track your points.</div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)"><i class="fas fa-chevron-right faq-arrow"></i> What payment methods are accepted?</div>
                    <div class="faq-answer">We accept cash payment upon pick-up at our store. Online payment integrations are coming soon.</div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)"><i class="fas fa-chevron-right faq-arrow"></i> Can I cancel my order?</div>
                    <div class="faq-answer">Yes, you can cancel your order while it is still in "Pending" status. Once it moves to "Processing," please contact us directly at <strong>8833 2273</strong> for assistance.</div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)"><i class="fas fa-chevron-right faq-arrow"></i> What are your store hours?</div>
                    <div class="faq-answer">Monday to Saturday: 8:00 AM – 9:00 PM. Sunday: 9:00 AM – 6:00 PM.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Privacy Policy Modal -->
    <div class="footer-modal-overlay" id="privacyModal">
        <div class="footer-modal">
            <button class="footer-modal-close" onclick="closeFooterModal('privacyModal')">&times;</button>
            <div class="footer-modal-icon"><i class="fas fa-shield-alt"></i></div>
            <h2>Privacy Policy</h2>
            <div class="footer-modal-body">
                <p><em>Last updated: <?php echo date('F Y'); ?></em></p>
                <h4>Information We Collect</h4>
                <p>We collect personal information that you provide when creating an account, placing an order, or contacting us. This may include your name, email address, phone number, and order history.</p>
                <h4>How We Use Your Information</h4>
                <ul>
                    <li>To process and fulfill your orders</li>
                    <li>To manage your loyalty rewards account</li>
                    <li>To communicate order updates and promotions</li>
                    <li>To improve our products and services</li>
                </ul>
                <h4>Data Protection</h4>
                <p>We implement industry-standard security measures to protect your personal data. Your information is stored securely and is never sold or shared with third parties for marketing purposes.</p>
                <h4>Your Rights</h4>
                <p>You may request access to, correction of, or deletion of your personal data at any time by contacting us at <strong>callowaypharmacy@gmail.com</strong>.</p>
                <h4>Cookies</h4>
                <p>Our website uses cookies to enhance your browsing experience and remember your preferences. You can manage cookie settings through your browser.</p>
            </div>
        </div>
    </div>

    <!-- Contact Us Modal -->
    <div class="footer-modal-overlay" id="contactModal">
        <div class="footer-modal">
            <button class="footer-modal-close" onclick="closeFooterModal('contactModal')">&times;</button>
            <div class="footer-modal-icon"><i class="fas fa-headset"></i></div>
            <h2>Contact Us</h2>
            <div class="footer-modal-body">
                <p>We'd love to hear from you! Reach out through any of the following channels:</p>
                <div class="contact-method-card">
                    <i class="fas fa-phone-alt"></i>
                    <div><strong>Phone</strong><br><a href="tel:88332273" style="color:var(--primary-blue);">8833 2273</a></div>
                </div>
                <div class="contact-method-card">
                    <i class="fas fa-envelope"></i>
                    <div><strong>Email</strong><br><a href="mailto:callowaypharmacy@gmail.com" style="color:var(--primary-blue);">callowaypharmacy@gmail.com</a></div>
                </div>
                <div class="contact-method-card">
                    <i class="fas fa-map-marker-alt"></i>
                    <div><strong>Visit Us</strong><br>051 J. Corona St, Tanauan City, Batangas</div>
                </div>
                <div class="contact-method-card">
                    <i class="fab fa-facebook"></i>
                    <div><strong>Facebook</strong><br><a href="#" style="color:var(--primary-blue);">Calloway Pharmacy</a></div>
                </div>
                <div class="footer-modal-divider"></div>
                <h4><i class="fas fa-clock"></i> Customer Service Hours</h4>
                <p>Monday &ndash; Saturday: 8:00 AM &ndash; 9:00 PM<br>Sunday: 9:00 AM &ndash; 6:00 PM</p>
            </div>
        </div>
    </div>

    <!-- Refund Policy Modal -->
    <div class="footer-modal-overlay" id="refundModal">
        <div class="footer-modal">
            <button class="footer-modal-close" onclick="closeFooterModal('refundModal')">&times;</button>
            <div class="footer-modal-icon"><i class="fas fa-undo-alt"></i></div>
            <h2>Refund Policy</h2>
            <div class="footer-modal-body">
                <p><em>Effective: <?php echo date('F Y'); ?></em></p>
                <h4>Eligibility for Refund</h4>
                <ul>
                    <li>Items must be returned within <strong>7 days</strong> of purchase</li>
                    <li>Products must be unopened, unused, and in original packaging</li>
                    <li>A valid receipt or order reference number is required</li>
                </ul>
                <h4>Non-Refundable Items</h4>
                <ul>
                    <li>Prescription medicines (once dispensed)</li>
                    <li>Opened or used personal care products</li>
                    <li>Items purchased on clearance or special promotion</li>
                </ul>
                <h4>Refund Process</h4>
                <p>Bring the item and your receipt to our store. Our staff will inspect the product and process your refund within <strong>3&ndash;5 business days</strong>. Refunds are issued via the original payment method.</p>
                <h4>Exchanges</h4>
                <p>We offer exchanges for defective or incorrect items. Please contact us within 48 hours of receiving your order.</p>
            </div>
        </div>
    </div>

    <!-- Terms of Service Modal -->
    <div class="footer-modal-overlay" id="termsModal">
        <div class="footer-modal">
            <button class="footer-modal-close" onclick="closeFooterModal('termsModal')">&times;</button>
            <div class="footer-modal-icon"><i class="fas fa-file-contract"></i></div>
            <h2>Terms of Service</h2>
            <div class="footer-modal-body">
                <p><em>Last updated: <?php echo date('F Y'); ?></em></p>
                <h4>1. Acceptance of Terms</h4>
                <p>By accessing and using the Calloway Pharmacy website and services, you agree to be bound by these Terms of Service.</p>
                <h4>2. Online Orders</h4>
                <p>All online orders are subject to product availability. We reserve the right to limit quantities. Prices displayed are in Philippine Pesos (PHP) and may change without prior notice.</p>
                <h4>3. Prescription Medicines</h4>
                <p>Certain products require a valid prescription. You must present the original prescription upon pick-up. Calloway Pharmacy reserves the right to refuse dispensing without proper documentation.</p>
                <h4>4. Account Responsibility</h4>
                <p>You are responsible for maintaining the confidentiality of your account credentials. Any activity under your account is your responsibility.</p>
                <h4>5. Loyalty Program</h4>
                <p>Loyalty points are non-transferable and have no cash value. Calloway Pharmacy reserves the right to modify or discontinue the loyalty program at any time.</p>
                <h4>6. Limitation of Liability</h4>
                <p>Calloway Pharmacy shall not be liable for any indirect, incidental, or consequential damages arising from the use of our services.</p>
                <h4>7. Governing Law</h4>
                <p>These terms are governed by the laws of the Republic of the Philippines.</p>
            </div>
        </div>
    </div>

    <!-- Contact Information Modal -->
    <div class="footer-modal-overlay" id="contactInfoModal">
        <div class="footer-modal">
            <button class="footer-modal-close" onclick="closeFooterModal('contactInfoModal')">&times;</button>
            <div class="footer-modal-icon"><i class="fas fa-address-card"></i></div>
            <h2>Contact Information</h2>
            <div class="footer-modal-body">
                <div class="contact-info-grid">
                    <div class="contact-info-card">
                        <i class="fas fa-building"></i>
                        <h4>Business Name</h4>
                        <p>Calloway Pharmacy Inc.</p>
                    </div>
                    <div class="contact-info-card">
                        <i class="fas fa-map-marker-alt"></i>
                        <h4>Address</h4>
                        <p>051 J. Corona St,<br>Tanauan City, Batangas</p>
                    </div>
                    <div class="contact-info-card">
                        <i class="fas fa-phone-alt"></i>
                        <h4>Phone</h4>
                        <p><a href="tel:88332273" style="color:var(--primary-blue);">8833 2273</a></p>
                    </div>
                    <div class="contact-info-card">
                        <i class="fas fa-envelope"></i>
                        <h4>Email</h4>
                        <p><a href="mailto:callowaypharmacy@gmail.com" style="color:var(--primary-blue);">callowaypharmacy@gmail.com</a></p>
                    </div>
                </div>
                <div class="footer-modal-divider"></div>
                <h4><i class="fas fa-clock"></i> Store Hours</h4>
                <table class="hours-table">
                    <tr><td>Monday &ndash; Saturday</td><td>8:00 AM &ndash; 9:00 PM</td></tr>
                    <tr><td>Sunday</td><td>9:00 AM &ndash; 6:00 PM</td></tr>
                </table>
            </div>
        </div>
    </div>

    <script>
        // ─── Storage Helpers (Tracking Prevention safe) ───
        const __storageOk = (() => {
            try {
                const k = '__calloway_storage_test__';
                localStorage.setItem(k, '1');
                localStorage.removeItem(k);
                return true;
            } catch (e) {
                return false;
            }
        })();

        function storageGet(key) {
            if (!__storageOk) return null;
            try { return localStorage.getItem(key); } catch (_) { return null; }
        }

        function storageSet(key, value) {
            if (!__storageOk) return false;
            try { localStorage.setItem(key, value); return true; } catch (_) { return false; }
        }

        // ─── Cart State (localStorage, scoped per session owner) ───
        const cartOwnerKey = <?php echo $isLoggedIn ? ('"user_' . intval($_SESSION['user_id']) . '"') : '"guest"'; ?>;
        const cartStorageKey = 'calloway_cart_' + cartOwnerKey;
        const legacyCartKey = 'calloway_cart';

        function safeParseCart(raw) {
            try {
                const parsed = JSON.parse(raw || '[]');
                return Array.isArray(parsed) ? parsed : [];
            } catch (_) {
                return [];
            }
        }

        // One-time migration: preserve old shared cart only for guest context.
        // Logged-in users should never inherit guest cart data.
        if (!storageGet(cartStorageKey)) {
            if (cartOwnerKey === 'guest' && storageGet(legacyCartKey)) {
                storageSet(cartStorageKey, storageGet(legacyCartKey) || '[]');
            } else {
                storageSet(cartStorageKey, '[]');
            }
        }

        let cart = safeParseCart(storageGet(cartStorageKey));
        let currentCategory = null;

        function sanitizeCartAgainstCatalog() {
            const previousLength = cart.length;
            let quantityAdjusted = false;

            cart = cart.filter(item => {
                const product = productDataMap[item.id];
                if (!product || product.stock <= 0) {
                    return false;
                }

                const currentQty = parseInt(item.quantity || item.qty || 1, 10);
                const normalizedQty = Math.max(1, Math.min(currentQty, product.stock));
                if (normalizedQty !== currentQty) {
                    quantityAdjusted = true;
                }
                item.quantity = normalizedQty;
                item.qty = normalizedQty;
                return true;
            });

            if (cart.length !== previousLength || quantityAdjusted) {
                saveCart();
            }

            return {
                removed: previousLength - cart.length,
                quantityAdjusted
            };
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (!__storageOk) {
                console.warn('Storage is blocked by the browser. Cart/wishlist will not persist after refresh.');
            }

            const cartFixes = sanitizeCartAgainstCatalog();
            if (cartFixes.removed > 0) {
                showToast('Some unavailable or expired items were removed from your cart.', 'info');
            } else if (cartFixes.quantityAdjusted) {
                showToast('Cart quantities were adjusted to available stock.', 'info');
            }

            updateCartUI();
            renderCartPanel();
        });

        // ─── Category-first navigation ───
        function showCategoryProducts(category) {
            currentCategory = category;

            // Hide category cards + promo banner, show products
            document.getElementById('categoryCardsSection').style.display = 'none';
            document.getElementById('qrPromoBanner').style.display = 'none';
            const productsSection = document.querySelector('.products-section');
            productsSection.classList.add('visible');

            // Show big category banner
            document.getElementById('categoryTitleBanner').style.display = '';
            document.getElementById('categoryBannerName').textContent = category;

            // Default to grid view for category browsing
            setView('grid');

            // Filter products
            const query = document.getElementById('searchInput').value.toLowerCase().trim();
            applyFilters(query, category);
        }

        function showCategories() {
            currentCategory = null;
            document.getElementById('categoryCardsSection').style.display = '';
            document.getElementById('qrPromoBanner').style.display = '';
            document.querySelector('.products-section').classList.remove('visible');
            document.getElementById('categoryTitleBanner').style.display = 'none';
            document.getElementById('searchInput').value = '';
            setView('grid');
        }

        // ─── View Toggle (grid / small-grid / list) ───
        function setView(mode) {
            const grid = document.getElementById('productsGrid');
            grid.classList.remove('list-view', 'small-grid-view');

            document.getElementById('viewGridBtn').classList.remove('active');
            document.getElementById('viewSmallGridBtn').classList.remove('active');
            document.getElementById('viewListBtn').classList.remove('active');

            if (mode === 'list') {
                grid.classList.add('list-view');
                document.getElementById('viewListBtn').classList.add('active');
            } else if (mode === 'small-grid') {
                grid.classList.add('small-grid-view');
                document.getElementById('viewSmallGridBtn').classList.add('active');
            } else {
                document.getElementById('viewGridBtn').classList.add('active');
            }
        }

        // ─── Sort Products ───
        function sortProducts() {
            const sortVal = document.getElementById('sortSelect').value;
            const grid = document.getElementById('productsGrid');
            const cards = Array.from(grid.querySelectorAll('.product-card'));

            cards.sort((a, b) => {
                const nameA = a.dataset.name || '';
                const nameB = b.dataset.name || '';
                const priceA = parseFloat(a.querySelector('.product-price')?.textContent.replace(/[^\d.]/g, '') || 0);
                const priceB = parseFloat(b.querySelector('.product-price')?.textContent.replace(/[^\d.]/g, '') || 0);

                switch (sortVal) {
                    case 'name-asc': return nameA.localeCompare(nameB);
                    case 'name-desc': return nameB.localeCompare(nameA);
                    case 'price-asc': return priceA - priceB;
                    case 'price-desc': return priceB - priceA;
                    default: return 0;
                }
            });

            cards.forEach(card => grid.appendChild(card));
        }

        // ─── Wishlist System (localStorage, scoped per session owner) ───
        const wishlistOwnerKey = <?php echo $isLoggedIn ? ('"user_' . intval($_SESSION['user_id']) . '"') : '"guest"'; ?>;
        const wishlistStorageKey = 'calloway_wishlist_' + wishlistOwnerKey;
        const legacyWishlistKey = 'calloway_wishlist';

        function safeParseWishlist(raw) {
            try {
                const parsed = JSON.parse(raw || '[]');
                return Array.isArray(parsed) ? parsed : [];
            } catch (_) {
                return [];
            }
        }

        // One-time migration: preserve old shared wishlist only for guest context.
        // Logged-in users should never inherit guest wishlist data.
        if (!storageGet(wishlistStorageKey)) {
            if (wishlistOwnerKey === 'guest' && storageGet(legacyWishlistKey)) {
                storageSet(wishlistStorageKey, storageGet(legacyWishlistKey) || '[]');
            } else {
                storageSet(wishlistStorageKey, '[]');
            }
        }

        let wishlist = safeParseWishlist(storageGet(wishlistStorageKey));

        function saveWishlist() {
            storageSet(wishlistStorageKey, JSON.stringify(wishlist));
            updateWishlistUI();
        }

        function updateWishlistUI() {
            const badge = document.getElementById('headerWishlistCount');
            if (badge) badge.textContent = wishlist.length;
        }

        function isInWishlist(productId) {
            return wishlist.some(w => w.id === productId);
        }

        function toggleWishlist(btn, productId) {
            const p = productDataMap[productId];
            if (!p) return;
            if (isInWishlist(productId)) {
                wishlist = wishlist.filter(w => w.id !== productId);
                if (btn) { btn.classList.remove('active'); const i = btn.querySelector('i'); if (i) i.className = 'far fa-heart'; }
                showToast('Removed from wishlist', 'info');
            } else {
                wishlist.push({ id: p.id, name: p.name, price: p.price, image: p.image });
                if (btn) { btn.classList.add('active'); const i = btn.querySelector('i'); if (i) i.className = 'fas fa-heart'; }
                showToast('Added to wishlist ❤️', 'success');
            }
            saveWishlist();
            renderWishlistPanel();
        }

        function removeFromWishlist(productId) {
            wishlist = wishlist.filter(w => w.id !== productId);
            saveWishlist();
            renderWishlistPanel();
            showToast('Removed from wishlist', 'info');
        }

        function addWishlistToCart(productId) {
            const p = productDataMap[productId];
            if (!p) return;
            const existing = cart.find(item => item.id === productId);
            if (existing) { existing.quantity += 1; }
            else { cart.push({ id: p.id, name: p.name, price: p.price, quantity: 1 }); }
            saveCart();
            showToast('Added ' + p.name + ' to cart', 'success');
            renderCartPanel();
        }

        function toggleWishlistPanel() {
            document.getElementById('wishlistPanel').classList.toggle('open');
            document.getElementById('wishlistOverlay').classList.toggle('open');
            renderWishlistPanel();
        }

        function renderWishlistPanel() {
            const body = document.getElementById('wishlistPanelBody');
            if (wishlist.length === 0) {
                body.innerHTML = '<div class="wishlist-empty"><i class="far fa-heart"></i><p>Your wishlist is empty</p><span>Browse products and tap the heart to save them here</span></div>';
                return;
            }
            let html = '';
            wishlist.forEach(item => {
                const imgHtml = item.image
                    ? '<img src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.name) + '">'
                    : '<i class="fas fa-pills"></i>';
                html += `<div class="wishlist-item" onclick="openProductModal(${item.id}); toggleWishlistPanel();">
                    <div class="wishlist-item-img">${imgHtml}</div>
                    <div class="wishlist-item-details">
                        <div class="wishlist-item-name" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</div>
                        <div class="wishlist-item-price">₱${item.price.toFixed(2)}</div>
                    </div>
                    <div class="wishlist-item-actions" onclick="event.stopPropagation()">
                        <button class="wl-cart-btn" onclick="addWishlistToCart(${item.id})" title="Add to Cart"><i class="fas fa-cart-plus"></i></button>
                        <button class="wl-remove-btn" onclick="removeFromWishlist(${item.id})" title="Remove"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </div>`;
            });
            body.innerHTML = html;
        }

        // Init wishlist UI on load
        document.addEventListener('DOMContentLoaded', function() {
            updateWishlistUI();
            renderWishlistPanel();
            // Sync wishlist heart icons on product cards
            document.querySelectorAll('.wishlist-btn').forEach(btn => {
                const match = btn.getAttribute('onclick')?.match(/toggleWishlist\(this,\s*(\d+)\)/);
                if (match) {
                    const pid = parseInt(match[1]);
                    if (isInWishlist(pid)) {
                        btn.classList.add('active');
                        const i = btn.querySelector('i');
                        if (i) i.className = 'fas fa-heart';
                    }
                }
            });
        });

        // ─── Search ───
        function filterProducts() {
            const query = document.getElementById('searchInput').value.toLowerCase().trim();
            if (query.length > 0 && !currentCategory) {
                // Search across all — show products section
                document.getElementById('categoryCardsSection').style.display = 'none';
                document.getElementById('qrPromoBanner').style.display = 'none';
                document.querySelector('.products-section').classList.add('visible');
                document.getElementById('categoryTitleBanner').style.display = '';
                document.getElementById('categoryBannerName').textContent = 'Search Results';
                setView('grid');
                applyFilters(query, 'all');
            } else if (query.length === 0 && !currentCategory) {
                showCategories();
            } else {
                applyFilters(query, currentCategory || 'all');
            }
        }

        function applyFilters(query, category) {
            const cards = document.querySelectorAll('.product-card');
            let visible = 0;
            cards.forEach(card => {
                const name = card.dataset.name || '';
                const cat = card.dataset.category || '';
                const desc = card.dataset.desc || '';
                const matchCategory = (category === 'all' || cat === category);
                const matchSearch = !query || name.includes(query) || desc.includes(query);
                if (matchCategory && matchSearch) { card.style.display = ''; visible++; }
                else { card.style.display = 'none'; }
            });
            document.getElementById('productCount').textContent = visible + ' product' + (visible !== 1 ? 's' : '');
            const grid = document.getElementById('productsGrid');
            let emptyState = grid.querySelector('.empty-state.search-empty');
            if (visible === 0) {
                if (!emptyState) {
                    emptyState = document.createElement('div');
                    emptyState.className = 'empty-state search-empty';
                    emptyState.innerHTML = '<i class="fas fa-search"></i><h3>No matches found</h3><p>Try a different search term or category.</p>';
                    grid.appendChild(emptyState);
                }
                emptyState.style.display = '';
            } else if (emptyState) { emptyState.style.display = 'none'; }
        }

        // ─── Chat Toggle ───
        function toggleChat() {
            const popup = document.getElementById('chatPopup');
            const icon = document.getElementById('chatIcon');
            popup.classList.toggle('open');
            icon.className = popup.classList.contains('open') ? 'fas fa-times' : 'fas fa-comment-dots';
        }

        <?php if ($isAdmin): ?>
        // ─── Admin: Product CRUD ───
        function editProduct(productId) {
            // Fetch product data and show modal
            fetch('inventory_api.php?action=get_product&id=' + productId)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { showToast('Failed to load product', 'info'); return; }
                    const p = data.data;
                    showAdminModal('Edit Product', `
                        <input type="hidden" id="adminEditId" value="${p.product_id}">
                        <label>Product Name</label>
                        <input type="text" id="adminEditName" value="${escapeHtml(p.name)}">
                        <label>Description</label>
                        <textarea id="adminEditDesc">${escapeHtml(p.description || '')}</textarea>
                        <label>Selling Price (₱)</label>
                        <input type="number" step="0.01" id="adminEditPrice" value="${p.selling_price}">
                        <label>Stock Quantity</label>
                        <input type="number" id="adminEditStock" value="${p.stock_quantity}">
                    `, saveProduct);
                })
                .catch(() => showToast('Error loading product', 'info'));
        }

        function saveProduct() {
            const id = document.getElementById('adminEditId').value;
            const payload = {
                product_id: parseInt(id),
                name: document.getElementById('adminEditName').value,
                description: document.getElementById('adminEditDesc').value,
                selling_price: parseFloat(document.getElementById('adminEditPrice').value),
                stock_quantity: parseInt(document.getElementById('adminEditStock').value)
            };

            fetch('inventory_api.php?action=update_product', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Product updated!', 'success'); closeAdminModal(); location.reload(); }
                    else showToast(data.message || 'Update failed', 'info');
                })
                .catch(() => showToast('Error updating product', 'info'));
        }

        async function deleteProduct(productId, name) {
            const ok = await customConfirm('Delete Product', 'Delete "' + name + '"? This cannot be undone.', 'danger', { confirmText: 'Yes, Delete', cancelText: 'Cancel' });
            if (!ok) return;

            fetch('inventory_api.php?action=delete_product&id=' + productId, { method: 'GET' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Product deleted', 'success'); location.reload(); }
                    else showToast(data.message || 'Delete failed', 'info');
                })
                .catch(() => showToast('Error deleting product', 'info'));
        }

        function editProductImage(productId, name) {
            showAdminModal('Change Image — ' + name, `
                <input type="hidden" id="adminImgProductId" value="${productId}">
                <label>Select Image</label>
                <input type="file" id="adminImgFile" accept="image/*" onchange="previewAdminImg(this)">
                <img id="adminImgPreview" class="img-preview" style="display:none">
            `, uploadProductImage);
        }

        function previewAdminImg(input) {
            const preview = document.getElementById('adminImgPreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function uploadProductImage() {
            const id = document.getElementById('adminImgProductId').value;
            const file = document.getElementById('adminImgFile').files[0];
            if (!file) { showToast('Please select an image', 'info'); return; }

            const formData = new FormData();
            formData.append('product_id', id);
            formData.append('image', file);

            fetch('upload_product_image.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Image updated!', 'success'); closeAdminModal(); location.reload(); }
                    else showToast(data.message || 'Upload failed', 'info');
                })
                .catch(() => showToast('Error uploading image', 'info'));
        }

        // ─── Admin: Category CRUD ───
        function editCategory(catId, catName) {
            showAdminModal('Edit Category', `
                <input type="hidden" id="adminCatId" value="${catId}">
                <label>Category Name</label>
                <input type="text" id="adminCatName" value="${escapeHtml(catName)}">
            `, saveCategory);
        }

        function saveCategory() {
            const id = document.getElementById('adminCatId').value;
            const name = document.getElementById('adminCatName').value.trim();
            if (!name) { showToast('Name cannot be empty', 'info'); return; }

            const formData = new FormData();
            formData.append('category_id', id);
            formData.append('category_name', name);

            fetch('inventory_api.php?action=update_category', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Category updated!', 'success'); closeAdminModal(); location.reload(); }
                    else showToast(data.message || 'Update failed', 'info');
                })
                .catch(() => showToast('Error updating category', 'info'));
        }

        async function deleteCategory(catId, catName) {
            const ok = await customConfirm('Delete Category', 'Delete category "' + catName + '"? Products in this category will become uncategorized.', 'danger', { confirmText: 'Yes, Delete', cancelText: 'Cancel' });
            if (!ok) return;
            const formData = new FormData();
            formData.append('category_id', catId);

            fetch('inventory_api.php?action=delete_category', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Category deleted', 'success'); location.reload(); }
                    else showToast(data.message || 'Delete failed', 'info');
                })
                .catch(() => showToast('Error deleting category', 'info'));
        }

        // ─── Admin Modal Helpers ───
        function showAdminModal(title, bodyHtml, onSave) {
            let overlay = document.getElementById('adminModalOverlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'adminModalOverlay';
                overlay.className = 'admin-modal-overlay';
                overlay.innerHTML = `<div class="admin-modal">
                    <div class="admin-modal-header"><span id="adminModalTitle"></span><button onclick="closeAdminModal()"><i class="fas fa-times"></i></button></div>
                    <div class="admin-modal-body" id="adminModalBody"></div>
                    <div class="admin-modal-footer">
                        <button class="admin-cancel-btn" onclick="closeAdminModal()">Cancel</button>
                        <button class="admin-save-btn" id="adminModalSaveBtn">Save</button>
                    </div>
                </div>`;
                document.body.appendChild(overlay);
            }
            document.getElementById('adminModalTitle').textContent = title;
            document.getElementById('adminModalBody').innerHTML = bodyHtml;
            document.getElementById('adminModalSaveBtn').onclick = onSave;
            overlay.classList.add('active');
        }

        function closeAdminModal() {
            const overlay = document.getElementById('adminModalOverlay');
            if (overlay) overlay.classList.remove('active');
        }
        <?php endif; ?>

        // ─── Cart Panel Toggle ───
        function toggleCartPanel() {
            document.getElementById('cartPanel').classList.toggle('open');
            document.getElementById('cartOverlay').classList.toggle('open');
            renderCartPanel();
        }

        // ─── Cart Logic ───
        function addToCart(productId, productName, price, stock) {
            const existing = cart.find(item => item.id === productId);
            if (existing) {
                if (stock && existing.quantity >= stock) {
                    showToast('Only ' + stock + ' in stock for ' + productName, 'info');
                    return;
                }
                existing.quantity += 1;
            } else {
                cart.push({ id: productId, name: productName, price: parseFloat(price), quantity: 1 });
            }
            saveCart();
            const stockMsg = stock ? ' (' + stock + ' in stock)' : '';
            showToast('Added ' + productName + ' to cart' + stockMsg, 'success');
            // Auto-open cart panel so user can see what they added
            if (!document.getElementById('cartPanel').classList.contains('open')) {
                toggleCartPanel();
            } else {
                renderCartPanel();
            }
        }

        function changeQty(productId, delta) {
            const item = cart.find(i => i.id === productId);
            if (!item) return;
            item.quantity += delta;
            if (item.quantity <= 0) {
                cart = cart.filter(i => i.id !== productId);
            }
            saveCart();
            renderCartPanel();
        }

        function removeFromCart(productId) {
            const item = cart.find(i => i.id === productId);
            cart = cart.filter(i => i.id !== productId);
            saveCart();
            renderCartPanel();
            if (item) showToast('Removed ' + item.name, 'info');
        }

        function clearCart() {
            cart = [];
            saveCart();
            renderCartPanel();
            showToast('Cart cleared', 'info');
        }

        function saveCart() {
            storageSet(cartStorageKey, JSON.stringify(cart));
            updateCartUI();
        }

        function updateCartUI() {
            const total = cart.reduce((s, i) => s + i.quantity, 0);
            document.getElementById('cartCount').textContent = total;
            document.getElementById('headerCartCount').textContent = total;
            // floating cart hidden — badge is in topbar now
        }

        // ─── Render Cart Panel Contents ───
        function renderCartPanel() {
            const body = document.getElementById('cartPanelBody');
            const footer = document.getElementById('cartPanelFooter');
            const emptyEl = document.getElementById('cartEmpty');

            if (cart.length === 0) {
                body.innerHTML = '';
                body.appendChild(createEmptyState());
                footer.style.display = 'none';
                return;
            }

            const totalAmount = cart.reduce((s, i) => s + (i.price * i.quantity), 0);
            const totalItems = cart.reduce((s, i) => s + i.quantity, 0);

            let html = '';
            cart.forEach(item => {
                const subtotal = item.price * item.quantity;
                html += `
                    <div class="cart-item">
                        <div class="cart-item-icon"><i class="fas fa-pills"></i></div>
                        <div class="cart-item-details">
                            <div class="cart-item-name" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</div>
                            <div class="cart-item-price-info">
                                Unit Price: ₱${item.price.toFixed(2)} &times; ${item.quantity}
                            </div>
                            <div class="cart-item-subtotal">₱${subtotal.toFixed(2)}</div>
                        </div>
                        <div class="cart-item-actions">
                            <button class="qty-btn" onclick="changeQty(${item.id}, 1)" title="Increase"><i class="fas fa-plus"></i></button>
                            <span class="cart-item-qty">${item.quantity}</span>
                            <button class="qty-btn" onclick="changeQty(${item.id}, -1)" title="Decrease"><i class="fas fa-minus"></i></button>
                            <button class="qty-btn remove" onclick="removeFromCart(${item.id})" title="Remove"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </div>
                `;
            });
            body.innerHTML = html;

            // Update footer totals
            footer.style.display = '';
            document.getElementById('cartSubtotal').textContent = '₱' + totalAmount.toFixed(2);
            document.getElementById('cartTotal').textContent = '₱' + totalAmount.toFixed(2);
            document.getElementById('cartTotalItems').textContent = totalItems + ' item' + (totalItems !== 1 ? 's' : '');
        }

        function createEmptyState() {
            const div = document.createElement('div');
            div.className = 'cart-empty';
            div.id = 'cartEmpty';
            div.innerHTML = '<i class="fas fa-shopping-basket"></i><p>Your cart is empty</p><span>Add some medicines to get started</span>';
            return div;
        }

        function checkout() {
            if (cart.length === 0) {
                showToast('Your cart is empty!', 'info');
                return;
            }

            // Build order summary in modal
            const overlay = document.getElementById('checkoutOverlay');
            const itemsList = document.getElementById('checkoutItemsList');
            let totalAmount = 0;
            let html = '';

            cart.forEach(item => {
                const qty = item.quantity || item.qty || 1;
                const lineTotal = item.price * qty;
                totalAmount += lineTotal;
                html += `
                    <div class="checkout-item">
                        <div>
                            <div class="checkout-item-name">${escapeHtml(item.name)}</div>
                            <div class="checkout-item-qty">${qty} x ₱${item.price.toFixed(2)}</div>
                        </div>
                        <div class="checkout-item-price">₱${lineTotal.toFixed(2)}</div>
                    </div>`;
            });

            itemsList.innerHTML = html;
            document.getElementById('checkoutSubtotal').textContent = '₱' + totalAmount.toFixed(2);
            document.getElementById('checkoutTotal').textContent = '₱' + totalAmount.toFixed(2);

            // Store subtotal for points calculation
            orderSubtotal = totalAmount;

            // Reset senior discount checkbox
            const seniorCheck = document.getElementById('seniorDiscountCheck');
            if (seniorCheck) { seniorCheck.checked = false; }
            document.getElementById('seniorDiscountRow').style.display = 'none';
            const discCard = document.getElementById('seniorDiscountCard');
            if (discCard) {
                discCard.style.borderColor = 'rgba(34,197,94,0.25)';
                discCard.style.background = 'rgba(34,197,94,0.08)';
            }
            const claimNotice = document.getElementById('seniorClaimNotice');
            if (claimNotice) { claimNotice.style.display = 'none'; }

            // Reset points UI
            <?php if ($isLoggedIn): ?>
            document.getElementById('pointsToRedeem').value = 0;
            document.getElementById('pointsDiscountRow').style.display = 'none';
            document.getElementById('pointsError').style.display = 'none';
            pointsToRedeem = 0;
            fetchLoyaltyPoints(); // Load available points
            const cashRadio = document.querySelector('input[name="paymentMethod"][value="Cash on Pickup"]');
            if (cashRadio) cashRadio.checked = true;
            <?php endif; ?>

            // Reset to step 1
            document.getElementById('checkoutStep1').style.display = '';
            document.getElementById('checkoutStep2').style.display = 'none';

            // Close cart panel, show checkout
            document.getElementById('cartPanel').classList.remove('open');
            document.getElementById('cartOverlay').classList.remove('open');
            overlay.classList.add('active');
        }

        function closeCheckoutModal(event) {
            if (event && event.target !== event.currentTarget) return;
            document.getElementById('checkoutOverlay').classList.remove('active');
        }

        const SENIOR_DISCOUNT_MIN = 200;

        // Recalculate checkout total with points (SC/PWD discount is NOT applied here — verified at pickup)
        function recalcCheckoutTotal() {
            let total = orderSubtotal;

            // Show SC/PWD claim row (informational only — no actual discount subtracted)
            const seniorCheck = document.getElementById('seniorDiscountCheck');
            if (seniorCheck && seniorCheck.checked) {
                document.getElementById('seniorDiscountRow').style.display = 'flex';
            } else {
                document.getElementById('seniorDiscountRow').style.display = 'none';
            }

            // Apply points discount
            if (typeof pointsToRedeem !== 'undefined' && pointsToRedeem > 0) {
                total -= pointsToRedeem;
                if (total < 0) total = 0;
            }

            document.getElementById('checkoutTotal').textContent = '₱' + total.toFixed(2);
        }

        function updateSeniorDiscount() {
            const card = document.getElementById('seniorDiscountCard');
            const isChecked = document.getElementById('seniorDiscountCheck').checked;
            const notice = document.getElementById('seniorClaimNotice');
            if (card) {
                card.style.borderColor = isChecked ? '#f59e0b' : 'rgba(34,197,94,0.25)';
                card.style.background = isChecked 
                    ? 'rgba(245,158,11,0.10)' 
                    : 'rgba(34,197,94,0.08)';
            }
            if (notice) {
                notice.style.display = isChecked ? 'block' : 'none';
            }
            recalcCheckoutTotal();
        }

        async function placeOrder() {
            const btn = document.querySelector('.checkout-place-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Placing Order...';

            const cartFixes = sanitizeCartAgainstCatalog();
            if (cartFixes.removed > 0 || cartFixes.quantityAdjusted) {
                renderCartPanel();
            }

            if (!Array.isArray(cart) || cart.length === 0) {
                showToast('Your cart is empty!', 'info');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                return;
            }

            const paymentEl = document.querySelector('input[name="paymentMethod"]:checked');
            const paymentMethod = paymentEl ? paymentEl.value : 'Cash on Pickup';

            if (paymentMethod === 'Loyalty Points') {
                const requiredPoints = roundMoney(orderSubtotal);
                const usablePoints = roundMoney(Math.min(pointsToRedeem, userLoyaltyPoints));
                if (usablePoints + 0.0001 < requiredPoints) {
                    showToast(`Insufficient points for full payment. Need ${formatPoints(requiredPoints)} points.`, 'info');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                    return;
                }
            }

            // Map cart items to ensure 'qty' property exists for the backend
            const orderItems = cart
                .map(item => ({
                    id: item.id,
                    name: item.name,
                    price: item.price,
                    qty: item.quantity || item.qty || 1
                }))
                .filter(i => Number.isFinite(parseInt(i.id)) && parseInt(i.id) > 0 && Number.isFinite(parseInt(i.qty)) && parseInt(i.qty) > 0);

            if (orderItems.length === 0) {
                showToast('Your cart items are invalid. Please remove and re-add items, then try again.', 'info');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                return;
            }

            const orderData = {
                items: orderItems,
                payment_method: paymentMethod,
                customer_name: '<?php echo addslashes($customerName ?: "Guest"); ?>',
                senior_discount: document.getElementById('seniorDiscountCheck')?.checked ? 1 : 0,
                <?php if ($isLoggedIn): ?>
                points_to_redeem: pointsToRedeem
                <?php endif; ?>
            };

            try {
                const res = await fetch('order_handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(orderData),
                    credentials: 'same-origin' // Ensure cookies/session are sent
                });

                const rawText = await res.text();
                let data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (parseErr) {
                    console.error('Non-JSON response:', rawText);
                }

                console.log('[OnlineOrder placeOrder] status:', res.status, 'raw:', rawText.substring(0, 500));

                // Handle authentication error - user not logged in
                if (res.status === 401) {
                    showToast(data?.message || 'Please log in to place an order', 'info');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                    // Optionally redirect to login
                    setTimeout(() => {
                        if (confirm('You need to log in to place an order. Go to login page?')) {
                            window.location.href = 'login.php?redirect=onlineordering.php';
                        }
                    }, 500);
                    return;
                }

                if (!res.ok) {
                    const statusMsg = `Server error (${res.status})`;
                    const detailedMessage = (Array.isArray(data?.errors) && data.errors.length)
                        ? `${data.message || statusMsg}: ${data.errors[0]}`
                        : (data?.message || statusMsg);
                    showToast(detailedMessage, 'info');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                    return;
                }

                if (data && data.success) {
                    // Show success step
                    document.getElementById('checkoutStep1').style.display = 'none';
                    document.getElementById('checkoutStep2').style.display = 'block'; // Ensure block display
                    
                    // Populate Receipt Modal
                    const orderRef = data.order_ref || 'PENDING';
                    document.getElementById('receiptRef').textContent = orderRef;
                    document.getElementById('receiptDate').textContent = new Date().toLocaleString();
                    
                    // Use the final total calculated during checkout (including discounts if applied)
                    const finalTotal = document.getElementById('checkoutTotal').textContent;
                    document.getElementById('receiptTotal').textContent = finalTotal;

                    // Items List
                    const itemsDiv = document.getElementById('receiptItemsList');
                    itemsDiv.innerHTML = cart.map(item => `
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
                            <span>${item.quantity}x ${escapeHtml(item.name)}</span>
                            <span>₱${(item.price * item.quantity).toFixed(2)}</span>
                        </div>
                    `).join('');

                    // Generate QR Code for Order Reference
                    const qrContainer = document.getElementById('receiptQrCode');
                    qrContainer.innerHTML = '';
                    if (typeof QRCode !== 'undefined') {
                        new QRCode(qrContainer, {
                            text: orderRef,
                            width: 128,
                            height: 128,
                            colorDark: "#000000",
                            colorLight: "#ffffff",
                            correctLevel: QRCode.CorrectLevel.H
                        });
                    } else {
                        // Fallback: use QR code image API
                        const img = document.createElement('img');
                        img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=128x128&data=' + encodeURIComponent(orderRef);
                        img.alt = 'Order QR Code';
                        img.width = 128;
                        img.height = 128;
                        img.style.borderRadius = '4px';
                        qrContainer.appendChild(img);
                    }

                    // Clear cart
                    cart = [];
                    saveCart();
                    renderCartPanel();

                    // Refresh My Orders immediately after successful placement
                    <?php if ($isLoggedIn): ?>
                    setTimeout(() => {
                        if (typeof loadMyOrders === 'function') loadMyOrders();
                    }, 300);
                    <?php endif; ?>

                    // Show points earned message if available
                    const message = data.message || 'Order placed successfully! 🎉';
                    showToast(message, 'success');

                    // Show Reward QR Code popup if available (keep this separate functionality)
                    if (data.reward_qr_code) {
                        setTimeout(() => {
                            showRewardQrPopup(data.reward_qr_code);
                        }, 2000); // Delay slightly so they see the receipt first
                    }
                } else {
                    // data.success is false or data is null
                    const errorMsg = data?.message || (rawText ? 'Server returned: ' + rawText.substring(0, 100) : 'Failed to place order');
                    showToast(errorMsg, 'info');
                    console.error('Order failed. Response:', rawText);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
                }
            } catch (err) {
                console.error('Order error:', err);
                const errMsg = err && err.message ? err.message : 'Network error. Please try again.';
                showToast(errMsg, 'info');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Place Order';
            }
        }

        // ─── Toast Notification ───
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast' + (type === 'info' ? ' info' : '');
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i> ${message}`;
            container.appendChild(toast);
            // Errors/info stay visible longer
            const delay = (type === 'success') ? 2500 : 5000;
            setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, delay);
            if (type !== 'success') console.error('[OnlineOrder Toast]', message);
        }

        // ─── Utility ───
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ===== LOYALTY POINTS SYSTEM =====
        let userLoyaltyPoints = 0;
        let pointsToRedeem = 0;
        let orderSubtotal = 0;

        function roundMoney(value) {
            return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
        }

        function formatPoints(value) {
            const n = roundMoney(value);
            return n.toFixed(2).replace(/\.00$/, '');
        }

        function getSelectedPaymentMethod() {
            const paymentEl = document.querySelector('input[name="paymentMethod"]:checked');
            return paymentEl ? paymentEl.value : 'Cash on Pickup';
        }

        function syncPointsWithPaymentMethod() {
            const selected = getSelectedPaymentMethod();
            const input = document.getElementById('pointsToRedeem');
            if (!input) return;

            if (selected === 'Loyalty Points') {
                const needed = roundMoney(orderSubtotal);
                const maxUsable = roundMoney(Math.min(userLoyaltyPoints, needed));
                input.value = maxUsable.toFixed(2);
            }

            updatePointsDiscount();
        }

        async function fetchLoyaltyPoints() {
            <?php if ($isLoggedIn): ?>
            try {
                const res = await fetch('get_loyalty_points.php');
                const data = await res.json();
                if (data.success && typeof data.points !== 'undefined') {
                    userLoyaltyPoints = roundMoney(parseFloat(data.points) || 0);
                    document.getElementById('availablePoints').textContent = `${formatPoints(userLoyaltyPoints)} points`;
                    document.getElementById('pointsToRedeem').max = userLoyaltyPoints.toFixed(2);
                } else {
                    userLoyaltyPoints = 0;
                    document.getElementById('availablePoints').textContent = '0 points';
                }
                syncPointsWithPaymentMethod();
            } catch (err) {
                console.error('Failed to fetch loyalty points:', err);
                userLoyaltyPoints = 0;
                document.getElementById('availablePoints').textContent = '0 points';
            }
            <?php endif; ?>
        }

        function updatePointsDiscount() {
            const input = document.getElementById('pointsToRedeem');
            const pointsError = document.getElementById('pointsError');
            const selectedMethod = getSelectedPaymentMethod();
            let points = roundMoney(parseFloat(input.value) || 0);
            const maxOrderPoints = roundMoney(orderSubtotal);

            // Validate
            if (points < 0) {
                points = 0;
                input.value = '0.00';
            }
            if (points > userLoyaltyPoints) {
                pointsError.textContent = `You only have ${formatPoints(userLoyaltyPoints)} points available`;
                pointsError.style.display = 'block';
                points = userLoyaltyPoints;
                input.value = userLoyaltyPoints.toFixed(2);
            } else if (points > orderSubtotal) {
                pointsError.textContent = `Cannot exceed order total (₱${orderSubtotal.toFixed(2)})`;
                pointsError.style.display = 'block';
                points = maxOrderPoints;
                input.value = maxOrderPoints.toFixed(2);
            } else {
                pointsError.style.display = 'none';
            }

            if (selectedMethod === 'Loyalty Points') {
                const required = maxOrderPoints;
                if (points < required) {
                    const lacking = roundMoney(required - points);
                    pointsError.textContent = `Insufficient points for full loyalty payment. Need ${formatPoints(lacking)} more points.`;
                    pointsError.style.display = 'block';
                }
            }

            pointsToRedeem = points;
            input.value = points.toFixed(2);

            const discount = roundMoney(pointsToRedeem);

            // Update totals using shared recalculation
            if (discount > 0) {
                document.getElementById('pointsDiscountRow').style.display = 'flex';
                document.getElementById('pointsDiscountAmount').textContent = '-₱' + discount.toFixed(2);
            } else {
                document.getElementById('pointsDiscountRow').style.display = 'none';
            }

            recalcCheckoutTotal();
        }

        function useMaxPoints() {
            const maxUsable = roundMoney(Math.min(userLoyaltyPoints, roundMoney(orderSubtotal)));
            document.getElementById('pointsToRedeem').value = maxUsable.toFixed(2);
            updatePointsDiscount();
        }

        <?php if ($isLoggedIn): ?>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('input[name="paymentMethod"]').forEach((radio) => {
                radio.addEventListener('change', syncPointsWithPaymentMethod);
            });
        });
        <?php endif; ?>
    </script>

    <!-- Reward QR Code Popup Modal -->
    <div id="rewardQrOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); z-index:20000; justify-content:center; align-items:center; padding:1rem;">
        <div style="background:var(--card-bg); border-radius:20px; width:100%; max-width:420px; padding:2rem; text-align:center; box-shadow:0 25px 50px rgba(0,0,0,0.25); animation:slideUp 0.3s ease;" onclick="event.stopPropagation()">
            <div style="font-size:4rem; margin-bottom:0.5rem;">🎉</div>
            <h2 style="color:var(--text-color); font-size:1.3rem; margin-bottom:0.5rem;">You Earned a Reward!</h2>
                    <p style="color:var(--text-light); font-size:0.9rem; margin-bottom:1rem;">Scan this QR code to earn <strong style="color:var(--primary-blue);">loyalty points (25 per ₱500 spent)</strong></p>
            <div id="rewardQrContainer" style="display:flex; justify-content:center; margin:1rem auto; background:white; padding:1rem; border-radius:12px; width:fit-content;"></div>
            <div id="rewardQrCodeText" style="font-size:0.75rem; color:var(--text-light); word-break:break-all; margin:0.5rem 0;"></div>
            <p style="font-size:0.78rem; color:var(--text-light); margin-bottom:1rem;">⏰ Valid for 30 days &bull; One-time use only<br>Go to <a href="loyalty_qr.php" style="color:var(--primary-blue); font-weight:700;">Loyalty & QR</a> to scan and collect your point!</p>
            <button onclick="closeRewardQrPopup()" style="width:100%; padding:0.85rem; background:var(--primary-blue,#2563eb); color:white; border:none; border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer; transition:all 0.2s;"><i class="fas fa-check"></i> Got it!</button>
        </div>
    </div>

    <script>
    // Reward QR popup functions
    function showRewardQrPopup(qrCode) {
        const overlay = document.getElementById('rewardQrOverlay');
        const container = document.getElementById('rewardQrContainer');
        const codeText = document.getElementById('rewardQrCodeText');
        
        if (!overlay || !container) return;
        
        container.innerHTML = '';
        codeText.textContent = qrCode;
        
        if (typeof QRCode !== 'undefined') {
            new QRCode(container, {
                text: qrCode,
                width: 200,
                height: 200,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            // Fallback: use QR code image API
            const img = document.createElement('img');
            img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(qrCode);
            img.alt = 'Reward QR Code';
            img.width = 200;
            img.height = 200;
            img.style.borderRadius = '4px';
            container.appendChild(img);
        }
        
        overlay.style.display = 'flex';
    }
    
    function closeRewardQrPopup() {
        document.getElementById('rewardQrOverlay').style.display = 'none';
    }
    </script>

    <!-- ═══════ Footer Modal Scripts ═══════ -->
    <script>
    function openFooterModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }
    function closeFooterModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
    // Close modal on overlay click
    document.querySelectorAll('.footer-modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.footer-modal-overlay.active').forEach(m => {
                m.classList.remove('active');
            });
            document.body.style.overflow = '';
        }
    });

    // FAQ accordion toggle
    function toggleFaq(el) {
        const answer = el.nextElementSibling;
        const isOpen = el.classList.contains('open');
        // Close all
        document.querySelectorAll('.faq-question').forEach(q => {
            q.classList.remove('open');
            q.nextElementSibling.classList.remove('open');
        });
        if (!isOpen) {
            el.classList.add('open');
            answer.classList.add('open');
        }
    }

    // Order status tracker (legacy footer modal - kept for guest users)
    function trackOrderStatus() {
        const input = document.getElementById('orderTrackInput');
        const resultDiv = document.getElementById('orderTrackResult');
        const ref = input.value.trim();
        if (!ref) {
            resultDiv.innerHTML = '<div style="padding:0.8rem;background:#fef3c7;border-radius:8px;color:#92400e;font-size:0.88rem;"><i class="fas fa-exclamation-triangle"></i> Please enter an order number or reference code.</div>';
            return;
        }
        resultDiv.innerHTML = '<div style="text-align:center;padding:1rem;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Looking up order...</div>';
        
        fetch('api_orders.php?action=track&ref=' + encodeURIComponent(ref))
            .then(r => r.json())
            .then(data => {
                if (data.success && data.order) {
                    const o = data.order;
                    const statusColors = {pending:'#f59e0b',processing:'#3b82f6',ready:'#10b981',completed:'#22c55e',cancelled:'#ef4444'};
                    const statusColor = statusColors[o.status?.toLowerCase()] || '#64748b';
                    resultDiv.innerHTML = `
                        <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:1rem;margin-top:0.5rem;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.6rem;">
                                <strong style="font-size:0.95rem;color:#0c4a6e;">${escapeHtml(o.reference || ref)}</strong>
                                <span style="background:${statusColor};color:white;padding:0.2rem 0.8rem;border-radius:20px;font-size:0.78rem;font-weight:600;">${escapeHtml(o.status || 'Unknown')}</span>
                            </div>
                            <div style="font-size:0.85rem;color:#475569;">
                                <div><strong>Date:</strong> ${escapeHtml(o.date || 'N/A')}</div>
                                <div><strong>Items:</strong> ${o.item_count || 'N/A'} item(s)</div>
                                <div><strong>Total:</strong> ₱${parseFloat(o.total || 0).toFixed(2)}</div>
                            </div>
                        </div>`;
                } else {
                    resultDiv.innerHTML = '<div style="padding:0.8rem;background:#fef2f2;border-radius:8px;color:#991b1b;font-size:0.88rem;"><i class="fas fa-times-circle"></i> No order found with that reference. Please double-check and try again.</div>';
                }
            })
            .catch(() => {
                resultDiv.innerHTML = '<div style="padding:0.8rem;background:#fef2f2;border-radius:8px;color:#991b1b;font-size:0.88rem;"><i class="fas fa-times-circle"></i> Unable to look up order at this time. Please try again later.</div>';
            });
    }

    // ═══════ MY ORDERS PANEL (Account-linked auto tracker) ═══════
    <?php if ($isLoggedIn): ?>
    let ordersLoaded = false;
    let ordersRefreshInterval = null;
    const ORDERS_REFRESH_MS = 5000;

    function toggleOrdersPanel() {
        const panel = document.getElementById('ordersPanel');
        const overlay = document.getElementById('ordersOverlay');
        if (!panel || !overlay) return;

        const isOpen = panel.classList.contains('open');
        if (isOpen) {
            panel.classList.remove('open');
            overlay.classList.remove('open');
            document.body.style.overflow = '';
            if (ordersRefreshInterval) {
                clearInterval(ordersRefreshInterval);
                ordersRefreshInterval = null;
            }
        } else {
            panel.classList.add('open');
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
            loadMyOrders();
            // Auto-refresh while panel is open
            if (ordersRefreshInterval) clearInterval(ordersRefreshInterval);
            ordersRefreshInterval = setInterval(loadMyOrders, ORDERS_REFRESH_MS);
        }
    }

    async function loadMyOrders() {
        const body = document.getElementById('ordersPanelBody');
        if (!body) return;

        // Only show loading spinner on first load
        if (!ordersLoaded) {
            body.innerHTML = '<div class="orders-loading"><i class="fas fa-spinner fa-spin" style="font-size:2rem;opacity:0.3;"></i><p style="margin-top:0.8rem;">Loading your orders...</p></div>';
        }

        try {
            const res = await fetch('api_orders.php?action=my_orders&_ts=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await res.json();

            if (!data.success) {
                body.innerHTML = '<div class="orders-empty"><i class="fas fa-exclamation-circle"></i><p>Unable to load orders</p><span>' + escapeHtml(data.message || 'Please try again') + '</span></div>';
                return;
            }

            ordersLoaded = true;

            if (!data.orders || data.orders.length === 0) {
                body.innerHTML = '<div class="orders-empty"><i class="fas fa-shopping-bag"></i><p>No orders yet</p><span>Your orders will appear here once you place one</span></div>';
                return;
            }

            body.innerHTML = data.orders.map(order => renderOrderCard(order)).join('');

        } catch (err) {
            console.error('Error loading orders:', err);
            if (!ordersLoaded) {
                body.innerHTML = '<div class="orders-empty"><i class="fas fa-wifi"></i><p>Connection error</p><span>Please check your internet and try again</span></div>';
            }
        }
    }

    function renderOrderCard(order) {
        const statusClass = (order.status || 'pending').toLowerCase();
        const statusSteps = ['Pending', 'Confirmed', 'Preparing', 'Ready', 'Completed'];
        const currentIndex = statusSteps.findIndex(s => s.toLowerCase() === statusClass);
        const isCancelled = statusClass === 'cancelled';

        // Build items list
        let itemsHtml = '';
        if (order.items && order.items.length > 0) {
            const maxShow = 3;
            const shownItems = order.items.slice(0, maxShow);
            itemsHtml = shownItems.map(item => 
                `<div class="item-line"><span>${escapeHtml(item.product_name)} x${item.quantity}</span><span>₱${parseFloat(item.subtotal).toFixed(2)}</span></div>`
            ).join('');
            if (order.items.length > maxShow) {
                itemsHtml += `<div class="item-line" style="color:var(--primary-blue);font-weight:600;"><span>+${order.items.length - maxShow} more item(s)</span><span></span></div>`;
            }
        } else {
            itemsHtml = `<div class="item-line"><span>${order.item_count || 0} item(s)</span><span></span></div>`;
        }

        // Build tracker
        let trackerHtml = '';
        if (isCancelled) {
            trackerHtml = `
                <div class="order-tracker" style="justify-content:center;">
                    <div class="tracker-step">
                        <div class="tracker-dot cancelled-dot"><i class="fas fa-times"></i></div>
                        <span class="tracker-label" style="color:#ef4444;">Cancelled</span>
                    </div>
                </div>`;
        } else {
            trackerHtml = '<div class="order-tracker">';
            statusSteps.forEach((step, idx) => {
                let dotClass = 'tracker-dot';
                let labelClass = 'tracker-label';
                let icon = '';
                if (idx < currentIndex) {
                    dotClass += ' done';
                    labelClass += ' done';
                    icon = '<i class="fas fa-check"></i>';
                } else if (idx === currentIndex) {
                    dotClass += ' active';
                    labelClass += ' active';
                    const icons = ['fa-clock', 'fa-thumbs-up', 'fa-mortar-pestle', 'fa-box-open', 'fa-check-double'];
                    icon = '<i class="fas ' + icons[idx] + '"></i>';
                } else {
                    icon = '<i class="fas fa-circle" style="font-size:0.4rem;"></i>';
                }
                trackerHtml += `
                    <div class="tracker-step">
                        <div class="${dotClass}">${icon}</div>
                        <span class="${labelClass}">${step}</span>
                    </div>`;
            });
            trackerHtml += '</div>';
        }

        // Date formatting
        const orderDate = order.created_at ? new Date(order.created_at) : null;
        const dateStr = orderDate ? orderDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';
        const timeStr = orderDate ? orderDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '';

        return `
            <div class="order-card">
                <div class="order-card-header">
                    <span class="order-card-ref">${escapeHtml(order.order_ref)}</span>
                    <span class="order-status-badge ${statusClass}">${escapeHtml(order.status)}</span>
                </div>
                ${trackerHtml}
                <div class="order-card-items">${itemsHtml}</div>
                <div class="order-card-footer">
                    <span class="order-card-total">₱${parseFloat(order.total_amount).toFixed(2)}</span>
                    <span class="order-card-date"><i class="far fa-calendar-alt"></i> ${dateStr} ${timeStr}</span>
                </div>
            </div>`;
    }

    // Refresh immediately when user returns to the tab so status updates feel realtime
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            const panel = document.getElementById('ordersPanel');
            if (panel && panel.classList.contains('open')) {
                loadMyOrders();
            }
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>
<?php
// ─── Helper: map category name → icon ───
function getCategoryEmoji($category) {
    $map = [
        'Pain Relief'   => '<i class="fas fa-pills" style="color:#ef4444"></i>',
        'Antibiotics'   => '<i class="fas fa-capsules" style="color:#3b82f6"></i>',
        'Vitamins'      => '<i class="fas fa-lemon" style="color:#f59e0b"></i>',
        'Cold & Flu'    => '<i class="fas fa-head-side-mask" style="color:#10b981"></i>',
        'Supplements'   => '<i class="fas fa-leaf" style="color:#8b5cf6"></i>',
        'Skin Care'     => '<i class="fas fa-hand-sparkles" style="color:#ec4899"></i>',
        'First Aid'     => '<i class="fas fa-kit-medical" style="color:#f97316"></i>',
        'Baby Care'     => '<i class="fas fa-baby" style="color:#06b6d4"></i>',
    ];
    return $map[$category] ?? '<i class="fas fa-prescription-bottle-medical" style="color:#64748b"></i>';
}
?>
