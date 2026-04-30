<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 064</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 700px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
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
<h1>Migration 064: Backfill locked_recipe_data</h1>
<pre><?php

// Find all bakacties missing locked_recipe_data but with a known dough_type_name
$stmt = $pdo->query("
    SELECT ba.id, ba.dough_type_name, ba.total_qty, ba.total_weight_g, ba.status,
           dt.recipe_data
    FROM bak_acties ba
    LEFT JOIN dough_types dt ON dt.name = ba.dough_type_name
    WHERE ba.locked_recipe_data IS NULL
      AND ba.dough_type_name IS NOT NULL
      AND ba.dough_type_name != ''
    ORDER BY ba.id
");
$rows = $stmt->fetchAll();

echo "Bakacties zonder locked_recipe_data: " . count($rows) . "\n\n";

$updated  = 0;
$skipped  = 0;

$upStmt = $pdo->prepare("UPDATE bak_acties SET locked_recipe_data = ? WHERE id = ?");

foreach ($rows as $row) {
    if (!$row['recipe_data']) {
        echo "<span class='info'>- #{$row['id']} {$row['dough_type_name']} — geen recept in dough_types, overgeslagen</span>\n";
        $skipped++;
        continue;
    }
    try {
        $upStmt->execute([$row['recipe_data'], $row['id']]);
        $status = $row['status'];
        $qty    = $row['total_qty'];
        $weight = $row['total_weight_g'];
        echo "<span class='success'>✓ #{$row['id']} {$row['dough_type_name']} ({$status}, {$qty}st, {$weight}g) — recept gekoppeld</span>\n";
        $updated++;
    } catch (PDOException $e) {
        echo "<span class='error'>✗ #{$row['id']}: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration 064 voltooid: {$updated} bijgewerkt, {$skipped} overgeslagen</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Vult <code>locked_recipe_data</code> in voor bestaande bakacties die het missen, door het huidige recept van het bijbehorende deegtype te kopiëren</li>
    <li>Zorgt dat de desemafschrijving werkt voor bakacties aangemaakt vóór het bijhouden van receptversies</li>
</ul>

<a href="../bakker/logboek.php" class="btn">← Naar Logboek</a>
</div>
</body>
</html>
