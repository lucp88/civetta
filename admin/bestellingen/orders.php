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
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll();
}
unset($order);

foreach ($completedOrders as &$order) {
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll();
}
unset($order);

$totalUpcoming = array_sum(array_column($upcomingOrders, 'total_amount'));
$totalCompleted = array_sum(array_column($completedOrders, 'total_amount'));
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bestellingen | Civetta Admin</title>
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
        .header h1 { font-size: 1.5rem; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
        }
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .breadcrumb {
            margin-bottom: 1.5rem;
        }
        .breadcrumb a {
            color: #8b5a2b;
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
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
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
            color: #5c3d1e;
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
        }
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
            color: #5c3d1e;
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
            background: #8b5a2b;
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .btn-update:hover { background: #5c3d1e; }
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
            border-color: #8b5a2b;
            color: #8b5a2b;
        }
        .filter-btn.active {
            background: #8b5a2b;
            border-color: #8b5a2b;
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
    </style>
</head>
<body>
    <div class="header">
        <h1>Civetta Admin</h1>
        <a href="../logout.php">Uitloggen</a>
    </div>
    
    <div class="container">
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
            <h2>Lopende Bestellingen</h2>
            
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
                        <div class="order-card" data-payment-status="<?= $order['payment_status'] ?>" data-payment-type="<?= $order['payment_type'] ?>">
                            <div class="order-header">
                                <div>
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="customer">— <strong><?= htmlspecialchars($order['bedrijfsnaam']) ?></strong> (<?= htmlspecialchars($order['contactpersoon']) ?>)</span>
                                </div>
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
                                            <span><?= $item['quantity'] ?>x <?= htmlspecialchars($item['product_name']) ?></span>
                                            <span>€<?= number_format($item['quantity'] * $item['unit_price'], 2, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php 
                                        $btwBedrag = berekenBtw($order['total_amount'], $btwTarief);
                                        $exclBtw = $order['total_amount'] - $btwBedrag;
                                    ?>
                                    <div class="order-subtotal">
                                        <span>Excl. BTW</span>
                                        <span>€<?= number_format($exclBtw, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="order-subtotal">
                                        <span>BTW (<?= $btwTarief ?>%)</span>
                                        <span>€<?= number_format($btwBedrag, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="order-total">
                                        <span>Totaal incl. BTW</span>
                                        <span>€<?= number_format($order['total_amount'], 2, ',', '.') ?></span>
                                    </div>
                                    <a href="<?= '../../api/factuur.php?order_id=' . $order['id'] ?>" target="_blank" class="btn-factuur">Factuur</a>
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
                        <div class="order-card">
                            <div class="order-header">
                                <div>
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="customer">— <strong><?= htmlspecialchars($order['bedrijfsnaam']) ?></strong></span>
                                </div>
                                <?php if ($order['is_cancelled']): ?>
                                    <span class="status-badge cancelled">Geannuleerd</span>
                                <?php else: ?>
                                    <span class="status-badge <?= $order['payment_status'] ?>"><?= $order['payment_status'] === 'paid' ? 'Betaald' : 'In afwachting' ?></span>
                                    <span class="payment-type-badge <?= $order['payment_type'] ?>"><?= $order['payment_type'] === 'mollie_direct' ? 'Mollie' : ($order['payment_type'] === 'invoice' ? 'Factuur' : 'Contant') ?></span>
                                <?php endif; ?>
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
                                            <span><?= $item['quantity'] ?>x <?= htmlspecialchars($item['product_name']) ?></span>
                                            <span>€<?= number_format($item['quantity'] * $item['unit_price'], 2, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php 
                                        $btwBedrag = berekenBtw($order['total_amount'], $btwTarief);
                                        $exclBtw = $order['total_amount'] - $btwBedrag;
                                    ?>
                                    <div class="order-subtotal">
                                        <span>Excl. BTW</span>
                                        <span>€<?= number_format($exclBtw, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="order-subtotal">
                                        <span>BTW (<?= $btwTarief ?>%)</span>
                                        <span>€<?= number_format($btwBedrag, 2, ',', '.') ?></span>
                                    </div>
                                    <div class="order-total">
                                        <span>Totaal incl. BTW</span>
                                        <span>€<?= number_format($order['total_amount'], 2, ',', '.') ?></span>
                                    </div>
                                    <a href="<?= '../../api/factuur.php?order_id=' . $order['id'] ?>" target="_blank" class="btn-factuur">Factuur</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
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
        document.querySelectorAll('.order-card').forEach(card => {
            const paymentStatus = card.dataset.paymentStatus;
            const paymentType = card.dataset.paymentType;
            
            let show = false;
            
            if (activeFilters.paid && paymentStatus === 'paid') show = true;
            if (activeFilters.pending && paymentStatus === 'pending') show = true;
            if (activeFilters.factuur && (paymentType === 'invoice' || paymentType === 'factuur')) show = true;
            if (activeFilters.ideal && (paymentType === 'ideal' || paymentType === 'mollie_direct')) show = true;
            
            card.style.display = show ? '' : 'none';
        });
    }
    </script>
</body>
</html>
