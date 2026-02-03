<?php
require_once '../config.php';
requireLogin();

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$stmt = $pdo->prepare("
    SELECT COUNT(*) as count, SUM(total_amount) as total
    FROM business_orders 
    WHERE delivery_date = ? AND is_cancelled = 0
");
$stmt->execute([$today]);
$todayDeliveries = $stmt->fetch();

$stmt->execute([$tomorrow]);
$tomorrowDeliveries = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT COUNT(*) as count
    FROM business_orders 
    WHERE delivery_date = ? AND is_cancelled = 0
");
$stmt->execute([$tomorrow]);
$todayBereiding = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT bo.*, ba.bedrijfsnaam
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    WHERE bo.delivery_date = ? AND bo.is_cancelled = 0
    ORDER BY bo.id
    LIMIT 5
");
$stmt->execute([$today]);
$upcomingDeliveries = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT boi.product_name, SUM(boi.quantity) as total_qty
    FROM business_order_items boi
    JOIN business_orders bo ON boi.order_id = bo.id
    WHERE bo.delivery_date = ? AND bo.is_cancelled = 0
    GROUP BY boi.product_name
    ORDER BY total_qty DESC
");
$stmt->execute([$tomorrow]);
$productsToBake = $stmt->fetchAll();

function getDutchDayName($date) {
    $dagen = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
    return $dagen[date('w', strtotime($date))];
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bakker Dashboard | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
        .header h1 { font-size: 1.5rem; display: flex; align-items: center; gap: 0.5rem; }
        .header-links { display: flex; gap: 0.75rem; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .header a:hover { background: rgba(255,255,255,0.3); }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }
        
        .welcome {
            text-align: center;
            margin-bottom: 2rem;
        }
        .welcome h2 {
            color: #5c3d1e;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        .welcome p { color: #666; }
        
        .main-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        @media (max-width: 700px) {
            .main-cards { grid-template-columns: 1fr; }
        }
        
        .main-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            display: block;
            position: relative;
            overflow: hidden;
        }
        .main-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .main-card.bereiden {
            background: linear-gradient(135deg, #fff5f0, #ffe8db);
            border: 2px solid #ffccbc;
        }
        .main-card.bereiden:hover { border-color: #ff6b35; }
        .main-card.bereiden .card-icon { background: linear-gradient(135deg, #ff6b35, #e55a2b); }
        .main-card.bereiden .card-title { color: #e55a2b; }
        
        .main-card.leveren {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 2px solid #90caf9;
        }
        .main-card.leveren:hover { border-color: #2196f3; }
        .main-card.leveren .card-icon { background: linear-gradient(135deg, #2196f3, #1976d2); }
        .main-card.leveren .card-title { color: #1976d2; }
        
        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.8rem;
            color: white;
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .card-desc {
            color: #666;
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }
        
        .card-stats {
            display: flex;
            gap: 1.5rem;
        }
        .stat {
            text-align: center;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }
        .main-card.bereiden .stat-value { color: #e55a2b; }
        .main-card.leveren .stat-value { color: #1976d2; }
        .stat-label {
            font-size: 0.75rem;
            color: #888;
            text-transform: uppercase;
        }
        
        .card-arrow {
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 2rem;
            opacity: 0.3;
        }
        .main-card:hover .card-arrow { opacity: 0.6; }
        .main-card.bereiden .card-arrow { color: #e55a2b; }
        .main-card.leveren .card-arrow { color: #1976d2; }
        
        .summary-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 700px) {
            .summary-section { grid-template-columns: 1fr; }
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .summary-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .summary-header h3 {
            font-size: 1rem;
            color: #5c3d1e;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .summary-header a {
            color: #8b5a2b;
            text-decoration: none;
            font-size: 0.85rem;
        }
        .summary-header a:hover { text-decoration: underline; }
        
        .summary-body {
            padding: 1rem 1.25rem;
        }
        
        .delivery-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f5f5f5;
        }
        .delivery-item:last-child { border-bottom: none; }
        .delivery-name { font-weight: 500; color: #333; }
        .delivery-status {
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .delivery-status.pending { background: #fff3cd; color: #856404; }
        .delivery-status.onderweg { background: #e3f2fd; color: #1565c0; }
        .delivery-status.afgeleverd { background: #d1e7dd; color: #0f5132; }
        
        .product-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f5f5f5;
        }
        .product-item:last-child { border-bottom: none; }
        .product-name { color: #333; }
        .product-qty {
            font-weight: 600;
            color: #e55a2b;
        }
        
        .empty-state {
            text-align: center;
            padding: 1.5rem;
            color: #999;
        }
        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="bi bi-calendar3"></i> Bakker Dashboard</h1>
        <div class="header-links">
            <a href="../bestellingen/orders.php"><i class="bi bi-list-ul"></i> Alle bestellingen</a>
            <a href="../index.php"><i class="bi bi-house"></i> Admin</a>
        </div>
    </div>
    
    <div class="container">
        <div class="welcome">
            <h2>Goedemorgen!</h2>
            <p>Hier is je planning voor vandaag en morgen.</p>
        </div>
        
        <div class="main-cards">
            <a href="bereiden.php" class="main-card bereiden">
                <div class="card-icon"><i class="bi bi-fire"></i></div>
                <div class="card-title">Bereiden</div>
                <div class="card-desc">Bekijk wat je vandaag moet bakken voor de leveringen van morgen.</div>
                <div class="card-stats">
                    <div class="stat">
                        <div class="stat-value"><?= $todayBereiding['count'] ?? 0 ?></div>
                        <div class="stat-label">Bestellingen</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value"><?= count($productsToBake) ?></div>
                        <div class="stat-label">Producten</div>
                    </div>
                </div>
                <div class="card-arrow"><i class="bi bi-arrow-right"></i></div>
            </a>
            
            <a href="leveren.php" class="main-card leveren">
                <div class="card-icon"><i class="bi bi-truck"></i></div>
                <div class="card-title">Leveren</div>
                <div class="card-desc">Plan je route en lever de bestellingen van vandaag.</div>
                <div class="card-stats">
                    <div class="stat">
                        <div class="stat-value"><?= $todayDeliveries['count'] ?? 0 ?></div>
                        <div class="stat-label">Stops</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">€<?= number_format($todayDeliveries['total'] ?? 0, 0, ',', '.') ?></div>
                        <div class="stat-label">Totaal</div>
                    </div>
                </div>
                <div class="card-arrow"><i class="bi bi-arrow-right"></i></div>
            </a>
        </div>
        
        <div class="summary-section">
            <div class="summary-card">
                <div class="summary-header">
                    <h3><i class="bi bi-truck"></i> Leveringen vandaag</h3>
                    <a href="leveren.php?mode=day">Bekijk alles →</a>
                </div>
                <div class="summary-body">
                    <?php if (empty($upcomingDeliveries)): ?>
                        <div class="empty-state">
                            <i class="bi bi-emoji-smile"></i>
                            <p>Geen leveringen vandaag</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($upcomingDeliveries as $delivery): ?>
                            <div class="delivery-item">
                                <span class="delivery-name"><?= htmlspecialchars($delivery['bedrijfsnaam']) ?></span>
                                <?php
                                $status = $delivery['delivery_status'] ?? 'geplaatst';
                                $statusClass = $status === 'afgeleverd' ? 'afgeleverd' : ($status === 'onderweg' ? 'onderweg' : 'pending');
                                $statusText = $status === 'afgeleverd' ? 'Afgeleverd' : ($status === 'onderweg' ? 'Onderweg' : 'Gepland');
                                ?>
                                <span class="delivery-status <?= $statusClass ?>"><?= $statusText ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="summary-header">
                    <h3><i class="bi bi-fire"></i> Vandaag bereiden</h3>
                    <a href="bereiden.php?mode=day">Bekijk alles →</a>
                </div>
                <div class="summary-body">
                    <?php if (empty($productsToBake)): ?>
                        <div class="empty-state">
                            <i class="bi bi-emoji-smile"></i>
                            <p>Niets te bereiden vandaag</p>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($productsToBake, 0, 5) as $product): ?>
                            <div class="product-item">
                                <span class="product-name"><?= htmlspecialchars($product['product_name']) ?></span>
                                <span class="product-qty"><?= $product['total_qty'] ?>x</span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($productsToBake) > 5): ?>
                            <div style="text-align: center; padding-top: 0.5rem;">
                                <a href="bereiden.php?mode=day" style="color: #e55a2b; text-decoration: none; font-size: 0.85rem;">
                                    +<?= count($productsToBake) - 5 ?> meer producten →
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
