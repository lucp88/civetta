<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 027</title>
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
<h1>Migration 027: Bakdagen</h1>
<pre><?php

// Create bakdagen_extra table for impromptu baking days
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bakdagen_extra (
        id INT AUTO_INCREMENT PRIMARY KEY,
        datum DATE NOT NULL UNIQUE,
        notitie VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<span class='success'>✓ bakdagen_extra tabel aangemaakt</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Insert default settings
try {
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bakdagen_patroon', '')");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bakdagen_voorbereiding_dagen', '3')");
    echo "<span class='success'>✓ Standaard bakdagen instellingen toegevoegd</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 027 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Nieuwe tabel: bakdagen_extra (extra bakdagen met datum en notitie)</li>
    <li>settings: +bakdagen_patroon (wekelijks bakpatroon)</li>
    <li>settings: +bakdagen_voorbereiding_dagen (aantal dagen voorbereiding)</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
