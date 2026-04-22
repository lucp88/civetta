<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 059</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 680px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #5c3d1e; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info    { color: #666; }
        .error   { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; font-size: 0.85rem; }
        a { color: #8b5a2b; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #8b5a2b; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #5c3d1e; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 059: Groepen, Merken &amp; Macronutriënten</h1>
<pre><?php

// 1. parent_id — self-referencing hierarchy (group → sub-product/brand)
try {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN parent_id INT NULL DEFAULT NULL");
    echo "<span class='success'>✓ Kolom parent_id toegevoegd aan ingredients</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false)
        echo "<span class='info'>- parent_id bestaat al</span>\n";
    else
        echo "<span class='error'>✗ parent_id: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 2. brand_name — on sub-products (children)
try {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN brand_name VARCHAR(255) NULL DEFAULT NULL");
    echo "<span class='success'>✓ Kolom brand_name toegevoegd aan ingredients</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false)
        echo "<span class='info'>- brand_name bestaat al</span>\n";
    else
        echo "<span class='error'>✗ brand_name: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 3. Macro columns (per 100g)
$macroColumns = [
    'kcal'            => 'DECIMAL(8,2) NULL',
    'protein_g'       => 'DECIMAL(8,2) NULL',
    'carbs_g'         => 'DECIMAL(8,2) NULL',
    'carbs_sugars_g'  => 'DECIMAL(8,2) NULL',
    'fat_g'           => 'DECIMAL(8,2) NULL',
    'fat_saturated_g' => 'DECIMAL(8,2) NULL',
    'fiber_g'         => 'DECIMAL(8,2) NULL',
    'salt_g'          => 'DECIMAL(8,2) NULL',
];
foreach ($macroColumns as $col => $type) {
    try {
        $pdo->exec("ALTER TABLE ingredients ADD COLUMN $col $type");
        echo "<span class='success'>✓ Kolom $col toegevoegd</span>\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false)
            echo "<span class='info'>- $col bestaat al</span>\n";
        else
            echo "<span class='error'>✗ $col: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 4. Drop ingredient_brands if it was created (replaced by parent_id approach)
try {
    $pdo->exec("DROP TABLE IF EXISTS ingredient_brands");
    echo "<span class='info'>- ingredient_brands tabel verwijderd (vervangen door parent_id)</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ drop ingredient_brands: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 5. Auto-migrate: for each existing top-level ingredient, create a default child sub-product
//    and move existing batches + allergenen to that child.
//    Only runs for ingredients that have no children yet (idempotent).
echo "\n<span class='info'>Bestaande ingrediënten migreren naar groep + standaard sub-product...</span>\n";

$toMigrate = $pdo->query(
    "SELECT i.* FROM ingredients i
     LEFT JOIN ingredients child ON child.parent_id = i.id
     WHERE i.parent_id IS NULL AND child.id IS NULL"
)->fetchAll();

if (empty($toMigrate)) {
    echo "<span class='info'>- Geen ingrediënten te migreren (al gedaan of leeg)</span>\n";
} else {
    $insChild = $pdo->prepare(
        "INSERT INTO ingredients (name, category, unit, parent_id, brand_name,
            is_whole_grain, grain_type_id, is_biologisch, is_allergeen, allergeen_naam,
            use_verpakkingen, sort_order, is_active)
         VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $moveBatches  = $pdo->prepare("UPDATE ingredient_batches SET ingredient_id = ? WHERE ingredient_id = ?");
    $getAllergens = $pdo->prepare("SELECT allergeen_naam FROM ingredient_allergenen WHERE ingredient_id = ?");
    $insAllergen  = $pdo->prepare("INSERT IGNORE INTO ingredient_allergenen (ingredient_id, allergeen_naam) VALUES (?, ?)");

    $migrated = 0;
    $errors   = 0;
    foreach ($toMigrate as $ing) {
        try {
            $insChild->execute([
                $ing['name'], $ing['category'], $ing['unit'], $ing['id'],
                $ing['is_whole_grain'], $ing['grain_type_id'],
                $ing['is_biologisch'], $ing['is_allergeen'], $ing['allergeen_naam'],
                $ing['use_verpakkingen'], $ing['sort_order'], $ing['is_active'],
            ]);
            $childId = $pdo->lastInsertId();

            $moveBatches->execute([$childId, $ing['id']]);

            $getAllergens->execute([$ing['id']]);
            foreach ($getAllergens->fetchAll(PDO::FETCH_COLUMN) as $a) {
                $insAllergen->execute([$childId, $a]);
            }
            $migrated++;
        } catch (PDOException $e) {
            echo "<span class='error'>✗ {$ing['name']}: " . htmlspecialchars($e->getMessage()) . "</span>\n";
            $errors++;
        }
    }
    echo "<span class='success'>✓ $migrated ingrediënten gemigreerd naar groep + standaard sub-product</span>\n";
    if ($errors) echo "<span class='error'>✗ $errors fouten — controleer hierboven</span>\n";
}

echo "\n<span class='success'>✓ Migration 059 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Kolom <code>parent_id</code> op <code>ingredients</code> — <code>NULL</code> = groep, ingevuld = sub-product/merk</li>
    <li>Kolom <code>brand_name</code> op <code>ingredients</code> — merknaam van het sub-product</li>
    <li>Kolommen <code>kcal, protein_g, carbs_g, carbs_sugars_g, fat_g, fat_saturated_g, fiber_g, salt_g</code> — macronutriënten per 100g</li>
    <li>Bestaande ingrediënten worden groepen; per ingrediënt één standaard sub-product aangemaakt; bestaande batches verplaatst naar het sub-product</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
