<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 069</title>
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
<h1>Migration 069: Broodrecept major.minor versienummering</h1>
<pre><?php

// 1. Add columns
try {
    $pdo->exec("ALTER TABLE baker_recipe_versions
        ADD COLUMN dough_type_version_number SMALLINT DEFAULT NULL AFTER version_number,
        ADD COLUMN loaf_minor_version SMALLINT DEFAULT NULL AFTER dough_type_version_number");
    echo "<span class='success'>✓ Kolommen dough_type_version_number + loaf_minor_version toegevoegd</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Kolommen bestaan al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 2. Backfill dough_type_version_number from the recipe's current dough type
try {
    $pdo->exec("
        UPDATE baker_recipe_versions brv
        JOIN baker_recipes br ON br.id = brv.recipe_id
        JOIN dough_types dt ON dt.id = br.dough_type_id
        SET brv.dough_type_version_number = COALESCE(dt.current_version, 1)
        WHERE br.dough_type_id IS NOT NULL
          AND brv.dough_type_version_number IS NULL
    ");
    echo "<span class='success'>✓ dough_type_version_number ingevuld voor bestaande versies</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Backfill dough_type_version_number: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 3. Backfill loaf_minor_version: sequential within (recipe_id, dough_type_version_number)
try {
    $pdo->exec("
        UPDATE baker_recipe_versions brv
        JOIN (
            SELECT id,
                   ROW_NUMBER() OVER (
                       PARTITION BY recipe_id, dough_type_version_number
                       ORDER BY version_number ASC
                   ) AS rn
            FROM baker_recipe_versions
            WHERE dough_type_version_number IS NOT NULL
        ) ranked ON ranked.id = brv.id
        SET brv.loaf_minor_version = ranked.rn
        WHERE brv.dough_type_version_number IS NOT NULL
    ");
    echo "<span class='success'>✓ loaf_minor_version ingevuld voor bestaande versies</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Backfill loaf_minor_version: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Report
$count = $pdo->query("SELECT COUNT(*) FROM baker_recipe_versions WHERE dough_type_version_number IS NOT NULL")->fetchColumn();
echo "<span class='info'>  → $count versies hebben nu een major.minor versienummer</span>\n";

$legacy = $pdo->query("SELECT COUNT(*) FROM baker_recipe_versions WHERE dough_type_version_number IS NULL")->fetchColumn();
if ($legacy > 0) {
    echo "<span class='info'>  → $legacy versies zonder deegsoort koppeling behouden het oude versienummer</span>\n";
}

echo "\n<span class='success'>✓ Migration 069 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Kolom <code>dough_type_version_number</code> (SMALLINT) toegevoegd aan <code>baker_recipe_versions</code> — welke deegsoortversie (major) dit broodrecept gebruikt</li>
    <li>Kolom <code>loaf_minor_version</code> (SMALLINT) toegevoegd aan <code>baker_recipe_versions</code> — het minor versienummer binnen die major</li>
    <li>Bestaande versies zijn teruggevuld: major = huidige deegsoortversie, minor = sequentieel genummerd</li>
    <li>Broodrecepten tonen voortaan versie als <strong>v{major}.{minor}</strong> (bijv. v2.3)</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
