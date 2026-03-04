<?php
/**
 * Image Proxy - Serves product images from local filesystem.
 * Bypasses Apache URL-decoding issues with folder names containing % and other
 * special characters.
 *
 * Usage: serve_image.php?f=medicine_images/FolderName/file.jpg
 *   The 'f' param is the relative path (not URL-encoded beyond normal query-string encoding).
 */

// Performance: skip session, output buffering, etc.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$requestedPath = $_GET['f'] ?? '';

if ($requestedPath === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Missing file parameter';
    exit;
}

// Decode (browser will percent-encode the query param value)
$requestedPath = rawurldecode($requestedPath);

// Security: only allow specific safe directories
$allowedPrefixes = ['medicine_images/', 'uploads/products/'];
$safe = false;
foreach ($allowedPrefixes as $prefix) {
    if (strpos($requestedPath, $prefix) === 0) {
        $safe = true;
        break;
    }
}

if (!$safe) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Forbidden';
    exit;
}

// Security: block path traversal
if (preg_match('/\.\.[\\/]/', $requestedPath) || strpos($requestedPath, "\0") !== false) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Forbidden';
    exit;
}

$basePath = __DIR__;
$fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $requestedPath);
$realBase = realpath($basePath);
$realFile = realpath($fullPath);

// Confirm file exists and is within project root
if ($realBase === false || $realFile === false || !is_file($realFile)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';
    exit;
}

if (strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Forbidden';
    exit;
}

// Only allow image MIME types
$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
];

$contentType = $mimeMap[$ext] ?? null;
if ($contentType === null) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Not an allowed image type';
    exit;
}

// Serve the image with cache headers
$fileSize = filesize($realFile);
$lastModified = filemtime($realFile);
$etag = '"' . md5($realFile . $lastModified . $fileSize) . '"';

// Handle conditional requests (304 Not Modified)
if (
    (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
    (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastModified)
) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . $fileSize);
header('Cache-Control: public, max-age=31536000, immutable');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('ETag: ' . $etag);
header('Accept-Ranges: bytes');

readfile($realFile);
exit;
