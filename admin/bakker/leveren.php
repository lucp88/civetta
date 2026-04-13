<?php
// Redirect to unified planning page
$params = $_GET;
$params['filter'] = 'bezorging';
header('Location: planning.php?' . http_build_query($params));
exit;

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

// Load bakdagen configuration
$bakdagenPatroonStr = '';
$stmtBp = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_patroon'");
$stmtBp->execute();
$bakdagenPatroonStr = $stmtBp->fetchColumn() ?: '';
$bakdagenPatroon = $bakdagenPatroonStr ? array_map('intval', explode(',', $bakdagenPatroonStr)) : [];

$stmtExtra = $pdo->prepare("SELECT datum FROM bakdagen_extra WHERE datum BETWEEN ? AND ? ORDER BY datum");
$stmtExtra->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$extraDatums = array_column($stmtExtra->fetchAll(), 'datum');

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
    <link rel="stylesheet" href="/css/bootstrap-icons.min.css">
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

        /* Internal order toggle */
        .internal-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            font-weight: 500;
            padding: 0.6rem 0.8rem;
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .internal-toggle:has(input:checked) {
            background: #fff3e0;
            border-color: #ff9800;
        }
        .internal-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #3d6b3d;
        }

        /* Variant dropdown in product row */
        .product-select-row .variant-select { flex: 2; }

        /* Bakdag indicator for date picker */
        .bakdag-indicator { margin-top: 0.4rem; font-size: 0.85rem; }
        .bakdag-ok { color: #2e7d32; display: flex; align-items: center; gap: 0.3rem; }
        .bakdag-warning {
            margin-top: 0.4rem;
            font-size: 0.85rem;
            color: #e65100;
            background: #fff3e0;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .bakdag-warning strong { cursor: pointer; text-decoration: underline; }

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
        .appt-form .form-control:focus { outline: none; border-color: var(--accent); }
        .appt-form .form-row { display: flex; gap: 0.5rem; }
        .appt-form .form-row .form-group { flex: 1; }
        .appt-form .color-options { display: flex; gap: 0.4rem; flex-wrap: wrap; }
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
            background: linear-gradient(135deg, var(--accent), var(--accent-dark, #1565c0));
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .btn-save-appt:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
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
        .btn-add-appt:hover { background: var(--accent-dark, #1565c0); }

        /* Bakdag indicators */
        .bakdag-badge-leveren {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            background: linear-gradient(135deg, #ff6b35, #e55a2b);
            color: white;
            font-size: 0.65rem;
            font-weight: 600;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            margin-left: 0.3rem;
        }
        .calendar-cell.bakdag-cell {
            border-top: 3px solid #ff6b35;
        }
        .calendar-cell.bakdag-cell.today {
            border: 2px solid var(--accent);
            border-top: 3px solid #ff6b35;
        }

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
                $dayAppointments = $appointmentsByDate[$dateKey] ?? [];
                $isToday = $dateKey === date('Y-m-d');
                $isBakdag = in_array($dateKey, $bakdagen);
                ?>
                <div class="calendar-grid day-view">
                    <div class="calendar-cell day-view-cell <?= $isToday ? 'today' : '' ?> <?= $isBakdag ? 'bakdag-cell' : '' ?>" style="cursor: default;">
                        <div class="calendar-date">
                            <span>
                                <?= formatDutchDate($currentDate) ?>
                                <?php if ($isBakdag): ?>
                                    <span class="bakdag-badge-leveren"><i class="bi bi-fire"></i> Bakdag</span>
                                <?php endif; ?>
                            </span>
                            <span class="calendar-count <?= count($orders) === 0 ? 'empty' : '' ?>">
                                <?= count($orders) ?> stop<?= count($orders) !== 1 ? 's' : '' ?>
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
                                <p>Geen leveringen vandaag</p>
                            </div>
                            <?php endif; ?>
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
                        $dayAppts = $appointmentsByDate[$dateKey] ?? [];
                        $isToday = $dateKey === date('Y-m-d');
                        $isBakdag = in_array($dateKey, $bakdagen);
                    ?>
                        <div class="calendar-cell <?= $isToday ? 'today' : '' ?> <?= $isBakdag ? 'bakdag-cell' : '' ?>"
                             onclick="openDayModal('<?= $dateKey ?>', '<?= formatDutchDate($current) ?>')">
                            <div class="calendar-date">
                                <span>
                                    <?= $current->format('j') ?>
                                    <?php if ($isBakdag): ?>
                                        <span class="bakdag-badge-leveren" style="font-size:0.55rem;padding:0.1rem 0.25rem;"><i class="bi bi-fire"></i></span>
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
                            $dayAppts = $appointmentsByDate[$dateKey] ?? [];
                            $isToday = $dateKey === date('Y-m-d');
                            $isOtherMonth = $date->format('m') !== $currentMonth;
                            $isBakdag = in_array($dateKey, $bakdagen);
                    ?>
                        <div class="calendar-cell <?= $isToday ? 'today' : '' ?> <?= $isOtherMonth ? 'other-month' : '' ?> <?= $isBakdag && !$isOtherMonth ? 'bakdag-cell' : '' ?>"
                             onclick="openDayModal('<?= $dateKey ?>', '<?= formatDutchDate($date) ?>')">
                            <div class="calendar-date">
                                <span>
                                    <?= $date->format('j') ?>
                                    <?php if ($isBakdag && !$isOtherMonth): ?>
                                        <span class="bakdag-badge-leveren" style="font-size:0.5rem;padding:0.08rem 0.2rem;"><i class="bi bi-fire"></i></span>
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
                                        <div class="calendar-preview-item">
                                            <i class="bi bi-geo-alt-fill"></i>
                                            <?= htmlspecialchars($order['bedrijfsnaam']) ?>
                                        </div>
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
                <span>Totaal: <span class="total-amount" id="newOrderTotal">€0,00</span></span>
                <button class="btn-submit-order" id="btnSubmitOrder" onclick="submitNewOrder()">
                    <i class="bi bi-check-lg"></i> Bestelling plaatsen
                </button>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="bakdagConfirmModal">
        <div class="modal" style="max-width:420px">
            <div class="modal-header">
                <h3><i class="bi bi-exclamation-triangle"></i> Geen bakdag</h3>
                <button class="modal-close" onclick="closeBakdagConfirm()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="bakdagConfirmText" style="margin:0"></p>
            </div>
            <div style="display:flex;gap:.75rem;justify-content:flex-end;padding:1rem 1.5rem;border-top:1px solid #eee">
                <button class="btn btn-outline" onclick="closeBakdagConfirm()">Annuleren</button>
                <button class="btn btn-route" onclick="confirmBakdagOverride()">Toch plaatsen</button>
            </div>
        </div>
    </div>

    <?php $detailAccentColor = '#1976d2'; $detailAccentColorDark = '#1565c0'; include 'order-detail-modal.php'; ?>

    <script>
    let allCustomers = [];
    let allProducts = [];
    let allBakdagen = [];
    let newOrderProductIndex = 0;
    
    const currentDate = '<?= $viewDate ?>';
    const currentMode = '<?= $viewMode ?>';
    const bakkerijAdres = '<?= addslashes($bakkerijAdres ?: 'Leersum, Utrecht') ?>';
    
    let currentDayOrders = [];
    let currentDayDate = null;
    const appointmentsByDate = <?= json_encode($appointmentsByDate) ?>;
    </script>
    <script src="../../js/bakker-calendar.js?v=1"></script>
    <script>
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

    function closeAllModals() {
        document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
    }

    document.getElementById('appointmentModal').addEventListener('click', function(e) {
        if (e.target === this) closeAppointmentModal();
    });

    async function openDayModal(date, dateLabel) {
        currentDayDate = date;
        document.getElementById('dayModalDate').textContent = dateLabel;
        document.getElementById('successMessage').classList.remove('show');

        // Render appointments in day modal
        const dayAppts = appointmentsByDate[date] || [];
        let apptHtml = `<div class="appointments-section">`;
        apptHtml += `<h4><i class="bi bi-calendar-event"></i> Afspraken (${dayAppts.length}) <button class="btn-add-appt" onclick="closeAllModals();openAppointmentModal('${date}')" style="margin-left:auto"><i class="bi bi-plus"></i> Nieuw</button></h4>`;
        if (dayAppts.length > 0) {
            dayAppts.forEach(appt => {
                const timeStr = appt.start_time ? `<div class="appt-time"><i class="bi bi-clock"></i> ${appt.start_time.substring(0,5)}${appt.end_time ? ' - ' + appt.end_time.substring(0,5) : ''}</div>` : '';
                const descStr = appt.description ? `<div class="appt-desc">${escapeHtml(appt.description)}</div>` : '';
                apptHtml += `<div class="appointment-card" onclick='closeAllModals();openEditAppointment(${JSON.stringify(appt).replace(/'/g, "&#39;")})'>
                    <div class="appt-color" style="background:${appt.color || '#3d6b3d'}"></div>
                    <div class="appt-info">
                        <div class="appt-title">${escapeHtml(appt.title)}</div>
                        ${timeStr}${descStr}
                    </div>
                </div>`;
            });
        }
        apptHtml += '</div>';

        // Insert appointments before route stops
        const routeStops = document.getElementById('routeStops');
        const existingApptSection = routeStops.parentElement.querySelector('.appointments-section');
        if (existingApptSection) existingApptSection.remove();
        routeStops.insertAdjacentHTML('beforebegin', apptHtml);

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

        const confirmMsg = `${orderIds.length} bestelling(en) op "onderweg" zetten${sendEmails ? ' en emails versturen' : ''}?`;
        const ok = await showConfirm(confirmMsg, 'Route starten');
        if (!ok) return;

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
                showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-truck"></i> Start Route';
            }
        } catch (error) {
            console.error('Error:', error);
            showToast('Er ging iets mis', 'error');
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
            await loadBakdagen();
        } catch (e) {
            console.error('Error loading data:', e);
        }
    }

    function toLocalDateStr(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    async function loadBakdagen() {
        try {
            const today = new Date();
            const start = toLocalDateStr(today);
            const end = toLocalDateStr(new Date(today.getFullYear(), today.getMonth() + 3, today.getDate()));
            const response = await fetch(`../../api/bakdagen.php?start=${start}&end=${end}`);
            const data = await response.json();
            if (data.success) {
                allBakdagen = data.bakdagen || [];
            }
        } catch (e) {
            console.error('Error loading bakdagen:', e);
        }
    }

    function getAvailableBakdagen() {
        if (document.getElementById('newOrderInternal').checked) return 999;
        const dateStr = document.getElementById('newOrderDate').value;
        if (!dateStr) return 999;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const target = new Date(dateStr + 'T00:00');
        let count = 0;
        const d = new Date(today);
        while (d <= target) {
            if (allBakdagen.includes(toLocalDateStr(d))) count++;
            d.setDate(d.getDate() + 1);
        }
        return count;
    }

    function getEarliestDeliveryDate(recipeDays) {
        if (!recipeDays || recipeDays <= 0) recipeDays = 1;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        let count = 0;
        const d = new Date(today);
        while (count < recipeDays) {
            if (allBakdagen.includes(toLocalDateStr(d))) count++;
            if (count < recipeDays) d.setDate(d.getDate() + 1);
        }
        return toLocalDateStr(d);
    }

    function formatDateNL(dateStr) {
        return new Date(dateStr + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'short', day: 'numeric', month: 'short'});
    }

    function isProductAvailable(recipeDays) {
        return getAvailableBakdagen() >= (recipeDays || 1);
    }

    function buildProductOptions() {
        const available = getAvailableBakdagen();
        let html = '<option value="">Kies product...</option>';
        allProducts.forEach(p => {
            const days = p.recipe_days || 1;
            const canMake = days <= available;
            if (canMake) {
                html += `<option value="${p.id}">${escapeHtml(p.naam)}</option>`;
            } else {
                const earliest = getEarliestDeliveryDate(days);
                html += `<option value="${p.id}" disabled style="color: #999;">${escapeHtml(p.naam)} \u2014 pas vanaf ${formatDateNL(earliest)} (Bakproces: ${days} dagen)</option>`;
            }
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
                if (product && isProductAvailable(product.recipe_days)) {
                    productSelect.value = currentVal;
                    onProductSelect(productSelect);
                } else {
                    productSelect.value = '';
                    const variantSelect = row.querySelector('.variant-select');
                    if (variantSelect) { variantSelect.style.display = 'none'; variantSelect.innerHTML = ''; }
                    const priceEl = row.querySelector('.product-price');
                    if (priceEl) priceEl.textContent = '\u20AC0,00';
                }
            }
        });
        updateNewOrderTotal();
    }

    function checkBakdag() {
        const date = document.getElementById('newOrderDate').value;
        const indicator = document.getElementById('bakdagIndicator');
        const warning = document.getElementById('bakdagWarning');

        if (!date) {
            indicator.style.display = 'none';
            warning.style.display = 'none';
            return;
        }

        if (allBakdagen.includes(date)) {
            indicator.style.display = '';
            warning.style.display = 'none';
        } else {
            indicator.style.display = 'none';
            warning.style.display = '';
            const next = allBakdagen.find(d => d > date);
            document.getElementById('nextBakdag').textContent = next
                ? new Date(next + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'long', day: 'numeric', month: 'long'})
                : 'onbekend';
        }

        refreshProductOptions();
    }

    function selectNextBakdag() {
        const date = document.getElementById('newOrderDate').value;
        const next = allBakdagen.find(d => d > date);
        if (next) {
            document.getElementById('newOrderDate').value = next;
            checkBakdag();
        }
    }

    function onInternalToggle() {
        const isInternal = document.getElementById('newOrderInternal').checked;
        const customerGroup = document.getElementById('customerGroup');
        const customerCard = document.getElementById('customerInfoCard');

        if (isInternal) {
            customerGroup.style.display = 'none';
            customerCard.classList.remove('show');
        } else {
            customerGroup.style.display = '';
        }
        refreshProductOptions();
    }

    function getInternalAccountId() {
        const internal = allCustomers.find(c => c.is_internal == 1);
        return internal ? internal.id : null;
    }
    
    async function openNewOrderModal(prefillDate) {
        await loadNewOrderData();

        // Reset internal toggle
        document.getElementById('newOrderInternal').checked = false;
        onInternalToggle();

        const custSelect = document.getElementById('newOrderCustomer');
        custSelect.innerHTML = '<option value="">Selecteer een klant...</option>';
        allCustomers.filter(c => !c.is_internal).forEach(c => {
            custSelect.innerHTML += `<option value="${c.id}">${escapeHtml(c.bedrijfsnaam)} (${escapeHtml(c.contactpersoon)})</option>`;
        });

        document.getElementById('newOrderDate').value = prefillDate || toLocalDateStr(new Date());
        document.getElementById('newOrderNotes').value = '';
        document.getElementById('newOrderProducts').innerHTML = '';
        newOrderProductIndex = 0;
        addProductRow();
        updateNewOrderTotal();
        checkBakdag();

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

        const row = document.createElement('div');
        row.className = 'product-select-row';
        row.innerHTML = `
            <select class="form-control product-select" data-idx="${idx}" onchange="onProductSelect(this)">${buildProductOptions()}</select>
            <select class="form-control variant-select" data-idx="${idx}" onchange="onVariantSelect(this)" style="display:none;"></select>
            <input type="number" class="form-control product-qty" data-idx="${idx}" min="1" value="1" onchange="updateNewOrderTotal()" oninput="updateNewOrderTotal()">
            <span class="product-price" data-idx="${idx}">&euro;0,00</span>
            <button type="button" class="btn-remove" onclick="removeProductRow(this)"><i class="bi bi-x"></i></button>
        `;
        container.appendChild(row);
    }

    function removeProductRow(btn) {
        btn.closest('.product-select-row').remove();
        updateNewOrderTotal();
    }

    function onProductSelect(select) {
        const idx = select.dataset.idx;
        const productId = parseInt(select.value);
        const variantSelect = document.querySelector(`.variant-select[data-idx="${idx}"]`);
        const priceEl = document.querySelector(`.product-price[data-idx="${idx}"]`);

        if (!productId) {
            variantSelect.style.display = 'none';
            variantSelect.innerHTML = '';
            priceEl.textContent = '\u20AC0,00';
            updateNewOrderTotal();
            return;
        }

        const product = allProducts.find(p => p.id == productId);
        if (!product) return;

        if (product.variants && product.variants.length > 0) {
            const available = getAvailableBakdagen();
            let variantOptions = '<option value="">Kies variant...</option>';
            product.variants.forEach(v => {
                const label = v.gewicht + 'g' + (v.naam ? ' - ' + v.naam : '');
                const days = v.recipe_days || 1;
                const canMake = days <= available;
                if (canMake) {
                    variantOptions += `<option value="${v.id}" data-price="${v.prijs}" data-weight="${v.gewicht}" data-naam="${escapeHtml(v.naam || '')}">${escapeHtml(label)} (\u20AC${parseFloat(v.prijs).toFixed(2).replace('.', ',')})</option>`;
                } else {
                    const earliest = getEarliestDeliveryDate(days);
                    variantOptions += `<option value="${v.id}" disabled style="color: #999;">${escapeHtml(label)} \u2014 pas vanaf ${formatDateNL(earliest)} (Bakproces: ${days} dagen)</option>`;
                }
            });
            variantSelect.innerHTML = variantOptions;
            variantSelect.style.display = '';
            priceEl.textContent = '\u20AC0,00';
        } else {
            variantSelect.style.display = 'none';
            variantSelect.innerHTML = '';
            priceEl.textContent = '\u20AC' + parseFloat(product.prijs).toFixed(2).replace('.', ',');
        }

        updateNewOrderTotal();
    }

    function onVariantSelect(select) {
        const idx = select.dataset.idx;
        const option = select.options[select.selectedIndex];
        const price = parseFloat(option?.dataset?.price || 0);
        const priceEl = document.querySelector(`.product-price[data-idx="${idx}"]`);
        priceEl.textContent = '\u20AC' + price.toFixed(2).replace('.', ',');
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
                if (product && product.variants && product.variants.length > 0 && variantSelect.value) {
                    const option = variantSelect.options[variantSelect.selectedIndex];
                    price = parseFloat(option?.dataset?.price || 0);
                } else if (product && (!product.variants || product.variants.length === 0)) {
                    price = parseFloat(product.prijs || 0);
                }
            }

            total += qty * price;
        });
        document.getElementById('newOrderTotal').textContent = '\u20AC' + total.toFixed(2).replace('.', ',');
    }

    async function submitNewOrder() {
        const isInternal = document.getElementById('newOrderInternal').checked;
        const accountId = isInternal
            ? getInternalAccountId()
            : document.getElementById('newOrderCustomer').value;
        const deliveryDate = document.getElementById('newOrderDate').value;
        const notes = document.getElementById('newOrderNotes').value.trim();

        if (!isInternal && !accountId) { showToast('Selecteer een klant', 'warning'); return; }
        if (isInternal && !accountId) { showToast('Intern account niet gevonden. Voer eerst migration 028 uit.', 'error'); return; }
        if (!deliveryDate) { showToast('Selecteer een leverdatum', 'warning'); return; }

        const items = [];
        document.querySelectorAll('.product-select-row').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            const variantSelect = row.querySelector('.variant-select');
            const qty = parseInt(row.querySelector('.product-qty').value) || 0;
            const productId = parseInt(productSelect.value);

            if (!productId || qty <= 0) return;

            const product = allProducts.find(p => p.id == productId);
            if (!product) return;

            let productName = product.naam;
            let price = parseFloat(product.prijs || 0);

            if (product.variants && product.variants.length > 0 && variantSelect.value) {
                const variantOption = variantSelect.options[variantSelect.selectedIndex];
                const weight = variantOption.dataset.weight;
                price = parseFloat(variantOption.dataset.price || 0);
                const variantNaam = variantOption.dataset.naam;
                if (variantNaam) {
                    productName = `${product.naam} - ${variantNaam} (${weight}g)`;
                } else {
                    productName = `${product.naam} (${weight}g)`;
                }
            }

            items.push({
                product_name: productName,
                quantity: qty,
                unit_price: price,
                variant_id: variantSelect && variantSelect.value ? parseInt(variantSelect.value) || null : null,
                product_id: productId || null
            });
        });

        if (items.length === 0) { showToast('Voeg minimaal \u00e9\u00e9n product toe', 'warning'); return; }

        const payload = {
            account_id: parseInt(accountId),
            delivery_date: deliveryDate,
            items,
            notes
        };
        if (isInternal) {
            payload.is_internal = true;
        }

        await doSubmitOrder(payload);
    }

    let pendingOrderPayload = null;

    function openBakdagConfirm(message) {
        document.getElementById('bakdagConfirmText').textContent = message;
        document.getElementById('bakdagConfirmModal').classList.add('active');
    }

    function closeBakdagConfirm() {
        document.getElementById('bakdagConfirmModal').classList.remove('active');
        pendingOrderPayload = null;
    }

    async function confirmBakdagOverride() {
        document.getElementById('bakdagConfirmModal').classList.remove('active');
        if (pendingOrderPayload) {
            const payload = Object.assign({}, pendingOrderPayload, { confirm_override: true });
            pendingOrderPayload = null;
            await doSubmitOrder(payload);
        }
    }

    async function doSubmitOrder(payload) {
        const btn = document.getElementById('btnSubmitOrder');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig...';

        try {
            const response = await fetch('../../api/admin-orders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();

            if (data.success) {
                closeNewOrderModal();
                showToast(data.message, 'success');
                setTimeout(function() { window.location.reload(); }, 1500);
            } else if (data.needs_confirm) {
                pendingOrderPayload = payload;
                openBakdagConfirm(data.warning);
            } else {
                showToast('Fout: ' + (data.error || 'Onbekende fout'), 'error');
            }
        } catch (e) {
            console.error('Error:', e);
            showToast('Er ging iets mis bij het plaatsen van de bestelling', 'error');
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
