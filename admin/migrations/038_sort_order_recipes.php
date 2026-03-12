<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 038</title>
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
<h1>Migration 038: Sort order recepten & deegsoorten</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE dough_types ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
    echo "<span class='success'>✓ Kolom sort_order toegevoegd aan dough_types</span>\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "<span class='info'>- sort_order bestaat al in dough_types</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE baker_recipes ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
    echo "<span class='success'>✓ Kolom sort_order toegevoegd aan baker_recipes</span>\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "<span class='info'>- sort_order bestaat al in baker_recipes</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Initialize sort_order for dough_types based on current name order
try {
    $rows = $pdo->query("SELECT id FROM dough_types ORDER BY name ASC")->fetchAll();
    foreach ($rows as $i => $row) {
        $pdo->prepare("UPDATE dough_types SET sort_order = ? WHERE id = ?")->execute([$i, $row['id']]);
    }
    echo "<span class='success'>✓ sort_order geïnitialiseerd voor " . count($rows) . " deegsoorten</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Initialize sort_order for baker_recipes based on current name order within dough type
try {
    $rows = $pdo->query("SELECT id, dough_type_id FROM baker_recipes ORDER BY dough_type_id ASC, name ASC")->fetchAll();
    $counters = [];
    foreach ($rows as $row) {
        $key = $row['dough_type_id'] ?? 'null';
        $counters[$key] = ($counters[$key] ?? 0);
        $pdo->prepare("UPDATE baker_recipes SET sort_order = ? WHERE id = ?")->execute([$counters[$key], $row['id']]);
        $counters[$key]++;
    }
    echo "<span class='success'>✓ sort_order geïnitialiseerd voor " . count($rows) . " recepten</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 038 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Kolom <code>sort_order</code> toegevoegd aan <code>dough_types</code></li>
    <li>Kolom <code>sort_order</code> toegevoegd aan <code>baker_recipes</code></li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
