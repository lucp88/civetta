<?php
require_once 'config.php';
requireLogin();

$viewDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$viewMode = isset($_GET['mode']) ? $_GET['mode'] : 'week';

$currentDate = new DateTime($viewDate);

if ($viewMode === 'day') {
    $startDate = clone $currentDate;
    $endDate = clone $currentDate;
} elseif ($viewMode === 'week') {
    $startDate = clone $currentDate;
    $startDate->modify('monday this week');
    $endDate = clone $startDate;
    $endDate->modify('+6 days');
} else {
    $startDate = clone $currentDate;
    $startDate->modify('first day of this month');
    $endDate = clone $startDate;
    $endDate->modify('last day of this month');
}

$deliveryStart = clone $startDate;
$deliveryStart->modify('+1 day');
$deliveryEnd = clone $endDate;
$deliveryEnd->modify('+1 day');

$stmt = $pdo->prepare("
    SELECT 
        bo.*, 
        ba.bedrijfsnaam, 
        ba.contactpersoon, 
        ba.email, 
        ba.telefoon
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    WHERE bo.delivery_date BETWEEN ? AND ?
    AND bo.is_cancelled = 0
    ORDER BY bo.delivery_date ASC
");
$stmt->execute([$deliveryStart->format('Y-m-d'), $deliveryEnd->format('Y-m-d')]);
$allOrders = $stmt->fetchAll();

foreach ($allOrders as &$order) {
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll();
    
    $deliveryDate = new DateTime($order['delivery_date']);
    $deliveryDate->modify('-1 day');
    $order['bereiding_date'] = $deliveryDate->format('Y-m-d');
}
unset($order);

$ordersByBereidingDate = [];
foreach ($allOrders as $order) {
    $date = $order['bereiding_date'];
    if (!isset($ordersByBereidingDate[$date])) {
        $ordersByBereidingDate[$date] = [];
    }
    $ordersByBereidingDate[$date][] = $order;
}

function getDutchDayName($date) {
    $dagen = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
    return $dagen[$date->format('w')];
}

function getDutchDayNameFull($date) {
    $dagen = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
    return $dagen[$date->format('w')];
}

function getDutchMonthName($date) {
    $maanden = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    return $maanden[$date->format('n') - 1];
}

function formatDutchDate($date) {
    return getDutchDayNameFull($date) . ' ' . $date->format('j') . ' ' . getDutchMonthName($date);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bereiden | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #ff6b35, #e55a2b);
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .breadcrumb a { color: #ff6b35; text-decoration: none; }
        .breadcrumb span { color: #888; margin: 0 0.5rem; }
        
        .nav-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .nav-btn {
            width: 36px;
            height: 36px;
            border: none;
            background: white;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            color: #e55a2b;
            font-size: 1.1rem;
        }
        .nav-btn:hover { background: #fff5f0; }
        .current-period {
            font-weight: 600;
            color: #e55a2b;
            min-width: 200px;
            text-align: center;
        }
        .today-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #ff6b35;
            background: white;
            color: #ff6b35;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        .today-btn:hover { background: #ff6b35; color: white; }
        
        .view-tabs {
            display: flex;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .view-tab {
            padding: 0.6rem 1.2rem;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 0.9rem;
            color: #666;
            transition: all 0.2s;
        }
        .view-tab:hover { background: #fff5f0; }
        .view-tab.active {
            background: linear-gradient(135deg, #ff6b35, #e55a2b);
            color: white;
        }
        
        .calendar-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .calendar-grid {
            display: grid;
            gap: 1px;
            background: #e8e8e8;
        }
        .calendar-grid.week-view { grid-template-columns: repeat(7, 1fr); }
        .calendar-grid.day-view { grid-template-columns: 1fr; }
        .calendar-grid.month-view { grid-template-columns: repeat(7, 1fr); }
        
        .calendar-header-cell {
            background: #e55a2b;
            color: white;
            padding: 0.75rem;
            text-align: center;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        
        .calendar-cell {
            background: white;
            min-height: 120px;
            padding: 0.75rem;
            cursor: pointer;
            transition: all 0.15s;
            position: relative;
        }
        .calendar-cell:hover { background: #fff5f0; }
        .calendar-cell.other-month { background: #faf8f5; }
        .calendar-cell.other-month:hover { background: #f5f2ed; }
        .calendar-cell.today { background: #fff8e1; border: 2px solid #ff6b35; }
        .calendar-cell.selected { background: #ffe0d0; }
        
        .calendar-date {
            font-weight: 600;
            font-size: 1rem;
            color: #333;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .calendar-cell.other-month .calendar-date { color: #bbb; }
        .calendar-cell.today .calendar-date { color: #e55a2b; }
        
        .calendar-count {
            background: #ff6b35;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .calendar-count.empty { background: #ddd; color: #999; }
        
        .calendar-preview {
            font-size: 0.75rem;
            color: #666;
        }
        .calendar-preview-item {
            padding: 0.2rem 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .day-view-cell {
            min-height: 400px;
        }
        .day-view-cell .calendar-date {
            font-size: 1.3rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #eee;
            margin-bottom: 1rem;
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1rem;
        }
        .modal-overlay.active { display: flex; }
        
        .modal {
            background: white;
            border-radius: 12px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #ff6b35, #e55a2b);
            color: white;
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.2rem;
            color: white;
        }
        .modal-close:hover { background: rgba(255,255,255,0.3); }
        
        .modal-body { padding: 1.25rem; }
        
        .totals-section {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid #a5d6a7;
        }
        .totals-section h4 {
            color: #2e7d32;
            margin-bottom: 1rem;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .totals-section h4 i {
            font-size: 1.2rem;
        }
        .product-totals-list {
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        .product-total-row {
            display: flex;
            align-items: center;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #e8f5e9;
        }
        .product-total-row:last-child { border-bottom: none; }
        .product-total-row:nth-child(odd) { background: #fafafa; }
        .product-total-qty {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #4caf50, #388e3c);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        .product-total-name {
            flex: 1;
            font-weight: 500;
            color: #333;
            font-size: 1rem;
        }
        .product-total-label {
            font-size: 0.75rem;
            color: #888;
            text-transform: uppercase;
        }
        
        .orders-section {
            margin-top: 1.5rem;
        }
        .orders-section h4 {
            color: #e55a2b;
            margin-bottom: 1rem;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #ffe0d0;
        }
        
        .order-card {
            background: white;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #eee;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .order-card:hover { 
            background: #fff5f0; 
            border-color: #ff6b35;
            box-shadow: 0 4px 12px rgba(255,107,53,0.15);
            transform: translateY(-2px);
        }
        
        .order-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        .order-card-company {
            font-weight: 600;
            color: #333;
            font-size: 1.05rem;
        }
        .order-card-badges {
            display: flex;
            gap: 0.4rem;
        }
        
        .order-card-products {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .order-product-tag {
            background: #f5f2ed;
            padding: 0.35rem 0.7rem;
            border-radius: 6px;
            font-size: 0.8rem;
            color: #5c3d1e;
        }
        .order-product-tag strong {
            color: #e55a2b;
            margin-right: 0.2rem;
        }
        
        .order-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #f0f0f0;
            font-size: 0.85rem;
            color: #888;
        }
        .order-card-footer i { margin-right: 0.3rem; }
        
        .status-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-badge.paid { background: #d1e7dd; color: #0f5132; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        
        .detail-modal {
            max-width: 600px;
        }
        .detail-modal .modal-header {
            background: linear-gradient(135deg, #5c3d1e, #8b5a2b);
        }
        
        .detail-section {
            margin-bottom: 1.5rem;
        }
        .detail-section-title {
            font-size: 0.8rem;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .detail-card {
            background: #faf8f5;
            border-radius: 10px;
            padding: 1rem;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 500px) {
            .detail-grid { grid-template-columns: 1fr; }
        }
        .detail-item label {
            display: block;
            font-size: 0.7rem;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 0.3rem;
            letter-spacing: 0.5px;
        }
        .detail-item .value {
            color: #333;
            font-weight: 500;
            font-size: 0.95rem;
        }
        .detail-item .value a { color: #e55a2b; text-decoration: none; }
        .detail-item .value a:hover { text-decoration: underline; }
        
        .product-list {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #eee;
        }
        .product-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #f5f5f5;
        }
        .product-row:last-child { border-bottom: none; }
        .product-row:nth-child(odd) { background: #fafafa; }
        .product-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .product-qty {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #ff6b35, #e55a2b);
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
        }
        .product-name {
            font-weight: 500;
            color: #333;
        }
        .product-price {
            color: #666;
            font-weight: 500;
        }
        
        .detail-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: linear-gradient(135deg, #5c3d1e, #8b5a2b);
            border-radius: 10px;
            color: white;
            margin-top: 1rem;
        }
        .detail-total-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .detail-total-value {
            font-size: 1.4rem;
            font-weight: 700;
        }
        
        .status-flow {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .status-step {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.4rem 0.7rem;
            background: #f0f0f0;
            border-radius: 20px;
            font-size: 0.75rem;
            color: #999;
        }
        .status-step.active {
            background: #d1e7dd;
            color: #0f5132;
        }
        .status-step.current {
            background: linear-gradient(135deg, #ff6b35, #e55a2b);
            color: white;
        }
        .status-arrow {
            color: #ddd;
            font-size: 0.8rem;
        }
        
        .contact-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        .contact-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.7rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;
            transition: all 0.15s;
        }
        .contact-btn.phone {
            background: #e3f2fd;
            color: #1976d2;
        }
        .contact-btn.phone:hover { background: #bbdefb; }
        .contact-btn.email {
            background: #fce4ec;
            color: #c2185b;
        }
        .contact-btn.email:hover { background: #f8bbd9; }
        
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #999;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
        .empty-state p {
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="bi bi-fire"></i> Bereiden</h1>
        <div class="header-links">
            <a href="leveren.php"><i class="bi bi-truck"></i> Leveren</a>
            <a href="bakker-dashboard.php"><i class="bi bi-grid"></i> Overzicht</a>
            <a href="index.php"><i class="bi bi-house"></i> Admin</a>
        </div>
    </div>
    
    <div class="container">
        <div class="top-bar">
            <div class="breadcrumb">
                <a href="index.php">Dashboard</a>
                <span>›</span>
                <a href="bakker-dashboard.php">Bakker</a>
                <span>›</span>
                Bereiden
            </div>
            
            <div class="nav-controls">
                <button class="nav-btn" onclick="navigate(-1)"><i class="bi bi-chevron-left"></i></button>
                <span class="current-period">
                    <?php
                    if ($viewMode === 'day') {
                        echo formatDutchDate($currentDate);
                    } elseif ($viewMode === 'week') {
                        echo 'Week ' . $startDate->format('W') . ' - ' . getDutchMonthName($startDate) . ' ' . $startDate->format('Y');
                    } else {
                        echo ucfirst(getDutchMonthName($currentDate)) . ' ' . $currentDate->format('Y');
                    }
                    ?>
                </span>
                <button class="nav-btn" onclick="navigate(1)"><i class="bi bi-chevron-right"></i></button>
                <button class="today-btn" onclick="goToday()">Vandaag</button>
            </div>
            
            <div class="view-tabs">
                <button class="view-tab <?= $viewMode === 'day' ? 'active' : '' ?>" onclick="setViewMode('day')">
                    <i class="bi bi-calendar-day"></i> Dag
                </button>
                <button class="view-tab <?= $viewMode === 'week' ? 'active' : '' ?>" onclick="setViewMode('week')">
                    <i class="bi bi-calendar-week"></i> Week
                </button>
                <button class="view-tab <?= $viewMode === 'month' ? 'active' : '' ?>" onclick="setViewMode('month')">
                    <i class="bi bi-calendar-month"></i> Maand
                </button>
            </div>
        </div>
        
        <div class="calendar-container">
            <?php if ($viewMode === 'day'): ?>
                <?php
                $dateKey = $currentDate->format('Y-m-d');
                $orders = $ordersByBereidingDate[$dateKey] ?? [];
                $isToday = $dateKey === date('Y-m-d');
                ?>
                <div class="calendar-grid day-view">
                    <div class="calendar-cell day-view-cell <?= $isToday ? 'today' : '' ?>">
                        <div class="calendar-date">
                            <span><?= formatDutchDate($currentDate) ?></span>
                            <span class="calendar-count <?= count($orders) === 0 ? 'empty' : '' ?>">
                                <?= count($orders) ?> bestelling<?= count($orders) !== 1 ? 'en' : '' ?>
                            </span>
                        </div>
                        <?php if (empty($orders)): ?>
                            <div class="empty-state">
                                <i class="bi bi-emoji-smile"></i>
                                <p>Geen bestellingen om te bereiden</p>
                            </div>
                        <?php else: ?>
                            <?php
                            $productTotals = [];
                            foreach ($orders as $order) {
                                foreach ($order['items'] as $item) {
                                    $name = $item['product_name'];
                                    if (!isset($productTotals[$name])) $productTotals[$name] = 0;
                                    $productTotals[$name] += $item['quantity'];
                                }
                            }
                            arsort($productTotals);
                            ?>
                            <div class="totals-section">
                                <h4><i class="bi bi-list-check"></i> Totaal te bereiden</h4>
                                <div class="product-totals-list">
                                    <?php foreach ($productTotals as $product => $qty): ?>
                                        <div class="product-total-row">
                                            <div class="product-total-qty"><?= $qty ?></div>
                                            <div class="product-total-name"><?= htmlspecialchars($product) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="orders-section">
                                <h4><i class="bi bi-people"></i> Klanten (<?= count($orders) ?>)</h4>
                                <?php foreach ($orders as $order): ?>
                                    <div class="order-card" onclick='showOrderDetail(<?= json_encode($order) ?>)'>
                                        <div class="order-card-header">
                                            <span class="order-card-company"><?= htmlspecialchars($order['bedrijfsnaam']) ?></span>
                                            <div class="order-card-badges">
                                                <span class="status-badge <?= $order['payment_status'] ?>"><?= $order['payment_status'] === 'paid' ? 'Betaald' : 'Open' ?></span>
                                            </div>
                                        </div>
                                        <div class="order-card-products">
                                            <?php foreach ($order['items'] as $item): ?>
                                                <span class="order-product-tag"><strong><?= $item['quantity'] ?>x</strong> <?= htmlspecialchars($item['product_name']) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="order-card-footer">
                                            <span><i class="bi bi-calendar3"></i> Levering: <?= date('j M', strtotime($order['delivery_date'])) ?></span>
                                            <span><i class="bi bi-currency-euro"></i> €<?= number_format($order['total_amount'], 2, ',', '.') ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            
            <?php elseif ($viewMode === 'week'): ?>
                <div class="calendar-grid week-view">
                    <?php
                    $dayNames = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];
                    foreach ($dayNames as $day): ?>
                        <div class="calendar-header-cell"><?= $day ?></div>
                    <?php endforeach; ?>
                    
                    <?php
                    $current = clone $startDate;
                    for ($i = 0; $i < 7; $i++):
                        $dateKey = $current->format('Y-m-d');
                        $orders = $ordersByBereidingDate[$dateKey] ?? [];
                        $isToday = $dateKey === date('Y-m-d');
                    ?>
                        <div class="calendar-cell <?= $isToday ? 'today' : '' ?>" 
                             onclick="openDayModal('<?= $dateKey ?>', '<?= formatDutchDate($current) ?>')">
                            <div class="calendar-date">
                                <span><?= $current->format('j') ?></span>
                                <span class="calendar-count <?= count($orders) === 0 ? 'empty' : '' ?>"><?= count($orders) ?></span>
                            </div>
                            <div class="calendar-preview">
                                <?php foreach (array_slice($orders, 0, 3) as $order): ?>
                                    <div class="calendar-preview-item"><?= htmlspecialchars($order['bedrijfsnaam']) ?></div>
                                <?php endforeach; ?>
                                <?php if (count($orders) > 3): ?>
                                    <div class="calendar-preview-item" style="color: #ff6b35;">+<?= count($orders) - 3 ?> meer</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php
                        $current->modify('+1 day');
                    endfor;
                    ?>
                </div>
            
            <?php else: ?>
                <div class="calendar-grid month-view">
                    <?php
                    $dayNames = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];
                    foreach ($dayNames as $day): ?>
                        <div class="calendar-header-cell"><?= $day ?></div>
                    <?php endforeach; ?>
                    
                    <?php
                    $firstDay = clone $startDate;
                    $dayOfWeek = ($firstDay->format('N') - 1);
                    $firstDay->modify("-{$dayOfWeek} days");
                    
                    $current = clone $firstDay;
                    $currentMonth = $startDate->format('m');
                    
                    for ($week = 0; $week < 6; $week++):
                        $weekHasDaysInMonth = false;
                        $weekDates = [];
                        for ($day = 0; $day < 7; $day++) {
                            $weekDates[] = clone $current;
                            if ($current->format('m') === $currentMonth) $weekHasDaysInMonth = true;
                            $current->modify('+1 day');
                        }
                        if (!$weekHasDaysInMonth && $week > 0) break;
                        
                        foreach ($weekDates as $date):
                            $dateKey = $date->format('Y-m-d');
                            $orders = $ordersByBereidingDate[$dateKey] ?? [];
                            $isToday = $dateKey === date('Y-m-d');
                            $isOtherMonth = $date->format('m') !== $currentMonth;
                    ?>
                        <div class="calendar-cell <?= $isToday ? 'today' : '' ?> <?= $isOtherMonth ? 'other-month' : '' ?>" 
                             onclick="openDayModal('<?= $dateKey ?>', '<?= formatDutchDate($date) ?>')">
                            <div class="calendar-date">
                                <span><?= $date->format('j') ?></span>
                                <?php if (count($orders) > 0): ?>
                                    <span class="calendar-count"><?= count($orders) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$isOtherMonth && count($orders) > 0): ?>
                                <div class="calendar-preview">
                                    <?php foreach (array_slice($orders, 0, 2) as $order): ?>
                                        <div class="calendar-preview-item"><?= htmlspecialchars($order['bedrijfsnaam']) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php
                        endforeach;
                    endfor;
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="modal-overlay" id="dayModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="bi bi-fire"></i> Bereiden - <span id="dayModalDate"></span></h3>
                <button class="modal-close" onclick="closeDayModal()">&times;</button>
            </div>
            <div class="modal-body" id="dayModalBody">
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="orderModal">
        <div class="modal detail-modal">
            <div class="modal-header">
                <h3><i class="bi bi-box"></i> Bestelling <span id="orderModalId"></span></h3>
                <button class="modal-close" onclick="closeOrderModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="orderStatusFlow"></div>
                
                <div class="detail-section">
                    <div class="detail-section-title"><i class="bi bi-building"></i> Klantgegevens</div>
                    <div class="detail-card">
                        <div class="detail-grid" style="margin-bottom: 0;">
                            <div class="detail-item">
                                <label>Bedrijf</label>
                                <div class="value" id="orderCompany"></div>
                            </div>
                            <div class="detail-item">
                                <label>Contactpersoon</label>
                                <div class="value" id="orderContact"></div>
                            </div>
                            <div class="detail-item">
                                <label>Telefoon</label>
                                <div class="value"><a id="orderPhone" href=""></a></div>
                            </div>
                            <div class="detail-item">
                                <label>E-mail</label>
                                <div class="value"><a id="orderEmail" href=""></a></div>
                            </div>
                            <div class="detail-item">
                                <label>Leverdatum</label>
                                <div class="value" id="orderDeliveryDate"></div>
                            </div>
                            <div class="detail-item">
                                <label>Betaalstatus</label>
                                <div class="value" id="orderPaymentStatus"></div>
                            </div>
                        </div>
                        <div class="contact-actions" id="orderContactActions"></div>
                    </div>
                </div>
                
                <div class="detail-section">
                    <div class="detail-section-title"><i class="bi bi-basket"></i> Producten</div>
                    <div class="product-list" id="orderProducts"></div>
                    <div class="detail-total">
                        <span class="detail-total-label">Totaal</span>
                        <span class="detail-total-value" id="orderTotal"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    const currentDate = '<?= $viewDate ?>';
    const currentMode = '<?= $viewMode ?>';
    const ordersByDate = <?= json_encode($ordersByBereidingDate) ?>;
    
    function navigate(direction) {
        const date = new Date(currentDate);
        if (currentMode === 'day') {
            date.setDate(date.getDate() + direction);
        } else if (currentMode === 'week') {
            date.setDate(date.getDate() + (direction * 7));
        } else {
            date.setMonth(date.getMonth() + direction);
        }
        window.location.href = `?date=${date.toISOString().split('T')[0]}&mode=${currentMode}`;
    }
    
    function goToday() {
        window.location.href = `?date=${new Date().toISOString().split('T')[0]}&mode=${currentMode}`;
    }
    
    function setViewMode(mode) {
        window.location.href = `?date=${currentDate}&mode=${mode}`;
    }
    
    function openDayModal(date, dateLabel) {
        document.getElementById('dayModalDate').textContent = dateLabel;
        
        const orders = ordersByDate[date] || [];
        let html = '';
        
        if (orders.length === 0) {
            html = '<div class="empty-state"><i class="bi bi-emoji-smile"></i><p>Geen bestellingen om te bereiden</p></div>';
        } else {
            const productTotals = {};
            orders.forEach(order => {
                order.items.forEach(item => {
                    if (!productTotals[item.product_name]) productTotals[item.product_name] = 0;
                    productTotals[item.product_name] += item.quantity;
                });
            });
            
            const sortedProducts = Object.entries(productTotals).sort((a, b) => b[1] - a[1]);
            
            html += '<div class="totals-section"><h4><i class="bi bi-list-check"></i> Totaal te bereiden</h4><div class="product-totals-list">';
            for (const [product, qty] of sortedProducts) {
                html += `<div class="product-total-row"><div class="product-total-qty">${qty}</div><div class="product-total-name">${escapeHtml(product)}</div></div>`;
            }
            html += '</div></div>';
            
            html += `<div class="orders-section"><h4><i class="bi bi-people"></i> Klanten (${orders.length})</h4>`;
            orders.forEach(order => {
                const statusClass = order.payment_status === 'paid' ? 'paid' : 'pending';
                const statusText = order.payment_status === 'paid' ? 'Betaald' : 'Open';
                const deliveryDate = new Date(order.delivery_date);
                const deliveryStr = deliveryDate.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' });
                
                let productTags = '';
                order.items.forEach(item => {
                    productTags += `<span class="order-product-tag"><strong>${item.quantity}x</strong> ${escapeHtml(item.product_name)}</span>`;
                });
                
                html += `
                    <div class="order-card" onclick='showOrderDetail(${JSON.stringify(order).replace(/'/g, "&#39;")})'>
                        <div class="order-card-header">
                            <span class="order-card-company">${escapeHtml(order.bedrijfsnaam)}</span>
                            <div class="order-card-badges">
                                <span class="status-badge ${statusClass}">${statusText}</span>
                            </div>
                        </div>
                        <div class="order-card-products">${productTags}</div>
                        <div class="order-card-footer">
                            <span><i class="bi bi-calendar3"></i> Levering: ${deliveryStr}</span>
                            <span><i class="bi bi-currency-euro"></i> €${parseFloat(order.total_amount).toFixed(2).replace('.', ',')}</span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
        }
        
        document.getElementById('dayModalBody').innerHTML = html;
        document.getElementById('dayModal').classList.add('active');
    }
    
    function closeDayModal() {
        document.getElementById('dayModal').classList.remove('active');
    }
    
    function showOrderDetail(order) {
        const deliveryStatus = order.delivery_status || 'geplaatst';
        const statuses = ['geplaatst', 'wordt_bereid', 'onderweg', 'afgeleverd'];
        const statusLabels = { geplaatst: 'Geplaatst', wordt_bereid: 'Wordt bereid', onderweg: 'Onderweg', afgeleverd: 'Afgeleverd' };
        const currentIdx = statuses.indexOf(deliveryStatus);
        
        let statusFlowHtml = '<div class="status-flow">';
        statuses.forEach((status, idx) => {
            let cls = 'status-step';
            if (idx < currentIdx) cls += ' active';
            if (idx === currentIdx) cls += ' current';
            statusFlowHtml += `<div class="${cls}"><i class="bi bi-${idx === 0 ? 'cart' : idx === 1 ? 'fire' : idx === 2 ? 'truck' : 'check-circle'}"></i> ${statusLabels[status]}</div>`;
            if (idx < statuses.length - 1) statusFlowHtml += '<span class="status-arrow">→</span>';
        });
        statusFlowHtml += '</div>';
        
        document.getElementById('orderModalId').textContent = '#' + order.id;
        document.getElementById('orderCompany').textContent = order.bedrijfsnaam;
        document.getElementById('orderContact').textContent = order.contactpersoon || '-';
        
        const phoneEl = document.getElementById('orderPhone');
        phoneEl.textContent = order.telefoon || '-';
        phoneEl.href = order.telefoon ? 'tel:' + order.telefoon : '#';
        
        const emailEl = document.getElementById('orderEmail');
        emailEl.textContent = order.email || '-';
        emailEl.href = order.email ? 'mailto:' + order.email : '#';
        
        const deliveryDate = new Date(order.delivery_date);
        document.getElementById('orderDeliveryDate').textContent = deliveryDate.toLocaleDateString('nl-NL', { weekday: 'long', day: 'numeric', month: 'long' });
        
        const statusHtml = order.payment_status === 'paid' 
            ? '<span class="status-badge paid">Betaald</span>'
            : '<span class="status-badge pending">Openstaand</span>';
        document.getElementById('orderPaymentStatus').innerHTML = statusHtml;
        
        document.getElementById('orderStatusFlow').innerHTML = statusFlowHtml;
        
        let contactHtml = '';
        if (order.telefoon) {
            contactHtml += `<a href="tel:${order.telefoon}" class="contact-btn phone"><i class="bi bi-telephone"></i> Bellen</a>`;
        }
        if (order.email) {
            contactHtml += `<a href="mailto:${order.email}" class="contact-btn email"><i class="bi bi-envelope"></i> E-mail</a>`;
        }
        document.getElementById('orderContactActions').innerHTML = contactHtml;
        
        let productsHtml = '';
        order.items.forEach(item => {
            const lineTotal = item.quantity * item.unit_price;
            productsHtml += `
                <div class="product-row">
                    <div class="product-info">
                        <div class="product-qty">${item.quantity}</div>
                        <div class="product-name">${escapeHtml(item.product_name)}</div>
                    </div>
                    <div class="product-price">€${lineTotal.toFixed(2).replace('.', ',')}</div>
                </div>
            `;
        });
        document.getElementById('orderProducts').innerHTML = productsHtml;
        document.getElementById('orderTotal').textContent = '€' + parseFloat(order.total_amount).toFixed(2).replace('.', ',');
        
        document.getElementById('orderModal').classList.add('active');
    }
    
    function closeOrderModal() {
        document.getElementById('orderModal').classList.remove('active');
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    document.getElementById('dayModal').addEventListener('click', function(e) {
        if (e.target === this) closeDayModal();
    });
    
    document.getElementById('orderModal').addEventListener('click', function(e) {
        if (e.target === this) closeOrderModal();
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDayModal();
            closeOrderModal();
        }
    });
    </script>
</body>
</html>
