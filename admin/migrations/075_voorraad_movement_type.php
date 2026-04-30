<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 075</title>
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
<h1>Migration 075: Voorraad beweging actie-type</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE voorraad_movements ADD COLUMN movement_type VARCHAR(50) NULL DEFAULT NULL AFTER bakactie_id");
    echo "<span class='success'>✓ Kolom movement_type toegevoegd aan voorraad_movements</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false)
        echo "<span class='info'>- movement_type bestaat al</span>\n";
    else
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Backfill: sourdough_consumed movements → pre-ferment, others → deeg
// We identify sourdough movements by cross-referencing bak_acties.sourdough_consumed
// and matching movement created_at with sourdough consumption timestamps
// Simpler: any movement where ALL linked inventory_consumption rows belong to
// an ingredient that was the only ingredient consumed (i.e. single-ingredient movements)
// is likely sourdough. But we can't reliably backfill without extra context.
// Leave existing movements as NULL — they'll show without a badge.
echo "<span class='info'>- Bestaande bewegingen krijgen geen type (NULL), nieuwe bewegingen worden gelabeld</span>\n";

echo "\n<span class='success'>✓ Migration 075 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>voorraad_movements.movement_type</code> — actie-categorie: <code>pre-ferment</code>, <code>deeg</code>, <code>vormen</code>, of <code>bakken</code></li>
    <li>Nieuwe afschrijvingen worden automatisch gelabeld; bestaande blijven leeg</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
