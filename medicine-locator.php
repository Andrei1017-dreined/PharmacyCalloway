<?php
require_once 'Security.php';
Security::initSession();
include 'db_connection.php';
require_once 'ImageHelper.php';

// Initialize variables
$search = '';
$category_filter = '';
$error_message = '';

// Handle search and filter
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['search'])) {
        $search = trim($_GET['search']);
    }
    if (isset($_GET['category'])) {
        $category_filter = trim($_GET['category']);
    }
}

try {
    // Build query — fetch all needed fields for locator + expiry
    $sql = "SELECT p.product_id, p.name, p.category, p.stock_quantity, p.price, p.expiry_date, p.location,
                   p.image_url, p.generic_name, p.brand_name, p.strength, p.dosage_form,
                   p.sell_by_piece, p.price_per_piece, p.requires_prescription
            FROM products p
            WHERE (p.is_active = 1 OR p.is_active IS NULL)";

    if (!empty($search)) {
        $search = '%' . $search . '%';
        $sql .= " AND (p.name LIKE ? OR p.category LIKE ? OR CAST(p.price AS CHAR) LIKE ? OR p.location LIKE ? OR p.generic_name LIKE ? OR p.brand_name LIKE ?)";
    }
    if (!empty($category_filter)) {
        $sql .= " AND p.category = ?";
    }

    $sql .= " ORDER BY p.expiry_date ASC, p.name ASC";

    // Get unique categories
    $categoriesQuery = "SELECT DISTINCT category FROM products WHERE (is_active = 1 OR is_active IS NULL) AND category IS NOT NULL AND category != '' ORDER BY category";
    $categoriesResult = $conn->query($categoriesQuery);
    $categories = [];
    if ($categoriesResult && $categoriesResult->num_rows > 0) {
        while ($row = $categoriesResult->fetch_assoc()) {
            $categories[] = $row['category'];
        }
    }

    // Prepare and execute
    $stmt = $conn->prepare($sql);
    if (!empty($search) && !empty($category_filter)) {
        $stmt->bind_param('sssssss', $search, $search, $search, $search, $search, $search, $category_filter);
    } else if (!empty($search)) {
        $stmt->bind_param('ssssss', $search, $search, $search, $search, $search, $search);
    } else if (!empty($category_filter)) {
        $stmt->bind_param('s', $category_filter);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    // Get expiry threshold from settings
    $expiryThreshold = 30;
    $s_stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'expiry_alert_days'");
    if ($s_stmt) {
        $s_stmt->execute();
        if ($s_res = $s_stmt->get_result()->fetch_assoc()) {
            $expiryThreshold = (int) $s_res['setting_value'];
        }
    }

    // Get low stock threshold from settings (consistent with inventory_api.php)
    $lowStockThreshold = 20;
    $ls_stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'low_stock_threshold'");
    if ($ls_stmt) {
        $ls_stmt->execute();
        if ($ls_res = $ls_stmt->get_result()->fetch_assoc()) {
            $lowStockThreshold = (int) $ls_res['setting_value'];
        }
    }

    // Combined statistics
    $totalMedicines = $result->num_rows;
    $uniqueCategories = count($categories);
    $lowStockCount = 0;
    $outOfStockCount = 0;
    $expiredCount = 0;
    $expiringSoonCount = 0;
    $validExpiryCount = 0;
    $today = new DateTime();

    $statsResult = $conn->query("SELECT
      COUNT(CASE WHEN stock_quantity = 0 THEN 1 END) as out_of_stock,
      COUNT(CASE WHEN stock_quantity > 0 AND stock_quantity <= {$lowStockThreshold} THEN 1 END) as low_stock,
      COUNT(CASE WHEN expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 1 END) as expired,
      COUNT(CASE WHEN expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL {$expiryThreshold} DAY) THEN 1 END) as expiring_soon,
      COUNT(CASE WHEN expiry_date IS NOT NULL AND expiry_date > DATE_ADD(CURDATE(), INTERVAL {$expiryThreshold} DAY) THEN 1 END) as valid_expiry
      FROM products WHERE is_active = 1");
    if ($statsResult && $statsResult->num_rows > 0) {
        $statsRow = $statsResult->fetch_assoc();
        $lowStockCount    = $statsRow['low_stock'];
        $outOfStockCount  = $statsRow['out_of_stock'];
        $expiredCount     = $statsRow['expired'];
        $expiringSoonCount = $statsRow['expiring_soon'];
        $validExpiryCount = $statsRow['valid_expiry'];
    }

    // Handle location update (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_location'])) {
        $product_id = filter_var($_POST['product_id'], FILTER_VALIDATE_INT);
        $new_location = trim($_POST['new_location']);
        if ($product_id === false || empty($new_location)) {
            echo json_encode(['error' => 'Invalid input data']);
            exit;
        }
        try {
            $updateStmt = $conn->prepare("UPDATE products SET location = ? WHERE product_id = ?");
            $updateStmt->bind_param('si', $new_location, $product_id);
            echo json_encode($updateStmt->execute() ? ['success' => true] : ['error' => 'Failed to update location']);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

$page_title = "Medicine Locator & Expiry Monitor";
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<script>
(function() {
  var theme = localStorage.getItem('calloway_theme') || 'light';
  document.documentElement.setAttribute('data-theme', theme);
})();
</script>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
<title>Medicine Locator &amp; Expiry Monitor &mdash; Calloway Pharmacy</title>
<link rel="stylesheet" href="design-system.css">
<link rel="stylesheet" href="styles.css">
<link rel="stylesheet" href="shared-polish.css">
<link rel="stylesheet" href="polish.css">
<link rel="stylesheet" href="responsive.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── Medicine Locator & Expiry Monitor Premium ─────────── */
*{box-sizing:border-box}
main { width:100%; max-width:1440px; margin:0 auto; padding:1.25rem 1.5rem 2rem; }

/* Page Header */
.page-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; animation:ds-fade-in .3s var(--ease-out) both; }
.page-header-left h1 { font-size:var(--text-2xl); font-weight:800; letter-spacing:-0.02em; color:var(--c-text); margin:0; line-height:1.2; display:flex; align-items:center; gap:.4rem; }
.page-header-left p { margin:.2rem 0 0; color:var(--c-text-muted); font-size:var(--text-sm); }
.page-header-right { display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; }

/* View Tabs */
.view-tabs { display:flex; gap:2px; padding:3px; border-radius:var(--radius-lg); background:var(--c-surface-sunken); border:1px solid var(--c-border); }
.view-tab { padding:.45rem 1rem; border:none; border-radius:var(--radius-md); cursor:pointer; font-weight:600; font-size:var(--text-sm); background:transparent; color:var(--c-text-muted); transition:all var(--duration-fast) var(--ease-out); font-family:var(--font-sans); display:inline-flex; align-items:center; gap:.3rem; }
.view-tab:hover { color:var(--c-text); }
.view-tab.active { background:var(--c-brand); color:white; box-shadow:var(--shadow-sm); }

/* Export Button */
.ml-export-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.5rem 1rem; font-size:var(--text-sm); font-weight:600; border-radius:var(--radius-md); border:1px solid var(--c-border); background:var(--c-surface); color:var(--c-text); cursor:pointer; transition:all var(--duration-fast) var(--ease-out); font-family:var(--font-sans); }
.ml-export-btn:hover { border-color:var(--c-brand); color:var(--c-brand); box-shadow:var(--shadow-sm); transform:translate3d(0,-1px,0); }
.ml-export-btn:active { transform:translate3d(0,0,0) scale(.97); }

/* Stats Grid */
.stats-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:.75rem; margin-bottom:1.5rem; }
.stat-card { background:var(--c-surface); border:1px solid var(--c-border); border-radius:var(--radius-lg); padding:1rem; display:flex; align-items:center; gap:.75rem; transition:transform var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out); will-change:transform; position:relative; overflow:hidden; animation:ds-fade-in .3s var(--ease-out) both; }
.stat-card:nth-child(1){animation-delay:.02s} .stat-card:nth-child(2){animation-delay:.04s} .stat-card:nth-child(3){animation-delay:.06s} .stat-card:nth-child(4){animation-delay:.08s} .stat-card:nth-child(5){animation-delay:.1s} .stat-card:nth-child(6){animation-delay:.12s} .stat-card:nth-child(7){animation-delay:.14s}
.stat-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; border-radius:0 3px 3px 0; opacity:0; transition:opacity var(--duration-fast) var(--ease-out); }
.stat-card:hover { transform:translate3d(0,-2px,0); box-shadow:var(--shadow-md); }
.stat-card:hover::before { opacity:1; }
.stat-card.c-blue::before{background:var(--c-brand)} .stat-card.c-green::before{background:#10b981} .stat-card.c-amber::before{background:#f59e0b} .stat-card.c-red::before{background:#ef4444}
.stat-icon { width:42px; height:42px; border-radius:var(--radius-lg); display:grid; place-items:center; font-size:1.1rem; flex-shrink:0; }
.stat-icon.blue{background:rgba(var(--c-brand-rgb),.1);color:var(--c-brand)} .stat-icon.green{background:rgba(16,185,129,.1);color:#10b981} .stat-icon.amber{background:rgba(245,158,11,.1);color:#f59e0b} .stat-icon.red{background:rgba(239,68,68,.1);color:#ef4444} .stat-icon.green-alt{background:rgba(16,185,129,.08);color:#059669} .stat-icon.amber-alt{background:rgba(245,158,11,.08);color:#d97706} .stat-icon.red-alt{background:rgba(239,68,68,.08);color:#dc2626}
.stat-body { min-width:0; }
.stat-val { font-size:var(--text-2xl); font-weight:800; line-height:1.1; color:var(--c-text); letter-spacing:-0.02em; }
.stat-card.c-blue .stat-val{color:var(--c-brand)} .stat-card.c-green .stat-val{color:#10b981} .stat-card.c-amber .stat-val{color:#f59e0b} .stat-card.c-red .stat-val{color:#ef4444}
.stat-label { font-size:.68rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--c-text-muted); margin-top:.1rem; }
.stat-sub { font-size:.62rem; color:var(--c-text-muted); font-weight:500; display:block; margin-top:.1rem; text-transform:none; letter-spacing:normal; }

/* Section Card */
.sec-card { background:var(--c-surface); border:1px solid var(--c-border); border-radius:var(--radius-xl); padding:1.25rem 1.5rem; margin-bottom:1.25rem; box-shadow:var(--shadow-sm); animation:ds-fade-in .35s .08s var(--ease-out) both; }
.sec-card h2 { color:var(--c-text); font-weight:700; margin:0 0 .85rem; font-size:var(--text-base); display:flex; align-items:center; gap:.45rem; }
.sec-card h2 i{color:var(--c-brand);font-size:.95rem}

/* Search & Filters */
.filter-row { display:flex; flex-wrap:wrap; gap:.65rem; align-items:center; }
.filter-row .search-wrap { flex:1; min-width:260px; position:relative; }
.filter-row .search-wrap input { width:100%; padding:.6rem .85rem .6rem 2.3rem; border:1.5px solid var(--c-border); border-radius:var(--radius-md); font-size:var(--text-sm); background:var(--c-surface); color:var(--c-text); font-family:var(--font-sans); transition:border-color var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out); }
.filter-row .search-wrap::before { content:"\f002"; font-family:"Font Awesome 6 Free"; font-weight:900; position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:var(--c-text-muted); font-size:.8rem; pointer-events:none; }
.filter-row .search-wrap input:focus { outline:none; border-color:var(--c-brand); box-shadow:0 0 0 3px rgba(var(--c-brand-rgb),.1); }
.filter-row select { padding:.6rem .85rem; border:1.5px solid var(--c-border); border-radius:var(--radius-md); font-size:var(--text-sm); background:var(--c-surface); color:var(--c-text); cursor:pointer; font-family:var(--font-sans); transition:border-color var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out); min-width:155px; }
.filter-row select:focus { outline:none; border-color:var(--c-brand); box-shadow:0 0 0 3px rgba(var(--c-brand-rgb),.1); }
.clear-btn { display:inline-flex; align-items:center; gap:.35rem; padding:.58rem 1rem; background:#ef4444; color:#fff; border:none; border-radius:var(--radius-md); font-size:var(--text-sm); font-weight:600; cursor:pointer; font-family:var(--font-sans); transition:all var(--duration-fast) var(--ease-out); }
.clear-btn:hover { background:#dc2626; transform:translate3d(0,-1px,0); box-shadow:var(--shadow-sm); }
.clear-btn:active { transform:scale(.97); }
.badge-pill { display:none; align-items:center; gap:.3rem; padding:.25rem .7rem; background:rgba(var(--c-brand-rgb),.1); color:var(--c-brand); border-radius:var(--radius-full); font-size:.73rem; font-weight:700; border:1px solid rgba(var(--c-brand-rgb),.2); }
.badge-pill.visible { display:inline-flex; }
.badge-pill .cnt { background:var(--c-brand); color:white; padding:.05rem .35rem; border-radius:var(--radius-full); font-size:.65rem; font-weight:800; }

/* Product Grid */
.product-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(310px,1fr)); gap:1rem; margin-top:.85rem; }

/* Product Card */
.prod-card { background:var(--c-surface); border-radius:var(--radius-xl); padding:0; border:1px solid var(--c-border); position:relative; overflow:hidden; transition:transform var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out),border-color var(--duration-fast) var(--ease-out); will-change:transform; animation:ds-fade-in .3s var(--ease-out) both; }
.prod-card:hover { transform:translate3d(0,-3px,0); box-shadow:var(--shadow-lg); }
.prod-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--c-brand); opacity:0; transition:opacity var(--duration-fast) var(--ease-out); }
.prod-card:hover::before { opacity:1; }
.prod-card.b-red{border-color:rgba(239,68,68,.3)} .prod-card.b-red::before{background:#ef4444;opacity:1}
.prod-card.b-amber{border-color:rgba(245,158,11,.3)} .prod-card.b-amber::before{background:#f59e0b;opacity:1}
.prod-card.b-green{border-color:rgba(16,185,129,.2)} .prod-card.b-green::before{background:#10b981;opacity:.6}
.prod-top { display:flex; align-items:flex-start; gap:.75rem; padding:1.15rem 1.15rem .5rem; }
.prod-img { width:54px; height:54px; border-radius:var(--radius-lg); object-fit:cover; border:1px solid var(--c-border); flex-shrink:0; background:var(--c-surface-sunken); }
.prod-img-placeholder { display:grid; place-items:center; color:var(--c-text-muted); font-size:1.2rem; opacity:.5; }
.prod-top-info { flex:1; min-width:0; }
.prod-name { font-size:1rem; font-weight:700; color:var(--c-text); margin:0; line-height:1.3; }
.prod-variant { font-size:.73rem; color:var(--c-text-muted); margin-top:1px; }
.rx-tag { display:inline-flex; align-items:center; gap:2px; background:rgba(230,81,0,.1); color:#e65100; font-size:.6rem; padding:1px 5px; border-radius:var(--radius-sm); font-weight:700; vertical-align:middle; margin-left:3px; }
[data-theme="dark"] .rx-tag{background:rgba(255,152,0,.15);color:#ff9800}
.cat-badge { display:inline-flex; align-items:center; gap:.25rem; padding:.18rem .55rem; font-size:.72rem; font-weight:600; border-radius:var(--radius-full); margin-top:.3rem; background:rgba(var(--c-brand-rgb),.07); color:var(--c-brand); border:1px solid rgba(var(--c-brand-rgb),.12); }
.cat-badge i{font-size:.6rem}
.prod-details { padding:.35rem 1.15rem 1.15rem; display:flex; flex-direction:column; gap:.45rem; }
.prod-price-row { display:flex; align-items:baseline; gap:.6rem; flex-wrap:wrap; }
.price-tag { font-size:1.2rem; font-weight:800; color:var(--c-brand); letter-spacing:-0.01em; }
.piece-price { font-size:.72rem; color:var(--c-brand); opacity:.75; font-weight:600; display:inline-flex; align-items:center; gap:.2rem; }
.piece-price i{font-size:.55rem}
.prod-meta-row { display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; }

/* Stock Pill */
.stock-pill { display:inline-flex; align-items:center; gap:.25rem; padding:.2rem .6rem; border-radius:var(--radius-full); font-size:.71rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.stock-pill i{font-size:.6rem}
.stock-pill.in-stock{background:rgba(16,185,129,.1);color:#10b981} .stock-pill.low-stock{background:rgba(245,158,11,.1);color:#d97706} .stock-pill.out-of-stock{background:rgba(239,68,68,.1);color:#ef4444}
[data-theme="dark"] .stock-pill.in-stock{background:rgba(16,185,129,.15);color:#34d399} [data-theme="dark"] .stock-pill.low-stock{background:rgba(245,158,11,.15);color:#fbbf24} [data-theme="dark"] .stock-pill.out-of-stock{background:rgba(239,68,68,.15);color:#f87171}

/* Expiry Row */
.expiry-row { display:flex; align-items:center; gap:.4rem; padding:.4rem .65rem; border-radius:var(--radius-md); font-size:.8rem; font-weight:600; border:1px solid var(--c-border-light); background:var(--c-surface-sunken); }
.expiry-row i{font-size:.75rem;flex-shrink:0}
.expiry-date{flex:1}
.expiry-row.e-expired{background:rgba(239,68,68,.05);border-color:rgba(239,68,68,.15);color:#ef4444}
.expiry-row.e-soon{background:rgba(245,158,11,.05);border-color:rgba(245,158,11,.15);color:#d97706}
.expiry-row.e-valid{background:rgba(16,185,129,.05);border-color:rgba(16,185,129,.15);color:#10b981}
.expiry-row.e-none{opacity:.5;font-weight:500}
[data-theme="dark"] .expiry-row.e-expired{background:rgba(239,68,68,.08);color:#f87171} [data-theme="dark"] .expiry-row.e-soon{background:rgba(245,158,11,.08);color:#fbbf24} [data-theme="dark"] .expiry-row.e-valid{background:rgba(16,185,129,.08);color:#34d399}

/* Status Pill (inline in expiry row) */
.status-pill { display:inline-flex; align-items:center; gap:.2rem; padding:.15rem .5rem; border-radius:var(--radius-full); font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; margin-left:auto; flex-shrink:0; }
.status-pill i{font-size:.55rem}
.status-pill.s-expired{background:rgba(239,68,68,.12);color:#ef4444} .status-pill.s-soon{background:rgba(245,158,11,.12);color:#d97706} .status-pill.s-valid{background:rgba(16,185,129,.12);color:#10b981}

/* Location Row */
.loc-row { display:flex; align-items:center; gap:.45rem; padding:.4rem .65rem; border-radius:var(--radius-md); background:rgba(var(--c-brand-rgb),.04); border:1px solid rgba(var(--c-brand-rgb),.08); transition:all var(--duration-fast) var(--ease-out); }
.loc-row:hover{background:rgba(var(--c-brand-rgb),.08);border-color:rgba(var(--c-brand-rgb),.15)}
.loc-icon{color:var(--c-brand);font-size:.8rem;flex-shrink:0}
.loc-text { flex:1; font-weight:600; color:var(--c-text); font-size:.82rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.edit-loc { width:28px; height:28px; border-radius:var(--radius-md); display:grid; place-items:center; border:1px solid var(--c-border); background:var(--c-surface); color:var(--c-brand); cursor:pointer; font-size:.7rem; transition:all var(--duration-fast) var(--ease-out); }
.edit-loc:hover{background:rgba(var(--c-brand-rgb),.08);border-color:var(--c-brand);transform:translate3d(0,-1px,0)}
.edit-loc:active{transform:scale(.92)}
.save-loc { width:28px; height:28px; border-radius:var(--radius-md); display:grid; place-items:center; border:none; background:var(--c-brand); color:white; cursor:pointer; font-size:.7rem; transition:all var(--duration-fast) var(--ease-out); }
.save-loc:hover{background:var(--c-brand-dark);transform:translate3d(0,-1px,0)}
.loc-input { flex:1; padding:.4rem .65rem; border:1.5px solid var(--c-border); border-radius:var(--radius-md); font-size:.82rem; font-weight:600; color:var(--c-text); background:var(--c-surface); font-family:var(--font-sans); transition:border-color var(--duration-fast) var(--ease-out); }
.loc-input:focus{outline:none;border-color:var(--c-brand);box-shadow:0 0 0 3px rgba(var(--c-brand-rgb),.1)}

/* No Results */
.no-results { grid-column:1/-1; text-align:center; padding:3rem 2rem; color:var(--c-text-muted); }
.no-results i{font-size:2.5rem;margin-bottom:.75rem;opacity:.4;display:block}
.no-results h3{font-size:var(--text-lg);margin:0 0 .3rem;color:var(--c-text-secondary);font-weight:700}
.no-results p{font-size:var(--text-sm);opacity:.6;margin:0}

/* Toast */
.ml-toast { position:fixed; top:72px; right:1rem; padding:.75rem 1.15rem; border-radius:var(--radius-lg); color:white; font-weight:600; font-size:var(--text-sm); z-index:10001; box-shadow:var(--shadow-lg); transform:translate3d(120%,0,0); transition:transform .3s var(--ease-spring); max-width:360px; display:flex; align-items:center; gap:.5rem; }
.ml-toast.show{transform:translate3d(0,0,0)} .ml-toast.success{background:#10b981} .ml-toast.error{background:#ef4444}

/* FAB */
.fab-top { position:fixed; bottom:1.5rem; right:1.5rem; width:42px; height:42px; border-radius:var(--radius-full); background:var(--c-brand); color:white; border:none; box-shadow:var(--shadow-lg); cursor:pointer; display:grid; place-items:center; font-size:.85rem; transition:all var(--duration-fast) var(--ease-out); z-index:100; opacity:0; pointer-events:none; transform:translate3d(0,8px,0); }
.fab-top.visible{opacity:1;pointer-events:auto;transform:translate3d(0,0,0)}
.fab-top:hover{transform:translate3d(0,-2px,0)!important;box-shadow:var(--shadow-xl)}
.fab-top:active{transform:scale(.92)!important}

/* Responsive */
@media(max-width:768px){
  main{padding:.75rem 1rem 1.5rem!important}
  .filter-row{flex-direction:column} .filter-row .search-wrap,.filter-row select{width:100%;min-width:unset}
  .product-grid{grid-template-columns:1fr}
  .stats-grid{grid-template-columns:repeat(2,1fr);gap:.5rem} .stat-card{padding:.85rem}
  .page-header{flex-direction:column;align-items:flex-start}
  .page-header-right{flex-direction:column;align-items:flex-start;width:100%} .view-tabs{width:100%;overflow-x:auto}
  .sec-card{padding:1rem;border-radius:var(--radius-lg)}
}
@media(max-width:480px){
  .stats-grid{grid-template-columns:1fr 1fr} .stat-icon{width:36px;height:36px;font-size:.95rem} .stat-val{font-size:var(--text-xl)}
  .prod-top{padding:1rem 1rem .4rem} .prod-details{padding:.3rem 1rem 1rem}
}

/* Print */
@media print{
  header,.topbar,.sidebar,.sidebar-overlay,.fab-top,.clear-btn,.filter-row,.view-tabs,.edit-loc,.page-header-right{display:none!important}
  main{margin:0!important;padding:0!important;max-width:100%!important}
  .prod-card{break-inside:avoid;page-break-inside:avoid;border:1px solid #ccc!important;box-shadow:none!important}
  .sec-card{box-shadow:none!important;border:1px solid #ddd!important}
}
</style>
</head>
<body>

<?php
$page_title = 'Medicine Locator & Expiry Monitor';
include 'header-component.php';
?>

<main>
  <?php if (!empty($error_message)): ?>
    <div style="padding:.85rem 1.15rem;background:rgba(239,68,68,.08);color:#ef4444;border-radius:var(--radius-lg);margin-bottom:1.25rem;border:1px solid rgba(239,68,68,.15);font-weight:600;font-size:var(--text-sm);display:flex;align-items:center;gap:.5rem;">
      <i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error_message) ?>
    </div>
  <?php endif; ?>

  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-left">
      <h1><i class="fas fa-pills" style="color:var(--c-brand);font-size:1.1rem"></i> Medicine Locator &amp; Expiry Monitor</h1>
      <p>Search medicines, check stock levels, and track expiry dates</p>
    </div>
    <div class="page-header-right">
      <div class="view-tabs">
        <button class="view-tab active" data-view="all" onclick="setView('all')"><i class="fas fa-border-all"></i> All</button>
        <button class="view-tab" data-view="expired" onclick="setView('expired')"><i class="fas fa-circle-xmark"></i> Expired</button>
        <button class="view-tab" data-view="expiring" onclick="setView('expiring')"><i class="fas fa-triangle-exclamation"></i> Expiring</button>
      </div>
      <button class="ml-export-btn" onclick="exportMedicineCSV()"><i class="fas fa-download"></i> Export</button>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card c-blue"><div class="stat-icon blue"><i class="fas fa-pills"></i></div><div class="stat-body"><div class="stat-val stat-num" data-target="<?= $totalMedicines ?>">0</div><div class="stat-label">Total Medicines</div></div></div>
    <div class="stat-card c-green"><div class="stat-icon green"><i class="fas fa-layer-group"></i></div><div class="stat-body"><div class="stat-val stat-num" data-target="<?= $uniqueCategories ?>">0</div><div class="stat-label">Categories</div></div></div>
    <div class="stat-card c-amber"><div class="stat-icon amber"><i class="fas fa-arrow-trend-down"></i></div><div class="stat-body"><div class="stat-val stat-num" data-target="<?= $lowStockCount ?>">0</div><div class="stat-label">Low Stock</div></div></div>
    <div class="stat-card c-red"><div class="stat-icon red"><i class="fas fa-box-open"></i></div><div class="stat-body"><div class="stat-val stat-num" data-target="<?= $outOfStockCount ?>">0</div><div class="stat-label">Out of Stock</div></div></div>
    <div class="stat-card c-red"><div class="stat-icon red-alt"><i class="fas fa-skull-crossbones"></i></div><div class="stat-body"><div class="stat-val stat-num" data-target="<?= $expiredCount ?>">0</div><div class="stat-label">Expired</div></div></div>
    <div class="stat-card c-amber"><div class="stat-icon amber-alt"><i class="fas fa-hourglass-half"></i></div><div class="stat-body"><div class="stat-val stat-num" data-target="<?= $expiringSoonCount ?>">0</div><div class="stat-label">Expiring Soon</div><small class="stat-sub"><?= $expiryThreshold ?>-day window</small></div></div>
    <div class="stat-card c-green"><div class="stat-icon green-alt"><i class="fas fa-circle-check"></i></div><div class="stat-body"><div class="stat-val stat-num" data-target="<?= $validExpiryCount ?>">0</div><div class="stat-label">Valid Stock</div></div></div>
  </div>

  <!-- Search & Filter -->
  <div class="sec-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem">
      <h2><i class="fas fa-magnifying-glass"></i> Search &amp; Filter</h2>
      <div class="badge-pill" id="filterBadge"><span class="cnt" id="filterCnt">0</span> filters active</div>
    </div>
    <div class="filter-row">
      <div class="search-wrap">
        <input type="text" id="searchInput" placeholder="Search by name, category, price, location…" autocomplete="off">
      </div>
      <select id="categoryFilter">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="statusFilter">
        <option value="">All Expiry Status</option>
        <option value="expired">Expired</option>
        <option value="expiring-soon">Expiring Soon</option>
        <option value="valid">Valid</option>
      </select>
      <button class="clear-btn" onclick="clearFilters()"><i class="fas fa-xmark"></i> Clear</button>
    </div>
  </div>

  <!-- Products -->
  <div class="sec-card">
    <h2><i class="fas fa-capsules"></i> Medicine Inventory <span style="font-weight:500;font-size:.8rem;color:var(--c-text-muted);margin-left:.5rem" id="visibleCount">(<?= $totalMedicines ?> items)</span></h2>
    <div class="product-grid" id="productGrid">
      <?php if ($result && $result->num_rows > 0):
        $idx = 0;
        while ($row = $result->fetch_assoc()):
          $row['image_url'] = resolveProductImageUrl((string)($row['image_url'] ?? ''), (string)($row['name'] ?? ''));
          $stockClass = $row['stock_quantity'] <= 0 ? 'out-of-stock' : ($row['stock_quantity'] < 10 ? 'low-stock' : 'in-stock');
          $stockText  = $row['stock_quantity'] <= 0 ? 'Out of Stock' : ($row['stock_quantity'] < 10 ? 'Low Stock' : 'In Stock');
          $expiryStatus = ''; $expiryClass = ''; $borderClass = ''; $daysDiff = null;
          if (!empty($row['expiry_date'])) {
              $expiryDate = new DateTime($row['expiry_date']);
              $daysDiff = (int) $today->diff($expiryDate)->format("%r%a");
              if ($daysDiff < 0) { $expiryStatus = 'expired'; $expiryClass = 'e-expired'; $borderClass = 'b-red'; }
              elseif ($daysDiff <= $expiryThreshold) { $expiryStatus = 'expiring-soon'; $expiryClass = 'e-soon'; $borderClass = 'b-amber'; }
              else { $expiryStatus = 'valid'; $expiryClass = 'e-valid'; $borderClass = 'b-green'; }
          }
          $variant = trim(($row['strength'] ?? '') . ' ' . ($row['dosage_form'] ?? ''));
          $stockIcon = $stockClass === 'in-stock' ? 'fa-circle-check' : ($stockClass === 'low-stock' ? 'fa-circle-exclamation' : 'fa-circle-xmark');
          $idx++;
      ?>
        <div class="prod-card <?= $borderClass ?>" data-category="<?= htmlspecialchars($row['category']) ?>" data-status="<?= $expiryStatus ?>" data-name="<?= htmlspecialchars(strtolower($row['name'])) ?>" data-location="<?= htmlspecialchars(strtolower($row['location'] ?: '')) ?>" data-price="<?= $row['price'] ?>" data-stock="<?= $row['stock_quantity'] ?>" style="animation-delay:<?= min($idx * 0.025, 0.5) ?>s">
          <div class="prod-top">
            <?php if (!empty($row['image_url'])): ?>
              <img class="prod-img" src="<?= htmlspecialchars($row['image_url']) ?>" alt="<?= htmlspecialchars($row['name']) ?>" loading="lazy">
            <?php else: ?>
              <div class="prod-img prod-img-placeholder"><i class="fas fa-capsules"></i></div>
            <?php endif; ?>
            <div class="prod-top-info">
              <h3 class="prod-name"><?= htmlspecialchars($row['name']) ?><?php if (!empty($row['requires_prescription'])): ?><span class="rx-tag">Rx</span><?php endif; ?></h3>
              <?php if ($variant): ?><div class="prod-variant"><?= htmlspecialchars($variant) ?></div><?php endif; ?>
              <div class="cat-badge"><i class="fas fa-tag"></i> <?= htmlspecialchars($row['category']) ?></div>
            </div>
          </div>
          <div class="prod-details">
            <div class="prod-price-row">
              <div class="price-tag">&#8369;<?= number_format($row['price'], 2) ?></div>
              <?php if (!empty($row['sell_by_piece']) && $row['price_per_piece'] > 0): ?>
                <div class="piece-price"><i class="fas fa-puzzle-piece"></i> &#8369;<?= number_format($row['price_per_piece'], 2) ?>/pc</div>
              <?php endif; ?>
            </div>
            <div class="prod-meta-row">
              <span class="stock-pill <?= $stockClass ?>"><i class="fas <?= $stockIcon ?>"></i> <?= $stockText ?> (<?= $row['stock_quantity'] ?>)</span>
            </div>
            <?php if (!empty($row['expiry_date'])): ?>
              <div class="expiry-row <?= $expiryClass ?>">
                <i class="fas fa-calendar-day"></i>
                <span class="expiry-date"><?= $expiryDate->format('M d, Y') ?></span>
                <span class="status-pill <?= $expiryStatus === 'expired' ? 's-expired' : ($expiryStatus === 'expiring-soon' ? 's-soon' : 's-valid') ?>">
                  <?php if ($expiryStatus === 'expired'): ?><i class="fas fa-circle-xmark"></i> <?= abs($daysDiff) ?>d ago<?php elseif ($expiryStatus === 'expiring-soon'): ?><i class="fas fa-triangle-exclamation"></i> <?= $daysDiff ?>d left<?php else: ?><i class="fas fa-circle-check"></i> <?= $daysDiff ?>d left<?php endif; ?>
                </span>
              </div>
            <?php else: ?>
              <div class="expiry-row e-none"><i class="fas fa-calendar-day"></i> No expiry set</div>
            <?php endif; ?>
            <div class="loc-row" data-product-id="<?= $row['product_id'] ?>">
              <i class="fas fa-location-dot loc-icon"></i>
              <span class="loc-text"><?= htmlspecialchars($row['location'] ?: 'Not set') ?></span>
              <button class="edit-loc" onclick="editLocation(<?= $row['product_id'] ?>)" title="Edit location"><i class="fas fa-pen"></i></button>
            </div>
          </div>
        </div>
      <?php endwhile; else: ?>
        <div class="no-results">
          <i class="fas fa-capsules"></i>
          <h3>No medicines found</h3>
          <p>Try adjusting your search criteria or check back later.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- Scroll-to-top -->
<button class="fab-top" id="fabTop" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top">
  <i class="fas fa-arrow-up"></i>
</button>

<?php include 'footer-component.php'; ?>
<?php if (file_exists('pills-background.php')) include 'pills-background.php'; ?>

<script src="theme.js"></script>
<script src="shared-polish.js"></script>
<script>
/* ── Medicine Locator Premium JS ────────────────────────── */
var currentSearch='', currentCategory='', currentStatus='', currentView='all';

/* ── Animated Counters ───────────────────────────────────── */
(function(){
  var els = document.querySelectorAll('.stat-num');
  els.forEach(function(el, i) {
    var target = parseInt(el.dataset.target) || 0;
    if (target === 0) return;
    var current = 0, step = Math.max(1, Math.ceil(target / 25));
    setTimeout(function() {
      var iv = setInterval(function() {
        current = Math.min(current + step, target);
        el.textContent = current;
        if (current >= target) clearInterval(iv);
      }, 25);
    }, i * 50);
  });
})();

/* ── View Tabs ───────────────────────────────────────────── */
function setView(v) {
  currentView = v;
  document.querySelectorAll('.view-tab').forEach(function(b){ b.classList.toggle('active', b.dataset.view === v); });
  var s = document.getElementById('statusFilter');
  if (v === 'expired') { s.value = 'expired'; currentStatus = 'expired'; }
  else if (v === 'expiring') { s.value = 'expiring-soon'; currentStatus = 'expiring-soon'; }
  else { s.value = ''; currentStatus = ''; }
  filterCards();
}

/* ── Filter ──────────────────────────────────────────────── */
function filterCards() {
  var cards = document.querySelectorAll('.prod-card');
  var vis = 0;
  cards.forEach(function(c) {
    var name = c.dataset.name || '';
    var cat = c.dataset.category || '';
    var st = c.dataset.status || '';
    var loc = c.dataset.location || '';
    var sm = !currentSearch || name.indexOf(currentSearch) !== -1 || cat.toLowerCase().indexOf(currentSearch) !== -1 || loc.indexOf(currentSearch) !== -1;
    var cm = !currentCategory || cat === currentCategory;
    var stm = !currentStatus || st === currentStatus;
    if (sm && cm && stm) { c.style.display = ''; vis++; }
    else { c.style.display = 'none'; }
  });
  handleEmpty(vis);
  updateBadge();
  var vc = document.getElementById('visibleCount');
  if (vc) vc.textContent = '(' + vis + ' item' + (vis !== 1 ? 's' : '') + ')';
}

function handleEmpty(vis) {
  var grid = document.getElementById('productGrid');
  var nr = grid.querySelector('.no-results-dyn');
  if (vis === 0) {
    if (!nr) { nr = document.createElement('div'); nr.className = 'no-results no-results-dyn'; nr.innerHTML = '<i class="fas fa-magnifying-glass"></i><h3>No matching medicines</h3><p>Try adjusting your filters.</p>'; grid.appendChild(nr); }
  } else if (nr) nr.remove();
}

function updateBadge() {
  var n = 0;
  if (currentSearch) n++;
  if (currentCategory) n++;
  if (currentStatus) n++;
  var b = document.getElementById('filterBadge');
  var c = document.getElementById('filterCnt');
  if (n) { b.classList.add('visible'); c.textContent = n; }
  else { b.classList.remove('visible'); }
}

function clearFilters() {
  document.getElementById('searchInput').value = '';
  document.getElementById('categoryFilter').value = '';
  document.getElementById('statusFilter').value = '';
  currentSearch = ''; currentCategory = ''; currentStatus = ''; currentView = 'all';
  document.querySelectorAll('.view-tab').forEach(function(b){ b.classList.toggle('active', b.dataset.view === 'all'); });
  filterCards();
  showToast('Filters cleared');
}

/* ── Events ──────────────────────────────────────────────── */
(function(){
  var si = document.getElementById('searchInput');
  var cf = document.getElementById('categoryFilter');
  var sf = document.getElementById('statusFilter');
  if (si) si.addEventListener('input', function(e){ currentSearch = e.target.value.toLowerCase(); filterCards(); });
  if (cf) cf.addEventListener('change', function(e){ currentCategory = e.target.value; filterCards(); });
  if (sf) sf.addEventListener('change', function(e){ currentStatus = e.target.value; filterCards(); });
  var fab = document.getElementById('fabTop');
  window.addEventListener('scroll', function(){ if(fab) fab.classList.toggle('visible', window.scrollY > 280); });
})();

/* ── Location Editing ────────────────────────────────────── */
function editLocation(pid) {
  var row = document.querySelector('.loc-row[data-product-id="'+pid+'"]');
  var cur = row.querySelector('.loc-text').textContent;
  var blank = cur === 'Not set';
  var inp = document.createElement('input');
  inp.type = 'text'; inp.value = blank ? '' : cur; inp.placeholder = 'e.g. Shelf A-3'; inp.className = 'loc-input';
  var btn = document.createElement('button');
  btn.className = 'save-loc';
  btn.innerHTML = '<i class="fas fa-check"></i>';
  btn.onclick = function(){ saveLocation(pid, inp.value); };
  row.innerHTML = '';
  var icon = document.createElement('i');
  icon.className = 'fas fa-location-dot loc-icon';
  row.appendChild(icon);
  row.appendChild(inp); row.appendChild(btn);
  inp.focus();
  inp.addEventListener('keydown', function(e){ if(e.key==='Enter') saveLocation(pid, inp.value); if(e.key==='Escape') location.reload(); });
}

function saveLocation(pid, val) {
  if (!val.trim()) { showToast('Location cannot be empty', 'error'); return; }
  var fd = new FormData();
  fd.append('update_location', '1');
  fd.append('product_id', pid);
  fd.append('new_location', val);
  fetch('medicine-locator.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.success) {
        var row = document.querySelector('.loc-row[data-product-id="'+pid+'"]');
        row.innerHTML = '<i class="fas fa-location-dot loc-icon"></i>'+
          '<span class="loc-text">'+val.replace(/</g,'&lt;')+'</span>'+
          '<button class="edit-loc" onclick="editLocation('+pid+')" title="Edit location"><i class="fas fa-pen"></i></button>';
        row.closest('.prod-card').dataset.location = val.toLowerCase();
        showToast('Location updated');
      } else { showToast(d.error || 'Update failed', 'error'); }
    })
    .catch(function(){ showToast('Error updating location', 'error'); });
}

/* ── Export CSV ──────────────────────────────────────────── */
function exportMedicineCSV() {
  var cards = document.querySelectorAll('.prod-card');
  if (!cards.length) { showToast('No medicines to export', 'error'); return; }
  var csv = 'Name,Category,Price,Stock,Expiry Status,Location\n';
  cards.forEach(function(c) {
    if (c.style.display === 'none') return;
    var name = (c.querySelector('.prod-name') || {}).textContent || '';
    name = name.replace(/Rx$/,'').trim();
    var cat = c.dataset.category || '';
    var price = c.dataset.price || '';
    var stock = c.dataset.stock || '';
    var status = c.dataset.status || 'N/A';
    var loc = (c.querySelector('.loc-text') || {}).textContent || '';
    csv += '"'+name+'","'+cat+'","'+price+'","'+stock+'","'+status+'","'+loc.trim()+'"\n';
  });
  var blob = new Blob([csv], {type:'text/csv'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'medicines_'+new Date().toISOString().slice(0,10)+'.csv';
  a.click(); URL.revokeObjectURL(a.href);
  showToast('Exported to CSV');
}

/* ── Toast ───────────────────────────────────────────────── */
function showToast(msg, type) {
  type = type || 'success';
  var t = document.createElement('div');
  t.className = 'ml-toast ' + type;
  t.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'circle-check' : 'circle-exclamation') + '"></i> ' + msg;
  document.body.appendChild(t);
  requestAnimationFrame(function(){ requestAnimationFrame(function(){ t.classList.add('show'); }); });
  setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 300); }, 3000);
}

/* ── Keyboard Shortcuts ──────────────────────────────────── */
document.addEventListener('keydown', function(e) {
  if ((e.ctrlKey && e.key === 'f') || e.key === 'F3') { e.preventDefault(); document.getElementById('searchInput').focus(); }
  if (e.key === 'Escape') {
    var s = document.getElementById('searchInput');
    if (s && s === document.activeElement) { s.value=''; s.blur(); currentSearch=''; filterCards(); }
  }
});
</script>
</body>
</html>
