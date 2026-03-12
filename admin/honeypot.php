<?php
require_once 'config.php';
requireLogin();

// Check of tabel bestaat
$tabelBestaat = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'honeypot_logs'")->fetchColumn() > 0;

$totaal = 0;
$vandaag = 0;
$week = 0;
$maand = 0;
$perPagina = [];
$perDag = [];
$recent = [];

if ($tabelBestaat) {
    $totaal = (int)$pdo->query("SELECT COUNT(*) FROM honeypot_logs")->fetchColumn();
    $vandaag = (int)$pdo->query("SELECT COUNT(*) FROM honeypot_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $week = (int)$pdo->query("SELECT COUNT(*) FROM honeypot_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $maand = (int)$pdo->query("SELECT COUNT(*) FROM honeypot_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    $perPagina = $pdo->query("SELECT pagina, COUNT(*) as aantal FROM honeypot_logs GROUP BY pagina ORDER BY aantal DESC")->fetchAll();
    $perDag = $pdo->query("
        SELECT DATE(created_at) as dag, COUNT(*) as aantal
        FROM honeypot_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY dag ORDER BY dag DESC
    ")->fetchAll();
    $recent = $pdo->query("SELECT * FROM honeypot_logs ORDER BY created_at DESC LIMIT 50")->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cleanup') {
        $dagen = (int)($_POST['dagen'] ?? 30);
        $pdo->prepare("DELETE FROM honeypot_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([$dagen]);
        header('Location: honeypot.php?cleaned=1');
        exit;
    }
}

$currentPage = 'honeypot';
$adminBasePath = '';

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = ? AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute([$today]);
$sidebarUnprocessedOrders = $stmt->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Honeypot | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--cream);
            color: var(--text-primary);
            min-height: 100vh;
        }
        .admin-content { padding: 2rem; max-width: 1200px; }
        .page-header { margin-bottom: 1.5rem; }
        .page-header h2 { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); }
        .page-header p { color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: var(--white);
            border-radius: var(--radius-md);
            padding: 1.25rem 1.5rem;
            border: 1px solid var(--border);
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
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
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
        }
        .card-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .card-body { padding: 1rem 1.5rem; }

        .data-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--cream-dark);
        }
        .data-row:last-child { border-bottom: none; }

        .badge {
            display: inline-block;
            padding: 0.15rem 0.6rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-blue { background: #eaf4fe; color: #2980b9; }
        .badge-green { background: #eafaf1; color: #27ae60; }
        .badge-orange { background: #fef5e7; color: #e67e22; }
        .badge-red { background: #fdedec; color: #c0392b; }

        .cleanup-form {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            padding: 1rem 1.5rem;
        }
        .cleanup-form select {
            padding: 0.5rem 0.75rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.88rem;
            background: var(--white);
        }
        .btn-danger {
            padding: 0.5rem 1rem;
            background: #c0392b;
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-danger:hover { opacity: 0.9; }

        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 0.65rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--cream-dark);
            font-size: 0.85rem;
        }
        th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 600;
            background: var(--cream);
        }

        .alert-success {
            background: #eafaf1;
            color: #1e8449;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            font-size: 0.88rem;
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text-muted);
        }

        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .content-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'components/sidebar.php'; ?>

        <div class="admin-main">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-toggle" onclick="toggleSidebar()">
                        <i class="bi bi-list"></i>
                    </button>
                    <span class="topbar-title">Honeypot</span>
                </div>
            </header>

            <div class="admin-content">
                <div class="page-header">
                    <h2>Honeypot &mdash; Bot-detectie</h2>
                    <p>Overzicht van spam-bots gevangen door honeypot velden</p>
                </div>

<?php if (!$tabelBestaat): ?>
    <div class="card">
        <div class="card-body">
            <p>De honeypot_logs tabel bestaat nog niet. Draai migratie 036 om deze aan te maken.</p>
        </div>
    </div>
<?php else: ?>

<?php if (!empty($_GET['cleaned'])): ?>
    <div class="alert-success">Oude logs opgeschoond.</div>
<?php endif; ?>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-label">Totaal gevangen</div>
                        <div class="stat-card-value"><?= $totaal ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-label">Afgelopen 30 dagen</div>
                        <div class="stat-card-value"><?= $maand ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-label">Afgelopen 7 dagen</div>
                        <div class="stat-card-value"><?= $week ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-label">Vandaag</div>
                        <div class="stat-card-value"><?= $vandaag ?></div>
                    </div>
                </div>

                <div class="content-grid">
                    <div class="card">
                        <div class="card-header"><h3>Per pagina</h3></div>
                        <div class="card-body">
                            <?php if (empty($perPagina)): ?>
                                <div class="empty-state">Nog geen data.</div>
                            <?php else: ?>
                                <?php foreach ($perPagina as $hp): ?>
                                    <div class="data-row">
                                        <span class="badge badge-blue"><?= htmlspecialchars($hp['pagina']) ?></span>
                                        <strong><?= $hp['aantal'] ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3>Per dag (laatste 30 dagen)</h3></div>
                        <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                            <?php if (empty($perDag)): ?>
                                <div class="empty-state">Nog geen data.</div>
                            <?php else: ?>
                                <?php foreach ($perDag as $d): ?>
                                    <div class="data-row">
                                        <span><?= date('d M Y', strtotime($d['dag'])) ?></span>
                                        <span class="badge <?= $d['aantal'] >= 10 ? 'badge-red' : ($d['aantal'] >= 3 ? 'badge-orange' : 'badge-green') ?>"><?= $d['aantal'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom: 1.5rem;">
                    <div class="card-header"><h3>Logs opschonen</h3></div>
                    <form method="POST" class="cleanup-form">
                        <input type="hidden" name="action" value="cleanup">
                        <label style="font-size: 0.88rem;">Verwijder logs ouder dan</label>
                        <select name="dagen">
                            <option value="7">7 dagen</option>
                            <option value="30" selected>30 dagen</option>
                            <option value="90">90 dagen</option>
                        </select>
                        <button type="submit" class="btn-danger" onclick="return confirmSubmit(this.form, 'Weet je het zeker?')">Opschonen</button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Laatste 50 gevangen bots</h3></div>
                    <div class="card-body">
                        <?php if (empty($recent)): ?>
                            <div class="empty-state">Nog geen bots gevangen.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Pagina</th>
                                        <th>IP-adres</th>
                                        <th>Ingevulde waarde</th>
                                        <th>User Agent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $hl): ?>
                                    <tr>
                                        <td style="white-space: nowrap;"><?= date('d M Y H:i', strtotime($hl['created_at'])) ?></td>
                                        <td><span class="badge badge-blue"><?= htmlspecialchars($hl['pagina']) ?></span></td>
                                        <td><code style="font-size: 0.8rem;"><?= htmlspecialchars($hl['ip_adres']) ?></code></td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($hl['ingevulde_waarde']) ?></td>
                                        <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($hl['user_agent']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

<?php endif; ?>

            </div>
        </div>
    </div>
<script src="../js/ui-notifications.js?v=1"></script>
</body>
</html>
