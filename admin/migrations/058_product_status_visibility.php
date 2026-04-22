<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 058</title>
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
<h1>Migration 058: Product status &amp; zichtbaarheid</h1>
<pre><?php

$queries = [
    "ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0" =>
        "products.is_active toegevoegd (default: inactief)",
    "ALTER TABLE products ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 1" =>
        "products.is_hidden toegevoegd (default: verborgen)",
    "ALTER TABLE product_variants ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0" =>
        "product_variants.is_active toegevoegd (default: inactief)",
    "ALTER TABLE product_variants ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 1" =>
        "product_variants.is_hidden toegevoegd (default: verborgen)",
];

foreach ($queries as $sql => $label) {
    try {
        $pdo->exec($sql);
        echo "<span class='success'>✓ $label</span>\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "<span class='info'>- $label (bestaat al)</span>\n";
        } else {
            echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
        }
    }
}

echo "\n<span class='success'>✓ Migration 058 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>products.is_active</code> — 1 = beschikbaar voor aankoop, 0 = niet beschikbaar (default)</li>
    <li><code>products.is_hidden</code> — 1 = verborgen op website (default), 0 = zichtbaar</li>
    <li><code>product_variants.is_active</code> — zelfde logica per variant</li>
    <li><code>product_variants.is_hidden</code> — zelfde logica per variant</li>
</ul>
<p style="color:#888;font-size:0.9rem;margin-top:0.75rem;">Bestaande producten staan nu als verborgen en inactief. Zet ze op zichtbaar/actief via Producten beheer.</p>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
