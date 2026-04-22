<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 056</title>
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
<h1>Migration 056: Deegsoort Versioning</h1>
<pre><?php

try {
    $pdo->exec("
        CREATE TABLE dough_type_versions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            dough_type_id INT NOT NULL,
            version_number INT NOT NULL DEFAULT 1,
            name VARCHAR(255) NOT NULL,
            recipe_data JSON NOT NULL,
            note VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dtv (dough_type_id, version_number),
            FOREIGN KEY (dough_type_id) REFERENCES dough_types(id) ON DELETE CASCADE
        )
    ");
    echo "<span class='success'>✓ Tabel dough_type_versions aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- Tabel dough_type_versions bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE dough_types ADD COLUMN current_version INT NOT NULL DEFAULT 1 AFTER recipe_data");
    echo "<span class='success'>✓ Kolom current_version toegevoegd aan dough_types</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Kolom current_version bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Snapshot existing dough types as version 1
$doughTypes = $pdo->query("SELECT id, name, recipe_data FROM dough_types")->fetchAll();
$stmt = $pdo->prepare("INSERT IGNORE INTO dough_type_versions (dough_type_id, version_number, name, recipe_data, note) VALUES (?, 1, ?, ?, 'Initiële versie')");
$count = 0;
foreach ($doughTypes as $dt) {
    try {
        $recipeData = $dt['recipe_data'] ?: '{}';
        $stmt->execute([$dt['id'], $dt['name'], $recipeData]);
        $count++;
    } catch (PDOException $e) {
        // Skip duplicates
    }
}
echo "<span class='success'>✓ $count bestaande deegsoorten opgeslagen als versie 1</span>\n";

echo "\n<span class='success'>✓ Migration 056 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Nieuwe tabel <code>dough_type_versions</code> — versiegeschiedenis per deegsoort</li>
    <li>Kolom <code>current_version</code> toegevoegd aan <code>dough_types</code></li>
    <li>Bestaande deegsoorten opgeslagen als versie 1</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
