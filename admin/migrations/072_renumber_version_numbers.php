<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 072</title>
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
<h1>Migration 072: Hernummer version_numbers sequentieel</h1>
<pre><?php

$pdo->beginTransaction();
$renumbered = 0;
$skipped    = 0;

try {
    $recipes = $pdo->query("
        SELECT DISTINCT br.id, br.name, br.current_version
        FROM baker_recipes br
        JOIN baker_recipe_versions brv ON brv.recipe_id = br.id
        ORDER BY br.name ASC
    ")->fetchAll();

    echo "Gevonden: " . count($recipes) . " recepten\n\n";

    foreach ($recipes as $recipe) {
        $rid = (int)$recipe['id'];

        // Get all versions ordered by major then minor (legacy/null major comes first as 0)
        $rowStmt = $pdo->prepare("
            SELECT id, version_number, dough_type_version_number, loaf_minor_version
            FROM baker_recipe_versions
            WHERE recipe_id = ?
            ORDER BY
                COALESCE(dough_type_version_number, 0) ASC,
                COALESCE(loaf_minor_version, version_number) ASC
        ");
        $rowStmt->execute([$rid]);
        $versions = $rowStmt->fetchAll();

        // Check if already 1, 2, 3… in that order
        $alreadyOk = true;
        foreach ($versions as $i => $v) {
            if ((int)$v['version_number'] !== $i + 1) { $alreadyOk = false; break; }
        }
        if ($alreadyOk) {
            echo "<span class='info'>- {$recipe['name']}: al in volgorde</span>\n";
            $skipped++;
            continue;
        }

        // Build old → new mapping
        $oldToNew = [];
        foreach ($versions as $i => $v) {
            $oldToNew[(int)$v['version_number']] = $i + 1;
        }

        $newCurrentVersion = $oldToNew[(int)$recipe['current_version']] ?? null;
        if (!$newCurrentVersion) {
            echo "<span class='warn'>⚠ {$recipe['name']}: actieve versie niet in mapping — overgeslagen</span>\n";
            $skipped++;
            continue;
        }

        // Step 1: Shift far out of range to avoid collision during reassignment
        $pdo->prepare("
            UPDATE baker_recipe_versions SET version_number = version_number + 100000 WHERE recipe_id = ?
        ")->execute([$rid]);

        // Step 2: Assign new sequential numbers by row id
        $upd = $pdo->prepare("UPDATE baker_recipe_versions SET version_number = ? WHERE id = ?");
        foreach ($versions as $i => $v) {
            $upd->execute([$i + 1, (int)$v['id']]);
        }

        // Step 3: Update current_version pointer
        $pdo->prepare("UPDATE baker_recipes SET current_version = ? WHERE id = ?")->execute([$newCurrentVersion, $rid]);

        echo "<span class='success'>✓ {$recipe['name']}: current_version {$recipe['current_version']} → {$newCurrentVersion}</span>\n";
        $renumbered++;
    }

    $pdo->commit();
    echo "\n<span class='success'>✓ Migration 072 voltooid — $renumbered recepten hernummerd</span>";
    if ($skipped) echo ", <span class='info'>$skipped al in orde</span>";
    echo "\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    echo "<span class='error'>✗ Fout — alles teruggedraaid: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}
?>
</pre>

<p><strong>Wat deze migratie doet:</strong></p>
<ul>
    <li>Nummert <code>version_number</code> opnieuw als 1, 2, 3… per recept</li>
    <li>Volgorde: deegsoort-major ASC, dan minor ASC (legacy versies eerst)</li>
    <li>Past <code>baker_recipes.current_version</code> aan zodat deze naar de juiste rij blijft wijzen</li>
    <li>Recepten die al 1, 2, 3… lopen worden overgeslagen</li>
    <li>Alles loopt in één transactie — bij een fout wordt alles teruggedraaid</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
