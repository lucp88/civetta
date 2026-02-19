<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 013 - Inventory Management</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 700px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #5c3d1e; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info { color: #666; }
        .error { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #8b5a2b; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #8b5a2b; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #5c3d1e; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 013: Inventory Management</h1>
<pre><?php

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ingredients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            category ENUM('meel', 'gist', 'mixin', 'topping', 'overig') NOT NULL DEFAULT 'overig',
            unit ENUM('g', 'kg', 'ml', 'l') NOT NULL DEFAULT 'g',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ Created ingredients table</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- ingredients table already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ingredient_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ingredient_id INT NOT NULL,
            quantity_purchased DECIMAL(10,2) NOT NULL COMMENT 'in grams',
            quantity_remaining DECIMAL(10,2) NOT NULL COMMENT 'in grams',
            price_per_kg DECIMAL(10,4) NOT NULL,
            purchase_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ingredient (ingredient_id),
            INDEX idx_remaining (quantity_remaining),
            INDEX idx_purchase_date (purchase_date),
            FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ Created ingredient_batches table</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- ingredient_batches table already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS utility_costs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('water', 'electricity') NOT NULL,
            year_month CHAR(7) NOT NULL COMMENT 'Format: YYYY-MM',
            cost DECIMAL(10,2) NOT NULL,
            is_estimate TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_type_month (type, year_month),
            INDEX idx_year_month (year_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ Created utility_costs table</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- utility_costs table already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_consumption (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ingredient_id INT NOT NULL,
            batch_id INT NOT NULL,
            order_id INT NULL COMMENT 'Reference to business_orders if applicable',
            quantity_consumed DECIMAL(10,2) NOT NULL COMMENT 'in grams',
            cost DECIMAL(10,4) NOT NULL,
            consumed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ingredient (ingredient_id),
            INDEX idx_batch (batch_id),
            INDEX idx_order (order_id),
            INDEX idx_consumed_at (consumed_at),
            FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES ingredient_batches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ Created inventory_consumption table</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- inventory_consumption table already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='info'>Seeding default ingredients from bakcalculator...</span>\n\n";

$defaultIngredients = [
    ['Tarwe wit', 'meel'],
    ['Tarwe volkoren', 'meel'],
    ['Spelt wit', 'meel'],
    ['Spelt volkoren', 'meel'],
    ['Durum', 'meel'],
    ['Emmer', 'meel'],
    ['Rogge wit', 'meel'],
    ['Rogge volkoren', 'meel'],
    ['Einkorn', 'meel'],
    ['Boekweit', 'meel'],
    ['Rijstmeel', 'meel'],
    ['Gerst', 'meel'],
    ['Teff', 'meel'],
    ['Verse gist', 'gist'],
    ['Instant gist', 'gist'],
    ['Zout', 'overig'],
    ['Water', 'overig'],
    ['Zonnebloempitten', 'mixin'],
    ['Pompoenpitten', 'mixin'],
    ['Maanzaad', 'mixin'],
    ['Sesamzaad', 'mixin'],
    ['Walnoten', 'mixin'],
    ['Pecannoten', 'mixin'],
    ['Amandelen', 'mixin'],
    ['Hazelnoten', 'mixin'],
    ['Pijnboompitten', 'mixin'],
    ['Rozijnen', 'mixin'],
    ['Gedroogde cranberries', 'mixin'],
    ['Gedroogde abrikozen', 'mixin'],
    ['Gedroogde vijgen', 'mixin'],
    ['Dadels', 'mixin'],
    ['Lijnzaad', 'mixin'],
    ['Chiazaad', 'mixin'],
    ['Havervlokken', 'mixin'],
    ['Olijfolie', 'mixin'],
    ['Boter', 'mixin'],
    ['Melk', 'mixin'],
    ['Suiker', 'mixin'],
    ['Honing', 'mixin'],
    ['Zadenmix', 'topping'],
    ['Zeezoutvlokken', 'topping'],
    ['Kaneelsuiker', 'topping'],
    ['Grove suiker', 'topping'],
];

$insertStmt = $pdo->prepare("INSERT IGNORE INTO ingredients (name, category) VALUES (?, ?)");
$inserted = 0;
foreach ($defaultIngredients as $ing) {
    try {
        $insertStmt->execute($ing);
        if ($insertStmt->rowCount() > 0) $inserted++;
    } catch (PDOException $e) {
    }
}
echo "<span class='success'>✓ Seeded $inserted default ingredients</span>\n";

try {
    $pdo->exec("ALTER TABLE business_orders ADD COLUMN inventory_consumed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_cancelled");
    echo "<span class='success'>✓ Added inventory_consumed column to business_orders</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- inventory_consumed column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration completed!</span>\n";
?></pre>

<p><strong>Tables created:</strong></p>
<ul>
    <li><code>ingredients</code> - Master data voor alle ingrediënten met categorie</li>
    <li><code>ingredient_batches</code> - Voorraadlagen voor FIFO prijsberekening</li>
    <li><code>utility_costs</code> - Maandelijkse water/elektra kosten</li>
    <li><code>inventory_consumption</code> - Verbruikshistorie voor audit trail</li>
</ul>

<a href="../bakker/voorraad.php" class="btn">→ Naar Voorraadbeheer</a>
<a href="../index.php" class="btn" style="background:#666">← Terug naar Admin</a>
</div>
</body>
</html>
