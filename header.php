<?php
session_start();
// Development bypass: when developing locally you can access pages without an active session
// by either adding ?dev=1 to the URL or creating an empty file named ".dev_pos" in the project root.
// This bypass is only enabled for localhost (127.0.0.1 / ::1) or the built-in PHP dev server.
$dev_bypass = false;
if (isset($_GET['dev']) || file_exists(__DIR__ . DIRECTORY_SEPARATOR . '.dev_pos')) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote === '127.0.0.1' || $remote === '::1' || php_sapi_name() === 'cli-server') {
        $dev_bypass = true;
    }
}

if (!$dev_bypass && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calloway Pharmacy IMS</title>
    <link rel="icon" type="image/png" href="logo-removebg-preview.png">
    <link rel="shortcut icon" type="image/png" href="logo-removebg-preview.png">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <header>
        <?php if (!str_contains($_SERVER['PHP_SELF'], 'dashboard.php')) : ?>
            <a href="dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        <?php endif; ?>
        <h1><?php echo isset($page_title) ? $page_title : 'Calloway Pharmacy IMS'; ?></h1>
        <div class="theme-toggle">
            <button id="themeToggle" class="btn btn-icon" aria-label="Toggle theme">
                <i class="fas fa-moon"></i>
            </button>
        </div>
    </header>
    <nav class="main-nav">
        <div class="nav-container">
            <a href="dashboard.php" class="nav-item <?php echo str_contains($_SERVER['PHP_SELF'], 'dashboard.php') ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="pos.php" class="nav-item <?php echo str_contains($_SERVER['PHP_SELF'], 'pos.php') ? 'active' : ''; ?>">
                <i class="fas fa-cash-register"></i>
                <span>Point of Sale</span>
            </a>
            <a href="medicine-locator.php" class="nav-item <?php echo str_contains($_SERVER['PHP_SELF'], 'medicine-locator.php') ? 'active' : ''; ?>">
                <i class="fas fa-search-location"></i>
                <span>Medicine &amp; Expiry</span>
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>
    <main class="animate-slide-in"> 
