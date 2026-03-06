<?php
/**
 * User Management System
 * Manage users, roles, permissions
 */

require_once 'db_connection.php';
require_once 'Auth.php';

$auth = new Auth($conn);
$auth->requireAuth('login.php');

if (!$auth->hasPermission('users.view')) {
    die('<h1>Access Denied</h1><p>You do not have permission to access user management.</p>');
}

$currentUser = $auth->getCurrentUser();
$page_title = 'User Management';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <script>
    // Apply theme immediately to prevent flash
    (function() {
      const theme = localStorage.getItem('calloway_theme') || 'light';
      document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Calloway Pharmacy</title>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="shared-polish.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="custom-modal.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="custom-modal.js?v=2"></script>
    <style>
        /* ═══════════════════════════════════════════════════════════
           Users & Access — Premium Redesign
           Matches Calloway Pharmacy Design System v2.0
           ═══════════════════════════════════════════════════════════ */

        /* ── Layout ─────────────────────────────────────────────── */
        .um-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.25rem 1.25rem 2.5rem;
        }

        /* ── Page Header ────────────────────────────────────────── */
        .um-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.75rem;
            background: var(--card-bg, #fff);
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius-xl, 16px);
            border: 1px solid var(--input-border, #e2e8f0);
            box-shadow: var(--shadow-sm);
        }
        .um-header-left {
            display: flex; align-items: center; gap: 0.75rem;
        }
        .um-header-icon {
            width: 42px; height: 42px; border-radius: var(--radius-lg, 12px);
            background: linear-gradient(135deg, var(--primary-color, #2563eb), #7c3aed);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.1rem;
            box-shadow: 0 4px 12px rgba(37,99,235,0.25);
        }
        .um-header h1 {
            font-size: 1.4rem; font-weight: 800; margin: 0;
            color: var(--text-color);
        }
        .um-header h1 small {
            display: block; font-size: 0.78rem; font-weight: 500;
            color: var(--text-light, #64748b); margin-top: 2px;
        }
        .um-btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.65rem 1.25rem; border: none; border-radius: var(--radius-md, 10px);
            font-weight: 600; font-size: 0.88rem; cursor: pointer;
            transition: all 0.2s var(--ease-out, ease);
        }
        .um-btn-primary {
            background: var(--primary-color, #2563eb); color: #fff;
            box-shadow: var(--shadow-sm);
        }
        .um-btn-primary:hover {
            filter: brightness(0.92); transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        .um-btn-secondary {
            background: var(--bg-color, #f1f5f9); color: var(--text-color);
            border: 1px solid var(--input-border, #e2e8f0);
        }
        .um-btn-secondary:hover {
            border-color: var(--primary-color); color: var(--primary-color);
            transform: translateY(-1px);
        }
        .um-btn-danger {
            background: var(--danger-color, #ef4444); color: #fff;
        }
        .um-btn-danger:hover { filter: brightness(0.9); }
        .um-btn-sm {
            padding: 0.4rem 0.75rem; font-size: 0.8rem; border-radius: var(--radius-sm, 6px);
        }
        .um-btn-icon {
            width: 32px; height: 32px; padding: 0; border-radius: var(--radius-md, 8px);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 0.85rem; border: 1px solid var(--input-border, #e2e8f0);
            background: var(--card-bg, #fff); color: var(--text-color);
            cursor: pointer; transition: all 0.15s;
        }
        .um-btn-icon:hover {
            border-color: var(--primary-color); color: var(--primary-color);
            transform: translateY(-1px); box-shadow: var(--shadow-sm);
        }
        .um-btn-icon.danger:hover {
            border-color: var(--danger-color, #ef4444); color: var(--danger-color, #ef4444);
            background: rgba(239,68,68,0.06);
        }

        /* ── Stats Row ──────────────────────────────────────────── */
        .um-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem; margin-bottom: 1.75rem;
        }
        .um-stat {
            background: var(--card-bg, #fff);
            border: 1px solid var(--input-border, #e2e8f0);
            border-radius: var(--radius-xl, 16px);
            padding: 1.25rem 1.25rem;
            display: flex; align-items: center; gap: 1rem;
            box-shadow: var(--shadow-sm);
            transition: transform 0.15s var(--ease-out, ease), box-shadow 0.15s;
            position: relative; overflow: hidden;
        }
        .um-stat:hover {
            transform: translateY(-2px); box-shadow: var(--shadow-md);
        }
        .um-stat::after {
            content: ''; position: absolute; top: 0; right: 0;
            width: 80px; height: 80px; border-radius: 50%;
            opacity: 0.06; pointer-events: none;
            transform: translate(25%, -25%);
        }
        .um-stat:nth-child(1)::after { background: var(--primary-color, #2563eb); }
        .um-stat:nth-child(2)::after { background: #10b981; }
        .um-stat:nth-child(3)::after { background: #8b5cf6; }
        .um-stat:nth-child(4)::after { background: #f59e0b; }

        .um-stat-icon {
            width: 46px; height: 46px; border-radius: var(--radius-lg, 12px);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem; flex-shrink: 0;
        }
        .um-stat-icon.blue { background: rgba(37,99,235,0.1); color: #2563eb; }
        .um-stat-icon.green { background: rgba(16,185,129,0.1); color: #10b981; }
        .um-stat-icon.purple { background: rgba(139,92,246,0.1); color: #8b5cf6; }
        .um-stat-icon.amber { background: rgba(245,158,11,0.1); color: #f59e0b; }

        .um-stat-value {
            font-size: 1.65rem; font-weight: 800; line-height: 1.1;
            color: var(--text-color);
            font-variant-numeric: tabular-nums;
        }
        .um-stat-label {
            font-size: 0.8rem; color: var(--text-light, #64748b);
            font-weight: 500; margin-top: 2px;
        }

        /* ── Toolbar: Search + Tabs ─────────────────────────────── */
        .um-toolbar {
            display: flex; justify-content: space-between; align-items: center;
            gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap;
        }
        .um-tabs {
            display: flex; gap: 0.25rem;
            background: var(--bg-color, #f1f5f9);
            padding: 4px; border-radius: var(--radius-lg, 12px);
            border: 1px solid var(--input-border, #e2e8f0);
        }
        .um-tab {
            padding: 0.55rem 1.25rem; border: none; border-radius: var(--radius-md, 8px);
            background: transparent; color: var(--text-light, #64748b);
            font-weight: 600; font-size: 0.85rem; cursor: pointer;
            transition: all 0.2s var(--ease-out, ease);
            display: flex; align-items: center; gap: 0.4rem;
        }
        .um-tab:hover { color: var(--text-color); }
        .um-tab.active {
            background: var(--card-bg, #fff); color: var(--primary-color, #2563eb);
            box-shadow: var(--shadow-sm);
        }
        .um-tab .badge {
            background: var(--primary-color, #2563eb); color: #fff;
            font-size: 0.7rem; padding: 1px 6px; border-radius: var(--radius-full, 999px);
            font-weight: 700; min-width: 20px; text-align: center;
        }
        .um-tab.active .badge { background: var(--primary-color, #2563eb); }

        .um-search {
            position: relative; flex: 0 1 320px;
        }
        .um-search i {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: var(--text-light, #94a3b8); font-size: 0.85rem;
        }
        .um-search input {
            width: 100%; padding: 0.6rem 0.75rem 0.6rem 2.25rem;
            border: 1px solid var(--input-border, #e2e8f0);
            border-radius: var(--radius-md, 10px);
            font-size: 0.88rem; background: var(--card-bg, #fff);
            color: var(--text-color);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .um-search input:focus {
            outline: none; border-color: var(--primary-color, #2563eb);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
        }
        .um-search input::placeholder { color: var(--text-light, #94a3b8); }

        /* ── Tab Content ────────────────────────────────────────── */
        .um-panel { display: none; animation: umFadeUp 0.3s var(--ease-out, ease); }
        .um-panel.active { display: block; }
        @keyframes umFadeUp {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Table Card ─────────────────────────────────────────── */
        .um-card {
            background: var(--card-bg, #fff);
            border: 1px solid var(--input-border, #e2e8f0);
            border-radius: var(--radius-xl, 16px);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .um-table {
            width: 100%; border-collapse: separate; border-spacing: 0;
        }
        .um-table thead th {
            padding: 0.85rem 1rem;
            text-align: left;
            font-size: 0.78rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-light, #64748b);
            background: var(--bg-color, #f8fafc);
            border-bottom: 1px solid var(--input-border, #e2e8f0);
            white-space: nowrap;
        }
        .um-table tbody td {
            padding: 0.85rem 1rem;
            font-size: 0.88rem;
            color: var(--text-color);
            border-bottom: 1px solid var(--input-border, #e2e8f0);
            vertical-align: middle;
        }
        .um-table tbody tr {
            transition: background 0.12s;
        }
        .um-table tbody tr:hover {
            background: var(--hover-bg, rgba(37,99,235,0.03));
        }
        .um-table tbody tr:last-child td { border-bottom: none; }

        /* User cell with avatar */
        .um-user-cell {
            display: flex; align-items: center; gap: 0.75rem;
        }
        .um-avatar {
            width: 36px; height: 36px; border-radius: var(--radius-full, 999px);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.82rem; font-weight: 700; color: #fff;
            flex-shrink: 0;
            text-transform: uppercase;
        }
        .um-avatar.av-blue { background: linear-gradient(135deg, #2563eb, #60a5fa); }
        .um-avatar.av-green { background: linear-gradient(135deg, #10b981, #34d399); }
        .um-avatar.av-purple { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
        .um-avatar.av-amber { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .um-avatar.av-rose { background: linear-gradient(135deg, #f43f5e, #fb7185); }
        .um-avatar.av-teal { background: linear-gradient(135deg, #0d9488, #2dd4bf); }

        .um-user-info { display: flex; flex-direction: column; }
        .um-user-name { font-weight: 600; color: var(--text-color); font-size: 0.88rem; }
        .um-user-email { font-size: 0.78rem; color: var(--text-light, #94a3b8); }

        /* Role badges — refined */
        .um-role {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.25rem 0.65rem; border-radius: var(--radius-full, 999px);
            font-size: 0.76rem; font-weight: 600; white-space: nowrap;
        }
        .um-role i { font-size: 0.65rem; }
        .um-role-admin { background: rgba(139,92,246,0.12); color: #7c3aed; }
        .um-role-super_admin { background: rgba(236,72,153,0.12); color: #db2777; }
        .um-role-manager { background: rgba(245,158,11,0.12); color: #d97706; }
        .um-role-cashier { background: rgba(6,182,212,0.12); color: #0891b2; }
        .um-role-customer { background: rgba(16,185,129,0.12); color: #059669; }
        .um-role-default { background: rgba(100,116,139,0.1); color: #475569; }

        /* Status: dot + text */
        .um-status {
            display: inline-flex; align-items: center; gap: 0.4rem;
            font-size: 0.82rem; font-weight: 600;
        }
        .um-status-dot {
            width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
        }
        .um-status-active .um-status-dot { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.2); }
        .um-status-active { color: #059669; }
        .um-status-inactive .um-status-dot { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.15); }
        .um-status-inactive { color: #dc2626; }

        /* Actions cell */
        .um-actions { display: flex; gap: 0.4rem; }

        /* ── Roles Tab: Card Grid ───────────────────────────────── */
        .um-roles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1rem;
        }
        .um-role-card {
            background: var(--card-bg, #fff);
            border: 1px solid var(--input-border, #e2e8f0);
            border-radius: var(--radius-xl, 16px);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            transition: transform 0.15s, box-shadow 0.15s;
            position: relative; overflow: hidden;
        }
        .um-role-card:hover {
            transform: translateY(-2px); box-shadow: var(--shadow-md);
        }
        .um-role-card-header {
            display: flex; align-items: center; gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .um-role-card-icon {
            width: 40px; height: 40px; border-radius: var(--radius-lg, 12px);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
        }
        .um-role-card-name {
            font-size: 1.05rem; font-weight: 700; color: var(--text-color);
        }
        .um-role-card-desc {
            font-size: 0.82rem; color: var(--text-light, #94a3b8);
        }
        .um-role-card-meta {
            display: flex; gap: 1.5rem; margin-bottom: 1rem;
        }
        .um-role-card-meta-item {
            display: flex; flex-direction: column;
        }
        .um-role-card-meta-value {
            font-size: 1.25rem; font-weight: 700; color: var(--text-color);
        }
        .um-role-card-meta-label {
            font-size: 0.75rem; color: var(--text-light, #94a3b8);
        }
        .um-role-card-footer {
            display: flex; gap: 0.5rem; padding-top: 0.75rem;
            border-top: 1px solid var(--input-border, #e2e8f0);
        }

        /* ── Modal ──────────────────────────────────────────────── */
        .um-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45); backdrop-filter: blur(4px);
            z-index: 9998; opacity: 0;
            transition: opacity 0.3s var(--ease-out, ease);
        }
        .um-overlay.active { display: block; opacity: 1; }

        .um-modal {
            display: none; position: fixed;
            top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.95);
            z-index: 9999; width: 90%; max-width: 560px; max-height: 92vh;
            background: var(--card-bg, #fff);
            border-radius: var(--radius-xl, 16px);
            border: 1px solid var(--input-border, #e2e8f0);
            box-shadow: var(--shadow-2xl, 0 25px 50px -12px rgba(0,0,0,0.15));
            overflow-y: auto;
            opacity: 0;
            transition: opacity 0.25s var(--ease-out, ease), transform 0.25s var(--ease-out, ease);
        }
        .um-modal.active {
            display: block; opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }
        .um-modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--input-border, #e2e8f0);
            position: sticky; top: 0; background: var(--card-bg, #fff);
            z-index: 1;
        }
        .um-modal-header h2 {
            font-size: 1.15rem; font-weight: 700; margin: 0;
            color: var(--text-color);
            display: flex; align-items: center; gap: 0.5rem;
        }
        .um-modal-close {
            width: 32px; height: 32px; border-radius: var(--radius-md, 8px);
            border: 1px solid var(--input-border, #e2e8f0);
            background: var(--bg-color, #f8fafc); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem; color: var(--text-light, #64748b);
            transition: all 0.15s;
        }
        .um-modal-close:hover {
            background: #fee2e2; color: #dc2626; border-color: #fca5a5;
        }
        .um-modal-body { padding: 1.5rem; }

        .um-form-group { margin-bottom: 1.25rem; }
        .um-form-group label {
            display: block; margin-bottom: 0.4rem;
            font-size: 0.82rem; font-weight: 600; color: var(--text-color);
        }
        .um-form-group label .req { color: var(--danger-color, #ef4444); }
        .um-form-group input,
        .um-form-group select {
            width: 100%; padding: 0.7rem 0.85rem;
            border: 1.5px solid var(--input-border, #e2e8f0);
            border-radius: var(--radius-md, 10px);
            font-size: 0.9rem;
            background: var(--bg-color, #f8fafc);
            color: var(--text-color);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .um-form-group input:focus,
        .um-form-group select:focus {
            outline: none;
            border-color: var(--primary-color, #2563eb);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
            background: var(--card-bg, #fff);
        }
        .um-form-row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
        }
        .um-form-actions {
            display: flex; gap: 0.75rem; padding-top: 0.5rem;
        }
        .um-form-actions .um-btn { flex: 1; justify-content: center; }

        /* ── Permissions Modal ───────────────────────────────────── */
        .um-perms-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 0.5rem; max-height: 340px; overflow-y: auto;
            padding: 0.75rem; background: var(--bg-color, #f8fafc);
            border: 1px solid var(--input-border, #e2e8f0);
            border-radius: var(--radius-md, 10px);
        }
        .um-perm-chip {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.35rem 0.65rem; border-radius: var(--radius-sm, 6px);
            font-size: 0.78rem; font-weight: 500;
            background: rgba(37,99,235,0.08); color: var(--primary-color, #2563eb);
            border: 1px solid rgba(37,99,235,0.15);
        }
        .um-perm-chip i { font-size: 0.65rem; }

        /* ── Loading State ──────────────────────────────────────── */
        .um-loading {
            text-align: center; padding: 3rem 2rem;
            color: var(--text-light, #94a3b8);
        }
        .um-loading i { font-size: 1.5rem; margin-bottom: 0.75rem; display: block; }

        /* ── Empty State ────────────────────────────────────────── */
        .um-empty {
            text-align: center; padding: 3rem 2rem;
        }
        .um-empty i {
            font-size: 2.5rem; color: var(--text-light, #cbd5e1);
            margin-bottom: 1rem; display: block;
        }
        .um-empty h3 {
            font-size: 1.05rem; font-weight: 700;
            color: var(--text-color); margin-bottom: 0.25rem;
        }
        .um-empty p { color: var(--text-light, #94a3b8); font-size: 0.88rem; }

        /* ── Responsive ─────────────────────────────────────────── */
        @media (max-width: 768px) {
            .um-container { padding: 1rem; }
            .um-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; padding: 1rem; }
            .um-stats { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
            .um-toolbar { flex-direction: column; align-items: stretch; }
            .um-search { flex: 1 1 100%; }
            .um-form-row { grid-template-columns: 1fr; }
            .um-roles-grid { grid-template-columns: 1fr; }

            /* Responsive table */
            .um-table thead { display: none; }
            .um-table tbody tr {
                display: block;
                border-bottom: 1px solid var(--input-border, #e2e8f0);
                padding: 0.75rem 1rem;
            }
            .um-table tbody td {
                display: flex; justify-content: space-between; align-items: center;
                padding: 0.35rem 0; border: none;
                font-size: 0.85rem;
            }
            .um-table tbody td::before {
                content: attr(data-label);
                font-weight: 600; font-size: 0.78rem;
                color: var(--text-light, #64748b);
                text-transform: uppercase; letter-spacing: 0.03em;
                flex-shrink: 0; margin-right: 1rem;
            }
            .um-table tbody td:first-child { padding-top: 0.5rem; }
        }

        @media (max-width: 480px) {
            .um-stats { grid-template-columns: 1fr; }
            .um-stat { padding: 1rem; }
        }
    </style>
</head>
<body>
    <?php include 'header-component.php'; ?>

    <main>
    <div class="um-container">
        <!-- Page Header -->
        <div class="um-header">
            <div class="um-header-left">
                <div class="um-header-icon"><i class="fa-solid fa-users-gear"></i></div>
                <h1>Users &amp; Access<small>Manage accounts, roles and permissions</small></h1>
            </div>
            <?php if ($auth->hasPermission('users.create')): ?>
            <button class="um-btn um-btn-primary" onclick="openAddUserModal()">
                <i class="fa-solid fa-user-plus"></i> Add User
            </button>
            <?php endif; ?>
        </div>

        <!-- Stat Cards -->
        <div class="um-stats">
            <div class="um-stat">
                <div class="um-stat-icon blue"><i class="fa-solid fa-users"></i></div>
                <div><div class="um-stat-value" id="totalUsers">0</div><div class="um-stat-label">Total Users</div></div>
            </div>
            <div class="um-stat">
                <div class="um-stat-icon green"><i class="fa-solid fa-user-check"></i></div>
                <div><div class="um-stat-value" id="activeUsers">0</div><div class="um-stat-label">Active Users</div></div>
            </div>
            <div class="um-stat">
                <div class="um-stat-icon purple"><i class="fa-solid fa-shield-halved"></i></div>
                <div><div class="um-stat-value" id="totalRoles">0</div><div class="um-stat-label">User Roles</div></div>
            </div>
            <div class="um-stat">
                <div class="um-stat-icon amber"><i class="fa-solid fa-key"></i></div>
                <div><div class="um-stat-value" id="totalPermissions">—</div><div class="um-stat-label">Permissions</div></div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="um-toolbar">
            <div class="um-tabs">
                <button class="um-tab active" data-tab="users" onclick="switchTab('users', this)">
                    <i class="fa-solid fa-user"></i> Users <span class="badge" id="usersBadge">0</span>
                </button>
                <button class="um-tab" data-tab="roles" onclick="switchTab('roles', this)">
                    <i class="fa-solid fa-shield"></i> Roles <span class="badge" id="rolesBadge">0</span>
                </button>
            </div>
            <div class="um-search" id="searchWrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="searchInput" placeholder="Search users..." oninput="filterTable()">
            </div>
        </div>

        <!-- Users Tab -->
        <div class="um-panel active" id="usersTab">
            <div class="um-card">
                <table class="um-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Employee</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr><td colspan="6"><div class="um-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading users&hellip;</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Roles Tab -->
        <div class="um-panel" id="rolesTab">
            <div class="um-roles-grid" id="rolesGrid">
                <div class="um-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading roles&hellip;</div>
            </div>
        </div>
    </div>
    </main>

    <!-- User Modal Overlay -->
    <div class="um-overlay" id="userOverlay" onclick="closeUserModal()"></div>

    <!-- Add / Edit User Modal -->
    <div class="um-modal" id="userModal">
        <div class="um-modal-header">
            <h2><i class="fa-solid fa-user-pen"></i> <span id="userModalTitle">Add User</span></h2>
            <button class="um-modal-close" onclick="closeUserModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="um-modal-body">
            <form id="userForm" onsubmit="saveUser(event)">
                <input type="hidden" id="userId">

                <div class="um-form-row">
                    <div class="um-form-group">
                        <label for="username">Username <span class="req">*</span></label>
                        <input type="text" id="username" required>
                    </div>
                    <div class="um-form-group">
                        <label for="email">Email <span class="req">*</span></label>
                        <input type="email" id="email" required>
                    </div>
                </div>

                <div class="um-form-group" id="passwordGroup">
                    <label for="password">Password <span class="req">*</span></label>
                    <input type="password" id="password" autocomplete="new-password">
                </div>

                <div class="um-form-group">
                    <label for="fullName">Full Name</label>
                    <input type="text" id="fullName">
                </div>

                <div class="um-form-row">
                    <div class="um-form-group">
                        <label for="employeeId">Employee <span class="req">*</span></label>
                        <select id="employeeId" required>
                            <option value="">Select Employee…</option>
                        </select>
                    </div>
                    <div class="um-form-group">
                        <label for="userRole">Role <span class="req">*</span></label>
                        <select id="userRole" required>
                            <option value="">Select Role…</option>
                        </select>
                    </div>
                </div>

                <div class="um-form-group">
                    <label for="userStatus">Status <span class="req">*</span></label>
                    <select id="userStatus" required>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>

                <div class="um-form-actions">
                    <button type="submit" class="um-btn um-btn-primary"><i class="fa-solid fa-check"></i> Save User</button>
                    <button type="button" class="um-btn um-btn-secondary" onclick="closeUserModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Permissions Modal Overlay -->
    <div class="um-overlay" id="permsOverlay" onclick="closePermsModal()"></div>

    <!-- Permissions Modal -->
    <div class="um-modal" id="permsModal" style="max-width:640px">
        <div class="um-modal-header">
            <h2><i class="fa-solid fa-key"></i> <span id="permsModalTitle">Role Permissions</span></h2>
            <button class="um-modal-close" onclick="closePermsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="um-modal-body">
            <div id="permsContent">
                <div class="um-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading…</div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast"><span id="toastMessage"></span></div>

    <script src="theme.js"></script>
    <script>
        /* ═══════════════════════════════════════════════════════════
           Users & Access — JavaScript
           ═══════════════════════════════════════════════════════════ */
        let currentTab = 'users';
        let _allUsers = [];

        const AVATAR_COLORS = ['av-blue','av-green','av-purple','av-amber','av-rose','av-teal'];
        const ROLE_ICONS = {
            'admin': 'fa-user-shield', 'super_admin': 'fa-crown', 'manager': 'fa-briefcase',
            'cashier': 'fa-cash-register', 'customer': 'fa-user', 'inventory': 'fa-boxes-stacked'
        };

        function getAvatarColor(name) {
            let hash = 0;
            for (let i = 0; i < (name||'').length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
            return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
        }
        function getInitials(name) {
            if (!name) return '?';
            const parts = name.trim().split(/\s+/);
            return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
        }
        function getRoleSlug(name) { return (name||'').toLowerCase().replace(/\s+/g, '_'); }

        // ── Init ─────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            loadStats();
            loadUsers();
            loadRoles();
            loadRolesForSelect();
            loadEmployeesForSelect();
        });

        // ── Tab Switch ───────────────────────────────────────────
        function switchTab(tab, btn) {
            currentTab = tab;
            document.querySelectorAll('.um-tab').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.um-panel').forEach(p => p.classList.remove('active'));
            document.getElementById(tab + 'Tab').classList.add('active');
            // Toggle search visibility
            document.getElementById('searchWrapper').style.display = tab === 'users' ? '' : 'none';
            if (tab === 'users') loadUsers();
            else loadRoles();
        }

        // ── Stats ────────────────────────────────────────────────
        async function loadStats() {
            try {
                const response = await fetch('user_api.php?action=get_stats');
                const data = await response.json();
                if (data.success) {
                    animateValue('totalUsers', data.data.total_users);
                    animateValue('activeUsers', data.data.active_users);
                    animateValue('totalRoles', data.data.total_roles);
                    document.getElementById('usersBadge').textContent = data.data.total_users;
                    document.getElementById('rolesBadge').textContent = data.data.total_roles;
                }
            } catch (error) { console.error('Error loading stats:', error); }
        }

        function animateValue(id, end) {
            const el = document.getElementById(id);
            const start = parseInt(el.textContent) || 0;
            if (start === end) { el.textContent = end; return; }
            const duration = 400;
            const startTime = performance.now();
            function tick(now) {
                const elapsed = now - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                el.textContent = Math.round(start + (end - start) * eased);
                if (progress < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        }

        // ── Users ────────────────────────────────────────────────
        async function loadUsers() {
            try {
                const response = await fetch('user_api.php?action=get_users');
                const data = await response.json();
                _allUsers = (data.success && data.data) ? data.data : [];
                renderUsers(_allUsers);
            } catch (error) { console.error('Error loading users:', error); }
        }

        function renderUsers(users) {
            const tbody = document.getElementById('usersTableBody');
            if (users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6"><div class="um-empty"><i class="fa-solid fa-user-slash"></i><h3>No users found</h3><p>Try adjusting your search or add a new user.</p></div></td></tr>';
                return;
            }
            tbody.innerHTML = users.map(user => {
                const slug = getRoleSlug(user.role_name);
                const roleClass = 'um-role-' + slug;
                const roleIcon = ROLE_ICONS[slug] || 'fa-user-tag';
                const statusCls = user.is_active == 1 ? 'um-status-active' : 'um-status-inactive';
                const statusTxt = user.is_active == 1 ? 'Active' : 'Inactive';
                const displayName = user.full_name || user.username;
                const avColor = getAvatarColor(displayName);
                const initials = getInitials(displayName);
                const empName = user.employee_name ? escapeHtml(user.employee_name) : '<span style="color:var(--text-light,#94a3b8)">Unlinked</span>';
                const lastLogin = user.last_login ? formatDateTime(user.last_login) : '<span style="color:var(--text-light,#94a3b8)">Never</span>';

                return `<tr>
                    <td data-label="User">
                        <div class="um-user-cell">
                            <div class="um-avatar ${avColor}">${initials}</div>
                            <div class="um-user-info">
                                <span class="um-user-name">${escapeHtml(user.username)}</span>
                                <span class="um-user-email">${escapeHtml(user.email || '')}</span>
                            </div>
                        </div>
                    </td>
                    <td data-label="Employee">${empName}</td>
                    <td data-label="Role"><span class="um-role ${roleClass}"><i class="fa-solid ${roleIcon}"></i> ${escapeHtml(user.role_name)}</span></td>
                    <td data-label="Status"><span class="um-status ${statusCls}"><span class="um-status-dot"></span>${statusTxt}</span></td>
                    <td data-label="Last Login">${lastLogin}</td>
                    <td data-label="Actions" style="text-align:right">
                        <div class="um-actions" style="justify-content:flex-end">
                            <?php if ($auth->hasPermission('users.edit')): ?>
                            <button class="um-btn-icon" title="Edit user" onclick="editUser(${user.user_id})"><i class="fa-solid fa-pen-to-square"></i></button>
                            <?php endif; ?>
                            <?php if ($auth->hasPermission('users.delete')): ?>
                            <button class="um-btn-icon danger" title="Delete user" onclick="deleteUser(${user.user_id})"><i class="fa-solid fa-trash-can"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        // ── Search / Filter ──────────────────────────────────────
        function filterTable() {
            const q = document.getElementById('searchInput').value.toLowerCase().trim();
            if (!q) { renderUsers(_allUsers); return; }
            const filtered = _allUsers.filter(u =>
                (u.username||'').toLowerCase().includes(q) ||
                (u.email||'').toLowerCase().includes(q) ||
                (u.full_name||'').toLowerCase().includes(q) ||
                (u.role_name||'').toLowerCase().includes(q) ||
                (u.employee_name||'').toLowerCase().includes(q)
            );
            renderUsers(filtered);
        }

        // ── Roles ────────────────────────────────────────────────
        async function loadRoles() {
            try {
                const response = await fetch('user_api.php?action=get_roles');
                const data = await response.json();
                const grid = document.getElementById('rolesGrid');
                if (data.success && data.data.length > 0) {
                    let totalPerms = 0;
                    grid.innerHTML = data.data.map(role => {
                        totalPerms += parseInt(role.permissions_count) || 0;
                        const slug = getRoleSlug(role.role_name);
                        const icon = ROLE_ICONS[slug] || 'fa-user-tag';
                        const colors = getRoleCardColors(slug);
                        return `<div class="um-role-card">
                            <div class="um-role-card-header">
                                <div class="um-role-card-icon" style="background:${colors.bg};color:${colors.fg}">
                                    <i class="fa-solid ${icon}"></i>
                                </div>
                                <div>
                                    <div class="um-role-card-name">${escapeHtml(role.role_name)}</div>
                                    <div class="um-role-card-desc">${escapeHtml(role.description || 'No description')}</div>
                                </div>
                            </div>
                            <div class="um-role-card-meta">
                                <div class="um-role-card-meta-item">
                                    <span class="um-role-card-meta-value">${role.users_count}</span>
                                    <span class="um-role-card-meta-label">Users</span>
                                </div>
                                <div class="um-role-card-meta-item">
                                    <span class="um-role-card-meta-value">${role.permissions_count}</span>
                                    <span class="um-role-card-meta-label">Permissions</span>
                                </div>
                            </div>
                            <div class="um-role-card-footer">
                                <?php if ($auth->hasPermission('roles.manage')): ?>
                                <button class="um-btn um-btn-sm um-btn-secondary" onclick="viewRolePermissions(${role.role_id}, '${escapeHtml(role.role_name)}')">
                                    <i class="fa-solid fa-eye"></i> View Permissions
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>`;
                    }).join('');
                    document.getElementById('totalPermissions').textContent = totalPerms;
                } else {
                    grid.innerHTML = '<div class="um-empty"><i class="fa-solid fa-shield-halved"></i><h3>No roles found</h3><p>Roles will appear here once configured.</p></div>';
                }
            } catch (error) { console.error('Error loading roles:', error); }
        }

        function getRoleCardColors(slug) {
            const map = {
                admin:       { bg:'rgba(139,92,246,0.12)', fg:'#7c3aed' },
                super_admin: { bg:'rgba(236,72,153,0.12)', fg:'#db2777' },
                manager:     { bg:'rgba(245,158,11,0.12)', fg:'#d97706' },
                cashier:     { bg:'rgba(6,182,212,0.12)',  fg:'#0891b2' },
                customer:    { bg:'rgba(16,185,129,0.12)', fg:'#059669' },
                inventory:   { bg:'rgba(37,99,235,0.12)',  fg:'#2563eb' }
            };
            return map[slug] || { bg:'rgba(100,116,139,0.1)', fg:'#475569' };
        }

        // ── Roles / Employees for Select ─────────────────────────
        async function loadRolesForSelect() {
            try {
                const response = await fetch('user_api.php?action=get_roles');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('userRole');
                    data.data.forEach(role => {
                        const option = document.createElement('option');
                        option.value = role.role_id;
                        option.textContent = role.role_name;
                        select.appendChild(option);
                    });
                }
            } catch (error) { console.error('Error loading roles:', error); }
        }

        async function loadEmployeesForSelect() {
            try {
                const response = await fetch('user_api.php?action=get_employees');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('employeeId');
                    select.innerHTML = '<option value="">Select Employee…</option>';
                    if (data.data.length === 0) {
                        const opt = document.createElement('option');
                        opt.value = ''; opt.textContent = 'No employees found – add employees first'; opt.disabled = true;
                        select.appendChild(opt);
                        return;
                    }
                    data.data.forEach(emp => {
                        const option = document.createElement('option');
                        option.value = emp.id;
                        option.textContent = emp.name;
                        select.appendChild(option);
                    });
                }
            } catch (error) { console.error('Error loading employees:', error); }
        }

        // ── Modal: Open / Close ──────────────────────────────────
        function openAddUserModal() {
            document.getElementById('userModalTitle').textContent = 'Add User';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('password').required = true;
            document.getElementById('passwordGroup').style.display = '';
            showModal('userModal', 'userOverlay');
        }

        function closeUserModal() { hideModal('userModal', 'userOverlay'); }
        function closePermsModal() { hideModal('permsModal', 'permsOverlay'); }

        function showModal(modalId, overlayId) {
            const modal = document.getElementById(modalId);
            const overlay = document.getElementById(overlayId);
            overlay.classList.add('active');
            // Trigger reflow for transition
            requestAnimationFrame(() => { modal.classList.add('active'); });
        }
        function hideModal(modalId, overlayId) {
            document.getElementById(modalId).classList.remove('active');
            document.getElementById(overlayId).classList.remove('active');
        }

        // ── Edit User ────────────────────────────────────────────
        async function editUser(id) {
            try {
                const response = await fetch(`user_api.php?action=get_user&id=${encodeURIComponent(id)}`);
                const data = await response.json();
                if (data.success) {
                    const user = data.data;
                    document.getElementById('userModalTitle').textContent = 'Edit User';
                    document.getElementById('userId').value = user.user_id;
                    document.getElementById('username').value = user.username;
                    document.getElementById('email').value = user.email || '';
                    document.getElementById('fullName').value = user.full_name || '';
                    document.getElementById('employeeId').value = user.employee_id || '';
                    document.getElementById('userRole').value = user.role_id;
                    document.getElementById('userStatus').value = user.is_active;
                    document.getElementById('password').required = false;
                    document.getElementById('passwordGroup').style.display = 'none';
                    showModal('userModal', 'userOverlay');
                }
            } catch (error) {
                console.error('Error loading user:', error);
                showToast('Error loading user', 'error');
            }
        }

        // ── Save User ────────────────────────────────────────────
        async function saveUser(event) {
            event.preventDefault();
            const userId = document.getElementById('userId').value;
            const isEdit = userId !== '';
            const userData = {
                user_id: userId || undefined,
                username: document.getElementById('username').value,
                email: document.getElementById('email').value,
                password: document.getElementById('password').value,
                full_name: document.getElementById('fullName').value,
                employee_id: document.getElementById('employeeId').value,
                role_id: document.getElementById('userRole').value,
                is_active: document.getElementById('userStatus').value
            };
            const action = isEdit ? 'update_user' : 'create_user';
            try {
                const response = await fetch(`user_api.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(userData)
                });
                const data = await response.json();
                if (data.success) {
                    showToast(isEdit ? 'User updated successfully' : 'User created successfully', 'success');
                    closeUserModal();
                    loadUsers();
                    loadStats();
                } else {
                    showToast(data.message || 'Failed to save user', 'error');
                }
            } catch (error) {
                console.error('Error saving user:', error);
                showToast('Error saving user', 'error');
            }
        }

        // ── Delete User ──────────────────────────────────────────
        async function deleteUser(id) {
            const ok = await customConfirm('Delete User', 'Are you sure you want to delete this user? This action cannot be undone.', 'danger', { confirmText: 'Yes, Delete', cancelText: 'Cancel' });
            if (!ok) return;
            try {
                const response = await fetch('user_api.php?action=delete_user', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('User deleted successfully', 'success');
                    loadUsers();
                    loadStats();
                } else {
                    showToast(data.message || 'Failed to delete user', 'error');
                }
            } catch (error) {
                console.error('Error deleting user:', error);
                showToast('Error deleting user', 'error');
            }
        }

        // ── View Permissions ─────────────────────────────────────
        async function viewRolePermissions(roleId, roleName) {
            document.getElementById('permsModalTitle').textContent = roleName + ' — Permissions';
            document.getElementById('permsContent').innerHTML = '<div class="um-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
            showModal('permsModal', 'permsOverlay');
            try {
                const response = await fetch(`user_api.php?action=get_role_permissions&role_id=${encodeURIComponent(roleId)}`);
                const data = await response.json();
                if (data.success && data.data && data.data.length > 0) {
                    const chips = data.data.map(p => {
                        const name = typeof p === 'string' ? p : (p.permission_name || p.name || '');
                        return `<div class="um-perm-chip"><i class="fa-solid fa-check"></i> ${escapeHtml(name)}</div>`;
                    }).join('');
                    document.getElementById('permsContent').innerHTML = `<div class="um-perms-grid">${chips}</div>`;
                } else {
                    document.getElementById('permsContent').innerHTML = '<div class="um-empty" style="padding:1.5rem"><i class="fa-solid fa-lock"></i><h3>No permissions</h3><p>This role has no assigned permissions yet.</p></div>';
                }
            } catch (error) {
                console.error('Error loading permissions:', error);
                document.getElementById('permsContent').innerHTML = '<div class="um-empty" style="padding:1.5rem"><i class="fa-solid fa-triangle-exclamation"></i><h3>Failed to load</h3><p>Could not fetch permissions for this role.</p></div>';
            }
        }

        // ── Toast ────────────────────────────────────────────────
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            toastMessage.textContent = message;
            toast.className = `toast ${type} active`;
            setTimeout(() => { toast.classList.remove('active'); }, 3000);
        }

        // ── Utils ────────────────────────────────────────────────
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDateTime(dateString) {
            const date = new Date(dateString);
            return date.toLocaleString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
        }

        // ── Keyboard Shortcuts ───────────────────────────────────
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'n') { e.preventDefault(); openAddUserModal(); }
            if (e.key === 'F3') { e.preventDefault(); document.getElementById('searchInput')?.focus(); }
            if (e.key === 'Escape') { closeUserModal(); closePermsModal(); }
        });
    </script>
    <script src="shared-polish.js"></script>
</body>
</html>
