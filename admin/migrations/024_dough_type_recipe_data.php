<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 024</title>
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
<h1>Migration 024: Dough Type Recipe Data</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE dough_types ADD COLUMN recipe_data JSON NULL");
    echo "<span class='success'>✓ Added recipe_data column to dough_types</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- recipe_data column already exists</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration completed!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>dough_types: +recipe_data (JSON, NULL) — bevat de basis deegreceptuur</li>
    <li>Deegsoorten kunnen nu hydratatie, meelsamenstelling, zuurdesem en gist opslaan</li>
    <li>Recepten erven van hun deegsoort en stellen alleen deeggewicht, toevoegingen en methode in</li>
</ul>

<a href="../index.php" class="btn">← Terug naar Admin</a>
</div>
</body>
</html>
