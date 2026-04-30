<?php
$adminPageTitle = $adminPageTitle ?? 'Admin';
$adminBasePath = $adminBasePath ?? '';
$adminExtraHead = $adminExtraHead ?? '';
$sidebarPendingAccounts = $sidebarPendingAccounts ?? 0;
$sidebarUnprocessedOrders = $sidebarUnprocessedOrders ?? 0;
if (!isset($currentPage) || $currentPage === '') {
    $currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($adminPageTitle) ?> | Civetta Admin</title>
    <link rel="stylesheet" href="/css/bootstrap-icons.min.css">
    <style>
    :root {
        --green-dark: #1a2e1a;
        --green: #2d4a2d;
        --green-medium: #3d6b3d;
        --green-light: #5a9a5a;
        --cream: #f5f6f4;
        --cream-dark: #e8eae6;
        --white: #ffffff;
        --text-primary: #1a1e1a;
        --text-secondary: #5a635a;
        --text-muted: #8a918a;
        --border: #d4d9d4;
        --shadow-sm: 0 1px 3px rgba(20,35,20,0.06);
        --shadow-md: 0 4px 12px rgba(20,35,20,0.08);
        --shadow-lg: 0 8px 30px rgba(20,35,20,0.12);
        --radius-sm: 0px;
        --radius-md: 0px;
        --radius-lg: 0px;
        --sidebar-width: 260px;
        --header-height: 64px;
        --mobile-topbar-height: 52px;
    }

    @keyframes pageFadeIn {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; border-radius: 0 !important; }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: var(--cream);
        color: var(--text-primary);
        min-height: 100vh;
        animation: pageFadeIn 0.2s ease-out;
    }

    /* Mobile topbar (shown on small screens) */
    .admin-topbar {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0;
        height: var(--mobile-topbar-height);
        background: var(--green-dark);
        align-items: center;
        justify-content: space-between;
        padding: 0 1rem;
        z-index: 200;
    }

    .admin-topbar__toggle {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0.4rem;
        color: rgba(255,255,255,0.85);
        display: flex;
        flex-direction: column;
        gap: 5px;
        min-width: 38px;
        min-height: 38px;
        align-items: center;
        justify-content: center;
        -webkit-tap-highlight-color: transparent;
        touch-action: manipulation;
    }

    .admin-topbar__toggle span {
        display: block;
        width: 22px;
        height: 2px;
        background: currentColor;
        border-radius: 0;
        transition: all 0.25s;
    }

    .admin-topbar__toggle.open span:nth-child(1) { transform: rotate(45deg) translate(5px, 5px); }
    .admin-topbar__toggle.open span:nth-child(2) { opacity: 0; }
    .admin-topbar__toggle.open span:nth-child(3) { transform: rotate(-45deg) translate(5px, -5px); }

    .admin-topbar__brand {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: white;
        font-size: 1rem;
        font-weight: 600;
        letter-spacing: -0.2px;
    }

    .admin-topbar__brand img {
        height: 28px;
        width: auto;
    }

    .admin-topbar__brand em {
        font-style: normal;
        color: rgba(255,255,255,0.45);
        font-size: 0.72rem;
        font-weight: 400;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        display: block;
        line-height: 1;
        margin-top: 2px;
    }

    /* Sidebar overlay */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.45);
        z-index: 149;
        transition: opacity 0.25s;
    }

    .sidebar-overlay.active { display: block; }

    /* Layout */
    .admin-layout {
        display: flex;
        min-height: 100vh;
    }

    /* Sidebar */
    .sidebar {
        width: var(--sidebar-width);
        background: var(--green-dark);
        color: white;
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 100;
        display: flex;
        flex-direction: column;
        transition: transform 0.25s ease;
    }

    .sidebar-brand {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-shrink: 0;
    }

    .sidebar-brand-icon {
        width: 36px;
        height: 36px;
        border-radius: 0;
        object-fit: contain;
    }

    .sidebar-brand h1 {
        font-size: 1.15rem;
        font-weight: 600;
        letter-spacing: -0.3px;
        margin: 0;
    }

    .sidebar-brand span {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: rgba(255,255,255,0.4);
        display: block;
        margin-top: 1px;
    }

    .sidebar-nav {
        flex: 1;
        padding: 0.75rem 0;
        overflow-y: auto;
    }

    .nav-section {
        padding: 1rem 1.25rem 0.5rem;
        font-size: 0.65rem;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: rgba(255,255,255,0.3);
        font-weight: 600;
        margin-top: 0;
    }

    .nav-item {
        display: block;
        padding: 0.6rem 1.25rem;
        color: rgba(255,255,255,0.65);
        text-decoration: none;
        font-size: 0.9rem;
        transition: all 0.2s;
        border-left: 3px solid transparent;
    }

    .nav-item:hover {
        color: white;
        background: rgba(255,255,255,0.06);
    }

    .nav-item.active {
        color: white;
        background: rgba(255,255,255,0.08);
        border-left-color: var(--green-light);
    }

    .nav-badge {
        margin-left: auto;
        background: #e74c3c;
        color: white;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 0.15rem 0.5rem;
        border-radius: 0;
        min-width: 18px;
        text-align: center;
    }

    .sidebar-footer {
        padding: 1rem;
        border-top: 1px solid rgba(255,255,255,0.08);
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .sidebar-footer a {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: rgba(255,255,255,0.5);
        text-decoration: none;
        font-size: 0.85rem;
        padding: 0.35rem 0;
        transition: color 0.15s;
    }

    .sidebar-footer a:hover { color: white; }

    /* Main content area */
    .admin-main {
        flex: 1;
        margin-left: var(--sidebar-width);
        min-height: 100vh;
    }

    /* Per-page sticky topbar */
    .topbar {
        height: var(--header-height);
        background: var(--white);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 2rem;
        position: sticky;
        top: 0;
        z-index: 50;
    }

    .topbar-left {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .topbar-title {
        font-size: 1.05rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .topbar-right {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .topbar-link {
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.82rem;
        display: flex;
        align-items: center;
        gap: 0.35rem;
        transition: color 0.15s;
    }

    .topbar-link:hover { color: var(--green); }

    /* Mobile */
    @media (max-width: 1024px) {
        .admin-topbar { display: flex; }

        .sidebar {
            top: var(--mobile-topbar-height);
            transform: translateX(-100%);
            z-index: 150;
        }

        .sidebar.open { transform: translateX(0); }

        .admin-main {
            margin-left: 0;
            padding-top: var(--mobile-topbar-height);
        }

        .topbar {
            top: var(--mobile-topbar-height);
            padding: 0 1.25rem;
        }
    }

    .nav-install-btn {
        width: 100%;
        background: none;
        border: none;
        cursor: pointer;
        text-align: left;
        font-family: inherit;
    }

    @media (max-width: 768px) {
        .topbar {
            padding: 0 1rem;
            height: var(--header-height);
        }

        .topbar-title { font-size: 0.95rem; }
        .topbar-right { gap: 0.5rem; }
        .topbar-link span { display: none; }
    }
    /* Shared state badges */
    .badge {
        display: inline-block;
        font-size: 0.72rem;
        font-weight: 600;
        padding: 0.2rem 0.55rem;
        border-radius: 0;
        vertical-align: middle;
        white-space: nowrap;
    }
    .badge--green  { background: #d4edda; color: #155724; }
    .badge--orange { background: #fff3cd; color: #856404; }
    .badge--blue   { background: #cce5ff; color: #004085; }
    .badge--red    { background: #f8d7da; color: #721c24; }
    .badge--gray   { background: #e2e3e5; color: #383d41; }

    </style>
    <link rel="manifest" href="<?= $adminBasePath ?>manifest.json">
    <meta name="theme-color" content="#5c3d1e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    <?php if ($adminExtraHead) echo $adminExtraHead; ?>
</head>
<body>

<div class="admin-topbar" id="adminTopbar">
    <button class="admin-topbar__toggle" id="sidebarToggle" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
    <div class="admin-topbar__brand">
        <div>
            <div>Civetta</div>
            <em>Admin</em>
        </div>
    </div>
    <div style="width:38px"></div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="admin-layout">

<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-brand">
        <img src="<?= $adminBasePath ?>../img/Logo_transparant_white.png" alt="Civetta" class="sidebar-brand-icon">
        <div>
            <h1>Civetta</h1>
            <span>Admin Panel</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Bedrijf</div>
        <a href="<?= $adminBasePath ?>index.php" class="nav-item <?= $currentPage === 'index' || $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="<?= $adminBasePath ?>reporting/analytics.php" class="nav-item <?= $currentPage === 'analytics' ? 'active' : '' ?>">Analytics</a>

        <div class="nav-section">Bakkerij</div>
        <a href="<?= $adminBasePath ?>bakker/bakker-dashboard.php" class="nav-item <?= ($currentPage === 'bakker-dashboard' || $currentPage === 'dagproductie') ? 'active' : '' ?>">Bakkersdashboard</a>
        <a href="<?= $adminBasePath ?>bakker/recepten.php" class="nav-item <?= ($currentPage === 'recepten' || $currentPage === 'bakcalculator') ? 'active' : '' ?>">Recepten</a>
        <a href="<?= $adminBasePath ?>bakker/logboek.php" class="nav-item <?= $currentPage === 'logboek' ? 'active' : '' ?>">Logboek</a>
        <a href="<?= $adminBasePath ?>bakker/voorraad.php" class="nav-item <?= $currentPage === 'voorraad' ? 'active' : '' ?>">Voorraadbeheer</a>
        <a href="<?= $adminBasePath ?>bakker/voedselveiligheid.php" class="nav-item <?= $currentPage === 'voedselveiligheid' ? 'active' : '' ?>">Voedselveiligheid</a>

        <div class="nav-section">Winkel</div>
        <a href="<?= $adminBasePath ?>bestellingen/orders.php" class="nav-item <?= $currentPage === 'orders' ? 'active' : '' ?>">Bestellingen
            <?php if ($sidebarUnprocessedOrders > 0): ?>
                <span class="nav-badge"><?= $sidebarUnprocessedOrders ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= $adminBasePath ?>producten/products.php" class="nav-item <?= $currentPage === 'products' ? 'active' : '' ?>">Producten</a>
        <a href="<?= $adminBasePath ?>donaties/donations.php" class="nav-item <?= $currentPage === 'donations' ? 'active' : '' ?>">Donaties</a>

        <div class="nav-section">Content</div>
        <a href="<?= $adminBasePath ?>blog/posts.php" class="nav-item <?= $currentPage === 'posts' ? 'active' : '' ?>">Blog Posts</a>

        <div class="nav-section">Beheer</div>
        <a href="<?= $adminBasePath ?>accounts/accounts.php" class="nav-item <?= $currentPage === 'accounts' ? 'active' : '' ?>">Accounts
            <?php if ($sidebarPendingAccounts > 0): ?>
                <span class="nav-badge"><?= $sidebarPendingAccounts ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= $adminBasePath ?>settings/settings-bedrijf.php" class="nav-item <?= $currentPage === 'settings-bedrijf' ? 'active' : '' ?>">Bedrijfsgegevens</a>
        <a href="<?= $adminBasePath ?>settings/settings-boekhouding.php" class="nav-item <?= $currentPage === 'settings-boekhouding' ? 'active' : '' ?>">Boekhouding</a>
        <a href="<?= $adminBasePath ?>settings/email-templates.php" class="nav-item <?= $currentPage === 'email-templates' ? 'active' : '' ?>">E-mail templates</a>

        <div class="nav-section">Web-Dev</div>
        <a href="<?= $adminBasePath ?>settings/recipe-versions.php" class="nav-item <?= $currentPage === 'recipe-versions' ? 'active' : '' ?>">Receptversies</a>
        <a href="<?= $adminBasePath ?>migrations/index.php" class="nav-item <?= $currentPage === 'migrations' ? 'active' : '' ?>">Migraties</a>
        <button class="nav-item nav-install-btn" id="pwaInstallBtn" onclick="pwaInstall()">App installeren</button>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= $adminBasePath ?>../index.html" target="_blank">Bekijk website</a>
        <a href="<?= $adminBasePath ?>logout.php">Uitloggen</a>
    </div>
</aside>

<script>
(function() {
    var toggle = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('adminSidebar');
    var overlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('open');
        toggle.classList.add('open');
        overlay.classList.add('active');
    }
    function closeSidebar() {
        sidebar.classList.remove('open');
        toggle.classList.remove('open');
        overlay.classList.remove('active');
    }
    if (toggle) toggle.addEventListener('click', function() {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });
    if (overlay) overlay.addEventListener('click', closeSidebar);
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1024) closeSidebar();
    });
})();

var _pwaPrompt = null;
window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    _pwaPrompt = e;
});
function pwaInstall() {
    if (_pwaPrompt) {
        _pwaPrompt.prompt();
        _pwaPrompt.userChoice.then(function() { _pwaPrompt = null; });
    } else {
        alert('De app is al geïnstalleerd of deze browser ondersteunt installatie niet.');
    }
}
</script>

<div class="admin-main">
