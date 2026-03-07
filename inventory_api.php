<?php
/**
 * Inventory Management API
 * Handles CRUD operations for products, stock movements, categories, and suppliers
 * Implements role-based authorization
 */

require_once 'db_connection.php';
require_once 'Auth.php';
require_once 'ImageHelper.php';

header('Content-Type: application/json');

// Initialize Auth
$auth = new Auth($conn);

// Require authentication for all inventory operations
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit;
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Route requests
switch ($action) {
    case 'get_products':
        getProducts($conn, $auth);
        break;

    case 'get_product':
        getProduct($conn, $auth);
        break;

    case 'add_product':
        addProduct($conn, $auth);
        break;

    case 'update_product':
        updateProduct($conn, $auth);
        break;

    case 'delete_product':
        deleteProduct($conn, $auth);
        break;

    case 'stock_movement':
        stockMovement($conn, $auth);
        break;

    case 'low_stock_alert':
        lowStockAlert($conn, $auth);
        break;

    case 'expiring_products':
        expiringProducts($conn, $auth);
        break;

    case 'get_categories':
        getCategories($conn, $auth);
        break;

    case 'update_category':
        updateCategory($conn, $auth);
        break;

    case 'delete_category':
        deleteCategory($conn, $auth);
        break;

    case 'get_suppliers':
        getSuppliers($conn, $auth);
        break;

    case 'get_activity_logs':
        getActivityLogs($conn, $auth);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action'
        ]);
        break;
}

function tableExists($conn, $tableName)
{
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function ensureStockMovementsTable($conn)
{
    if (tableExists($conn, 'stock_movements')) {
        return true;
    }

    // Create table (FK to users is optional to support partial/older schemas)
    $hasUsers = tableExists($conn, 'users');

    $sql = "CREATE TABLE IF NOT EXISTS stock_movements (
        movement_id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        movement_type ENUM('IN', 'OUT', 'ADJUSTMENT') NOT NULL,
        quantity INT NOT NULL,
        reference_type VARCHAR(50) NULL COMMENT 'sale, purchase, adjustment, return',
        reference_id INT NULL COMMENT 'ID of related sale, purchase, etc',
        previous_stock INT NOT NULL,
        new_stock INT NOT NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE";

    if ($hasUsers) {
        $sql .= ",\n        FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL";
    }

    $sql .= ",\n        INDEX idx_product (product_id),
        INDEX idx_movement_type (movement_type),
        INDEX idx_reference (reference_type, reference_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $conn->query($sql) === true;
}

/**
 * Get all products with filtering and pagination
 */
function getProducts($conn, $auth)
{
    // Permission check removed - all authenticated users can view products
    // $auth->requirePermission('products.view');

    try {
        // Get query parameters
        $search = $_GET['search'] ?? '';
        $category = $_GET['category'] ?? '';
        $supplier = $_GET['supplier'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = max(1, min(1000, intval($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        // Build query
        $where = ["p.is_active = 1"];
        $params = [];
        $types = '';

        if ($search) {
            $where[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= 'sss';
        }

        if ($category) {
            $where[] = "p.category_id = ?";
            $params[] = $category;
            $types .= 'i';
        }

        if ($supplier) {
            $where[] = "p.supplier_id = ?";
            $params[] = $supplier;
            $types .= 'i';
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $countQuery = "
            SELECT COUNT(*) as total
            FROM products p
            WHERE $whereClause
        ";

        $countStmt = $conn->prepare($countQuery);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $totalResult = $countStmt->get_result()->fetch_assoc();
        $total = $totalResult['total'];

        // Get products with pagination
        $query = "
            SELECT 
                p.*,
                c.category_name,
                s.supplier_name,
                s.phone as supplier_phone
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE $whereClause
            ORDER BY p.name ASC
            LIMIT ? OFFSET ?
        ";

        $stmt = $conn->prepare($query);
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $products = [];
        while ($row = $result->fetch_assoc()) {
            $row['image_url'] = resolveProductImageUrl((string)($row['image_url'] ?? ''), (string)($row['name'] ?? ''));
            $products[] = $row;
        }

        echo json_encode([
            'success' => true,
            'data' => $products,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);

    } catch (Exception $e) {
        error_log("Get products error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Failed to retrieve products'
        ]);
    }
}

/**
 * Get single product by ID
 */
function getProduct($conn, $auth)
{
    $auth->requirePermission('products.view');

    $product_id = intval($_GET['id'] ?? 0);
    if (!$product_id) {
        echo json_encode(['success' => false, 'message' => 'Product ID required']);
        return;
    }

    try {
        $stmt = $conn->prepare("
            SELECT 
                p.*,
                c.category_name,
                s.supplier_name,
                s.contact_person,
                s.phone as supplier_phone,
                s.email as supplier_email
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = ?
        ");

        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            return;
        }

        $product = $result->fetch_assoc();
        $product['image_url'] = resolveProductImageUrl((string)($product['image_url'] ?? ''), (string)($product['name'] ?? ''));

        echo json_encode([
            'success' => true,
            'data' => $product
        ]);

    } catch (Exception $e) {
        error_log("Get product error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve product']);
    }
}

/**
 * Add new product
 */
function addProduct($conn, $auth)
{
    // $auth->requirePermission('products.create');

    $data = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required = ['name', 'selling_price', 'stock_quantity', 'expiry_date', 'cost_price', 'strength'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            return;
        }
    }

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("
            INSERT INTO products (
                sku, barcode, name, description, category_id, supplier_id,
                cost_price, selling_price, stock_quantity, reorder_level,
                expiry_date, location, is_active,
                generic_name, brand_name, dosage_form, strength, age_group,
                pieces_per_box, price_per_piece, sell_by_piece
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Convert empty FK values to NULL to avoid foreign key constraint violations
        $category_id = !empty($data['category_id']) ? intval($data['category_id']) : null;
        $supplier_id = !empty($data['supplier_id']) ? intval($data['supplier_id']) : null;
        $cost_price = floatval($data['cost_price'] ?? 0);
        $reorder_level = intval($data['reorder_level'] ?? 10);
        $generic_name = $data['generic_name'] ?? '';
        $brand_name = $data['brand_name'] ?? '';
        $dosage_form = $data['dosage_form'] ?? '';
        $strength = $data['strength'] ?? '';
        $age_group = $data['age_group'] ?? 'all';
        $pieces_per_box = intval($data['pieces_per_box'] ?? 0);
        $price_per_piece = floatval($data['price_per_piece'] ?? 0);
        $sell_by_piece = intval($data['sell_by_piece'] ?? 0);
        $sku = !empty($data['sku']) ? $data['sku'] : null;
        $barcode = !empty($data['barcode']) ? $data['barcode'] : null;
        $expiry_date = !empty($data['expiry_date']) ? $data['expiry_date'] : null;

        $stmt->bind_param(
            "ssssiiddiisssssssidi",
            $sku,
            $barcode,
            $data['name'],
            $data['description'],
            $category_id,
            $supplier_id,
            $cost_price,
            $data['selling_price'],
            $data['stock_quantity'],
            $reorder_level,
            $expiry_date,
            $data['location'],
            $generic_name,
            $brand_name,
            $dosage_form,
            $strength,
            $age_group,
            $pieces_per_box,
            $price_per_piece,
            $sell_by_piece
        );

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        $product_id = $stmt->insert_id;

        // Log stock movement (initial stock)
        if ($data['stock_quantity'] > 0) {
            if (ensureStockMovementsTable($conn)) {
                $moveStmt = $conn->prepare("
                    INSERT INTO stock_movements (
                        product_id, movement_type, quantity, reference_type,
                        previous_stock, new_stock, notes, created_by
                    ) VALUES (?, 'IN', ?, 'initial', 0, ?, 'Initial stock', ?)
                ");

                if ($moveStmt) {
                    $user_id = $_SESSION['user_id'];
                    $moveStmt->bind_param("iiii", $product_id, $data['stock_quantity'], $data['stock_quantity'], $user_id);
                    $moveStmt->execute();
                }
            }
        }

        // Log activity
        $auth->logActivity($_SESSION['user_id'], 'product_created', 'Inventory', "Created product: {$data['name']} (ID: $product_id)");

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Product added successfully',
            'product_id' => $product_id
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Add product error: " . $e->getMessage());

        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            echo json_encode(['success' => false, 'message' => 'SKU or Barcode already exists']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add product']);
        }
    }
}

/**
 * Update existing product
 */
function updateProduct($conn, $auth)
{
    // $auth->requirePermission('products.edit');

    $data = json_decode(file_get_contents('php://input'), true);

    $product_id = intval($data['product_id'] ?? 0);
    if (!$product_id) {
        echo json_encode(['success' => false, 'message' => 'Product ID required']);
        return;
    }

    try {
        $generic_name = $data['generic_name'] ?? '';
        $brand_name = $data['brand_name'] ?? '';
        $dosage_form = $data['dosage_form'] ?? '';
        $strength = $data['strength'] ?? '';
        $age_group = $data['age_group'] ?? 'all';
        $pieces_per_box = intval($data['pieces_per_box'] ?? 0);
        $price_per_piece = floatval($data['price_per_piece'] ?? 0);
        $sell_by_piece = intval($data['sell_by_piece'] ?? 0);

        // Convert empty FK values to NULL to avoid foreign key constraint violations
        $category_id = !empty($data['category_id']) ? intval($data['category_id']) : null;
        $supplier_id = !empty($data['supplier_id']) ? intval($data['supplier_id']) : null;
        $cost_price = floatval($data['cost_price'] ?? 0);
        $selling_price = floatval($data['selling_price'] ?? 0);
        $reorder_level = intval($data['reorder_level'] ?? 10);
        $expiry_date = !empty($data['expiry_date']) ? $data['expiry_date'] : null;
        $sku = !empty($data['sku']) ? $data['sku'] : null;
        $barcode = !empty($data['barcode']) ? $data['barcode'] : null;

        $stock_quantity = isset($data['stock_quantity']) ? intval($data['stock_quantity']) : null;
        if ($stock_quantity !== null && $stock_quantity < 0) {
            echo json_encode(['success' => false, 'message' => 'Stock quantity cannot be negative']);
            return;
        }

        $stmt = $conn->prepare("
            UPDATE products SET
                sku = ?,
                barcode = ?,
                name = ?,
                description = ?,
                category_id = ?,
                supplier_id = ?,
                cost_price = ?,
                selling_price = ?,
                stock_quantity = IFNULL(?, stock_quantity),
                reorder_level = ?,
                expiry_date = ?,
                location = ?,
                generic_name = ?,
                brand_name = ?,
                dosage_form = ?,
                strength = ?,
                age_group = ?,
                pieces_per_box = ?,
                price_per_piece = ?,
                sell_by_piece = ?
            WHERE product_id = ?
        ");

        $stmt->bind_param(
            "ssssiiddiisssssssidii",
            $sku,
            $barcode,
            $data['name'],
            $data['description'],
            $category_id,
            $supplier_id,
            $cost_price,
            $selling_price,
            $stock_quantity,
            $reorder_level,
            $expiry_date,
            $data['location'],
            $generic_name,
            $brand_name,
            $dosage_form,
            $strength,
            $age_group,
            $pieces_per_box,
            $price_per_piece,
            $sell_by_piece,
            $product_id
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            error_log("Update product SQL error: " . $error);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $error]);
            return;
        }

        // Log activity
        $auth->logActivity($_SESSION['user_id'], 'product_updated', 'Inventory', "Updated product: {$data['name']} (ID: $product_id)");

        echo json_encode([
            'success' => true,
            'message' => 'Product updated successfully'
        ]);

    } catch (Exception $e) {
        error_log("Update product error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update product: ' . $e->getMessage()]);
    }
}

/**
 * Delete product (soft delete)
 */
function deleteProduct($conn, $auth)
{
    // $auth->requirePermission('products.delete');

    $product_id = intval($_GET['id'] ?? 0);
    if (!$product_id) {
        echo json_encode(['success' => false, 'message' => 'Product ID required']);
        return;
    }

    try {
        $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE product_id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();

        $auth->logActivity($_SESSION['user_id'], 'product_deleted', 'Inventory', "Deleted product ID: $product_id");

        echo json_encode([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);

    } catch (Exception $e) {
        error_log("Delete product error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete product']);
    }
}

/**
 * Record stock movement (adjustment, restock, etc.)
 */
function stockMovement($conn, $auth)
{
    $auth->requirePermission('inventory.adjust');

    $data = json_decode(file_get_contents('php://input'), true);

    $product_id = intval($data['product_id'] ?? 0);
    $movement_type = $data['movement_type'] ?? '';
    $quantityRaw = $data['quantity'] ?? null;
    $quantity = ($quantityRaw === null || $quantityRaw === '') ? null : intval($quantityRaw);

    if (!$product_id || !$movement_type || $quantity === null) {
        echo json_encode(['success' => false, 'message' => 'Product ID, quantity, and movement type required']);
        return;
    }

    if (!in_array($movement_type, ['IN', 'OUT', 'ADJUSTMENT'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid movement type']);
        return;
    }

    // Validation rules
    if (($movement_type === 'IN' || $movement_type === 'OUT') && $quantity <= 0) {
        echo json_encode(['success' => false, 'message' => 'Quantity must be greater than 0']);
        return;
    }
    if ($movement_type === 'ADJUSTMENT' && $quantity < 0) {
        echo json_encode(['success' => false, 'message' => 'Quantity cannot be negative']);
        return;
    }

    try {
        $conn->begin_transaction();

        // Get current stock
        $stmt = $conn->prepare("SELECT stock_quantity FROM products WHERE product_id = ? FOR UPDATE");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            throw new Exception('Product not found');
        }

        $row = $result->fetch_assoc();
        $previous_stock = $row['stock_quantity'];

        // Calculate new stock
        if ($movement_type === 'IN') {
            $new_stock = $previous_stock + $quantity;
        } elseif ($movement_type === 'OUT') {
            $new_stock = $previous_stock - $quantity;
        } else { // ADJUSTMENT
            $new_stock = $quantity; // Set to exact quantity
        }

        if ($new_stock < 0) {
            throw new Exception('Insufficient stock');
        }

        // Update product stock
        $updateStmt = $conn->prepare("UPDATE products SET stock_quantity = ? WHERE product_id = ?");
        $updateStmt->bind_param("ii", $new_stock, $product_id);
        $updateStmt->execute();

        // Record stock movement (create table if missing)
        if (ensureStockMovementsTable($conn)) {
            $moveStmt = $conn->prepare("
                INSERT INTO stock_movements (
                    product_id, movement_type, quantity, reference_type,
                    previous_stock, new_stock, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if ($moveStmt) {
                $user_id = $_SESSION['user_id'];
                $abs_quantity = abs($quantity);
                $reference_type = $data['reference_type'] ?? 'adjustment';
                $notes = $data['notes'] ?? '';
                $moveStmt->bind_param(
                    "isisiisi",
                    $product_id,
                    $movement_type,
                    $abs_quantity,
                    $reference_type,
                    $previous_stock,
                    $new_stock,
                    $notes,
                    $user_id
                );
                $moveStmt->execute();
            }
        }

        // Log activity
        $auth->logActivity($user_id, 'stock_movement', 'Inventory', "Stock $movement_type: Product ID $product_id, Quantity: $quantity");

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Stock updated successfully',
            'previous_stock' => $previous_stock,
            'new_stock' => $new_stock
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Stock movement error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Get low stock products
 */
/**
 * Get low stock products
 */
function lowStockAlert($conn, $auth)
{
    $auth->requirePermission('products.view');

    try {
        // Get threshold from settings
        $threshold = 20; // Default
        $settingStmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'low_stock_threshold'");
        $settingStmt->execute();
        $res = $settingStmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $threshold = (int) $row['setting_value'];
        }

        $stmt = $conn->prepare("SELECT * FROM products WHERE stock_quantity <= ? AND is_active = 1 ORDER BY stock_quantity ASC");
        $stmt->bind_param("i", $threshold);
        $stmt->execute();
        $result = $stmt->get_result();

        $products = [];
        while ($row = $result->fetch_assoc()) {
            $row['image_url'] = resolveProductImageUrl((string)($row['image_url'] ?? ''), (string)($row['name'] ?? ''));
            $products[] = $row;
        }

        echo json_encode([
            'success' => true,
            'data' => $products,
            'count' => count($products),
            'threshold' => $threshold
        ]);

    } catch (Exception $e) {
        error_log("Low stock alert error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve low stock products']);
    }
}

/**
 * Get expiring products
 */
function expiringProducts($conn, $auth)
{
    $auth->requirePermission('products.view');

    try {
        // Get threshold from settings
        $days = 30; // Default
        $settingStmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'expiry_alert_days'");
        $settingStmt->execute();
        $res = $settingStmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $days = (int) $row['setting_value'];
        }

        $targetDate = date('Y-m-d', strtotime("+$days days"));
        $today = date('Y-m-d');

        // Include already-expired products (critical) + products expiring within threshold
        $stmt = $conn->prepare("
            SELECT *, DATEDIFF(expiry_date, ?) as days_until_expiry 
            FROM products 
            WHERE expiry_date IS NOT NULL 
              AND expiry_date != '0000-00-00'
              AND expiry_date <= ? 
              AND is_active = 1 
            ORDER BY expiry_date ASC
        ");
        $stmt->bind_param("ss", $today, $targetDate);
        $stmt->execute();
        $result = $stmt->get_result();

        $products = [];
        $expiredCount = 0;
        $expiringSoonCount = 0;
        while ($row = $result->fetch_assoc()) {
            $row['image_url'] = resolveProductImageUrl((string)($row['image_url'] ?? ''), (string)($row['name'] ?? ''));
            if ($row['days_until_expiry'] < 0) {
                $expiredCount++;
            } else {
                $expiringSoonCount++;
            }
            $products[] = $row;
        }

        echo json_encode([
            'success' => true,
            'data' => $products,
            'count' => count($products),
            'expired_count' => $expiredCount,
            'expiring_soon_count' => $expiringSoonCount,
            'days_threshold' => $days
        ]);

    } catch (Exception $e) {
        error_log("Expiring products error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve expiring products']);
    }
}

/**
 * Get all categories
 */
function getCategories($conn, $auth)
{
    // $auth->requirePermission('products.view');

    try {
        $stmt = $conn->query("SELECT * FROM categories ORDER BY category_name ASC");
        $categories = [];

        while ($row = $stmt->fetch_assoc()) {
            $categories[] = $row;
        }

        echo json_encode([
            'success' => true,
            'data' => $categories
        ]);

    } catch (Exception $e) {
        error_log("Get categories error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve categories']);
    }
}

/**
 * Update a category
 */
function updateCategory($conn, $auth)
{
    // Only admins can update categories
    if (($_SESSION['role_name'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        return;
    }

    $category_id = intval($_POST['category_id'] ?? 0);
    $category_name = trim($_POST['category_name'] ?? '');

    if (!$category_id || !$category_name) {
        echo json_encode(['success' => false, 'message' => 'Category ID and name are required']);
        return;
    }

    try {
        $stmt = $conn->prepare("UPDATE categories SET category_name = ? WHERE category_id = ?");
        $stmt->bind_param("si", $category_name, $category_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Category not found or name unchanged']);
            return;
        }

        $auth->logActivity($_SESSION['user_id'], 'category_updated', 'Inventory', "Updated category: $category_name (ID: $category_id)");

        echo json_encode([
            'success' => true,
            'message' => 'Category updated successfully'
        ]);

    } catch (Exception $e) {
        error_log("Update category error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update category']);
    }
}

/**
 * Delete a category (sets products in this category to uncategorized)
 */
function deleteCategory($conn, $auth)
{
    if (($_SESSION['role_name'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        return;
    }

    $category_id = intval($_POST['category_id'] ?? 0);
    if (!$category_id) {
        echo json_encode(['success' => false, 'message' => 'Category ID is required']);
        return;
    }

    try {
        $conn->begin_transaction();

        // Set products in this category to NULL (uncategorized)
        $stmt = $conn->prepare("UPDATE products SET category_id = NULL WHERE category_id = ?");
        $stmt->bind_param("i", $category_id);
        $stmt->execute();

        // Delete the category
        $stmt2 = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
        $stmt2->bind_param("i", $category_id);
        $stmt2->execute();

        $auth->logActivity($_SESSION['user_id'], 'category_deleted', 'Inventory', "Deleted category ID: $category_id");

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Category deleted successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delete category error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete category']);
    }
}

/**
 * Get all suppliers
 */
function getSuppliers($conn, $auth)
{
    // $auth->requirePermission('products.view');

    try {
        $stmt = $conn->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
        $suppliers = [];

        while ($row = $stmt->fetch_assoc()) {
            $suppliers[] = $row;
        }

        echo json_encode([
            'success' => true,
            'data' => $suppliers
        ]);

    } catch (Exception $e) {
        error_log("Get suppliers error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve suppliers']);
    }
}

/**
 * Get recent inventory activity logs
 */
function getActivityLogs($conn, $auth) {
    try {
        $limit = intval($_GET['limit'] ?? 50);
        $limit = min($limit, 200);

        $sql = "SELECT al.*, u.username, u.full_name 
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.user_id
                WHERE al.module = 'Inventory'
                ORDER BY al.created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = [
                'id' => $row['log_id'] ?? $row['id'] ?? null,
                'action' => $row['action'],
                'details' => $row['details'],
                'username' => $row['full_name'] ?? $row['username'] ?? 'System',
                'ip_address' => $row['ip_address'] ?? '',
                'created_at' => $row['created_at']
            ];
        }

        echo json_encode(['success' => true, 'data' => $logs]);
    } catch (Exception $e) {
        error_log("Activity logs error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve activity logs']);
    }
}
?>