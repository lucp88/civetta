<?php
require_once 'config.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Civetta Admin</title>
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
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .welcome {
            margin-bottom: 2rem;
        }
        .welcome h2 {
            color: #5c3d1e;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        .welcome p {
            color: #666;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 2rem;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s, box-shadow 0.2s;
            display: block;
        }
        .dashboard-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .dashboard-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }
        .dashboard-card .icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.8rem;
        }
        .dashboard-card.disabled .icon {
            background: linear-gradient(135deg, #999, #666);
        }
        .dashboard-card h3 {
            color: #5c3d1e;
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
        }
        .dashboard-card.disabled h3 {
            color: #888;
        }
        .dashboard-card p {
            color: #666;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .dashboard-card .badge {
            display: inline-block;
            background: #e8dfd2;
            color: #8b5a2b;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-top: 1rem;
        }
        .dashboard-card.disabled .badge {
            background: #ddd;
            color: #888;
        }
        .quick-links {
            margin-top: 2rem;
            padding: 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .quick-links h3 {
            color: #5c3d1e;
            margin-bottom: 1rem;
        }
        .quick-links a {
            color: #8b5a2b;
            text-decoration: none;
            margin-right: 1.5rem;
        }
        .quick-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Civetta Admin</h1>
        <a href="logout.php">Uitloggen</a>
    </div>
    
    <div class="container">
        <div class="welcome">
            <h2>Welkom terug!</h2>
            <p>Beheer hier je bakkerij website.</p>
        </div>

        <div class="dashboard-grid">
            <a href="posts.php" class="dashboard-card">
                <div class="icon">📝</div>
                <h3>Blog Posts</h3>
                <p>Schrijf en beheer nieuws berichten voor je website.</p>
                <span class="badge">Actief</span>
            </a>

            <a href="donations.php" class="dashboard-card">
                <div class="icon">💚</div>
                <h3>Donaties</h3>
                <p>Bekijk ontvangen crowdfunding donaties.</p>
                <span class="badge">Actief</span>
            </a>

            <a href="products.php" class="dashboard-card">
                <div class="icon">🥖</div>
                <h3>Producten</h3>
                <p>Beheer je bakkerij producten.</p>
                <span class="badge">Actief</span>
            </a>

            <a href="accounts.php" class="dashboard-card">
                <div class="icon">👥</div>
                <h3>Accounts beheren</h3>
                <p>Beheer zakelijke en particuliere accounts.</p>
                <span class="badge">Actief</span>
            </a>

            <a href="orders.php" class="dashboard-card">
                <div class="icon">📦</div>
                <h3>Bestellingen</h3>
                <p>Bekijk en beheer klant bestellingen.</p>
                <span class="badge">Actief</span>
            </a>
        </div>

        <div class="quick-links">
            <h3>Snelle links</h3>
            <a href="../index.html" target="_blank">Bekijk website</a>
            <a href="../financiering.html" target="_blank">Crowdfunding pagina</a>
            <a href="../contact.html" target="_blank">Contact pagina</a>
        </div>
    </div>
</body>
</html>
