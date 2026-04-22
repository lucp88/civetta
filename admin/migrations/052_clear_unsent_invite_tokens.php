<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');

$runCount = 0;
try {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'migration_052_run_count'");
    $stmt->execute();
    $row = $stmt->fetch();
    $runCount = $row ? (int)$row['value'] : 0;
} catch (PDOException $e) {
    // settings table may not exist yet — treat as 0
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 052</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #5c3d1e; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info    { color: #666; }
        .error   { color: #c62828; }
        .warning { color: #856404; background: #fff3cd; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #8b5a2b; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #8b5a2b; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #5c3d1e; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 052: Verwijder niet-verstuurde uitnodigingstokens</h1>

<?php if ($runCount >= 1): ?>
<div class="warning">
    ⚠️ Deze migration is al uitgevoerd (<?= $runCount ?>×). Opnieuw uitvoeren is uitgeschakeld.
</div>
<?php else: ?>
<pre><?php
try {
    $stmt = $pdo->prepare("
        UPDATE business_accounts
        SET invite_token = NULL
        WHERE invite_token IS NOT NULL
          AND invite_opened_at IS NULL
          AND invite_accepted_at IS NULL
    ");
    $stmt->execute();
    $rows = $stmt->rowCount();
    echo "<span class='success'>✓ $rows account(s) gecorrigeerd — stale tokens verwijderd</span>\n";

    $newCount = $runCount + 1;
    $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('migration_052_run_count', ?)
        ON DUPLICATE KEY UPDATE value = ?")->execute([$newCount, $newCount]);

    echo "\n<span class='success'>✓ Migration 052 voltooid! (uitvoering #$newCount)</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}
?></pre>
<?php endif; ?>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Wist <code>invite_token</code> voor accounts waarbij de uitnodiging nooit verstuurd is (token gegenereerd maar mail niet verzonden)</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
