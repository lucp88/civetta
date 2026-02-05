<?php
require_once '../config.php';
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
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#1976d2">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" sizes="192x192" href="/img/icon-192.png">
    <link rel="apple-touch-icon" sizes="512x512" href="/img/icon-512.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../css/admin-bakker.css?v=2">
    <style>
        :root {
            --accent: #2196f3;
            --accent-dark: #1976d2;
            --accent-hover: #e3f2fd;
        }
        .modal { max-width: 800px; }
        .modal-body { padding: 0; }
        .calendar-preview-item {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .calendar-preview-item i { color: #2196f3; }
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
        
        .day-content { padding: 1rem; }
        .day-content .route-actions { padding: 0; margin-bottom: 1rem; background: transparent; border: none; }
        
        .fab {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(33,150,243,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            z-index: 900;
            transition: all 0.2s;
        }
        .fab:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(33,150,243,0.5); }
        
        .new-order-modal { max-width: 700px; }
        .new-order-modal .modal-body { padding: 1.25rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #333; margin-bottom: 0.4rem; }
        .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-control:focus { border-color: #2196f3; outline: none; }
        
        .product-select-row {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .product-select-row select { flex: 3; }
        .product-select-row input[type="number"] { flex: 1; min-width: 60px; }
        .product-select-row .product-price { flex: 1; min-width: 80px; text-align: right; color: #666; font-size: 0.9rem; white-space: nowrap; }
        .product-select-row .btn-remove {
            width: 32px;
            height: 32px;
            border: none;
            background: #f8d7da;
            color: #dc3545;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .product-select-row .btn-remove:hover { background: #dc3545; color: white; }
        
        .btn-add-product {
            padding: 0.4rem 1rem;
            border: 2px dashed #2196f3;
            background: transparent;
            color: #2196f3;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .btn-add-product:hover { background: #e3f2fd; }
        
        .order-total-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            background: #f8f9fa;
            border-top: 1px solid #eee;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .order-total-bar .total-amount { color: #1976d2; font-size: 1.3rem; }
        
        .btn-submit-order {
            padding: 0.75rem 2rem;
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
        }
        .btn-submit-order:hover { background: linear-gradient(135deg, #1976d2, #1565c0); }
        .btn-submit-order:disabled { opacity: 0.6; cursor: not-allowed; }
        
        .customer-info-card {
            display: none;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-top: 0.5rem;
        }
        .customer-info-card.show { display: block; }
        .customer-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }
        .customer-info-item {
            font-size: 0.85rem;
        }
        .customer-info-item .ci-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #888;
            font-weight: 600;
        }
        .customer-info-item .ci-value {
            color: #333;
        }
        .customer-info-item .ci-value a { color: #1976d2; text-decoration: none; }
        .customer-info-item .ci-value a:hover { text-decoration: underline; }
        .customer-info-item.full-width { grid-column: 1 / -1; }

        @media (max-width: 768px) {
            .route-summary { gap: 1rem; padding: 0.75rem 1rem; flex-wrap: wrap; }
            .route-stat-value { font-size: 1.2rem; }
            .route-stat-label { font-size: 0.65rem; }
            .route-actions { padding: 0.75rem 1rem; gap: 0.5rem; }
            .route-actions .btn { padding: 0.5rem 0.9rem; font-size: 0.8rem; }
            .email-toggle { font-size: 0.8rem; }
            .route-stop { padding: 0.75rem 1rem; flex-wrap: wrap; }
            .route-stop .info .address { font-size: 0.8rem; }
            .route-stop .info .products { font-size: 0.75rem; }
            .route-stop .actions { width: 100%; justify-content: flex-start; margin-top: 0.5rem; padding-left: 52px; }
            .route-stop .actions .btn { padding: 0.35rem 0.7rem; font-size: 0.75rem; }
            .route-point { padding: 0.75rem 1rem; }
            .route-point .info h4 { font-size: 0.85rem; }
            .route-point .info p { font-size: 0.8rem; }
            .connector { margin-left: 17px; }
            .fab { bottom: 1.5rem; right: 1.5rem; width: 48px; height: 48px; font-size: 1.25rem; }
            .new-order-modal .modal-body { padding: 1rem; }
            .form-group label { font-size: 0.8rem; }
            .form-control { padding: 0.5rem 0.7rem; font-size: 0.9rem; }
            .product-select-row { flex-wrap: wrap; }
            .product-select-row select { flex: 1 1 100%; }
            .product-select-row input[type="number"] { flex: 1 1 60px; }
            .product-select-row .product-price { flex: 1 1 60px; min-width: 60px; font-size: 0.8rem; }
            .order-total-bar { flex-direction: column; gap: 0.75rem; padding: 0.75rem 1rem; text-align: center; }
            .btn-submit-order { width: 100%; justify-content: center; }
            .customer-info-grid { grid-template-columns: 1fr; }
            .success-message { padding: 0.75rem 1rem; font-size: 0.85rem; }
            .day-content .route-actions { flex-direction: column; align-items: stretch; }
            .day-content .route-actions .btn { justify-content: center; }
        }

        @media (max-width: 480px) {
            .route-summary { justify-content: space-around; }
            .route-stop .marker { width: 30px; height: 30px; font-size: 0.8rem; }
            .route-stop .actions { padding-left: 46px; gap: 0.4rem; }
            .route-stop .info h4 { font-size: 0.9rem; }
            .route-point .marker { width: 30px; height: 30px; }
            .connector { margin-left: 14px; height: 15px; }
            .route-actions { flex-direction: column; align-items: stretch; }
            .route-actions .btn { justify-content: center; }
            .email-toggle { justify-content: center; }
            .btn-add-product { width: 100%; text-align: center; justify-content: center; display: flex; }
            .product-select-row .btn-remove { width: 28px; height: 28px; font-size: 0.85rem; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="bi bi-truck"></i> Leveren</h1>
        <div class="header-links">
            <a href="bereiden.php"><i class="bi bi-fire"></i> Bereiden</a>
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
    
    <button class="fab" onclick="openNewOrderModal()" title="Nieuwe bestelling">
        <i class="bi bi-plus-lg"></i>
    </button>
    
    <div class="modal-overlay" id="newOrderModal">
        <div class="modal new-order-modal">
            <div class="modal-header">
                <h3><i class="bi bi-plus-circle"></i> Nieuwe Bestelling</h3>
                <button class="modal-close" onclick="closeNewOrderModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Klant</label>
                    <select class="form-control" id="newOrderCustomer" onchange="onCustomerChange()">
                        <option value="">Selecteer een klant...</option>
                    </select>
                    <div class="customer-info-card" id="customerInfoCard">
                        <div class="customer-info-grid">
                            <div class="customer-info-item">
                                <div class="ci-label">Contactpersoon</div>
                                <div class="ci-value" id="ciContact">-</div>
                            </div>
                            <div class="customer-info-item">
                                <div class="ci-label">Telefoon</div>
                                <div class="ci-value" id="ciPhone">-</div>
                            </div>
                            <div class="customer-info-item">
                                <div class="ci-label">E-mail</div>
                                <div class="ci-value" id="ciEmail">-</div>
                            </div>
                            <div class="customer-info-item">
                                <div class="ci-label">Leveradres</div>
                                <div class="ci-value" id="ciAddress">-</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Leverdatum</label>
                    <input type="date" class="form-control" id="newOrderDate">
                </div>
                <div class="form-group">
                    <label>Producten</label>
                    <div id="newOrderProducts"></div>
                    <button type="button" class="btn-add-product" onclick="addProductRow()">
                        <i class="bi bi-plus"></i> Product toevoegen
                    </button>
                </div>
                <div class="form-group">
                    <label>Opmerkingen</label>
                    <textarea class="form-control" id="newOrderNotes" rows="2" placeholder="Optionele opmerkingen..."></textarea>
                </div>
            </div>
            <div class="order-total-bar">
                <span>Totaal: <span class="total-amount" id="newOrderTotal">€0,00</span></span>
                <button class="btn-submit-order" id="btnSubmitOrder" onclick="submitNewOrder()">
                    <i class="bi bi-check-lg"></i> Bestelling plaatsen
                </button>
            </div>
        </div>
    </div>
    
    <?php $detailAccentColor = '#1976d2'; $detailAccentColorDark = '#1565c0'; include 'order-detail-modal.php'; ?>

    <script>
    let allCustomers = [];
    let allProducts = [];
    let newOrderProductIndex = 0;
    
    const currentDate = '<?= $viewDate ?>';
    const currentMode = '<?= $viewMode ?>';
    const bakkerijAdres = '<?= addslashes($bakkerijAdres ?: 'Leersum, Utrecht') ?>';
    
    let currentDayOrders = [];
    let currentDayDate = null;
    </script>
    <script src="../../js/bakker-calendar.js?v=1"></script>
    <script>
    async function openDayModal(date, dateLabel) {
        currentDayDate = date;
        document.getElementById('dayModalDate').textContent = dateLabel;
        document.getElementById('successMessage').classList.remove('show');
        document.getElementById('dayModal').classList.add('active');
        
        await loadRouteData(date);
    }
    
    async function loadRouteData(date) {
        try {
            const response = await fetch(`../../api/delivery-route.php?date=${date}`);
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
            const response = await fetch('../../api/delivery-route.php', {
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
            const response = await fetch('../../api/delivery-route.php', {
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
    
    const _origCloseDayModal = closeDayModal;
    closeDayModal = function() {
        _origCloseDayModal();
        currentDayOrders = [];
        currentDayDate = null;
    };
    
    async function loadNewOrderData() {
        if (allCustomers.length && allProducts.length) return;
        try {
            const [custRes, prodRes] = await Promise.all([
                fetch('../../api/admin-orders.php?action=customers'),
                fetch('../../api/admin-orders.php?action=products')
            ]);
            const custData = await custRes.json();
            const prodData = await prodRes.json();
            if (custData.success) allCustomers = custData.customers;
            if (prodData.success) allProducts = prodData.products;
        } catch (e) {
            console.error('Error loading data:', e);
        }
    }
    
    async function openNewOrderModal(prefillDate) {
        await loadNewOrderData();
        
        const custSelect = document.getElementById('newOrderCustomer');
        custSelect.innerHTML = '<option value="">Selecteer een klant...</option>';
        allCustomers.forEach(c => {
            custSelect.innerHTML += `<option value="${c.id}">${escapeHtml(c.bedrijfsnaam)} (${escapeHtml(c.contactpersoon)})</option>`;
        });
        
        document.getElementById('newOrderDate').value = prefillDate || new Date().toISOString().split('T')[0];
        document.getElementById('newOrderNotes').value = '';
        document.getElementById('newOrderProducts').innerHTML = '';
        newOrderProductIndex = 0;
        addProductRow();
        updateNewOrderTotal();
        
        document.getElementById('newOrderModal').classList.add('active');
    }
    
    function closeNewOrderModal() {
        document.getElementById('newOrderModal').classList.remove('active');
        document.getElementById('customerInfoCard').classList.remove('show');
    }
    
    function onCustomerChange() {
        const select = document.getElementById('newOrderCustomer');
        const card = document.getElementById('customerInfoCard');
        const customerId = parseInt(select.value);
        
        if (!customerId) {
            card.classList.remove('show');
            return;
        }
        
        const customer = allCustomers.find(c => c.id == customerId);
        if (!customer) {
            card.classList.remove('show');
            return;
        }
        
        document.getElementById('ciContact').textContent = customer.contactpersoon || '-';
        
        const phoneEl = document.getElementById('ciPhone');
        if (customer.telefoon) {
            phoneEl.innerHTML = `<a href="tel:${escapeHtml(customer.telefoon)}">${escapeHtml(customer.telefoon)}</a>`;
        } else {
            phoneEl.textContent = '-';
        }
        
        const emailEl = document.getElementById('ciEmail');
        if (customer.email) {
            emailEl.innerHTML = `<a href="mailto:${escapeHtml(customer.email)}">${escapeHtml(customer.email)}</a>`;
        } else {
            emailEl.textContent = '-';
        }
        
        let address;
        if (customer.delivery_same_as_business || !customer.delivery_adres) {
            address = [customer.adres, customer.postcode, customer.plaats].filter(Boolean).join(', ');
        } else {
            address = [customer.delivery_adres, customer.delivery_postcode, customer.delivery_plaats].filter(Boolean).join(', ');
        }
        document.getElementById('ciAddress').textContent = address || '-';
        
        card.classList.add('show');
    }
    
    function addProductRow() {
        const container = document.getElementById('newOrderProducts');
        const idx = newOrderProductIndex++;
        let options = '<option value="">Kies product...</option>';
        allProducts.forEach(p => {
            options += `<option value="${escapeHtml(p.naam)}" data-price="${p.prijs}">${escapeHtml(p.naam)} (€${parseFloat(p.prijs).toFixed(2).replace('.', ',')})</option>`;
        });
        
        const row = document.createElement('div');
        row.className = 'product-select-row';
        row.innerHTML = `
            <select class="form-control product-select" data-idx="${idx}" onchange="onProductChange(this)">${options}</select>
            <input type="number" class="form-control product-qty" data-idx="${idx}" min="1" value="1" onchange="updateNewOrderTotal()" oninput="updateNewOrderTotal()">
            <span class="product-price" data-idx="${idx}">€0,00</span>
            <button type="button" class="btn-remove" onclick="removeProductRow(this)"><i class="bi bi-x"></i></button>
        `;
        container.appendChild(row);
    }
    
    function removeProductRow(btn) {
        btn.closest('.product-select-row').remove();
        updateNewOrderTotal();
    }
    
    function onProductChange(select) {
        const idx = select.dataset.idx;
        const option = select.options[select.selectedIndex];
        const price = parseFloat(option.dataset.price || 0);
        const priceEl = document.querySelector(`.product-price[data-idx="${idx}"]`);
        priceEl.textContent = '€' + price.toFixed(2).replace('.', ',');
        updateNewOrderTotal();
    }
    
    function updateNewOrderTotal() {
        let total = 0;
        document.querySelectorAll('.product-select-row').forEach(row => {
            const select = row.querySelector('.product-select');
            const qty = parseInt(row.querySelector('.product-qty').value) || 0;
            const option = select.options[select.selectedIndex];
            const price = parseFloat(option?.dataset?.price || 0);
            total += qty * price;
        });
        document.getElementById('newOrderTotal').textContent = '€' + total.toFixed(2).replace('.', ',');
    }
    
    async function submitNewOrder() {
        const accountId = document.getElementById('newOrderCustomer').value;
        const deliveryDate = document.getElementById('newOrderDate').value;
        const notes = document.getElementById('newOrderNotes').value.trim();
        
        if (!accountId) { alert('Selecteer een klant'); return; }
        if (!deliveryDate) { alert('Selecteer een leverdatum'); return; }
        
        const items = [];
        let valid = true;
        document.querySelectorAll('.product-select-row').forEach(row => {
            const select = row.querySelector('.product-select');
            const qty = parseInt(row.querySelector('.product-qty').value) || 0;
            const option = select.options[select.selectedIndex];
            const name = select.value;
            const price = parseFloat(option?.dataset?.price || 0);
            
            if (name && qty > 0) {
                items.push({ product_name: name, quantity: qty, unit_price: price });
            }
        });
        
        if (items.length === 0) { alert('Voeg minimaal één product toe'); return; }
        
        const btn = document.getElementById('btnSubmitOrder');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig...';
        
        try {
            const response = await fetch('../../api/admin-orders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ account_id: parseInt(accountId), delivery_date: deliveryDate, items, notes })
            });
            const data = await response.json();
            
            if (data.success) {
                closeNewOrderModal();
                alert('Bestelling #' + data.order_id + ' geplaatst! Bevestigingsmail verzonden.');
                window.location.reload();
            } else {
                alert('Fout: ' + (data.error || 'Onbekende fout'));
            }
        } catch (e) {
            console.error('Error:', e);
            alert('Er ging iets mis bij het plaatsen van de bestelling');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Bestelling plaatsen';
        }
    }
    
    document.getElementById('newOrderModal').addEventListener('click', function(e) {
        if (e.target === this) closeNewOrderModal();
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
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('../sw.js', { scope: '/admin/' });
        if ('PushManager' in window && Notification.permission === 'granted') {
            navigator.serviceWorker.ready.then(async reg => {
                const sub = await reg.pushManager.getSubscription();
                if (sub) return;
                try {
                    const r = await fetch('/api/push-subscriptions.php?action=vapid-key');
                    const { publicKey } = await r.json();
                    const padding = '='.repeat((4 - publicKey.length % 4) % 4);
                    const raw = atob((publicKey + padding).replace(/-/g, '+').replace(/_/g, '/'));
                    const key = Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
                    const newSub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
                    const j = newSub.toJSON();
                    await fetch('/api/push-subscriptions.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: j.endpoint, keys: { p256dh: j.keys.p256dh, auth: j.keys.auth } }) });
                } catch (e) {}
            });
        }
    }
    </script>
</body>
</html>
