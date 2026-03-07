<?php
/**
 * Inventory Management System
 * Full CRUD interface for managing products, stock, categories, and suppliers
 */

require_once 'db_connection.php';
require_once 'Auth.php';

$auth = new Auth($conn);
$auth->requireAuth('login.php');

if (!$auth->hasPermission('products.view')) {
    die('<h1>Access Denied</h1><p>You do not have permission to access inventory management.</p>');
}

$currentUser = $auth->getCurrentUser();
$page_title = 'Inventory Management';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
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
        .inventory-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 1.5rem 2rem;
        }

        .inventory-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            padding: 1.5rem;
            border-radius: 16px;
            border: 1px solid var(--input-border);
            box-shadow: var(--shadow-sm);
        }

        .inventory-header h1 {
            font-size: 1.5rem;
            margin: 0;
            color: var(--text-color);
            font-weight: 800;
        }

        .inventory-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.65rem 1.25rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.88rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary:hover {
            filter: brightness(0.92);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--bg-color);
            color: var(--text-color);
            border: 1px solid var(--input-border);
        }

        .btn-secondary:hover {
            background: var(--hover-bg);
            transform: translateY(-1px);
            border-color: var(--primary-color);
        }

        .search-filter-bar {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            padding: 1rem 1.25rem;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid var(--input-border);
            box-sizing: border-box;
        }

        [data-theme="dark"] .search-filter-bar {
            background: rgba(30, 41, 59, 0.7);
        }

        .search-group {
            flex: 1 1 150px;
            min-width: 0;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 2.9rem 0.75rem 1rem;
            border: 1px solid var(--input-border);
            border-radius: 12px;
            font-size: 0.85rem;
            background: var(--bg-color);
            transition: all 0.3s;
            color: var(--text-color);
            box-sizing: border-box;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.1);
        }

        .search-icon {
            position: absolute;
            right: 0.95rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            width: 1rem;
            text-align: center;
            line-height: 1;
            z-index: 1;
        }

        .filter-group select {
            padding: 0.75rem 1rem;
            border: 1px solid var(--input-border);
            border-radius: 12px;
            background: var(--bg-color);
            font-size: 0.85rem;
            cursor: pointer;
            color: var(--text-color);
            box-sizing: border-box;
        }

        .filter-group {
            flex: 0 1 auto;
            min-width: 0;
        }

        .products-grid {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            overflow-x: auto;
            border: 1px solid var(--input-border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 1.25rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid var(--divider-color);
        }

        th {
            background: var(--table-header-bg);
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }

        tr:hover {
            background: var(--hover-bg);
        }

        .status-badge {
            padding: 0.35rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .stock-high {
            background: rgba(16, 185, 129, 0.1);
            color: var(--secondary-color);
        }

        .stock-low {
            background: rgba(245, 158, 11, 0.1);
            color: var(--accent-color);
        }

        .stock-out {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .stock-editable {
            cursor: pointer;
        }

        .stock-inline-input {
            width: 90px;
            padding: 0.35rem 0.6rem;
            border-radius: 10px;
            border: 1px solid var(--input-border);
            background: var(--bg-color);
            color: var(--text-color);
            font-weight: 700;
        }

        /* Statistics Cards */
        .inventory-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 1.25rem;
            border-radius: 14px;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--input-border);
            transition: transform 0.15s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }

        .stat-content h3 {
            font-size: 1.5rem;
            margin: 0;
            color: var(--text-color);
            font-weight: 700;
        }

        .stat-content p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Toggle switch */
        .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: var(--input-border); border-radius: 24px; transition: 0.3s; }
        .toggle-slider::before { content: ""; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.3s; }
        .toggle-switch input:checked + .toggle-slider { background: var(--primary-color); }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

        /* Product thumbnail in table */
        .product-thumb { width: 36px; height: 36px; border-radius: 8px; object-fit: cover; background: var(--bg-color); border: 1px solid var(--input-border); }
        .product-thumb-placeholder { width: 36px; height: 36px; border-radius: 8px; background: var(--bg-color); border: 1px solid var(--input-border); display: flex; align-items: center; justify-content: center; color: var(--text-light); font-size: 0.7rem; }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
            animation: fadeIn 0.2s;
        }

        .modal-content {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 16px;
            width: 100%;
            max-width: 600px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--divider-color);
            padding-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--input-border);
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-light);
            cursor: pointer;
        }

        .loading {
            padding: 3rem;
            text-align: center;
            color: var(--text-light);
            font-style: italic;
        }

        .toast {
            position: fixed;
            top: 100px;
            bottom: auto;
            right: 2rem;
            left: auto;
            background: var(--card-bg);
            color: var(--text-color);
            padding: 1rem 1.5rem;
            border-radius: 12px;
            border: 1px solid var(--input-border);
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 10000;
            border-left: 4px solid var(--primary-color);
            max-width: min(420px, calc(100vw - 4rem));
            width: max-content;
            height: auto;
            line-height: 1.35;
        }

        .toast.success {
            border-left-color: var(--secondary-color);
        }

        .toast.error {
            border-left-color: var(--danger-color);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .inventory-container { padding: 1rem; }
            .inventory-header { flex-direction: column; align-items: flex-start; padding: 1rem; }
            .inventory-header h1 { font-size: 1.25rem; }
            .inventory-actions { width: 100%; }
            .inventory-actions .btn { flex: 1; justify-content: center; font-size: 0.82rem; padding: 0.6rem 0.75rem; }
            .inventory-stats { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .stat-card { padding: 1rem; }
            .stat-icon { font-size: 1.8rem; }
            .stat-content h3 { font-size: 1.25rem; }
            .search-filter-bar { flex-direction: column; gap: 0.5rem; }
            .search-group { flex: 1 1 100%; }
            .filter-group { width: 100%; }
            .filter-group select { width: 100%; }
            th, td { padding: 0.75rem 0.6rem; font-size: 0.82rem; }
            .modal-content { margin: 1rem; max-width: calc(100% - 2rem); border-radius: 14px; }
        }

        @media (max-width: 480px) {
            .inventory-stats { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .stat-card { padding: 0.75rem; gap: 0.5rem; }
            .stat-icon { font-size: 1.5rem; }
        }
    </style>
</head>

<body>
    <?php include 'header-component.php'; ?>

    <main class="inventory-container">
        <!-- Header -->
        <div class="inventory-header fade-in">
            <h1><i class="fas fa-boxes"
                    style="-webkit-text-fill-color: var(--primary-color); margin-right: 0.5rem;"></i> Inventory
                Management</h1>
            <div class="inventory-actions">
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Product
                </button>
                <button class="btn btn-secondary" onclick="exportInventory()">
                    <i class="fas fa-file-export"></i> Export Report
                </button>
                <button class="btn btn-secondary" onclick="openActivityLogs()" style="background:#7c3aed;color:#fff;border-color:#7c3aed;">
                    <i class="fas fa-clock-rotate-left"></i> Activity Log
                </button>
            </div>
        </div>

        <!-- Statistics -->
        <div class="inventory-stats slide-in-bottom">
            <div class="stat-card">
                <div class="stat-icon">📦</div>
                <div class="stat-content">
                    <h3 id="totalProducts">0</h3>
                    <p>Total Products</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-content">
                    <h3 id="totalStock">0</h3>
                    <p>Total Stock Units</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⚠️</div>
                <div class="stat-content">
                    <h3 id="lowStock">0</h3>
                    <p>Low Stock Items</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div class="stat-content">
                    <h3 id="expiringSoon">0</h3>
                    <p>Expiring Soon</p>
                </div>
            </div>
        </div>

        <!-- Search & Filter -->
        <div class="search-filter-bar fade-in" style="animation-delay: 0.1s;">
            <div class="search-group">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input"
                    placeholder="Search products by name, SKU, or category..." oninput="searchProducts()">
            </div>
            <div class="filter-group">
                <select id="categoryFilter" onchange="filterProducts()">
                    <option value="">All Categories</option>
                </select>
            </div>
            <div class="filter-group">
                <select id="stockFilter" onchange="filterProducts()">
                    <option value="">All Stock Levels</option>
                    <option value="high">In Stock</option>
                    <option value="low">Low Stock</option>
                    <option value="out">Out of Stock</option>
                </select>
            </div>
            <button class="btn btn-secondary" onclick="resetFilters()">Clear</button>
        </div>

        <!-- Products Table -->
        <div class="products-grid fade-in" style="animation-delay: 0.2s;">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px;"></th>
                        <th>Product Name</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Stock</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="productsTableBody">
                    <tr>
                        <td colspan="8" class="loading"><i class="fas fa-spinner fa-spin"></i> Loading products...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Add/Edit Modal -->
    <div class="modal" id="productModal">
        <div class="modal-content" style="max-width: 720px;">
            <div class="modal-header">
                <h2 id="modalTitle">Add Product</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form id="productForm" onsubmit="saveProduct(event)">
                <input type="hidden" id="productId">

                <!-- Image Upload -->
                <div class="form-group" style="text-align:center; margin-bottom:1.5rem;">
                    <div id="imagePreviewWrap" style="width:120px; height:120px; margin:0 auto 0.75rem; border-radius:12px; border:2px dashed var(--input-border); display:flex; align-items:center; justify-content:center; overflow:hidden; cursor:pointer; background:var(--bg-color);" onclick="document.getElementById('productImage').click()">
                        <img id="imagePreview" src="" style="max-width:100%; max-height:100%; display:none; object-fit:cover;">
                        <span id="imagePlaceholder" style="color:var(--text-light); font-size:0.85rem;"><i class="fas fa-camera" style="font-size:1.5rem; display:block; margin-bottom:0.3rem;"></i>Add Photo</span>
                    </div>
                    <input type="file" id="productImage" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none" onchange="previewImage(this)">
                    <small style="color:var(--text-light);">Click to upload (JPG, PNG, WebP — max 5MB)</small>
                </div>

                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" id="productName" required placeholder="e.g. Biogesic 500mg Tablet">
                </div>

                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label>Generic Name</label>
                        <input type="text" id="productGenericName" placeholder="e.g. Paracetamol">
                    </div>
                    <div>
                        <label>Brand Name</label>
                        <input type="text" id="productBrandName" placeholder="e.g. Biogesic (Unilab)">
                    </div>
                </div>

                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                    <div>
                        <label>Dosage Form</label>
                        <select id="productDosageForm">
                            <option value="">Select...</option>
                            <option value="Tablet">Tablet</option>
                            <option value="Caplet">Caplet</option>
                            <option value="Capsule">Capsule</option>
                            <option value="Softgel">Softgel</option>
                            <option value="Chewable">Chewable</option>
                            <option value="Syrup">Syrup</option>
                            <option value="Drops">Drops</option>
                            <option value="Suspension">Suspension</option>
                            <option value="Cream">Cream</option>
                            <option value="Ointment">Ointment</option>
                            <option value="Gel">Gel</option>
                            <option value="Solution">Solution</option>
                            <option value="Nebule">Nebule</option>
                            <option value="Powder">Powder</option>
                            <option value="Oil">Oil</option>
                            <option value="Wash">Wash</option>
                            <option value="Bandage">Bandage</option>
                            <option value="Inhaler">Inhaler</option>
                        </select>
                    </div>
                    <div>
                        <label>Strength *</label>
                        <input type="text" id="productStrength" placeholder="e.g. 500mg" required>
                    </div>
                    <div>
                        <label>Age Group</label>
                        <select id="productAgeGroup">
                            <option value="all">All Ages</option>
                            <option value="adult">Adult</option>
                            <option value="pediatric">Pediatric</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label>SKU</label>
                        <input type="text" id="productSKU">
                    </div>
                    <div>
                        <label>Barcode</label>
                        <input type="text" id="productBarcode">
                    </div>
                </div>
                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label>Category *</label>
                        <select id="productCategory" required></select>
                    </div>
                    <div>
                        <label>Supplier</label>
                        <select id="productSupplier"></select>
                    </div>
                </div>
                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label>Selling Price (per box/bottle) *</label>
                        <input type="number" id="productPrice" step="0.01" required>
                    </div>
                    <div>
                        <label>Cost Price *</label>
                        <input type="number" id="productCost" step="0.01" required>
                    </div>
                </div>

                <!-- Per-Piece Selling Section -->
                <div class="form-group" style="background: var(--bg-color); padding: 1rem; border-radius: 12px; border: 1px solid var(--input-border);">
                    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.75rem;">
                        <label style="margin:0; font-weight:700; color:var(--primary-color);"><i class="fas fa-pills"></i> Per-Piece / Per-Tablet Selling</label>
                        <label class="toggle-switch" style="margin-left:auto;">
                            <input type="checkbox" id="productSellByPiece" onchange="togglePieceFields()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div id="pieceFields" style="display:none; gap:1rem; grid-template-columns:1fr 1fr;">
                        <div>
                            <label>Pieces Per Box</label>
                            <input type="number" id="productPiecesPerBox" placeholder="e.g. 100" min="1">
                        </div>
                        <div>
                            <label>Price Per Piece (₱)</label>
                            <input type="number" id="productPricePerPiece" step="0.01" placeholder="e.g. 2.50">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="background: #fff3e0; padding: 1rem; border-radius: 12px; border: 1px solid #ffe0b2;">
                    <div style="display:flex; align-items:center; gap:0.75rem;">
                        <label style="margin:0; font-weight:700; color:#e65100;"><i class="fas fa-prescription"></i> Requires Prescription (Rx)</label>
                        <label class="toggle-switch" style="margin-left:auto;">
                            <input type="checkbox" id="productRequiresPrescription">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label>Stock Quantity *</label>
                        <input type="number" id="productStock" required>
                    </div>
                    <div>
                        <label>Reorder Level</label>
                        <input type="number" id="productReorder" value="10">
                    </div>
                </div>
                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label>Expiry Date</label>
                        <input type="date" id="productExpiry">
                    </div>
                    <div>
                        <label>Location</label>
                        <input type="text" id="productLocation" placeholder="e.g. Shelf A1">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="productDescription" rows="3"></textarea>
                </div>
                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">Save Product</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()"
                        style="flex: 1;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activity Log Modal -->
    <div id="activityLogModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:none;align-items:center;justify-content:center;">
        <div style="background:var(--card-bg,#fff);border-radius:18px;max-width:720px;width:95%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 16px 48px rgba(0,0,0,0.22);overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.3rem;border-bottom:1px solid rgba(0,0,0,0.08);">
                <h3 style="margin:0;font-size:1.1rem;"><i class="fas fa-clock-rotate-left" style="color:#7c3aed;margin-right:0.4rem;"></i>Inventory Activity Log</h3>
                <button onclick="closeActivityLogs()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-light);">&times;</button>
            </div>
            <div id="activityLogBody" style="padding:0.5rem 1rem 1rem;overflow-y:auto;flex:1;">
                <div style="text-align:center;padding:2rem;color:var(--text-light);">Loading...</div>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script src="theme.js"></script>
    <script src="shared-polish.js"></script>
    <script>
        let allProducts = [];
        let categories = [];
        let filteredProducts = [];

        document.addEventListener('DOMContentLoaded', function () {
            loadProducts();
            loadCategories();
            loadSuppliers();
            loadStats();

            attachInlineStockEditing();
        });

        function attachInlineStockEditing() {
            const tbody = document.getElementById('productsTableBody');
            if (!tbody) return;

            tbody.addEventListener('dblclick', (e) => {
                const badge = e.target.closest('.stock-editable');
                if (!badge) return;

                const productId = parseInt(badge.dataset.productId || '0', 10);
                if (!productId) return;

                beginInlineStockEdit(badge, productId);
            });
        }

        function beginInlineStockEdit(badgeEl, productId) {
            if (!badgeEl || badgeEl.dataset.editing === '1') return;
            badgeEl.dataset.editing = '1';

            const originalText = (badgeEl.textContent || '').trim();
            const originalStock = parseInt(originalText || '0', 10);

            const input = document.createElement('input');
            input.type = 'number';
            input.min = '0';
            input.step = '1';
            input.value = Number.isFinite(originalStock) ? String(originalStock) : '0';
            input.className = 'stock-inline-input';

            const parent = badgeEl.parentElement;
            parent.replaceChild(input, badgeEl);

            let finished = false;

            const restore = () => {
                parent.replaceChild(badgeEl, input);
                delete badgeEl.dataset.editing;
            };

            const cancel = () => {
                if (finished) return;
                finished = true;
                restore();
            };

            const commit = async () => {
                if (finished) return;
                finished = true;

                const newStock = parseInt((input.value || '').trim(), 10);
                if (!Number.isFinite(newStock) || newStock < 0) {
                    showToast('Invalid stock quantity', 'error');
                    restore();
                    return;
                }

                if (newStock === originalStock) {
                    restore();
                    return;
                }

                input.disabled = true;
                try {
                    const result = await setProductStock(productId, newStock);
                    if (!result.success) {
                        showToast(result.message || 'Failed to update stock', 'error');
                        restore();
                        return;
                    }

                    const updatedStock = result.new_stock ?? newStock;
                    const p = allProducts.find(x => parseInt(x.product_id, 10) === productId);
                    if (p) p.stock_quantity = updatedStock;

                    badgeEl.textContent = String(updatedStock);
                    badgeEl.dataset.currentStock = String(updatedStock);

                    restore();

                    applyFilters();
                    updateStatsDisplay();
                    loadStats();
                    showToast('Stock updated', 'success');

                } catch (err) {
                    console.error(err);
                    showToast('Server error while updating stock', 'error');
                    restore();
                }
            };

            input.addEventListener('keydown', (ev) => {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    commit();
                } else if (ev.key === 'Escape') {
                    ev.preventDefault();
                    cancel();
                }
            });
            input.addEventListener('blur', () => commit());

            setTimeout(() => {
                input.focus();
                input.select();
            }, 0);
        }

        async function setProductStock(productId, newStock) {
            // Prefer stock_movement (audited). If permission is missing, fallback to update_product.
            const movementPayload = {
                product_id: productId,
                movement_type: 'ADJUSTMENT',
                quantity: newStock,
                reference_type: 'inline_edit',
                notes: 'Inline stock edit'
            };

            const moveRes = await fetch('inventory_api.php?action=stock_movement', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(movementPayload)
            });

            if (moveRes.ok) {
                const moveData = await moveRes.json();
                return {
                    success: !!moveData.success,
                    message: moveData.message,
                    new_stock: moveData.new_stock
                };
            }

            // If forbidden, attempt update_product (persists stock without movement logging)
            if (moveRes.status !== 403) {
                let msg = 'Failed to update stock';
                try {
                    const err = await moveRes.json();
                    msg = err.message || msg;
                } catch (_) {}
                return { success: false, message: msg };
            }

            const p = allProducts.find(x => parseInt(x.product_id, 10) === productId);
            if (!p) return { success: false, message: 'Product not found in list' };

            const updatePayload = {
                product_id: productId,
                name: p.name || '',
                sku: p.sku || '',
                barcode: p.barcode || '',
                category_id: p.category_id || '',
                supplier_id: p.supplier_id || '',
                selling_price: p.selling_price || 0,
                cost_price: p.cost_price || 0,
                stock_quantity: newStock,
                reorder_level: p.reorder_level || 10,
                expiry_date: p.expiry_date || null,
                location: p.location || '',
                description: p.description || '',
                generic_name: p.generic_name || '',
                brand_name: p.brand_name || '',
                dosage_form: p.dosage_form || '',
                strength: p.strength || '',
                age_group: p.age_group || 'all',
                pieces_per_box: p.pieces_per_box || 0,
                price_per_piece: p.price_per_piece || 0,
                sell_by_piece: p.sell_by_piece || 0
            };

            const updRes = await fetch('inventory_api.php?action=update_product', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(updatePayload)
            });

            const updData = await updRes.json();
            return {
                success: !!updData.success,
                message: updData.message,
                new_stock: newStock
            };
        }

        async function loadProducts() {
            try {
                const response = await fetch('inventory_api.php?action=get_products&limit=1000');
                const data = await response.json();

                if (data.success) {
                    allProducts = data.data;
                    filteredProducts = allProducts;
                    renderProducts(allProducts);
                    updateStatsDisplay();
                } else {
                    showToast(data.message || 'Failed to load products', 'error');
                }
            } catch (error) {
                console.error('Error loading products:', error);
                document.getElementById('productsTableBody').innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--danger-color);">Error loading data. Is the database running?</td></tr>';
            }
        }

        async function loadCategories() {
            try {
                const response = await fetch('inventory_api.php?action=get_categories');
                const data = await response.json();
                if (data.success) {
                    categories = data.data;
                    const catFilter = document.getElementById('categoryFilter');
                    const catSelect = document.getElementById('productCategory');

                    // Clear existing (except first)
                    while (catFilter.options.length > 1) catFilter.remove(1);
                    catSelect.innerHTML = '<option value="">Select Category</option>';

                    categories.forEach(cat => {
                        const opt1 = new Option(cat.category_name, cat.category_name);
                        catFilter.add(opt1);
                        const opt2 = new Option(cat.category_name, cat.category_id);
                        catSelect.add(opt2);
                    });
                }
            } catch (e) { console.error(e); }
        }

        async function loadSuppliers() {
            try {
                const response = await fetch('inventory_api.php?action=get_suppliers');
                const data = await response.json();
                if (data.success) {
                    const supSelect = document.getElementById('productSupplier');
                    supSelect.innerHTML = '<option value="">Select Supplier</option>';
                    data.data.forEach(s => {
                        supSelect.add(new Option(s.supplier_name, s.supplier_id));
                    });
                }
            } catch (e) { console.error(e); }
        }

        async function loadStats() {
            try {
                const [lowStockRes, expiringRes] = await Promise.all([
                    fetch('inventory_api.php?action=low_stock_alert'),
                    fetch('inventory_api.php?action=expiring_products')
                ]);
                const low = await lowStockRes.json();
                const exp = await expiringRes.json();

                if (low.success) document.getElementById('lowStock').textContent = low.count || 0;
                if (exp.success) document.getElementById('expiringSoon').textContent = exp.expiring_soon_count ?? exp.count ?? 0;

            } catch (e) { console.error(e); }
        }

        function updateStatsDisplay() {
            document.getElementById('totalProducts').textContent = allProducts.length;
            const totalStock = allProducts.reduce((sum, p) => sum + parseInt(p.stock_quantity || 0), 0);
            document.getElementById('totalStock').textContent = totalStock.toLocaleString();
        }

        function renderProducts(products) {
            const tbody = document.getElementById('productsTableBody');
            if (products.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 2rem;">No products found</td></tr>';
                return;
            }

            tbody.innerHTML = products.map(product => {
                const stock = parseInt(product.stock_quantity || 0);
                let stockClass = 'stock-high';
                let stockLabel = 'In Stock';

                if (stock <= 0) { stockClass = 'stock-out'; stockLabel = 'Out of Stock'; }
                else if (stock <= 20) { stockClass = 'stock-low'; stockLabel = 'Low Stock'; }

                const imgHtml = product.image_url
                    ? `<img src="${escapeHtml(product.image_url)}" class="product-thumb" alt="">`
                    : `<div class="product-thumb-placeholder"><i class="fas fa-pills"></i></div>`;

                const variant = [product.strength, product.dosage_form].filter(Boolean).join(' ');
                const pieceInfo = parseInt(product.sell_by_piece) === 1
                    ? `<br><small style="color:var(--primary-color);">₱${parseFloat(product.price_per_piece).toFixed(2)}/pc</small>`
                    : '';

                return `<tr>
                    <td>${imgHtml}</td>
                    <td><strong>${escapeHtml(product.name)}</strong>${parseInt(product.requires_prescription) === 1 ? ' <span style="background:#e65100;color:#fff;font-size:0.65rem;padding:2px 6px;border-radius:4px;font-weight:700;vertical-align:middle;">Rx</span>' : ''}${variant ? '<br><small style="color:var(--text-light);">' + escapeHtml(variant) + '</small>' : ''}</td>
                    <td style="font-family: monospace; color: var(--text-light); font-size:0.8rem;">${escapeHtml(product.sku || '-')}</td>
                    <td>${escapeHtml(product.category_name || '-')}</td>
                    <td><span class="stock-badge ${stockClass} stock-editable" data-product-id="${product.product_id}" data-current-stock="${stock}" title="Double-click to edit stock">${stock}</span></td>
                    <td style="font-weight: 700;">₱${parseFloat(product.selling_price).toFixed(2)}${pieceInfo}</td>
                    <td><span class="status-badge ${stockClass}">${stockLabel}</span></td>
                    <td>
                        <button class="btn btn-secondary" style="padding: 0.5rem; border-radius: 50%;" onclick="editProduct(${product.product_id})" title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-secondary" style="padding: 0.5rem; border-radius: 50%; margin-left: 0.35rem; border-color: var(--danger-color); color: var(--danger-color);" onclick="deleteProduct(${product.product_id})" title="Delete"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
            }).join('');
        }

        async function deleteProduct(id) {
            const p = allProducts.find(x => x.product_id == id);
            const name = p?.name ? `\n\n${p.name}` : '';
            const ok = await customConfirm('Delete Product', `Delete this medicine/product?\n\n${p?.name || ''}\n\nThis will hide it from inventory (soft delete).`, 'danger', { confirmText: 'Yes, Delete', cancelText: 'Cancel' });
            if (!ok) {
                return;
            }

            try {
                const res = await fetch(`inventory_api.php?action=delete_product&id=${encodeURIComponent(id)}`);
                const d = await res.json();
                if (d.success) {
                    showToast('Product deleted', 'success');
                    loadProducts();
                    loadStats();
                } else {
                    showToast(d.message || 'Failed to delete product', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Server error', 'error');
            }
        }

        function searchProducts() {
            const term = document.getElementById('searchInput').value.toLowerCase();
            filteredProducts = allProducts.filter(p =>
                p.name.toLowerCase().includes(term) ||
                (p.sku && p.sku.toLowerCase().includes(term)) ||
                (p.category_name && p.category_name.toLowerCase().includes(term))
            );
            applyFilters();
        }

        function filterProducts() {
            applyFilters();
        }

        function applyFilters() {
            const cat = document.getElementById('categoryFilter').value;
            const stock = document.getElementById('stockFilter').value;

            let res = filteredProducts; // Start from search results

            if (cat) res = res.filter(p => p.category_name === cat);

            if (stock === 'high') res = res.filter(p => p.stock_quantity > 20);
            else if (stock === 'low') res = res.filter(p => p.stock_quantity > 0 && p.stock_quantity <= 20);
            else if (stock === 'out') res = res.filter(p => p.stock_quantity <= 0);

            renderProducts(res);
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('categoryFilter').value = '';
            document.getElementById('stockFilter').value = '';
            filteredProducts = allProducts;
            renderProducts(allProducts);
        }

        // Helpers
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }

        function togglePieceFields() {
            const on = document.getElementById('productSellByPiece').checked;
            document.getElementById('pieceFields').style.display = on ? 'grid' : 'none';
        }

        // Add/Edit Modal Logic
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Product';
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
            document.getElementById('imagePreview').style.display = 'none';
            document.getElementById('pieceFields').style.display = 'none';
            document.getElementById('productModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('productModal').classList.remove('active');
        }

        function editProduct(id) {
            const p = allProducts.find(x => x.product_id == id);
            if (!p) return;

            document.getElementById('modalTitle').textContent = 'Edit Product';
            document.getElementById('productId').value = p.product_id;
            document.getElementById('productName').value = p.name;
            document.getElementById('productSKU').value = p.sku || '';
            document.getElementById('productBarcode').value = p.barcode || '';
            document.getElementById('productCategory').value = p.category_id || '';
            document.getElementById('productSupplier').value = p.supplier_id || '';
            document.getElementById('productPrice').value = p.selling_price;
            document.getElementById('productCost').value = p.cost_price;
            document.getElementById('productStock').value = p.stock_quantity;
            document.getElementById('productReorder').value = p.reorder_level;
            document.getElementById('productExpiry').value = p.expiry_date || '';
            document.getElementById('productLocation').value = p.location || '';
            document.getElementById('productDescription').value = p.description || '';

            // Variant fields
            document.getElementById('productGenericName').value = p.generic_name || '';
            document.getElementById('productBrandName').value = p.brand_name || '';
            document.getElementById('productDosageForm').value = p.dosage_form || '';
            document.getElementById('productStrength').value = p.strength || '';
            document.getElementById('productAgeGroup').value = p.age_group || 'all';

            // Per-piece fields
            const sellByPiece = parseInt(p.sell_by_piece) === 1;
            document.getElementById('productSellByPiece').checked = sellByPiece;
            document.getElementById('pieceFields').style.display = sellByPiece ? 'grid' : 'none';
            document.getElementById('productPiecesPerBox').value = p.pieces_per_box || '';
            document.getElementById('productPricePerPiece').value = p.price_per_piece || '';

            // Prescription field
            document.getElementById('productRequiresPrescription').checked = parseInt(p.requires_prescription) === 1;

            // Image preview
            const preview = document.getElementById('imagePreview');
            if (p.image_url) {
                preview.src = p.image_url;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }

            document.getElementById('productModal').classList.add('active');
        }

        async function saveProduct(e) {
            e.preventDefault();
            const id = document.getElementById('productId').value;
            const action = id ? 'update_product' : 'add_product';

            // Require image for new products
            const imageInput = document.getElementById('productImage');
            if (!id && imageInput.files.length === 0) {
                showToast('Please upload a product image', 'error');
                document.getElementById('imagePreviewWrap').style.borderColor = '#d9534f';
                return;
            }

            const payload = {
                product_id: id,
                name: document.getElementById('productName').value,
                sku: document.getElementById('productSKU').value,
                barcode: document.getElementById('productBarcode').value,
                category_id: document.getElementById('productCategory').value,
                supplier_id: document.getElementById('productSupplier').value,
                selling_price: document.getElementById('productPrice').value,
                cost_price: document.getElementById('productCost').value,
                stock_quantity: document.getElementById('productStock').value,
                reorder_level: document.getElementById('productReorder').value,
                expiry_date: document.getElementById('productExpiry').value,
                location: document.getElementById('productLocation').value,
                description: document.getElementById('productDescription').value,
                type: 'medicine',
                generic_name: document.getElementById('productGenericName').value,
                brand_name: document.getElementById('productBrandName').value,
                dosage_form: document.getElementById('productDosageForm').value,
                strength: document.getElementById('productStrength').value,
                age_group: document.getElementById('productAgeGroup').value,
                sell_by_piece: document.getElementById('productSellByPiece').checked ? 1 : 0,
                pieces_per_box: document.getElementById('productPiecesPerBox').value || 0,
                price_per_piece: document.getElementById('productPricePerPiece').value || 0,
                requires_prescription: document.getElementById('productRequiresPrescription').checked ? 1 : 0
            };

            try {
                const res = await fetch(`inventory_api.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const d = await res.json();
                if (d.success) {
                    // Upload image if selected
                    const imageInput = document.getElementById('productImage');
                    if (imageInput.files.length > 0) {
                        const productId = id || d.product_id;
                        if (productId) {
                            const formData = new FormData();
                            formData.append('image', imageInput.files[0]);
                            formData.append('product_id', productId);
                            try {
                                const imgRes = await fetch('upload_product_image.php', { method: 'POST', body: formData });
                                const imgText = await imgRes.text();
                                let imgData;
                                try {
                                    imgData = JSON.parse(imgText);
                                } catch (jsonErr) {
                                    imgData = {
                                        success: false,
                                        message: imgText ? imgText.slice(0, 200) : 'Invalid server response from image upload'
                                    };
                                }
                                if (!imgData.success) {
                                    showToast('Product saved but image upload failed: ' + (imgData.message || 'Unknown error'), 'error');
                                    closeModal();
                                    loadProducts();
                                    return;
                                }
                            } catch (imgErr) {
                                console.warn('Image upload error:', imgErr);
                                showToast('Product saved but image upload failed', 'error');
                                closeModal();
                                loadProducts();
                                return;
                            }
                        }
                    }
                    showToast(imageInput.files.length > 0 ? 'Product saved with image!' : 'Product saved successfully', 'success');
                    closeModal();
                    loadProducts();
                } else {
                    showToast(d.message || 'Error saving product', 'error');
                }
            } catch (err) { console.error(err); showToast('Server error', 'error'); }
        }

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = `toast ${type}`;
            t.style.display = 'block';
            setTimeout(() => { t.style.display = 'none'; }, 3000);
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.toString().replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Export inventory as CSV report
        function exportInventory() {
            if (!allProducts || allProducts.length === 0) {
                showToast('No products to export', 'error');
                return;
            }

            const headers = ['Product ID', 'Name', 'Category', 'Cost Price', 'Selling Price', 'Strength', 'Stock Qty', 'Reorder Level', 'Expiry Date', 'Supplier', 'Status'];
            const csvRows = [headers.join(',')];

            allProducts.forEach(p => {
                const stock = parseInt(p.stock_quantity) || 0;
                const reorder = parseInt(p.reorder_level) || 0;
                let status = 'In Stock';
                if (stock <= 0) status = 'Out of Stock';
                else if (stock <= reorder) status = 'Low Stock';

                const row = [
                    p.product_id || '',
                    '"' + (p.name || '').replace(/"/g, '""') + '"',
                    '"' + (p.category_name || 'Uncategorized').replace(/"/g, '""') + '"',
                    p.cost_price || '0.00',
                    p.selling_price || '0.00',
                    '"' + (p.strength || 'N/A').replace(/"/g, '""') + '"',
                    stock,
                    reorder,
                    p.expiry_date || 'N/A',
                    '"' + (p.supplier_name || 'N/A').replace(/"/g, '""') + '"',
                    status
                ];
                csvRows.push(row.join(','));
            });

            const csvContent = csvRows.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const now = new Date();
            const dateStr = now.toISOString().slice(0, 10);
            link.href = URL.createObjectURL(blob);
            link.download = `inventory_report_${dateStr}.csv`;
            link.click();
            URL.revokeObjectURL(link.href);
            showToast('Inventory report exported!', 'success');
        }

        // ─── Activity Log Functions ───
        function openActivityLogs() {
            const modal = document.getElementById('activityLogModal');
            modal.style.display = 'flex';
            document.getElementById('activityLogBody').innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-light);"><i class="fas fa-spinner fa-spin"></i> Loading activity logs...</div>';
            fetch('inventory_api.php?action=get_activity_logs&limit=100')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.data && data.data.length > 0) {
                        renderActivityLogs(data.data);
                    } else {
                        document.getElementById('activityLogBody').innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-light);"><i class="fas fa-inbox" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:0.5rem;"></i>No activity logs found.</div>';
                    }
                })
                .catch(() => {
                    document.getElementById('activityLogBody').innerHTML = '<div style="text-align:center;padding:2rem;color:#ef4444;">Failed to load activity logs.</div>';
                });
        }

        function closeActivityLogs() {
            document.getElementById('activityLogModal').style.display = 'none';
        }

        function renderActivityLogs(logs) {
            const actionIcons = {
                'product_created': { icon: 'fa-plus-circle', color: '#22c55e' },
                'product_updated': { icon: 'fa-pen', color: '#3b82f6' },
                'product_deleted': { icon: 'fa-trash', color: '#ef4444' },
                'stock_movement':  { icon: 'fa-boxes-stacked', color: '#f59e0b' },
                'category_updated': { icon: 'fa-tags', color: '#8b5cf6' },
                'category_deleted': { icon: 'fa-tag', color: '#ef4444' }
            };

            let html = '<div style="display:flex;flex-direction:column;gap:0.3rem;">';
            logs.forEach(log => {
                const ai = actionIcons[log.action] || { icon: 'fa-circle-info', color: '#6b7280' };
                const time = log.created_at ? new Date(log.created_at).toLocaleString() : '';
                html += `<div style="display:flex;align-items:flex-start;gap:0.7rem;padding:0.6rem 0.5rem;border-bottom:1px solid rgba(0,0,0,0.05);">
                    <div style="width:32px;height:32px;border-radius:8px;background:${ai.color}15;color:${ai.color};display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.85rem;">
                        <i class="fas ${ai.icon}"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:0.82rem;font-weight:600;">${log.details || log.action}</div>
                        <div style="font-size:0.72rem;color:var(--text-light);margin-top:0.1rem;">
                            <span style="font-weight:600;">${log.username}</span> &middot; ${time}
                        </div>
                    </div>
                </div>`;
            });
            html += '</div>';
            document.getElementById('activityLogBody').innerHTML = html;
        }
    </script>
</body>

</html>