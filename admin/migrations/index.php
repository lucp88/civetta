<?php
/**
 * Civetta — Migration Runner
 * Visual interface to view and run database migrations.
 */
require_once __DIR__ . '/../config.php';
requireLogin();

// Discover migration files
$migrationDir = __DIR__;
$files = glob($migrationDir . '/[0-9][0-9][0-9]_*.php');
sort($files);

$migrations = [];
foreach ($files as $file) {
    $basename = basename($file, '.php');
    preg_match('/^(\d{3})_(.+)$/', $basename, $m);
    $migrations[] = [
        'file' => $file,
        'basename' => $basename,
        'number' => $m[1] ?? '???',
        'name' => ucfirst(str_replace('_', ' ', $m[2] ?? $basename)),
    ];
}

// Show newest first
$migrations = array_reverse($migrations);

// Handle run request
$runResult = null;
$runMigration = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run'])) {
    $target = $_POST['run'];

    foreach ($migrations as $mig) {
        if ($mig['basename'] === $target) {
            $runMigration = $mig;
            break;
        }
    }

    if ($runMigration) {
        $runResult = runMigration($pdo, $runMigration['file']);
    }
}

function runMigration($pdo, $file) {
    $content = file_get_contents($file);

    if (str_contains($content, '$steps')) {
        return runStepsMigration($pdo, $file);
    } else {
        return runLegacyMigration($pdo, $file);
    }
}

function runStepsMigration($pdo, $file) {
    $results = [];
    $steps = [];

    ob_start();
    $GLOBALS['_migration_runner'] = true;

    try {
        include $file;
    } catch (\Throwable $e) {
        ob_end_clean();
        return [['desc' => 'Bestand laden', 'status' => 'error', 'message' => $e->getMessage()]];
    }

    ob_end_clean();

    if (!empty($steps)) {
        foreach ($steps as $step) {
            $entry = ['desc' => $step['desc'], 'status' => 'ok', 'message' => ''];

            if (!empty($step['check'])) {
                try {
                    $result = $pdo->query($step['check'])->fetch();
                    if ($result && $result['c'] > 0) {
                        $entry['status'] = 'skip';
                        $entry['message'] = 'Bestaat al';
                        $results[] = $entry;
                        continue;
                    }
                } catch (\PDOException $e) {}
            }

            try {
                $pdo->exec($step['sql']);
                $entry['status'] = 'ok';
            } catch (\PDOException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'Duplicate') || str_contains($msg, 'already exists') || str_contains($msg, 'check that column/key exists')) {
                    $entry['status'] = 'skip';
                    $entry['message'] = $msg;
                } else {
                    $entry['status'] = 'error';
                    $entry['message'] = $msg;
                }
            }

            $results[] = $entry;
        }
    }

    return $results;
}

function runLegacyMigration($pdo, $file) {
    ob_start();
    $GLOBALS['_migration_runner'] = true;

    try {
        $returned = include $file;
    } catch (\Throwable $e) {
        ob_end_clean();
        return [['desc' => 'Migratie uitvoeren', 'status' => 'error', 'message' => $e->getMessage()]];
    }

    $output = ob_get_clean();
    return [['desc' => 'Migratie voltooid', 'status' => 'ok', 'message' => $output ? 'Zie output hierboven' : '']];
}

$currentPage = 'migrations';
$adminBasePath = '../';

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
    <title>Migraties | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--cream);
            color: var(--text-primary);
            min-height: 100vh;
        }
        .admin-content { padding: 2rem; max-width: 1000px; }
        .page-header { margin-bottom: 1.5rem; }
        .page-header h2 { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); }
        .page-header p { color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem; }

        .migration-card {
            background: var(--white);
            border-radius: var(--radius-md);
            padding: 1.25rem 1.5rem;
            margin-bottom: 0.75rem;
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        .migration-info { display: flex; align-items: center; gap: 1rem; }
        .migration-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--brown-medium);
            min-width: 2.5rem;
        }
        .migration-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-primary);
        }
        .migration-file {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.15rem;
            font-family: monospace;
        }

        .run-btn {
            padding: 0.45rem 1rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(135deg, var(--brown), var(--brown-dark));
            color: white;
            transition: opacity 0.15s;
            white-space: nowrap;
        }
        .run-btn:hover { opacity: 0.9; }

        .result-card {
            background: var(--white);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
        }
        .result-card h3 {
            margin: 0 0 1rem;
            font-size: 1.05rem;
            color: var(--text-primary);
        }
        .result-step {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.6rem 0;
            border-bottom: 1px solid var(--cream-dark);
        }
        .result-step:last-child { border-bottom: none; }
        .result-icon {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .result-icon--ok { background: rgba(39, 174, 96, 0.2); color: #1e8449; }
        .result-icon--skip { background: var(--cream-dark); color: var(--text-muted); }
        .result-icon--error { background: rgba(192, 57, 43, 0.15); color: #c0392b; }
        .result-desc { font-weight: 600; font-size: 0.88rem; color: var(--text-primary); }
        .result-msg { font-size: 0.78rem; color: var(--text-muted); margin-top: 0.15rem; }
        .result-summary {
            margin-top: 1rem;
            padding-top: 0.75rem;
            border-top: 2px solid var(--cream-dark);
            font-weight: 700;
            font-size: 0.9rem;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text-muted);
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
            .migration-card { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include __DIR__ . '/../components/sidebar.php'; ?>

        <div class="admin-main">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-toggle" onclick="toggleSidebar()">
                        <i class="bi bi-list"></i>
                    </button>
                    <span class="topbar-title">Migraties</span>
                </div>
            </header>

            <div class="admin-content">
                <div class="page-header">
                    <h2>Database Migraties</h2>
                    <p><?= count($migrations) ?> migraties gevonden</p>
                </div>

<?php if ($runResult !== null && $runMigration): ?>
    <div class="result-card">
        <h3>Resultaat: <?= htmlspecialchars($runMigration['name']) ?></h3>
        <?php
        $okCount = count(array_filter($runResult, fn($r) => $r['status'] === 'ok'));
        $skipCount = count(array_filter($runResult, fn($r) => $r['status'] === 'skip'));
        $errorCount = count(array_filter($runResult, fn($r) => $r['status'] === 'error'));

        foreach ($runResult as $r):
            $iconClass = match($r['status']) {
                'ok' => 'result-icon--ok',
                'skip' => 'result-icon--ok',
                default => 'result-icon--error',
            };
            $iconSymbol = match($r['status']) {
                'ok' => '&#10003;',
                'skip' => '&#10003;',
                default => '&#10007;',
            };
        ?>
            <div class="result-step">
                <div class="result-icon <?= $iconClass ?>"><?= $iconSymbol ?></div>
                <div>
                    <div class="result-desc"><?= htmlspecialchars($r['desc']) ?></div>
                    <?php if (!empty($r['message'])): ?>
                        <div class="result-msg"><?= htmlspecialchars($r['message']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="result-summary">
            <?= $okCount + $skipCount ?> gelukt<?php if ($skipCount): ?> (<?= $skipCount ?> bestond al)<?php endif; ?>
            <?php if ($errorCount): ?> &middot; <span style="color:#c0392b;"><?= $errorCount ?> fout(en)</span><?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($migrations as $mig): ?>
    <div class="migration-card">
        <div class="migration-info">
            <span class="migration-number">#<?= $mig['number'] ?></span>
            <div>
                <div class="migration-name"><?= htmlspecialchars($mig['name']) ?></div>
                <div class="migration-file"><?= htmlspecialchars($mig['basename']) ?>.php</div>
            </div>
        </div>
        <form method="POST" style="margin:0;" onsubmit="return confirmSubmit(this, 'Migratie <?= htmlspecialchars($mig['number']) ?> uitvoeren?')">
            <input type="hidden" name="run" value="<?= htmlspecialchars($mig['basename']) ?>">
            <button type="submit" class="run-btn">Uitvoeren</button>
        </form>
    </div>
<?php endforeach; ?>

<?php if (empty($migrations)): ?>
    <div class="empty-state">Geen migraties gevonden.</div>
<?php endif; ?>

            </div>
        </div>
    </div>
<script src="../../js/ui-notifications.js?v=1"></script>
</body>
</html>
