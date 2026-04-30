<?php
require_once '../config.php';
requireLogin();

setlocale(LC_TIME, 'nl_NL.UTF-8', 'nl_NL', 'nl');

function getDutchDate($date) {
    $dagen = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
    $maanden = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    $ts = strtotime($date);
    $dag = $dagen[date('w', $ts)];
    $dagNr = date('j', $ts);
    $maand = $maanden[date('n', $ts) - 1];
    $jaar = date('Y', $ts);
    return "$dag $dagNr $maand $jaar";
}


$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
$btwTarief = floatval($stmt->fetchColumn() ?: 9);

function berekenBtw($totaalInclBtw, $tarief) {
    $btw = $totaalInclBtw - ($totaalInclBtw / (1 + $tarief / 100));
    return round($btw, 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $orderId = intval($_POST['order_id']);
    $paymentStatus = $_POST['payment_status'] ?? null;
    $isCancelled = isset($_POST['is_cancelled']) ? 1 : 0;

    if ($paymentStatus && in_array($paymentStatus, ['pending', 'paid'])) {
        $stmt = $pdo->prepare("UPDATE business_orders SET payment_status = ?, is_cancelled = ? WHERE id = ?");
        $stmt->execute([$paymentStatus, $isCancelled, $orderId]);
        header('Location: orders.php?updated=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
    $orderId = intval($_POST['order_id']);
    $stmt = $pdo->prepare("DELETE FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $stmt = $pdo->prepare("DELETE FROM business_orders WHERE id = ?");
    $stmt->execute([$orderId]);
    header('Location: orders.php?deleted=1');
    exit;
}

$upcomingOrders = $pdo->query("
    SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email, ba.telefoon, ba.adres, ba.postcode, ba.plaats
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    WHERE bo.delivery_date >= CURDATE() AND bo.is_cancelled = 0
    ORDER BY bo.delivery_date ASC
")->fetchAll();

$completedOrders = $pdo->query("
    SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email, ba.telefoon, ba.adres, ba.postcode, ba.plaats
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    WHERE bo.delivery_date < CURDATE() OR bo.is_cancelled = 1
    ORDER BY bo.delivery_date DESC
    LIMIT 50
")->fetchAll();

foreach ($upcomingOrders as &$order) {
    $stmt = $pdo->prepare("SELECT id, product_name, quantity, unit_price, quantity_sold, variant_id, product_id FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll();
}
unset($order);

foreach ($completedOrders as &$order) {
    $stmt = $pdo->prepare("SELECT id, product_name, quantity, unit_price, quantity_sold, variant_id, product_id FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll();
}
unset($order);

$totalUpcoming = array_sum(array_column($upcomingOrders, 'total_amount'));
$totalCompleted = array_sum(array_column($completedOrders, 'total_amount'));

// Sidebar data
$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Bestellingen';
$currentPage = 'orders';
$adminBasePath = '../';
ob_start(); ?>
<style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--cream);
            min-height: 100vh;
        }
        .admin-content {
            padding: 2rem;
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
        }
        .breadcrumb {
            margin-bottom: 1.5rem;
        }
        .breadcrumb a {
            color: #3d6b3d;
            text-decoration: none;
        }
        .breadcrumb a:hover { text-decoration: underline; }
        .breadcrumb span { color: #888; margin: 0 0.5rem; }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            background: #d4edda;
            color: #155724;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-box {
            background: linear-gradient(135deg, #3d6b3d, #2d4a2d);
            color: white;
            padding: 1.25rem;
            border-radius: 10px;
            text-align: center;
        }
        .stat-box .number {
            font-size: 1.75rem;
            font-weight: 700;
        }
        .stat-box .label {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card h2 {
            color: #2d4a2d;
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }
        .orders-grid {
            display: grid;
            gap: 1rem;
        }
        .order-card {
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            overflow: hidden;
            background: #fafafa;
        }
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: white;
            border-bottom: 1px solid #eee;
            flex-wrap: wrap;
            gap: 0.5rem;
            cursor: pointer;
            user-select: none;
            transition: background 0.15s;
        }
        .order-header:hover { background: #faf8f5; }
        .order-header .toggle-icon { transition: transform 0.2s; color: #aaa; font-size: 1.1rem; }
        .order-card.collapsed .order-header .toggle-icon { transform: rotate(-90deg); }
        .order-card.collapsed .order-body,
        .order-card.collapsed .order-actions { display: none !important; }
        .order-header-info { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; flex: 1; min-width: 0; }
        .order-header-badges { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; flex-shrink: 0; }
        .order-header-meta { font-size: 0.8rem; color: #888; display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .order-header-meta span { display: inline-flex; align-items: center; gap: 0.25rem; }
        .order-header-amount { font-weight: 700; color: #2d4a2d; font-size: 0.95rem; white-space: nowrap; }
        .order-header .order-id {
            font-weight: 700;
            color: #333;
        }
        .order-header .customer {
            color: #666;
            font-size: 0.9rem;
        }
        .order-header .customer strong {
            color: #333;
        }
        .status-badge {
            padding: 0.3rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        .status-badge.paid { background: #d1e7dd; color: #0f5132; }
        .status-badge.cancelled { background: #f8d7da; color: #842029; }
        .payment-type-badge { display: inline-flex; align-items: center; gap: 4px; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; margin-left: 0.5rem; }
        .payment-type-badge.mollie_direct { background: #e3f2fd; color: #1565c0; }
        .payment-type-badge.invoice { background: #f3e5f5; color: #7b1fa2; }
        .payment-type-badge.cash { background: #e8f5e9; color: #2e7d32; }
        .payment-method {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        .payment-method.mollie { background: #e3f2fd; color: #1565c0; }
        .payment-method.pending-payment { background: #fff3e0; color: #e65100; }
        .mollie-status { font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 4px; margin-left: 0.25rem; }
        .mollie-status.paid { background: #d1e7dd; color: #0f5132; }
        .mollie-status.pending, .mollie-status.open { background: #fff3cd; color: #856404; }
        .mollie-status.failed, .mollie-status.canceled, .mollie-status.expired { background: #f8d7da; color: #842029; }
        .order-body {
            padding: 1rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 600px) {
            .order-body { grid-template-columns: 1fr; }
        }
        .order-info dt {
            font-size: 0.75rem;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 0.2rem;
        }
        .order-info dd {
            margin-bottom: 0.75rem;
            color: #333;
        }
        .order-items {
            background: white;
            border-radius: 6px;
            padding: 0.75rem;
        }
        .order-items h4 {
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
        }
        .order-item {
            display: flex;
            justify-content: space-between;
            padding: 0.35rem 0;
            font-size: 0.9rem;
            border-bottom: 1px solid #f0f0f0;
        }
        .order-item:last-child { border-bottom: none; }
        .order-subtotal {
            display: flex;
            justify-content: space-between;
            padding: 0.25rem 0;
            font-size: 0.85rem;
            color: #666;
        }
        .order-subtotal:first-of-type {
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid #e8e8e8;
        }
        .order-total {
            display: flex;
            justify-content: space-between;
            padding-top: 0.5rem;
            margin-top: 0.25rem;
            border-top: 2px solid #e8e8e8;
            font-weight: 700;
            color: #2d4a2d;
        }
        .order-actions {
            padding: 1rem;
            background: white;
            border-top: 1px solid #eee;
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .order-actions label {
            font-size: 0.85rem;
            color: #666;
        }
        .order-actions select {
            padding: 0.4rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .btn-update {
            background: #3d6b3d;
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .btn-update:hover { background: #2d4a2d; }
        .empty {
            color: #888;
            font-style: italic;
            padding: 2rem;
            text-align: center;
        }
        .filter-bar {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 0.9rem;
            border: 2px solid #e8e8e8;
            border-radius: 20px;
            background: white;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s;
            color: #666;
        }
        .filter-btn:hover {
            border-color: #3d6b3d;
            color: #3d6b3d;
        }
        .filter-btn.active {
            background: #3d6b3d;
            border-color: #3d6b3d;
            color: white;
        }
        .filter-btn i {
            font-size: 1rem;
        }
        .delivery-icon {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #e3f2fd;
            color: #1565c0;
            margin-left: 0.5rem;
        }
        .notes {
            background: #fffbe6;
            border: 1px solid #ffe58f;
            border-radius: 4px;
            padding: 0.5rem;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }
        .btn-factuur {
            display: inline-block;
            margin-top: 0.75rem;
            padding: 0.5rem 1rem;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .btn-factuur:hover {
            background: #5a6268;
        }
        .search-bar {
            width: 100%;
            padding: 0.6rem 1rem 0.6rem 2.5rem;
            border: 2px solid #e0d5c7;
            border-radius: 8px;
            font-size: 0.95rem;
            background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23999' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.442.656a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z'/%3E%3C/svg%3E") no-repeat 0.75rem center;
            margin-bottom: 1rem;
        }
        .search-bar:focus { outline: none; border-color: #c8913a; }
        .btn-edit-order {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 0.4rem 0.9rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-edit-order:hover { background: #138496; }
        .btn-delete-order {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.4rem 0.9rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-delete-order:hover { background: #b02a37; }
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: flex-start;
            padding: 2rem;
            overflow-y: auto;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-top: 2rem;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #eee;
        }
        .modal-header h3 { margin: 0; color: #2d4a2d; font-size: 1.1rem; }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #999;
            line-height: 1;
        }
        .modal-close:hover { color: #333; }
        .modal-body { padding: 1.5rem; }
        .modal-body label { display: block; font-size: 0.8rem; color: #888; text-transform: uppercase; margin-bottom: 0.4rem; font-weight: 600; }
        .modal-body textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
            min-height: 60px;
            resize: vertical;
            font-family: inherit;
        }
        .edit-products-list { margin-top: 1rem; }
        .edit-product-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f0f0f0;
            opacity: 0.5;
            transition: opacity 0.2s;
        }
        .edit-product-row.in-order { opacity: 1; }
        .edit-product-info { flex: 1; min-width: 0; }
        .edit-product-name { font-size: 0.9rem; color: #333; }
        .edit-product-price { font-size: 0.8rem; color: #888; }
        .edit-product-controls { display: flex; align-items: center; gap: 0.5rem; }
        .qty-btn {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 2px solid #ddd;
            background: white;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            transition: all 0.15s;
        }
        .qty-btn:hover { border-color: #3d6b3d; color: #3d6b3d; }
        .qty-display { min-width: 24px; text-align: center; font-weight: 600; font-size: 0.95rem; }
        .edit-total-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            margin-top: 0.5rem;
            border-top: 2px solid #e8e8e8;
            font-weight: 700;
            color: #2d4a2d;
            font-size: 1rem;
        }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        .btn-cancel { background: #e0e0e0; color: #333; border: none; padding: 0.5rem 1.25rem; border-radius: 6px; cursor: pointer; font-size: 0.9rem; }
        .btn-cancel:hover { background: #ccc; }
        .btn-save { background: #3d6b3d; color: white; border: none; padding: 0.5rem 1.25rem; border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 600; }
        .btn-save:hover { background: #2d4a2d; }
        .btn-save:disabled { opacity: 0.6; cursor: not-allowed; }

        /* FAB button */
        .fab {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3d6b3d, #2d4a2d);
            color: white;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(139,90,43,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            z-index: 900;
            transition: all 0.2s;
        }
        .fab:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(139,90,43,0.5); }

        /* New order modal */
        .new-order-modal { max-width: 700px; }
        .new-order-modal .modal-body { padding: 1.25rem; }
        .new-order-modal .form-group { margin-bottom: 1rem; }
        .new-order-modal .form-group > label { display: block; font-size: 0.85rem; font-weight: 600; color: #333; margin-bottom: 0.4rem; text-transform: none; }
        .new-order-modal .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .new-order-modal .form-control:focus { border-color: #3d6b3d; outline: none; }

        .product-select-row {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .product-select-row select.product-select { flex: 3; }
        .product-select-row select.variant-select { flex: 2; }
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
            border: 2px dashed #3d6b3d;
            background: transparent;
            color: #3d6b3d;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .btn-add-product:hover { background: #f5f2ed; }

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
        .order-total-bar .total-amount { color: #2d4a2d; font-size: 1.3rem; }

        .btn-submit-order {
            padding: 0.75rem 2rem;
            background: linear-gradient(135deg, #3d6b3d, #2d4a2d);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
        }
        .btn-submit-order:hover { background: linear-gradient(135deg, #2d4a2d, #3e2a14); }
        .btn-submit-order:disabled { opacity: 0.6; cursor: not-allowed; }

        /* Internal toggle */
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

        /* Customer info card */
        .customer-info-card {
            display: none;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-top: 0.5rem;
        }
        .customer-info-card.show { display: block; }
        .customer-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        .ci-label { font-size: 0.7rem; text-transform: uppercase; color: #888; font-weight: 600; }
        .ci-value { color: #333; font-size: 0.85rem; }
        .ci-value a { color: #3d6b3d; text-decoration: none; }
        .ci-value a:hover { text-decoration: underline; }

        /* Bakdag indicator */
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

        /* Intern badge */
        .intern-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #fff3e0;
            color: #e65100;
        }
        .settled-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #e8f5e9;
            color: #2e7d32;
        }
        .btn-settle {
            background: #ff9800;
            color: white;
            border: none;
            padding: 0.4rem 0.9rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-settle:hover { background: #f57c00; }

        /* Settle modal */
        .settle-item-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .settle-item-name { flex: 2; font-size: 0.9rem; }
        .settle-item-qty { flex: 0.7; text-align: center; color: #888; font-size: 0.85rem; }
        .settle-item-sold { flex: 0.8; }
        .settle-item-sold input {
            width: 100%;
            padding: 0.4rem;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            text-align: center;
            font-size: 0.9rem;
        }
        .settle-item-sold input:focus { border-color: #ff9800; outline: none; }
        .settle-item-rest { flex: 0.7; text-align: center; font-weight: 600; font-size: 0.85rem; }
        .settle-item-rest.has-remainder { color: #dc3545; }
        .settle-summary {
            padding: 0.75rem 0;
            margin-top: 0.5rem;
            border-top: 2px solid #e8e8e8;
            display: flex;
            justify-content: space-between;
            font-weight: 700;
            color: #2d4a2d;
        }
        .settle-remainder-actions {
            padding: 1rem 0;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn-writeoff {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1.25rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .btn-writeoff:hover { background: #5a6268; }
        .btn-transfer {
            background: #3d6b3d;
            color: white;
            border: none;
            padding: 0.5rem 1.25rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .btn-transfer:hover { background: #2d4a2d; }

        .sold-info { font-size: 0.8rem; color: #2e7d32; }
        .remainder-info { font-size: 0.8rem; color: #dc3545; }

        /* Multi-select */
        .order-select { width: 20px; height: 20px; accent-color: #3d6b3d; cursor: pointer; flex-shrink: 0; }
        .order-card.selected > .order-header { background: #f5f0eb; border-left: 3px solid #3d6b3d; }
        .batch-bar { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: white; border-top: 2px solid #3d6b3d; padding: 0.75rem 2rem; z-index: 950; gap: 1rem; align-items: center; justify-content: space-between; box-shadow: 0 -4px 20px rgba(0,0,0,0.12); }
        .batch-bar.show { display: flex; }
        .batch-bar-info { font-size: 0.9rem; font-weight: 600; color: #333; }
        .batch-bar-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .batch-btn { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.5rem 1rem; border: none; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; }
        .batch-btn-paid { background: #4caf50; color: white; }
        .batch-btn-paid:hover { background: #388e3c; }
        .batch-btn-cancel { background: #ff9800; color: white; }
        .batch-btn-cancel:hover { background: #e68a00; }
        .batch-btn-delete { background: #dc3545; color: white; }
        .batch-btn-delete:hover { background: #b71c1c; }
        .batch-btn-deselect { background: #eee; color: #666; }
        .batch-btn-deselect:hover { background: #ddd; }

        @media (max-width: 768px) {
            .fab { bottom: 1.5rem; right: 1.5rem; width: 48px; height: 48px; font-size: 1.25rem; }
            .product-select-row { flex-wrap: wrap; }
            .product-select-row select { flex: 1 1 100%; }
            .settle-item-row { flex-wrap: wrap; gap: 0.4rem; }
            .batch-bar { padding: 0.75rem 1rem; gap: 0.5rem; flex-wrap: wrap; }
            .batch-bar.show + .fab { bottom: 5rem; }
        }

    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">Bestellingen</span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
        <div class="breadcrumb">
            <a href="../index.php">Dashboard</a>
            <span>›</span>
            Bestellingen
        </div>

        <?php if (isset($_GET['updated'])): ?>
            <div class="alert">Status bijgewerkt.</div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat-box">
                <div class="number"><?= count($upcomingOrders) ?></div>
                <div class="label">Lopende bestellingen</div>
            </div>
            <div class="stat-box">
                <div class="number">€<?= number_format($totalUpcoming, 2, ',', '.') ?></div>
                <div class="label">Waarde lopend</div>
            </div>
            <div class="stat-box">
                <div class="number"><?= count($completedOrders) ?></div>
                <div class="label">Afgehandeld</div>
            </div>
            <div class="stat-box">
                <div class="number">€<?= number_format($totalCompleted, 2, ',', '.') ?></div>
                <div class="label">Waarde afgehandeld</div>
            </div>
        </div>

        <div class="card">
            <h2 style="display:flex;align-items:center;gap:0.75rem;">
                <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.8rem;font-weight:500;color:#888;" title="Alles selecteren">
                    <input type="checkbox" class="order-select" id="selectAll" onclick="toggleSelectAll(this.checked)"> Alles
                </label>
                <span style="flex:1;">Lopende Bestellingen</span>
            </h2>
            <input type="text" class="search-bar" id="searchInput" placeholder="Zoek op klantnaam, ordernummer of leverdatum..." oninput="applySearch()">
            
            <div class="filter-bar">
                <button class="filter-btn active" data-filter="all" onclick="toggleFilter(this)">
                    <i class="bi bi-grid"></i> Alles
                </button>
                <button class="filter-btn active" data-filter="paid" onclick="toggleFilter(this)">
                    <i class="bi bi-check-circle"></i> Betaald
                </button>
                <button class="filter-btn active" data-filter="pending" onclick="toggleFilter(this)">
                    <i class="bi bi-clock"></i> Openstaand
                </button>
                <button class="filter-btn active" data-filter="factuur" onclick="toggleFilter(this)">
                    <i class="bi bi-file-earmark-text"></i> Factuur
                </button>
                <button class="filter-btn active" data-filter="ideal" onclick="toggleFilter(this)">
                    <i class="bi bi-credit-card"></i> iDEAL
                </button>
            </div>
            
            <?php if (empty($upcomingOrders)): ?>
                <div class="empty">Geen lopende bestellingen.</div>
            <?php else: ?>
                <div class="orders-grid">
                    <?php foreach ($upcomingOrders as $order): ?>
                        <div class="order-card collapsed" data-order-id="<?= $order['id'] ?>" data-payment-status="<?= $order['payment_status'] ?>" data-payment-type="<?= $order['payment_type'] ?>" data-search="<?= strtolower($order['id'] . ' ' . htmlspecialchars($order['bedrijfsnaam']) . ' ' . htmlspecialchars($order['contactpersoon']) . ' ' . $order['delivery_date'] . ' ' . getDutchDate($order['delivery_date'])) ?>">
                            <div class="order-header" onclick="this.parentElement.classList.toggle('collapsed')">
                                <input type="checkbox" class="order-select" data-order-id="<?= $order['id'] ?>" onclick="event.stopPropagation();toggleOrderSelect(<?= $order['id'] ?>)" title="Selecteer">
                                <div class="order-header-info">
                                    <i class="bi bi-chevron-down toggle-icon"></i>
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="customer"><strong><?= htmlspecialchars($order['bedrijfsnaam']) ?></strong></span>
                                    <div class="order-header-meta">
                                        <span><i class="bi bi-calendar3"></i> <?= date('d-m-Y', strtotime($order['delivery_date'])) ?></span>
                                        <span><i class="bi bi-clock-history"></i> <?= date('d-m-Y', strtotime($order['created_at'])) ?></span>
                                    </div>
                                </div>
                                <div class="order-header-badges">
                                    <span class="order-header-amount">&euro;<?= number_format($order['total_amount'], 2, ',', '.') ?></span>
                                    <?php if (!empty($order['is_internal'])): ?>
                                        <span class="intern-badge"><i class="bi bi-shop"></i> Intern</span>
                                    <?php endif; ?>
                                    <?php if (!empty($order['settled_at'])): ?>
                                        <span class="settled-badge"><i class="bi bi-check-circle"></i> Afgehandeld</span>
                                    <?php endif; ?>
                                    <?php if ($order['is_cancelled']): ?>
                                        <span class="status-badge cancelled">Geannuleerd</span>
                                    <?php else: ?>
                                        <span class="status-badge <?= $order['payment_status'] ?>"><?= $order['payment_status'] === 'paid' ? 'Betaald' : 'In afwachting' ?></span>
                                        <span class="payment-type-badge <?= $order['payment_type'] ?>"><?= $order['payment_type'] === 'mollie_direct' ? 'Mollie' : ($order['payment_type'] === 'invoice' ? 'Factuur' : 'Contant') ?></span>
                                        <?php if (isset($order['delivery_status']) && $order['delivery_status'] === 'onderweg'): ?>
                                            <span class="delivery-icon"><i class="bi bi-truck"></i> Onderweg</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="order-body">
                                <div class="order-info">
                                    <dl>
                                        <dt>Leverdatum</dt>
                                        <dd><?= getDutchDate($order['delivery_date']) ?></dd>
                                        <dt>Adres</dt>
                                        <dd>
                                            <?= htmlspecialchars($order['adres']) ?><br>
                                            <?= htmlspecialchars($order['postcode'] . ' ' . $order['plaats']) ?>
                                        </dd>
                                        <dt>Contact</dt>
                                        <dd>
                                            <?= htmlspecialchars($order['email']) ?><br>
                                            <?= $order['telefoon'] ? htmlspecialchars($order['telefoon']) : '-' ?>
                                        </dd>
                                        <dt>Besteld op</dt>
                                        <dd><?= date('d-m-Y H:i', strtotime($order['created_at'])) ?></dd>
                                        <dt>Betaling</dt>
                                        <dd>
                                            <?php if ($order['mollie_payment_id']): ?>
                                                <span class="payment-method mollie">Mollie</span>
                                                <?php if ($order['mollie_status']): ?>
                                                    <span class="mollie-status <?= $order['mollie_status'] ?>"><?= $order['mollie_status'] ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="payment-method pending-payment">Handmatig</span>
                                            <?php endif; ?>
                                        </dd>
                                    </dl>
                                    <?php if ($order['notes']): ?>
                                        <div class="notes"><strong>Opmerking:</strong> <?= htmlspecialchars($order['notes']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="order-items">
                                    <h4>Producten</h4>
                                    <?php foreach ($order['items'] as $item): ?>
                                        <div class="order-item">
                                            <span>
                                                <?= $item['quantity'] ?>x <?= htmlspecialchars($item['product_name']) ?>
                                                <?php if ($item['quantity_sold'] !== null): ?>
                                                    <span class="sold-info">(<?= $item['quantity_sold'] ?>/<?= $item['quantity'] ?> verkocht)</span>
                                                    <?php $rest = $item['quantity'] - $item['quantity_sold']; if ($rest > 0): ?>
                                                        <span class="remainder-info"><?= $rest ?> afgeschreven</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </span>
                                            <span>&euro;<?= number_format($item['quantity'] * $item['unit_price'], 2, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php
                                        $displayAmount = $order['settled_amount'] !== null ? $order['settled_amount'] : $order['total_amount'];
                                        $btwBedrag = berekenBtw($displayAmount, $btwTarief);
                                        $exclBtw = $displayAmount - $btwBedrag;
                                    ?>
                                    <div class="order-subtotal">
                                        <span>Excl. BTW</span>
                                        <span>&euro;<?= number_format($exclBtw, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="order-subtotal">
                                        <span>BTW (<?= $btwTarief ?>%)</span>
                                        <span>&euro;<?= number_format($btwBedrag, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="order-total">
                                        <span>Totaal incl. BTW</span>
                                        <span>&euro;<?= number_format($displayAmount, 2, ',', '.') ?></span>
                                    </div>
                                    <?php if ($order['settled_amount'] !== null && $order['settled_amount'] != $order['total_amount']): ?>
                                        <div style="font-size: 0.8rem; color: #888; margin-top: 0.3rem;">Productiewaarde: &euro;<?= number_format($order['total_amount'], 2, ',', '.') ?></div>
                                    <?php endif; ?>
                                    <?php if (empty($order['is_internal'])): ?>
                                        <a href="<?= '../../api/factuur.php?order_id=' . $order['id'] ?>" target="_blank" class="btn-factuur">Factuur</a>
                                    <?php elseif (!empty($order['settled_at'])): ?>
                                        <?php if (!empty($order['eboekhouden_invoice_id']) || !empty($order['invoice_number'])): ?>
                                            <a href="<?= '../../api/factuur.php?order_id=' . $order['id'] ?>" target="_blank" class="btn-factuur">Factuur <?= htmlspecialchars($order['eboekhouden_factuurnummer'] ?? $order['invoice_number'] ?? '') ?></a>
                                        <?php else: ?>
                                            <button type="button" class="btn-factuur" onclick="createInternalInvoice(<?= $order['id'] ?>)" style="cursor: pointer; border: none;">
                                                <i class="bi bi-receipt"></i> Factuur aanmaken
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="order-actions">
                                <form method="POST" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                                    <input type="hidden" name="update_status" value="1">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <label>Betaling:</label>
                                    <select name="payment_status">
                                        <option value="pending" <?= $order['payment_status'] === 'pending' ? 'selected' : '' ?>>In afwachting</option>
                                        <option value="paid" <?= $order['payment_status'] === 'paid' ? 'selected' : '' ?>>Betaald</option>
                                    </select>
                                    <label style="display: flex; align-items: center; gap: 4px;">
                                        <input type="checkbox" name="is_cancelled" <?= $order['is_cancelled'] ? 'checked' : '' ?>>
                                        Geannuleerd
                                    </label>
                                    <button type="submit" class="btn-update">Bijwerken</button>
                                </form>
                                <button class="btn-edit-order" onclick="openEditModal(<?= $order['id'] ?>, <?= htmlspecialchars(json_encode($order['items']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($order['notes'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($order['delivery_date'] ?? ''), ENT_QUOTES) ?>, <?= !empty($order['is_internal']) ? 'true' : 'false' ?>)">
                                    <i class="bi bi-pencil"></i> Aanpassen
                                </button>
                                <?php if (!empty($order['is_internal']) && empty($order['settled_at'])): ?>
                                    <button class="btn-settle" onclick="openSettleModal(<?= $order['id'] ?>, <?= htmlspecialchars(json_encode($order['items']), ENT_QUOTES) ?>)">
                                        <i class="bi bi-clipboard-check"></i> Afhandelen
                                    </button>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" onsubmit="return handleDeleteConfirm(event, '<?= $order['id'] ?>', '<?= htmlspecialchars($order['bedrijfsnaam'], ENT_QUOTES) ?>')">
                                    <input type="hidden" name="delete_order" value="1">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="btn-delete-order"><i class="bi bi-trash"></i> Verwijderen</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Afgehandelde Bestellingen</h2>
            
            <?php if (empty($completedOrders)): ?>
                <div class="empty">Nog geen afgehandelde bestellingen.</div>
            <?php else: ?>
                <div class="orders-grid">
                    <?php foreach ($completedOrders as $order): ?>
                        <div class="order-card collapsed" data-search="<?= strtolower($order['id'] . ' ' . htmlspecialchars($order['bedrijfsnaam']) . ' ' . htmlspecialchars($order['contactpersoon']) . ' ' . $order['delivery_date'] . ' ' . getDutchDate($order['delivery_date'])) ?>">
                            <div class="order-header" onclick="this.parentElement.classList.toggle('collapsed')">
                                <div class="order-header-info">
                                    <i class="bi bi-chevron-down toggle-icon"></i>
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="customer"><strong><?= htmlspecialchars($order['bedrijfsnaam']) ?></strong></span>
                                    <div class="order-header-meta">
                                        <span><i class="bi bi-calendar3"></i> <?= date('d-m-Y', strtotime($order['delivery_date'])) ?></span>
                                        <span><i class="bi bi-clock-history"></i> <?= date('d-m-Y', strtotime($order['created_at'])) ?></span>
                                    </div>
                                </div>
                                <div class="order-header-badges">
                                    <span class="order-header-amount">&euro;<?= number_format($order['total_amount'], 2, ',', '.') ?></span>
                                    <?php if (!empty($order['is_internal'])): ?>
                                        <span class="intern-badge"><i class="bi bi-shop"></i> Intern</span>
                                    <?php endif; ?>
                                    <?php if (!empty($order['settled_at'])): ?>
                                        <span class="settled-badge"><i class="bi bi-check-circle"></i> Afgehandeld</span>
                                    <?php endif; ?>
                                    <?php if ($order['is_cancelled']): ?>
                                        <span class="status-badge cancelled">Geannuleerd</span>
                                    <?php else: ?>
                                        <span class="status-badge <?= $order['payment_status'] ?>"><?= $order['payment_status'] === 'paid' ? 'Betaald' : 'In afwachting' ?></span>
                                        <span class="payment-type-badge <?= $order['payment_type'] ?>"><?= $order['payment_type'] === 'mollie_direct' ? 'Mollie' : ($order['payment_type'] === 'invoice' ? 'Factuur' : 'Contant') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="order-body">
                                <div class="order-info">
                                    <dl>
                                        <dt>Leverdatum</dt>
                                        <dd><?= getDutchDate($order['delivery_date']) ?></dd>
                                        <dt>Adres</dt>
                                        <dd>
                                            <?= htmlspecialchars($order['adres']) ?><br>
                                            <?= htmlspecialchars($order['postcode'] . ' ' . $order['plaats']) ?>
                                        </dd>
                                        <dt>Besteld op</dt>
                                        <dd><?= date('d-m-Y', strtotime($order['created_at'])) ?></dd>
                                    </dl>
                                </div>
                                <div class="order-items">
                                    <h4>Producten</h4>
                                    <?php foreach ($order['items'] as $item): ?>
                                        <div class="order-item">
                                            <span>
                                                <?= $item['quantity'] ?>x <?= htmlspecialchars($item['product_name']) ?>
                                                <?php if ($item['quantity_sold'] !== null): ?>
                                                    <span class="sold-info">(<?= $item['quantity_sold'] ?>/<?= $item['quantity'] ?> verkocht)</span>
                                                    <?php $rest = $item['quantity'] - $item['quantity_sold']; if ($rest > 0): ?>
                                                        <span class="remainder-info"><?= $rest ?> afgeschreven</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </span>
                                            <span>&euro;<?= number_format($item['quantity'] * $item['unit_price'], 2, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php
                                        $displayAmount = $order['settled_amount'] !== null ? $order['settled_amount'] : $order['total_amount'];
                                        $btwBedrag = berekenBtw($displayAmount, $btwTarief);
                                        $exclBtw = $displayAmount - $btwBedrag;
                                    ?>
                                    <div class="order-subtotal">
                                        <span>Excl. BTW</span>
                                        <span>&euro;<?= number_format($exclBtw, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="order-subtotal">
                                        <span>BTW (<?= $btwTarief ?>%)</span>
                                        <span>&euro;<?= number_format($btwBedrag, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="order-total">
                                        <span>Totaal incl. BTW</span>
                                        <span>&euro;<?= number_format($displayAmount, 2, ',', '.') ?></span>
                                    </div>
                                    <?php if ($order['settled_amount'] !== null && $order['settled_amount'] != $order['total_amount']): ?>
                                        <div style="font-size: 0.8rem; color: #888; margin-top: 0.3rem;">Productiewaarde: &euro;<?= number_format($order['total_amount'], 2, ',', '.') ?></div>
                                    <?php endif; ?>
                                    <?php if (empty($order['is_internal'])): ?>
                                        <a href="<?= '../../api/factuur.php?order_id=' . $order['id'] ?>" target="_blank" class="btn-factuur">Factuur</a>
                                    <?php elseif (!empty($order['settled_at'])): ?>
                                        <?php if (!empty($order['eboekhouden_invoice_id']) || !empty($order['invoice_number'])): ?>
                                            <a href="<?= '../../api/factuur.php?order_id=' . $order['id'] ?>" target="_blank" class="btn-factuur">Factuur <?= htmlspecialchars($order['eboekhouden_factuurnummer'] ?? $order['invoice_number'] ?? '') ?></a>
                                        <?php else: ?>
                                            <button type="button" class="btn-factuur" onclick="createInternalInvoice(<?= $order['id'] ?>)" style="cursor: pointer; border: none;">
                                                <i class="bi bi-receipt"></i> Factuur aanmaken
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #eee;">
                                    <button class="btn-edit-order" onclick="openEditModal(<?= $order['id'] ?>, <?= htmlspecialchars(json_encode($order['items']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($order['notes'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($order['delivery_date'] ?? ''), ENT_QUOTES) ?>, <?= !empty($order['is_internal']) ? 'true' : 'false' ?>)">
                                        <i class="bi bi-pencil"></i> Aanpassen
                                    </button>
                                    <form method="POST" style="display:inline;margin-left:0.5rem;" onsubmit="return handleDeleteConfirm(event, '<?= $order['id'] ?>', '<?= htmlspecialchars($order['bedrijfsnaam'], ENT_QUOTES) ?>')">
                                        <input type="hidden" name="delete_order" value="1">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <button type="submit" class="btn-delete-order"><i class="bi bi-trash"></i> Verwijderen</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        <button class="fab" onclick="openNewOrderModal()" title="Nieuwe bestelling">
            <i class="bi bi-plus-lg"></i>
        </button>
        </div>
    </div>

    <!-- Batch action bar -->
    <div class="batch-bar" id="batchBar">
        <span class="batch-bar-info"><span id="batchCount">0</span> bestelling(en) geselecteerd</span>
        <div class="batch-bar-actions">
            <button class="batch-btn batch-btn-deselect" onclick="deselectAllOrders()"><i class="bi bi-x"></i> Deselecteer</button>
            <button class="batch-btn batch-btn-paid" onclick="batchMarkPaid()"><i class="bi bi-check-circle"></i> Betaald</button>
            <button class="batch-btn batch-btn-cancel" onclick="batchCancelOrders()"><i class="bi bi-slash-circle"></i> Annuleren</button>
            <button class="batch-btn batch-btn-delete" onclick="batchDeleteOrders()"><i class="bi bi-trash"></i> Verwijderen</button>
        </div>
    </div>

    <!-- New order modal -->
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

    <!-- Settle modal -->
    <div class="modal-overlay" id="settleModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="settleModalTitle">Interne bestelling afhandelen</h3>
                <button class="modal-close" onclick="closeSettleModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 0.5rem;">
                    <div class="settle-item-row" style="font-weight: 600; border-bottom: 2px solid #e8e8e8;">
                        <span class="settle-item-name">Product</span>
                        <span class="settle-item-qty">Besteld</span>
                        <span class="settle-item-sold">Verkocht</span>
                        <span class="settle-item-rest">Restant</span>
                    </div>
                    <div id="settleItemsList"></div>
                </div>
                <div class="settle-summary">
                    <span>Werkelijke omzet</span>
                    <span id="settleTotal">&euro;0,00</span>
                </div>
                <div id="settleRemainderActions" class="settle-remainder-actions" style="display: none;">
                    <p style="width: 100%; margin: 0 0 0.5rem; font-size: 0.9rem; color: #666;">Er zijn onverkochte items. Wat wil je doen?</p>
                    <button class="btn-writeoff" onclick="settleWriteOff()"><i class="bi bi-x-circle"></i> Afschrijven</button>
                    <button class="btn-transfer" onclick="settleTransfer()"><i class="bi bi-arrow-right-circle"></i> Nieuwe bestelling van restant</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeSettleModal()">Annuleren</button>
                <button class="btn-save" id="settleSaveBtn" onclick="saveSettle()">Opslaan</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="editModal" onmousedown="this._md=(event.target===this)" onclick="if(event.target===this&&this._md)closeEditModal()">
        <div class="modal">
            <div class="modal-header">
                <h3 id="editModalTitle">Bestelling aanpassen</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 1rem;">
                    <label>Bakdag / Leverdatum</label>
                    <input type="date" class="form-control" id="editOrderDate" onchange="checkEditBakdag()">
                    <div class="bakdag-indicator" id="editBakdagIndicator" style="display:none;">
                        <span class="bakdag-ok"><i class="bi bi-check-circle-fill"></i> Dit is een bakdag</span>
                    </div>
                    <div class="bakdag-warning" id="editBakdagWarning" style="display:none;">
                        <i class="bi bi-exclamation-triangle-fill"></i> Dit is geen bakdag. Eerstvolgende bakdag: <strong id="editNextBakdag" onclick="selectEditNextBakdag()"></strong>
                        <button id="editBakdagAddBtn" onclick="addEditBakdagFromOrder()" style="display:none;margin-left:auto;padding:0.25rem 0.6rem;background:#ff6b35;color:white;border:none;border-radius:5px;font-size:0.8rem;font-weight:600;cursor:pointer;white-space:nowrap;"><i class="bi bi-plus"></i> Als bakdag instellen</button>
                    </div>
                </div>
                <div style="margin-bottom: 1rem;">
                    <label>Opmerkingen</label>
                    <textarea id="editNotes" placeholder="Opmerkingen voor deze bestelling"></textarea>
                </div>
                <label>Producten</label>
                <div class="edit-products-list" id="editProductsList"></div>
                <div class="edit-total-row">
                    <span>Totaal</span>
                    <span id="editTotal">&euro;0,00</span>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeEditModal()">Annuleren</button>
                <button class="btn-save" id="editSaveBtn" onclick="saveEditOrder()">Opslaan</button>
            </div>
        </div>
    </div>

    <script src="../../js/ui-notifications.js?v=1"></script>
    <script>
    function handleDeleteConfirm(event, orderId, bedrijfsnaam) {
        event.preventDefault();
        const form = event.target;
        showConfirm('Bestelling #' + orderId + ' van ' + bedrijfsnaam + ' definitief verwijderen?', 'Let op!').then(ok => {
            if (ok) form.submit();
        });
        return false;
    }

    let allProducts = [];
    let allCustomers = [];
    let allBakdagen = [];
    let newOrderProductIndex = 0;
    let editOrderId = null;
    let editItems = {};
    let settleOrderId = null;
    let settleItems = [];
    let settleRemainderItems = [];

    async function loadProducts() {
        try {
            const res = await fetch('../../api/admin-orders.php?action=products');
            const data = await res.json();
            if (data.success) allProducts = data.products;
        } catch(e) {
            console.error('Kon producten niet laden');
        }
    }
    loadProducts();

    // ===== New Order Functions =====

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

    function checkBakdag() {
        const date = document.getElementById('newOrderDate').value;
        const indicator = document.getElementById('bakdagIndicator');
        const warning = document.getElementById('bakdagWarning');

        if (!date) {
            indicator.style.display = 'none';
            warning.style.display = 'none';
            return;
        }

        const isInternal = document.getElementById('newOrderInternal').checked;
        const addBtn = document.getElementById('bakdagAddBtn');
        if (allBakdagen.includes(date)) {
            indicator.style.display = '';
            warning.style.display = 'none';
            if (addBtn) addBtn.style.display = 'none';
        } else {
            indicator.style.display = 'none';
            warning.style.display = '';
            const next = allBakdagen.find(d => d > date);
            document.getElementById('nextBakdag').textContent = next
                ? new Date(next + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'long', day: 'numeric', month: 'long'})
                : 'onbekend';
            if (addBtn) addBtn.style.display = isInternal ? '' : 'none';
        }

        // Refresh product rows to filter by available baking days
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

    function addBakdagFromOrder() {
        const date = document.getElementById('newOrderDate').value;
        if (!date) return;
        const dateLabel = new Date(date + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'long', day: 'numeric', month: 'long'});
        if (!confirm(`${dateLabel} als extra bakdag instellen?`)) return;
        fetch('../../api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_extra', datum: date, notitie: 'Interne bestelling' }) })
        .then(r => r.json()).then(data => {
            if (data.success) { allBakdagen.push(date); allBakdagen.sort(); showToast('Bakdag toegevoegd', 'success'); checkBakdag(); }
            else { showToast(data.error || 'Fout bij toevoegen bakdag', 'error'); }
        });
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

    async function openNewOrderModal(prefillDate, prefillItems) {
        await loadNewOrderData();

        document.getElementById('newOrderInternal').checked = false;
        onInternalToggle();

        const custSelect = document.getElementById('newOrderCustomer');
        custSelect.innerHTML = '<option value="">Selecteer een klant...</option>';
        allCustomers.filter(c => !c.is_internal).forEach(c => {
            custSelect.innerHTML += '<option value="' + c.id + '">' + escHtml(c.bedrijfsnaam) + ' (' + escHtml(c.contactpersoon) + ')</option>';
        });

        document.getElementById('newOrderDate').value = prefillDate || toLocalDateStr(new Date());
        document.getElementById('newOrderNotes').value = '';
        document.getElementById('newOrderProducts').innerHTML = '';
        newOrderProductIndex = 0;


        if (prefillItems && prefillItems.length > 0) {
            prefillItems.forEach(item => {
                addProductRowPrefilled(item.product_name, item.quantity, item.unit_price);
            });
        } else {
            addProductRow();
        }

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

        if (!customerId) { card.classList.remove('show'); return; }

        const customer = allCustomers.find(c => c.id == customerId);
        if (!customer) { card.classList.remove('show'); return; }

        document.getElementById('ciContact').textContent = customer.contactpersoon || '-';

        const phoneEl = document.getElementById('ciPhone');
        if (customer.telefoon) {
            phoneEl.innerHTML = '<a href="tel:' + escHtml(customer.telefoon) + '">' + escHtml(customer.telefoon) + '</a>';
        } else {
            phoneEl.textContent = '-';
        }

        const emailEl = document.getElementById('ciEmail');
        if (customer.email) {
            emailEl.innerHTML = '<a href="mailto:' + escHtml(customer.email) + '">' + escHtml(customer.email) + '</a>';
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

    function getEarliestDeliveryDate(recipeDays) {
        if (!recipeDays || recipeDays <= 0) recipeDays = 1;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        let count = 0;
        const d = new Date(today);
        let iterations = 0;
        while (count < recipeDays && iterations < 365) {
            if (allBakdagen.includes(toLocalDateStr(d))) count++;
            if (count < recipeDays) d.setDate(d.getDate() + 1);
            iterations++;
        }
        return toLocalDateStr(d);
    }

    function formatDateNL(dateStr) {
        return new Date(dateStr + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'short', day: 'numeric', month: 'short'});
    }

    function isProductAvailable(recipeDays) {
        if (document.getElementById('newOrderInternal').checked) return true;
        return getAvailableBakdagen() >= (recipeDays || 1);
    }

    function buildProductOptions() {
        const isInternal = document.getElementById('newOrderInternal').checked;
        const available = getAvailableBakdagen();
        let html = '<option value="">Kies product...</option>';
        allProducts.forEach(p => {
            const days = p.recipe_days || 1;
            const canMake = isInternal || days <= available;
            if (canMake) {
                html += '<option value="' + p.id + '">' + escHtml(p.naam) + '</option>';
            } else {
                const earliest = getEarliestDeliveryDate(days);
                html += '<option value="' + p.id + '" disabled style="color: #999;">' + escHtml(p.naam) + ' \u2014 pas vanaf ' + formatDateNL(earliest) + ' (Bakproces: ' + days + ' dagen)</option>';
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

    function addProductRow() {
        const container = document.getElementById('newOrderProducts');
        const idx = newOrderProductIndex++;

        const row = document.createElement('div');
        row.className = 'product-select-row';
        row.innerHTML =
            '<select class="form-control product-select" data-idx="' + idx + '" onchange="onProductSelect(this)">' + buildProductOptions() + '</select>' +
            '<select class="form-control variant-select" data-idx="' + idx + '" onchange="onVariantSelect(this)" style="display:none;"></select>' +
            '<input type="number" class="form-control product-qty" data-idx="' + idx + '" min="1" value="1" onchange="updateNewOrderTotal()" oninput="updateNewOrderTotal()">' +
            '<span class="product-price" data-idx="' + idx + '">\u20AC0,00</span>' +
            '<button type="button" class="btn-remove" onclick="removeProductRow(this)"><i class="bi bi-x"></i></button>';
        container.appendChild(row);
    }

    function addProductRowPrefilled(productName, quantity, unitPrice) {
        const container = document.getElementById('newOrderProducts');
        const idx = newOrderProductIndex++;

        const row = document.createElement('div');
        row.className = 'product-select-row';
        row.innerHTML =
            '<input type="text" class="form-control" data-idx="' + idx + '" value="' + escAttr(productName) + '" readonly style="flex: 3; background: #f5f5f5;">' +
            '<input type="hidden" class="prefill-name" value="' + escAttr(productName) + '">' +
            '<input type="hidden" class="prefill-price" value="' + unitPrice + '">' +
            '<input type="number" class="form-control product-qty" data-idx="' + idx + '" min="1" value="' + quantity + '" onchange="updateNewOrderTotal()" oninput="updateNewOrderTotal()">' +
            '<span class="product-price" data-idx="' + idx + '">\u20AC' + parseFloat(unitPrice).toFixed(2).replace('.', ',') + '</span>' +
            '<button type="button" class="btn-remove" onclick="removeProductRow(this)"><i class="bi bi-x"></i></button>';
        container.appendChild(row);
    }

    function removeProductRow(btn) {
        btn.closest('.product-select-row').remove();
        updateNewOrderTotal();
    }

    function onProductSelect(select) {
        const idx = select.dataset.idx;
        const productId = parseInt(select.value);
        const variantSelect = document.querySelector('.variant-select[data-idx="' + idx + '"]');
        const priceEl = document.querySelector('.product-price[data-idx="' + idx + '"]');

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
                    variantOptions += '<option value="' + v.id + '" data-price="' + v.prijs + '" data-weight="' + v.gewicht + '" data-naam="' + escAttr(v.naam || '') + '">' + escHtml(label) + ' (\u20AC' + parseFloat(v.prijs).toFixed(2).replace('.', ',') + ')</option>';
                } else {
                    const earliest = getEarliestDeliveryDate(days);
                    variantOptions += '<option value="' + v.id + '" disabled style="color: #999;">' + escHtml(label) + ' \u2014 pas vanaf ' + formatDateNL(earliest) + ' (Bakproces: ' + days + ' dagen)</option>';
                }
            });
            variantSelect.innerHTML = variantOptions;
            variantSelect.style.display = '';
            if (isInternal && firstAvailableVariant) {
                variantSelect.value = firstAvailableVariant.id;
                priceEl.textContent = '\u20AC' + parseFloat(firstAvailableVariant.prijs).toFixed(2).replace('.', ',');
            } else {
                priceEl.textContent = '\u20AC0,00';
            }
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
        const priceEl = document.querySelector('.product-price[data-idx="' + idx + '"]');
        priceEl.textContent = '\u20AC' + price.toFixed(2).replace('.', ',');
        updateNewOrderTotal();
    }

    function updateNewOrderTotal() {
        let total = 0;
        document.querySelectorAll('#newOrderProducts .product-select-row').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            const variantSelect = row.querySelector('.variant-select');
            const prefillPrice = row.querySelector('.prefill-price');
            const qty = parseInt(row.querySelector('.product-qty').value) || 0;

            let price = 0;
            if (prefillPrice) {
                price = parseFloat(prefillPrice.value || 0);
            } else if (productSelect) {
                const productId = parseInt(productSelect.value);
                if (productId) {
                    const product = allProducts.find(p => p.id == productId);
                    if (product && product.variants && product.variants.length > 0 && variantSelect && variantSelect.value) {
                        const option = variantSelect.options[variantSelect.selectedIndex];
                        price = parseFloat(option?.dataset?.price || 0);
                    } else if (product && (!product.variants || product.variants.length === 0)) {
                        price = parseFloat(product.prijs || 0);
                    }
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

        if (!isInternal && !accountId) { showToast('Selecteer een klant', 'error'); return; }
        if (isInternal && !accountId) { showToast('Intern account niet gevonden. Voer eerst migration 028 uit.', 'error'); return; }
        if (!deliveryDate) { showToast('Selecteer een leverdatum', 'error'); return; }

        // Validate variant required for internal orders
        if (isInternal) {
            let missingVariant = null;
            document.querySelectorAll('#newOrderProducts .product-select-row').forEach(row => {
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
        document.querySelectorAll('#newOrderProducts .product-select-row').forEach(row => {
            const productSelect = row.querySelector('.product-select');
            const variantSelect = row.querySelector('.variant-select');
            const prefillName = row.querySelector('.prefill-name');
            const prefillPrice = row.querySelector('.prefill-price');
            const qty = parseInt(row.querySelector('.product-qty').value) || 0;

            if (qty <= 0) return;

            if (prefillName) {
                items.push({ product_name: prefillName.value, quantity: qty, unit_price: parseFloat(prefillPrice.value) });
                return;
            }

            const productId = parseInt(productSelect?.value);
            if (!productId) return;

            const product = allProducts.find(p => p.id == productId);
            if (!product) return;

            let productName = product.naam;
            let price = parseFloat(product.prijs || 0);

            if (product.variants && product.variants.length > 0 && variantSelect && variantSelect.value) {
                const variantOption = variantSelect.options[variantSelect.selectedIndex];
                const weight = variantOption.dataset.weight;
                price = parseFloat(variantOption.dataset.price || 0);
                const variantNaam = variantOption.dataset.naam;
                if (variantNaam) {
                    productName = product.naam + ' - ' + variantNaam + ' (' + weight + 'g)';
                } else {
                    productName = product.naam + ' (' + weight + 'g)';
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

        if (items.length === 0) { showToast('Voeg minimaal \u00e9\u00e9n product toe', 'error'); return; }

        await doSubmitOrder(isInternal, accountId, deliveryDate, items, notes, false);
    }

    async function doSubmitOrder(isInternal, accountId, deliveryDate, items, notes, confirmOverride) {
        const payload = {
            account_id: parseInt(accountId),
            delivery_date: deliveryDate,
            items: items,
            notes: notes
        };
        if (isInternal) payload.is_internal = true;
        if (confirmOverride) payload.confirm_override = true;

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
                setTimeout(() => location.reload(), 1500);
            } else if (data.needs_confirm) {
                // Bakeday warning — ask user to confirm
                const ok = await showConfirm(data.warning, 'Bakdag waarschuwing');
                if (ok) {
                    await doSubmitOrder(isInternal, accountId, deliveryDate, items, notes, true);
                }
            } else {
                showToast(data.error || 'Onbekende fout', 'error');
            }
        } catch (e) {
            console.error('Error:', e);
            showToast('Er ging iets mis bij het plaatsen van de bestelling', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Bestelling plaatsen';
        }
    }

    document.getElementById('newOrderModal').addEventListener('mousedown', function(e) { this._md = e.target === this; });
    document.getElementById('newOrderModal').addEventListener('click', function(e) { if (e.target === this && this._md) closeNewOrderModal(); });

    // ===== Settle Functions =====

    function openSettleModal(orderId, items) {
        settleOrderId = orderId;
        settleItems = items;
        settleRemainderItems = [];

        document.getElementById('settleModalTitle').textContent = 'Interne bestelling #' + orderId + ' afhandelen';
        document.getElementById('settleRemainderActions').style.display = 'none';

        let html = '';
        items.forEach(item => {
            html += '<div class="settle-item-row">' +
                '<span class="settle-item-name">' + escHtml(item.product_name) + '</span>' +
                '<span class="settle-item-qty">' + item.quantity + '</span>' +
                '<span class="settle-item-sold"><input type="number" min="0" max="' + item.quantity + '" value="' + item.quantity + '" data-item-id="' + item.id + '" data-price="' + item.unit_price + '" data-qty="' + item.quantity + '" oninput="updateSettleTotals()"></span>' +
                '<span class="settle-item-rest" data-rest-for="' + item.id + '">0</span>' +
                '</div>';
        });
        document.getElementById('settleItemsList').innerHTML = html;

        updateSettleTotals();
        document.getElementById('settleModal').classList.add('active');
    }

    function closeSettleModal() {
        document.getElementById('settleModal').classList.remove('active');
        settleOrderId = null;
    }

    document.getElementById('settleModal').addEventListener('mousedown', function(e) { this._md = e.target === this; });
    document.getElementById('settleModal').addEventListener('click', function(e) { if (e.target === this && this._md) closeSettleModal(); });

    function updateSettleTotals() {
        let total = 0;
        let hasRemainder = false;
        settleRemainderItems = [];

        document.querySelectorAll('#settleItemsList input[type="number"]').forEach(input => {
            const sold = parseInt(input.value) || 0;
            const qty = parseInt(input.dataset.qty);
            const price = parseFloat(input.dataset.price);
            const itemId = input.dataset.itemId;
            const rest = qty - sold;

            const restEl = document.querySelector('[data-rest-for="' + itemId + '"]');
            restEl.textContent = rest;
            restEl.classList.toggle('has-remainder', rest > 0);

            total += sold * price;

            if (rest > 0) {
                hasRemainder = true;
                const itemData = settleItems.find(i => i.id == itemId);
                settleRemainderItems.push({
                    product_name: itemData ? itemData.product_name : '',
                    quantity: rest,
                    unit_price: price
                });
            }
        });

        document.getElementById('settleTotal').textContent = '\u20AC' + total.toFixed(2).replace('.', ',');
        document.getElementById('settleRemainderActions').style.display = hasRemainder ? '' : 'none';
    }

    async function saveSettle() {
        const items = [];
        document.querySelectorAll('#settleItemsList input[type="number"]').forEach(input => {
            items.push({
                item_id: parseInt(input.dataset.itemId),
                quantity_sold: parseInt(input.value) || 0
            });
        });

        const btn = document.getElementById('settleSaveBtn');
        btn.disabled = true;
        btn.textContent = 'Opslaan...';

        try {
            const response = await fetch('../../api/admin-orders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'settle_internal',
                    order_id: settleOrderId,
                    items: items
                })
            });
            const data = await response.json();

            if (data.success) {
                showToast(data.message, 'success');
                closeSettleModal();
                location.reload();
            } else {
                showToast(data.error || 'Er ging iets mis', 'error');
            }
        } catch (e) {
            console.error('Error:', e);
            showToast('Er ging iets mis bij het opslaan', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Opslaan';
        }
    }

    async function settleWriteOff() {
        if (await showConfirm('Weet je zeker dat je de resterende items wilt afschrijven?')) {
            saveSettle();
        }
    }

    async function settleTransfer() {
        if (settleRemainderItems.length === 0) return;

        // First save the settlement
        const items = [];
        document.querySelectorAll('#settleItemsList input[type="number"]').forEach(input => {
            items.push({
                item_id: parseInt(input.dataset.itemId),
                quantity_sold: parseInt(input.value) || 0
            });
        });

        const btn = document.getElementById('settleSaveBtn');
        btn.disabled = true;

        try {
            const response = await fetch('../../api/admin-orders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'settle_internal',
                    order_id: settleOrderId,
                    items: items
                })
            });
            const data = await response.json();

            if (data.success) {
                closeSettleModal();
                // Open new order modal with remainder items prefilled
                openNewOrderModal(null, settleRemainderItems);
            } else {
                showToast(data.error || 'Er ging iets mis', 'error');
            }
        } catch (e) {
            console.error('Error:', e);
            showToast('Er ging iets mis bij het opslaan', 'error');
        } finally {
            btn.disabled = false;
        }
    }

    // ===== Internal Invoice Function =====

    async function createInternalInvoice(orderId) {
        if (!await showConfirm('Factuur aanmaken voor interne bestelling #' + orderId + '? De factuur wordt gebaseerd op de verkochte aantallen.')) return;

        const btn = event.target.closest('button');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig...';
        }

        try {
            const response = await fetch('../../api/admin-orders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create_invoice_internal',
                    order_id: orderId
                })
            });
            const data = await response.json();

            if (data.success) {
                showToast(data.message, 'success');
                location.reload();
            } else {
                showToast(data.error || 'Er ging iets mis bij het aanmaken van de factuur', 'error');
            }
        } catch (e) {
            console.error('Error:', e);
            showToast('Er ging iets mis bij het aanmaken van de factuur', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-receipt"></i> Factuur aanmaken';
            }
        }
    }

    let editIsInternal = false;

    function openEditModal(orderId, currentItems, notes, deliveryDate, isInternal) {
        editOrderId = orderId;
        editIsInternal = !!isInternal;
        editItems = {};

        currentItems.forEach(item => {
            const key = item.product_name.toLowerCase();
            editItems[key] = {
                product_name: item.product_name,
                quantity: parseInt(item.quantity) || 0,
                unit_price: parseFloat(item.unit_price) || 0,
                variant_id: item.variant_id || null,
                product_id: item.product_id || null
            };
        });

        document.getElementById('editModalTitle').textContent = 'Bestelling #' + orderId + ' aanpassen';
        document.getElementById('editNotes').value = notes || '';
        document.getElementById('editOrderDate').value = deliveryDate || '';
        checkEditBakdag();

        renderEditProducts();
        document.getElementById('editModal').classList.add('active');
    }

    function checkEditBakdag() {
        const date = document.getElementById('editOrderDate').value;
        const indicator = document.getElementById('editBakdagIndicator');
        const warning = document.getElementById('editBakdagWarning');
        const addBtn = document.getElementById('editBakdagAddBtn');
        if (!date) { indicator.style.display = 'none'; warning.style.display = 'none'; return; }
        if (allBakdagen.includes(date)) {
            indicator.style.display = '';
            warning.style.display = 'none';
            if (addBtn) addBtn.style.display = 'none';
        } else {
            indicator.style.display = 'none';
            warning.style.display = '';
            const next = allBakdagen.find(d => d > date);
            document.getElementById('editNextBakdag').textContent = next
                ? new Date(next + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'long', day: 'numeric', month: 'long'})
                : 'onbekend';
            if (addBtn) addBtn.style.display = editIsInternal ? '' : 'none';
        }
    }

    function selectEditNextBakdag() {
        const next = allBakdagen.find(d => d > document.getElementById('editOrderDate').value);
        if (next) { document.getElementById('editOrderDate').value = next; checkEditBakdag(); }
    }

    function addEditBakdagFromOrder() {
        const date = document.getElementById('editOrderDate').value;
        if (!date) return;
        const dateLabel = new Date(date + 'T00:00').toLocaleDateString('nl-NL', {weekday: 'long', day: 'numeric', month: 'long'});
        if (!confirm(`${dateLabel} als extra bakdag instellen?`)) return;
        fetch('../../api/bakdagen.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_extra', datum: date, notitie: 'Interne bestelling' }) })
        .then(r => r.json()).then(data => {
            if (data.success) { allBakdagen.push(date); allBakdagen.sort(); showToast('Bakdag toegevoegd', 'success'); checkEditBakdag(); }
            else { showToast(data.error || 'Fout bij toevoegen bakdag', 'error'); }
        });
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.remove('active');
        editOrderId = null;
    }

    function renderEditProducts() {
        const container = document.getElementById('editProductsList');
        let html = '';

        allProducts.forEach(p => {
            const variants = p.variants && p.variants.length > 0 ? p.variants : null;
            if (variants) {
                variants.forEach(v => {
                    const label = p.naam + ' - ' + (v.naam || '') + ' (' + v.gewicht + 'g)';
                    const key = label.toLowerCase();
                    const qty = editItems[key] ? editItems[key].quantity : 0;
                    const prijs = parseFloat(v.prijs) || 0;

                    html += '<div class="edit-product-row' + (qty > 0 ? ' in-order' : '') + '" data-key="' + escAttr(key) + '">';
                    html += '<div class="edit-product-info">';
                    html += '<div class="edit-product-name">' + escHtml(label) + '</div>';
                    html += '<div class="edit-product-price">&euro;' + prijs.toFixed(2).replace('.', ',') + '</div>';
                    html += '</div>';
                    html += '<div class="edit-product-controls">';
                    html += '<button class="qty-btn" onclick="changeQty(\'' + escAttr(key) + '\', \'' + escAttr(label) + '\', ' + prijs + ', -1, ' + (v.id || 'null') + ', ' + (p.id || 'null') + ')">-</button>';
                    html += '<span class="qty-display">' + qty + '</span>';
                    html += '<button class="qty-btn" onclick="changeQty(\'' + escAttr(key) + '\', \'' + escAttr(label) + '\', ' + prijs + ', 1, ' + (v.id || 'null') + ', ' + (p.id || 'null') + ')">+</button>';
                    html += '</div></div>';
                });
            } else {
                const key = p.naam.toLowerCase();
                const qty = editItems[key] ? editItems[key].quantity : 0;
                const prijs = parseFloat(p.prijs) || 0;

                html += '<div class="edit-product-row' + (qty > 0 ? ' in-order' : '') + '" data-key="' + escAttr(key) + '">';
                html += '<div class="edit-product-info">';
                html += '<div class="edit-product-name">' + escHtml(p.naam) + '</div>';
                html += '<div class="edit-product-price">&euro;' + prijs.toFixed(2).replace('.', ',') + '</div>';
                html += '</div>';
                html += '<div class="edit-product-controls">';
                html += '<button class="qty-btn" onclick="changeQty(\'' + escAttr(key) + '\', \'' + escAttr(p.naam) + '\', ' + prijs + ', -1, null, ' + (p.id || 'null') + ')">-</button>';
                html += '<span class="qty-display">' + qty + '</span>';
                html += '<button class="qty-btn" onclick="changeQty(\'' + escAttr(key) + '\', \'' + escAttr(p.naam) + '\', ' + prijs + ', 1, null, ' + (p.id || 'null') + ')">+</button>';
                html += '</div></div>';
            }
        });

        container.innerHTML = html;
        updateEditTotal();
    }

    function changeQty(key, name, price, delta, variantId, productId) {
        if (!editItems[key]) {
            editItems[key] = { product_name: name, quantity: 0, unit_price: price, variant_id: variantId || null, product_id: productId || null };
        }
        editItems[key].quantity = Math.max(0, editItems[key].quantity + delta);
        if (editItems[key].quantity === 0) delete editItems[key];
        renderEditProducts();
    }

    function updateEditTotal() {
        let total = 0;
        Object.values(editItems).forEach(item => {
            total += item.quantity * item.unit_price;
        });
        document.getElementById('editTotal').textContent = '\u20AC' + total.toFixed(2).replace('.', ',');
    }

    async function saveEditOrder() {
        const items = Object.values(editItems).filter(i => i.quantity > 0);
        if (items.length === 0) {
            showToast('Voeg minimaal \u00e9\u00e9n product toe', 'error');
            return;
        }

        const btn = document.getElementById('editSaveBtn');
        btn.disabled = true;
        btn.textContent = 'Opslaan...';

        try {
            const res = await fetch('../../api/admin-orders.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    order_id: editOrderId,
                    items: items,
                    notes: document.getElementById('editNotes').value,
                    delivery_date: document.getElementById('editOrderDate').value || null
                })
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                closeEditModal();
                location.reload();
            } else {
                showToast(data.error || 'Kon niet opslaan', 'error');
            }
        } catch(e) {
            showToast('Er ging iets mis', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Opslaan';
        }
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        return str.replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    const activeFilters = {
        paid: true,
        pending: true,
        factuur: true,
        ideal: true
    };

    function toggleFilter(btn) {
        const filter = btn.dataset.filter;
        
        if (filter === 'all') {
            const allActive = Object.values(activeFilters).every(v => v);
            Object.keys(activeFilters).forEach(key => activeFilters[key] = !allActive);
            document.querySelectorAll('.filter-btn:not([data-filter="all"])').forEach(b => {
                b.classList.toggle('active', !allActive);
            });
            btn.classList.toggle('active', !allActive);
        } else {
            activeFilters[filter] = !activeFilters[filter];
            btn.classList.toggle('active');
            
            const allBtn = document.querySelector('.filter-btn[data-filter="all"]');
            const allActive = Object.values(activeFilters).every(v => v);
            allBtn.classList.toggle('active', allActive);
        }
        
        applyFilters();
    }

    function applyFilters() {
        const query = (document.getElementById('searchInput').value || '').toLowerCase().trim();
        document.querySelectorAll('.order-card').forEach(card => {
            const paymentStatus = card.dataset.paymentStatus;
            const paymentType = card.dataset.paymentType;
            const searchData = card.dataset.search || '';
            
            let matchesFilter = false;
            if (!paymentStatus) {
                matchesFilter = true;
            } else {
                if (activeFilters.paid && paymentStatus === 'paid') matchesFilter = true;
                if (activeFilters.pending && paymentStatus === 'pending') matchesFilter = true;
                if (activeFilters.factuur && (paymentType === 'invoice' || paymentType === 'factuur')) matchesFilter = true;
                if (activeFilters.ideal && (paymentType === 'ideal' || paymentType === 'mollie_direct')) matchesFilter = true;
            }
            
            const matchesSearch = !query || searchData.includes(query);
            
            card.style.display = (matchesFilter && matchesSearch) ? '' : 'none';
        });
    }

    function applySearch() {
        applyFilters();
    }

    // ===== Multi-select batch operations =====
    let selectedOrderIds = [];

    function toggleSelectAll(checked) {
        selectedOrderIds = [];
        document.querySelectorAll('.order-card[data-order-id]').forEach(card => {
            if (card.style.display === 'none') return; // skip filtered-out
            const id = parseInt(card.dataset.orderId);
            const cb = card.querySelector('.order-select');
            if (checked) {
                selectedOrderIds.push(id);
                card.classList.add('selected');
                if (cb) cb.checked = true;
            } else {
                card.classList.remove('selected');
                if (cb) cb.checked = false;
            }
        });
        updateBatchBar();
    }

    function toggleOrderSelect(orderId) {
        const idx = selectedOrderIds.indexOf(orderId);
        if (idx >= 0) selectedOrderIds.splice(idx, 1);
        else selectedOrderIds.push(orderId);
        const card = document.querySelector('.order-card[data-order-id="' + orderId + '"]');
        if (card) card.classList.toggle('selected', selectedOrderIds.includes(orderId));
        updateBatchBar();
    }

    function updateBatchBar() {
        const bar = document.getElementById('batchBar');
        bar.classList.toggle('show', selectedOrderIds.length > 0);
        document.getElementById('batchCount').textContent = selectedOrderIds.length;
    }

    function deselectAllOrders() {
        selectedOrderIds = [];
        document.querySelectorAll('.order-select').forEach(cb => cb.checked = false);
        document.querySelectorAll('.order-card.selected').forEach(el => el.classList.remove('selected'));
        document.getElementById('selectAll').checked = false;
        updateBatchBar();
    }

    async function batchMarkPaid() {
        if (selectedOrderIds.length === 0) return;
        if (!await showConfirm(selectedOrderIds.length + ' bestelling(en) als betaald markeren?')) return;
        const form = new FormData();
        let count = 0;
        for (const orderId of selectedOrderIds) {
            try {
                const fd = new FormData();
                fd.append('update_status', '1');
                fd.append('order_id', orderId);
                fd.append('payment_status', 'paid');
                await fetch('orders.php', { method: 'POST', body: fd });
                count++;
            } catch (e) { console.error('Error updating order ' + orderId, e); }
        }
        if (count > 0) window.location.href = 'orders.php?updated=1';
    }

    async function batchCancelOrders() {
        if (selectedOrderIds.length === 0) return;
        if (!await showConfirm(selectedOrderIds.length + ' bestelling(en) annuleren?')) return;
        let count = 0;
        for (const orderId of selectedOrderIds) {
            try {
                const fd = new FormData();
                fd.append('update_status', '1');
                fd.append('order_id', orderId);
                fd.append('payment_status', 'pending');
                fd.append('is_cancelled', '1');
                await fetch('orders.php', { method: 'POST', body: fd });
                count++;
            } catch (e) { console.error('Error cancelling order ' + orderId, e); }
        }
        if (count > 0) window.location.href = 'orders.php?updated=1';
    }

    async function batchDeleteOrders() {
        if (selectedOrderIds.length === 0) return;
        if (!await showConfirm(selectedOrderIds.length + ' bestelling(en) DEFINITIEF verwijderen? Dit kan niet ongedaan worden!', 'Let op!')) return;
        let count = 0;
        for (const orderId of selectedOrderIds) {
            try {
                const fd = new FormData();
                fd.append('delete_order', '1');
                fd.append('order_id', orderId);
                await fetch('orders.php', { method: 'POST', body: fd });
                count++;
            } catch (e) { console.error('Error deleting order ' + orderId, e); }
        }
        if (count > 0) window.location.href = 'orders.php?deleted=1';
    }
    </script>
</body>
</html>
