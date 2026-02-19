<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 017</title>
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
<h1>Migration 017: Clear Ingredients</h1>
<pre><?php

try {
    $countBatches = $pdo->query("SELECT COUNT(*) FROM ingredient_batches")->fetchColumn();
    $pdo->exec("DELETE FROM ingredient_batches");
    echo "<span class='success'>✓ Deleted $countBatches ingredient batches</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $countIngredients = $pdo->query("SELECT COUNT(*) FROM ingredients")->fetchColumn();
    $pdo->exec("DELETE FROM ingredients");
    echo "<span class='success'>✓ Deleted $countIngredients ingredients</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $pdo->exec("ALTER TABLE ingredients AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE ingredient_batches AUTO_INCREMENT = 1");
    echo "<span class='success'>✓ Reset AUTO_INCREMENT counters</span>\n";
} catch (PDOException $e) {
    echo "<span class='info'>- Could not reset AUTO_INCREMENT: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration completed!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Alle ingredient_batches verwijderd</li>
    <li>Alle ingredients verwijderd</li>
    <li>Klaar om zelf opnieuw in te vullen</li>
</ul>

<a href="../bakker/voorraad.php" class="btn">← Naar Voorraadbeheer</a>
</div>
</body>
</html>
