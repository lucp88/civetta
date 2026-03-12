<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 042</title>
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
<h1>Migration 042: Sort order productvarianten</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE product_variants ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
    echo "<span class='success'>✓ Kolom sort_order toegevoegd aan product_variants</span>\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "<span class='info'>- sort_order bestaat al in product_variants</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Initialize sort_order per product based on current gewicht order
try {
    $rows = $pdo->query("SELECT id, product_id FROM product_variants ORDER BY product_id ASC, gewicht ASC")->fetchAll();
    $counters = [];
    foreach ($rows as $row) {
        $pid = $row['product_id'];
        $counters[$pid] = $counters[$pid] ?? 0;
        $pdo->prepare("UPDATE product_variants SET sort_order = ? WHERE id = ?")->execute([$counters[$pid], $row['id']]);
        $counters[$pid]++;
    }
    echo "<span class='success'>✓ sort_order geïnitialiseerd voor " . count($rows) . " varianten</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 042 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Kolom <code>sort_order</code> toegevoegd aan <code>product_variants</code></li>
</ul>

<a href="../producten/products.php" class="btn">← Naar Producten</a>
</div>
</body>
</html>
