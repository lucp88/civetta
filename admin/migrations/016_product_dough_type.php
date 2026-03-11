<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 016</title>
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
<h1>Migration 016: Product Dough Type</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE products ADD COLUMN dough_type_id INT NULL AFTER foto");
    echo "<span class='success'>✓ Added dough_type_id column to products</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- dough_type_id column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE products ADD INDEX idx_product_dough_type (dough_type_id)");
    echo "<span class='success'>✓ Added index on dough_type_id</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key') !== false) {
        echo "<span class='info'>- Index already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration completed!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>products: +dough_type_id (FK naar dough_types)</li>
    <li>Recepten bij varianten worden gefilterd op de deegsoort van het product</li>
</ul>

<a href="../index.php" class="btn">← Terug naar Admin</a>
</div>
</body>
</html>
