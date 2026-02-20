<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 025</title>
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
<h1>Migration 025: Variant naam en foto</h1>
<pre><?php

// Add naam column to product_variants
try {
    $pdo->exec("ALTER TABLE product_variants ADD COLUMN naam VARCHAR(255) NULL AFTER product_id");
    echo "<span class='success'>✓ naam kolom toegevoegd aan product_variants</span>\n";
} catch (PDOException $e) {
    echo strpos($e->getMessage(), 'Duplicate') !== false
        ? "<span class='info'>- naam kolom bestaat al</span>\n"
        : "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Add foto column to product_variants
try {
    $pdo->exec("ALTER TABLE product_variants ADD COLUMN foto VARCHAR(255) NULL AFTER prijs");
    echo "<span class='success'>✓ foto kolom toegevoegd aan product_variants</span>\n";
} catch (PDOException $e) {
    echo strpos($e->getMessage(), 'Duplicate') !== false
        ? "<span class='info'>- foto kolom bestaat al</span>\n"
        : "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 025 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>product_variants: +naam (variant display naam, bijv. "Walnoot", "Naturel")</li>
    <li>product_variants: +foto (variant-specifieke foto, overschrijft productfoto)</li>
</ul>

<a href="../producten/products.php" class="btn">← Naar Producten</a>
</div>
</body>
</html>
