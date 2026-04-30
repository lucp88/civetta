<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 071</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 700px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #5c3d1e; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info    { color: #666; }
        .error   { color: #c62828; }
        .warn    { color: #b45309; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; font-size: 0.85rem; }
        a { color: #8b5a2b; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #8b5a2b; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #5c3d1e; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 071: Sync version_number → loaf_minor_version</h1>
<pre><?php

$pdo->beginTransaction();
$skipped = 0;
$synced  = 0;
$errors  = 0;

try {
    // Get all recipes that have at least one compound-versioned entry
    $recipes = $pdo->query("
        SELECT DISTINCT br.id, br.current_version, br.name
        FROM baker_recipes br
        JOIN baker_recipe_versions brv ON brv.recipe_id = br.id
        WHERE brv.dough_type_version_number IS NOT NULL
          AND brv.loaf_minor_version IS NOT NULL
    ")->fetchAll();

    echo "Gevonden: " . count($recipes) . " recepten met major.minor versienummering\n\n";

    foreach ($recipes as $recipe) {
        $rid = (int)$recipe['id'];

        // Find the active version row (version_number = current_version) and its loaf_minor_version
        $activeStmt = $pdo->prepare("
            SELECT id, version_number, loaf_minor_version
            FROM baker_recipe_versions
            WHERE recipe_id = ? AND version_number = ?
              AND dough_type_version_number IS NOT NULL
            LIMIT 1
        ");
        $activeStmt->execute([$rid, $recipe['current_version']]);
        $activeRow = $activeStmt->fetch();

        if (!$activeRow) {
            echo "<span class='warn'>⚠ {$recipe['name']}: actieve versie niet gevonden — overgeslagen</span>\n";
            $skipped++;
            continue;
        }

        $newCurrentVersion = (int)$activeRow['loaf_minor_version'];

        // Check for duplicate loaf_minor_version values within this recipe (would cause collisions)
        $dupCheck = $pdo->prepare("
            SELECT loaf_minor_version, COUNT(*) as cnt
            FROM baker_recipe_versions
            WHERE recipe_id = ? AND dough_type_version_number IS NOT NULL AND loaf_minor_version IS NOT NULL
            GROUP BY loaf_minor_version HAVING cnt > 1
        ");
        $dupCheck->execute([$rid]);
        $dups = $dupCheck->fetchAll();
        if ($dups) {
            $dupNums = implode(', ', array_column($dups, 'loaf_minor_version'));
            echo "<span class='warn'>⚠ {$recipe['name']}: dubbele minor versienummers ($dupNums) — overgeslagen</span>\n";
            $skipped++;
            continue;
        }

        // Step 1: Shift version_number far out of range to avoid collision during reassignment
        $pdo->prepare("
            UPDATE baker_recipe_versions
            SET version_number = version_number + 100000
            WHERE recipe_id = ? AND dough_type_version_number IS NOT NULL
        ")->execute([$rid]);

        // Step 2: Set version_number = loaf_minor_version
        $pdo->prepare("
            UPDATE baker_recipe_versions
            SET version_number = loaf_minor_version
            WHERE recipe_id = ? AND dough_type_version_number IS NOT NULL
        ")->execute([$rid]);

        // Step 3: Sync baker_recipes.current_version to the new version_number of the active version
        $pdo->prepare("
            UPDATE baker_recipes SET current_version = ? WHERE id = ?
        ")->execute([$newCurrentVersion, $rid]);

        echo "<span class='success'>✓ {$recipe['name']}: current_version {$recipe['current_version']} → {$newCurrentVersion}</span>\n";
        $synced++;
    }

    $pdo->commit();

    echo "\n<span class='success'>✓ Migration 071 voltooid — $synced recepten gesynchroniseerd</span>";
    if ($skipped) echo ", <span class='warn'>$skipped overgeslagen</span>";
    echo "\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    echo "<span class='error'>✗ Fout — alles teruggedraaid: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    $errors++;
}

?></pre>

<p><strong>Wat deze migratie doet:</strong></p>
<ul>
    <li>Zet <code>version_number</code> gelijk aan <code>loaf_minor_version</code> voor alle broodreceptversies met een deegsoort-koppeling</li>
    <li>Past <code>baker_recipes.current_version</code> aan zodat deze naar de juiste rij blijft wijzen</li>
    <li>Recepten met dubbele minor-nummers worden overgeslagen (geen dataverlies)</li>
    <li>Alles loopt in één transactie — bij een fout wordt alles teruggedraaid</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
