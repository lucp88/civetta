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
    $stmt = $pdo->prepare("
        SELECT 
            boi.product_name, 
            boi.quantity, 
            boi.unit_price, 
            pv.recipe_id,
            pv.gewicht as variant_weight,
            COALESCE(br.name, 'Geen recept') as recipe_name,
            br.recipe_data,
            br.dough_type_id,
            COALESCE(dt.name, 'Geen deegsoort') as dough_type_name
        FROM business_order_items boi 
        LEFT JOIN products p ON LOWER(TRIM(boi.product_name)) = LOWER(TRIM(p.naam))
        LEFT JOIN product_variants pv ON pv.product_id = p.id AND ROUND(pv.prijs, 2) = ROUND(boi.unit_price, 2)
        LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
        LEFT JOIN dough_types dt ON br.dough_type_id = dt.id
        WHERE boi.order_id = ?
    ");
    $stmt->execute([$order['id']]);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['dough_weight'] = 0;
        if (!empty($item['recipe_data'])) {
            $recipeData = json_decode($item['recipe_data'], true);
            $item['dough_weight'] = $recipeData['doughWeight'] ?? 0;
        }
        unset($item['recipe_data']);
    }
    unset($item);
    $order['items'] = $items;
    
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
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#e55a2b">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" sizes="192x192" href="/img/icon-192.png">
    <link rel="apple-touch-icon" sizes="512x512" href="/img/icon-512.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../css/admin-bakker.css?v=2">
    <style>
        :root {
            --accent: #ff6b35;
            --accent-dark: #e55a2b;
            --accent-hover: #fff5f0;
        }
        .calendar-cell.today { background: #fff8e1; }
        .calendar-cell.selected { background: #ffe0d0; }
        .calendar-preview-item + .calendar-preview-item[style] { color: #ff6b35; }
        .dough-type-header {
            font-weight: 700;
            font-size: 0.95rem;
            color: #5c3d1e;
            padding: 0.75rem 0 0.4rem;
            border-bottom: 3px solid #c8913a;
            margin-top: 0.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(to bottom, #faf6f1, transparent);
        }
        .dough-type-header:first-child { margin-top: 0; }
        .dough-type-header i { margin-right: 0.4rem; color: #c8913a; }
        .recipe-group-title {
            font-weight: 600;
            font-size: 0.85rem;
            color: #8b5a2b;
            padding: 0.5rem 0 0.25rem;
            border-bottom: 1px solid #e8dfd2;
            margin-top: 0.4rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .recipe-group-title:first-child { margin-top: 0; }
        .recipe-group-title i { margin-right: 0.3rem; color: #c8913a; }
        .product-total-weight {
            color: #888;
            font-size: 0.8rem;
        }
        .btn-dagproductie {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            margin-top: 1rem;
            padding: 0.75rem;
            background: linear-gradient(135deg, #c8913a, #a0722e);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .btn-dagproductie:hover {
            background: linear-gradient(135deg, #a0722e, #8b5a2b);
            transform: translateY(-1px);
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
                            $doughTypeTotals = [];
                            foreach ($orders as $o) {
                                foreach ($o['items'] as $item) {
                                    $doughTypeName = $item['dough_type_name'] ?? 'Geen deegsoort';
                                    $recipeName = $item['recipe_name'] ?? 'Geen recept';
                                    $doughWeight = $item['dough_weight'] ?? 0;
                                    $productName = $item['product_name'];
                                    
                                    if ($doughWeight > 0) {
                                        if (!isset($doughTypeTotals[$doughTypeName])) {
                                            $doughTypeTotals[$doughTypeName] = ['recipes' => [], 'total_dough' => 0];
                                        }
                                        if (!isset($doughTypeTotals[$doughTypeName]['recipes'][$recipeName])) {
                                            $doughTypeTotals[$doughTypeName]['recipes'][$recipeName] = ['weights' => [], 'total_dough' => 0];
                                        }
                                        if (!isset($doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['weights'][$doughWeight])) {
                                            $doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['weights'][$doughWeight] = ['qty' => 0, 'products' => []];
                                        }
                                        $doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['weights'][$doughWeight]['qty'] += $item['quantity'];
                                        if (!isset($doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['weights'][$doughWeight]['products'][$productName])) {
                                            $doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['weights'][$doughWeight]['products'][$productName] = 0;
                                        }
                                        $doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['weights'][$doughWeight]['products'][$productName] += $item['quantity'];
                                        $doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['total_dough'] += $item['quantity'] * $doughWeight;
                                        $doughTypeTotals[$doughTypeName]['total_dough'] += $item['quantity'] * $doughWeight;
                                    }
                                }
                            }
                            ksort($doughTypeTotals);
                            foreach ($doughTypeTotals as &$dt) {
                                ksort($dt['recipes']);
                                foreach ($dt['recipes'] as &$r) { krsort($r['weights']); }
                            }
                            unset($dt, $r);
                            ?>
                            <div class="totals-section">
                                <h4><i class="bi bi-list-check"></i> Totaal te bereiden</h4>
                                <div class="totals-tabs">
                                    <button class="totals-tab active" onclick="switchTotalsTab(this, 'producten')">Producten</button>
                                    <button class="totals-tab" onclick="switchTotalsTab(this, 'recepten')">Recepten</button>
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
                                <div class="totals-tab-content" data-tab="recepten">
                                    <div class="product-totals-list">
                                        <?php foreach ($doughTypeTotals as $doughType => $dtData): ?>
                                            <div class="dough-type-header">
                                                <span><i class="bi bi-layers"></i> <?= htmlspecialchars($doughType) ?></span>
                                                <span style="font-weight:700;color:#5c3d1e"><?= number_format($dtData['total_dough']/1000, 2, ',', '.') ?> kg</span>
                                            </div>
                                            <?php foreach ($dtData['recipes'] as $recipe => $rData): ?>
                                                <div class="recipe-group-title" style="margin-left:0.75rem">
                                                    <span><i class="bi bi-journal-bookmark"></i> <?= htmlspecialchars($recipe) ?></span>
                                                    <span style="font-weight:600;color:#c8913a"><?= number_format($rData['total_dough']/1000, 2, ',', '.') ?> kg</span>
                                                </div>
                                                <?php foreach ($rData['weights'] as $weight => $wdata): ?>
                                                    <div class="product-total-item" style="margin-left:1.5rem;font-weight:600">
                                                        <span><span class="product-total-qty"><?= $wdata['qty'] ?>x</span> <span class="product-total-name"><?= $weight ?>g</span></span>
                                                    </div>
                                                    <?php foreach ($wdata['products'] as $pname => $pqty): ?>
                                                    <div class="product-total-item" style="margin-left:2.5rem;font-size:0.85rem;color:#666">
                                                        <span><?= $pqty ?>x <?= htmlspecialchars($pname) ?></span>
                                                    </div>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <a href="dagproductie.php?date=<?= $currentDate->format('Y-m-d') ?>" class="btn-dagproductie">
                                        <i class="bi bi-calculator"></i> Bekijk ingrediënten
                                    </a>
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
    </script>
    <script src="../../js/bakker-calendar.js?v=1"></script>
    <script>
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
            
            const doughTypeTotals = {};
            orders.forEach(order => {
                order.items.forEach(item => {
                    const doughTypeName = item.dough_type_name || 'Geen deegsoort';
                    const recipeName = item.recipe_name || 'Geen recept';
                    const doughWeight = parseInt(item.dough_weight) || 0;
                    const productName = item.product_name;
                    if (doughWeight > 0) {
                        if (!doughTypeTotals[doughTypeName]) doughTypeTotals[doughTypeName] = { recipes: {}, totalDough: 0 };
                        if (!doughTypeTotals[doughTypeName].recipes[recipeName]) doughTypeTotals[doughTypeName].recipes[recipeName] = { weights: {}, totalDough: 0 };
                        if (!doughTypeTotals[doughTypeName].recipes[recipeName].weights[doughWeight]) {
                            doughTypeTotals[doughTypeName].recipes[recipeName].weights[doughWeight] = { qty: 0, products: {} };
                        }
                        const qty = parseInt(item.quantity);
                        doughTypeTotals[doughTypeName].recipes[recipeName].weights[doughWeight].qty += qty;
                        if (!doughTypeTotals[doughTypeName].recipes[recipeName].weights[doughWeight].products[productName]) {
                            doughTypeTotals[doughTypeName].recipes[recipeName].weights[doughWeight].products[productName] = 0;
                        }
                        doughTypeTotals[doughTypeName].recipes[recipeName].weights[doughWeight].products[productName] += qty;
                        doughTypeTotals[doughTypeName].recipes[recipeName].totalDough += qty * doughWeight;
                        doughTypeTotals[doughTypeName].totalDough += qty * doughWeight;
                    }
                });
            });
            
            html += '<div class="totals-section"><h4><i class="bi bi-list-check"></i> Totaal te bereiden</h4>';
            html += '<div class="totals-tabs"><button class="totals-tab active" onclick="switchTotalsTab(this, \'producten\')">Producten</button><button class="totals-tab" onclick="switchTotalsTab(this, \'recepten\')">Recepten</button></div>';
            html += '<div class="totals-tab-content active" data-tab="producten"><div class="product-totals-list">';
            for (const [product, data] of sortedProducts) {
                html += `<div class="product-total-item"><span><span class="product-total-qty">${data.qty}x</span> <span class="product-total-name">${escapeHtml(product)}</span></span><span class="product-total-price">\u20AC${data.amount.toFixed(2).replace('.', ',')}</span></div>`;
            }
            html += '</div></div>';
            html += '<div class="totals-tab-content" data-tab="recepten"><div class="product-totals-list">';
            for (const doughType of Object.keys(doughTypeTotals).sort()) {
                const dtData = doughTypeTotals[doughType];
                const kgTotal = (dtData.totalDough / 1000).toFixed(2).replace('.', ',');
                html += `<div class="dough-type-header"><span><i class="bi bi-layers"></i> ${escapeHtml(doughType)}</span><span style="font-weight:700;color:#5c3d1e">${kgTotal} kg</span></div>`;
                for (const recipe of Object.keys(dtData.recipes).sort()) {
                    const rData = dtData.recipes[recipe];
                    const kgRecipe = (rData.totalDough / 1000).toFixed(2).replace('.', ',');
                    html += `<div class="recipe-group-title" style="margin-left:0.75rem"><span><i class="bi bi-journal-bookmark"></i> ${escapeHtml(recipe)}</span><span style="font-weight:600;color:#c8913a">${kgRecipe} kg</span></div>`;
                    const sortedWeights = Object.keys(rData.weights).sort((a, b) => b - a);
                    for (const weight of sortedWeights) {
                        const wdata = rData.weights[weight];
                        html += `<div class="product-total-item" style="margin-left:1.5rem;font-weight:600"><span><span class="product-total-qty">${wdata.qty}x</span> <span class="product-total-name">${weight}g</span></span></div>`;
                        for (const [pname, pqty] of Object.entries(wdata.products)) {
                            html += `<div class="product-total-item" style="margin-left:2.5rem;font-size:0.85rem;color:#666"><span>${pqty}x ${escapeHtml(pname)}</span></div>`;
                        }
                    }
                }
            }
            html += '</div>';
            html += `<a href="dagproductie.php?date=${date}" class="btn-dagproductie"><i class="bi bi-calculator"></i> Bekijk ingrediënten</a>`;
            html += '</div></div>';
            
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
