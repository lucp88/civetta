<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 060</title>
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
<h1>Migration 060: Bakactie voorraad afschrijving</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE bak_acties ADD COLUMN inventory_consumed TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    echo "<span class='success'>✓ Kolom inventory_consumed toegevoegd aan bak_acties</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false)
        echo "<span class='info'>- inventory_consumed bestaat al</span>\n";
    else
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $pdo->exec("ALTER TABLE bak_acties ADD COLUMN skip_inventory TINYINT(1) NOT NULL DEFAULT 0 AFTER inventory_consumed");
    echo "<span class='success'>✓ Kolom skip_inventory toegevoegd aan bak_acties</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false)
        echo "<span class='info'>- skip_inventory bestaat al</span>\n";
    else
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 060 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>bak_acties.inventory_consumed</code> — wordt 1 zodra voorraad is afgeschreven</li>
    <li><code>bak_acties.skip_inventory</code> — voorraadafschrijving overgeslagen als 1</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
