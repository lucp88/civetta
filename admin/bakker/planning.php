<?php
require_once '../config.php';
requireLogin();

$defaultFilter = isset($_GET['filter']) ? $_GET['filter'] : '';
$viewDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$viewMode = isset($_GET['mode']) ? $_GET['mode'] : 'week';

$currentDate = new DateTime($viewDate);

// date range calc identical to bereiden.php
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

// Bakdagen config (from bereiden.php)
$bakdagenPatroonStr = '';
$stmtBp = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_patroon'");
$stmtBp->execute();
$bakdagenPatroonStr = $stmtBp->fetchColumn() ?: '';
$bakdagenPatroon = $bakdagenPatroonStr ? array_map('intval', explode(',', $bakdagenPatroonStr)) : [];

$stmtVd = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
$stmtVd->execute();
$voorbereidingDagen = (int)($stmtVd->fetchColumn() ?: 3);

$stmtExtra = $pdo->prepare("SELECT datum, notitie, COALESCE(type,'extra') as type FROM bakdagen_extra WHERE datum BETWEEN ? AND ? ORDER BY datum");
$stmtExtra->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$allExtraDagen = $stmtExtra->fetchAll();
$extraDagen    = array_values(array_filter($allExtraDagen, fn($r) => $r['type'] === 'extra'));
$sluitingDagen = array_values(array_filter($allExtraDagen, fn($r) => $r['type'] === 'sluiting'));
$extraDatums    = array_column($extraDagen, 'datum');
$sluitingDatums = array_column($sluitingDagen, 'datum');

$bakdagen = [];
$iterDt = clone $startDate;
while ($iterDt <= $endDate) {
    $weekday = (int)$iterDt->format('N');
    $dateStr = $iterDt->format('Y-m-d');
    if (!in_array($dateStr, $sluitingDatums) && (in_array($weekday, $bakdagenPatroon) || in_array($dateStr, $extraDatums))) {
        $bakdagen[] = $dateStr;
    }
    $iterDt->modify('+1 day');
}

// Bakkerij address (from leveren.php)
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bedrijf_adres'");
$startAdres = $stmt->fetchColumn() ?: '';
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bedrijf_postcode'");
$startPostcode = $stmt->fetchColumn() ?: '';
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bedrijf_plaats'");
$startPlaats = $stmt->fetchColumn() ?: '';
$bakkerijAdres = trim($startAdres . ', ' . $startPostcode . ' ' . $startPlaats, ', ');

// Orders with rich data (from bereiden.php)
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
        $item['method_days_count'] = $voorbereidingDagen;
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
    $order['bereiding_date'] = $order['delivery_date'];

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

// Appointments
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

// Prep bars for week view (from bereiden.php)
$recipeBarsByBakdag = [];
foreach ($bakdagen as $bakdag) {
    $orders = $ordersByDate[$bakdag] ?? [];
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
<?php
$adminPageTitle = 'Planning';
$adminBasePath = '../';
$currentPage = 'planning';
ob_start();
?>
    <link rel="stylesheet" href="../../css/admin-bakker.css?v=3">
    <style>
        :root {
            --accent: #3d6b3d;
            --accent-dark: #2d4a2d;
            --accent-hover: #f5f0ea;
            --color-bakken: #ff6b35;
            --color-bezorging: #2196f3;
            --color-afspraak: #9c27b0;
        }

        /* Filter toggles */
        .filter-bar {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .filter-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            border: 2px solid #ddd;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            color: #888;
            transition: all 0.2s;
        }
        .filter-toggle:hover { border-color: #bbb; color: #666; }
        .filter-toggle.active[data-type="bakken"] { border-color: var(--color-bakken); color: var(--color-bakken); background: #fff5f0; }
        .filter-toggle.active[data-type="bezorging"] { border-color: var(--color-bezorging); color: var(--color-bezorging); background: #e3f2fd; }
        .filter-toggle.active[data-type="afspraak"] { border-color: var(--color-afspraak); color: var(--color-afspraak); background: #f3e5f5; }
        .filter-count {
            background: #eee;
            color: #999;
            padding: 0.1rem 0.45rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .filter-toggle.active .filter-count { background: currentColor; color: white; }

        /* Type indicators in cells */
        .type-bakken { color: var(--color-bakken); }
        .type-bezorging { color: var(--color-bezorging); }
        .type-afspraak { color: var(--color-afspraak); }

        .calendar-cell.bakdag { border-top: 3px solid var(--color-bakken); }
        .calendar-cell.bakdag.today { border: 2px solid var(--accent); border-top: 3px solid var(--color-bakken); }

        .bakdag-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: linear-gradient(135deg, var(--color-bakken), #e55a2b);
            color: white;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
        }

        /* Delivery preview items */
        .calendar-preview-item {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .calendar-preview-item i.type-bezorging { color: var(--color-bezorging); }

        /* Prep bars (from bereiden) */
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
            border-left: 4px solid var(--color-bakken);
            min-height: 44px;
        }
        .prep-bar:hover { filter: brightness(0.95); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
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
        .prep-bar-inner i { color: var(--color-bakken); flex-shrink: 0; }
        .prep-bar-count {
            margin-left: auto;
            background: var(--color-bakken);
            color: white;
            padding: 0.15rem 0.5rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .prep-bar-days { font-size: 0.7rem; color: #8b7355; font-weight: 400; }

        /* Bereiden-specific styles */
        .dough-type-header {
            font-weight: 700; font-size: 0.95rem; color: #2d4a2d;
            padding: 0.75rem 0 0.4rem; border-bottom: 3px solid #c8913a;
            margin-top: 0.75rem; display: flex; justify-content: space-between; align-items: center;
            background: linear-gradient(to bottom, #faf6f1, transparent);
            text-decoration: none; cursor: pointer;
        }
        a.dough-type-header:hover { background: linear-gradient(to bottom, #f0e8dc, transparent); color: #92400e; }
        a.dough-type-header:hover .dth-arrow { opacity: 1; }
        .dth-arrow { opacity: 0; font-size: 0.75rem; margin-left: 0.35rem; transition: opacity 0.15s; }
        .dough-type-header:first-child { margin-top: 0; }
        .dough-type-header i { margin-right: 0.4rem; color: #c8913a; }
        .recipe-group-title {
            font-weight: 600; font-size: 0.85rem; color: #3d6b3d;
            padding: 0.5rem 0 0.25rem; border-bottom: 1px solid #e8dfd2;
            margin-top: 0.4rem; display: flex; justify-content: space-between; align-items: center;
        }
        .recipe-group-title:first-child { margin-top: 0; }
        .recipe-group-title i { margin-right: 0.3rem; color: #c8913a; }
        .btn-dagproductie {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            width: 100%; margin-top: 1rem; padding: 0.75rem;
            background: linear-gradient(135deg, #c8913a, #a0722e);
            color: white; text-decoration: none; border-radius: 8px;
            font-weight: 600; font-size: 0.9rem; transition: all 0.2s;
        }
        .btn-dagproductie:hover { background: linear-gradient(135deg, #a0722e, #3d6b3d); transform: translateY(-1px); }

        /* Settings gear button */
        .btn-settings {
            width: 36px; height: 36px; border: none; background: white;
            border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); color: var(--accent-dark); font-size: 1.1rem; transition: all 0.2s;
        }
        .btn-settings:hover { background: var(--accent-hover); transform: rotate(30deg); }

        /* Bakdagen settings modal */
        .bakdagen-checkboxes { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .bakdagen-checkboxes label {
            display: flex; align-items: center; gap: 0.35rem; padding: 0.5rem 0.75rem;
            background: #f5f2ed; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem;
            color: #2d4a2d; border: 2px solid transparent; transition: all 0.2s;
        }
        .bakdagen-checkboxes label:has(input:checked) { background: #fff5f0; border-color: var(--color-bakken); color: #e55a2b; }
        .bakdagen-checkboxes input[type="checkbox"] { accent-color: var(--color-bakken); }
        .extra-bakdagen-list { margin-bottom: 0.75rem; }
        .extra-bakdag-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.4rem 0; border-bottom: 1px solid #f0f0f0; font-size: 0.85rem;
        }
        .extra-bakdag-item:last-child { border-bottom: none; }
        .extra-bakdag-remove { background: none; border: none; color: #dc3545; cursor: pointer; font-size: 1rem; padding: 0.25rem; }
        .add-extra-bakdag { display: flex; gap: 0.5rem; align-items: center; }
        .add-extra-bakdag input { padding: 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.85rem; }
        .add-extra-bakdag button {
            padding: 0.4rem 0.75rem; background: var(--color-bakken); color: white;
            border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.85rem;
        }
        .add-extra-bakdag button:hover { background: #e55a2b; }
        .bakdagen-modal-section h4 { color: #2d4a2d; font-size: 0.9rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem; }
        .bakdagen-modal-section { margin-bottom: 1.25rem; }
        .btn-save-bakdagen {
            width: 100%; padding: 0.75rem;
            background: linear-gradient(135deg, var(--color-bakken), #e55a2b);
            color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer;
        }
        .btn-save-bakdagen:hover { background: linear-gradient(135deg, #e55a2b, #cc4a1a); transform: translateY(-1px); }

        /* Leveren-specific styles */
        .modal { max-width: 800px; }
        .modal-body { padding: 0; }
        .route-summary { display: flex; gap: 2rem; padding: 1rem 1.25rem; background: #f8f9fa; border-bottom: 1px solid #eee; }
        .route-stat { text-align: center; }
        .route-stat-value { font-size: 1.5rem; font-weight: 700; color: var(--color-bezorging); }
        .route-stat-label { font-size: 0.75rem; color: #666; text-transform: uppercase; }
        .route-actions {
            display: flex; gap: 1rem; padding: 1rem 1.25rem; background: white;
            border-bottom: 1px solid #eee; flex-wrap: wrap; align-items: center;
        }
        .btn { padding: 0.6rem 1.2rem; border-radius: 6px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; border: none; }
        .btn-onderweg { background: linear-gradient(135deg, #ff9800, #f57c00); color: white; }
        .btn-onderweg:hover { background: linear-gradient(135deg, #f57c00, #e65100); }
        .btn-onderweg:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-route { background: linear-gradient(135deg, var(--color-bezorging), #1976d2); color: white; }
        .btn-outline { background: white; border: 2px solid var(--color-bezorging); color: var(--color-bezorging); }
        .btn-outline:hover { background: #e3f2fd; }
        .btn-delivered { background: #4caf50; color: white; }
        .btn-delivered:hover { background: #388e3c; }
        .btn-delivered.done { background: #e8f5e9; color: #2e7d32; cursor: default; }
        .email-toggle { display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: #666; }
        .email-toggle input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--color-bezorging); }
        .success-message { background: #d4edda; color: #155724; padding: 1rem 1.25rem; display: none; align-items: center; gap: 0.75rem; font-weight: 500; }
        .success-message.show { display: flex; }
        .route-stops { max-height: 400px; overflow-y: auto; }
        .route-point { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.25rem; }
        .route-point.start { background: #e8f5e9; border-bottom: 1px solid #c8e6c9; }
        .route-point.end { background: #fff3e0; border-top: 1px solid #ffe0b2; }
        .route-point .marker { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; flex-shrink: 0; }
        .route-point.start .marker { background: #4caf50; color: white; }
        .route-point.end .marker { background: #ff9800; color: white; }
        .route-point .info h4 { margin: 0; font-size: 0.95rem; }
        .route-point.start .info h4 { color: #2e7d32; }
        .route-point.end .info h4 { color: #e65100; }
        .route-point .info p { margin: 0.25rem 0 0; font-size: 0.85rem; color: #666; }
        .route-stop { display: flex; align-items: flex-start; gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid #f0f0f0; transition: background 0.15s; }
        .route-stop:hover { background: #fafafa; }
        .route-stop.delivered { background: #f0f9f0; }
        .route-stop .marker { width: 36px; height: 36px; background: #1976d2; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; flex-shrink: 0; }
        .route-stop.delivered .marker { background: #4caf50; }
        .route-stop .info { flex: 1; }
        .route-stop .info h4 { margin: 0 0 0.25rem; color: #333; font-size: 1rem; }
        .route-stop .info .address { color: #666; font-size: 0.85rem; display: flex; align-items: center; gap: 0.3rem; }
        .route-stop .info .products { color: #888; font-size: 0.8rem; margin-top: 0.3rem; }
        .route-stop .info .badges { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .route-stop .actions { display: flex; gap: 0.5rem; align-items: center; }
        .route-stop .actions .btn { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
        .connector { width: 2px; height: 20px; background: #e0e0e0; margin: -5px 0 -5px 17px; }

        /* FAB + New order modal */
        .fab-wrapper {
            position: fixed; bottom: 2rem; right: 2rem; z-index: 900;
            display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem;
        }
        .fab {
            width: 56px; height: 56px;
            border-radius: 50%; background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white; border: none; cursor: pointer; box-shadow: 0 4px 15px rgba(139,90,43,0.4);
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem; transition: all 0.25s;
            flex-shrink: 0;
        }
        .fab:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(139,90,43,0.5); }
        .fab.open { transform: rotate(45deg); background: linear-gradient(135deg, #666, #444); }
        .fab-options {
            display: flex; flex-direction: column; align-items: flex-end; gap: 0.4rem;
            visibility: hidden; opacity: 0; transform: translateY(10px); transition: all 0.2s;
        }
        .fab-options.show { visibility: visible; opacity: 1; transform: translateY(0); }
        .fab-option {
            display: flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1rem;
            background: white; border: none; border-radius: 24px; cursor: pointer;
            font-size: 0.88rem; font-weight: 600; box-shadow: 0 3px 12px rgba(0,0,0,0.15);
            white-space: nowrap; transition: all 0.15s;
        }
        .fab-option:hover { transform: translateX(-3px); box-shadow: 0 5px 16px rgba(0,0,0,0.2); }
        .fab-option-bakken i { color: var(--color-bakken); }
        .fab-option-bezorging i { color: var(--color-bezorging); }
        .fab-option-afspraak i { color: var(--color-afspraak); }

        /* Empty-day plus indicator */
        .cell-add-hint {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            font-size: 1.6rem; color: #ddd; pointer-events: none; transition: color 0.15s;
        }
        .calendar-cell:hover .cell-add-hint { color: var(--accent); }
        .new-order-modal { max-width: 700px; }
        .new-order-modal .modal-body { padding: 1.25rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #333; margin-bottom: 0.4rem; }
        .form-control { width: 100%; padding: 0.6rem 0.8rem; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; transition: border-color 0.2s; box-sizing: border-box; }
        .form-control:focus { border-color: var(--accent); outline: none; }
        .product-select-row { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem; }
        .product-select-row select { flex: 3; }
        .product-select-row input[type="number"] { flex: 1; min-width: 60px; }
        .product-select-row .product-price { flex: 1; min-width: 80px; text-align: right; color: #666; font-size: 0.9rem; white-space: nowrap; }
        .product-select-row .btn-remove { width: 32px; height: 32px; border: none; background: #f8d7da; color: #dc3545; border-radius: 6px; cursor: pointer; font-size: 1rem; flex-shrink: 0; }
        .product-select-row .btn-remove:hover { background: #dc3545; color: white; }
        .product-select-row .variant-select { flex: 2; }
        .btn-add-product { padding: 0.4rem 1rem; border: 2px dashed var(--accent); background: transparent; color: var(--accent); border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 500; }
        .btn-add-product:hover { background: var(--accent-hover); }
        .order-total-bar { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; background: #f8f9fa; border-top: 1px solid #eee; font-size: 1.1rem; font-weight: 600; }
        .order-total-bar .total-amount { color: var(--accent-dark); font-size: 1.3rem; }
        .btn-submit-order { padding: 0.75rem 2rem; background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 1rem; font-weight: 600; }
        .btn-submit-order:hover { background: linear-gradient(135deg, var(--accent-dark), #4a2f15); }
        .btn-submit-order:disabled { opacity: 0.6; cursor: not-allowed; }
        .customer-info-card { display: none; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 0.75rem 1rem; margin-top: 0.5rem; }
        .customer-info-card.show { display: block; }
        .customer-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        .customer-info-item { font-size: 0.85rem; }
        .customer-info-item .ci-label { font-size: 0.7rem; text-transform: uppercase; color: #888; font-weight: 600; }
        .customer-info-item .ci-value { color: #333; }
        .customer-info-item .ci-value a { color: var(--accent); text-decoration: none; }
        .customer-info-item .ci-value a:hover { text-decoration: underline; }
        .customer-info-item.full-width { grid-column: 1 / -1; }
        .internal-toggle { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 500; padding: 0.6rem 0.8rem; background: #f8f9fa; border: 2px solid #e0e0e0; border-radius: 8px; transition: all 0.2s; }
        .internal-toggle:has(input:checked) { background: #fff3e0; border-color: #ff9800; }
        .internal-toggle input[type="checkbox"] { width: 18px; height: 18px; accent-color: #3d6b3d; }
        .bakdag-indicator { margin-top: 0.4rem; font-size: 0.85rem; }
        .bakdag-ok { color: #2e7d32; display: flex; align-items: center; gap: 0.3rem; }
        .bakdag-warning { margin-top: 0.4rem; font-size: 0.85rem; color: #e65100; background: #fff3e0; padding: 0.5rem 0.75rem; border-radius: 6px; display: flex; align-items: center; gap: 0.3rem; }
        .bakdag-warning strong { cursor: pointer; text-decoration: underline; }

        /* Appointments */
        .appointment-item {
            display: flex; align-items: center; gap: 0.35rem; font-size: 0.75rem;
            padding: 0.2rem 0.4rem; border-radius: 4px; color: white; font-weight: 600;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 0.15rem;
        }
        .appointment-item i { font-size: 0.65rem; flex-shrink: 0; }
        .appointment-time { font-weight: 400; opacity: 0.85; font-size: 0.65rem; }
        .appointments-section { margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #eee; }
        .appointments-section h4 { font-size: 0.9rem; color: #2d4a2d; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem; }
        .appointment-card {
            display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.6rem 0.75rem;
            border-radius: 8px; background: #f8f6f3; margin-bottom: 0.4rem; cursor: pointer; transition: background 0.15s;
        }
        .appointment-card:hover { background: #f0ede8; }
        .appointment-card .appt-color { width: 4px; min-height: 32px; border-radius: 2px; flex-shrink: 0; }
        .appointment-card .appt-info { flex: 1; min-width: 0; }
        .appointment-card .appt-title { font-weight: 600; font-size: 0.9rem; color: #333; }
        .appointment-card .appt-time { font-size: 0.8rem; color: #888; }
        .appointment-card .appt-desc { font-size: 0.8rem; color: #666; margin-top: 0.2rem; }

        .appt-form .form-group { margin-bottom: 0.75rem; }
        .appt-form .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: #2d4a2d; margin-bottom: 0.3rem; }
        .appt-form .form-control { width: 100%; padding: 0.5rem 0.7rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.9rem; box-sizing: border-box; }
        .appt-form .form-control:focus { outline: none; border-color: var(--accent); }
        .appt-form .form-row { display: flex; gap: 0.5rem; }
        .appt-form .form-row .form-group { flex: 1; }
        .appt-form .color-options { display: flex; gap: 0.4rem; flex-wrap: wrap; }
        .appt-form .color-option { width: 28px; height: 28px; border-radius: 50%; border: 2px solid transparent; cursor: pointer; transition: all 0.15s; }
        .appt-form .color-option:hover { transform: scale(1.15); }
        .appt-form .color-option.selected { border-color: #333; box-shadow: 0 0 0 2px white, 0 0 0 4px #333; }
        .btn-save-appt { width: 100%; padding: 0.75rem; background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer; }
        .btn-save-appt:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .btn-delete-appt { width: 100%; padding: 0.5rem; background: none; color: #dc3545; border: 1px solid #dc3545; border-radius: 8px; font-weight: 600; font-size: 0.8rem; cursor: pointer; margin-top: 0.5rem; }
        .btn-delete-appt:hover { background: #dc3545; color: white; }
        .btn-add-appt { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.4rem 0.7rem; background: var(--accent); color: white; border: none; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; }
        .btn-add-appt:hover { background: var(--accent-dark); }

        /* Multi-select orders */
        .order-row { position: relative; }
        .order-checkbox { width: 18px; height: 18px; accent-color: var(--accent); cursor: pointer; flex-shrink: 0; margin-right: 0.5rem; }
        .order-row.selected { background: #f5f0eb; border-left: 3px solid var(--accent); }
        .batch-bar { display: none; position: sticky; bottom: 0; background: white; border-top: 2px solid var(--accent); padding: 0.75rem 1.25rem; z-index: 10; gap: 0.75rem; align-items: center; justify-content: space-between; box-shadow: 0 -4px 12px rgba(0,0,0,0.1); }
        .batch-bar.show { display: flex; }
        .batch-bar-info { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
        .batch-bar-actions { display: flex; gap: 0.5rem; }
        .batch-btn { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.5rem 0.9rem; border: none; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; }
        .batch-btn-deliver { background: #4caf50; color: white; }
        .batch-btn-deliver:hover { background: #388e3c; }
        .batch-btn-cancel { background: #dc3545; color: white; }
        .batch-btn-cancel:hover { background: #b71c1c; }
        .batch-btn-deselect { background: #eee; color: #666; }
        .batch-btn-deselect:hover { background: #ddd; }

        /* Day modal section headers */
        .day-section-header {
            display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.25rem;
            font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .day-section-header.bakken-header { background: #fff5f0; color: var(--color-bakken); border-bottom: 2px solid var(--color-bakken); }
        .day-section-header.bezorging-header { background: #e3f2fd; color: var(--color-bezorging); border-bottom: 2px solid var(--color-bezorging); }
        .day-section-header.afspraak-header { background: #f3e5f5; color: var(--color-afspraak); border-bottom: 2px solid var(--color-afspraak); }
        .day-section-header { cursor: pointer; user-select: none; }
        .day-section-header .collapse-icon { margin-left: auto; font-size: 0.75rem; transition: transform 0.2s; }
        .day-section.collapsed .day-section-header .collapse-icon { transform: rotate(-90deg); }
        .day-section.collapsed .day-section-body { display: none; }
        .day-section.collapsed .day-section-header { opacity: 0.6; border-bottom-style: dashed; }
        .day-section-body { padding: 1.25rem; }
        .day-section-body:empty { display: none; }

        /* Hidden by filter */
        [data-type].filter-hidden { display: none !important; }

        /* Add menu in day modal */
        .modal-add-menu { position: relative; display: inline-block; }
        .modal-add-btn { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.4rem 0.75rem; background: var(--accent); color: white; border: none; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; }
        .modal-add-btn:hover { background: var(--accent-dark); }
        .modal-add-dropdown { display: none; position: absolute; right: 0; top: 100%; margin-top: 4px; background: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); z-index: 100; min-width: 180px; overflow: hidden; }
        .modal-add-dropdown.show { display: block; }
        .modal-add-option { display: flex; align-items: center; gap: 0.5rem; padding: 0.7rem 1rem; font-size: 0.85rem; font-weight: 500; cursor: pointer; border: none; background: none; width: 100%; text-align: left; }
        .modal-add-option:hover { background: #f5f2ed; }
        .modal-add-option i { font-size: 1rem; width: 20px; text-align: center; }
        .modal-add-option.afspraak i { color: var(--color-afspraak); }
        .modal-add-option.bakken i { color: var(--color-bakken); }
        .modal-add-option.bezorging i { color: var(--color-bezorging); }

        /* Non-bakdag hint */
        .add-bakdag-hint { position: absolute; bottom: 4px; right: 4px; font-size: 0.65rem; color: #bbb; display: none; }
        .calendar-cell.non-bakdag { opacity: 0.5; background: #f5f2ed; }
        .calendar-cell.non-bakdag:hover { opacity: 0.8; background: #ede8e0; }
        .calendar-cell.non-bakdag:hover .add-bakdag-hint { display: block; }
        .calendar-bakken-badge { font-size: 0.7rem; color: var(--color-bakken); padding: 0.15rem 0; margin-top: 0.15rem; }
        .calendar-bakken-badge i { font-size: 0.65rem; }

        .day-content { padding: 1rem; }
        .day-content .route-actions { padding: 0; margin-bottom: 1rem; background: transparent; border: none; }

        @media (max-width: 768px) {
            .week-bars-container { padding: 0.35rem 0.15rem; min-height: 40px; }
            .prep-bar { padding: 0.35rem 0.5rem; min-height: 36px; }
            .prep-bar-inner { font-size: 0.75rem; gap: 0.3rem; }
            .prep-bar-count { font-size: 0.65rem; padding: 0.1rem 0.35rem; }
            .prep-bar-days { display: none; }
            .bakdagen-checkboxes { gap: 0.35rem; }
            .bakdagen-checkboxes label { padding: 0.35rem 0.5rem; font-size: 0.8rem; }
            .add-extra-bakdag { flex-wrap: wrap; }
            .route-summary { gap: 1rem; padding: 0.75rem 1rem; flex-wrap: wrap; }
            .route-stat-value { font-size: 1.2rem; }
            .route-actions { padding: 0.75rem 1rem; gap: 0.5rem; }
            .route-actions .btn { padding: 0.5rem 0.9rem; font-size: 0.8rem; }
            .route-stop { padding: 0.75rem 1rem; flex-wrap: wrap; }
            .route-stop .actions { width: 100%; justify-content: flex-start; margin-top: 0.5rem; padding-left: 52px; }
            .route-stop .actions .btn { padding: 0.35rem 0.7rem; font-size: 0.75rem; }
            .fab { bottom: 1.5rem; right: 1.5rem; width: 48px; height: 48px; font-size: 1.25rem; }
            .new-order-modal .modal-body { padding: 1rem; }
            .product-select-row { flex-wrap: wrap; }
            .product-select-row select { flex: 1 1 100%; }
            .customer-info-grid { grid-template-columns: 1fr; }
            .day-content .route-actions { flex-direction: column; align-items: stretch; }
        }
        @media (max-width: 480px) {
            .filter-bar { gap: 0.35rem; }
            .filter-toggle { padding: 0.4rem 0.7rem; font-size: 0.8rem; }
            .route-stop .marker { width: 30px; height: 30px; font-size: 0.8rem; }
            .route-actions { flex-direction: column; align-items: stretch; }
            .route-actions .btn { justify-content: center; }
            .btn-add-product { width: 100%; text-align: center; justify-content: center; display: flex; }
        }
    </style>
<?php $adminExtraHead = ob_get_clean(); require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title"><i class="bi bi-calendar3"></i> Planning</span>
                </div>
                <div class="topbar-right"></div>
            </header>

    <div class="container">
        <div class="top-bar">
            <div class="breadcrumb">
                <a href="../index.php">Dashboard</a>
                <span>›</span>
                <a href="bakker-dashboard.php">Bakker</a>
                <span>›</span>
                Planning
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

            <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
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
                <div class="filter-bar" id="filterBar">
                    <button class="filter-toggle active" data-type="bakken" onclick="toggleFilter('bakken')">
                        <i class="bi bi-fire"></i> Bakken <span class="filter-count" id="countBakken">0</span>
                    </button>
                    <button class="filter-toggle active" data-type="bezorging" onclick="toggleFilter('bezorging')">
                        <i class="bi bi-truck"></i> Bezorging <span class="filter-count" id="countBezorging">0</span>
                    </button>
                    <button class="filter-toggle active" data-type="afspraak" onclick="toggleFilter('afspraak')">
                        <i class="bi bi-calendar-event"></i> Afspraken <span class="filter-count" id="countAfspraak">0</span>
                    </button>
                </div>
                <button class="btn-settings" onclick="openBakdagenModal()" title="Bakdagen instellen">
                    <i class="bi bi-gear"></i>
                </button>
            </div>
        </div>

        <div class="calendar-container">
            <?php if ($viewMode === 'day'): ?>
                <?php
                $dateKey = $currentDate->format('Y-m-d');
                $orders = $ordersByDate[$dateKey] ?? [];
                $dayAppointments = $appointmentsByDate[$dateKey] ?? [];
                $isToday = $dateKey === date('Y-m-d');
                $isBakdag = in_array($dateKey, $bakdagen);
                ?>
                <div class="calendar-grid day-view">
                    <div class="calendar-cell day-view-cell <?= $isToday ? 'today' : '' ?> <?= $isBakdag ? 'bakdag' : 'non-bakdag' ?>" style="cursor:default">
                        <div class="calendar-date">
                            <span>
                                <?= formatDutchDate($currentDate) ?>
                                <?php if ($isBakdag): ?>
                                    <span class="bakdag-badge"><i class="bi bi-fire"></i> Bakdag</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <!-- Appointments section -->
                        <div data-type="afspraak">
                            <?php if (!empty($dayAppointments)): ?>
                                <div class="appointments-section">
                                    <h4><i class="bi bi-calendar-event type-afspraak"></i> Afspraken (<?= count($dayAppointments) ?>)
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
                        </div>

                        <!-- Bakken section -->
                        <div data-type="bakken">
                        <?php if (!empty($orders)): ?>
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
                            $doughTypeTotals = [];
                            foreach ($orders as $o) {
                                foreach ($o['items'] as $item) {
                                    $doughTypeName = $item['dough_type_name'] ?? 'Geen deegsoort';
                                    $recipeName = $item['recipe_name'] ?? 'Geen recept';
                                    $doughWeight = $item['dough_weight'] ?? 0;
                                    $productName = $item['product_name'];
                                    if ($doughWeight > 0) {
                                        if (!isset($doughTypeTotals[$doughTypeName])) $doughTypeTotals[$doughTypeName] = ['recipes' => [], 'total_dough' => 0, 'total_qty' => 0];
                                        if (!isset($doughTypeTotals[$doughTypeName]['recipes'][$recipeName])) $doughTypeTotals[$doughTypeName]['recipes'][$recipeName] = ['weights' => [], 'total_dough' => 0];
                                        if (!isset($doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['weights'][$doughWeight])) $doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['weights'][$doughWeight] = ['qty' => 0];
                                        $doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['weights'][$doughWeight]['qty'] += $item['quantity'];
                                        $doughTypeTotals[$doughTypeName]['recipes'][$recipeName]['total_dough'] += $item['quantity'] * $doughWeight;
                                        $doughTypeTotals[$doughTypeName]['total_dough'] += $item['quantity'] * $doughWeight;
                                        $doughTypeTotals[$doughTypeName]['total_qty'] += $item['quantity'];
                                    }
                                }
                            }
                            ksort($doughTypeTotals);
                            foreach ($doughTypeTotals as &$dt) { ksort($dt['recipes']); foreach ($dt['recipes'] as &$r) { krsort($r['weights']); } }
                            unset($dt, $r);
                            ?>
                            <div class="totals-section">
                                <h4><i class="bi bi-fire type-bakken"></i> Te bereiden (<?= count($orders) ?> bestelling<?= count($orders) !== 1 ? 'en' : '' ?>)</h4>
                                <div class="totals-tab-content active" data-tab="recepten">
                                    <div class="product-totals-list">
                                        <?php foreach ($doughTypeTotals as $doughType => $dtData): ?>
                                            <a href="dagproductie.php?date=<?= $currentDate->format('Y-m-d') ?>&dough_type=<?= urlencode($doughType) ?>" class="dough-type-header">
                                                <span><i class="bi bi-layers"></i> <span class="product-total-qty"><?= $dtData['total_qty'] ?>x</span> <?= htmlspecialchars($doughType) ?></span>
                                                <span style="font-weight:700;color:#2d4a2d"><?= number_format($dtData['total_dough']/1000, 2, ',', '.') ?> kg <i class="bi bi-arrow-right dth-arrow"></i></span>
                                            </a>
                                            <?php foreach ($dtData['recipes'] as $recipe => $rData): ?>
                                                <div class="recipe-group-title" style="margin-left:0.75rem">
                                                    <span><i class="bi bi-journal-bookmark"></i> <?= htmlspecialchars($recipe) ?></span>
                                                    <span style="font-weight:600;color:#c8913a"><?= number_format($rData['total_dough']/1000, 2, ',', '.') ?> kg</span>
                                                </div>
                                                <?php foreach ($rData['weights'] as $weight => $wdata): ?>
                                                    <div class="product-total-item" style="margin-left:1.5rem;font-weight:600">
                                                        <span><span class="product-total-qty"><?= $wdata['qty'] ?>x</span> <span class="product-total-name"><?= $weight ?>g</span></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($doughTypeTotals) > 1): ?>
                                    <a href="dagproductie.php?date=<?= $currentDate->format('Y-m-d') ?>" class="btn-dagproductie" style="margin-top:0.75rem;opacity:0.7;font-size:0.82rem">
                                        <i class="bi bi-calculator"></i> Alle deegsoorten
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        </div>

                        <!-- Bezorging section (day view inline route) -->
                        <div data-type="bezorging">
                            <?php if (!empty($orders)): ?>
                                <div class="day-section-header bezorging-header"><i class="bi bi-truck"></i> Bezorging (<?= count($orders) ?> stop<?= count($orders) !== 1 ? 's' : '' ?>)</div>
                                <div class="route-summary" id="inlineRouteSummary">
                                    <div class="route-stat"><div class="route-stat-value" id="inlineStopCount"><?= count($orders) ?></div><div class="route-stat-label">Stops</div></div>
                                    <div class="route-stat"><div class="route-stat-value" id="inlineTotalAmount">&euro;0</div><div class="route-stat-label">Totaal</div></div>
                                    <div class="route-stat"><div class="route-stat-value" id="inlineDeliveredCount">0/<?= count($orders) ?></div><div class="route-stat-label">Afgeleverd</div></div>
                                </div>
                                <div class="route-actions" id="inlineRouteActions">
                                    <button class="btn btn-onderweg" id="inlineBtnStartRoute" onclick="startInlineRoute()"><i class="bi bi-truck"></i> Start Route</button>
                                    <label class="email-toggle"><input type="checkbox" id="inlineSendEmails" checked> Stuur emails</label>
                                    <a id="inlineGoogleMapsBtn" href="#" target="_blank" class="btn btn-route" style="margin-left:auto;"><i class="bi bi-map"></i> Google Maps</a>
                                </div>
                                <div class="route-stops" id="inlineRouteStops"></div>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($orders) && empty($dayAppointments)): ?>
                            <div class="empty-state">
                                <p>Geen activiteit vandaag</p>
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

                    <!-- Prep bars row -->
                    <div class="week-bars-container" data-type="bakken">
                        <?php
                        $barColors = ['#ff6b35', '#c8913a', '#4caf50', '#2196f3', '#9c27b0', '#e91e63', '#00bcd4', '#795548'];
                        $barBgColors = ['#fff0e8', '#faf3e8', '#e8f5e9', '#e3f2fd', '#f3e5f5', '#fce4ec', '#e0f7fa', '#efebe9'];
                        $colorIndex = 0;
                        $doughColorMap = [];
                        foreach ($bakdagen as $bakdag):
                            if (!isset($recipeBarsByBakdag[$bakdag])) continue;
                            $bakdagDt = new DateTime($bakdag);
                            $colEnd = (int)$bakdagDt->format('N');
                            foreach ($recipeBarsByBakdag[$bakdag] as $doughName => $rdata):
                                $dayCount = $rdata['method_days_count'];
                                $colStart = max(1, $colEnd - $dayCount + 1);
                                $totalQty = $rdata['total_qty'];
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
                             onclick="openDayModal('<?= $bakdag ?>', '<?= formatDutchDate($bakdagDt) ?>')">
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
                                Geen bakdagen deze week — <a href="#" onclick="openBakdagenModal();return false" style="color:var(--color-bakken)">instellen</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    $current = clone $startDate;
                    for ($i = 0; $i < 7; $i++):
                        $dateKey = $current->format('Y-m-d');
                        $orders = $ordersByDate[$dateKey] ?? [];
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
                                <?php if (count($orders) > 0): ?>
                                    <span class="calendar-count"><?= count($orders) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php foreach ($dayAppts as $appt): ?>
                                <div class="appointment-item" data-type="afspraak" style="background:<?= htmlspecialchars($appt['color']) ?>" onclick="event.stopPropagation();openEditAppointment(<?= htmlspecialchars(json_encode($appt), ENT_QUOTES) ?>)">
                                    <i class="bi bi-calendar-event"></i>
                                    <?php if ($appt['start_time']): ?><span class="appointment-time"><?= substr($appt['start_time'], 0, 5) ?></span><?php endif; ?>
                                    <?= htmlspecialchars($appt['title']) ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="calendar-preview" data-type="bezorging">
                                <?php foreach (array_slice($orders, 0, 3) as $order): ?>
                                    <div class="calendar-preview-item">
                                        <i class="bi bi-geo-alt-fill type-bezorging"></i>
                                        <?= htmlspecialchars($order['bedrijfsnaam']) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($orders) > 3): ?>
                                    <div class="calendar-preview-item" style="color: var(--accent);">+<?= count($orders) - 3 ?> meer</div>
                                <?php endif; ?>
                            </div>
                            <?php if ($isBakdag && count($orders) > 0): ?>
                                <div class="calendar-bakken-badge" data-type="bakken"><i class="bi bi-fire type-bakken"></i> <?= count($orders) ?> bestelling<?= count($orders) !== 1 ? 'en' : '' ?></div>
                            <?php endif; ?>
                            <?php if (!$isBakdag): ?>
                                <span class="add-bakdag-hint"><i class="bi bi-plus-circle"></i> bakdag</span>
                            <?php endif; ?>
                            <?php if (empty($orders) && empty($dayAppts)): ?>
                                <span class="cell-add-hint"><i class="bi bi-plus"></i></span>
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
                            $orders = $ordersByDate[$dateKey] ?? [];
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
                                    <div class="appointment-item" data-type="afspraak" style="background:<?= htmlspecialchars($appt['color']) ?>;font-size:0.65rem;" onclick="event.stopPropagation();openEditAppointment(<?= htmlspecialchars(json_encode($appt), ENT_QUOTES) ?>)">
                                        <i class="bi bi-calendar-event"></i> <?= htmlspecialchars($appt['title']) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($orders) > 0): ?>
                                <div class="calendar-preview" data-type="bezorging">
                                    <?php foreach (array_slice($orders, 0, 2) as $order): ?>
                                        <div class="calendar-preview-item">
                                            <i class="bi bi-geo-alt-fill type-bezorging"></i>
                                            <?= htmlspecialchars($order['bedrijfsnaam']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <?php if (empty($orders) && empty($dayAppts)): ?>
                                    <span class="cell-add-hint"><i class="bi bi-plus"></i></span>
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

    <!-- Day detail modal -->
    <div class="modal-overlay" id="dayModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="bi bi-calendar3"></i> <span id="dayModalDate"></span></h3>
                <div style="display:flex;align-items:center;gap:0.5rem;margin-left:auto;">
                    <button id="dayModalBakdagBtn" style="display:none;padding:0.35rem 0.75rem;border:none;border-radius:7px;font-size:0.82rem;font-weight:600;cursor:pointer;display:none" onclick="toggleDayBakdag()"></button>
                    <div class="modal-add-menu">
                        <button class="modal-add-btn" onclick="toggleAddMenu(event)"><i class="bi bi-plus-lg"></i> Nieuw</button>
                        <div class="modal-add-dropdown" id="addMenuDropdown">
                            <button class="modal-add-option afspraak" onclick="addMenuAction('afspraak')"><i class="bi bi-calendar-event"></i> Afspraak</button>
                            <button class="modal-add-option bakken" onclick="addMenuAction('bakken')"><i class="bi bi-fire"></i> Bestelling</button>
                            <button class="modal-add-option bezorging" onclick="addMenuAction('bezorging')"><i class="bi bi-truck"></i> Bezorging</button>
                        </div>
                    </div>
                    <button class="modal-close" onclick="closeDayModal()">&times;</button>
                </div>
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
            <div class="modal-body" style="padding:1.25rem;">
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
                <div class="bakdagen-modal-section">
                    <h4><i class="bi bi-calendar-x"></i> Sluitingsdagen <span style="font-weight:400;font-size:0.8rem;color:#888;">(blokkeert vaste bakdagen)</span></h4>
                    <div class="extra-bakdagen-list" id="sluitingDagenList">
                        <?php if (empty($sluitingDagen)): ?>
                            <div style="color:#bbb;font-size:0.85rem;">Geen sluitingsdagen</div>
                        <?php endif; ?>
                        <?php foreach ($sluitingDagen as $sluiting): ?>
                            <div class="extra-bakdag-item">
                                <span style="color:#dc3545"><i class="bi bi-x-circle-fill"></i> <?= (new DateTime($sluiting['datum']))->format('d-m-Y') ?><?= $sluiting['notitie'] ? ' — ' . htmlspecialchars($sluiting['notitie']) : '' ?></span>
                                <button class="extra-bakdag-remove" onclick="removeSluitingDag('<?= $sluiting['datum'] ?>')" title="Verwijderen"><i class="bi bi-trash"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="add-extra-bakdag">
                        <input type="date" id="sluitingDate">
                        <input type="text" id="sluitingNotitie" placeholder="Reden (bijv. vakantie)" style="flex:1;">
                        <button onclick="addSluitingDag()" style="background:#dc3545;"><i class="bi bi-plus"></i> Toevoegen</button>
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
            <div class="modal-body" style="padding:1.25rem;">
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

    <!-- FAB with type dropdown -->
    <div class="fab-wrapper" id="fabWrapper">
        <div class="fab-options" id="fabOptions">
            <button class="fab-option fab-option-afspraak" onclick="fabAction('afspraak')">
                <i class="bi bi-calendar-event"></i> Afspraak
            </button>
            <button class="fab-option fab-option-bezorging" onclick="fabAction('bezorging')">
                <i class="bi bi-truck"></i> Bezorging
            </button>
            <button class="fab-option fab-option-bakken" onclick="fabAction('bakken')">
                <i class="bi bi-fire"></i> Bakken
            </button>
        </div>
        <button class="fab" id="fabBtn" onclick="toggleFabMenu(event)" title="Toevoegen">
            <i class="bi bi-plus-lg" id="fabIcon"></i>
        </button>
    </div>

    <!-- New order modal -->
    <div class="modal-overlay" id="newOrderModal">
        <div class="modal new-order-modal">
            <div class="modal-header">
                <h3><i class="bi bi-plus-circle"></i> Nieuwe Bestelling</h3>
                <button class="modal-close" onclick="closeNewOrderModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="loadDataError" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.875rem;color:#991b1b;">
                    <strong><i class="bi bi-exclamation-triangle-fill"></i> Fout bij laden</strong>
                    <div id="loadDataErrorMsg" style="margin-top:0.3rem;"></div>
                    <button onclick="retryLoadData()" style="margin-top:0.5rem;padding:0.3rem 0.75rem;background:#991b1b;color:white;border:none;border-radius:5px;cursor:pointer;font-size:0.8rem;"><i class="bi bi-arrow-clockwise"></i> Opnieuw proberen</button>
                </div>
                <div class="form-group">
                    <label class="internal-toggle">
                        <input type="checkbox" id="newOrderInternal" onchange="onInternalToggle()">
                        <span>Interne bestelling (Civetta)</span>
                    </label>
                </div>
                <div class="form-group" id="customerGroup">
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
                    <label>Bakdag / Leverdatum</label>
                    <input type="date" class="form-control" id="newOrderDate" onchange="checkBakdag()">
                    <div class="bakdag-indicator" id="bakdagIndicator" style="display:none;">
                        <span class="bakdag-ok"><i class="bi bi-check-circle-fill"></i> Dit is een bakdag</span>
                    </div>
                    <div class="bakdag-warning" id="bakdagWarning" style="display:none;">
                        <i class="bi bi-exclamation-triangle-fill"></i> Dit is geen bakdag. Eerstvolgende bakdag: <strong id="nextBakdag" onclick="selectNextBakdag()"></strong>
                        <button id="bakdagAddBtn" onclick="addBakdagFromOrder()" style="display:none;margin-left:auto;padding:0.25rem 0.6rem;background:#ff6b35;color:white;border:none;border-radius:5px;font-size:0.8rem;font-weight:600;cursor:pointer;white-space:nowrap;"><i class="bi bi-plus"></i> Als bakdag instellen</button>
                    </div>
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
                <span>Totaal: <span class="total-amount" id="newOrderTotal">&euro;0,00</span></span>
                <button class="btn-submit-order" id="btnSubmitOrder" onclick="submitNewOrder()">
                    <i class="bi bi-check-lg"></i> Bestelling plaatsen
                </button>
            </div>
        </div>
    </div>

    <?php $detailAccentColor = '#3d6b3d'; $detailAccentColorDark = '#2d4a2d'; include 'order-detail-modal.php'; ?>

    <script>
    // Data from PHP
    let allCustomers = [];
    let allProducts = [];
    let allBakdagen = [];
    let newOrderProductIndex = 0;
    let currentDayOrders = [];
    let currentDayDate = null;

    const currentDate = '<?= $viewDate ?>';
    const currentMode = '<?= $viewMode ?>';
    const defaultFilter = '<?= $defaultFilter ?>';
    const bakkerijAdres = '<?= addslashes($bakkerijAdres ?: "Leersum, Utrecht") ?>';
    const ordersByDate = <?= json_encode($ordersByDate) ?>;
    const appointmentsByDate = <?= json_encode($appointmentsByDate) ?>;
    const bakdagen = <?= json_encode($bakdagen) ?>;
    const extraBakdagen = <?= json_encode($extraDatums) ?>;
    const voorbereidingDagen = <?= $voorbereidingDagen ?>;
    const phpSluitingDagen = <?= json_encode($sluitingDagen) ?>;

    // Filter state
    let activeFilters = { bakken: true, bezorging: true, afspraak: true };
    </script>
    <script src="../../js/bakker-calendar.js?v=2"></script>
    <script>
    // Initialize filters
    (function() {
        const saved = localStorage.getItem('planningFilters');
        if (saved) {
            try { Object.assign(activeFilters, JSON.parse(saved)); } catch(e) {}
        }
        if (defaultFilter) {
            activeFilters = { bakken: false, bezorging: false, afspraak: false };
            if (activeFilters.hasOwnProperty(defaultFilter)) activeFilters[defaultFilter] = true;
            else { activeFilters.bakken = true; activeFilters.bezorging = true; activeFilters.afspraak = true; }
        }
        applyFilters();
        updateFilterCounts();
    })();

    function toggleFilter(type) {
        activeFilters[type] = !activeFilters[type];
        localStorage.setItem('planningFilters', JSON.stringify(activeFilters));
        applyFilters();
    }

    function applyFilters() {
        for (const type of ['bakken', 'bezorging', 'afspraak']) {
            const btn = document.querySelector(`.filter-toggle[data-type="${type}"]`);
            if (btn) btn.classList.toggle('active', activeFilters[type]);
            document.querySelectorAll(`[data-type="${type}"]:not(.filter-toggle)`).forEach(el => {
                el.classList.toggle('filter-hidden', !activeFilters[type]);
            });
        }
    }

    function updateFilterCounts() {
        let bakkenCount = 0, bezorgingCount = 0, afspraakCount = 0;
        for (const date in ordersByDate) { bakkenCount += ordersByDate[date].length; bezorgingCount += ordersByDate[date].length; }
        for (const date in appointmentsByDate) { afspraakCount += appointmentsByDate[date].length; }
        document.getElementById('countBakken').textContent = bakkenCount;
        document.getElementById('countBezorging').textContent = bezorgingCount;
        document.getElementById('countAfspraak').textContent = afspraakCount;
    }

    // Bakdagen settings
    function openBakdagenModal() { document.getElementById('bakdagenModal').classList.add('active'); }
    function closeBakdagenModal() { document.getElementById('bakdagenModal').classList.remove('active'); }

    function saveBakdagenPatroon() {
        const checkboxes = document.querySelectorAll('.bakdagen-checkboxes input[type="checkbox"]:checked');
        const dagen = Array.from(checkboxes).map(cb => parseInt(cb.value));
        fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'save_patroon', dagen: dagen }) })
        .then(r => r.json()).then(data => {
            if (data.success) { window.location.reload(); } else { showToast('Fout bij opslaan: ' + (data.error || 'Onbekende fout'), 'error'); }
        });
    }

    function addExtraBakdagFromModal() {
        const datum = document.getElementById('extraBakdagDate').value;
        const notitie = document.getElementById('extraBakdagNotitie').value;
        if (!datum) { showToast('Kies een datum', 'warning'); return; }
        fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_extra', datum: datum, notitie: notitie }) })
        .then(r => r.json()).then(data => {
            if (data.success) { window.location.reload(); } else { showToast(data.error || 'Fout bij toevoegen', 'error'); }
        });
    }

    function removeExtraBakdag(datum) {
        showConfirm('Extra bakdag verwijderen?').then(function(ok) {
            if (!ok) return;
            fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'remove_extra', datum: datum }) })
            .then(r => r.json()).then(data => {
                if (data.success) { window.location.reload(); } else { showToast(data.error || 'Fout bij verwijderen', 'error'); }
            });
        });
    }

    function addSluitingDag() {
        const datum = document.getElementById('sluitingDate').value;
        const notitie = document.getElementById('sluitingNotitie').value;
        if (!datum) { showToast('Kies een datum', 'warning'); return; }
        fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_sluiting', datum, notitie }) })
        .then(r => r.json()).then(data => {
            if (data.success) { window.location.reload(); } else { showToast(data.error || 'Fout bij toevoegen', 'error'); }
        });
    }

    function removeSluitingDag(datum) {
        showConfirm('Sluitingsdag verwijderen?').then(function(ok) {
            if (!ok) return;
            fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'remove_sluiting', datum }) })
            .then(r => r.json()).then(data => {
                if (data.success) { window.location.reload(); } else { showToast(data.error || 'Fout bij verwijderen', 'error'); }
            });
        });
    }

    function addImpromptuBakdag(date, dateLabel) {
        showConfirm('Bakdag toevoegen op ' + dateLabel + '?').then(function(ok) {
            if (!ok) return;
            fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_extra', datum: date, notitie: 'Impromptu bakdag' }) })
            .then(r => r.json()).then(data => {
                if (data.success) { window.location.reload(); } else { showToast(data.error || 'Fout bij toevoegen', 'error'); }
            });
        });
    }

    if (new URLSearchParams(window.location.search).get('settings') === 'bakdagen') { openBakdagenModal(); }

    function toggleDayBakdag() {
        const date = currentDayDate;
        if (!date) return;
        const isBakdag = bakdagen.includes(date);
        const isExtra = extraBakdagen.includes(date);
        if (!isBakdag) {
            const dateLabel = new Date(date + 'T00:00').toLocaleDateString('nl-NL', { weekday: 'long', day: 'numeric', month: 'long' });
            showConfirm(dateLabel + ' als bakdag instellen?', 'Bakdag toevoegen').then(ok => {
                if (!ok) return;
                fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_extra', datum: date, notitie: '' }) })
                .then(r => r.json()).then(data => {
                    if (data.success) { window.location.reload(); } else { showToast(data.error || 'Fout bij toevoegen', 'error'); }
                });
            });
        } else if (isExtra) {
            showConfirm('Bakdag verwijderen?').then(ok => {
                if (!ok) return;
                fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'remove_extra', datum: date }) })
                .then(r => r.json()).then(data => {
                    if (data.success) { window.location.reload(); } else { showToast(data.error || 'Fout bij verwijderen', 'error'); }
                });
            });
        }
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

    function closeAppointmentModal() { document.getElementById('appointmentModal').classList.remove('active'); }

    function selectApptColor(el) {
        document.querySelectorAll('#apptColorOptions .color-option').forEach(o => o.classList.remove('selected'));
        el.classList.add('selected');
        selectedApptColor = el.dataset.color;
    }

    function selectApptColorByValue(color) {
        selectedApptColor = color;
        document.querySelectorAll('#apptColorOptions .color-option').forEach(o => { o.classList.toggle('selected', o.dataset.color === color); });
    }

    function saveAppointment() {
        const id = document.getElementById('apptId').value;
        const title = document.getElementById('apptTitle').value.trim();
        const date = document.getElementById('apptDate').value;
        const startTime = document.getElementById('apptStartTime').value || null;
        const endTime = document.getElementById('apptEndTime').value || null;
        const description = document.getElementById('apptDescription').value.trim();
        if (!title || !date) { showToast('Vul titel en datum in', 'warning'); return; }
        const payload = { action: id ? 'update' : 'create', title, appointment_date: date, start_time: startTime, end_time: endTime, description, color: selectedApptColor };
        if (id) payload.id = parseInt(id);
        fetch('../../api/appointments.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(r => r.json()).then(data => {
            if (data.success) { window.location.reload(); } else { showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error'); }
        });
    }

    function deleteAppointment() {
        const id = document.getElementById('apptId').value;
        if (!id) return;
        showConfirm('Afspraak verwijderen?').then(function(ok) {
            if (!ok) return;
            fetch('../../api/appointments.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: parseInt(id) }) })
            .then(r => r.json()).then(data => {
                if (data.success) { window.location.reload(); } else { showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error'); }
            });
        });
    }

    function closeAllModals() { document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active')); }

    document.getElementById('appointmentModal').addEventListener('mousedown', function(e) { this._md = e.target === this; });
    document.getElementById('appointmentModal').addEventListener('click', function(e) { if (e.target === this && this._md) closeAppointmentModal(); });

    // Multi-select orders
    let selectedOrderIds = [];

    function toggleOrderSelect(orderId) {
        const idx = selectedOrderIds.indexOf(orderId);
        if (idx >= 0) selectedOrderIds.splice(idx, 1);
        else selectedOrderIds.push(orderId);
        updateBatchBar();
        const row = document.querySelector('.order-row[data-order-id="' + orderId + '"]');
        if (row) row.classList.toggle('selected', selectedOrderIds.includes(orderId));
        const routeStop = document.querySelector('.route-stop[data-order-id="' + orderId + '"]');
        if (routeStop) routeStop.classList.toggle('selected', selectedOrderIds.includes(orderId));
    }

    function updateBatchBar() {
        const bar = document.getElementById('batchBar');
        if (!bar) return;
        bar.classList.toggle('show', selectedOrderIds.length > 0);
        const countEl = document.getElementById('batchCount');
        if (countEl) countEl.textContent = selectedOrderIds.length;
    }

    function deselectAllOrders() {
        selectedOrderIds = [];
        document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = false);
        document.querySelectorAll('.order-row.selected, .route-stop.selected').forEach(el => el.classList.remove('selected'));
        updateBatchBar();
    }

    async function batchCancelOrders() {
        if (selectedOrderIds.length === 0) return;
        const ok = await showConfirm(selectedOrderIds.length + ' bestelling(en) annuleren? Dit kan niet ongedaan worden.');
        if (!ok) return;
        let successCount = 0;
        for (const orderId of selectedOrderIds) {
            try {
                const response = await fetch('../../api/business-orders.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'cancel', order_id: orderId })
                });
                const data = await response.json();
                if (data.success) successCount++;
            } catch (e) { console.error('Error cancelling order ' + orderId, e); }
        }
        showToast(successCount + ' van ' + selectedOrderIds.length + ' bestelling(en) geannuleerd', successCount > 0 ? 'success' : 'error');
        if (successCount > 0) setTimeout(function() { window.location.reload(); }, 1500);
    }

    async function batchMarkDelivered() {
        if (selectedOrderIds.length === 0) return;
        const ok = await showConfirm(selectedOrderIds.length + ' bestelling(en) markeren als afgeleverd?');
        if (!ok) return;
        let successCount = 0;
        for (const orderId of selectedOrderIds) {
            try {
                const response = await fetch('../../api/delivery-route.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'mark_delivered', order_id: orderId })
                });
                const data = await response.json();
                if (data.success) successCount++;
            } catch (e) { console.error('Error marking delivered ' + orderId, e); }
        }
        showToast(successCount + ' van ' + selectedOrderIds.length + ' bestelling(en) afgeleverd', successCount > 0 ? 'success' : 'error');
        if (successCount > 0) {
            deselectAllOrders();
            loadRouteData(currentDayDate);
        }
    }

    // Collapsible sections in day modal
    function toggleSection(headerEl) {
        headerEl.closest('.day-section').classList.toggle('collapsed');
    }

    // FAB dropdown
    function toggleFabMenu(e) {
        e.stopPropagation();
        const opts = document.getElementById('fabOptions');
        const btn = document.getElementById('fabBtn');
        const isOpen = opts.classList.contains('show');
        opts.classList.toggle('show', !isOpen);
        btn.classList.toggle('open', !isOpen);
    }
    document.addEventListener('click', function() {
        document.getElementById('fabOptions')?.classList.remove('show');
        document.getElementById('fabBtn')?.classList.remove('open');
    });
    function fabAction(type) {
        document.getElementById('fabOptions').classList.remove('show');
        document.getElementById('fabBtn').classList.remove('open');
        const today = toLocalDateStr(new Date());
        if (type === 'afspraak') {
            openAppointmentModal(today);
        } else {
            openNewOrderModal(today);
        }
    }

    // Add menu dropdown (used inside day modal)
    function toggleAddMenu(e) {
        e.stopPropagation();
        document.getElementById('addMenuDropdown').classList.toggle('show');
    }
    document.addEventListener('click', function() { document.getElementById('addMenuDropdown')?.classList.remove('show'); });

    function addMenuAction(type) {
        document.getElementById('addMenuDropdown').classList.remove('show');
        const date = currentDayDate;
        if (type === 'afspraak') {
            closeAllModals();
            openAppointmentModal(date);
        } else if (type === 'bakken') {
            // Adding a baking order: ensure day is a bakdag, then open new order modal
            if (!bakdagen.includes(date)) {
                showConfirm('Deze dag is nog geen bakdag. Wil je ' + date + ' als extra bakdag toevoegen?').then(function(ok) {
                    if (!ok) return;
                    fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_extra', datum: date, notitie: 'Automatisch toegevoegd' }) })
                    .then(r => r.json()).then(data => {
                        if (data.success) {
                            bakdagen.push(date);
                            showToast('Bakdag toegevoegd', 'success');
                            closeDayModal();
                            openNewOrderModal(date);
                        } else { showToast(data.error || 'Fout bij toevoegen bakdag', 'error'); }
                    });
                });
            } else {
                closeDayModal();
                openNewOrderModal(date);
            }
        } else if (type === 'bezorging') {
            closeDayModal();
            openNewOrderModal(date);
        }
    }

    // Unified day modal - shows all three sections
    function openDayModal(date, dateLabel, filterDoughType) {
        currentDayDate = date;
        const isBakdagDay = bakdagen.includes(date);
        const badgeHtml = isBakdagDay ? ' <span class="bakdag-badge"><i class="bi bi-fire"></i> Bakdag</span>' : '';
        document.getElementById('dayModalDate').innerHTML = escapeHtml(dateLabel) + badgeHtml;

        // Bakdag toggle button in modal header
        const btn = document.getElementById('dayModalBakdagBtn');
        const isExtra = extraBakdagen.includes(date);
        if (!isBakdagDay) {
            btn.style.display = 'inline-flex';
            btn.style.background = '#fff5f0';
            btn.style.color = '#e55a2b';
            btn.style.border = '1px solid #ffc9a8';
            btn.innerHTML = '<i class="bi bi-fire" style="margin-right:0.3rem"></i> Als bakdag instellen';
        } else if (isExtra) {
            btn.style.display = 'inline-flex';
            btn.style.background = '#fef2f2';
            btn.style.color = '#dc3545';
            btn.style.border = '1px solid #fca5a5';
            btn.innerHTML = '<i class="bi bi-fire" style="margin-right:0.3rem"></i> Bakdag verwijderen';
        } else {
            btn.style.display = 'none';
        }

        let html = '';

        // === AFSPRAAK SECTION ===
        const dayAppts = appointmentsByDate[date] || [];
        const afspraakCollapsed = !activeFilters.afspraak;
        html += '<div class="day-section' + (afspraakCollapsed ? ' collapsed' : '') + '" data-section="afspraak">';
        html += '<div class="day-section-header afspraak-header" onclick="toggleSection(this)"><i class="bi bi-calendar-event"></i> Afspraken (' + dayAppts.length + ') <i class="bi bi-chevron-down collapse-icon"></i></div>';
        html += '<div class="day-section-body">';
        if (dayAppts.length > 0) {
            dayAppts.forEach(appt => {
                const timeStr = appt.start_time ? '<div class="appt-time"><i class="bi bi-clock"></i> ' + appt.start_time.substring(0,5) + (appt.end_time ? ' - ' + appt.end_time.substring(0,5) : '') + '</div>' : '';
                const descStr = appt.description ? '<div class="appt-desc">' + escapeHtml(appt.description) + '</div>' : '';
                html += '<div class="appointment-card" onclick=\'closeAllModals();openEditAppointment(' + JSON.stringify(appt).replace(/'/g, "&#39;") + ')\'>' +
                    '<div class="appt-color" style="background:' + (appt.color || '#3d6b3d') + '"></div>' +
                    '<div class="appt-info"><div class="appt-title">' + escapeHtml(appt.title) + '</div>' + timeStr + descStr + '</div></div>';
            });
        } else {
            html += '<div style="color:#bbb;font-size:0.85rem;padding:0.3rem 0;">Geen afspraken</div>';
        }
        html += '</div></div>';

        // === BAKKEN SECTION ===
        const orders = ordersByDate[date] || [];
        const filteredOrders = filterDoughType ? orders.map(order => ({
            ...order, items: order.items.filter(item => (item.dough_type_name || 'Geen deegsoort') === filterDoughType)
        })).filter(order => order.items.length > 0) : orders;

        const bakkenCollapsed = !activeFilters.bakken;
        html += '<div class="day-section' + (bakkenCollapsed ? ' collapsed' : '') + '" data-section="bakken">';
        html += '<div class="day-section-header bakken-header" onclick="toggleSection(this)"><i class="bi bi-fire"></i> Bakken (' + filteredOrders.length + ' bestelling' + (filteredOrders.length !== 1 ? 'en' : '') + ') <i class="bi bi-chevron-down collapse-icon"></i></div>';
        html += '<div class="day-section-body">';

        if (filteredOrders.length > 0) {
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
                        if (!doughTypeTotals[doughTypeName]) doughTypeTotals[doughTypeName] = { recipes: {}, totalDough: 0, totalQty: 0 };
                        if (!doughTypeTotals[doughTypeName].recipes[recipeName]) doughTypeTotals[doughTypeName].recipes[recipeName] = { weights: {}, totalDough: 0 };
                        if (!doughTypeTotals[doughTypeName].recipes[recipeName].weights[doughWeight]) doughTypeTotals[doughTypeName].recipes[recipeName].weights[doughWeight] = { qty: 0 };
                        const qty = parseInt(item.quantity);
                        doughTypeTotals[doughTypeName].recipes[recipeName].weights[doughWeight].qty += qty;
                        doughTypeTotals[doughTypeName].recipes[recipeName].totalDough += qty * doughWeight;
                        doughTypeTotals[doughTypeName].totalDough += qty * doughWeight;
                        doughTypeTotals[doughTypeName].totalQty += qty;
                    }
                });
            });

            html += '<div class="totals-section"><h4><i class="bi bi-list-check"></i> Totaal te bereiden</h4>';
            html += '<div class="totals-tab-content active" data-tab="recepten"><div class="product-totals-list">';
            for (const doughType of Object.keys(doughTypeTotals).sort()) {
                const dtData = doughTypeTotals[doughType];
                const kgTotal = (dtData.totalDough / 1000).toFixed(2).replace('.', ',');
                const dtLink = 'dagproductie.php?date=' + date + '&dough_type=' + encodeURIComponent(doughType);
                html += '<a href="' + dtLink + '" class="dough-type-header"><span><i class="bi bi-layers"></i> <span class="product-total-qty">' + dtData.totalQty + 'x</span> ' + escapeHtml(doughType) + '</span><span style="font-weight:700;color:#2d4a2d">' + kgTotal + ' kg <i class="bi bi-arrow-right dth-arrow"></i></span></a>';
                for (const recipe of Object.keys(dtData.recipes).sort()) {
                    const rData = dtData.recipes[recipe];
                    const kgRecipe = (rData.totalDough / 1000).toFixed(2).replace('.', ',');
                    html += '<div class="recipe-group-title" style="margin-left:0.75rem"><span><i class="bi bi-journal-bookmark"></i> ' + escapeHtml(recipe) + '</span><span style="font-weight:600;color:#c8913a">' + kgRecipe + ' kg</span></div>';
                    const sortedWeights = Object.keys(rData.weights).sort((a, b) => b - a);
                    for (const weight of sortedWeights) {
                        const wdata = rData.weights[weight];
                        html += '<div class="product-total-item" style="margin-left:1.5rem;font-weight:600"><span><span class="product-total-qty">' + wdata.qty + 'x</span> <span class="product-total-name">' + weight + 'g</span></span></div>';
                    }
                }
            }
            html += '</div>';
            const doughTypeCount = Object.keys(doughTypeTotals).length;
            if (doughTypeCount > 1) {
                html += '<a href="dagproductie.php?date=' + date + '" class="btn-dagproductie" style="margin-top:0.75rem;opacity:0.7;font-size:0.82rem"><i class="bi bi-calculator"></i> Alle deegsoorten</a>';
            }
            html += '</div></div>';

            html += '<div class="orders-section"><h4><i class="bi bi-people"></i> Klanten (' + filteredOrders.length + ')</h4>';
            filteredOrders.forEach(order => {
                const statusClass = order.payment_status === 'paid' ? 'paid' : 'pending';
                const statusText = order.payment_status === 'paid' ? 'Betaald' : 'Open';
                const deliveryLabel = order.delivery_status === 'afgeleverd' ? '<span class="status-badge afgeleverd">Afgeleverd</span>' : '';
                const items = order.items.map(i => i.quantity + 'x ' + i.product_name);
                const itemsSummary = items.slice(0, 3).join(', ') + (items.length > 3 ? '...' : '');
                html += '<div class="order-row" data-order-id="' + order.id + '">' +
                    '<input type="checkbox" class="order-checkbox" data-order-id="' + order.id + '" onclick="event.stopPropagation();toggleOrderSelect(' + order.id + ')">' +
                    '<div class="order-info" onclick=\'showOrderDetail(' + JSON.stringify(order).replace(/'/g, "&#39;") + ')\'><div class="order-company">' + escapeHtml(order.bedrijfsnaam) + '</div>' +
                    '<div class="order-products-summary"><i class="bi bi-box"></i> ' + escapeHtml(itemsSummary) + '</div></div>' +
                    '<div class="order-badges"><span class="status-badge ' + statusClass + '">' + statusText + '</span>' + deliveryLabel + '</div>' +
                    '<span class="order-amount">\u20AC' + parseFloat(order.total_amount).toFixed(2).replace('.', ',') + '</span></div>';
            });
            html += '</div>';
        } else {
            html += '<div style="color:#bbb;font-size:0.85rem;padding:0.3rem 0;">Geen bestellingen</div>';
        }
        html += '</div></div>';

        // === BEZORGING SECTION ===
        const bezorgingCollapsed = !activeFilters.bezorging;
        html += '<div class="day-section' + (bezorgingCollapsed ? ' collapsed' : '') + '" data-section="bezorging">';
        html += '<div class="day-section-header bezorging-header" onclick="toggleSection(this)"><i class="bi bi-truck"></i> Bezorging <i class="bi bi-chevron-down collapse-icon"></i></div>';
        html += '<div class="day-section-body">';
        html += '<div class="success-message" id="successMessage"><i class="bi bi-check-circle-fill"></i><span id="successText"></span></div>';
        html += '<div class="route-summary" id="routeSummary"><div class="route-stat"><div class="route-stat-value" id="stopCount">0</div><div class="route-stat-label">Stops</div></div><div class="route-stat"><div class="route-stat-value" id="totalAmount">\u20AC0</div><div class="route-stat-label">Totaal</div></div><div class="route-stat"><div class="route-stat-value" id="deliveredCount">0/0</div><div class="route-stat-label">Afgeleverd</div></div></div>';
        html += '<div class="route-actions"><button class="btn btn-onderweg" id="btnStartRoute" onclick="startRoute()"><i class="bi bi-truck"></i> Start Route</button><label class="email-toggle"><input type="checkbox" id="sendEmails" checked> Stuur emails naar klanten</label><a id="googleMapsBtn" href="#" target="_blank" class="btn btn-route" style="margin-left:auto;"><i class="bi bi-map"></i> Google Maps</a></div>';
        html += '<div class="route-stops" id="routeStops"></div>';
        html += '</div></div>';

        // Batch action bar
        html += '<div class="batch-bar" id="batchBar">';
        html += '<span class="batch-bar-info"><span id="batchCount">0</span> geselecteerd</span>';
        html += '<div class="batch-bar-actions">';
        html += '<button class="batch-btn batch-btn-deselect" onclick="deselectAllOrders()"><i class="bi bi-x"></i> Deselecteer</button>';
        html += '<button class="batch-btn batch-btn-deliver" onclick="batchMarkDelivered()"><i class="bi bi-check-circle"></i> Afleveren</button>';
        html += '<button class="batch-btn batch-btn-cancel" onclick="batchCancelOrders()"><i class="bi bi-trash"></i> Annuleren</button>';
        html += '</div></div>';

        selectedOrderIds = [];
        document.getElementById('dayModalBody').innerHTML = html;
        document.getElementById('dayModal').classList.add('active');

        // Load route data for bezorging section
        loadRouteData(date);
    }

    // Route/delivery functions (from leveren.php)
    async function loadRouteData(date) {
        try {
            const response = await fetch('../../api/delivery-route.php?date=' + date);
            const data = await response.json();
            if (data.success) {
                currentDayOrders = data.orders;
                renderRoute(data.orders);
                updateSummary(data.orders);
                updateGoogleMapsLink(data.orders);
                updateStartButton(data.orders);
            }
        } catch (error) { console.error('Error loading route:', error); }
    }

    function renderRoute(orders) {
        let html = '<div class="route-point start"><div class="marker"><i class="bi bi-house-fill"></i></div><div class="info"><h4>Startpunt: Bakkerij</h4><p>' + escapeHtml(bakkerijAdres) + '</p></div></div>';
        orders.forEach((order, idx) => {
            const isDelivered = order.delivery_status === 'afgeleverd';
            const isOnRoute = order.delivery_status === 'onderweg';
            const products = order.items.map(i => i.quantity + 'x ' + i.product_name).join(', ');
            html += '<div class="connector"></div>' +
                '<div class="route-stop ' + (isDelivered ? 'delivered' : '') + '" data-order-id="' + order.id + '">' +
                '<div class="marker">' + (idx + 1) + '</div>' +
                '<div class="info"><h4>' + escapeHtml(order.bedrijfsnaam) + '</h4>' +
                '<div class="address"><i class="bi bi-geo-alt"></i> ' + escapeHtml(order.full_delivery_address) + '</div>' +
                '<div class="products"><i class="bi bi-box"></i> ' + escapeHtml(products) + '</div>' +
                '<div class="badges">' +
                (isOnRoute ? '<span class="status-badge onderweg"><i class="bi bi-truck"></i> Onderweg</span>' : '') +
                (isDelivered ? '<span class="status-badge afgeleverd"><i class="bi bi-check"></i> Afgeleverd</span>' : '') +
                '<span class="status-badge ' + order.payment_status + '">' + (order.payment_status === 'paid' ? 'Betaald' : 'Open') + '</span>' +
                '</div></div>' +
                '<div class="actions">' +
                (isDelivered ? '<button class="btn btn-delivered done"><i class="bi bi-check"></i></button>' :
                    '<button class="btn btn-delivered" onclick="markDelivered(' + order.id + ', this)"><i class="bi bi-check"></i></button>') +
                '<a href="https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(order.full_delivery_address) + '" target="_blank" class="btn btn-outline"><i class="bi bi-geo-alt"></i></a>' +
                '<a href="tel:' + (order.telefoon || '') + '" class="btn btn-outline"><i class="bi bi-telephone"></i></a>' +
                '<button class="btn btn-outline" onclick=\'showOrderDetail(' + JSON.stringify(order).replace(/'/g, "&#39;") + ')\'><i class="bi bi-eye"></i></button>' +
                '</div></div>';
        });
        html += '<div class="connector"></div><div class="route-point end"><div class="marker"><i class="bi bi-arrow-return-left"></i></div><div class="info"><h4>Terug naar bakkerij</h4><p>' + escapeHtml(bakkerijAdres) + '</p></div></div>';
        document.getElementById('routeStops').innerHTML = html;
    }

    function updateSummary(orders) {
        document.getElementById('stopCount').textContent = orders.length;
        const total = orders.reduce((sum, o) => sum + parseFloat(o.total_amount), 0);
        document.getElementById('totalAmount').textContent = '\u20AC' + total.toFixed(2).replace('.', ',');
        const delivered = orders.filter(o => o.delivery_status === 'afgeleverd').length;
        document.getElementById('deliveredCount').textContent = delivered + '/' + orders.length;
    }

    function updateGoogleMapsLink(orders) {
        const btn = document.getElementById('googleMapsBtn');
        if (!btn) return;
        if (orders.length === 0) { btn.href = '#'; return; }
        const waypoints = orders.map(o => encodeURIComponent(o.full_delivery_address)).join('/');
        const origin = encodeURIComponent(bakkerijAdres);
        btn.href = 'https://www.google.com/maps/dir/' + origin + '/' + waypoints + '/' + origin;
    }

    function updateStartButton(orders) {
        const btn = document.getElementById('btnStartRoute');
        if (!btn) return;
        const allStarted = orders.every(o => o.delivery_status === 'onderweg' || o.delivery_status === 'afgeleverd');
        if (orders.length === 0) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-truck"></i> Geen leveringen'; }
        else if (allStarted) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-check-circle"></i> Route gestart'; btn.style.background = '#4caf50'; }
        else { btn.disabled = false; btn.innerHTML = '<i class="bi bi-truck"></i> Start Route'; btn.style.background = ''; }
    }

    async function startRoute() {
        const sendEmailsEl = document.getElementById('sendEmails');
        const sendEmails = sendEmailsEl ? sendEmailsEl.checked : false;
        const orderIds = currentDayOrders.filter(o => o.delivery_status !== 'onderweg' && o.delivery_status !== 'afgeleverd').map(o => o.id);
        if (orderIds.length === 0) return;
        const confirmMsg = orderIds.length + ' bestelling(en) op "onderweg" zetten' + (sendEmails ? ' en emails versturen' : '') + '?';
        const ok = await showConfirm(confirmMsg, 'Route starten');
        if (!ok) return;
        const btn = document.getElementById('btnStartRoute');
        btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig...';
        try {
            const response = await fetch('../../api/delivery-route.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'start_route', order_ids: orderIds, send_emails: sendEmails }) });
            const data = await response.json();
            if (data.success) {
                document.getElementById('successText').textContent = data.updated_count + ' bestelling(en) op onderweg gezet' + (sendEmails ? ', ' + data.emails_sent + ' email(s) verstuurd' : '');
                document.getElementById('successMessage').classList.add('show');
                currentDayOrders.forEach(o => { if (orderIds.includes(o.id)) o.delivery_status = 'onderweg'; });
                renderRoute(currentDayOrders); updateSummary(currentDayOrders); updateStartButton(currentDayOrders);
            } else { showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error'); btn.disabled = false; btn.innerHTML = '<i class="bi bi-truck"></i> Start Route'; }
        } catch (error) { console.error('Error:', error); showToast('Er ging iets mis', 'error'); btn.disabled = false; btn.innerHTML = '<i class="bi bi-truck"></i> Start Route'; }
    }

    async function markDelivered(orderId, btn) {
        btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        try {
            const response = await fetch('../../api/delivery-route.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'mark_delivered', order_id: orderId }) });
            const data = await response.json();
            if (data.success) {
                const order = currentDayOrders.find(o => o.id === orderId);
                if (order) order.delivery_status = 'afgeleverd';
                btn.className = 'btn btn-delivered done'; btn.innerHTML = '<i class="bi bi-check"></i>';
                btn.closest('.route-stop').classList.add('delivered');
                updateSummary(currentDayOrders);
            } else { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check"></i>'; }
        } catch (error) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check"></i>'; }
    }

    const _origCloseDayModal = closeDayModal;
    closeDayModal = function() { _origCloseDayModal(); currentDayOrders = []; currentDayDate = null; };

    // New order functions (from leveren.php)
    function toLocalDateStr(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

    function showLoadError(msgs) {
        const box = document.getElementById('loadDataError');
        const msgEl = document.getElementById('loadDataErrorMsg');
        if (box && msgEl) { msgEl.innerHTML = msgs.map(m => escapeHtml(m)).join('<br>'); box.style.display = ''; }
    }
    function hideLoadError() {
        const box = document.getElementById('loadDataError');
        if (box) box.style.display = 'none';
    }
    async function retryLoadData() {
        allCustomers = []; allProducts = []; allBakdagen = [];
        hideLoadError();
        await loadNewOrderData();
        const custSelect = document.getElementById('newOrderCustomer');
        if (custSelect) {
            custSelect.innerHTML = '<option value="">Selecteer een klant...</option>';
            allCustomers.filter(c => !c.is_internal).forEach(c => { custSelect.innerHTML += '<option value="' + c.id + '">' + escapeHtml(c.bedrijfsnaam) + ' (' + escapeHtml(c.contactpersoon) + ')</option>'; });
        }
        checkBakdag();
    }

    async function loadNewOrderData() {
        if (allCustomers.length && allProducts.length) return;
        const errors = [];
        try {
            const [custRes, prodRes] = await Promise.all([
                fetch('../../api/admin-orders.php?action=customers'),
                fetch('../../api/admin-orders.php?action=products')
            ]);
            let custData, prodData;
            try { custData = await custRes.json(); } catch(e) { errors.push('Klanten: ongeldige response (HTTP ' + custRes.status + ')'); }
            try { prodData = await prodRes.json(); } catch(e) { errors.push('Producten: ongeldige response (HTTP ' + prodRes.status + ')'); }
            if (custData) { if (custData.success) allCustomers = custData.customers; else errors.push('Klanten: ' + (custData.error || 'onbekende fout')); }
            if (prodData) { if (prodData.success) allProducts = prodData.products; else errors.push('Producten: ' + (prodData.error || 'onbekende fout')); }
        } catch (e) {
            errors.push('Netwerk: ' + e.message);
            console.error('Error loading order data:', e);
        }
        try { await loadBakdagen(); } catch(e) { errors.push('Bakdagen: ' + e.message); }
        if (errors.length) showLoadError(errors); else hideLoadError();
    }

    async function loadBakdagen() {
        const today = new Date();
        const start = toLocalDateStr(today);
        const end = toLocalDateStr(new Date(today.getFullYear(), today.getMonth() + 3, today.getDate()));
        const response = await fetch('../../api/bakdagen.php?start=' + start + '&end=' + end);
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const data = await response.json();
        if (data.success) allBakdagen = data.bakdagen || [];
        else throw new Error(data.error || 'onbekende fout');
    }

    function getAvailableBakdagen() {
        if (document.getElementById('newOrderInternal').checked) return 999;
        const dateStr = document.getElementById('newOrderDate').value;
        if (!dateStr) return 999;
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const target = new Date(dateStr + 'T00:00');
        let count = 0; const d = new Date(today);
        while (d <= target) { if (allBakdagen.includes(toLocalDateStr(d))) count++; d.setDate(d.getDate() + 1); }
        return count;
    }

    function getEarliestDeliveryDate(recipeDays) {
        if (!recipeDays || recipeDays <= 0) recipeDays = 1;
        const today = new Date(); today.setHours(0, 0, 0, 0);
        let count = 0; const d = new Date(today); let iterations = 0;
        while (count < recipeDays && iterations < 365) { if (allBakdagen.includes(toLocalDateStr(d))) count++; if (count < recipeDays) d.setDate(d.getDate() + 1); iterations++; }
        return toLocalDateStr(d);
    }

    function formatDateNL(dateStr) { return new Date(dateStr + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'short', day: 'numeric', month: 'short'}); }
    function isProductAvailable(recipeDays) { return getAvailableBakdagen() >= (recipeDays || 1); }

    function buildProductOptions() {
        const available = getAvailableBakdagen();
        let html = '<option value="">Kies product...</option>';
        allProducts.forEach(p => {
            const days = p.recipe_days || 1;
            if (days <= available) { html += '<option value="' + p.id + '">' + escapeHtml(p.naam) + '</option>'; }
            else { const earliest = getEarliestDeliveryDate(days); html += '<option value="' + p.id + '" disabled style="color:#999;">' + escapeHtml(p.naam) + ' \u2014 pas vanaf ' + formatDateNL(earliest) + ' (Bakproces: ' + days + ' dagen)</option>'; }
        });
        return html;
    }

    function refreshProductOptions() {
        const options = buildProductOptions();
        document.querySelectorAll('#newOrderProducts .product-select-row').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            if (!productSelect) return;
            const currentVal = productSelect.value;
            productSelect.innerHTML = options;
            if (currentVal) {
                const product = allProducts.find(p => p.id == currentVal);
                if (product && isProductAvailable(product.recipe_days)) { productSelect.value = currentVal; onProductSelect(productSelect); }
                else { productSelect.value = ''; const vs = row.querySelector('.variant-select'); if (vs) { vs.style.display = 'none'; vs.innerHTML = ''; } const pe = row.querySelector('.product-price'); if (pe) pe.textContent = '\u20AC0,00'; }
            }
        });
        updateNewOrderTotal();
    }

    function findNextPossibleBakdag(afterDate) {
        const today = new Date(); today.setHours(0, 0, 0, 0);
        return allBakdagen.find(d => {
            if (d <= afterDate) return false;
            // Count bakdagen between today and this date
            let count = 0;
            const check = new Date(today);
            const target = new Date(d + 'T00:00');
            while (check <= target) { if (allBakdagen.includes(toLocalDateStr(check))) count++; check.setDate(check.getDate() + 1); }
            return count >= voorbereidingDagen;
        });
    }

    function checkBakdag() {
        const isInternal = document.getElementById('newOrderInternal').checked;
        const date = document.getElementById('newOrderDate').value;
        const indicator = document.getElementById('bakdagIndicator');
        const warning = document.getElementById('bakdagWarning');
        if (!date) { indicator.style.display = 'none'; warning.style.display = 'none'; return; }
        const isBakdagDate = allBakdagen.includes(date);
        const hasEnoughLeadTime = getAvailableBakdagen() >= voorbereidingDagen;
        if (isBakdagDate && hasEnoughLeadTime) { indicator.style.display = ''; warning.style.display = 'none'; }
        else {
            indicator.style.display = 'none'; warning.style.display = '';
            const next = findNextPossibleBakdag(date);
            const reason = isBakdagDate ? ' (te weinig voorbereidingstijd)' : '';
            document.getElementById('nextBakdag').textContent = next ? new Date(next + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'long', day: 'numeric', month: 'long'}) + reason : 'onbekend';
            const addBtn = document.getElementById('bakdagAddBtn');
            if (addBtn) addBtn.style.display = isInternal ? '' : 'none';
        }
        refreshProductOptions();
    }

    function addBakdagFromOrder() {
        const date = document.getElementById('newOrderDate').value;
        if (!date) return;
        const dateLabel = new Date(date + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'long', day: 'numeric', month: 'long'});
        if (!confirm(`${dateLabel} als extra bakdag instellen?`)) return;
        fetch('/api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_extra', datum: date, notitie: 'Interne bestelling' }) })
        .then(r => r.json()).then(data => {
            if (data.success) { allBakdagen.push(date); showToast('Bakdag toegevoegd', 'success'); checkBakdag(); }
            else { showToast(data.error || 'Fout bij toevoegen bakdag', 'error'); }
        });
    }

    function selectNextBakdag() {
        const date = document.getElementById('newOrderDate').value;
        const next = findNextPossibleBakdag(date);
        if (next) { document.getElementById('newOrderDate').value = next; checkBakdag(); }
    }

    function onInternalToggle() {
        const isInternal = document.getElementById('newOrderInternal').checked;
        const customerGroup = document.getElementById('customerGroup');
        const customerCard = document.getElementById('customerInfoCard');
        if (isInternal) { customerGroup.style.display = 'none'; customerCard.classList.remove('show'); }
        else { customerGroup.style.display = ''; }
        if (document.getElementById('newOrderDate').value) checkBakdag();
        refreshProductOptions();
    }

    function getInternalAccountId() {
        const internal = allCustomers.find(c => c.is_internal == 1);
        return internal ? internal.id : null;
    }

    async function openNewOrderModal(prefillDate) {
        await loadNewOrderData();
        document.getElementById('newOrderInternal').checked = false;
        onInternalToggle();
        const custSelect = document.getElementById('newOrderCustomer');
        custSelect.innerHTML = '<option value="">Selecteer een klant...</option>';
        allCustomers.filter(c => !c.is_internal).forEach(c => { custSelect.innerHTML += '<option value="' + c.id + '">' + escapeHtml(c.bedrijfsnaam) + ' (' + escapeHtml(c.contactpersoon) + ')</option>'; });
        document.getElementById('newOrderDate').value = prefillDate || toLocalDateStr(new Date());
        document.getElementById('newOrderNotes').value = '';
        document.getElementById('newOrderProducts').innerHTML = '';
        newOrderProductIndex = 0;
        addProductRow();
        updateNewOrderTotal();
        checkBakdag();
        document.getElementById('newOrderModal').classList.add('active');
    }

    function closeNewOrderModal() { document.getElementById('newOrderModal').classList.remove('active'); document.getElementById('customerInfoCard').classList.remove('show'); }

    function onCustomerChange() {
        const select = document.getElementById('newOrderCustomer');
        const card = document.getElementById('customerInfoCard');
        const customerId = parseInt(select.value);
        if (!customerId) { card.classList.remove('show'); return; }
        const customer = allCustomers.find(c => c.id == customerId);
        if (!customer) { card.classList.remove('show'); return; }
        document.getElementById('ciContact').textContent = customer.contactpersoon || '-';
        const phoneEl = document.getElementById('ciPhone');
        if (customer.telefoon) { phoneEl.innerHTML = '<a href="tel:' + escapeHtml(customer.telefoon) + '">' + escapeHtml(customer.telefoon) + '</a>'; } else { phoneEl.textContent = '-'; }
        const emailEl = document.getElementById('ciEmail');
        if (customer.email) { emailEl.innerHTML = '<a href="mailto:' + escapeHtml(customer.email) + '">' + escapeHtml(customer.email) + '</a>'; } else { emailEl.textContent = '-'; }
        let address;
        if (customer.delivery_same_as_business || !customer.delivery_adres) { address = [customer.adres, customer.postcode, customer.plaats].filter(Boolean).join(', '); }
        else { address = [customer.delivery_adres, customer.delivery_postcode, customer.delivery_plaats].filter(Boolean).join(', '); }
        document.getElementById('ciAddress').textContent = address || '-';
        card.classList.add('show');
    }

    function addProductRow() {
        const container = document.getElementById('newOrderProducts');
        const idx = newOrderProductIndex++;
        const row = document.createElement('div');
        row.className = 'product-select-row';
        row.innerHTML = '<select class="form-control product-select" data-idx="' + idx + '" onchange="onProductSelect(this)">' + buildProductOptions() + '</select>' +
            '<select class="form-control variant-select" data-idx="' + idx + '" onchange="onVariantSelect(this)" style="display:none;"></select>' +
            '<input type="number" class="form-control product-qty" data-idx="' + idx + '" min="1" value="1" onchange="updateNewOrderTotal()" oninput="updateNewOrderTotal()">' +
            '<span class="product-price" data-idx="' + idx + '">\u20AC0,00</span>' +
            '<button type="button" class="btn-remove" onclick="removeProductRow(this)"><i class="bi bi-x"></i></button>';
        container.appendChild(row);
    }

    function removeProductRow(btn) { btn.closest('.product-select-row').remove(); updateNewOrderTotal(); }

    function onProductSelect(select) {
        const idx = select.dataset.idx;
        const productId = parseInt(select.value);
        const variantSelect = document.querySelector('.variant-select[data-idx="' + idx + '"]');
        const priceEl = document.querySelector('.product-price[data-idx="' + idx + '"]');
        if (!productId) { variantSelect.style.display = 'none'; variantSelect.innerHTML = ''; priceEl.textContent = '\u20AC0,00'; updateNewOrderTotal(); return; }
        const product = allProducts.find(p => p.id == productId);
        if (product.variants && product.variants.length > 0) {
            const isInternal = document.getElementById('newOrderInternal').checked;
            const available = getAvailableBakdagen();
            let variantOptions = '<option value="">Kies variant...</option>';
            let firstAvailableVariant = null;
            product.variants.forEach(v => {
                const label = v.gewicht + 'g' + (v.naam ? ' - ' + v.naam : '');
                const days = v.recipe_days || 1;
                const canMake = isInternal || days <= available;
                if (canMake) {
                    if (!firstAvailableVariant) firstAvailableVariant = v;
                    variantOptions += '<option value="' + v.id + '" data-price="' + v.prijs + '" data-weight="' + v.gewicht + '" data-naam="' + escapeHtml(v.naam || '') + '">' + escapeHtml(label) + ' (€' + parseFloat(v.prijs).toFixed(2).replace('.', ',') + ')</option>';
                } else {
                    const earliest = getEarliestDeliveryDate(days);
                    variantOptions += '<option value="' + v.id + '" disabled style="color:#999;">' + escapeHtml(label) + ' — pas vanaf ' + formatDateNL(earliest) + '</option>';
                }
            });
            variantSelect.innerHTML = variantOptions; variantSelect.style.display = '';
            if (isInternal && firstAvailableVariant) {
                variantSelect.value = firstAvailableVariant.id;
                priceEl.textContent = '\u20AC' + parseFloat(firstAvailableVariant.prijs).toFixed(2).replace('.', ',');
            } else { priceEl.textContent = '\u20AC0,00'; }
        } else { variantSelect.style.display = 'none'; variantSelect.innerHTML = ''; priceEl.textContent = '\u20AC' + parseFloat(product.prijs).toFixed(2).replace('.', ','); }
        updateNewOrderTotal();
    }

    function onVariantSelect(select) {
        const idx = select.dataset.idx;
        const option = select.options[select.selectedIndex];
        const price = parseFloat(option?.dataset?.price || 0);
        document.querySelector('.product-price[data-idx="' + idx + '"]').textContent = '\u20AC' + price.toFixed(2).replace('.', ',');
        updateNewOrderTotal();
    }

    function updateNewOrderTotal() {
        let total = 0;
        document.querySelectorAll('.product-select-row').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            const variantSelect = row.querySelector('.variant-select');
            const qty = parseInt(row.querySelector('.product-qty').value) || 0;
            let price = 0;
            const productId = parseInt(productSelect.value);
            if (productId) {
                const product = allProducts.find(p => p.id == productId);
                if (product && product.variants && product.variants.length > 0 && variantSelect.value) { const option = variantSelect.options[variantSelect.selectedIndex]; price = parseFloat(option?.dataset?.price || 0); }
                else if (product && (!product.variants || product.variants.length === 0)) { price = parseFloat(product.prijs || 0); }
            }
            total += qty * price;
        });
        document.getElementById('newOrderTotal').textContent = '\u20AC' + total.toFixed(2).replace('.', ',');
    }

    async function submitNewOrder() {
        const isInternal = document.getElementById('newOrderInternal').checked;
        const accountId = isInternal ? getInternalAccountId() : document.getElementById('newOrderCustomer').value;
        const deliveryDate = document.getElementById('newOrderDate').value;
        const notes = document.getElementById('newOrderNotes').value.trim();
        if (!isInternal && !accountId) { showToast('Selecteer een klant', 'warning'); return; }
        if (isInternal && !accountId) { showToast('Intern account niet gevonden. Voer eerst migration 028 uit.', 'error'); return; }
        if (!deliveryDate) { showToast('Selecteer een leverdatum', 'warning'); return; }

        if (isInternal) {
            let missingVariant = null;
            document.querySelectorAll('.product-select-row').forEach(row => {
                if (missingVariant) return;
                const productId = parseInt(row.querySelector('.product-select')?.value);
                if (!productId) return;
                const product = allProducts.find(p => p.id == productId);
                const variantSelect = row.querySelector('.variant-select');
                const qty = parseInt(row.querySelector('.product-qty').value) || 0;
                if (qty > 0 && product && product.variants && product.variants.length > 0 && (!variantSelect || !variantSelect.value)) {
                    missingVariant = product.naam;
                }
            });
            if (missingVariant) { showToast('Kies een variant voor ' + missingVariant, 'error'); return; }
        }

        const items = [];
        document.querySelectorAll('.product-select-row').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            const variantSelect = row.querySelector('.variant-select');
            const qty = parseInt(row.querySelector('.product-qty').value) || 0;
            const productId = parseInt(productSelect.value);
            if (!productId || qty <= 0) return;
            const product = allProducts.find(p => p.id == productId);
            if (!product) return;
            let productName = product.naam; let price = parseFloat(product.prijs || 0);
            if (product.variants && product.variants.length > 0 && variantSelect.value) {
                const variantOption = variantSelect.options[variantSelect.selectedIndex];
                const weight = variantOption.dataset.weight; price = parseFloat(variantOption.dataset.price || 0);
                const variantNaam = variantOption.dataset.naam;
                productName = variantNaam ? product.naam + ' - ' + variantNaam + ' (' + weight + 'g)' : product.naam + ' (' + weight + 'g)';
            }
            items.push({ product_name: productName, quantity: qty, unit_price: price, variant_id: variantSelect && variantSelect.value ? parseInt(variantSelect.value) || null : null, product_id: productId || null });
        });
        if (items.length === 0) { showToast('Voeg minimaal \u00e9\u00e9n product toe', 'warning'); return; }
        const payload = { account_id: parseInt(accountId), delivery_date: deliveryDate, items, notes };
        if (isInternal) payload.is_internal = true;
        const btn = document.getElementById('btnSubmitOrder');
        btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig...';
        try {
            const response = await fetch('../../api/admin-orders.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const data = await response.json();
            if (data.success) { closeNewOrderModal(); showToast(data.message, 'success'); setTimeout(function() { window.location.reload(); }, 1500); }
            else { showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error'); }
        } catch (e) { console.error('Error:', e); showToast('Er ging iets mis bij het plaatsen van de bestelling', 'error'); }
        finally { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i> Bestelling plaatsen'; }
    }

    document.getElementById('newOrderModal').addEventListener('mousedown', function(e) { this._md = e.target === this; });
    document.getElementById('newOrderModal').addEventListener('click', function(e) { if (e.target === this && this._md) closeNewOrderModal(); });

    // Inline route functions for day view
    let inlineRouteOrders = [];

    async function loadInlineRouteData(date) {
        try {
            const response = await fetch('../../api/delivery-route.php?date=' + date);
            const data = await response.json();
            if (data.success) {
                inlineRouteOrders = data.orders;
                renderInlineRoute(data.orders);
                updateInlineSummary(data.orders);
                updateInlineGoogleMapsLink(data.orders);
                updateInlineStartButton(data.orders);
            }
        } catch (error) { console.error('Error loading inline route:', error); }
    }

    function renderInlineRoute(orders) {
        const container = document.getElementById('inlineRouteStops');
        if (!container) return;
        let html = '<div class="route-point start"><div class="marker"><i class="bi bi-house-fill"></i></div><div class="info"><h4>Startpunt: Bakkerij</h4><p>' + escapeHtml(bakkerijAdres) + '</p></div></div>';
        orders.forEach((order, idx) => {
            const isDelivered = order.delivery_status === 'afgeleverd';
            const isOnRoute = order.delivery_status === 'onderweg';
            const products = order.items.map(i => i.quantity + 'x ' + i.product_name).join(', ');
            html += '<div class="connector"></div>' +
                '<div class="route-stop ' + (isDelivered ? 'delivered' : '') + '" data-order-id="' + order.id + '">' +
                '<div class="marker">' + (idx + 1) + '</div>' +
                '<div class="info"><h4>' + escapeHtml(order.bedrijfsnaam) + '</h4>' +
                '<div class="address"><i class="bi bi-geo-alt"></i> ' + escapeHtml(order.full_delivery_address) + '</div>' +
                '<div class="products"><i class="bi bi-box"></i> ' + escapeHtml(products) + '</div>' +
                '<div class="badges">' +
                (isOnRoute ? '<span class="status-badge onderweg"><i class="bi bi-truck"></i> Onderweg</span>' : '') +
                (isDelivered ? '<span class="status-badge afgeleverd"><i class="bi bi-check"></i> Afgeleverd</span>' : '') +
                '<span class="status-badge ' + order.payment_status + '">' + (order.payment_status === 'paid' ? 'Betaald' : 'Open') + '</span>' +
                '</div></div>' +
                '<div class="actions">' +
                (isDelivered ? '<button class="btn btn-delivered done"><i class="bi bi-check"></i></button>' :
                    '<button class="btn btn-delivered" onclick="markInlineDelivered(' + order.id + ', this)"><i class="bi bi-check"></i></button>') +
                '<a href="https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(order.full_delivery_address) + '" target="_blank" class="btn btn-outline"><i class="bi bi-geo-alt"></i></a>' +
                '<a href="tel:' + (order.telefoon || '') + '" class="btn btn-outline"><i class="bi bi-telephone"></i></a>' +
                '<button class="btn btn-outline" onclick=\'showOrderDetail(' + JSON.stringify(order).replace(/'/g, "&#39;") + ')\'><i class="bi bi-eye"></i></button>' +
                '</div></div>';
        });
        html += '<div class="connector"></div><div class="route-point end"><div class="marker"><i class="bi bi-arrow-return-left"></i></div><div class="info"><h4>Terug naar bakkerij</h4><p>' + escapeHtml(bakkerijAdres) + '</p></div></div>';
        container.innerHTML = html;
    }

    function updateInlineSummary(orders) {
        const el = (id) => document.getElementById(id);
        if (!el('inlineStopCount')) return;
        el('inlineStopCount').textContent = orders.length;
        const total = orders.reduce((sum, o) => sum + parseFloat(o.total_amount), 0);
        el('inlineTotalAmount').textContent = '\u20AC' + total.toFixed(2).replace('.', ',');
        const delivered = orders.filter(o => o.delivery_status === 'afgeleverd').length;
        el('inlineDeliveredCount').textContent = delivered + '/' + orders.length;
    }

    function updateInlineGoogleMapsLink(orders) {
        const btn = document.getElementById('inlineGoogleMapsBtn');
        if (!btn || orders.length === 0) return;
        const waypoints = orders.map(o => encodeURIComponent(o.full_delivery_address)).join('/');
        const origin = encodeURIComponent(bakkerijAdres);
        btn.href = 'https://www.google.com/maps/dir/' + origin + '/' + waypoints + '/' + origin;
    }

    function updateInlineStartButton(orders) {
        const btn = document.getElementById('inlineBtnStartRoute');
        if (!btn) return;
        const allOnRoute = orders.length > 0 && orders.every(o => o.delivery_status === 'onderweg' || o.delivery_status === 'afgeleverd');
        if (allOnRoute) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-check-circle"></i> Route gestart'; }
    }

    async function startInlineRoute() {
        const orderIds = inlineRouteOrders.filter(o => o.delivery_status !== 'onderweg' && o.delivery_status !== 'afgeleverd').map(o => o.id);
        if (orderIds.length === 0) { showToast('Alle bestellingen zijn al onderweg of afgeleverd', 'info'); return; }
        const sendEmails = document.getElementById('inlineSendEmails')?.checked ?? true;
        try {
            const response = await fetch('../../api/delivery-route.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'start_route', order_ids: orderIds, send_emails: sendEmails }) });
            const data = await response.json();
            if (data.success) { showToast('Route gestart! ' + orderIds.length + ' bestelling(en) onderweg.', 'success'); loadInlineRouteData(currentDate); }
            else { showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error'); }
        } catch (e) { showToast('Fout bij starten route', 'error'); }
    }

    async function markInlineDelivered(orderId, btn) {
        try {
            const response = await fetch('../../api/delivery-route.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'mark_delivered', order_id: orderId }) });
            const data = await response.json();
            if (data.success) { showToast('Bestelling afgeleverd!', 'success'); loadInlineRouteData(currentDate); }
            else { showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error'); }
        } catch (e) { showToast('Fout bij markeren als afgeleverd', 'error'); }
    }

    <?php if ($viewMode === 'day' && !empty($ordersByDate[$currentDate->format('Y-m-d')])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        loadInlineRouteData('<?= $currentDate->format('Y-m-d') ?>');
    });
    <?php endif; ?>
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
</div><!-- /.admin-main -->
</div><!-- /.admin-layout -->
</body>
</html>
