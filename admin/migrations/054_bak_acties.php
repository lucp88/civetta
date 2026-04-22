<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 054</title>
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
<h1>Migration 054: Bakacties</h1>
<pre><?php

try {
    $pdo->exec("
        CREATE TABLE bak_acties (
            id INT PRIMARY KEY AUTO_INCREMENT,
            recipe_id INT,
            recipe_version_id INT,
            locked_recipe_name VARCHAR(255) NOT NULL,
            locked_recipe_data JSON NOT NULL,
            datum DATETIME NOT NULL,
            bakker VARCHAR(100),
            notes TEXT,
            status ENUM('gepland','bezig','voltooid') NOT NULL DEFAULT 'gepland',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES baker_recipes(id) ON DELETE SET NULL,
            FOREIGN KEY (recipe_version_id) REFERENCES baker_recipe_versions(id) ON DELETE SET NULL
        )
    ");
    echo "<span class='success'>✓ Tabel bak_acties aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- Tabel bak_acties bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration 054 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Nieuwe tabel <code>bak_acties</code> met gekoppeld recept (snapshot), datum, bakker, notities en status</li>
    <li>FK naar <code>baker_recipes</code> en <code>baker_recipe_versions</code> (SET NULL bij verwijderen)</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
