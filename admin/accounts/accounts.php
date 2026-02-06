<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage = 'accounts';
$adminBasePath = '../';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts beheren | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--cream);
            color: var(--text-primary);
            min-height: 100vh;
        }

        .admin-content {
            padding: 2rem;
            max-width: 900px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--text-muted);
            margin-bottom: 2rem;
        }

        .account-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .account-card {
            background: var(--white);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            padding: 2rem;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s, box-shadow 0.2s;
            display: block;
            text-align: center;
        }

        .account-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .account-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }

        .account-card .icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, var(--brown-medium), var(--brown));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 1.75rem;
            color: white;
        }

        .account-card.disabled .icon {
            background: linear-gradient(135deg, #999, #666);
        }

        .account-card h3 {
            color: var(--text-primary);
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .account-card.disabled h3 {
            color: var(--text-muted);
        }

        .account-card p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .account-card .badge {
            display: inline-block;
            padding: 0.3rem 0.85rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .account-card .badge.active {
            background: #d4edda;
            color: #155724;
        }

        .account-card .badge.coming-soon {
            background: var(--cream-dark);
            color: var(--brown);
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
            .account-options { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include '../components/sidebar.php'; ?>

        <div class="admin-main">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-toggle" onclick="toggleSidebar()">
                        <i class="bi bi-list"></i>
                    </button>
                    <span class="topbar-title">Accounts</span>
                </div>
                <div class="topbar-right">
                    <a href="../index.php" class="topbar-link">
                        <i class="bi bi-arrow-left"></i> <span>Dashboard</span>
                    </a>
                </div>
            </header>

            <div class="admin-content">
                <h2 class="page-title">Accounts beheren</h2>
                <p class="page-subtitle">Beheer zakelijke en particuliere klantaccounts</p>

                <div class="account-options">
                    <a href="accounts-bedrijven.php" class="account-card">
                        <div class="icon"><i class="bi bi-building"></i></div>
                        <h3>Bedrijven</h3>
                        <p>Beheer zakelijke klantaccounts, bekijk aanvragen en keur nieuwe bedrijven goed.</p>
                        <span class="badge active">Actief</span>
                    </a>

                    <div class="account-card disabled">
                        <div class="icon"><i class="bi bi-person"></i></div>
                        <h3>Particulieren</h3>
                        <p>Beheer particuliere klantaccounts voor consumenten.</p>
                        <span class="badge coming-soon">Komt binnenkort</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
