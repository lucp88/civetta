<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 029</title>
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
<h1>Migration 029: Interne Bestelling Afhandeling</h1>
<pre><?php

// 1. Add quantity_sold to business_order_items
try {
    $check = $pdo->query("SHOW COLUMNS FROM business_order_items LIKE 'quantity_sold'");
    if ($check->rowCount() > 0) {
        echo "<span class='info'>- quantity_sold kolom bestaat al</span>\n";
    } else {
        $pdo->exec("ALTER TABLE business_order_items ADD COLUMN quantity_sold INT DEFAULT NULL AFTER unit_price");
        echo "<span class='success'>✓ quantity_sold kolom toegevoegd aan business_order_items</span>\n";
    }
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 2. Add settled_amount to business_orders
try {
    $check = $pdo->query("SHOW COLUMNS FROM business_orders LIKE 'settled_amount'");
    if ($check->rowCount() > 0) {
        echo "<span class='info'>- settled_amount kolom bestaat al</span>\n";
    } else {
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN settled_amount DECIMAL(10,2) DEFAULT NULL AFTER total_amount");
        echo "<span class='success'>✓ settled_amount kolom toegevoegd aan business_orders</span>\n";
    }
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 3. Add settled_at to business_orders
try {
    $check = $pdo->query("SHOW COLUMNS FROM business_orders LIKE 'settled_at'");
    if ($check->rowCount() > 0) {
        echo "<span class='info'>- settled_at kolom bestaat al</span>\n";
    } else {
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN settled_at DATETIME DEFAULT NULL AFTER settled_amount");
        echo "<span class='success'>✓ settled_at kolom toegevoegd aan business_orders</span>\n";
    }
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 029 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>quantity_sold</code> kolom toegevoegd aan <code>business_order_items</code> (voor bijhouden werkelijk verkocht aantal)</li>
    <li><code>settled_amount</code> kolom toegevoegd aan <code>business_orders</code> (werkelijke verkoopomzet, apart van productiewaarde)</li>
    <li><code>settled_at</code> kolom toegevoegd aan <code>business_orders</code> (tijdstip afhandeling)</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">&larr; Naar Dashboard</a>
</div>
</body>
</html>
