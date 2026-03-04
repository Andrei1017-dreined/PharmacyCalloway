<?php
/**
 * API: Select Medicine Image
 * Updates the image_url for a product
 */
require_once 'db_connection.php';
require_once 'Auth.php';
require_once 'ImageHelper.php';

header('Content-Type: application/json');

$auth = new Auth($conn);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$productId = intval($input['product_id'] ?? 0);
$imageUrl = normalizeProductImageUrl((string)($input['image_url'] ?? ''));

if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

if (empty($imageUrl)) {
    echo json_encode(['success' => false, 'message' => 'Invalid image URL']);
    exit;
}

if (preg_match('#^(https?:)?//#i', $imageUrl) || stripos($imageUrl, 'data:') === 0) {
    echo json_encode(['success' => false, 'message' => 'Only local server image paths are allowed']);
    exit;
}

$decodedPath = rawurldecode($imageUrl);
$basePath = realpath(__DIR__);
$resolvedPath = realpath(__DIR__ . '/' . ltrim($decodedPath, '/'));

if ($basePath === false || $resolvedPath === false || !is_file($resolvedPath)) {
    echo json_encode(['success' => false, 'message' => 'Image file not found on server']);
    exit;
}

if (strpos($resolvedPath, $basePath . DIRECTORY_SEPARATOR) !== 0 && $resolvedPath !== $basePath) {
    echo json_encode(['success' => false, 'message' => 'Invalid image path']);
    exit;
}

$relativePath = str_replace('\\', '/', ltrim(substr($resolvedPath, strlen($basePath)), '\\/'));
// Store the raw (non-URL-encoded) relative path; the image proxy handles encoding at display time
$imageUrl = $relativePath;

try {
    // Update the product's image_url
    $stmt = $conn->prepare("UPDATE products SET image_url = ? WHERE product_id = ?");
    $stmt->bind_param("si", $imageUrl, $productId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Image assigned successfully',
            'product_id' => $productId,
            'image_url' => $imageUrl
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
    }
} catch (Exception $e) {
    error_log("Select medicine image error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
