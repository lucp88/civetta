<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 034</title>
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
<h1>Migration 034: Delivery opties per klant</h1>
<pre><?php

// 1. delivery_enabled on business_accounts
try {
    $pdo->exec("ALTER TABLE business_accounts ADD COLUMN delivery_enabled TINYINT(1) NOT NULL DEFAULT 0");
    echo "<span class='success'>✓ Kolom delivery_enabled toegevoegd aan business_accounts</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Kolom delivery_enabled bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 2. delivery_cost on business_accounts
try {
    $pdo->exec("ALTER TABLE business_accounts ADD COLUMN delivery_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    echo "<span class='success'>✓ Kolom delivery_cost toegevoegd aan business_accounts</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Kolom delivery_cost bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 3. Set delivery_enabled=1 for all existing zakelijk accounts
try {
    $stmt = $pdo->exec("UPDATE business_accounts SET delivery_enabled = 1 WHERE account_type = 'zakelijk' OR account_type IS NULL");
    echo "<span class='success'>✓ delivery_enabled=1 gezet voor bestaande zakelijke accounts ($stmt rijen)</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 4. delivery_cost on business_orders
try {
    $pdo->exec("ALTER TABLE business_orders ADD COLUMN delivery_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    echo "<span class='success'>✓ Kolom delivery_cost toegevoegd aan business_orders</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Kolom delivery_cost bestaat al op business_orders</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 5. delivery_type on business_orders
try {
    $pdo->exec("ALTER TABLE business_orders ADD COLUMN delivery_type VARCHAR(20) NOT NULL DEFAULT 'pickup'");
    echo "<span class='success'>✓ Kolom delivery_type toegevoegd aan business_orders</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Kolom delivery_type bestaat al op business_orders</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 6. Set delivery_type='delivery' for all existing orders (they were all delivery before this feature)
try {
    $stmt = $pdo->exec("UPDATE business_orders SET delivery_type = 'delivery' WHERE delivery_type = 'pickup'");
    echo "<span class='success'>✓ Bestaande bestellingen op delivery_type='delivery' gezet ($stmt rijen)</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 034 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>business_accounts.delivery_enabled</code> — bezorging aan/uit per klant (default 0, zakelijk accounts op 1 gezet)</li>
    <li><code>business_accounts.delivery_cost</code> — bezorgkosten per bestelling voor deze klant</li>
    <li><code>business_orders.delivery_cost</code> — bezorgkosten snapshot op bestelling</li>
    <li><code>business_orders.delivery_type</code> — 'pickup' of 'delivery'</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
