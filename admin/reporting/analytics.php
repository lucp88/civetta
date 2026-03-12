<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Analytics';
$currentPage = 'analytics';
$adminBasePath = '../';
ob_start(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .filters-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
            background: var(--white);
            padding: 1rem 1.5rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .filter-group input[type="date"],
        .filter-group select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            background: var(--white);
        }

        .filter-group input[type="date"]:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--brown-medium);
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

        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .chart-card.full-width {
            grid-column: span 2;
        }

        .chart-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .chart-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-header h3 i {
            color: var(--brown-medium);
        }

        .chart-body {
            padding: 1.5rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .chart-container.tall {
            height: 400px;
        }

        .granularity-btns {
            display: flex;
            gap: 0.25rem;
        }

        .granularity-btn {
            padding: 0.35rem 0.75rem;
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.15s;
        }

        .granularity-btn:hover {
            border-color: var(--brown-medium);
            color: var(--brown);
        }

        .granularity-btn.active {
            background: var(--brown);
            border-color: var(--brown);
            color: white;
        }

        .ranking-list {
            list-style: none;
        }

        .ranking-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--cream-dark);
        }

        .ranking-item:last-child {
            border-bottom: none;
        }

        .ranking-number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--cream-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--brown);
            flex-shrink: 0;
        }

        .ranking-number.gold { background: #ffd700; color: #333; }
        .ranking-number.silver { background: #c0c0c0; color: #333; }
        .ranking-number.bronze { background: #cd7f32; color: white; }

        .ranking-info {
            flex: 1;
            min-width: 0;
        }

        .ranking-name {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .ranking-bar-container {
            height: 6px;
            background: var(--cream-dark);
            border-radius: 3px;
            margin-top: 0.35rem;
            overflow: hidden;
        }

        .ranking-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--brown-medium), var(--brown-light));
            border-radius: 3px;
            transition: width 0.5s ease;
        }

        .ranking-value {
            font-weight: 600;
            color: var(--brown);
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .heatmap-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .heatmap-item {
            background: var(--cream);
            border-radius: var(--radius-sm);
            padding: 1rem;
            transition: transform 0.2s;
        }

        .heatmap-item:hover {
            transform: translateY(-2px);
        }

        .heatmap-city {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .heatmap-stats {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .heatmap-bar {
            height: 4px;
            background: var(--cream-dark);
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .heatmap-bar-fill {
            height: 100%;
            border-radius: 2px;
            transition: width 0.5s ease;
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .loading i {
            animation: spin 1s linear infinite;
            margin-right: 0.5rem;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .chart-card.full-width { grid-column: span 1; }
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .filter-group { flex-direction: column; align-items: stretch; }
        }
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">Analytics</span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
                <div class="page-header">
                    <h2>Rapportages & Analytics</h2>
                    <p>Inzicht in omzet, verkoop en locaties</p>
                </div>

                <div class="filters-bar">
                    <div class="filter-group">
                        <label>Van:</label>
                        <input type="date" id="dateFrom">
                    </div>
                    <div class="filter-group">
                        <label>Tot:</label>
                        <input type="date" id="dateTo">
                    </div>
                    <button class="btn btn-primary" onclick="loadAllData()">
                        <i class="bi bi-arrow-repeat"></i> Vernieuwen
                    </button>
                </div>

                <div class="stats-grid" id="statsGrid">
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #eaf4fe; color: #2980b9;">
                            <i class="bi bi-currency-euro"></i>
                        </div>
                        <div class="stat-card-label">Totale omzet</div>
                        <div class="stat-card-value" id="statTotalRevenue">-</div>
                        <div class="stat-card-sub" id="statTotalOrders">- bestellingen</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #fef5e7; color: #e67e22;">
                            <i class="bi bi-basket3"></i>
                        </div>
                        <div class="stat-card-label">Producten verkocht</div>
                        <div class="stat-card-value" id="statTotalProducts">-</div>
                        <div class="stat-card-sub" id="statAvgOrder">Gem. - per bestelling</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #eafaf1; color: #27ae60;">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div class="stat-card-label">Gem. orderwaarde</div>
                        <div class="stat-card-value" id="statAvgOrderValue">-</div>
                        <div class="stat-card-sub">Per bestelling</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background: #f4ecf7; color: #8e44ad;">
                            <i class="bi bi-geo-alt"></i>
                        </div>
                        <div class="stat-card-label">Actieve locaties</div>
                        <div class="stat-card-value" id="statLocations">-</div>
                        <div class="stat-card-sub">Unieke plaatsen</div>
                    </div>
                </div>

                <div class="charts-grid">
                    <div class="chart-card full-width">
                        <div class="chart-header">
                            <h3><i class="bi bi-graph-up"></i> Omzet over tijd</h3>
                            <div class="granularity-btns" data-chart="revenue">
                                <button class="granularity-btn active" data-granularity="day">Dag</button>
                                <button class="granularity-btn" data-granularity="week">Week</button>
                                <button class="granularity-btn" data-granularity="month">Maand</button>
                                <button class="granularity-btn" data-granularity="year">Jaar</button>
                            </div>
                        </div>
                        <div class="chart-body">
                            <div class="chart-container">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="chart-card full-width">
                        <div class="chart-header">
                            <h3><i class="bi bi-basket3"></i> Broden verkocht over tijd</h3>
                            <div class="granularity-btns" data-chart="products">
                                <button class="granularity-btn active" data-granularity="day">Dag</button>
                                <button class="granularity-btn" data-granularity="week">Week</button>
                                <button class="granularity-btn" data-granularity="month">Maand</button>
                                <button class="granularity-btn" data-granularity="year">Jaar</button>
                            </div>
                        </div>
                        <div class="chart-body">
                            <div class="chart-container">
                                <canvas id="productsChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="bi bi-trophy"></i> Best verkopende broden</h3>
                        </div>
                        <div class="chart-body">
                            <ul class="ranking-list" id="topProductsList">
                                <li class="loading"><i class="bi bi-arrow-repeat"></i> Laden...</li>
                            </ul>
                        </div>
                    </div>

                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="bi bi-bar-chart"></i> Top producten grafiek</h3>
                        </div>
                        <div class="chart-body">
                            <div class="chart-container">
                                <canvas id="topProductsChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="chart-card full-width">
                        <div class="chart-header">
                            <h3><i class="bi bi-geo-alt"></i> Verkoop per locatie</h3>
                        </div>
                        <div class="chart-body">
                            <div class="heatmap-container" id="locationHeatmap">
                                <div class="loading"><i class="bi bi-arrow-repeat"></i> Laden...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    let revenueChart = null;
    let productsChart = null;
    let topProductsChart = null;
    let currentGranularity = { revenue: 'day', products: 'day' };

    const chartColors = {
        primary: '#3d6b3d',
        primaryLight: '#5a9a5a',
        secondary: '#2d4a2d',
        grid: '#e5ddd4',
        text: '#6b5c52'
    };

    function initDateFilters() {
        const today = new Date();
        const thirtyDaysAgo = new Date(today);
        thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);

        document.getElementById('dateTo').value = today.toISOString().split('T')[0];
        document.getElementById('dateFrom').value = thirtyDaysAgo.toISOString().split('T')[0];
    }

    function getDateRange() {
        return {
            from: document.getElementById('dateFrom').value,
            to: document.getElementById('dateTo').value
        };
    }

    async function loadAllData() {
        const { from, to } = getDateRange();
        
        await Promise.all([
            loadStats(from, to),
            loadRevenueChart(from, to, currentGranularity.revenue),
            loadProductsChart(from, to, currentGranularity.products),
            loadTopProducts(from, to),
            loadLocationData(from, to)
        ]);
    }

    async function loadStats(from, to) {
        try {
            const res = await fetch(`../../api/analytics.php?action=stats&from=${from}&to=${to}`);
            const data = await res.json();
            
            if (data.success) {
                document.getElementById('statTotalRevenue').textContent = formatCurrency(data.total_revenue);
                document.getElementById('statTotalOrders').textContent = `${data.total_orders} bestellingen`;
                document.getElementById('statTotalProducts').textContent = data.total_products;
                document.getElementById('statAvgOrder').textContent = `Gem. ${Math.round(data.total_products / (data.total_orders || 1))} per bestelling`;
                document.getElementById('statAvgOrderValue').textContent = formatCurrency(data.avg_order_value);
                document.getElementById('statLocations').textContent = data.unique_locations;
            }
        } catch (e) {
            console.error('Error loading stats:', e);
        }
    }

    async function loadRevenueChart(from, to, granularity) {
        try {
            const res = await fetch(`../../api/analytics.php?action=revenue&from=${from}&to=${to}&granularity=${granularity}`);
            const data = await res.json();
            
            if (data.success) {
                renderRevenueChart(data.labels, data.values);
            }
        } catch (e) {
            console.error('Error loading revenue chart:', e);
        }
    }

    async function loadProductsChart(from, to, granularity) {
        try {
            const res = await fetch(`../../api/analytics.php?action=products_over_time&from=${from}&to=${to}&granularity=${granularity}`);
            const data = await res.json();
            
            if (data.success) {
                renderProductsChart(data.labels, data.values);
            }
        } catch (e) {
            console.error('Error loading products chart:', e);
        }
    }

    async function loadTopProducts(from, to) {
        try {
            const res = await fetch(`../../api/analytics.php?action=top_products&from=${from}&to=${to}&limit=10`);
            const data = await res.json();
            
            if (data.success) {
                renderTopProductsList(data.products);
                renderTopProductsChart(data.products);
            }
        } catch (e) {
            console.error('Error loading top products:', e);
        }
    }

    async function loadLocationData(from, to) {
        try {
            const res = await fetch(`../../api/analytics.php?action=locations&from=${from}&to=${to}`);
            const data = await res.json();
            
            if (data.success) {
                renderLocationHeatmap(data.locations);
            }
        } catch (e) {
            console.error('Error loading location data:', e);
        }
    }

    function renderRevenueChart(labels, values) {
        const ctx = document.getElementById('revenueChart').getContext('2d');
        
        if (revenueChart) {
            revenueChart.destroy();
        }

        revenueChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Omzet',
                    data: values,
                    borderColor: chartColors.primary,
                    backgroundColor: chartColors.primary + '20',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4,
                    pointBackgroundColor: chartColors.primary
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => formatCurrency(ctx.raw)
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: chartColors.grid },
                        ticks: {
                            callback: (value) => formatCurrency(value),
                            color: chartColors.text
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: chartColors.text }
                    }
                }
            }
        });
    }

    function renderProductsChart(labels, values) {
        const ctx = document.getElementById('productsChart').getContext('2d');
        
        if (productsChart) {
            productsChart.destroy();
        }

        productsChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Producten verkocht',
                    data: values,
                    backgroundColor: chartColors.primaryLight,
                    borderColor: chartColors.primary,
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: chartColors.grid },
                        ticks: { color: chartColors.text }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: chartColors.text }
                    }
                }
            }
        });
    }

    function renderTopProductsList(products) {
        const container = document.getElementById('topProductsList');
        const maxQty = products.length > 0 ? products[0].quantity : 1;

        let html = '';
        products.forEach((product, index) => {
            const rankClass = index === 0 ? 'gold' : (index === 1 ? 'silver' : (index === 2 ? 'bronze' : ''));
            const barWidth = (product.quantity / maxQty) * 100;
            
            html += `
                <li class="ranking-item">
                    <span class="ranking-number ${rankClass}">${index + 1}</span>
                    <div class="ranking-info">
                        <div class="ranking-name">${escapeHtml(product.product_name)}</div>
                        <div class="ranking-bar-container">
                            <div class="ranking-bar" style="width: ${barWidth}%"></div>
                        </div>
                    </div>
                    <span class="ranking-value">${product.quantity}x</span>
                </li>
            `;
        });

        container.innerHTML = html || '<li class="loading">Geen data beschikbaar</li>';
    }

    function renderTopProductsChart(products) {
        const ctx = document.getElementById('topProductsChart').getContext('2d');
        
        if (topProductsChart) {
            topProductsChart.destroy();
        }

        const labels = products.slice(0, 8).map(p => truncate(p.product_name, 15));
        const values = products.slice(0, 8).map(p => p.quantity);

        topProductsChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Aantal verkocht',
                    data: values,
                    backgroundColor: [
                        '#ffd700', '#c0c0c0', '#cd7f32',
                        chartColors.primaryLight, chartColors.primaryLight,
                        chartColors.primaryLight, chartColors.primaryLight, chartColors.primaryLight
                    ],
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: chartColors.grid },
                        ticks: { color: chartColors.text }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: chartColors.text }
                    }
                }
            }
        });
    }

    function renderLocationHeatmap(locations) {
        const container = document.getElementById('locationHeatmap');
        const maxRevenue = locations.length > 0 ? locations[0].revenue : 1;

        const heatmapColors = [
            { threshold: 0.8, color: '#2e7d32' },
            { threshold: 0.6, color: '#558b2f' },
            { threshold: 0.4, color: '#8bc34a' },
            { threshold: 0.2, color: '#c5e1a5' },
            { threshold: 0, color: '#e8f5e9' }
        ];

        let html = '';
        locations.forEach(location => {
            const ratio = location.revenue / maxRevenue;
            let color = heatmapColors[heatmapColors.length - 1].color;
            for (const hc of heatmapColors) {
                if (ratio >= hc.threshold) {
                    color = hc.color;
                    break;
                }
            }
            
            html += `
                <div class="heatmap-item">
                    <div class="heatmap-city">${escapeHtml(location.plaats)}</div>
                    <div class="heatmap-stats">${location.order_count} bestellingen &bull; ${formatCurrency(location.revenue)}</div>
                    <div class="heatmap-bar">
                        <div class="heatmap-bar-fill" style="width: ${ratio * 100}%; background: ${color};"></div>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html || '<div class="loading">Geen locatiedata beschikbaar</div>';
    }

    function formatCurrency(value) {
        return new Intl.NumberFormat('nl-NL', {
            style: 'currency',
            currency: 'EUR'
        }).format(value || 0);
    }

    function truncate(str, len) {
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    document.querySelectorAll('.granularity-btns').forEach(container => {
        const chartType = container.dataset.chart;
        container.querySelectorAll('.granularity-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                container.querySelectorAll('.granularity-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                const granularity = btn.dataset.granularity;
                currentGranularity[chartType] = granularity;
                
                const { from, to } = getDateRange();
                if (chartType === 'revenue') {
                    loadRevenueChart(from, to, granularity);
                } else if (chartType === 'products') {
                    loadProductsChart(from, to, granularity);
                }
            });
        });
    });

    initDateFilters();
    loadAllData();
    </script>
</body>
</html>
