<?php
/**
 * Medicine Image Review & Selection Tool
 * Browse downloaded images and assign the best one to each product
 * OPTIMIZED: Pagination + on-demand image loading for speed
 */
require_once 'db_connection.php';
require_once 'Auth.php';
require_once 'ImageHelper.php';

$auth = new Auth($conn);
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// --- AJAX endpoint: get images for a single medicine ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_images') {
    header('Content-Type: application/json');
    $productId = intval($_GET['product_id'] ?? 0);
    $productName = $_GET['product_name'] ?? '';

    $imageDir = __DIR__ . '/medicine_images';
    $imageBaseUrl = 'medicine_images';

    function sanitizeFolderNameAjax($name) {
        $cleaned = preg_replace('/[<>:"\/\\|?*\x00-\x1f]/', '_', $name);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned, ' .');
        if (empty($cleaned)) $cleaned = 'unnamed_medicine';
        if (strlen($cleaned) > 120) {
            $cleaned = substr($cleaned, 0, 120);
            $cleaned = rtrim($cleaned, ' .');
        }
        return $cleaned;
    }

    $folderName = sanitizeFolderNameAjax($productName);
    $folderPath = $imageDir . '/' . $folderName;
    $images = [];

    if (is_dir($folderPath)) {
        $files = array_diff(scandir($folderPath), ['.', '..']);
        foreach ($files as $f) {
            if (is_file($folderPath . '/' . $f) && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $f)) {
                $images[] = [
                    'filename' => $f,
                    'url' => proxyImageUrl($imageBaseUrl . '/' . $folderName . '/' . $f)
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'images' => $images]);
    exit;
}

// --- Main page: only fetch metadata, no filesystem scanning ---
$imageDir = __DIR__ . '/medicine_images';

function sanitizeFolderName($name) {
    $cleaned = preg_replace('/[<>:"\/\\|?*\x00-\x1f]/', '_', $name);
    $cleaned = preg_replace('/\s+/', ' ', $cleaned);
    $cleaned = trim($cleaned, ' .');
    if (empty($cleaned)) $cleaned = 'unnamed_medicine';
    if (strlen($cleaned) > 120) {
        $cleaned = substr($cleaned, 0, 120);
        $cleaned = rtrim($cleaned, ' .');
    }
    return $cleaned;
}

// Build a quick set of folders that exist (one readdir, not per-medicine)
$existingFolders = [];
if (is_dir($imageDir)) {
    $dh = opendir($imageDir);
    if ($dh) {
        while (($entry = readdir($dh)) !== false) {
            if ($entry !== '.' && $entry !== '..' && is_dir($imageDir . '/' . $entry)) {
                $existingFolders[$entry] = true;
            }
        }
        closedir($dh);
    }
}

// Get all medicines - lightweight query
$medicines = [];
$query = "SELECT product_id, name, image_url, category_id FROM products WHERE type='medicine' ORDER BY name ASC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $folderName = sanitizeFolderName($row['name']);
    $row['folder_name'] = $folderName;
    $row['folder_exists'] = isset($existingFolders[$folderName]);
    $row['has_assigned'] = !empty($row['image_url']);
    $medicines[] = $row;
}

// Stats
$totalMeds = count($medicines);
$withFolders = count(array_filter($medicines, fn($m) => $m['folder_exists']));
$assigned = count(array_filter($medicines, fn($m) => $m['has_assigned']));
$needReview = count(array_filter($medicines, fn($m) => $m['folder_exists'] && !$m['has_assigned']));

// Pagination
$perPage = 20;
$totalPages = max(1, ceil($totalMeds / $perPage));
$page = max(1, min($totalPages, intval($_GET['page'] ?? 1)));

// Apply server-side filter
$filter = $_GET['filter'] ?? 'needs-review';
$search = strtolower(trim($_GET['search'] ?? ''));

$filtered = array_filter($medicines, function($m) use ($filter, $search) {
    // Status filter
    if ($filter === 'needs-review' && !($m['folder_exists'] && !$m['has_assigned'])) return false;
    if ($filter === 'assigned' && !$m['has_assigned']) return false;
    if ($filter === 'no-images' && $m['folder_exists']) return false;
    // Search filter
    if ($search !== '' && strpos(strtolower($m['name']), $search) === false) return false;
    return true;
});

$filteredCount = count($filtered);
$totalPages = max(1, ceil($filteredCount / $perPage));
$page = max(1, min($totalPages, $page));
$pageItems = array_slice(array_values($filtered), ($page - 1) * $perPage, $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medicine Image Review - Calloway Pharmacy</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; padding: 2rem; }
        .header { background: white; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { color: #2c3e50; margin-bottom: 0.5rem; }
        .stats { display: flex; gap: 1rem; margin-top: 1rem; flex-wrap: wrap; }
        .stat-card { background: #667eea; color: white; padding: 1rem; border-radius: 6px; min-width: 200px; }
        .stat-card.green { background: #11998e; }
        .stat-card.orange { background: #f093fb; }
        .stat-card h3 { font-size: 2rem; margin-bottom: 0.25rem; }
        .stat-card p { opacity: 0.9; font-size: 0.9rem; }
        
        .filters { background: white; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; display: flex; gap: 1rem; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .filters label { font-weight: 600; }
        .filters select, .filters input { padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; }
        
        .medicine-grid { display: grid; gap: 1.5rem; }
        .medicine-card { background: white; border-radius: 8px; padding: 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .medicine-card.needs-review { border-left: 4px solid #f5576c; }
        .medicine-card.has-image { border-left: 4px solid #38ef7d; }
        .medicine-card.no-images { border-left: 4px solid #95a5a6; opacity: 0.6; }
        
        .med-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem; }
        .med-header h3 { color: #2c3e50; }
        .med-header .badge { padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.85rem; font-weight: 600; }
        .badge.assigned { background: #d4edda; color: #155724; }
        .badge.needs { background: #fff3cd; color: #856404; }
        .badge.none { background: #f8d7da; color: #721c24; }
        
        .current-image { margin-bottom: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 6px; }
        .current-image img { max-width: 150px; max-height: 150px; object-fit: contain; border: 2px solid #28a745; border-radius: 4px; }
        .current-image p { margin-top: 0.5rem; font-size: 0.9rem; color: #6c757d; }
        
        .image-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; }
        .image-item { position: relative; border: 2px solid #dee2e6; border-radius: 6px; overflow: hidden; cursor: pointer; transition: all 0.2s; }
        .image-item:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); border-color: #007bff; }
        .image-item img { width: 100%; height: 150px; object-fit: contain; background: #f8f9fa; }
        .image-item.selected { border-color: #28a745; border-width: 3px; }
        .image-item .select-btn { position: absolute; top: 0.5rem; right: 0.5rem; background: #007bff; color: white; border: none; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.75rem; cursor: pointer; opacity: 0; transition: opacity 0.2s; z-index: 10; }
        .image-item:hover .select-btn { opacity: 1; }
        .image-item.selected .select-btn { background: #28a745; opacity: 1; }
        .image-name { padding: 0.5rem; font-size: 0.75rem; color: #6c757d; text-align: center; background: white; }
        
        /* Image Preview Modal */
        .image-preview-overlay { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.9); z-index: 9999; align-items: center; justify-content: center; animation: fadeIn 0.15s; }
        .image-preview-overlay.active { display: flex; }
        .image-preview-content { max-width: 90vw; max-height: 90vh; position: relative; }
        .image-preview-content img { max-width: 100%; max-height: 90vh; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 50px rgba(0,0,0,0.5); }
        .preview-close { position: absolute; top: -3rem; right: 0; background: white; color: #333; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .preview-info { position: absolute; bottom: -3rem; left: 0; right: 0; color: white; text-align: center; font-size: 0.9rem; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        .no-images-msg { text-align: center; padding: 3rem; color: #95a5a6; }
        .no-images-msg i { font-size: 3rem; margin-bottom: 1rem; }
        
        .loading { text-align: center; padding: 2rem; color: #6c757d; }
        
        @media (max-width: 768px) {
            .stats { flex-direction: column; }
            .filters { flex-direction: column; align-items: stretch; }
            .image-gallery { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.5rem; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🖼️ Medicine Image Review & Selection</h1>
        <p>Review downloaded images and select the best one for each medicine</p>
        <p style="margin-top: 0.5rem; font-size: 0.9rem; color: #6c757d;">
            💡 <strong>Tip:</strong> Click an image to zoom in • Double-click or use "Select" button to assign it
        </p>
        
        <div class="stats">
            <div class="stat-card">
                <h3><?= $totalMeds ?></h3>
                <p>Total Medicines</p>
            </div>
            <div class="stat-card green">
                <h3><?= $assigned ?></h3>
                <p>Images Assigned</p>
            </div>
            <div class="stat-card orange">
                <h3><?= $needReview ?></h3>
                <p>Need Review</p>
            </div>
            <div class="stat-card">
                <h3><?= $withFolders ?></h3>
                <p>Have Downloaded Images</p>
            </div>
        </div>
    </div>
    
    <form class="filters" method="GET" id="filterForm">
        <label>Filter:</label>
        <select name="filter" id="filterStatus" onchange="document.getElementById('filterForm').submit()">
            <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Medicines</option>
            <option value="needs-review" <?= $filter === 'needs-review' ? 'selected' : '' ?>>Needs Review (Has Images, Not Assigned)</option>
            <option value="assigned" <?= $filter === 'assigned' ? 'selected' : '' ?>>Already Assigned</option>
            <option value="no-images" <?= $filter === 'no-images' ? 'selected' : '' ?>>No Downloaded Images</option>
        </select>
        
        <label>Search:</label>
        <input type="text" name="search" id="searchBox" placeholder="Search medicine name..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit" style="padding:0.5rem 1rem; background:#007bff; color:#fff; border:none; border-radius:4px; cursor:pointer;">Search</button>
        <span style="color:#6c757d; font-size:0.9rem;"><?= $filteredCount ?> results — Page <?= $page ?> of <?= $totalPages ?></span>
    </form>
    
    <div class="medicine-grid" id="medicineGrid">
        <?php foreach ($pageItems as $med): ?>
            <?php
                $cardClass = 'medicine-card';
                $badgeText = '';
                $badgeClass = '';
                
                if ($med['has_assigned']) {
                    $cardClass .= ' has-image';
                    $badgeText = '✓ Image Assigned';
                    $badgeClass = 'badge assigned';
                } elseif ($med['folder_exists']) {
                    $cardClass .= ' needs-review';
                    $badgeText = 'Needs Review';
                    $badgeClass = 'badge needs';
                } else {
                    $cardClass .= ' no-images';
                    $badgeText = 'No Images';
                    $badgeClass = 'badge none';
                }
                $status = $med['has_assigned'] ? 'assigned' : ($med['folder_exists'] ? 'needs-review' : 'no-images');
            ?>
            <div class="<?= $cardClass ?>" data-status="<?= $status ?>" data-name="<?= htmlspecialchars(strtolower($med['name'])) ?>" data-product-id="<?= $med['product_id'] ?>" data-product-name="<?= htmlspecialchars($med['name']) ?>">
                <div class="med-header" onclick="toggleGallery(this.parentElement)" style="cursor:pointer;">
                    <div>
                        <h3><?= htmlspecialchars($med['name']) ?></h3>
                        <small style="color: #6c757d;">Product ID: <?= $med['product_id'] ?> | Folder: <?= $med['folder_exists'] ? '✓' : '✗' ?></small>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <span class="<?= $badgeClass ?>"><?= $badgeText ?></span>
                        <span class="expand-arrow" style="font-size:1.2rem; transition:transform 0.2s;">▶</span>
                    </div>
                </div>
                
                <?php if ($med['has_assigned']): ?>
                    <div class="current-image">
                        <strong>Current Assigned Image:</strong><br>
                        <img src="<?= htmlspecialchars(proxyImageUrl($med['image_url'])) ?>" alt="Current" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect width=\'100\' height=\'100\' fill=\'%23ddd\'/%3E%3Ctext x=\'50\' y=\'50\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3ENo Image%3C/text%3E%3C/svg%3E'">
                        <p><?= htmlspecialchars(basename($med['image_url'])) ?></p>
                    </div>
                <?php endif; ?>
                
                <!-- Gallery loads on-demand when expanded -->
                <div class="gallery-container" style="display:none;" data-loaded="false">
                    <div class="loading">Loading images...</div>
                </div>
                
                <?php if (!$med['folder_exists']): ?>
                    <div class="no-images-msg" style="display:none;">
                        <div style="font-size: 3rem;">📁</div>
                        <p>No images downloaded for this medicine</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex; justify-content:center; gap:0.5rem; margin:2rem 0; flex-wrap:wrap;">
        <?php
        $baseParams = http_build_query(array_filter(['filter' => $filter, 'search' => $search]));
        ?>
        <?php if ($page > 1): ?>
            <a href="?<?= $baseParams ?>&page=<?= $page - 1 ?>" style="padding:0.5rem 1rem; background:#007bff; color:#fff; border-radius:4px; text-decoration:none;">← Prev</a>
        <?php endif; ?>
        
        <?php
        $startP = max(1, $page - 3);
        $endP = min($totalPages, $page + 3);
        for ($p = $startP; $p <= $endP; $p++):
        ?>
            <a href="?<?= $baseParams ?>&page=<?= $p ?>" style="padding:0.5rem 1rem; background:<?= $p === $page ? '#28a745' : '#6c757d' ?>; color:#fff; border-radius:4px; text-decoration:none; font-weight:<?= $p === $page ? 'bold' : 'normal' ?>;"><?= $p ?></a>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
            <a href="?<?= $baseParams ?>&page=<?= $page + 1 ?>" style="padding:0.5rem 1rem; background:#007bff; color:#fff; border-radius:4px; text-decoration:none;">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Image Preview Overlay -->
    <div class="image-preview-overlay" id="imagePreview" onclick="closePreview()">
        <div class="image-preview-content" onclick="event.stopPropagation()">
            <button class="preview-close" onclick="closePreview()">✕ Close</button>
            <img id="previewImage" src="" alt="Full Preview">
            <div class="preview-info" id="previewInfo"></div>
        </div>
    </div>
    
    <script>
        function showPreview(imagePath, imageName) {
            const overlay = document.getElementById('imagePreview');
            const img = document.getElementById('previewImage');
            const info = document.getElementById('previewInfo');
            
            img.src = imagePath;
            info.textContent = imageName;
            overlay.classList.add('active');
        }
        
        function closePreview() {
            const overlay = document.getElementById('imagePreview');
            overlay.classList.remove('active');
        }
        
        // Close preview on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closePreview();
        });
        
        // Toggle gallery open/closed and lazy-load images via AJAX
        async function toggleGallery(card) {
            const container = card.querySelector('.gallery-container');
            const noImgMsg = card.querySelector('.no-images-msg');
            const arrow = card.querySelector('.expand-arrow');
            
            if (!container && noImgMsg) {
                // No images folder — toggle the message
                noImgMsg.style.display = noImgMsg.style.display === 'none' ? 'block' : 'none';
                arrow.style.transform = noImgMsg.style.display === 'none' ? '' : 'rotate(90deg)';
                return;
            }
            if (!container) return;
            
            const isOpen = container.style.display !== 'none';
            if (isOpen) {
                container.style.display = 'none';
                arrow.style.transform = '';
                return;
            }
            
            container.style.display = 'block';
            arrow.style.transform = 'rotate(90deg)';
            
            // Load images on first open
            if (container.dataset.loaded === 'false') {
                const productId = card.dataset.productId;
                const productName = card.dataset.productName;
                
                try {
                    const resp = await fetch(`review_medicine_images.php?ajax=get_images&product_id=${productId}&product_name=${encodeURIComponent(productName)}`);
                    const data = await resp.json();
                    
                    if (data.success && data.images.length > 0) {
                        const currentUrl = card.querySelector('.current-image img')?.src || '';
                        let html = '<div class="image-gallery">';
                        data.images.forEach(img => {
                            const isSelected = currentUrl && currentUrl.endsWith(img.filename);
                            html += `
                                <div class="image-item ${isSelected ? 'selected' : ''}" 
                                     onclick="showPreview('${img.url}', '${img.filename}')"
                                     ondblclick="selectImage(${productId}, '${img.url}', this)">
                                    <img src="${img.url}" alt="${img.filename}" loading="lazy">
                                    <button class="select-btn" onclick="event.stopPropagation(); selectImage(${productId}, '${img.url}', this.parentElement)">${isSelected ? '✓ Selected' : 'Select'}</button>
                                    <div class="image-name">${img.filename}</div>
                                </div>`;
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<div class="no-images-msg"><div style="font-size:3rem;">📁</div><p>No images found in folder</p></div>';
                    }
                    container.dataset.loaded = 'true';
                } catch (err) {
                    container.innerHTML = '<div class="no-images-msg"><p style="color:#dc3545;">Error loading images</p></div>';
                    console.error(err);
                }
            }
        }
        
        async function selectImage(productId, imagePath, element) {
            try {
                const response = await fetch('api_select_medicine_image.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ product_id: productId, image_url: imagePath })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const card = element.closest('.medicine-card');
                    const gallery = element.closest('.image-gallery');
                    
                    gallery.querySelectorAll('.image-item').forEach(item => {
                        item.classList.remove('selected');
                        item.querySelector('.select-btn').textContent = 'Select';
                    });
                    
                    element.classList.add('selected');
                    element.querySelector('.select-btn').textContent = '✓ Selected';
                    
                    card.classList.remove('needs-review');
                    card.classList.add('has-image');
                    card.dataset.status = 'assigned';
                    
                    const badge = card.querySelector('.badge');
                    badge.className = 'badge assigned';
                    badge.textContent = '✓ Image Assigned';
                    
                    let currentDiv = card.querySelector('.current-image');
                    if (!currentDiv) {
                        currentDiv = document.createElement('div');
                        currentDiv.className = 'current-image';
                        card.querySelector('.med-header').after(currentDiv);
                    }
                    currentDiv.innerHTML = `
                        <strong>Current Assigned Image:</strong><br>
                        <img src="${imagePath}" alt="Current">
                        <p>${imagePath.split('/').pop()}</p>
                    `;
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to update image');
            }
        }
    </script>
</body>
</html>
