<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 019</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2d4a2d; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info { color: #666; }
        .error { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #3d6b3d; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #3d6b3d; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #2d4a2d; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 019: THT + Consolidatie</h1>
<pre><?php

// 1. THT datum op ingredient_batches
try {
    $pdo->exec("ALTER TABLE ingredient_batches ADD COLUMN thd_date DATE NULL AFTER purchase_date");
    echo "<span class='success'>✓ Added thd_date column to ingredient_batches</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- thd_date column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 2. Reden op inventory_consumption (order / purge / consolidation)
try {
    $pdo->exec("ALTER TABLE inventory_consumption ADD COLUMN reason ENUM('order','purge','consolidation') NOT NULL DEFAULT 'order' AFTER order_id");
    echo "<span class='success'>✓ Added reason column to inventory_consumption</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- reason column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 3. Notitie op inventory_consumption
try {
    $pdo->exec("ALTER TABLE inventory_consumption ADD COLUMN note TEXT NULL AFTER reason");
    echo "<span class='success'>✓ Added note column to inventory_consumption</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- note column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 4. Consolidatie header tabel
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_consolidations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            consolidation_date DATE NOT NULL,
            notes TEXT NULL,
            performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_date (consolidation_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<span class='success'>✓ Created inventory_consolidations table</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- inventory_consolidations table already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 5. Consolidatie regels tabel
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_consolidation_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            consolidation_id INT NOT NULL,
            ingredient_id INT NOT NULL,
            expected_grams DECIMAL(10,2) NOT NULL,
            counted_grams DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (consolidation_id) REFERENCES inventory_consolidations(id) ON DELETE CASCADE,
            FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
            INDEX idx_consolidation (consolidation_id),
            INDEX idx_ingredient (ingredient_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<span class='success'>✓ Created inventory_consolidation_items table</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- inventory_consolidation_items table already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration 019 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>ingredient_batches: +thd_date (tenminste houdbaar tot)</li>
    <li>inventory_consumption: +reason (order/purge/consolidation), +note</li>
    <li>Nieuwe tabel: inventory_consolidations (fysieke tellingen)</li>
    <li>Nieuwe tabel: inventory_consolidation_items (per ingrediënt per telling)</li>
</ul>

<a href="../bakker/voorraad.php" class="btn">← Naar Voorraadbeheer</a>
</div>
</body>
</html>
