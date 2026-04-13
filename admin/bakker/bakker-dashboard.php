<?php
require_once '../config.php';
requireLogin();

$today = date('Y-m-d');

// Load bakdagen configuration
$bakdagenPatroonStr = '';
$stmtBp = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_patroon'");
$stmtBp->execute();
$bakdagenPatroonStr = $stmtBp->fetchColumn() ?: '';
$bakdagenPatroon = $bakdagenPatroonStr ? array_map('intval', explode(',', $bakdagenPatroonStr)) : [];

$stmtExtra = $pdo->prepare("SELECT datum FROM bakdagen_extra WHERE datum BETWEEN ? AND ? ORDER BY datum");
$stmtExtra->execute([$today, date('Y-m-d', strtotime('+14 days'))]);
$extraDatums = array_column($stmtExtra->fetchAll(), 'datum');

// Load voorbereiding_dagen setting
$stmtVb = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
$stmtVb->execute();
$voorbereidingDagen = (int)($stmtVb->fetchColumn() ?: 3);

// Check if today is a baking day
$todayWeekday = (int)(new DateTime($today))->format('N');
$todayIsBakdag = in_array($todayWeekday, $bakdagenPatroon) || in_array($today, $extraDatums);

// Helper: check if a date is a bakdag
function isBakdag($dateStr, $patroon, $extra) {
    $wd = (int)(new DateTime($dateStr))->format('N');
    return in_array($wd, $patroon) || in_array($dateStr, $extra);
}

// Helper: count bakdagen between today and a given date (inclusive)
function countBakdagenBetween($todayStr, $targetStr, $patroon, $extra) {
    $count = 0;
    $d = new DateTime($todayStr);
    $target = new DateTime($targetStr);
    while ($d <= $target) {
        if (isBakdag($d->format('Y-m-d'), $patroon, $extra)) {
            $count++;
        }
        $d->modify('+1 day');
    }
    return $count;
}

// Find next POSSIBLE baking day (enough bakdagen lead time for new orders)
$nextBakdag = null;
$nextBakdagDt = null;
for ($d = 1; $d <= 30; $d++) {
    $checkDate = date('Y-m-d', strtotime("+{$d} days"));
    if (isBakdag($checkDate, $bakdagenPatroon, $extraDatums)) {
        $bakdagenCount = countBakdagenBetween($today, $checkDate, $bakdagenPatroon, $extraDatums);
        if ($bakdagenCount >= $voorbereidingDagen) {
            $nextBakdag = $checkDate;
            $nextBakdagDt = new DateTime($checkDate);
            break;
        }
    }
}

// Baking day = delivery day. Show today's orders for baking if today is a bakdag
$bakdagDate = $todayIsBakdag ? $today : ($nextBakdag ?: $today);

$stmt = $pdo->prepare("
    SELECT COUNT(*) as count, SUM(total_amount) as total
    FROM business_orders
    WHERE delivery_date = ? AND is_cancelled = 0
");
$stmt->execute([$today]);
$todayDeliveries = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT COUNT(*) as count
    FROM business_orders
    WHERE delivery_date = ? AND is_cancelled = 0
");
$stmt->execute([$bakdagDate]);
$todayBereiding = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT bo.*, ba.bedrijfsnaam
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    WHERE bo.delivery_date = ? AND bo.is_cancelled = 0
    ORDER BY bo.id
    LIMIT 5
");
$stmt->execute([$today]);
$upcomingDeliveries = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        COALESCE(dt.name, 'Geen deegsoort') as dough_type_name,
        SUM(boi.quantity * COALESCE(pv.dough_weight, 0)) as total_dough
    FROM business_order_items boi
    JOIN business_orders bo ON boi.order_id = bo.id
    LEFT JOIN product_variants pv ON boi.variant_id = pv.id
    LEFT JOIN products p ON COALESCE(boi.product_id, pv.product_id) = p.id
    LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
    LEFT JOIN dough_types dt ON br.dough_type_id = dt.id
    WHERE bo.delivery_date = ? AND bo.is_cancelled = 0
    GROUP BY dt.id, dt.name
    HAVING total_dough > 0
    ORDER BY total_dough DESC
");
$stmt->execute([$bakdagDate]);
$doughToBake = $stmt->fetchAll();

$dutchDayNames = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = ? AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute([$today]);
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Bakker Dashboard';
$currentPage = 'bakker-dashboard';
$adminBasePath = '../';

function getGreeting() {
    $hour = (int)date('H');
    if ($hour < 12) return 'Goedemorgen';
    if ($hour < 18) return 'Goedemiddag';
    return 'Goedenavond';
}
ob_start(); ?>
<style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--cream);
            color: var(--text-primary);
            min-height: 100vh;
        }

        .admin-content {
            padding: 2rem;
        }

        .page-header {
            margin-bottom: 2rem;
            text-align: center;
        }

        .page-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.3px;
        }

        .page-header p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }

        .action-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.75rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            border: 2px solid var(--border);
            display: block;
            position: relative;
            overflow: hidden;
        }

        .action-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .action-card.bereiden {
            background: linear-gradient(135deg, #fff8f5, #ffede5);
            border-color: #ffccbc;
        }

        .action-card.bereiden:hover {
            border-color: #ff6b35;
        }

        .action-card.leveren {
            background: linear-gradient(135deg, #f0f7ff, #e1efff);
            border-color: #90caf9;
        }

        .action-card.leveren:hover {
            border-color: #2196f3;
        }

        .action-card-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
            font-size: 1.5rem;
            color: white;
        }

        .action-card.bereiden .action-card-icon {
            background: linear-gradient(135deg, #ff6b35, #e55a2b);
        }

        .action-card.leveren .action-card-icon {
            background: linear-gradient(135deg, #2196f3, #1976d2);
        }

        .action-card-title {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .action-card.bereiden .action-card-title {
            color: #d84315;
        }

        .action-card.leveren .action-card-title {
            color: #1565c0;
        }

        .action-card-desc {
            color: var(--text-secondary);
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
            line-height: 1.5;
        }

        .action-card-stats {
            display: flex;
            gap: 2rem;
        }

        .action-stat {
            text-align: left;
        }

        .action-stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .action-card.bereiden .action-stat-value {
            color: #e55a2b;
        }

        .action-card.leveren .action-stat-value {
            color: #1976d2;
        }

        .action-stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .action-card-arrow {
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.75rem;
            opacity: 0.2;
            transition: opacity 0.2s;
        }

        .action-card:hover .action-card-arrow {
            opacity: 0.5;
        }

        .action-card.bereiden .action-card-arrow {
            color: #e55a2b;
        }

        .action-card.leveren .action-card-arrow {
            color: #1976d2;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .summary-card {
            background: var(--white);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .summary-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .summary-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-header-link {
            font-size: 0.78rem;
            color: var(--brown-medium);
            text-decoration: none;
            font-weight: 500;
        }

        .summary-header-link:hover {
            text-decoration: underline;
        }

        .summary-body {
            padding: 0.75rem 1.25rem;
        }

        .product-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid var(--cream-dark);
        }

        .product-row:last-child {
            border-bottom: none;
        }

        .product-name {
            font-size: 0.88rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .product-qty {
            font-size: 0.85rem;
            font-weight: 600;
            color: #e55a2b;
            background: #fff0eb;
            padding: 0.2rem 0.65rem;
            border-radius: 6px;
        }

        .delivery-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid var(--cream-dark);
        }

        .delivery-row:last-child {
            border-bottom: none;
        }

        .delivery-name {
            font-size: 0.88rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .delivery-status {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .delivery-status.pending {
            background: #fef5e7;
            color: #d68910;
        }

        .delivery-status.onderweg {
            background: #eaf4fe;
            color: #1976d2;
        }

        .delivery-status.afgeleverd {
            background: #eafaf1;
            color: #1e8449;
        }

        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--text-muted);
            font-size: 0.88rem;
        }

        .empty-state i {
            font-size: 1.5rem;
            display: block;
            margin-bottom: 0.5rem;
            opacity: 0.4;
        }

        .more-link {
            display: block;
            text-align: center;
            padding: 0.75rem;
            font-size: 0.82rem;
            color: var(--brown-medium);
            text-decoration: none;
            border-top: 1px solid var(--cream-dark);
            transition: background 0.15s;
        }

        .more-link:hover {
            background: var(--cream);
        }

        .watertemp-tracker {
            background: var(--white);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            overflow: hidden;
            margin-top: 1.5rem;
        }
        .wt-tracker-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .wt-tracker-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .wt-tracker-body {
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .wt-tracker-inputs {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            flex: 1;
        }
        .wt-tracker-field {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }
        .wt-tracker-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            font-weight: 600;
        }
        .wt-tracker-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            font-variant-numeric: tabular-nums;
        }
        .wt-tracker-value.muted { color: var(--text-muted); font-size: 1rem; }
        .wt-result-pill {
            padding: 0.5rem 1.1rem;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1.4rem;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .wt-not-set {
            font-size: 0.85rem;
            color: var(--text-muted);
            padding: 1rem 1.25rem;
            font-style: italic;
        }
        .watertemp-cold { background: #eff6ff; color: #1d4ed8; }
        .watertemp-cool { background: #f0fdf4; color: #166534; }
        .watertemp-warm { background: #fff7ed; color: #c2410c; }
        .watertemp-hot  { background: #fef2f2; color: #b91c1c; }

        @media (max-width: 1024px) {
            .action-cards { gap: 1rem; }
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
            .action-cards { grid-template-columns: 1fr; }
            .summary-grid { grid-template-columns: 1fr; }
            .action-card { padding: 1.5rem; }
            .action-card-arrow { display: none; }
        }

        @media (max-width: 480px) {
            .action-card-stats { gap: 1.5rem; }
            .action-stat-value { font-size: 1.5rem; }
        }
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">Bakker Planning</span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
                <div class="page-header">
                    <h2><?= getGreeting() ?>!</h2>
                    <p>Hier is je planning voor vandaag en morgen.</p>
                </div>

                <div class="action-cards">
                    <a href="planning.php?filter=bakken" class="action-card bereiden">
                        <div class="action-card-icon">
                            <i class="bi bi-fire"></i>
                        </div>
                        <div class="action-card-title">Bereiden</div>
                        <div class="action-card-desc">
                            <?php if ($todayIsBakdag): ?>
                                Vandaag is een bakdag! Bekijk wat er gebakken en geleverd moet worden.
                            <?php elseif ($nextBakdagDt): ?>
                                Volgende bakdag: <?= $dutchDayNames[(int)$nextBakdagDt->format('w')] ?> <?= $nextBakdagDt->format('j-m') ?>.
                            <?php else: ?>
                                Bekijk je bakplanning en stel bakdagen in.
                            <?php endif; ?>
                        </div>
                        <div class="action-card-stats">
                            <div class="action-stat">
                                <div class="action-stat-value"><?= $todayBereiding['count'] ?? 0 ?></div>
                                <div class="action-stat-label">Bestellingen</div>
                            </div>
                            <div class="action-stat">
                                <div class="action-stat-value"><?= count($doughToBake) ?></div>
                                <div class="action-stat-label">Deegsoorten</div>
                            </div>
                        </div>
                        <div class="action-card-arrow">
                            <i class="bi bi-arrow-right"></i>
                        </div>
                    </a>

                    <a href="planning.php?filter=bezorging" class="action-card leveren">
                        <div class="action-card-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="action-card-title">Leveren</div>
                        <div class="action-card-desc">Plan je route en lever de bestellingen van vandaag.</div>
                        <div class="action-card-stats">
                            <div class="action-stat">
                                <div class="action-stat-value"><?= $todayDeliveries['count'] ?? 0 ?></div>
                                <div class="action-stat-label">Stops</div>
                            </div>
                            <div class="action-stat">
                                <div class="action-stat-value">&euro;<?= number_format($todayDeliveries['total'] ?? 0, 0, ',', '.') ?></div>
                                <div class="action-stat-label">Totaal</div>
                            </div>
                        </div>
                        <div class="action-card-arrow">
                            <i class="bi bi-arrow-right"></i>
                        </div>
                    </a>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-header">
                            <h3><i class="bi bi-fire" style="color: #e55a2b;"></i>
                                <?php if ($todayIsBakdag): ?>
                                    Vandaag bakken
                                <?php elseif ($nextBakdagDt): ?>
                                    Bakken op <?= $dutchDayNames[(int)$nextBakdagDt->format('w')] ?>
                                <?php else: ?>
                                    Bereiden
                                <?php endif; ?>
                            </h3>
                            <a href="planning.php?filter=bakken&mode=day" class="summary-header-link">Bekijk alles</a>
                        </div>
                        <div class="summary-body">
                            <?php if (empty($doughToBake)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-emoji-smile"></i>
                                    Niets te bereiden vandaag
                                </div>
                            <?php else: ?>
                                <?php foreach (array_slice($doughToBake, 0, 5) as $dough): ?>
                                    <div class="product-row">
                                        <span class="product-name"><?= htmlspecialchars($dough['dough_type_name']) ?></span>
                                        <span class="product-qty"><?= number_format($dough['total_dough'] / 1000, 1, ',', '.') ?> kg</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (count($doughToBake) > 5): ?>
                            <a href="planning.php?filter=bakken&mode=day" class="more-link">
                                +<?= count($doughToBake) - 5 ?> meer deegsoorten
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="summary-card">
                        <div class="summary-header">
                            <h3><i class="bi bi-truck" style="color: #1976d2;"></i> Leveringen vandaag</h3>
                            <a href="planning.php?filter=bezorging&mode=day" class="summary-header-link">Bekijk alles</a>
                        </div>
                        <div class="summary-body">
                            <?php if (empty($upcomingDeliveries)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-emoji-smile"></i>
                                    Geen leveringen vandaag
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcomingDeliveries as $delivery): ?>
                                    <div class="delivery-row">
                                        <span class="delivery-name"><?= htmlspecialchars($delivery['bedrijfsnaam']) ?></span>
                                        <?php
                                        $status = $delivery['delivery_status'] ?? 'geplaatst';
                                        $statusClass = $status === 'afgeleverd' ? 'afgeleverd' : ($status === 'onderweg' ? 'onderweg' : 'pending');
                                        $statusText = $status === 'afgeleverd' ? 'Afgeleverd' : ($status === 'onderweg' ? 'Onderweg' : 'Gepland');
                                        ?>
                                        <span class="delivery-status <?= $statusClass ?>"><?= $statusText ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="watertemp-tracker">
                    <div class="wt-tracker-header">
                        <h3><i class="bi bi-thermometer-half" style="color:#c8913a"></i> Watertemperatuur</h3>
                        <a href="dagproductie.php?date=<?= $today ?>" class="summary-header-link">Aanpassen</a>
                    </div>
                    <div id="wt-tracker-content">
                        <div class="wt-not-set">Nog geen waarden opgeslagen — open dagproductie om in te stellen.</div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
    (function() {
        var WT_KEY = 'civetta_watertemp';
        var BT_KEY = 'civetta_bakery_temp';
        var TODAY  = '<?= date('Y-m-d') ?>';
        try {
            var saved = JSON.parse(localStorage.getItem(WT_KEY)) || {};
            var bt    = JSON.parse(localStorage.getItem(BT_KEY));

            // Determine flour/ambient: prefer bakery temp
            var flour   = bt ? parseFloat(bt.value) : (parseFloat(saved.flour)   || 0);
            var ambient = bt ? parseFloat(bt.value) : (parseFloat(saved.ambient) || 0);
            var ddt      = parseFloat(saved.dough)    || 0;
            var friction = parseFloat(saved.friction) || 0;
            var prefVal  = (saved.preferment || '').trim();
            var hasPref  = prefVal !== '' && !isNaN(parseFloat(prefVal));

            if (!ddt && !bt) return; // nothing at all saved

            var water = hasPref
                ? ddt * 4 - (flour + ambient + parseFloat(prefVal) + friction)
                : ddt * 3 - (flour + ambient + friction);
            water = Math.round(water * 10) / 10;

            var colorClass = water <= 5 ? 'watertemp-cold' : water <= 20 ? 'watertemp-cool' : water <= 35 ? 'watertemp-warm' : 'watertemp-hot';
            var btStale = bt && bt.date !== TODAY;

            var html = '<div class="wt-tracker-body"><div class="wt-tracker-inputs">';

            // Bakery temp field (prominent)
            if (bt) {
                var btLabel = btStale
                    ? 'Bakkerij <span style="color:#b45309;font-size:0.65rem;font-weight:600"> — ' + bt.date + ' (oud)</span>'
                    : 'Bakkerij vandaag';
                html += '<div class="wt-tracker-field"><div class="wt-tracker-label">' + btLabel + '</div>'
                      + '<div class="wt-tracker-value' + (btStale ? ' muted' : '') + '">' + bt.value + '°C</div></div>';
            }

            if (ddt) {
                html += '<div class="wt-tracker-field"><div class="wt-tracker-label">DDT</div><div class="wt-tracker-value">' + ddt + '°C</div></div>';
                if (hasPref) html += '<div class="wt-tracker-field"><div class="wt-tracker-label">Voordeeg</div><div class="wt-tracker-value">' + parseFloat(prefVal) + '°C</div></div>';
                if (friction) html += '<div class="wt-tracker-field"><div class="wt-tracker-label">Wrijving</div><div class="wt-tracker-value">' + friction + '°C</div></div>';
                html += '</div><div class="wt-result-pill ' + colorClass + (btStale ? ' ' + colorClass + '" style="opacity:0.65' : '') + '">' + water + '°C water</div>';
            } else {
                html += '</div>';
            }

            html += '</div>';
            document.getElementById('wt-tracker-content').innerHTML = html;
        } catch(e) {}
    })();

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
