<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 057</title>
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
<h1>Migration 057: Bakactie uitgebreide velden</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE bak_acties
        ADD COLUMN flour_temp    DECIMAL(5,2) DEFAULT NULL AFTER dough_temp,
        ADD COLUMN ambient_temp  DECIMAL(5,2) DEFAULT NULL AFTER flour_temp,
        ADD COLUMN oven_temp     DECIMAL(5,2) DEFAULT NULL AFTER ambient_temp,
        ADD COLUMN bake_time_minutes SMALLINT UNSIGNED DEFAULT NULL AFTER oven_temp,
        ADD COLUMN notes_data    JSON         DEFAULT NULL AFTER notes
    ");
    echo "<span class='success'>✓ Kolommen flour_temp, ambient_temp, oven_temp, bake_time_minutes, notes_data toegevoegd</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Kolommen bestaan al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration 057 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>flour_temp</code> — meeltemperatuur (°C)</li>
    <li><code>ambient_temp</code> — omgevingstemperatuur (°C)</li>
    <li><code>oven_temp</code> — oventemperatuur (°C)</li>
    <li><code>bake_time_minutes</code> — baktijd in minuten</li>
    <li><code>notes_data</code> — gestructureerde notities (JSON: afwijkingen, observaties, algemeen, kwaliteit, stap-notities)</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
