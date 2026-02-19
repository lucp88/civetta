<?php
$sidebarPendingAccounts = $sidebarPendingAccounts ?? 0;
$sidebarUnprocessedOrders = $sidebarUnprocessedOrders ?? 0;
$currentPage = $currentPage ?? '';
?>
<style>
:root {
    --brown-dark: #3d2514;
    --brown: #5c3d1e;
    --brown-medium: #8b5a2b;
    --brown-light: #b07d4f;
    --cream: #f8f5f0;
    --cream-dark: #ede8e0;
    --white: #ffffff;
    --text-primary: #2c1810;
    --text-secondary: #6b5c52;
    --text-muted: #9a8d84;
    --border: #e5ddd4;
    --shadow-sm: 0 1px 3px rgba(60,35,20,0.06);
    --shadow-md: 0 4px 12px rgba(60,35,20,0.08);
    --shadow-lg: 0 8px 30px rgba(60,35,20,0.12);
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --sidebar-width: 260px;
    --header-height: 64px;
}

.admin-layout {
    display: flex;
    min-height: 100vh;
}

.sidebar {
    width: var(--sidebar-width);
    background: var(--brown-dark);
    color: white;
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 100;
    display: flex;
    flex-direction: column;
    transition: transform 0.3s ease;
}

.sidebar-brand {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.sidebar-brand-icon {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, var(--brown-medium), var(--brown-light));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    font-weight: 700;
    letter-spacing: -0.5px;
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
    padding: 0.5rem 1.5rem 0.25rem;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: rgba(255,255,255,0.3);
    font-weight: 600;
    margin-top: 0.5rem;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 1.5rem;
    color: rgba(255,255,255,0.65);
    text-decoration: none;
    font-size: 0.88rem;
    font-weight: 450;
    transition: all 0.15s;
    border-left: 3px solid transparent;
}

.nav-item:hover {
    color: white;
    background: rgba(255,255,255,0.06);
}

.nav-item.active {
    color: white;
    background: rgba(255,255,255,0.08);
    border-left-color: var(--brown-light);
}

.nav-item i {
    font-size: 1.05rem;
    width: 20px;
    text-align: center;
}

.nav-badge {
    margin-left: auto;
    background: #e74c3c;
    color: white;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 0.15rem 0.5rem;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
}

.sidebar-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid rgba(255,255,255,0.08);
}

.sidebar-footer a {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: rgba(255,255,255,0.5);
    text-decoration: none;
    font-size: 0.85rem;
    transition: color 0.15s;
}

.sidebar-footer a:hover { color: white; }

.admin-main {
    flex: 1;
    margin-left: var(--sidebar-width);
    min-height: 100vh;
}

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

.mobile-toggle {
    display: none;
    background: none;
    border: none;
    font-size: 1.3rem;
    color: var(--text-primary);
    cursor: pointer;
    padding: 0.25rem;
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

.topbar-link:hover { color: var(--brown); }

.mobile-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 99;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.mobile-overlay.open {
    display: block;
    opacity: 1;
}

.mobile-dropdown {
    display: none;
    position: fixed;
    top: var(--header-height);
    left: 0;
    right: 0;
    background: var(--brown-dark);
    z-index: 101;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.mobile-dropdown.open {
    max-height: calc(100vh - var(--header-height));
    overflow-y: auto;
}

.mobile-dropdown .nav-section {
    padding: 0.75rem 1.25rem 0.25rem;
}

.mobile-dropdown .nav-item {
    padding: 0.75rem 1.25rem;
    border-left: none;
}

.mobile-dropdown .sidebar-footer {
    border-top: 1px solid rgba(255,255,255,0.1);
    padding: 1rem 1.25rem;
}

@media (max-width: 1024px) {
    .sidebar {
        transform: translateX(-100%);
    }

    .sidebar.open {
        transform: translateX(0);
    }

    .mobile-overlay.open { display: block; }

    .admin-main { margin-left: 0; }

    .mobile-toggle { display: flex; }

    .topbar { padding: 0 1.25rem; }
}

@media (max-width: 768px) {
    .sidebar {
        display: none;
    }

    .mobile-overlay {
        display: none !important;
    }

    .mobile-dropdown {
        display: block;
    }

    .admin-main { margin-left: 0; }

    .mobile-toggle { display: flex; }

    .topbar {
        padding: 0 1rem;
        height: 56px;
    }

    .topbar-title {
        font-size: 0.95rem;
    }

    .topbar-right {
        gap: 0.5rem;
    }

    .topbar-link span {
        display: none;
    }
}
</style>

<div class="mobile-overlay" id="overlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">C</div>
        <div>
            <h1>Civetta</h1>
            <span>Admin Panel</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Overzicht</div>
        <a href="<?= $adminBasePath ?? '' ?>index.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>

        <div class="nav-section">Bakkerij</div>
        <a href="<?= $adminBasePath ?? '' ?>bakker/bakker-dashboard.php" class="nav-item <?= $currentPage === 'bakker-dashboard' ? 'active' : '' ?>">
            <i class="bi bi-calendar3"></i> Planning
        </a>
        <a href="<?= $adminBasePath ?? '' ?>bakker/bakcalculator.php" class="nav-item <?= $currentPage === 'bakcalculator' ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i> Receptuur
        </a>
        <a href="<?= $adminBasePath ?? '' ?>bakker/voorraad.php" class="nav-item <?= $currentPage === 'voorraad' ? 'active' : '' ?>">
            <i class="bi bi-box-seam-fill"></i> Voorraadbeheer
        </a>

        <div class="nav-section">Beheer</div>
        <a href="<?= $adminBasePath ?? '' ?>bestellingen/orders.php" class="nav-item <?= $currentPage === 'orders' ? 'active' : '' ?>">
            <i class="bi bi-box-seam"></i> Bestellingen
            <?php if ($sidebarUnprocessedOrders > 0): ?>
                <span class="nav-badge"><?= $sidebarUnprocessedOrders ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= $adminBasePath ?? '' ?>producten/products.php" class="nav-item <?= $currentPage === 'products' ? 'active' : '' ?>">
            <i class="bi bi-basket3"></i> Producten
        </a>
        <a href="<?= $adminBasePath ?? '' ?>accounts/accounts.php" class="nav-item <?= $currentPage === 'accounts' ? 'active' : '' ?>">
            <i class="bi bi-people"></i> Accounts
            <?php if ($sidebarPendingAccounts > 0): ?>
                <span class="nav-badge"><?= $sidebarPendingAccounts ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">Content</div>
        <a href="<?= $adminBasePath ?? '' ?>blog/posts.php" class="nav-item <?= $currentPage === 'blog' ? 'active' : '' ?>">
            <i class="bi bi-pencil-square"></i> Blog Posts
        </a>
        <a href="<?= $adminBasePath ?? '' ?>donaties/donations.php" class="nav-item <?= $currentPage === 'donations' ? 'active' : '' ?>">
            <i class="bi bi-heart"></i> Donaties
        </a>

        <div class="nav-section">Rapportage</div>
        <a href="<?= $adminBasePath ?? '' ?>reporting/analytics.php" class="nav-item <?= $currentPage === 'analytics' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-line"></i> Analytics
        </a>

        <div class="nav-section">Instellingen</div>
        <a href="<?= $adminBasePath ?? '' ?>settings/settings-bedrijf.php" class="nav-item <?= $currentPage === 'settings-bedrijf' ? 'active' : '' ?>">
            <i class="bi bi-building"></i> Bedrijfsgegevens
        </a>
        <a href="<?= $adminBasePath ?? '' ?>settings/settings-boekhouding.php" class="nav-item <?= $currentPage === 'settings-boekhouding' ? 'active' : '' ?>">
            <i class="bi bi-receipt"></i> Boekhouding
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= $adminBasePath ?? '' ?>logout.php"><i class="bi bi-box-arrow-left"></i> Uitloggen</a>
    </div>
</aside>

<div class="mobile-dropdown" id="mobileDropdown">
    <nav>
        <div class="nav-section">Overzicht</div>
        <a href="<?= $adminBasePath ?? '' ?>index.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>

        <div class="nav-section">Bakkerij</div>
        <a href="<?= $adminBasePath ?? '' ?>bakker/bakker-dashboard.php" class="nav-item <?= $currentPage === 'bakker-dashboard' ? 'active' : '' ?>">
            <i class="bi bi-calendar3"></i> Planning
        </a>
        <a href="<?= $adminBasePath ?? '' ?>bakker/bakcalculator.php" class="nav-item <?= $currentPage === 'bakcalculator' ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i> Receptuur
        </a>
        <a href="<?= $adminBasePath ?? '' ?>bakker/voorraad.php" class="nav-item <?= $currentPage === 'voorraad' ? 'active' : '' ?>">
            <i class="bi bi-box-seam-fill"></i> Voorraadbeheer
        </a>

        <div class="nav-section">Beheer</div>
        <a href="<?= $adminBasePath ?? '' ?>bestellingen/orders.php" class="nav-item <?= $currentPage === 'orders' ? 'active' : '' ?>">
            <i class="bi bi-box-seam"></i> Bestellingen
            <?php if ($sidebarUnprocessedOrders > 0): ?>
                <span class="nav-badge"><?= $sidebarUnprocessedOrders ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= $adminBasePath ?? '' ?>producten/products.php" class="nav-item <?= $currentPage === 'products' ? 'active' : '' ?>">
            <i class="bi bi-basket3"></i> Producten
        </a>
        <a href="<?= $adminBasePath ?? '' ?>accounts/accounts.php" class="nav-item <?= $currentPage === 'accounts' ? 'active' : '' ?>">
            <i class="bi bi-people"></i> Accounts
            <?php if ($sidebarPendingAccounts > 0): ?>
                <span class="nav-badge"><?= $sidebarPendingAccounts ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section">Content</div>
        <a href="<?= $adminBasePath ?? '' ?>blog/posts.php" class="nav-item <?= $currentPage === 'blog' ? 'active' : '' ?>">
            <i class="bi bi-pencil-square"></i> Blog Posts
        </a>
        <a href="<?= $adminBasePath ?? '' ?>donaties/donations.php" class="nav-item <?= $currentPage === 'donations' ? 'active' : '' ?>">
            <i class="bi bi-heart"></i> Donaties
        </a>

        <div class="nav-section">Rapportage</div>
        <a href="<?= $adminBasePath ?? '' ?>reporting/analytics.php" class="nav-item <?= $currentPage === 'analytics' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-line"></i> Analytics
        </a>

        <div class="nav-section">Instellingen</div>
        <a href="<?= $adminBasePath ?? '' ?>settings/settings-bedrijf.php" class="nav-item <?= $currentPage === 'settings-bedrijf' ? 'active' : '' ?>">
            <i class="bi bi-building"></i> Bedrijfsgegevens
        </a>
        <a href="<?= $adminBasePath ?? '' ?>settings/settings-boekhouding.php" class="nav-item <?= $currentPage === 'settings-boekhouding' ? 'active' : '' ?>">
            <i class="bi bi-receipt"></i> Boekhouding
        </a>

        <div class="sidebar-footer">
            <a href="<?= $adminBasePath ?? '' ?>logout.php"><i class="bi bi-box-arrow-left"></i> Uitloggen</a>
        </div>
    </nav>
</div>

<script>
function toggleSidebar() {
    const width = window.innerWidth;
    if (width <= 768) {
        document.getElementById('mobileDropdown').classList.toggle('open');
    } else {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('open');
    }
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('open');
    document.getElementById('mobileDropdown').classList.remove('open');
}

window.addEventListener('resize', function() {
    closeSidebar();
});
</script>
