<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 043</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #5c3d1e; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info    { color: #666; }
        .error   { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #8b5a2b; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #8b5a2b; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #5c3d1e; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 043: Wachtwoord reset tokens</h1>
<pre><?php

$cols = $pdo->query("SHOW COLUMNS FROM business_accounts LIKE 'pw_reset_token'")->fetchAll();
if (count($cols) > 0) {
    echo "<span class='info'>- pw_reset_token bestaat al</span>\n";
} else {
    try {
        $pdo->exec("ALTER TABLE business_accounts ADD COLUMN pw_reset_token VARCHAR(64) NULL DEFAULT NULL AFTER invite_accepted_at");
        echo "<span class='success'>✓ Kolom pw_reset_token toegevoegd</span>\n";
    } catch (PDOException $e) {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

$cols = $pdo->query("SHOW COLUMNS FROM business_accounts LIKE 'pw_reset_expires'")->fetchAll();
if (count($cols) > 0) {
    echo "<span class='info'>- pw_reset_expires bestaat al</span>\n";
} else {
    try {
        $pdo->exec("ALTER TABLE business_accounts ADD COLUMN pw_reset_expires DATETIME NULL DEFAULT NULL AFTER pw_reset_token");
        echo "<span class='success'>✓ Kolom pw_reset_expires toegevoegd</span>\n";
    } catch (PDOException $e) {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

$indexes = $pdo->query("SHOW INDEX FROM business_accounts WHERE Key_name = 'idx_pw_reset_token'")->fetchAll();
if (count($indexes) > 0) {
    echo "<span class='info'>- Index idx_pw_reset_token bestaat al</span>\n";
} else {
    try {
        $pdo->exec("ALTER TABLE business_accounts ADD UNIQUE INDEX idx_pw_reset_token (pw_reset_token)");
        echo "<span class='success'>✓ Unieke index op pw_reset_token toegevoegd</span>\n";
    } catch (PDOException $e) {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration 043 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Kolom <code>pw_reset_token</code> toegevoegd aan <code>business_accounts</code></li>
    <li>Kolom <code>pw_reset_expires</code> toegevoegd aan <code>business_accounts</code> (1 uur geldig)</li>
    <li>Unieke index <code>idx_pw_reset_token</code> toegevoegd</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
