<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 018</title>
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
<h1>Migration 018: Grain Types</h1>
<pre><?php

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grain_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<span class='success'>✓ Created grain_types table</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- grain_types table already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN is_whole_grain TINYINT(1) DEFAULT 0 AFTER category");
    echo "<span class='success'>✓ Added is_whole_grain column to ingredients</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- is_whole_grain column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN grain_type_id INT NULL AFTER is_whole_grain");
    echo "<span class='success'>✓ Added grain_type_id column to ingredients</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- grain_type_id column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE ingredients ADD INDEX idx_grain_type (grain_type_id)");
    echo "<span class='success'>✓ Added index on grain_type_id</span>\n";
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
    <li>Nieuwe tabel: grain_types (id, name) - voor graansoorten</li>
    <li>ingredients: +is_whole_grain (volkoren ja/nee)</li>
    <li>ingredients: +grain_type_id (FK naar grain_types)</li>
</ul>

<a href="../bakker/voorraad.php" class="btn">← Naar Voorraadbeheer</a>
</div>
</body>
</html>
