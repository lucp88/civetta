<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 062</title>
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
<h1>Migration 062: Voorraadbeweging koppeling bakactie</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE inventory_consumption ADD COLUMN bakactie_id INT NULL DEFAULT NULL AFTER order_id");
    echo "<span class='success'>✓ Kolom bakactie_id toegevoegd aan inventory_consumption</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false)
        echo "<span class='info'>- bakactie_id bestaat al</span>\n";
    else
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $pdo->exec("ALTER TABLE inventory_consumption ADD INDEX idx_bakactie_id (bakactie_id)");
    echo "<span class='success'>✓ Index op bakactie_id aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false)
        echo "<span class='info'>- Index bestaat al</span>\n";
    else
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 062 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>inventory_consumption.bakactie_id</code> — koppeling naar de bakactie die de voorraad heeft afgeschreven</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
