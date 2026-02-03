<?php
require_once '../config.php';
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
        ba.telefoon,
        ba.adres,
        ba.postcode,
        ba.plaats,
        ba.delivery_same_as_business,
        ba.delivery_adres,
        ba.delivery_postcode,
        ba.delivery_plaats
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    WHERE bo.delivery_date BETWEEN ? AND ?
    AND bo.is_cancelled = 0
    ORDER BY bo.delivery_date ASC
");
$stmt->execute([$deliveryStart->format('Y-m-d'), $deliveryEnd->format('Y-m-d')]);
$allOrders = $stmt->fetchAll();

foreach ($allOrders as &$order) {
    $stmt = $pdo->prepare("SELECT boi.product_name, boi.quantity, boi.unit_price, COALESCE(d.naam, 'Overig') as deegtype_naam FROM business_order_items boi LEFT JOIN products p ON boi.product_name = p.naam LEFT JOIN deegtypes d ON p.deegtype_id = d.id WHERE boi.order_id = ?");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll();
    
    $deliveryDate = new DateTime($order['delivery_date']);
    $deliveryDate->modify('-1 day');
    $order['bereiding_date'] = $deliveryDate->format('Y-m-d');
    
    if ($order['delivery_same_as_business'] || empty($order['delivery_adres'])) {
        $order['full_delivery_address'] = $order['adres'] . ', ' . $order['postcode'] . ' ' . $order['plaats'];
    } else {
        $order['full_delivery_address'] = $order['delivery_adres'] . ', ' . $order['delivery_postcode'] . ' ' . $order['delivery_plaats'];
    }
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
            background: #faf8f5;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e8e0d5;
        }
        .totals-section h4 {
            color: #5c3d1e;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e8e0d5;
        }
        .product-totals-list {
            background: white;
            border-radius: 8px;
            padding: 0.25rem 0.75rem;
        }
        .product-total-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.4rem 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.85rem;
        }
        .product-total-item:last-child { border-bottom: none; }
        .product-total-qty {
            font-weight: 700;
            color: #8b5a2b;
            margin-right: 0.4rem;
        }
        .product-total-name {
            color: #333;
        }
        .product-total-price {
            color: #666;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .totals-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 0.75rem;
        }
        .totals-tab {
            padding: 0.4rem 1rem;
            border: none;
            background: #e8e0d5;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            color: #8b7355;
            transition: all 0.2s;
        }
        .totals-tab:first-child { border-radius: 6px 0 0 6px; }
        .totals-tab:last-child { border-radius: 0 6px 6px 0; }
        .totals-tab.active {
            background: #8b5a2b;
            color: white;
        }
        .totals-tab:hover:not(.active) { background: #ddd5c8; }
        .totals-tab-content { display: none; }
        .totals-tab-content.active { display: block; }
        .deegtype-group-title {
            font-weight: 700;
            font-size: 0.8rem;
            color: #8b5a2b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.5rem 0 0.2rem;
            border-bottom: 2px solid #e8e0d5;
            margin-top: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .deegtype-group-title:first-child { margin-top: 0; }
        
        .orders-section {
            margin-top: 1rem;
        }
        .orders-section h4 {
            color: #5c3d1e;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }
        
        .order-row {
            display: flex;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f5f5f5;
            gap: 1rem;
            cursor: pointer;
            transition: background 0.15s;
        }
        .order-row:hover { background: #faf8f5; margin: 0 -1rem; padding-left: 1rem; padding-right: 1rem; }
        .order-row:last-child { border-bottom: none; }
        
        .order-info { flex: 1; min-width: 0; }
        .order-company {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.2rem;
        }
        .order-products-summary {
            font-size: 0.8rem;
            color: #888;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .order-badges {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        
        .order-amount {
            font-weight: 600;
            color: #5c3d1e;
            white-space: nowrap;
        }
        
        .status-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-badge.paid { background: #d1e7dd; color: #0f5132; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        
        
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
            <a href="../index.php"><i class="bi bi-house"></i> Admin</a>
        </div>
    </div>
    
    <div class="container">
        <div class="top-bar">
            <div class="breadcrumb">
                <a href="../index.php">Dashboard</a>
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
                                    if (!isset($productTotals[$name])) $productTotals[$name] = ['qty' => 0, 'amount' => 0];
                                    $productTotals[$name]['qty'] += $item['quantity'];
                                    $productTotals[$name]['amount'] += $item['quantity'] * $item['unit_price'];
                                }
                            }
                            uasort($productTotals, function($a, $b) { return $b['qty'] - $a['qty']; });
                            ?>
                            <?php
                            $deegtypeTotals = [];
                            foreach ($orders as $o) {
                                foreach ($o['items'] as $item) {
                                    $dt = $item['deegtype_naam'];
                                    $name = $item['product_name'];
                                    if (!isset($deegtypeTotals[$dt])) $deegtypeTotals[$dt] = [];
                                    if (!isset($deegtypeTotals[$dt][$name])) $deegtypeTotals[$dt][$name] = ['qty' => 0, 'amount' => 0];
                                    $deegtypeTotals[$dt][$name]['qty'] += $item['quantity'];
                                    $deegtypeTotals[$dt][$name]['amount'] += $item['quantity'] * $item['unit_price'];
                                }
                            }
                            ksort($deegtypeTotals);
                            ?>
                            <div class="totals-section">
                                <h4><i class="bi bi-list-check"></i> Totaal te bereiden</h4>
                                <div class="totals-tabs">
                                    <button class="totals-tab active" onclick="switchTotalsTab(this, 'producten')">Producten</button>
                                    <button class="totals-tab" onclick="switchTotalsTab(this, 'deegtypes')">Deegtypes</button>
                                </div>
                                <div class="totals-tab-content active" data-tab="producten">
                                    <div class="product-totals-list">
                                        <?php foreach ($productTotals as $product => $data): ?>
                                            <div class="product-total-item">
                                                <span><span class="product-total-qty"><?= $data['qty'] ?>x</span> <span class="product-total-name"><?= htmlspecialchars($product) ?></span></span>
                                                <span class="product-total-price">&euro;<?= number_format($data['amount'], 2, ',', '.') ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="totals-tab-content" data-tab="deegtypes">
                                    <div class="product-totals-list">
                                        <?php foreach ($deegtypeTotals as $deegtype => $products): ?>
                                            <?php $groupTotal = array_sum(array_column($products, 'qty')); ?>
                                            <div class="deegtype-group-title"><span><?= htmlspecialchars($deegtype) ?></span><span><?= $groupTotal ?></span></div>
                                            <?php foreach ($products as $product => $data): ?>
                                                <div class="product-total-item">
                                                    <span><span class="product-total-qty"><?= $data['qty'] ?>x</span> <span class="product-total-name"><?= htmlspecialchars($product) ?></span></span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="orders-section">
                                <h4><i class="bi bi-people"></i> Klanten (<?= count($orders) ?>)</h4>
                                <?php foreach ($orders as $order): 
                                    $items = array_map(function($i) { return $i['quantity'] . 'x ' . $i['product_name']; }, $order['items']);
                                    $itemsSummary = implode(', ', array_slice($items, 0, 3)) . (count($items) > 3 ? '...' : '');
                                ?>
                                    <div class="order-row" onclick='showOrderDetail(<?= json_encode($order) ?>)'>
                                        <div class="order-info">
                                            <div class="order-company"><?= htmlspecialchars($order['bedrijfsnaam']) ?></div>
                                            <div class="order-products-summary"><i class="bi bi-box"></i> <?= htmlspecialchars($itemsSummary) ?></div>
                                        </div>
                                        <div class="order-badges">
                                            <span class="status-badge <?= $order['payment_status'] ?>"><?= $order['payment_status'] === 'paid' ? 'Betaald' : 'Open' ?></span>
                                        </div>
                                        <span class="order-amount">€<?= number_format($order['total_amount'], 2, ',', '.') ?></span>
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
    
    <?php $detailAccentColor = '#8b5a2b'; $detailAccentColorDark = '#5c3d1e'; include 'order-detail-modal.php'; ?>

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
                    if (!productTotals[item.product_name]) productTotals[item.product_name] = { qty: 0, amount: 0 };
                    productTotals[item.product_name].qty += parseInt(item.quantity);
                    productTotals[item.product_name].amount += parseInt(item.quantity) * parseFloat(item.unit_price);
                });
            });
            
            const sortedProducts = Object.entries(productTotals).sort((a, b) => b[1].qty - a[1].qty);
            
            const deegtypeTotals = {};
            orders.forEach(order => {
                order.items.forEach(item => {
                    const dt = item.deegtype_naam || 'Overig';
                    if (!deegtypeTotals[dt]) deegtypeTotals[dt] = {};
                    if (!deegtypeTotals[dt][item.product_name]) deegtypeTotals[dt][item.product_name] = { qty: 0, amount: 0 };
                    deegtypeTotals[dt][item.product_name].qty += parseInt(item.quantity);
                    deegtypeTotals[dt][item.product_name].amount += parseInt(item.quantity) * parseFloat(item.unit_price);
                });
            });
            
            html += '<div class="totals-section"><h4><i class="bi bi-list-check"></i> Totaal te bereiden</h4>';
            html += '<div class="totals-tabs"><button class="totals-tab active" onclick="switchTotalsTab(this, \'producten\')">Producten</button><button class="totals-tab" onclick="switchTotalsTab(this, \'deegtypes\')">Deegtypes</button></div>';
            html += '<div class="totals-tab-content active" data-tab="producten"><div class="product-totals-list">';
            for (const [product, data] of sortedProducts) {
                html += `<div class="product-total-item"><span><span class="product-total-qty">${data.qty}x</span> <span class="product-total-name">${escapeHtml(product)}</span></span><span class="product-total-price">\u20AC${data.amount.toFixed(2).replace('.', ',')}</span></div>`;
            }
            html += '</div></div>';
            html += '<div class="totals-tab-content" data-tab="deegtypes"><div class="product-totals-list">';
            for (const dt of Object.keys(deegtypeTotals).sort()) {
                const dtTotal = Object.values(deegtypeTotals[dt]).reduce((sum, d) => sum + d.qty, 0);
                html += `<div class="deegtype-group-title"><span>${escapeHtml(dt)}</span><span>${dtTotal}</span></div>`;
                for (const [product, data] of Object.entries(deegtypeTotals[dt])) {
                    html += `<div class="product-total-item"><span><span class="product-total-qty">${data.qty}x</span> <span class="product-total-name">${escapeHtml(product)}</span></span></div>`;
                }
            }
            html += '</div></div></div>';
            
            html += `<div class="orders-section"><h4><i class="bi bi-people"></i> Klanten (${orders.length})</h4>`;
            orders.forEach(order => {
                const statusClass = order.payment_status === 'paid' ? 'paid' : 'pending';
                const statusText = order.payment_status === 'paid' ? 'Betaald' : 'Open';
                
                const items = order.items.map(i => i.quantity + 'x ' + i.product_name);
                const itemsSummary = items.slice(0, 3).join(', ') + (items.length > 3 ? '...' : '');
                
                html += `
                    <div class="order-row" onclick='showOrderDetail(${JSON.stringify(order).replace(/'/g, "&#39;")})'>
                        <div class="order-info">
                            <div class="order-company">${escapeHtml(order.bedrijfsnaam)}</div>
                            <div class="order-products-summary"><i class="bi bi-box"></i> ${escapeHtml(itemsSummary)}</div>
                        </div>
                        <div class="order-badges">
                            <span class="status-badge ${statusClass}">${statusText}</span>
                        </div>
                        <span class="order-amount">€${parseFloat(order.total_amount).toFixed(2).replace('.', ',')}</span>
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
    
    
    function switchTotalsTab(btn, tab) {
        const section = btn.closest('.totals-section');
        section.querySelectorAll('.totals-tab').forEach(t => t.classList.remove('active'));
        section.querySelectorAll('.totals-tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        section.querySelector(`.totals-tab-content[data-tab="${tab}"]`).classList.add('active');
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    document.getElementById('dayModal').addEventListener('click', function(e) {
        if (e.target === this) closeDayModal();
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
