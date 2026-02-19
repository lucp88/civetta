<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 014</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
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
<h1>Migration 014: Variant Recipe Link</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE product_variants ADD COLUMN recipe_id INT NULL AFTER prijs");
    echo "<span class='success'>✓ Added recipe_id column to product_variants</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- recipe_id column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE product_variants ADD COLUMN dough_weight INT NULL AFTER recipe_id");
    echo "<span class='success'>✓ Added dough_weight column to product_variants</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- dough_weight column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE product_variants ADD INDEX idx_recipe_id (recipe_id)");
    echo "<span class='success'>✓ Added index on recipe_id</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key') !== false) {
        echo "<span class='info'>- Index already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE products DROP COLUMN standard_weight");
    echo "<span class='success'>✓ Removed standard_weight column from products</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "check that column/key exists") !== false || strpos($e->getMessage(), "Unknown column") !== false) {
        echo "<span class='info'>- standard_weight column does not exist</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE products DROP COLUMN recipe_id");
    echo "<span class='success'>✓ Removed recipe_id column from products (moved to variants)</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "check that column/key exists") !== false || strpos($e->getMessage(), "Unknown column") !== false) {
        echo "<span class='info'>- recipe_id column does not exist on products</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration completed!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>product_variants: +recipe_id, +dough_weight</li>
    <li>products: -standard_weight, -recipe_id (verplaatst naar variants)</li>
</ul>

<a href="../index.php" class="btn">← Terug naar Admin</a>
</div>
</body>
</html>
