<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 049</title>
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
<h1>Migration 049: Meerdere allergenen per ingrediënt</h1>
<pre><?php

// 1. Create junction table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ingredient_allergenen (
            ingredient_id INT NOT NULL,
            allergeen_naam VARCHAR(100) NOT NULL,
            PRIMARY KEY (ingredient_id, allergeen_naam),
            CONSTRAINT fk_ia_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<span class='success'>✓ Tabel ingredient_allergenen aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- Tabel ingredient_allergenen bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 2. Migrate existing single-allergen data
try {
    $stmt = $pdo->query("
        INSERT IGNORE INTO ingredient_allergenen (ingredient_id, allergeen_naam)
        SELECT id, allergeen_naam
        FROM ingredients
        WHERE is_allergeen = 1
          AND allergeen_naam IS NOT NULL
          AND allergeen_naam != ''
    ");
    $rows = $stmt->rowCount();
    echo "<span class='success'>✓ $rows bestaande allergenen gemigreerd naar ingredient_allergenen</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Migratie bestaande data: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 049 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Nieuwe tabel <code>ingredient_allergenen</code> voor meerdere allergenen per ingrediënt</li>
    <li>Bestaande <code>allergeen_naam</code> data gemigreerd naar de nieuwe tabel</li>
    <li>De oude kolommen <code>is_allergeen</code> en <code>allergeen_naam</code> blijven bestaan voor backward compatibility</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
