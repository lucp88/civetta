<?php
require_once 'config.php';
requireLogin();

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bedrijf_adres'");
$startAdres = $stmt->fetchColumn() ?: '';
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bedrijf_postcode'");
$startPostcode = $stmt->fetchColumn() ?: '';
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bedrijf_plaats'");
$startPlaats = $stmt->fetchColumn() ?: '';
$bakkerijAdres = trim($startAdres . ', ' . $startPostcode . ' ' . $startPlaats, ', ');

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
$stmt->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$allOrders = $stmt->fetchAll();

foreach ($allOrders as &$order) {
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll();
    
    if ($order['delivery_same_as_business'] || empty($order['delivery_adres'])) {
        $order['full_delivery_address'] = $order['adres'] . ', ' . $order['postcode'] . ' ' . $order['plaats'];
    } else {
        $order['full_delivery_address'] = $order['delivery_adres'] . ', ' . $order['delivery_postcode'] . ' ' . $order['delivery_plaats'];
    }
}
unset($order);

$ordersByDate = [];
foreach ($allOrders as $order) {
    $date = $order['delivery_date'];
    if (!isset($ordersByDate[$date])) {
        $ordersByDate[$date] = [];
    }
    $ordersByDate[$date][] = $order;
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
    <title>Leveren | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #2196f3, #1976d2);
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
        
        .breadcrumb a { color: #1976d2; text-decoration: none; }
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
            color: #1976d2;
            font-size: 1.1rem;
        }
        .nav-btn:hover { background: #e3f2fd; }
        .current-period {
            font-weight: 600;
            color: #1976d2;
            min-width: 200px;
            text-align: center;
        }
        .today-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #2196f3;
            background: white;
            color: #2196f3;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        .today-btn:hover { background: #2196f3; color: white; }
        
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
        .view-tab:hover { background: #e3f2fd; }
        .view-tab.active {
            background: linear-gradient(135deg, #2196f3, #1976d2);
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
            background: #1976d2;
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
        .calendar-cell:hover { background: #e3f2fd; }
        .calendar-cell.other-month { background: #faf8f5; }
        .calendar-cell.other-month:hover { background: #f5f2ed; }
        .calendar-cell.today { background: #e3f2fd; border: 2px solid #2196f3; }
        
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
        .calendar-cell.today .calendar-date { color: #1976d2; }
        
        .calendar-count {
            background: #2196f3;
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
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .calendar-preview-item i { color: #2196f3; }
        
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
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #2196f3, #1976d2);
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
        
        .modal-body { padding: 0; }
        
        .route-summary {
            display: flex;
            gap: 2rem;
            padding: 1rem 1.25rem;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
        }
        .route-stat { text-align: center; }
        .route-stat-value { font-size: 1.5rem; font-weight: 700; color: #1976d2; }
        .route-stat-label { font-size: 0.75rem; color: #666; text-transform: uppercase; }
        
        .route-actions {
            display: flex;
            gap: 1rem;
            padding: 1rem 1.25rem;
            background: white;
            border-bottom: 1px solid #eee;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.9rem;
            border: none;
        }
        .btn-onderweg {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
        }
        .btn-onderweg:hover { background: linear-gradient(135deg, #f57c00, #e65100); }
        .btn-onderweg:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-route {
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
        }
        .btn-outline {
            background: white;
            border: 2px solid #2196f3;
            color: #2196f3;
        }
        .btn-outline:hover { background: #e3f2fd; }
        .btn-delivered { background: #4caf50; color: white; }
        .btn-delivered:hover { background: #388e3c; }
        .btn-delivered.done { background: #e8f5e9; color: #2e7d32; cursor: default; }
        
        .email-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: #666;
        }
        .email-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #2196f3;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 1rem 1.25rem;
            display: none;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
        }
        .success-message.show { display: flex; }
        
        .route-stops {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .route-point {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
        }
        .route-point.start { background: #e8f5e9; border-bottom: 1px solid #c8e6c9; }
        .route-point.end { background: #fff3e0; border-top: 1px solid #ffe0b2; }
        .route-point .marker {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .route-point.start .marker { background: #4caf50; color: white; }
        .route-point.end .marker { background: #ff9800; color: white; }
        .route-point .info h4 { margin: 0; font-size: 0.95rem; }
        .route-point.start .info h4 { color: #2e7d32; }
        .route-point.end .info h4 { color: #e65100; }
        .route-point .info p { margin: 0.25rem 0 0; font-size: 0.85rem; color: #666; }
        
        .route-stop {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .route-stop:hover { background: #fafafa; }
        .route-stop.delivered { background: #f0f9f0; }
        .route-stop .marker {
            width: 36px;
            height: 36px;
            background: #1976d2;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .route-stop.delivered .marker { background: #4caf50; }
        .route-stop .info { flex: 1; }
        .route-stop .info h4 { margin: 0 0 0.25rem; color: #333; font-size: 1rem; }
        .route-stop .info .address { color: #666; font-size: 0.85rem; display: flex; align-items: center; gap: 0.3rem; }
        .route-stop .info .products { color: #888; font-size: 0.8rem; margin-top: 0.3rem; }
        .route-stop .info .badges { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .route-stop .actions { display: flex; gap: 0.5rem; align-items: center; }
        .route-stop .actions .btn { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
        
        .connector {
            width: 2px;
            height: 20px;
            background: #e0e0e0;
            margin: -5px 0 -5px 17px;
        }
        
        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-badge.paid { background: #d1e7dd; color: #0f5132; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        .status-badge.onderweg { background: #e3f2fd; color: #1565c0; }
        .status-badge.afgeleverd { background: #d1e7dd; color: #0f5132; }
        
        .detail-modal { max-width: 600px; }
        .detail-modal .modal-body { padding: 1.25rem; }
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .detail-item label {
            display: block;
            font-size: 0.75rem;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }
        .detail-item .value { color: #333; font-weight: 500; }
        .detail-item .value a { color: #1976d2; text-decoration: none; }
        .detail-item .value a:hover { text-decoration: underline; }
        
        .product-list {
            background: #faf8f5;
            border-radius: 8px;
            padding: 0.75rem;
        }
        .product-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }
        .product-row:last-child { border-bottom: none; }
        .product-qty { font-weight: 600; color: #1976d2; margin-right: 0.5rem; }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #999;
        }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
        
        .day-content { padding: 1rem; }
        .day-content .route-actions { padding: 0; margin-bottom: 1rem; background: transparent; border: none; }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="bi bi-truck"></i> Leveren</h1>
        <div class="header-links">
            <a href="bereiden.php"><i class="bi bi-fire"></i> Bereiden</a>
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
                Leveren
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
                $orders = $ordersByDate[$dateKey] ?? [];
                $isToday = $dateKey === date('Y-m-d');
                ?>
                <div class="calendar-grid day-view">
                    <div class="calendar-cell day-view-cell <?= $isToday ? 'today' : '' ?>" style="cursor: default;">
                        <div class="calendar-date">
                            <span><?= formatDutchDate($currentDate) ?></span>
                            <span class="calendar-count <?= count($orders) === 0 ? 'empty' : '' ?>">
                                <?= count($orders) ?> stop<?= count($orders) !== 1 ? 's' : '' ?>
                            </span>
                        </div>
                        <?php if (empty($orders)): ?>
                            <div class="empty-state">
                                <i class="bi bi-emoji-smile"></i>
                                <p>Geen leveringen vandaag</p>
                            </div>
                        <?php else: ?>
                            <div class="day-content" id="dayContent" data-date="<?= $dateKey ?>">
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
                        $orders = $ordersByDate[$dateKey] ?? [];
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
                                    <div class="calendar-preview-item">
                                        <i class="bi bi-geo-alt-fill"></i>
                                        <?= htmlspecialchars($order['bedrijfsnaam']) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($orders) > 3): ?>
                                    <div class="calendar-preview-item" style="color: #1976d2;">+<?= count($orders) - 3 ?> meer</div>
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
                            $orders = $ordersByDate[$dateKey] ?? [];
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
                                        <div class="calendar-preview-item">
                                            <i class="bi bi-geo-alt-fill"></i>
                                            <?= htmlspecialchars($order['bedrijfsnaam']) ?>
                                        </div>
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
                <h3><i class="bi bi-truck"></i> Leveringen - <span id="dayModalDate"></span></h3>
                <button class="modal-close" onclick="closeDayModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="success-message" id="successMessage">
                    <i class="bi bi-check-circle-fill"></i>
                    <span id="successText"></span>
                </div>
                <div class="route-summary" id="routeSummary">
                    <div class="route-stat">
                        <div class="route-stat-value" id="stopCount">0</div>
                        <div class="route-stat-label">Stops</div>
                    </div>
                    <div class="route-stat">
                        <div class="route-stat-value" id="totalAmount">€0</div>
                        <div class="route-stat-label">Totaal</div>
                    </div>
                    <div class="route-stat">
                        <div class="route-stat-value" id="deliveredCount">0/0</div>
                        <div class="route-stat-label">Afgeleverd</div>
                    </div>
                </div>
                <div class="route-actions">
                    <button class="btn btn-onderweg" id="btnStartRoute" onclick="startRoute()">
                        <i class="bi bi-truck"></i> Start Route
                    </button>
                    <label class="email-toggle">
                        <input type="checkbox" id="sendEmails" checked>
                        Stuur emails naar klanten
                    </label>
                    <a id="googleMapsBtn" href="#" target="_blank" class="btn btn-route" style="margin-left: auto;">
                        <i class="bi bi-map"></i> Google Maps
                    </a>
                </div>
                <div class="route-stops" id="routeStops">
                </div>
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
                <div class="detail-grid">
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
                    <div class="detail-item" style="grid-column: 1/-1;">
                        <label>Leveradres</label>
                        <div class="value" id="orderAddress"></div>
                    </div>
                </div>
                <h4 style="color: #1976d2; margin-bottom: 0.75rem;">Producten</h4>
                <div class="product-list" id="orderProducts"></div>
                <div style="margin-top: 1rem; text-align: right; font-size: 1.2rem; font-weight: 600; color: #1976d2;">
                    Totaal: <span id="orderTotal"></span>
                </div>
            </div>
        </div>
    </div>

    <script>
    const currentDate = '<?= $viewDate ?>';
    const currentMode = '<?= $viewMode ?>';
    const bakkerijAdres = '<?= addslashes($bakkerijAdres ?: 'Leersum, Utrecht') ?>';
    
    let currentDayOrders = [];
    let currentDayDate = null;
    
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
    
    async function openDayModal(date, dateLabel) {
        currentDayDate = date;
        document.getElementById('dayModalDate').textContent = dateLabel;
        document.getElementById('successMessage').classList.remove('show');
        document.getElementById('dayModal').classList.add('active');
        
        await loadRouteData(date);
    }
    
    async function loadRouteData(date) {
        try {
            const response = await fetch(`../api/delivery-route.php?date=${date}`);
            const data = await response.json();
            
            if (data.success) {
                currentDayOrders = data.orders;
                renderRoute(data.orders);
                updateSummary(data.orders);
                updateGoogleMapsLink(data.orders);
                updateStartButton(data.orders);
            }
        } catch (error) {
            console.error('Error loading route:', error);
        }
    }
    
    function renderRoute(orders) {
        let html = `
            <div class="route-point start">
                <div class="marker"><i class="bi bi-house-fill"></i></div>
                <div class="info">
                    <h4>Startpunt: Bakkerij</h4>
                    <p>${escapeHtml(bakkerijAdres)}</p>
                </div>
            </div>
        `;
        
        orders.forEach((order, idx) => {
            const isDelivered = order.delivery_status === 'afgeleverd';
            const isOnRoute = order.delivery_status === 'onderweg';
            const products = order.items.map(i => `${i.quantity}x ${i.product_name}`).join(', ');
            
            html += `
                <div class="connector"></div>
                <div class="route-stop ${isDelivered ? 'delivered' : ''}" data-order-id="${order.id}">
                    <div class="marker">${idx + 1}</div>
                    <div class="info">
                        <h4>${escapeHtml(order.bedrijfsnaam)}</h4>
                        <div class="address"><i class="bi bi-geo-alt"></i> ${escapeHtml(order.full_delivery_address)}</div>
                        <div class="products"><i class="bi bi-box"></i> ${escapeHtml(products)}</div>
                        <div class="badges">
                            ${isOnRoute ? '<span class="status-badge onderweg"><i class="bi bi-truck"></i> Onderweg</span>' : ''}
                            ${isDelivered ? '<span class="status-badge afgeleverd"><i class="bi bi-check"></i> Afgeleverd</span>' : ''}
                            <span class="status-badge ${order.payment_status}">${order.payment_status === 'paid' ? 'Betaald' : 'Open'}</span>
                        </div>
                    </div>
                    <div class="actions">
                        ${isDelivered ? 
                            '<button class="btn btn-delivered done"><i class="bi bi-check"></i></button>' :
                            `<button class="btn btn-delivered" onclick="markDelivered(${order.id}, this)"><i class="bi bi-check"></i></button>`
                        }
                        <a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(order.full_delivery_address)}" target="_blank" class="btn btn-outline"><i class="bi bi-geo-alt"></i></a>
                        <a href="tel:${order.telefoon || ''}" class="btn btn-outline"><i class="bi bi-telephone"></i></a>
                        <button class="btn btn-outline" onclick='showOrderDetail(${JSON.stringify(order)})'><i class="bi bi-eye"></i></button>
                    </div>
                </div>
            `;
        });
        
        html += `
            <div class="connector"></div>
            <div class="route-point end">
                <div class="marker"><i class="bi bi-arrow-return-left"></i></div>
                <div class="info">
                    <h4>Terug naar bakkerij</h4>
                    <p>${escapeHtml(bakkerijAdres)}</p>
                </div>
            </div>
        `;
        
        document.getElementById('routeStops').innerHTML = html;
    }
    
    function updateSummary(orders) {
        document.getElementById('stopCount').textContent = orders.length;
        const total = orders.reduce((sum, o) => sum + parseFloat(o.total_amount), 0);
        document.getElementById('totalAmount').textContent = '€' + total.toFixed(2).replace('.', ',');
        const delivered = orders.filter(o => o.delivery_status === 'afgeleverd').length;
        document.getElementById('deliveredCount').textContent = delivered + '/' + orders.length;
    }
    
    function updateGoogleMapsLink(orders) {
        if (orders.length === 0) {
            document.getElementById('googleMapsBtn').href = '#';
            return;
        }
        const waypoints = orders.map(o => encodeURIComponent(o.full_delivery_address)).join('/');
        const origin = encodeURIComponent(bakkerijAdres);
        document.getElementById('googleMapsBtn').href = `https://www.google.com/maps/dir/${origin}/${waypoints}/${origin}`;
    }
    
    function updateStartButton(orders) {
        const btn = document.getElementById('btnStartRoute');
        const allStarted = orders.every(o => o.delivery_status === 'onderweg' || o.delivery_status === 'afgeleverd');
        
        if (orders.length === 0) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-truck"></i> Geen leveringen';
        } else if (allStarted) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-check-circle"></i> Route gestart';
            btn.style.background = '#4caf50';
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-truck"></i> Start Route';
            btn.style.background = '';
        }
    }
    
    async function startRoute() {
        const sendEmails = document.getElementById('sendEmails').checked;
        const orderIds = currentDayOrders
            .filter(o => o.delivery_status !== 'onderweg' && o.delivery_status !== 'afgeleverd')
            .map(o => o.id);
        
        if (orderIds.length === 0) return;
        
        if (!confirm(`${orderIds.length} bestelling(en) op "onderweg" zetten${sendEmails ? ' en emails versturen' : ''}?`)) return;
        
        const btn = document.getElementById('btnStartRoute');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig...';
        
        try {
            const response = await fetch('../api/delivery-route.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start_route', order_ids: orderIds, send_emails: sendEmails })
            });
            
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('successText').textContent = 
                    `${data.updated_count} bestelling(en) op onderweg gezet` + 
                    (sendEmails ? `, ${data.emails_sent} email(s) verstuurd` : '');
                document.getElementById('successMessage').classList.add('show');
                
                currentDayOrders.forEach(o => {
                    if (orderIds.includes(o.id)) o.delivery_status = 'onderweg';
                });
                
                renderRoute(currentDayOrders);
                updateSummary(currentDayOrders);
                updateStartButton(currentDayOrders);
            } else {
                alert('Fout: ' + (data.error || 'Onbekende fout'));
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-truck"></i> Start Route';
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Er ging iets mis');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-truck"></i> Start Route';
        }
    }
    
    async function markDelivered(orderId, btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        
        try {
            const response = await fetch('../api/delivery-route.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_delivered', order_id: orderId })
            });
            
            const data = await response.json();
            
            if (data.success) {
                const order = currentDayOrders.find(o => o.id === orderId);
                if (order) order.delivery_status = 'afgeleverd';
                
                btn.className = 'btn btn-delivered done';
                btn.innerHTML = '<i class="bi bi-check"></i>';
                btn.closest('.route-stop').classList.add('delivered');
                
                updateSummary(currentDayOrders);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check"></i>';
            }
        } catch (error) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check"></i>';
        }
    }
    
    function closeDayModal() {
        document.getElementById('dayModal').classList.remove('active');
        currentDayOrders = [];
        currentDayDate = null;
    }
    
    function showOrderDetail(order) {
        document.getElementById('orderModalId').textContent = '#' + order.id;
        document.getElementById('orderCompany').textContent = order.bedrijfsnaam;
        document.getElementById('orderContact').textContent = order.contactpersoon || '-';
        
        const phoneEl = document.getElementById('orderPhone');
        phoneEl.textContent = order.telefoon || '-';
        phoneEl.href = order.telefoon ? 'tel:' + order.telefoon : '#';
        
        const emailEl = document.getElementById('orderEmail');
        emailEl.textContent = order.email || '-';
        emailEl.href = order.email ? 'mailto:' + order.email : '#';
        
        document.getElementById('orderAddress').textContent = order.full_delivery_address;
        
        let productsHtml = '';
        order.items.forEach(item => {
            const lineTotal = item.quantity * item.unit_price;
            productsHtml += `
                <div class="product-row">
                    <span><span class="product-qty">${item.quantity}x</span> ${escapeHtml(item.product_name)}</span>
                    <span>€${lineTotal.toFixed(2).replace('.', ',')}</span>
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
    
    <?php if ($viewMode === 'day' && !empty($ordersByDate[$currentDate->format('Y-m-d')])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        loadRouteData('<?= $currentDate->format('Y-m-d') ?>').then(() => {
            const container = document.getElementById('dayContent');
            if (container) {
                container.innerHTML = document.getElementById('routeStops').innerHTML;
                
                const actionsHtml = document.querySelector('.route-actions').outerHTML;
                container.insertAdjacentHTML('afterbegin', actionsHtml);
            }
        });
    });
    <?php endif; ?>
    </script>
</body>
</html>
