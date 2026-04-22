<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 053</title>
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
<h1>Migration 053: Receptuur Versioning</h1>
<pre><?php

try {
    $pdo->exec("
        CREATE TABLE baker_recipe_versions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            recipe_id INT NOT NULL,
            version_number INT NOT NULL DEFAULT 1,
            name VARCHAR(255) NOT NULL,
            recipe_data JSON NOT NULL,
            note VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_recipe_version (recipe_id, version_number),
            FOREIGN KEY (recipe_id) REFERENCES baker_recipes(id) ON DELETE CASCADE
        )
    ");
    echo "<span class='success'>✓ Tabel baker_recipe_versions aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- Tabel baker_recipe_versions bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE baker_recipes ADD COLUMN current_version INT NOT NULL DEFAULT 1 AFTER updated_at");
    echo "<span class='success'>✓ Kolom current_version toegevoegd aan baker_recipes</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Kolom current_version bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Snapshot existing recipes as version 1
$recipes = $pdo->query("SELECT id, name, recipe_data FROM baker_recipes")->fetchAll();
$stmt = $pdo->prepare("INSERT IGNORE INTO baker_recipe_versions (recipe_id, version_number, name, recipe_data, note) VALUES (?, 1, ?, ?, 'Initiële versie')");
$count = 0;
foreach ($recipes as $r) {
    try {
        $stmt->execute([$r['id'], $r['name'], $r['recipe_data']]);
        $count++;
    } catch (PDOException $e) {
        // Skip duplicates
    }
}
echo "<span class='success'>✓ $count bestaande recepten opgeslagen als versie 1</span>\n";

echo "\n<span class='success'>✓ Migration 053 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Nieuwe tabel <code>baker_recipe_versions</code> — versiegeschiedenis per recept</li>
    <li>Kolom <code>current_version</code> toegevoegd aan <code>baker_recipes</code></li>
    <li>Bestaande recepten opgeslagen als versie 1</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
