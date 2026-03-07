<?php
// Get the current page name
$current_page = basename($_SERVER['PHP_SELF']);
$_headerUser = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$_headerRoleSlug = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$_headerIsCustomer = ($_headerRoleSlug === 'customer');
$_headerIsGuest = !isset($_SESSION['user_id']);
$_headerIsCashier = ($_headerRoleSlug === 'cashier');
$_headerIsAdmin = in_array($_headerRoleSlug, ['admin', 'super_admin', 'manager'], true);
$_headerRoleName = $_SESSION['role_name'] ?? 'Guest';

// ── Demo / Survey Mode ──────────────────────────────────────────────────────
// A "demo viewer" role can browse but cannot change any data.
$_headerIsDemo = ($_headerRoleSlug === 'demo viewer');

if ($_headerIsDemo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kill the request immediately – demo users cannot submit changes
    http_response_code(403);
    // If it looks like an AJAX / JSON request, return JSON
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
              str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false,
                          'message' => 'This is a read-only demo account. No changes can be saved.']);
        exit;
    }
    // Otherwise bounce back to the referring page with a flash
    $_SESSION['demo_blocked'] = true;
    $ref = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
    header('Location: ' . $ref);
    exit;
}
?>

<!-- Preconnect to CDNs for faster asset loading -->
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">

<script>
(function () {
  const faviconPath = 'logo-removebg-preview.png';
  let icon = document.querySelector("link[rel='icon']");
  if (!icon) {
    icon = document.createElement('link');
    icon.rel = 'icon';
    document.head.appendChild(icon);
  }
  icon.type = 'image/png';
  icon.href = faviconPath;

  let shortcutIcon = document.querySelector("link[rel='shortcut icon']");
  if (!shortcutIcon) {
    shortcutIcon = document.createElement('link');
    shortcutIcon.rel = 'shortcut icon';
    document.head.appendChild(shortcutIcon);
  }
  shortcutIcon.type = 'image/png';
  shortcutIcon.href = faviconPath;
})();
</script>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <button class="sidebar-close" onclick="closeSidebar()" aria-label="Close menu">
      <i class="fas fa-times"></i>
    </button>
  </div>

  <?php if (!$_headerIsGuest): ?>
  <div class="sidebar-user sidebar-user-top">
    <div class="sidebar-user-avatar"><i class="fas fa-user-circle"></i></div>
    <div class="sidebar-user-info">
      <span class="sidebar-user-name"><?php echo htmlspecialchars($_headerUser); ?></span>
      <span class="sidebar-user-role"><?php echo htmlspecialchars($_headerRoleName); ?></span>
    </div>
  </div>
  <?php else: ?>
  <div class="sidebar-user sidebar-user-top">
    <div class="sidebar-user-avatar"><i class="fas fa-user-circle"></i></div>
    <div class="sidebar-user-info">
      <span class="sidebar-user-name">Guest</span>
      <span class="sidebar-user-role"><a href="login.php" style="color:rgba(255,255,255,0.8);text-decoration:none;">Login / Register</a></span>
    </div>
  </div>
  <?php endif; ?>

  <div class="sidebar-nav">
    <?php if ($_headerIsGuest): ?>
    <!-- Guest: minimal nav -->
    <div class="sidebar-section-label">Browse</div>
    <a href="onlineordering.php" class="sidebar-link <?php echo $current_page === 'onlineordering.php' ? 'active' : ''; ?>">
      <i class="fas fa-cart-shopping"></i><span>Online Ordering</span>
    </a>
    <div class="sidebar-section-label">Account</div>
    <a href="login.php" class="sidebar-link">
      <i class="fas fa-sign-in-alt"></i><span>Login</span>
    </a>

    <?php elseif ($_headerIsCustomer): ?>
    <!-- Customer nav -->
    <div class="sidebar-section-label">Your Services</div>
    <a href="onlineordering.php" class="sidebar-link <?php echo $current_page === 'onlineordering.php' ? 'active' : ''; ?>">
      <i class="fas fa-cart-shopping"></i><span>Online Ordering</span>
    </a>
    <a href="order_status.php" class="sidebar-link <?php echo $current_page === 'order_status.php' ? 'active' : ''; ?>">
      <i class="fas fa-receipt"></i><span>Order Status</span>
    </a>
    <a href="loyalty_qr.php" class="sidebar-link <?php echo $current_page === 'loyalty_qr.php' ? 'active' : ''; ?>">
      <i class="fas fa-gift"></i><span>Loyalty & QR</span>
    </a>

    <?php elseif ($_headerIsCashier): ?>
    <!-- Cashier: limited operations -->
    <div class="sidebar-section-label">Operations</div>
    <a href="dashboard.php" class="sidebar-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
      <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
    </a>
    <a href="pos.php" class="sidebar-link <?php echo $current_page === 'pos.php' ? 'active' : ''; ?>">
      <i class="fas fa-cash-register"></i><span>Point of Sale</span>
    </a>
    <a href="medicine-locator.php" class="sidebar-link <?php echo ($current_page === 'medicine-locator.php' || $current_page === 'expiry-monitoring.php') ? 'active' : ''; ?>">
      <i class="fas fa-search-location"></i><span>Medicine Locator &amp; Expiry Monitoring</span>
    </a>
    <div class="sidebar-section-label">Customer Services</div>
    <a href="onlineordering.php" class="sidebar-link <?php echo $current_page === 'onlineordering.php' ? 'active' : ''; ?>">
      <i class="fas fa-cart-shopping"></i><span>Online Ordering</span>
    </a>
    <a href="order_status.php" class="sidebar-link <?php echo $current_page === 'order_status.php' ? 'active' : ''; ?>">
      <i class="fas fa-receipt"></i><span>Order Status</span>
    </a>

    <?php else: ?>
    <!-- Admin / Manager: full nav (no supplier_management) -->
    <div class="sidebar-section-label">Operations</div>
    <a href="dashboard.php" class="sidebar-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
      <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
    </a>
    <a href="pos.php" class="sidebar-link <?php echo $current_page === 'pos.php' ? 'active' : ''; ?>">
      <i class="fas fa-cash-register"></i><span>Point of Sale</span>
    </a>
    <a href="inventory_management.php" class="sidebar-link <?php echo $current_page === 'inventory_management.php' ? 'active' : ''; ?>">
      <i class="fas fa-boxes-stacked"></i><span>Inventory</span>
    </a>
    <a href="medicine-locator.php" class="sidebar-link <?php echo ($current_page === 'medicine-locator.php' || $current_page === 'expiry-monitoring.php') ? 'active' : ''; ?>">
      <i class="fas fa-search-location"></i><span>Medicine Locator &amp; Expiry Monitoring</span>
    </a>

    <div class="sidebar-section-label">Customer Services</div>
    <a href="onlineordering.php" class="sidebar-link <?php echo $current_page === 'onlineordering.php' ? 'active' : ''; ?>">
      <i class="fas fa-cart-shopping"></i><span>Online Ordering</span>
    </a>
    <a href="order_status.php" class="sidebar-link <?php echo $current_page === 'order_status.php' ? 'active' : ''; ?>">
      <i class="fas fa-receipt"></i><span>Order Status</span>
    </a>
    <a href="loyalty_qr.php" class="sidebar-link <?php echo $current_page === 'loyalty_qr.php' ? 'active' : ''; ?>">
      <i class="fas fa-gift"></i><span>Loyalty & QR</span>
    </a>

    <div class="sidebar-section-label">Administration</div>
    <a href="reports.php" class="sidebar-link <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
      <i class="fas fa-chart-bar"></i><span>Reports & Analytics</span>
    </a>
    <a href="user_management.php" class="sidebar-link <?php echo $current_page === 'user_management.php' ? 'active' : ''; ?>">
      <i class="fas fa-user-shield"></i><span>Users & Access</span>
    </a>
    <a href="settings_enhanced.php" class="sidebar-link <?php echo ($current_page === 'settings.php' || $current_page === 'settings_enhanced.php') ? 'active' : ''; ?>">
      <i class="fas fa-gear"></i><span>System Settings</span>
    </a>
    <?php endif; ?>
  </div>

  <div class="sidebar-footer">
    <?php if ($_headerIsGuest): ?>
    <a href="login.php" class="sidebar-link sidebar-logout">
      <i class="fas fa-sign-in-alt"></i><span>Login</span>
    </a>
    <?php else: ?>
    <a href="logout.php" class="sidebar-link sidebar-logout">
      <i class="fas fa-right-from-bracket"></i><span>Logout</span>
    </a>
    <?php endif; ?>
  </div>
</nav>

<!-- Top Header Bar -->
<header class="topbar" id="topbar">
  <div class="topbar-left">
    <button class="topbar-hamburger" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="Open menu">
      <i class="fas fa-bars"></i>
    </button>
    <a href="dashboard.php" class="topbar-brand-link">
      <img src="logo-removebg-preview.png" alt="Logo" class="topbar-logo-img" width="34" height="34">
      <div class="topbar-brand-text">
        <span class="topbar-brand-name">Calloway Pharmacy</span>
        <span class="topbar-brand-sub">Integrated Management System</span>
      </div>
    </a>
  </div>

  <div class="topbar-center" id="topbarCenter">
    <!-- Content injected by specific pages (Search bar etc) -->
  </div>

  <div class="topbar-right">
    <?php if (!$_headerIsGuest && !$_headerIsCustomer): ?>
    <!-- Notification bell for staff -->
    <button class="topbar-notification-btn" id="notifBellBtn" onclick="toggleNotifPanel()" aria-label="Notifications">
      <i class="fas fa-bell"></i>
      <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
    </button>
    <?php endif; ?>

    <div id="topbarRightExtras">
       <!-- Cart/Wishlist injected here by pages -->
    </div>

    <button class="topbar-theme-toggle" id="themeToggleBtn" onclick="toggleTheme()" aria-label="Toggle theme">
      <i class="fas fa-moon" id="themeIcon"></i>
    </button>
  </div>
</header>
<style>
/* ── Premium Topbar ──────────────────────────────────────── */
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 1.25rem;
    height: 56px;
    background: var(--header-bg);
    color: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}
.topbar-left, .topbar-right {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.topbar-brand-link {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    text-decoration: none;
    color: white;
    white-space: nowrap;
    min-width: 0;
}
.topbar-brand-link:hover { opacity: 0.92; transform: translate3d(0, 0, 0); }
.topbar-logo-img {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    object-fit: contain;
    flex-shrink: 0;
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.topbar-brand-link:hover .topbar-logo-img {
    transform: scale(1.06) rotate(-2deg);
}
.topbar-brand-text {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
    min-width: 0;
}
.topbar-brand-name {
    font-size: 1.15rem;
    font-weight: 800;
    letter-spacing: -0.01em;
    overflow: hidden;
    text-overflow: ellipsis;
}
.topbar-brand-sub {
    font-size: 0.65rem;
    font-weight: 500;
    opacity: 0.7;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    overflow: hidden;
    text-overflow: ellipsis;
}
#topbarRightExtras {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-wrap: nowrap;
}
.topbar-center {
    flex: 1;
    display: flex;
    justify-content: center;
    padding: 0 0.75rem;
    min-width: 0;
}
.topbar-hamburger {
    background: none;
    border: none;
    color: white;
    font-size: 1.25rem;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 8px;
    transition: background 0.15s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
}
.topbar-hamburger:hover { background: rgba(255,255,255,0.12); }

.topbar-notification-btn, .topbar-theme-toggle {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    color: white;
    cursor: pointer;
    font-size: 1rem;
    padding: 0;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s cubic-bezier(0.0, 0.0, 0.2, 1);
}
.topbar-notification-btn:hover, .topbar-theme-toggle:hover {
    background: rgba(255,255,255,0.18);
    transform: scale(1.08);
}
.topbar-notification-btn:active, .topbar-theme-toggle:active {
    transform: scale(0.95);
    transition-duration: 80ms;
}
.notif-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    background: #ef4444;
    color: white;
    border-radius: 50%;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.65rem;
    font-weight: 700;
    border: 2px solid var(--header-bg);
    line-height: 1;
}

/* ── Premium Sidebar ─────────────────────────────────────── */
.sidebar-logo-img {
  width: 32px;
  height: 32px;
  border-radius: 6px;
  object-fit: contain;
  flex-shrink: 0;
  background: rgba(255,255,255,0.9);
  padding: 2px;
}

/* On mobile: hide brand text (for compactness), show hamburger */
@media (max-width: 768px) {
  .topbar-right { gap: 0.3rem; }
  #topbarRightExtras { gap: 0.25rem; }
  .topbar-brand-sub { display: none; }
  .topbar-brand-name { font-size: 0.95rem; }
}

/* Very small screens: hide text entirely, logo only */
@media (max-width: 400px) {
  .topbar-brand-text { display: none; }
}

/* Desktop shell: sidebar + content in CSS grid */
@media (min-width: 769px) {
  html, body {
    margin: 0 !important;
    padding: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: clip !important;
  }

  body {
    display: grid !important;
    grid-template-columns: 260px minmax(0, 1fr) !important;
    grid-auto-rows: auto !important;
    min-height: 100vh !important;
  }

  #sidebar,
  .sidebar {
    grid-column: 1 !important;
    grid-row: 1 / span 100 !important;
    position: sticky !important;
    top: 0 !important;
    left: auto !important;
    width: 260px !important;
    height: 100vh !important;
    transform: none !important;
    margin: 0 !important;
    overflow-y: hidden !important;
    z-index: 20 !important;
    align-self: start !important;
    border-right: 1px solid var(--c-border, var(--divider-color));
    box-shadow: none !important;
  }

  .sidebar-nav {
    overflow-y: auto !important;
    max-height: none !important;
  }

  .sidebar-nav::-webkit-scrollbar { width: 0; }

  #sidebarOverlay,
  .sidebar-overlay,
  .sidebar-close,
  .topbar-hamburger {
    display: none !important;
  }

  .sidebar-header {
    display: none !important;
    height: 0 !important;
    min-height: 0 !important;
    max-height: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 0 !important;
    overflow: hidden !important;
  }

  .sidebar-user-top {
    margin: 0 !important;
    height: 56px !important;
    min-height: 56px !important;
    max-height: 56px !important;
    border-radius: 0 !important;
  }

  #topbar,
  .topbar {
    grid-column: 2 !important;
    position: sticky !important;
    top: 0 !important;
    left: auto !important;
    width: 100% !important;
    margin: 0 !important;
    z-index: 100 !important;
  }

  body > *:not(#sidebar):not(.sidebar):not(#sidebarOverlay):not(.sidebar-overlay) {
    grid-column: 2 !important;
    min-width: 0 !important;
  }

  main {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding-top: 1.25rem !important;
    padding-left: 1.5rem !important;
    padding-right: 1.5rem !important;
  }
}
</style>

<?php if (!$_headerIsGuest && !$_headerIsCustomer): ?>
<link rel="stylesheet" href="notification-tray.css">
<script src="notification-tray.js" defer></script>
<?php endif; ?>

<?php if ($_headerIsDemo): ?>
<!-- ═══ DEMO / SURVEY MODE BANNER + JS GUARD ════════════════════════════ -->
<style>
  #demo-mode-banner {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 99999;
    background: #6d28d9;
    color: #fff;
    display: flex; align-items: center; justify-content: center; gap: 0.65rem;
    padding: 0.55rem 1rem;
    font-family: system-ui, sans-serif;
    font-size: 0.85rem;
    font-weight: 600;
    box-shadow: 0 -2px 16px rgba(79,70,229,.35);
    user-select: none;
  }
  #demo-mode-banner .dmb-icon { font-size: 1rem; }
  #demo-mode-banner .dmb-text { opacity: .95; }
  #demo-mode-banner .dmb-badge {
    background: rgba(255,255,255,.18);
    border-radius: 999px;
    padding: .15rem .6rem;
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  /* Dim every interactive element in demo mode */
  .demo-mode-locked button:not(#demo-mode-dismiss):not(.sidebar-close):not(#sidebar-toggle),
  .demo-mode-locked input[type=submit],
  .demo-mode-locked input[type=button] {
    opacity: 0.55 !important;
    cursor: not-allowed !important;
    pointer-events: auto !important;  /* we still need click to show toast */
  }

  /* Nudge bottom so banner doesn't hide content */
  body.demo-mode-locked { padding-bottom: 46px !important; }

  /* Demo block toast */
  #demo-toast {
    position: fixed;
    bottom: 60px;
    left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: #1e1b4b;
    color: #fff;
    padding: .6rem 1.4rem;
    border-radius: 8px;
    font-size: .88rem;
    font-weight: 600;
    box-shadow: 0 4px 20px rgba(0,0,0,.35);
    z-index: 100000;
    opacity: 0;
    transition: opacity .25s, transform .25s;
    pointer-events: none;
    white-space: nowrap;
  }
  #demo-toast.show {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
  }
</style>

<!-- Banner -->
<div id="demo-mode-banner">
  <span class="dmb-icon">🔒</span>
  <span class="dmb-text">You are in <strong>Read-Only Demo Mode</strong> — all changes are disabled.</span>
  <span class="dmb-badge">Survey / Test Account</span>
</div>
<div id="demo-toast">🔒 Read-only demo — changes are not allowed</div>

<script>
(function () {
  'use strict';

  document.body.classList.add('demo-mode-locked');

  /* ── Intercept ALL form submissions ─────────────────────── */
  document.addEventListener('submit', function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    showDemoToast();
  }, true);

  /* ── Intercept fetch / XMLHttpRequest for mutating methods ─ */
  const _fetch = window.fetch;
  window.fetch = function (url, opts) {
    opts = opts || {};
    const method = (opts.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      showDemoToast();
      return Promise.resolve(new Response(
        JSON.stringify({ success: false, message: 'Read-only demo account.' }),
        { status: 403, headers: { 'Content-Type': 'application/json' } }
      ));
    }
    return _fetch.apply(this, arguments);
  };

  const _open = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method) {
    this._demoMethod = method.toUpperCase();
    return _open.apply(this, arguments);
  };
  const _send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function () {
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(this._demoMethod)) {
      showDemoToast();
      return; // swallow the request
    }
    return _send.apply(this, arguments);
  };

  /* ── Block clicks on write action buttons ────────────────── */
  document.addEventListener('click', function (e) {
    const el = e.target.closest('button, [type=submit], [type=button], .btn-action, .btn-save, .btn-delete, .btn-edit');
    if (!el) return;
    // Allow navigation/view buttons by checking text
    const text = (el.textContent || el.value || '').toLowerCase();
    const safe = ['view', 'close', 'cancel', 'back', 'print', 'search', 'filter', 'refresh',
                  'logout', 'sign out', 'open', 'details', 'show'];
    if (safe.some(s => text.includes(s))) return;
    // Allow sidebar toggle and demo banner
    if (el.id === 'demo-mode-dismiss' ||
        el.classList.contains('sidebar-close') ||
        el.id === 'sidebar-toggle' ||
        el.closest('#sidebar') ||
        el.closest('#topbar')) return;
    // If it looks like a write action, block it
    const writeHints = ['save', 'add', 'create', 'delete', 'remove', 'update', 'submit',
                        'confirm', 'approve', 'reject', 'edit', 'reset', 'clear'];
    if (writeHints.some(h => text.includes(h)) || el.type === 'submit') {
      e.preventDefault();
      e.stopImmediatePropagation();
      showDemoToast();
    }
  }, true);

  /* ── Toast helper ─────────────────────────────────────────── */
  let _toastTimer;
  function showDemoToast() {
    const t = document.getElementById('demo-toast');
    if (!t) return;
    t.classList.add('show');
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => t.classList.remove('show'), 2800);
  }

  /* ── Flash if PHP already blocked a POST last cycle ─────── */
  <?php if (!empty($_SESSION['demo_blocked'])): unset($_SESSION['demo_blocked']); ?>
  document.addEventListener('DOMContentLoaded', function () { showDemoToast(); });
  <?php endif; ?>

})();
</script>
<?php endif; ?>