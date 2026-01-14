<?php
require_once 'config.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts beheren | Civetta Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 1.5rem; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
        }
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .breadcrumb {
            margin-bottom: 1.5rem;
        }
        .breadcrumb a {
            color: #8b5a2b;
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .breadcrumb span {
            color: #888;
            margin: 0 0.5rem;
        }
        .page-title {
            color: #5c3d1e;
            margin-bottom: 0.5rem;
        }
        .page-subtitle {
            color: #666;
            margin-bottom: 2rem;
        }
        .account-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .account-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 2rem;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s, box-shadow 0.2s;
            display: block;
            text-align: center;
        }
        .account-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .account-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }
        .account-card .icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
        }
        .account-card.disabled .icon {
            background: linear-gradient(135deg, #999, #666);
        }
        .account-card h3 {
            color: #5c3d1e;
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
        }
        .account-card.disabled h3 {
            color: #888;
        }
        .account-card p {
            color: #666;
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }
        .account-card .badge {
            display: inline-block;
            padding: 0.35rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .account-card .badge.active {
            background: #d4edda;
            color: #155724;
        }
        .account-card .badge.coming-soon {
            background: #e8dfd2;
            color: #8b5a2b;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Civetta Admin</h1>
        <a href="logout.php">Uitloggen</a>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="index.php">Dashboard</a>
            <span>›</span>
            Accounts beheren
        </div>

        <h2 class="page-title">Accounts beheren</h2>
        <p class="page-subtitle">Beheer zakelijke en particuliere klantaccounts</p>

        <div class="account-options">
            <a href="accounts-bedrijven.php" class="account-card">
                <div class="icon">🏢</div>
                <h3>Bedrijven</h3>
                <p>Beheer zakelijke klantaccounts, bekijk aanvragen en keur nieuwe bedrijven goed.</p>
                <span class="badge active">Actief</span>
            </a>

            <div class="account-card disabled">
                <div class="icon">👤</div>
                <h3>Particulieren</h3>
                <p>Beheer particuliere klantaccounts voor consumenten.</p>
                <span class="badge coming-soon">Komt binnenkort</span>
            </div>
        </div>
    </div>
</body>
</html>
