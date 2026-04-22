<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 055</title>
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
<h1>Migration 055: Bakacties Logboek uitbreiding</h1>
<pre><?php

// Add dough_type_name to bak_acties
try {
    $pdo->exec("ALTER TABLE bak_acties ADD COLUMN dough_type_name VARCHAR(255) DEFAULT NULL AFTER recipe_version_id");
    echo "<span class='success'>✓ Kolom dough_type_name toegevoegd aan bak_acties</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- dough_type_name bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Add order_ids JSON column
try {
    $pdo->exec("ALTER TABLE bak_acties ADD COLUMN order_ids JSON DEFAULT NULL AFTER dough_type_name");
    echo "<span class='success'>✓ Kolom order_ids toegevoegd aan bak_acties</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- order_ids bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Add total_qty and total_weight_g
try {
    $pdo->exec("ALTER TABLE bak_acties ADD COLUMN total_qty INT DEFAULT NULL AFTER order_ids, ADD COLUMN total_weight_g INT DEFAULT NULL AFTER total_qty");
    echo "<span class='success'>✓ Kolommen total_qty en total_weight_g toegevoegd</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- total_qty/total_weight_g bestaan al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Add time tracking and temperature columns
try {
    $pdo->exec("ALTER TABLE bak_acties ADD COLUMN start_time TIME DEFAULT NULL AFTER status, ADD COLUMN end_time TIME DEFAULT NULL AFTER start_time, ADD COLUMN water_temp DECIMAL(5,2) DEFAULT NULL AFTER end_time, ADD COLUMN dough_temp DECIMAL(5,2) DEFAULT NULL AFTER water_temp");
    echo "<span class='success'>✓ Kolommen start_time, end_time, water_temp, dough_temp toegevoegd</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Tijden/temperaturen bestaan al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Make locked_recipe_name and locked_recipe_data nullable (dagproductie-based entries use dough_type_name)
try {
    $pdo->exec("ALTER TABLE bak_acties MODIFY COLUMN locked_recipe_name VARCHAR(255) DEFAULT NULL, MODIFY COLUMN locked_recipe_data JSON DEFAULT NULL");
    echo "<span class='success'>✓ locked_recipe_name en locked_recipe_data zijn nu optioneel</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Add type column to bakdagen_extra for sluitingen (holiday/closure blocking)
try {
    $pdo->exec("ALTER TABLE bakdagen_extra ADD COLUMN type ENUM('extra','sluiting') NOT NULL DEFAULT 'extra' AFTER notitie");
    echo "<span class='success'>✓ Kolom type (extra/sluiting) toegevoegd aan bakdagen_extra</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- type kolom bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration 055 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>bak_acties</code>: kolommen <code>dough_type_name</code>, <code>order_ids</code>, <code>total_qty</code>, <code>total_weight_g</code>, <code>start_time</code>, <code>end_time</code>, <code>water_temp</code>, <code>dough_temp</code> toegevoegd</li>
    <li><code>bak_acties</code>: <code>locked_recipe_name</code> en <code>locked_recipe_data</code> zijn nu optioneel (NULL toegestaan)</li>
    <li><code>bakdagen_extra</code>: kolom <code>type</code> toegevoegd (extra / sluiting) voor vakantie/sluiting blokkering</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
