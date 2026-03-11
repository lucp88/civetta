<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 032</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2d4a2d; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info    { color: #666; }
        .error   { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #3d6b3d; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #3d6b3d; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #2d4a2d; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 032: Ingredient allergeen</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN is_allergeen TINYINT(1) NOT NULL DEFAULT 0 AFTER is_biologisch");
    echo "<span class='success'>✓ is_allergeen kolom toegevoegd aan ingredients</span>\n";
} catch (PDOException $e) {
    echo strpos($e->getMessage(), 'Duplicate') !== false
        ? "<span class='info'>- is_allergeen kolom bestaat al</span>\n"
        : "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN allergeen_naam VARCHAR(100) DEFAULT NULL AFTER is_allergeen");
    echo "<span class='success'>✓ allergeen_naam kolom toegevoegd aan ingredients</span>\n";
} catch (PDOException $e) {
    echo strpos($e->getMessage(), 'Duplicate') !== false
        ? "<span class='info'>- allergeen_naam kolom bestaat al</span>\n"
        : "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 032 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>ingredients: +is_allergeen (markeer ingrediënten als allergeen)</li>
    <li>ingredients: +allergeen_naam (optionele naam van het allergeen, bijv. "melk")</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
