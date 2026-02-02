<?php
require_once 'config.php';
requireLogin();

setlocale(LC_TIME, 'nl_NL.UTF-8', 'nl_NL', 'nl');

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
$btwTarief = floatval($stmt->fetchColumn() ?: 9);

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bedrijf_adres'");
$startAdres = $stmt->fetchColumn() ?: '';

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bedrijf_postcode'");
$startPostcode = $stmt->fetchColumn() ?: '';

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bedrijf_plaats'");
$startPlaats = $stmt->fetchColumn() ?: '';

$bakkerijAdres = trim($startAdres . ', ' . $startPostcode . ' ' . $startPlaats, ', ');

$viewDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$viewMode = isset($_GET['mode']) ? $_GET['mode'] : 'week';

$startDate = new DateTime($viewDate);
if ($viewMode === 'week') {
    $startDate->modify('monday this week');
    $endDate = clone $startDate;
    $endDate->modify('+6 days');
} else {
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
        ba.delivery_plaats,
        ba.delivery_contactpersoon
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

$bereidingOrders = [];
$leveringOrders = [];

$today = new DateTime();
$today->setTime(0, 0, 0);

foreach ($allOrders as $order) {
    $deliveryDate = new DateTime($order['delivery_date']);
    $bereidingDate = clone $deliveryDate;
    $bereidingDate->modify('-1 day');
    
    $bereidKey = $bereidingDate->format('Y-m-d');
    if (!isset($bereidingOrders[$bereidKey])) {
        $bereidingOrders[$bereidKey] = [];
    }
    $bereidingOrders[$bereidKey][] = $order;
    
    $leverKey = $deliveryDate->format('Y-m-d');
    if (!isset($leveringOrders[$leverKey])) {
        $leveringOrders[$leverKey] = [];
    }
    $leveringOrders[$leverKey][] = $order;
}

function getDutchDayName($date) {
    $dagen = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
    return $dagen[$date->format('w')];
}

function getDutchMonthName($date) {
    $maanden = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    return $maanden[$date->format('n') - 1];
}

function formatDutchDate($date) {
    return getDutchDayName($date) . ' ' . $date->format('j') . ' ' . getDutchMonthName($date);
}

$dagTitels = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
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
            max-width: 1400px;
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
        
        .breadcrumb a {
            color: #8b5a2b;
            text-decoration: none;
        }
        .breadcrumb span { color: #888; margin: 0 0.5rem; }
        
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
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .view-tab:hover { background: #f5f2ed; }
        .view-tab.active {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
        }
        
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
            color: #5c3d1e;
            font-size: 1.1rem;
        }
        .nav-btn:hover { background: #f5f2ed; }
        .current-period {
            font-weight: 600;
            color: #5c3d1e;
            min-width: 200px;
            text-align: center;
        }
        .today-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #8b5a2b;
            background: white;
            color: #8b5a2b;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        .today-btn:hover { background: #8b5a2b; color: white; }
        
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 1100px) {
            .main-grid { grid-template-columns: 1fr; }
        }
        
        .panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .panel-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .panel-header h2 {
            font-size: 1.1rem;
            color: #5c3d1e;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .panel-header .icon {
            font-size: 1.3rem;
        }
        .panel-body {
            max-height: calc(100vh - 280px);
            overflow-y: auto;
        }
        
        .day-group {
            border-bottom: 1px solid #f0f0f0;
        }
        .day-group:last-child { border-bottom: none; }
        .day-header {
            padding: 0.75rem 1.25rem;
            background: #faf8f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .day-header:hover { background: #f5f2ed; }
        .day-header.today { background: linear-gradient(135deg, #e8dfd2, #f5f2ed); }
        .day-title {
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .day-title .weekday {
            text-transform: capitalize;
        }
        .day-badge {
            background: #8b5a2b;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .day-badge.empty {
            background: #ddd;
            color: #888;
        }
        
        .day-orders {
            padding: 0;
        }
        
        .order-row {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid #f5f5f5;
            gap: 1rem;
            cursor: pointer;
            transition: background 0.15s;
        }
        .order-row:hover { background: #faf8f5; }
        .order-row:last-child { border-bottom: none; }
        
        .order-time {
            font-size: 0.85rem;
            color: #888;
            min-width: 50px;
        }
        
        .order-info {
            flex: 1;
        }
        .order-company {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.2rem;
        }
        .order-address {
            font-size: 0.85rem;
            color: #666;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .order-products-summary {
            font-size: 0.8rem;
            color: #888;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .order-badges {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
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
        .status-badge.factuur { background: #f3e5f5; color: #7b1fa2; }
        .status-badge.ideal { background: #e3f2fd; color: #1565c0; }
        .status-badge.onderweg { background: #e3f2fd; color: #1565c0; }
        .status-badge.afgeleverd { background: #d1e7dd; color: #0f5132; }
        
        .order-amount {
            font-weight: 600;
            color: #5c3d1e;
            white-space: nowrap;
        }
        
        .order-actions {
            display: flex;
            gap: 0.3rem;
        }
        .order-action-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: #f5f2ed;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5c3d1e;
            transition: all 0.15s;
        }
        .order-action-btn:hover { background: #8b5a2b; color: white; }
        
        .route-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
        }
        .route-btn:hover { background: linear-gradient(135deg, #1976d2, #1565c0); }
        .route-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .empty-day {
            padding: 1.5rem;
            text-align: center;
            color: #999;
            font-size: 0.9rem;
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
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-header {
            padding: 1.25rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .modal-header h3 {
            color: #5c3d1e;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: #f5f2ed;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.2rem;
            color: #666;
        }
        .modal-close:hover { background: #e8e8e8; }
        
        .modal-body { padding: 1.25rem; }
        
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
            font-size: 0.75rem;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }
        .detail-item .value {
            color: #333;
            font-weight: 500;
        }
        .detail-item .value a {
            color: #8b5a2b;
            text-decoration: none;
        }
        .detail-item .value a:hover { text-decoration: underline; }
        
        .detail-section {
            margin-bottom: 1.5rem;
        }
        .detail-section h4 {
            font-size: 0.9rem;
            color: #5c3d1e;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }
        
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
        .product-qty {
            font-weight: 600;
            color: #8b5a2b;
            margin-right: 0.5rem;
        }
        
        .notes-box {
            background: #fffbe6;
            border: 1px solid #ffe58f;
            border-radius: 6px;
            padding: 0.75rem;
            font-size: 0.9rem;
        }
        
        .modal-actions {
            padding: 1rem 1.25rem;
            border-top: 1px solid #eee;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
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
        .btn-primary {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
        }
        .btn-primary:hover { opacity: 0.9; }
        .btn-outline {
            background: white;
            border: 2px solid #8b5a2b;
            color: #8b5a2b;
        }
        .btn-outline:hover { background: #f5f2ed; }
        .btn-route {
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
        }
        
        .status-flow {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .status-step {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.4rem 0.75rem;
            background: #f5f2ed;
            border-radius: 20px;
            font-size: 0.8rem;
            color: #888;
        }
        .status-step.active {
            background: #d1e7dd;
            color: #0f5132;
        }
        .status-step.current {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
        }
        .status-arrow { color: #ccc; }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #e8e8e8;
            border-radius: 8px;
            overflow: hidden;
            margin: 1rem 0;
        }
        .calendar-header-cell {
            background: #5c3d1e;
            color: white;
            padding: 0.75rem 0.5rem;
            text-align: center;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .calendar-cell {
            background: white;
            min-height: 100px;
            padding: 0.5rem;
        }
        .calendar-cell.other-month { background: #faf8f5; }
        .calendar-cell.today { background: #fffbe6; }
        .calendar-date {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            color: #333;
        }
        .calendar-cell.other-month .calendar-date { color: #aaa; }
        .calendar-cell.today .calendar-date { color: #8b5a2b; }
        .calendar-orders {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .calendar-order-dot {
            padding: 0.2rem 0.4rem;
            background: #e8dfd2;
            border-radius: 4px;
            font-size: 0.7rem;
            color: #5c3d1e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }
        .calendar-order-dot:hover { background: #8b5a2b; color: white; }
        .calendar-more {
            font-size: 0.7rem;
            color: #8b5a2b;
            cursor: pointer;
        }
        
        .totals-summary {
            display: flex;
            gap: 1.5rem;
            padding: 0.75rem 1.25rem;
            background: #faf8f5;
            border-top: 1px solid #eee;
        }
        .total-item {
            text-align: center;
        }
        .total-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #5c3d1e;
        }
        .total-label {
            font-size: 0.75rem;
            color: #888;
        }
        
        .modal-large {
            max-width: 900px;
        }
        
        .route-modal-header {
            background: linear-gradient(135deg, #2196f3, #1976d2);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .route-modal-header h3 {
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .route-modal-header .modal-close {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .route-modal-header .modal-close:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .route-summary {
            display: flex;
            gap: 2rem;
            padding: 1rem 1.5rem;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
        }
        .route-stat {
            text-align: center;
        }
        .route-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1976d2;
        }
        .route-stat-label {
            font-size: 0.75rem;
            color: #666;
            text-transform: uppercase;
        }
        
        .route-actions-bar {
            display: flex;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: white;
            border-bottom: 1px solid #eee;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .btn-onderweg {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
        }
        .btn-onderweg:hover {
            background: linear-gradient(135deg, #f57c00, #e65100);
        }
        .btn-onderweg:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn-onderweg i {
            font-size: 1.2rem;
        }
        
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
        
        .route-stops-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .route-start-point {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: #e8f5e9;
            border-bottom: 1px solid #c8e6c9;
        }
        .route-start-point .stop-marker {
            background: #4caf50;
            color: white;
        }
        .route-start-point .stop-info h4 {
            color: #2e7d32;
        }
        
        .route-end-point {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: #fff3e0;
            border-top: 1px solid #ffe0b2;
        }
        .route-end-point .stop-marker {
            background: #ff9800;
            color: white;
        }
        .route-end-point .stop-info h4 {
            color: #e65100;
        }
        
        .route-stop {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .route-stop:hover {
            background: #faf8f5;
        }
        .route-stop.delivered {
            background: #f0f9f0;
        }
        .route-stop.delivered .stop-marker {
            background: #4caf50;
        }
        
        .stop-marker {
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
        
        .stop-connector {
            width: 2px;
            height: 20px;
            background: #e0e0e0;
            margin: -5px 0 -5px 17px;
        }
        
        .stop-info {
            flex: 1;
        }
        .stop-info h4 {
            margin: 0 0 0.25rem 0;
            color: #333;
            font-size: 1rem;
        }
        .stop-info .stop-address {
            color: #666;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .stop-info .stop-products {
            color: #888;
            font-size: 0.8rem;
            margin-top: 0.3rem;
        }
        
        .stop-badges {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }
        
        .stop-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .stop-actions .btn {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        .btn-delivered {
            background: #4caf50;
            color: white;
        }
        .btn-delivered:hover {
            background: #388e3c;
        }
        .btn-delivered.done {
            background: #e8f5e9;
            color: #2e7d32;
            cursor: default;
        }
        
        .route-map-embed {
            width: 100%;
            height: 250px;
            border: none;
            border-radius: 8px;
            margin: 1rem 0;
        }
        
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #1976d2;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
        }
        .success-message i {
            font-size: 1.5rem;
        }
        
        .day-header-clickable {
            cursor: pointer;
        }
        .day-header-clickable:hover .day-title {
            color: #1976d2;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="bi bi-calendar3"></i> Bakker Dashboard</h1>
        <div class="header-links">
            <a href="orders.php"><i class="bi bi-list-ul"></i> Alle bestellingen</a>
            <a href="index.php"><i class="bi bi-house"></i> Dashboard</a>
            <a href="logout.php">Uitloggen</a>
        </div>
    </div>
    
    <div class="container">
        <div class="top-bar">
            <div class="breadcrumb">
                <a href="index.php">Dashboard</a>
                <span>›</span>
                Bakker Planning
            </div>
            
            <div class="nav-controls">
                <button class="nav-btn" onclick="navigate(-1)"><i class="bi bi-chevron-left"></i></button>
                <span class="current-period">
                    <?php
                    if ($viewMode === 'week') {
                        echo 'Week ' . $startDate->format('W') . ' - ' . getDutchMonthName($startDate) . ' ' . $startDate->format('Y');
                    } else {
                        echo ucfirst(getDutchMonthName($startDate)) . ' ' . $startDate->format('Y');
                    }
                    ?>
                </span>
                <button class="nav-btn" onclick="navigate(1)"><i class="bi bi-chevron-right"></i></button>
                <button class="today-btn" onclick="goToday()">Vandaag</button>
            </div>
            
            <div class="view-tabs">
                <button class="view-tab <?= $viewMode === 'week' ? 'active' : '' ?>" onclick="setViewMode('week')">
                    <i class="bi bi-calendar-week"></i> Week
                </button>
                <button class="view-tab <?= $viewMode === 'month' ? 'active' : '' ?>" onclick="setViewMode('month')">
                    <i class="bi bi-calendar-month"></i> Maand
                </button>
            </div>
        </div>
        
        <div class="main-grid">
            <div class="panel">
                <div class="panel-header">
                    <h2><span class="icon">🔥</span> Bereiden</h2>
                </div>
                <div class="panel-body">
                    <?php
                    $current = clone $startDate;
                    while ($current <= $endDate) {
                        $dateKey = $current->format('Y-m-d');
                        $orders = isset($bereidingOrders[$dateKey]) ? $bereidingOrders[$dateKey] : [];
                        $isToday = $current->format('Y-m-d') === date('Y-m-d');
                        
                        $productTotals = [];
                        foreach ($orders as $order) {
                            foreach ($order['items'] as $item) {
                                $name = $item['product_name'];
                                if (!isset($productTotals[$name])) {
                                    $productTotals[$name] = 0;
                                }
                                $productTotals[$name] += $item['quantity'];
                            }
                        }
                    ?>
                    <div class="day-group">
                        <div class="day-header <?= $isToday ? 'today' : '' ?>" onclick="toggleDay(this)">
                            <div class="day-title">
                                <span class="weekday"><?= getDutchDayName($current) ?></span>
                                <?= $current->format('j') . ' ' . getDutchMonthName($current) ?>
                                <?php if ($isToday): ?><span class="status-badge paid">Vandaag</span><?php endif; ?>
                            </div>
                            <span class="day-badge <?= count($orders) === 0 ? 'empty' : '' ?>">
                                <?= count($orders) ?> <?= count($orders) === 1 ? 'bestelling' : 'bestellingen' ?>
                            </span>
                        </div>
                        <div class="day-orders" style="<?= $isToday || (count($orders) > 0 && $current >= $today) ? '' : 'display:none;' ?>">
                            <?php if (empty($orders)): ?>
                                <div class="empty-day"><i class="bi bi-emoji-smile"></i> Geen bestellingen om te bereiden</div>
                            <?php else: ?>
                                <?php if (!empty($productTotals)): ?>
                                <div style="padding: 0.75rem 1.25rem; background: #f0f9f0; border-bottom: 1px solid #e0e0e0;">
                                    <strong style="font-size: 0.8rem; color: #2e7d32;">Totaal te bereiden:</strong>
                                    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem;">
                                        <?php foreach ($productTotals as $product => $qty): ?>
                                        <span style="background: #c8e6c9; padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 0.8rem; color: #1b5e20;">
                                            <strong><?= $qty ?>x</strong> <?= htmlspecialchars($product) ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php foreach ($orders as $order): ?>
                                <div class="order-row" onclick="showOrderDetail(<?= htmlspecialchars(json_encode($order)) ?>)">
                                    <div class="order-info">
                                        <div class="order-company"><?= htmlspecialchars($order['bedrijfsnaam']) ?></div>
                                        <div class="order-products-summary">
                                            <i class="bi bi-box"></i>
                                            <?php 
                                            $items = array_map(function($i) { return $i['quantity'] . 'x ' . $i['product_name']; }, $order['items']);
                                            echo htmlspecialchars(implode(', ', array_slice($items, 0, 3)));
                                            if (count($items) > 3) echo '...';
                                            ?>
                                        </div>
                                    </div>
                                    <div class="order-badges">
                                        <span class="status-badge <?= $order['payment_status'] ?>"><?= $order['payment_status'] === 'paid' ? 'Betaald' : 'Open' ?></span>
                                    </div>
                                    <div class="order-actions">
                                        <button class="order-action-btn" title="Details" onclick="event.stopPropagation();">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                        $current->modify('+1 day');
                    }
                    ?>
                </div>
            </div>
            
            <div class="panel">
                <div class="panel-header">
                    <h2><span class="icon">🚚</span> Leveren</h2>
                </div>
                <div class="panel-body">
                    <?php
                    $current = clone $startDate;
                    while ($current <= $endDate) {
                        $dateKey = $current->format('Y-m-d');
                        $orders = isset($leveringOrders[$dateKey]) ? $leveringOrders[$dateKey] : [];
                        $isToday = $current->format('Y-m-d') === date('Y-m-d');
                        
                        $addresses = [];
                        foreach ($orders as $order) {
                            $addresses[] = urlencode($order['full_delivery_address']);
                        }
                        $googleMapsUrl = '';
                        if (!empty($addresses)) {
                            $waypoints = implode('/', $addresses);
                            $origin = urlencode($bakkerijAdres ?: 'Leersum, Utrecht');
                            $googleMapsUrl = "https://www.google.com/maps/dir/{$origin}/{$waypoints}";
                        }
                    ?>
                    <div class="day-group">
                        <div class="day-header day-header-clickable <?= $isToday ? 'today' : '' ?>">
                            <div class="day-title" onclick="toggleDay(this.parentElement)">
                                <span class="weekday"><?= getDutchDayName($current) ?></span>
                                <?= $current->format('j') . ' ' . getDutchMonthName($current) ?>
                                <?php if ($isToday): ?><span class="status-badge paid">Vandaag</span><?php endif; ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <?php if (!empty($orders)): ?>
                                <button class="route-btn" onclick="openRouteModal('<?= $dateKey ?>', '<?= formatDutchDate($current) ?>')">
                                    <i class="bi bi-truck"></i> Route Details
                                </button>
                                <?php endif; ?>
                                <span class="day-badge <?= count($orders) === 0 ? 'empty' : '' ?>" onclick="toggleDay(this.closest('.day-header'))">
                                    <?= count($orders) ?> <?= count($orders) === 1 ? 'stop' : 'stops' ?>
                                </span>
                            </div>
                        </div>
                        <div class="day-orders" style="<?= $isToday || (count($orders) > 0 && $current >= $today) ? '' : 'display:none;' ?>">
                            <?php if (empty($orders)): ?>
                                <div class="empty-day"><i class="bi bi-emoji-smile"></i> Geen leveringen vandaag</div>
                            <?php else: ?>
                                <?php foreach ($orders as $idx => $order): ?>
                                <div class="order-row" onclick="showOrderDetail(<?= htmlspecialchars(json_encode($order)) ?>)">
                                    <div class="order-time"><?= $idx + 1 ?>.</div>
                                    <div class="order-info">
                                        <div class="order-company"><?= htmlspecialchars($order['bedrijfsnaam']) ?></div>
                                        <div class="order-address">
                                            <i class="bi bi-geo-alt"></i>
                                            <?= htmlspecialchars($order['full_delivery_address']) ?>
                                        </div>
                                    </div>
                                    <div class="order-badges">
                                        <?php if ($order['delivery_status'] === 'onderweg'): ?>
                                            <span class="status-badge onderweg"><i class="bi bi-truck"></i> Onderweg</span>
                                        <?php elseif ($order['delivery_status'] === 'afgeleverd'): ?>
                                            <span class="status-badge afgeleverd"><i class="bi bi-check"></i> Afgeleverd</span>
                                        <?php endif; ?>
                                        <span class="status-badge <?= $order['payment_status'] ?>"><?= $order['payment_status'] === 'paid' ? 'Betaald' : 'Open' ?></span>
                                    </div>
                                    <span class="order-amount">€<?= number_format($order['total_amount'], 2, ',', '.') ?></span>
                                    <div class="order-actions">
                                        <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($order['full_delivery_address']) ?>" 
                                           target="_blank" 
                                           class="order-action-btn" 
                                           title="Navigeren"
                                           onclick="event.stopPropagation();">
                                            <i class="bi bi-geo-alt"></i>
                                        </a>
                                        <a href="tel:<?= htmlspecialchars($order['telefoon']) ?>" 
                                           class="order-action-btn" 
                                           title="Bellen"
                                           onclick="event.stopPropagation();">
                                            <i class="bi bi-telephone"></i>
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                        $current->modify('+1 day');
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="orderModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="bi bi-box"></i> Bestelling <span id="modalOrderId"></span></h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Bedrijf</label>
                        <div class="value" id="modalCompany"></div>
                    </div>
                    <div class="detail-item">
                        <label>Contactpersoon</label>
                        <div class="value" id="modalContact"></div>
                    </div>
                    <div class="detail-item">
                        <label>Telefoon</label>
                        <div class="value"><a id="modalPhone" href=""></a></div>
                    </div>
                    <div class="detail-item">
                        <label>E-mail</label>
                        <div class="value"><a id="modalEmail" href=""></a></div>
                    </div>
                    <div class="detail-item" style="grid-column: 1 / -1;">
                        <label>Leveradres</label>
                        <div class="value" id="modalAddress"></div>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4>Status</h4>
                    <div class="status-flow" id="modalStatus"></div>
                </div>
                
                <div class="detail-section">
                    <h4>Producten</h4>
                    <div class="product-list" id="modalProducts"></div>
                </div>
                
                <div class="detail-section" id="modalNotesSection" style="display:none;">
                    <h4>Opmerkingen</h4>
                    <div class="notes-box" id="modalNotes"></div>
                </div>
                
                <div class="detail-grid" style="margin-top: 1rem;">
                    <div class="detail-item">
                        <label>Betaalmethode</label>
                        <div class="value" id="modalPaymentType"></div>
                    </div>
                    <div class="detail-item">
                        <label>Totaalbedrag</label>
                        <div class="value" style="font-size: 1.2rem; color: #5c3d1e;" id="modalTotal"></div>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <a id="modalRouteBtn" href="" target="_blank" class="btn btn-route">
                    <i class="bi bi-geo-alt"></i> Navigeren
                </a>
                <a id="modalPhoneBtn" href="" class="btn btn-outline">
                    <i class="bi bi-telephone"></i> Bellen
                </a>
                <a id="modalFactuurBtn" href="" target="_blank" class="btn btn-outline">
                    <i class="bi bi-file-earmark-text"></i> Bestelbon
                </a>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="routeModal">
        <div class="modal modal-large">
            <div class="route-modal-header">
                <h3><i class="bi bi-truck"></i> Leveringen <span id="routeModalDate"></span></h3>
                <button class="modal-close" onclick="closeRouteModal()">&times;</button>
            </div>
            
            <div id="routeSuccessMessage" class="success-message" style="display: none;">
                <i class="bi bi-check-circle-fill"></i>
                <span id="routeSuccessText"></span>
            </div>
            
            <div class="route-summary" id="routeSummary">
                <div class="route-stat">
                    <div class="route-stat-value" id="routeStopCount">0</div>
                    <div class="route-stat-label">Stops</div>
                </div>
                <div class="route-stat">
                    <div class="route-stat-value" id="routeTotalAmount">€0</div>
                    <div class="route-stat-label">Totaal</div>
                </div>
                <div class="route-stat">
                    <div class="route-stat-value" id="routeDeliveredCount">0</div>
                    <div class="route-stat-label">Afgeleverd</div>
                </div>
            </div>
            
            <div class="route-actions-bar">
                <button class="btn btn-onderweg" id="btnStartRoute" onclick="startRoute()">
                    <i class="bi bi-truck"></i> Start Route - Zet op "Onderweg"
                </button>
                <label class="email-toggle">
                    <input type="checkbox" id="sendEmailsCheckbox" checked>
                    Stuur "onderweg" emails naar klanten
                </label>
                <a id="routeGoogleMapsBtn" href="#" target="_blank" class="btn btn-route" style="margin-left: auto;">
                    <i class="bi bi-map"></i> Open in Google Maps
                </a>
            </div>
            
            <div class="route-stops-list" id="routeStopsList">
                <div class="route-start-point">
                    <div class="stop-marker"><i class="bi bi-house-fill"></i></div>
                    <div class="stop-info">
                        <h4>Startpunt: Bakkerij Civetta</h4>
                        <div class="stop-address"><?= htmlspecialchars($bakkerijAdres ?: 'Leersum, Utrecht') ?></div>
                    </div>
                </div>
                
                <div id="routeStopsContainer"></div>
                
                <div class="route-end-point">
                    <div class="stop-marker"><i class="bi bi-arrow-return-left"></i></div>
                    <div class="stop-info">
                        <h4>Terug naar bakkerij</h4>
                        <div class="stop-address"><?= htmlspecialchars($bakkerijAdres ?: 'Leersum, Utrecht') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    const currentDate = '<?= $viewDate ?>';
    const currentMode = '<?= $viewMode ?>';
    const bakkerijAdres = '<?= addslashes($bakkerijAdres ?: 'Leersum, Utrecht') ?>';
    
    let currentRouteDate = null;
    let currentRouteOrders = [];
    let routeLoading = false;
    
    function navigate(direction) {
        const date = new Date(currentDate);
        if (currentMode === 'week') {
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
    
    function toggleDay(header) {
        const orders = header.nextElementSibling;
        if (orders) {
            orders.style.display = orders.style.display === 'none' ? '' : 'none';
        }
    }
    
    async function openRouteModal(date, dateLabel) {
        currentRouteDate = date;
        document.getElementById('routeModalDate').textContent = dateLabel;
        document.getElementById('routeModal').classList.add('active');
        document.getElementById('routeSuccessMessage').style.display = 'none';
        
        try {
            const response = await fetch(`../api/delivery-route.php?date=${date}`);
            const data = await response.json();
            
            if (data.success) {
                currentRouteOrders = data.orders;
                renderRouteStops(data.orders, data.start_address);
                updateRouteSummary(data.orders);
                updateGoogleMapsLink(data.orders, data.start_address);
                updateRouteButtonState(data.orders);
            }
        } catch (error) {
            console.error('Fout bij laden route:', error);
        }
    }
    
    function closeRouteModal() {
        document.getElementById('routeModal').classList.remove('active');
        currentRouteOrders = [];
        currentRouteDate = null;
    }
    
    function renderRouteStops(orders, startAddress) {
        const container = document.getElementById('routeStopsContainer');
        let html = '';
        
        orders.forEach((order, idx) => {
            const isDelivered = order.delivery_status === 'afgeleverd';
            const isOnRoute = order.delivery_status === 'onderweg';
            const products = order.items.map(i => `${i.quantity}x ${i.product_name}`).join(', ');
            
            html += `
                <div class="stop-connector"></div>
                <div class="route-stop ${isDelivered ? 'delivered' : ''}" data-order-id="${order.id}">
                    <div class="stop-marker">${idx + 1}</div>
                    <div class="stop-info">
                        <h4>${escapeHtml(order.bedrijfsnaam)}</h4>
                        <div class="stop-address">
                            <i class="bi bi-geo-alt"></i>
                            ${escapeHtml(order.full_delivery_address)}
                        </div>
                        <div class="stop-products">
                            <i class="bi bi-box"></i> ${escapeHtml(products)}
                        </div>
                        <div class="stop-badges">
                            ${isOnRoute ? '<span class="status-badge onderweg"><i class="bi bi-truck"></i> Onderweg</span>' : ''}
                            ${isDelivered ? '<span class="status-badge afgeleverd"><i class="bi bi-check"></i> Afgeleverd</span>' : ''}
                            <span class="status-badge ${order.payment_status}">${order.payment_status === 'paid' ? 'Betaald' : 'Open'}</span>
                        </div>
                    </div>
                    <div class="stop-actions">
                        ${isDelivered ? 
                            '<button class="btn btn-delivered done"><i class="bi bi-check"></i> Afgeleverd</button>' :
                            `<button class="btn btn-delivered" onclick="markDelivered(${order.id}, this)"><i class="bi bi-check"></i> Afleveren</button>`
                        }
                        <a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(order.full_delivery_address)}" 
                           target="_blank" class="btn btn-outline" title="Navigeren">
                            <i class="bi bi-geo-alt"></i>
                        </a>
                        <a href="tel:${order.telefoon || ''}" class="btn btn-outline" title="Bellen">
                            <i class="bi bi-telephone"></i>
                        </a>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }
    
    function updateRouteSummary(orders) {
        document.getElementById('routeStopCount').textContent = orders.length;
        
        const total = orders.reduce((sum, o) => sum + parseFloat(o.total_amount), 0);
        document.getElementById('routeTotalAmount').textContent = '€' + total.toFixed(2).replace('.', ',');
        
        const delivered = orders.filter(o => o.delivery_status === 'afgeleverd').length;
        document.getElementById('routeDeliveredCount').textContent = delivered + '/' + orders.length;
    }
    
    function updateGoogleMapsLink(orders, startAddress) {
        const waypoints = orders.map(o => encodeURIComponent(o.full_delivery_address)).join('/');
        const origin = encodeURIComponent(startAddress || bakkerijAdres);
        const destination = encodeURIComponent(startAddress || bakkerijAdres);
        
        const url = `https://www.google.com/maps/dir/${origin}/${waypoints}/${destination}`;
        document.getElementById('routeGoogleMapsBtn').href = url;
    }
    
    function updateRouteButtonState(orders) {
        const btn = document.getElementById('btnStartRoute');
        const allOnRoute = orders.every(o => o.delivery_status === 'onderweg' || o.delivery_status === 'afgeleverd');
        
        if (allOnRoute) {
            btn.innerHTML = '<i class="bi bi-check-circle"></i> Route gestart';
            btn.disabled = true;
            btn.style.background = '#4caf50';
        } else {
            btn.innerHTML = '<i class="bi bi-truck"></i> Start Route - Zet op "Onderweg"';
            btn.disabled = false;
            btn.style.background = '';
        }
    }
    
    async function startRoute() {
        if (routeLoading) return;
        
        const sendEmails = document.getElementById('sendEmailsCheckbox').checked;
        const orderIds = currentRouteOrders
            .filter(o => o.delivery_status !== 'onderweg' && o.delivery_status !== 'afgeleverd')
            .map(o => o.id);
        
        if (orderIds.length === 0) {
            alert('Alle bestellingen zijn al onderweg of afgeleverd.');
            return;
        }
        
        if (!confirm(`${orderIds.length} bestelling(en) op "onderweg" zetten${sendEmails ? ' en emails versturen' : ''}?`)) {
            return;
        }
        
        routeLoading = true;
        const btn = document.getElementById('btnStartRoute');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig...';
        
        try {
            const response = await fetch('../api/delivery-route.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'start_route',
                    order_ids: orderIds,
                    send_emails: sendEmails
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('routeSuccessText').textContent = 
                    `${data.updated_count} bestelling(en) op onderweg gezet` + 
                    (sendEmails ? `, ${data.emails_sent} email(s) verstuurd` : '');
                document.getElementById('routeSuccessMessage').style.display = 'flex';
                
                currentRouteOrders.forEach(o => {
                    if (orderIds.includes(o.id)) {
                        o.delivery_status = 'onderweg';
                    }
                });
                
                renderRouteStops(currentRouteOrders, bakkerijAdres);
                updateRouteSummary(currentRouteOrders);
                updateRouteButtonState(currentRouteOrders);
            } else {
                alert('Fout: ' + (data.error || 'Onbekende fout'));
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-truck"></i> Start Route - Zet op "Onderweg"';
            }
        } catch (error) {
            console.error('Fout:', error);
            alert('Er ging iets mis bij het starten van de route');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-truck"></i> Start Route - Zet op "Onderweg"';
        } finally {
            routeLoading = false;
        }
    }
    
    async function markDelivered(orderId, btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        
        try {
            const response = await fetch('../api/delivery-route.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'mark_delivered',
                    order_id: orderId
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                const order = currentRouteOrders.find(o => o.id === orderId);
                if (order) order.delivery_status = 'afgeleverd';
                
                btn.className = 'btn btn-delivered done';
                btn.innerHTML = '<i class="bi bi-check"></i> Afgeleverd';
                btn.closest('.route-stop').classList.add('delivered');
                
                updateRouteSummary(currentRouteOrders);
            } else {
                alert('Fout: ' + (data.error || 'Onbekende fout'));
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check"></i> Afleveren';
            }
        } catch (error) {
            console.error('Fout:', error);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check"></i> Afleveren';
        }
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function showOrderDetail(order) {
        document.getElementById('modalOrderId').textContent = '#' + order.id;
        document.getElementById('modalCompany').textContent = order.bedrijfsnaam;
        document.getElementById('modalContact').textContent = order.contactpersoon || '-';
        
        const phoneEl = document.getElementById('modalPhone');
        phoneEl.textContent = order.telefoon || '-';
        phoneEl.href = order.telefoon ? 'tel:' + order.telefoon : '#';
        
        const emailEl = document.getElementById('modalEmail');
        emailEl.textContent = order.email || '-';
        emailEl.href = order.email ? 'mailto:' + order.email : '#';
        
        document.getElementById('modalAddress').textContent = order.full_delivery_address;
        
        const statusHtml = `
            <div class="status-step ${order.delivery_status === 'geplaatst' ? 'current' : 'active'}">
                <i class="bi bi-check2-circle"></i> Geplaatst
            </div>
            <span class="status-arrow">→</span>
            <div class="status-step ${order.delivery_status === 'wordt_bereid' ? 'current' : (['onderweg', 'afgeleverd'].includes(order.delivery_status) ? 'active' : '')}">
                <i class="bi bi-fire"></i> Bereiden
            </div>
            <span class="status-arrow">→</span>
            <div class="status-step ${order.delivery_status === 'onderweg' ? 'current' : (order.delivery_status === 'afgeleverd' ? 'active' : '')}">
                <i class="bi bi-truck"></i> Onderweg
            </div>
            <span class="status-arrow">→</span>
            <div class="status-step ${order.delivery_status === 'afgeleverd' ? 'current' : ''}">
                <i class="bi bi-house-check"></i> Afgeleverd
            </div>
        `;
        document.getElementById('modalStatus').innerHTML = statusHtml;
        
        let productsHtml = '';
        order.items.forEach(item => {
            productsHtml += `
                <div class="product-row">
                    <span><span class="product-qty">${item.quantity}x</span> ${item.product_name}</span>
                    <span>€${(item.quantity * item.unit_price).toFixed(2).replace('.', ',')}</span>
                </div>
            `;
        });
        document.getElementById('modalProducts').innerHTML = productsHtml;
        
        if (order.notes) {
            document.getElementById('modalNotes').textContent = order.notes;
            document.getElementById('modalNotesSection').style.display = '';
        } else {
            document.getElementById('modalNotesSection').style.display = 'none';
        }
        
        const paymentTypes = {
            'ideal': 'iDEAL',
            'mollie_direct': 'iDEAL', 
            'factuur': 'Op factuur',
            'invoice': 'Op factuur'
        };
        let paymentText = paymentTypes[order.payment_type] || order.payment_type;
        if (order.payment_status === 'paid') {
            paymentText += ' <span class="status-badge paid">Betaald</span>';
        } else {
            paymentText += ' <span class="status-badge pending">Openstaand</span>';
        }
        document.getElementById('modalPaymentType').innerHTML = paymentText;
        
        document.getElementById('modalTotal').textContent = '€' + parseFloat(order.total_amount).toFixed(2).replace('.', ',');
        
        document.getElementById('modalRouteBtn').href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(order.full_delivery_address);
        document.getElementById('modalPhoneBtn').href = order.telefoon ? 'tel:' + order.telefoon : '#';
        document.getElementById('modalFactuurBtn').href = '../api/bestelbon.php?order_id=' + order.id;
        
        document.getElementById('orderModal').classList.add('active');
    }
    
    function closeModal() {
        document.getElementById('orderModal').classList.remove('active');
    }
    
    document.getElementById('orderModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    
    document.getElementById('routeModal').addEventListener('click', function(e) {
        if (e.target === this) closeRouteModal();
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
            closeRouteModal();
        }
    });
    </script>
</body>
</html>
