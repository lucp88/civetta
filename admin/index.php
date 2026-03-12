<?php
require_once 'config.php';
requireLogin();

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = ? AND is_cancelled = 0");
$stmt->execute([$today]);
$todayOrders = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = ? AND is_cancelled = 0");
$stmt->execute([$tomorrow]);
$tomorrowOrders = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$pendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = ? AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute([$today]);
$todayUnprocessed = $stmt->fetch()['count'];

$stmt = $pdo->prepare("
    SELECT bo.id, ba.bedrijfsnaam, bo.total_amount, bo.delivery_status, bo.delivery_date
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    WHERE bo.is_cancelled = 0 AND bo.delivery_date >= ?
    ORDER BY bo.delivery_date ASC, bo.id DESC
    LIMIT 5
");
$stmt->execute([$today]);
$recentOrders = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT boi.product_name, SUM(boi.quantity) as total_qty
    FROM business_order_items boi
    JOIN business_orders bo ON boi.order_id = bo.id
    WHERE bo.delivery_date = ? AND bo.is_cancelled = 0
    GROUP BY boi.product_name
    ORDER BY total_qty DESC
    LIMIT 6
");
$stmt->execute([$tomorrow]);
$productsToBake = $stmt->fetchAll();

$deliveryStatusLabels = [
    'geplaatst' => 'Geplaatst',
    'wordt_bereid' => 'Wordt bereid',
    'onderweg' => 'Onderweg',
    'afgeleverd' => 'Afgeleverd'
];
$deliveryStatusColors = [
    'geplaatst' => '#e67e22',
    'wordt_bereid' => '#f39c12',
    'onderweg' => '#3498db',
    'afgeleverd' => '#27ae60'
];

function getDutchDay($date) {
    $dagen = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
    return $dagen[date('w', strtotime($date))];
}

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(boi.quantity), 0) as total
    FROM business_order_items boi
    JOIN business_orders bo ON boi.order_id = bo.id
    WHERE bo.delivery_date >= ? AND bo.delivery_date < ? AND bo.is_cancelled = 0
");
$stmt->execute([$monthStart, $today]);
$brodenGebakken = (int)$stmt->fetch()['total'];

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(boi.quantity), 0) as total
    FROM business_order_items boi
    JOIN business_orders bo ON boi.order_id = bo.id
    WHERE bo.delivery_date >= ? AND bo.delivery_date <= ? AND bo.is_cancelled = 0
");
$stmt->execute([$today, $monthEnd]);
$brodenTeBakken = (int)$stmt->fetch()['total'];

$brodenTotaal = $brodenGebakken + $brodenTeBakken;

$adminPageTitle = 'Dashboard';
$currentPage = 'dashboard';
$adminBasePath = '';
$sidebarPendingAccounts = $pendingAccounts;
$sidebarUnprocessedOrders = $todayUnprocessed;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius-md);
            padding: 1.25rem 1.5rem;
            border: 1px solid var(--border);
            transition: box-shadow 0.2s;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
        }

        .stat-card-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .stat-card-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.5px;
        }

        .stat-card-sub {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        .stat-card-icon {
            float: right;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .card-header-link {
            font-size: 0.78rem;
            color: var(--brown-medium);
            text-decoration: none;
            font-weight: 500;
        }

        .card-header-link:hover { text-decoration: underline; }

        .card-body {
            padding: 1rem 1.5rem;
        }

        .order-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.7rem 0;
            border-bottom: 1px solid var(--cream-dark);
        }

        .order-row:last-child { border-bottom: none; }

        .order-info {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }

        .order-id {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .order-name {
            font-size: 0.88rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .order-date {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .order-right {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.25rem;
        }

        .order-amount {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
        }

        .status-badge {
            display: inline-block;
            padding: 0.15rem 0.6rem;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .product-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.6rem 0;
            border-bottom: 1px solid var(--cream-dark);
        }

        .product-row:last-child { border-bottom: none; }

        .product-name {
            font-size: 0.88rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .product-qty {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--brown);
            background: var(--cream-dark);
            padding: 0.2rem 0.65rem;
            border-radius: 6px;
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

        .app-section {
            background: var(--white);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .app-section h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.35rem;
        }

        .app-section p {
            font-size: 0.83rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 1.25rem;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--brown), var(--brown-dark));
            color: white;
        }

        .btn-primary:hover { opacity: 0.9; }

        .btn-danger {
            background: #c0392b;
            color: white;
        }

        .btn-disabled {
            background: #999;
            color: white;
            cursor: not-allowed;
        }

        .hint-text {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }

        .install-steps {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
        }

        .install-step {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.88rem;
            color: var(--text-primary);
        }

        .install-step-num {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: var(--cream-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.78rem;
            color: var(--brown);
            flex-shrink: 0;
        }

        .quick-links-bar {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .quick-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.45rem 1rem;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 500;
            transition: all 0.15s;
        }

        .quick-link:hover {
            border-color: var(--brown-medium);
            color: var(--brown);
            box-shadow: var(--shadow-sm);
        }

        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .content-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
            .stat-card { padding: 1rem; }
            .stat-card-value { font-size: 1.5rem; }
            .stat-card-icon { width: 36px; height: 36px; font-size: 1rem; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .quick-links-bar { flex-direction: column; }
            .quick-link { justify-content: center; }
        }
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once 'components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">Dashboard</span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
                <div class="page-header">
                    <h2>Welkom terug</h2>
                    <p><?= strftime('%A %e %B %Y', time()) ?: date('l j F Y') ?></p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #eaf4fe; color: #2980b9;">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="stat-card-label">Leveringen vandaag</div>
                        <div class="stat-card-value"><?= $todayOrders ?></div>
                        <?php if ($todayUnprocessed > 0): ?>
                            <div class="stat-card-sub" style="color: #e67e22;"><?= $todayUnprocessed ?> nog niet verwerkt</div>
                        <?php else: ?>
                            <div class="stat-card-sub">Alles verwerkt</div>
                        <?php endif; ?>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #fef5e7; color: #e67e22;">
                            <i class="bi bi-fire"></i>
                        </div>
                        <div class="stat-card-label">Bereiden morgen</div>
                        <div class="stat-card-value"><?= $tomorrowOrders ?></div>
                        <div class="stat-card-sub"><?= count($productsToBake) ?> producten</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #eafaf1; color: #27ae60;">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="stat-card-label">Accounts in afwachting</div>
                        <div class="stat-card-value"><?= $pendingAccounts ?></div>
                        <div class="stat-card-sub"><?= $pendingAccounts > 0 ? 'Actie vereist' : 'Geen openstaand' ?></div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #f4ecf7; color: #8e44ad;">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="stat-card-label">Week overzicht</div>
                        <?php
                        $weekStart = date('Y-m-d', strtotime('monday this week'));
                        $weekEnd = date('Y-m-d', strtotime('sunday this week'));
                        $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount),0) as total FROM business_orders WHERE delivery_date BETWEEN ? AND ? AND is_cancelled = 0");
                        $stmt->execute([$weekStart, $weekEnd]);
                        $weekData = $stmt->fetch();
                        ?>
                        <div class="stat-card-value"><?= $weekData['count'] ?></div>
                        <div class="stat-card-sub">&euro;<?= number_format($weekData['total'], 2, ',', '.') ?> omzet</div>
                    </div>
                </div>

                <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 2rem;">
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #eafaf1; color: #27ae60;">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="stat-card-label">Broden gebakken deze maand</div>
                        <div class="stat-card-value"><?= $brodenGebakken ?></div>
                        <div class="stat-card-sub"><?= date('1 M') ?> – gisteren</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #fef5e7; color: #e67e22;">
                            <i class="bi bi-fire"></i>
                        </div>
                        <div class="stat-card-label">Te bakken broden deze maand</div>
                        <div class="stat-card-value"><?= $brodenTeBakken ?></div>
                        <div class="stat-card-sub">Vandaag – <?= date('t M') ?></div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #f5ece3; color: #3d6b3d;">
                            <i class="bi bi-layers"></i>
                        </div>
                        <div class="stat-card-label">Totaal broden deze maand</div>
                        <div class="stat-card-value"><?= $brodenTotaal ?></div>
                        <div class="stat-card-sub"><?= date('M Y') ?></div>
                    </div>
                </div>

                <div class="content-grid">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="bi bi-clock-history" style="margin-right: 0.35rem; color: var(--brown-medium);"></i> Komende bestellingen</h3>
                            <a href="bestellingen/orders.php" class="card-header-link">Alles bekijken</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentOrders)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    Geen komende bestellingen
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentOrders as $order):
                                    $statusLabel = $deliveryStatusLabels[$order['delivery_status']] ?? $order['delivery_status'];
                                    $statusColor = $deliveryStatusColors[$order['delivery_status']] ?? '#999';
                                ?>
                                    <div class="order-row">
                                        <div class="order-info">
                                            <span class="order-id">#<?= $order['id'] ?></span>
                                            <span class="order-name"><?= htmlspecialchars($order['bedrijfsnaam']) ?></span>
                                            <span class="order-date"><?= getDutchDay($order['delivery_date']) ?> <?= date('j M', strtotime($order['delivery_date'])) ?></span>
                                        </div>
                                        <div class="order-right">
                                            <span class="order-amount">&euro;<?= number_format($order['total_amount'], 2, ',', '.') ?></span>
                                            <span class="status-badge" style="background: <?= $statusColor ?>15; color: <?= $statusColor ?>;">
                                                <?= $statusLabel ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><i class="bi bi-fire" style="margin-right: 0.35rem; color: #e67e22;"></i> Te bereiden morgen</h3>
                            <a href="bakker/bakker-dashboard.php" class="card-header-link">Planning openen</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($productsToBake)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-check-circle"></i>
                                    Geen producten voor morgen
                                </div>
                            <?php else: ?>
                                <?php foreach ($productsToBake as $product): ?>
                                    <div class="product-row">
                                        <span class="product-name"><?= htmlspecialchars($product['product_name']) ?></span>
                                        <span class="product-qty"><?= $product['total_qty'] ?>x</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="appsSection" style="display:none;" class="app-section">
                    <h3><i class="bi bi-phone"></i> Civetta Bakker App</h3>
                    <p>Installeer de bakker app voor snelle toegang. De app werkt offline en wordt automatisch bijgewerkt.</p>
                    <div id="installChrome">
                        <button id="installBtn" onclick="installPWA()" class="btn btn-primary">
                            <i class="bi bi-download"></i> Installeer App
                        </button>
                    </div>
                    <div id="installIOS" style="display: none;">
                        <div class="install-steps">
                            <div class="install-step">
                                <span class="install-step-num">1</span>
                                <span>Tik op het <strong>Delen</strong>-icoon onderaan</span>
                            </div>
                            <div class="install-step">
                                <span class="install-step-num">2</span>
                                <span>Tik op <strong>Zet op beginscherm</strong></span>
                            </div>
                            <div class="install-step">
                                <span class="install-step-num">3</span>
                                <span>Tik op <strong>Voeg toe</strong> rechtsboven</span>
                            </div>
                        </div>
                    </div>
                    <p class="hint-text" id="installHint"></p>
                </div>

                <div id="notifSection" style="display:none;" class="app-section">
                    <h3><i class="bi bi-bell"></i> Push Notificaties</h3>
                    <p>Ontvang een melding als een klant een bestelling plaatst.</p>
                    <button id="notifBtn" onclick="togglePushNotifications()" class="btn btn-primary">
                        Notificaties inschakelen
                    </button>
                    <p class="hint-text" id="notifHint"></p>
                </div>

                <div class="quick-links-bar">
                    <a href="../index.html" target="_blank" class="quick-link">
                        <i class="bi bi-globe2"></i> Website
                    </a>
                    <a href="../financiering.html" target="_blank" class="quick-link">
                        <i class="bi bi-piggy-bank"></i> Crowdfunding
                    </a>
                    <a href="../contact.html" target="_blank" class="quick-link">
                        <i class="bi bi-envelope"></i> Contact
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
    let deferredPrompt = null;
    const isIOS = /iphone|ipad|ipod/.test(navigator.userAgent.toLowerCase()) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

    if (isIOS && !isStandalone) {
        document.getElementById('appsSection').style.display = '';
        document.getElementById('installChrome').style.display = 'none';
        document.getElementById('installIOS').style.display = '';
        document.getElementById('installHint').textContent = 'Gebruik Safari om de app te installeren.';
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        showInstallSection();
    });

    function showInstallSection() {
        document.getElementById('appsSection').style.display = '';
        document.getElementById('installChrome').style.display = '';
        document.getElementById('installIOS').style.display = 'none';
        const ua = navigator.userAgent.toLowerCase();
        const hint = document.getElementById('installHint');
        if (/android/.test(ua)) {
            hint.textContent = 'Wordt toegevoegd aan je startscherm.';
        } else if (/macintosh|mac os/.test(ua)) {
            hint.textContent = 'Wordt geinstalleerd als macOS app. Gebruik Chrome of Edge.';
        } else {
            hint.textContent = 'Gebruik Chrome of Edge om de app te installeren.';
        }
    }

    function installPWA() {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then((choice) => {
                if (choice.outcome === 'accepted') {
                    const btn = document.getElementById('installBtn');
                    btn.innerHTML = '<i class="bi bi-check-lg"></i> Geinstalleerd';
                    btn.disabled = true;
                    btn.className = 'btn btn-disabled';
                    btn.style.background = '#2e7d32';
                }
                deferredPrompt = null;
            });
        }
    }

    window.addEventListener('appinstalled', () => {
        document.getElementById('appsSection').style.display = 'none';
    });

    if (isStandalone) {
        document.getElementById('appsSection').style.display = 'none';
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js', { scope: '/admin/' });
    }

    async function initPushUI() {
        if (!('PushManager' in window) || !('serviceWorker' in navigator)) return;

        const isIOSDevice = /iphone|ipad|ipod/.test(navigator.userAgent.toLowerCase()) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const isStandaloneMode = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
        if (isIOSDevice && !isStandaloneMode) {
            document.getElementById('notifHint').textContent = 'Installeer eerst de app (via Delen > Zet op beginscherm), daarna kun je notificaties inschakelen.';
            document.getElementById('notifSection').style.display = '';
            document.getElementById('notifBtn').style.display = 'none';
            return;
        }

        document.getElementById('notifSection').style.display = '';

        const perm = Notification.permission;
        if (perm === 'denied') {
            const btn = document.getElementById('notifBtn');
            btn.textContent = 'Notificaties geblokkeerd';
            btn.disabled = true;
            btn.className = 'btn btn-disabled';
            document.getElementById('notifHint').textContent = 'Je hebt notificaties geblokkeerd. Wijzig dit in je browserinstellingen.';
            return;
        }

        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            const btn = document.getElementById('notifBtn');
            btn.textContent = 'Notificaties uitschakelen';
            btn.className = 'btn btn-danger';
            document.getElementById('notifHint').textContent = 'Je ontvangt meldingen bij nieuwe bestellingen.';
        }
    }

    async function togglePushNotifications() {
        const reg = await navigator.serviceWorker.ready;
        const existing = await reg.pushManager.getSubscription();

        if (existing) {
            await fetch('/api/push-subscriptions.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint: existing.endpoint })
            });
            await existing.unsubscribe();
            const btn = document.getElementById('notifBtn');
            btn.textContent = 'Notificaties inschakelen';
            btn.className = 'btn btn-primary';
            document.getElementById('notifHint').textContent = 'Notificaties uitgeschakeld.';
            return;
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            document.getElementById('notifHint').textContent = 'Notificaties geweigerd.';
            return;
        }

        try {
            const resp = await fetch('/api/push-subscriptions.php?action=vapid-key');
            const { publicKey } = await resp.json();

            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey)
            });

            const subJson = sub.toJSON();
            await fetch('/api/push-subscriptions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    endpoint: subJson.endpoint,
                    keys: {
                        p256dh: subJson.keys.p256dh,
                        auth: subJson.keys.auth
                    }
                })
            });

            const btn = document.getElementById('notifBtn');
            btn.textContent = 'Notificaties uitschakelen';
            btn.className = 'btn btn-danger';
            document.getElementById('notifHint').textContent = 'Je ontvangt nu meldingen bij nieuwe bestellingen.';
        } catch (e) {
            document.getElementById('notifHint').textContent = 'Fout bij inschakelen: ' + e.message;
        }
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = atob(base64);
        return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
    }

    initPushUI();
    </script>
</body>
</html>
