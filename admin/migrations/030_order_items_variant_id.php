<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 030</title>
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
<h1>Migration 030: Order Items Variant ID</h1>
<pre><?php

// 1. Add variant_id column
try {
    $check = $pdo->query("SHOW COLUMNS FROM business_order_items LIKE 'variant_id'");
    if ($check->rowCount() > 0) {
        echo "<span class='info'>- variant_id kolom bestaat al</span>\n";
    } else {
        $pdo->exec("ALTER TABLE business_order_items ADD COLUMN variant_id INT NULL AFTER unit_price");
        echo "<span class='success'>✓ variant_id kolom toegevoegd aan business_order_items</span>\n";
    }
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 2. Add product_id column
try {
    $check = $pdo->query("SHOW COLUMNS FROM business_order_items LIKE 'product_id'");
    if ($check->rowCount() > 0) {
        echo "<span class='info'>- product_id kolom bestaat al</span>\n";
    } else {
        $pdo->exec("ALTER TABLE business_order_items ADD COLUMN product_id INT NULL AFTER variant_id");
        echo "<span class='success'>✓ product_id kolom toegevoegd aan business_order_items</span>\n";
    }
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 3. Add index on variant_id
try {
    $pdo->exec("ALTER TABLE business_order_items ADD INDEX idx_boi_variant_id (variant_id)");
    echo "<span class='success'>✓ Index op variant_id toegevoegd</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key') !== false) {
        echo "<span class='info'>- Index op variant_id bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 4. Add index on product_id
try {
    $pdo->exec("ALTER TABLE business_order_items ADD INDEX idx_boi_product_id (product_id)");
    echo "<span class='success'>✓ Index op product_id toegevoegd</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key') !== false) {
        echo "<span class='info'>- Index op product_id bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration 030 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>variant_id</code> kolom toegevoegd aan <code>business_order_items</code> (FK naar product_variants, nullable)</li>
    <li><code>product_id</code> kolom toegevoegd aan <code>business_order_items</code> (FK naar products, nullable)</li>
    <li>Oude bestellingen houden NULL — alleen nieuwe bestellingen krijgen variant_id</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">&larr; Naar Dashboard</a>
</div>
</body>
</html>
