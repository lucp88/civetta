<?php
// Redirect to unified planning page
$params = $_GET;
$params['filter'] = 'bakken';
header('Location: planning.php?' . http_build_query($params));
exit;

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

// Load bakdagen configuration
$bakdagenPatroonStr = '';
$stmtBp = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_patroon'");
$stmtBp->execute();
$bakdagenPatroonStr = $stmtBp->fetchColumn() ?: '';
$bakdagenPatroon = $bakdagenPatroonStr ? array_map('intval', explode(',', $bakdagenPatroonStr)) : [];

$stmtVd = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
$stmtVd->execute();
$voorbereidingDagen = (int)($stmtVd->fetchColumn() ?: 3);

$stmtExtra = $pdo->prepare("SELECT datum, notitie FROM bakdagen_extra WHERE datum BETWEEN ? AND ? ORDER BY datum");
$stmtExtra->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$extraDagen = $stmtExtra->fetchAll();
$extraDatums = array_column($extraDagen, 'datum');

// Compute which dates in view range are baking days
$bakdagen = [];
$iterDt = clone $startDate;
while ($iterDt <= $endDate) {
    $weekday = (int)$iterDt->format('N');
    $dateStr = $iterDt->format('Y-m-d');
    if (in_array($weekday, $bakdagenPatroon) || in_array($dateStr, $extraDatums)) {
        $bakdagen[] = $dateStr;
    }
    $iterDt->modify('+1 day');
}

// Fetch orders - baking day = delivery day (no offset)
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
    $stmt = $pdo->prepare("
        SELECT
            boi.product_name,
            boi.quantity,
            boi.unit_price,
            COALESCE(br.name, 'Geen recept') as recipe_name,
            br.recipe_data,
            br.dough_type_id,
            dt.recipe_data as dough_type_recipe_data,
            COALESCE(dt.name, 'Geen deegsoort') as dough_type_name
        FROM business_order_items boi
        LEFT JOIN product_variants pv ON boi.variant_id = pv.id
        LEFT JOIN products p ON COALESCE(boi.product_id, pv.product_id) = p.id
        LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
        LEFT JOIN dough_types dt ON br.dough_type_id = dt.id
        WHERE boi.order_id = ?
    ");
    $stmt->execute([$order['id']]);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['dough_weight'] = 0;
        $item['method_days_count'] = $voorbereidingDagen; // fallback
        if (!empty($item['recipe_data'])) {
            $recipeData = json_decode($item['recipe_data'], true);
            $item['dough_weight'] = $recipeData['doughWeight'] ?? 0;
            if (!empty($recipeData['methodDays'])) {
                $item['method_days_count'] = count($recipeData['methodDays']);
            } elseif (!empty($item['dough_type_recipe_data'])) {
                $dtData = json_decode($item['dough_type_recipe_data'], true);
                if (!empty($dtData['methodDays'])) {
                    $item['method_days_count'] = count($dtData['methodDays']);
                }
            }
        }
        unset($item['recipe_data']);
        unset($item['dough_type_recipe_data']);
    }
    unset($item);
    $order['items'] = $items;
    
    // Baking day = delivery day (no offset)
    $order['bereiding_date'] = $order['delivery_date'];
    
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

// Load appointments for the date range
$stmtAppt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_date BETWEEN ? AND ? ORDER BY appointment_date, start_time");
$stmtAppt->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$allAppointments = $stmtAppt->fetchAll();

$appointmentsByDate = [];
foreach ($allAppointments as $appt) {
    $date = $appt['appointment_date'];
    if (!isset($appointmentsByDate[$date])) {
        $appointmentsByDate[$date] = [];
    }
    $appointmentsByDate[$date][] = $appt;
}

// Build per-dough-type bars data for week view
$recipeBarsByBakdag = [];
foreach ($bakdagen as $bakdag) {
    $orders = $ordersByBereidingDate[$bakdag] ?? [];
    $doughMap = [];
    foreach ($orders as $order) {
        foreach ($order['items'] as $item) {
            $doughName = $item['dough_type_name'] ?? 'Geen deegsoort';
            if (!isset($doughMap[$doughName])) {
                $doughMap[$doughName] = [
                    'method_days_count' => $item['method_days_count'],
                    'total_qty' => 0,
                    'order_ids' => [],
                ];
            }
            $doughMap[$doughName]['total_qty'] += (int)$item['quantity'];
            $doughMap[$doughName]['method_days_count'] = max($doughMap[$doughName]['method_days_count'], $item['method_days_count']);
            $doughMap[$doughName]['order_ids'][$order['id']] = true;
        }
    }
    foreach ($doughMap as &$rdata) {
        $rdata['order_count'] = count($rdata['order_ids']);
        unset($rdata['order_ids']);
    }
    unset($rdata);
    if (!empty($doughMap)) {
        $recipeBarsByBakdag[$bakdag] = $doughMap;
    }
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
            color: #2d4a2d;
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
            color: #3d6b3d;
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
            background: linear-gradient(135deg, #a0722e, #3d6b3d);
            transform: translateY(-1px);
        }

        /* Bakdagen styles */
        .bakdag-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: linear-gradient(135deg, #ff6b35, #e55a2b);
            color: white;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
        }
        .calendar-cell.non-bakdag {
            opacity: 0.5;
            background: #f5f2ed;
        }
        .calendar-cell.non-bakdag:hover {
            opacity: 0.8;
            background: #ede8e0;
        }
        .calendar-cell.bakdag {
            border-top: 3px solid #ff6b35;
        }
        .calendar-cell.bakdag.today {
            border: 2px solid var(--accent);
            border-top: 3px solid #ff6b35;
        }
        .week-bars-container {
            grid-column: 1 / -1;
            background: white;
            padding: 0.5rem 0.25rem;
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.25rem;
            min-height: 56px;
        }
        .prep-bar {
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, #fff0e8, #fff0e8dd);
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 4px solid #ff6b35;
            min-height: 44px;
        }
        .prep-bar:hover {
            filter: brightness(0.95);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .prep-bar-inner {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #2d4a2d;
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .prep-bar-inner i {
            color: #ff6b35;
            flex-shrink: 0;
        }
        .prep-bar-count {
            margin-left: auto;
            background: #ff6b35;
            color: white;
            padding: 0.15rem 0.5rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .prep-bar-days {
            font-size: 0.7rem;
            color: #8b7355;
            font-weight: 400;
        }

        /* Settings gear button */
        .btn-settings {
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
            color: var(--accent-dark);
            font-size: 1.1rem;
            transition: all 0.2s;
        }
        .btn-settings:hover {
            background: var(--accent-hover);
            transform: rotate(30deg);
        }

        /* Bakdagen settings modal */
        .bakdagen-checkboxes {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .bakdagen-checkboxes label {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 0.75rem;
            background: #f5f2ed;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            color: #2d4a2d;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        .bakdagen-checkboxes label:has(input:checked) {
            background: #fff5f0;
            border-color: #ff6b35;
            color: #e55a2b;
        }
        .bakdagen-checkboxes input[type="checkbox"] {
            accent-color: #ff6b35;
        }
        .extra-bakdagen-list {
            margin-bottom: 0.75rem;
        }
        .extra-bakdag-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.4rem 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.85rem;
        }
        .extra-bakdag-item:last-child { border-bottom: none; }
        .extra-bakdag-remove {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 1rem;
            padding: 0.25rem;
        }
        .add-extra-bakdag {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .add-extra-bakdag input {
            padding: 0.4rem 0.6rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .add-extra-bakdag button {
            padding: 0.4rem 0.75rem;
            background: #ff6b35;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .add-extra-bakdag button:hover { background: #e55a2b; }
        .bakdagen-modal-section h4 {
            color: #2d4a2d;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .bakdagen-modal-section {
            margin-bottom: 1.25rem;
        }
        .btn-save-bakdagen {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #ff6b35, #e55a2b);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-save-bakdagen:hover {
            background: linear-gradient(135deg, #e55a2b, #cc4a1a);
            transform: translateY(-1px);
        }

        /* Appointments */
        .appointment-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.75rem;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            color: white;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 0.15rem;
        }
        .appointment-item i { font-size: 0.65rem; flex-shrink: 0; }
        .appointment-time { font-weight: 400; opacity: 0.85; font-size: 0.65rem; }
        .appointments-section {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #eee;
        }
        .appointments-section h4 {
            font-size: 0.9rem;
            color: #2d4a2d;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .appointment-card {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.6rem 0.75rem;
            border-radius: 8px;
            background: #f8f6f3;
            margin-bottom: 0.4rem;
            cursor: pointer;
            transition: background 0.15s;
        }
        .appointment-card:hover { background: #f0ede8; }
        .appointment-card .appt-color {
            width: 4px;
            min-height: 32px;
            border-radius: 2px;
            flex-shrink: 0;
        }
        .appointment-card .appt-info { flex: 1; min-width: 0; }
        .appointment-card .appt-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: #333;
        }
        .appointment-card .appt-time {
            font-size: 0.8rem;
            color: #888;
        }
        .appointment-card .appt-desc {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.2rem;
        }
        .appointment-card .appt-actions {
            display: flex;
            gap: 0.3rem;
            flex-shrink: 0;
        }
        .appointment-card .appt-actions button {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
            font-size: 0.85rem;
            color: #999;
            border-radius: 4px;
        }
        .appointment-card .appt-actions button:hover { color: #333; background: #e8e5e0; }
        .appointment-card .appt-actions button.delete:hover { color: #dc3545; }

        /* Appointment modal form */
        .appt-form .form-group { margin-bottom: 0.75rem; }
        .appt-form .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #2d4a2d;
            margin-bottom: 0.3rem;
        }
        .appt-form .form-control {
            width: 100%;
            padding: 0.5rem 0.7rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
            box-sizing: border-box;
        }
        .appt-form .form-control:focus {
            outline: none;
            border-color: var(--accent);
        }
        .appt-form .form-row {
            display: flex;
            gap: 0.5rem;
        }
        .appt-form .form-row .form-group { flex: 1; }
        .appt-form .color-options {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .appt-form .color-option {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.15s;
        }
        .appt-form .color-option:hover { transform: scale(1.15); }
        .appt-form .color-option.selected { border-color: #333; box-shadow: 0 0 0 2px white, 0 0 0 4px #333; }
        .btn-save-appt {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-save-appt:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .btn-delete-appt {
            width: 100%;
            padding: 0.5rem;
            background: none;
            color: #dc3545;
            border: 1px solid #dc3545;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            margin-top: 0.5rem;
        }
        .btn-delete-appt:hover { background: #dc3545; color: white; }
        .btn-add-appt {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.4rem 0.7rem;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-add-appt:hover { background: var(--accent-dark); }

        /* Click-to-add overlay */
        .add-bakdag-hint {
            position: absolute;
            bottom: 4px;
            right: 4px;
            font-size: 0.65rem;
            color: #bbb;
            display: none;
        }
        .calendar-cell.non-bakdag:hover .add-bakdag-hint {
            display: block;
        }

        @media (max-width: 768px) {
            .week-bars-container { padding: 0.35rem 0.15rem; min-height: 40px; }
            .prep-bar { padding: 0.35rem 0.5rem; min-height: 36px; }
            .prep-bar-inner { font-size: 0.75rem; gap: 0.3rem; }
            .prep-bar-count { font-size: 0.65rem; padding: 0.1rem 0.35rem; }
            .prep-bar-days { display: none; }
            .bakdagen-checkboxes { gap: 0.35rem; }
            .bakdagen-checkboxes label { padding: 0.35rem 0.5rem; font-size: 0.8rem; }
            .add-extra-bakdag { flex-wrap: wrap; }
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
            <button class="btn-settings" onclick="openBakdagenModal()" title="Bakdagen instellen">
                <i class="bi bi-gear"></i>
            </button>
        </div>
        
        <div class="calendar-container">
            <?php if ($viewMode === 'day'): ?>
                <?php
                $dateKey = $currentDate->format('Y-m-d');
                $orders = $ordersByBereidingDate[$dateKey] ?? [];
                $isToday = $dateKey === date('Y-m-d');
                $isBakdag = in_array($dateKey, $bakdagen);
                ?>
                <?php $dayAppointments = $appointmentsByDate[$dateKey] ?? []; ?>
                <div class="calendar-grid day-view">
                    <div class="calendar-cell day-view-cell <?= $isToday ? 'today' : '' ?> <?= $isBakdag ? 'bakdag' : 'non-bakdag' ?>">
                        <div class="calendar-date">
                            <span>
                                <?= formatDutchDate($currentDate) ?>
                                <?php if ($isBakdag): ?>
                                    <span class="bakdag-badge"><i class="bi bi-fire"></i> Bakdag</span>
                                <?php endif; ?>
                            </span>
                            <span class="calendar-count <?= count($orders) === 0 ? 'empty' : '' ?>">
                                <?= count($orders) ?> bestelling<?= count($orders) !== 1 ? 'en' : '' ?>
                            </span>
                        </div>
                        <?php if (!empty($dayAppointments)): ?>
                            <div class="appointments-section">
                                <h4><i class="bi bi-calendar-event"></i> Afspraken (<?= count($dayAppointments) ?>)
                                    <button class="btn-add-appt" onclick="openAppointmentModal('<?= $dateKey ?>')" style="margin-left:auto"><i class="bi bi-plus"></i> Nieuw</button>
                                </h4>
                                <?php foreach ($dayAppointments as $appt): ?>
                                    <div class="appointment-card" onclick='openEditAppointment(<?= json_encode($appt) ?>)'>
                                        <div class="appt-color" style="background:<?= htmlspecialchars($appt['color']) ?>"></div>
                                        <div class="appt-info">
                                            <div class="appt-title"><?= htmlspecialchars($appt['title']) ?></div>
                                            <?php if ($appt['start_time']): ?>
                                                <div class="appt-time"><i class="bi bi-clock"></i> <?= substr($appt['start_time'], 0, 5) ?><?= $appt['end_time'] ? ' - ' . substr($appt['end_time'], 0, 5) : '' ?></div>
                                            <?php endif; ?>
                                            <?php if ($appt['description']): ?>
                                                <div class="appt-desc"><?= htmlspecialchars($appt['description']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="margin-top:0.5rem">
                                <button class="btn-add-appt" onclick="openAppointmentModal('<?= $dateKey ?>')"><i class="bi bi-plus"></i> Afspraak</button>
                            </div>
                        <?php endif; ?>
                        <?php if (empty($orders)): ?>
                            <?php if (empty($dayAppointments)): ?>
                            <div class="empty-state">
                                <i class="bi bi-emoji-smile"></i>
                                <p>Geen bestellingen om te bereiden</p>
                            </div>
                            <?php endif; ?>
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
                                                <span style="font-weight:700;color:#2d4a2d"><?= number_format($dtData['total_dough']/1000, 2, ',', '.') ?> kg</span>
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

                    <!-- Preparation bars row (per dough type) -->
                    <div class="week-bars-container">
                        <?php
                        $barColors = ['#ff6b35', '#c8913a', '#4caf50', '#2196f3', '#9c27b0', '#e91e63', '#00bcd4', '#795548'];
                        $barBgColors = ['#fff0e8', '#faf3e8', '#e8f5e9', '#e3f2fd', '#f3e5f5', '#fce4ec', '#e0f7fa', '#efebe9'];
                        $colorIndex = 0;
                        $doughColorMap = [];

                        foreach ($bakdagen as $bakdag):
                            if (!isset($recipeBarsByBakdag[$bakdag])) continue;
                            $bakdagDt = new DateTime($bakdag);
                            $colEnd = (int)$bakdagDt->format('N'); // 1=Mon..7=Sun

                            foreach ($recipeBarsByBakdag[$bakdag] as $doughName => $rdata):
                                $dayCount = $rdata['method_days_count'];
                                $colStart = max(1, $colEnd - $dayCount + 1);
                                $totalQty = $rdata['total_qty'];

                                // Assign consistent color per dough type
                                if (!isset($doughColorMap[$doughName])) {
                                    $ci = $colorIndex % count($barColors);
                                    $doughColorMap[$doughName] = ['color' => $barColors[$ci], 'bg' => $barBgColors[$ci]];
                                    $colorIndex++;
                                }
                                $barColor = $doughColorMap[$doughName]['color'];
                                $barBg = $doughColorMap[$doughName]['bg'];
                        ?>
                        <div class="prep-bar"
                             style="grid-column: <?= $colStart ?> / <?= $colEnd + 1 ?>; border-left-color: <?= $barColor ?>; background: linear-gradient(135deg, <?= $barBg ?>, <?= $barBg ?>dd);"
                             onclick="openDayModal('<?= $bakdag ?>', '<?= formatDutchDate($bakdagDt) ?>', '<?= htmlspecialchars(addslashes($doughName), ENT_QUOTES) ?>')">
                            <div class="prep-bar-inner">
                                <i class="bi bi-layers" style="color: <?= $barColor ?>;"></i>
                                <span><?= htmlspecialchars($doughName) ?></span>
                                <span class="prep-bar-days">(<?= $dayCount ?> dag<?= $dayCount !== 1 ? 'en' : '' ?>)</span>
                                <span class="prep-bar-count" style="background: <?= $barColor ?>;"><?= $totalQty ?>x</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php if (empty($bakdagen)): ?>
                            <div style="text-align:center;color:#bbb;font-size:0.8rem;padding:0.5rem;">
                                Geen bakdagen deze week — <a href="#" onclick="openBakdagenModal();return false" style="color:#ff6b35">instellen</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    $current = clone $startDate;
                    for ($i = 0; $i < 7; $i++):
                        $dateKey = $current->format('Y-m-d');
                        $orders = $ordersByBereidingDate[$dateKey] ?? [];
                        $dayAppts = $appointmentsByDate[$dateKey] ?? [];
                        $isToday = $dateKey === date('Y-m-d');
                        $isBakdag = in_array($dateKey, $bakdagen);
                    ?>
                        <div class="calendar-cell <?= $isToday ? 'today' : '' ?> <?= $isBakdag ? 'bakdag' : 'non-bakdag' ?>"
                             onclick="openDayModal('<?= $dateKey ?>', '<?= formatDutchDate($current) ?>')">
                            <div class="calendar-date">
                                <span>
                                    <?= $current->format('j') ?>
                                    <?php if ($isBakdag): ?>
                                        <span class="bakdag-badge" style="font-size:0.6rem;padding:0.1rem 0.3rem;"><i class="bi bi-fire"></i></span>
                                    <?php endif; ?>
                                </span>
                                <span class="calendar-count <?= count($orders) === 0 ? 'empty' : '' ?>"><?= count($orders) ?></span>
                            </div>
                            <?php foreach ($dayAppts as $appt): ?>
                                <div class="appointment-item" style="background:<?= htmlspecialchars($appt['color']) ?>" onclick="event.stopPropagation();openEditAppointment(<?= htmlspecialchars(json_encode($appt), ENT_QUOTES) ?>)">
                                    <i class="bi bi-calendar-event"></i>
                                    <?php if ($appt['start_time']): ?><span class="appointment-time"><?= substr($appt['start_time'], 0, 5) ?></span><?php endif; ?>
                                    <?= htmlspecialchars($appt['title']) ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="calendar-preview">
                                <?php foreach (array_slice($orders, 0, 3) as $order): ?>
                                    <div class="calendar-preview-item"><?= htmlspecialchars($order['bedrijfsnaam']) ?></div>
                                <?php endforeach; ?>
                                <?php if (count($orders) > 3): ?>
                                    <div class="calendar-preview-item" style="color: #ff6b35;">+<?= count($orders) - 3 ?> meer</div>
                                <?php endif; ?>
                            </div>
                            <?php if (!$isBakdag): ?>
                                <span class="add-bakdag-hint"><i class="bi bi-plus-circle"></i> bakdag</span>
                            <?php endif; ?>
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
                            $dayAppts = $appointmentsByDate[$dateKey] ?? [];
                            $isToday = $dateKey === date('Y-m-d');
                            $isOtherMonth = $date->format('m') !== $currentMonth;
                            $isBakdag = in_array($dateKey, $bakdagen);
                    ?>
                        <div class="calendar-cell <?= $isToday ? 'today' : '' ?> <?= $isOtherMonth ? 'other-month' : '' ?> <?= !$isOtherMonth ? ($isBakdag ? 'bakdag' : 'non-bakdag') : '' ?>"
                             onclick="openDayModal('<?= $dateKey ?>', '<?= formatDutchDate($date) ?>')">
                            <div class="calendar-date">
                                <span>
                                    <?= $date->format('j') ?>
                                    <?php if ($isBakdag && !$isOtherMonth): ?>
                                        <span class="bakdag-badge" style="font-size:0.55rem;padding:0.1rem 0.25rem;"><i class="bi bi-fire"></i></span>
                                    <?php endif; ?>
                                </span>
                                <?php if (count($orders) > 0): ?>
                                    <span class="calendar-count"><?= count($orders) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$isOtherMonth): ?>
                                <?php foreach (array_slice($dayAppts, 0, 2) as $appt): ?>
                                    <div class="appointment-item" style="background:<?= htmlspecialchars($appt['color']) ?>;font-size:0.65rem;" onclick="event.stopPropagation();openEditAppointment(<?= htmlspecialchars(json_encode($appt), ENT_QUOTES) ?>)">
                                        <i class="bi bi-calendar-event"></i> <?= htmlspecialchars($appt['title']) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($orders) > 0): ?>
                                <div class="calendar-preview">
                                    <?php foreach (array_slice($orders, 0, 2) as $order): ?>
                                        <div class="calendar-preview-item"><?= htmlspecialchars($order['bedrijfsnaam']) ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
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
    
    <!-- Bakdagen settings modal -->
    <div class="modal-overlay" id="bakdagenModal">
        <div class="modal" style="max-width:500px;">
            <div class="modal-header">
                <h3><i class="bi bi-gear"></i> Bakdagen instellen</h3>
                <button class="modal-close" onclick="closeBakdagenModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="bakdagen-modal-section">
                    <h4><i class="bi bi-calendar-check"></i> Vaste bakdagen</h4>
                    <div class="bakdagen-checkboxes">
                        <?php
                        $dagNamen = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
                        for ($d = 1; $d <= 7; $d++):
                        ?>
                        <label>
                            <input type="checkbox" value="<?= $d ?>" <?= in_array($d, $bakdagenPatroon) ? 'checked' : '' ?>>
                            <?= $dagNamen[$d - 1] ?>
                        </label>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="bakdagen-modal-section">
                    <h4><i class="bi bi-calendar-plus"></i> Extra bakdagen</h4>
                    <div class="extra-bakdagen-list" id="extraBakdagenList">
                        <?php if (empty($extraDagen)): ?>
                            <div style="color:#bbb;font-size:0.85rem;">Geen extra bakdagen</div>
                        <?php endif; ?>
                        <?php foreach ($extraDagen as $extra): ?>
                            <div class="extra-bakdag-item">
                                <span><?= (new DateTime($extra['datum']))->format('d-m-Y') ?><?= $extra['notitie'] ? ' — ' . htmlspecialchars($extra['notitie']) : '' ?></span>
                                <button class="extra-bakdag-remove" onclick="removeExtraBakdag('<?= $extra['datum'] ?>')" title="Verwijderen"><i class="bi bi-trash"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="add-extra-bakdag">
                        <input type="date" id="extraBakdagDate">
                        <input type="text" id="extraBakdagNotitie" placeholder="Notitie (optioneel)" style="flex:1;">
                        <button onclick="addExtraBakdagFromModal()"><i class="bi bi-plus"></i> Toevoegen</button>
                    </div>
                </div>
                <button class="btn-save-bakdagen" onclick="saveBakdagenPatroon()"><i class="bi bi-check-lg"></i> Opslaan</button>
            </div>
        </div>
    </div>

    <!-- Appointment modal -->
    <div class="modal-overlay" id="appointmentModal">
        <div class="modal" style="max-width:450px;">
            <div class="modal-header">
                <h3><i class="bi bi-calendar-event"></i> <span id="apptModalTitle">Afspraak toevoegen</span></h3>
                <button class="modal-close" onclick="closeAppointmentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="appt-form">
                    <input type="hidden" id="apptId" value="">
                    <div class="form-group">
                        <label>Titel *</label>
                        <input type="text" class="form-control" id="apptTitle" placeholder="bijv. Overleg leverancier">
                    </div>
                    <div class="form-group">
                        <label>Datum *</label>
                        <input type="date" class="form-control" id="apptDate">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Starttijd</label>
                            <input type="time" class="form-control" id="apptStartTime">
                        </div>
                        <div class="form-group">
                            <label>Eindtijd</label>
                            <input type="time" class="form-control" id="apptEndTime">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Omschrijving</label>
                        <textarea class="form-control" id="apptDescription" rows="2" placeholder="Optioneel..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Kleur</label>
                        <div class="color-options" id="apptColorOptions">
                            <div class="color-option selected" style="background:#3d6b3d" data-color="#3d6b3d" onclick="selectApptColor(this)"></div>
                            <div class="color-option" style="background:#ff6b35" data-color="#ff6b35" onclick="selectApptColor(this)"></div>
                            <div class="color-option" style="background:#2196f3" data-color="#2196f3" onclick="selectApptColor(this)"></div>
                            <div class="color-option" style="background:#4caf50" data-color="#4caf50" onclick="selectApptColor(this)"></div>
                            <div class="color-option" style="background:#9c27b0" data-color="#9c27b0" onclick="selectApptColor(this)"></div>
                            <div class="color-option" style="background:#e91e63" data-color="#e91e63" onclick="selectApptColor(this)"></div>
                            <div class="color-option" style="background:#795548" data-color="#795548" onclick="selectApptColor(this)"></div>
                            <div class="color-option" style="background:#607d8b" data-color="#607d8b" onclick="selectApptColor(this)"></div>
                        </div>
                    </div>
                    <button class="btn-save-appt" onclick="saveAppointment()"><i class="bi bi-check-lg"></i> Opslaan</button>
                    <button class="btn-delete-appt" id="btnDeleteAppt" style="display:none" onclick="deleteAppointment()"><i class="bi bi-trash"></i> Verwijderen</button>
                </div>
            </div>
        </div>
    </div>

    <?php $detailAccentColor = '#3d6b3d'; $detailAccentColorDark = '#2d4a2d'; include 'order-detail-modal.php'; ?>

    <script>
    const currentDate = '<?= $viewDate ?>';
    const currentMode = '<?= $viewMode ?>';
    const ordersByDate = <?= json_encode($ordersByBereidingDate) ?>;
    const appointmentsByDate = <?= json_encode($appointmentsByDate) ?>;
    const bakdagen = <?= json_encode($bakdagen) ?>;
    const voorbereidingDagen = <?= $voorbereidingDagen ?>;
    </script>
    <script src="../../js/bakker-calendar.js?v=1"></script>
    <script>
    // Bakdagen settings functions
    function openBakdagenModal() {
        document.getElementById('bakdagenModal').classList.add('active');
    }
    function closeBakdagenModal() {
        document.getElementById('bakdagenModal').classList.remove('active');
    }
    function saveBakdagenPatroon() {
        const checkboxes = document.querySelectorAll('.bakdagen-checkboxes input[type="checkbox"]:checked');
        const dagen = Array.from(checkboxes).map(cb => parseInt(cb.value));
        fetch('/api/bakdagen.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save_patroon', dagen: dagen })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                showToast('Fout bij opslaan: ' + (data.error || 'Onbekende fout'), 'error');
            }
        });
    }
    function addExtraBakdagFromModal() {
        const datum = document.getElementById('extraBakdagDate').value;
        const notitie = document.getElementById('extraBakdagNotitie').value;
        if (!datum) { showToast('Kies een datum', 'warning'); return; }
        fetch('/api/bakdagen.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_extra', datum: datum, notitie: notitie })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                showToast(data.error || 'Fout bij toevoegen', 'error');
            }
        });
    }
    function removeExtraBakdag(datum) {
        showConfirm('Extra bakdag verwijderen?').then(function(ok) {
            if (!ok) return;
            fetch('/api/bakdagen.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'remove_extra', datum: datum })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    showToast(data.error || 'Fout bij verwijderen', 'error');
                }
            });
        });
    }
    function addImpromptuBakdag(date, dateLabel) {
        showConfirm('Bakdag toevoegen op ' + dateLabel + '?').then(function(ok) {
            if (!ok) return;
            fetch('/api/bakdagen.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add_extra', datum: date, notitie: 'Impromptu bakdag' })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    showToast(data.error || 'Fout bij toevoegen', 'error');
                }
            });
        });
    }

    // Auto-open settings if requested via URL
    if (new URLSearchParams(window.location.search).get('settings') === 'bakdagen') {
        openBakdagenModal();
    }

    // Appointment functions
    let selectedApptColor = '#3d6b3d';

    function openAppointmentModal(date) {
        document.getElementById('apptModalTitle').textContent = 'Afspraak toevoegen';
        document.getElementById('apptId').value = '';
        document.getElementById('apptTitle').value = '';
        document.getElementById('apptDate').value = date || '';
        document.getElementById('apptStartTime').value = '';
        document.getElementById('apptEndTime').value = '';
        document.getElementById('apptDescription').value = '';
        document.getElementById('btnDeleteAppt').style.display = 'none';
        selectApptColorByValue('#3d6b3d');
        document.getElementById('appointmentModal').classList.add('active');
    }

    function openEditAppointment(appt) {
        document.getElementById('apptModalTitle').textContent = 'Afspraak bewerken';
        document.getElementById('apptId').value = appt.id;
        document.getElementById('apptTitle').value = appt.title;
        document.getElementById('apptDate').value = appt.appointment_date;
        document.getElementById('apptStartTime').value = appt.start_time ? appt.start_time.substring(0, 5) : '';
        document.getElementById('apptEndTime').value = appt.end_time ? appt.end_time.substring(0, 5) : '';
        document.getElementById('apptDescription').value = appt.description || '';
        document.getElementById('btnDeleteAppt').style.display = '';
        selectApptColorByValue(appt.color || '#3d6b3d');
        document.getElementById('appointmentModal').classList.add('active');
    }

    function closeAppointmentModal() {
        document.getElementById('appointmentModal').classList.remove('active');
    }

    function selectApptColor(el) {
        document.querySelectorAll('#apptColorOptions .color-option').forEach(o => o.classList.remove('selected'));
        el.classList.add('selected');
        selectedApptColor = el.dataset.color;
    }

    function selectApptColorByValue(color) {
        selectedApptColor = color;
        document.querySelectorAll('#apptColorOptions .color-option').forEach(o => {
            o.classList.toggle('selected', o.dataset.color === color);
        });
    }

    function saveAppointment() {
        const id = document.getElementById('apptId').value;
        const title = document.getElementById('apptTitle').value.trim();
        const date = document.getElementById('apptDate').value;
        const startTime = document.getElementById('apptStartTime').value || null;
        const endTime = document.getElementById('apptEndTime').value || null;
        const description = document.getElementById('apptDescription').value.trim();

        if (!title || !date) { showToast('Vul titel en datum in', 'warning'); return; }

        const payload = {
            action: id ? 'update' : 'create',
            title, appointment_date: date, start_time: startTime, end_time: endTime,
            description, color: selectedApptColor
        };
        if (id) payload.id = parseInt(id);

        fetch('../../api/appointments.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error');
            }
        });
    }

    function deleteAppointment() {
        const id = document.getElementById('apptId').value;
        if (!id) return;
        showConfirm('Afspraak verwijderen?').then(function(ok) {
            if (!ok) return;
            fetch('../../api/appointments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id: parseInt(id) })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error');
                }
            });
        });
    }

    document.getElementById('appointmentModal').addEventListener('click', function(e) {
        if (e.target === this) closeAppointmentModal();
    });

    function openDayModal(date, dateLabel, filterDoughType) {
        const isBakdagDay = bakdagen.includes(date);
        const badgeHtml = isBakdagDay ? ' <span class="bakdag-badge"><i class="bi bi-fire"></i> Bakdag</span>' : '';
        const filterHtml = filterDoughType ? ` <span style="font-size:0.85rem;color:#c8913a;font-weight:600"><i class="bi bi-layers"></i> ${escapeHtml(filterDoughType)}</span>` : '';
        document.getElementById('dayModalDate').innerHTML = escapeHtml(dateLabel) + badgeHtml + filterHtml;

        const orders = ordersByDate[date] || [];
        let html = '';

        // Filter items by dough type if specified
        const filteredOrders = filterDoughType ? orders.map(order => ({
            ...order,
            items: order.items.filter(item => (item.dough_type_name || 'Geen deegsoort') === filterDoughType)
        })).filter(order => order.items.length > 0) : orders;

        if (filteredOrders.length === 0) {
            html = '<div class="empty-state"><i class="bi bi-emoji-smile"></i><p>Geen bestellingen om te bereiden</p></div>';
        } else {
            const productTotals = {};
            filteredOrders.forEach(order => {
                order.items.forEach(item => {
                    if (!productTotals[item.product_name]) productTotals[item.product_name] = { qty: 0, amount: 0 };
                    productTotals[item.product_name].qty += parseInt(item.quantity);
                    productTotals[item.product_name].amount += parseInt(item.quantity) * parseFloat(item.unit_price);
                });
            });

            const sortedProducts = Object.entries(productTotals).sort((a, b) => b[1].qty - a[1].qty);

            const doughTypeTotals = {};
            filteredOrders.forEach(order => {
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
                html += `<div class="dough-type-header"><span><i class="bi bi-layers"></i> ${escapeHtml(doughType)}</span><span style="font-weight:700;color:#2d4a2d">${kgTotal} kg</span></div>`;
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
            const doughParam = filterDoughType ? `&dough_type=${encodeURIComponent(filterDoughType)}` : '';
            html += `<a href="dagproductie.php?date=${date}${doughParam}" class="btn-dagproductie"><i class="bi bi-calculator"></i> Bekijk ingrediënten</a>`;
            html += '</div></div>';

            html += `<div class="orders-section"><h4><i class="bi bi-people"></i> Klanten (${filteredOrders.length})</h4>`;
            filteredOrders.forEach(order => {
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
        
        // Appointments section in modal
        const dayAppts = appointmentsByDate[date] || [];
        html += `<div class="appointments-section" style="${dayAppts.length === 0 ? '' : ''}">`;
        html += `<h4><i class="bi bi-calendar-event"></i> Afspraken (${dayAppts.length}) <button class="btn-add-appt" onclick="closeAllModals();openAppointmentModal('${date}')" style="margin-left:auto"><i class="bi bi-plus"></i> Nieuw</button></h4>`;
        if (dayAppts.length > 0) {
            dayAppts.forEach(appt => {
                const timeStr = appt.start_time ? `<div class="appt-time"><i class="bi bi-clock"></i> ${appt.start_time.substring(0,5)}${appt.end_time ? ' - ' + appt.end_time.substring(0,5) : ''}</div>` : '';
                const descStr = appt.description ? `<div class="appt-desc">${escapeHtml(appt.description)}</div>` : '';
                html += `<div class="appointment-card" onclick='closeAllModals();openEditAppointment(${JSON.stringify(appt).replace(/'/g, "&#39;")})'>
                    <div class="appt-color" style="background:${appt.color || '#3d6b3d'}"></div>
                    <div class="appt-info">
                        <div class="appt-title">${escapeHtml(appt.title)}</div>
                        ${timeStr}${descStr}
                    </div>
                </div>`;
            });
        } else {
            html += '<div style="color:#bbb;font-size:0.85rem;padding:0.3rem 0;">Geen afspraken</div>';
        }
        html += '</div>';

        document.getElementById('dayModalBody').innerHTML = html;
        document.getElementById('dayModal').classList.add('active');
    }

    function closeAllModals() {
        document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
    }
    </script>
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('../sw.js', { scope: '/admin/' });
        if ('PushManager' in window) {
            navigator.serviceWorker.ready.then(async reg => {
                try {
                    let permission = Notification.permission;
                    if (permission === 'default') {
                        permission = await Notification.requestPermission();
                    }
                    if (permission !== 'granted') return;

                    let sub = await reg.pushManager.getSubscription();
                    if (!sub) {
                        const r = await fetch('/api/push-subscriptions.php?action=vapid-key');
                        const { publicKey } = await r.json();
                        const padding = '='.repeat((4 - publicKey.length % 4) % 4);
                        const raw = atob((publicKey + padding).replace(/-/g, '+').replace(/_/g, '/'));
                        const key = Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
                        sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
                    }
                    const j = sub.toJSON();
                    await fetch('/api/push-subscriptions.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: j.endpoint, keys: { p256dh: j.keys.p256dh, auth: j.keys.auth } }) });
                } catch (e) { console.error('Push setup failed:', e); }
            });
        }
    }
    </script>
</body>
</html>
