<?php
require_once '../config.php';
requireLogin();

$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$bereidingDate = new DateTime($date);
// Baking day = delivery day (no offset)
$deliveryDate = clone $bereidingDate;

$stmt = $pdo->prepare("
    SELECT 
        boi.product_name, 
        boi.quantity,
        boi.unit_price,
        pv.recipe_id,
        pv.gewicht as variant_weight,
        br.name as recipe_name,
        br.recipe_data
    FROM business_orders bo
    JOIN business_order_items boi ON bo.id = boi.order_id
    LEFT JOIN product_variants pv ON pv.id = (
        SELECT pv2.id FROM product_variants pv2
        LEFT JOIN products p2 ON pv2.product_id = p2.id
        WHERE LOWER(TRIM(pv2.naam)) = LOWER(TRIM(boi.product_name))
           OR LOWER(TRIM(p2.naam)) = LOWER(TRIM(boi.product_name))
           OR LOWER(TRIM(boi.product_name)) LIKE CONCAT(LOWER(TRIM(p2.naam)), ' (%')
        ORDER BY (LOWER(TRIM(pv2.naam)) = LOWER(TRIM(boi.product_name))) DESC,
                 (pv2.recipe_id IS NOT NULL) DESC
        LIMIT 1
    )
    LEFT JOIN products p ON pv.product_id = p.id
    LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
    WHERE bo.delivery_date = ?
    AND bo.is_cancelled = 0
");
$stmt->execute([$deliveryDate->format('Y-m-d')]);
$items = $stmt->fetchAll();

$recipes = [];
$noRecipe = ['products' => [], 'total_qty' => 0, 'total_weight' => 0];

foreach ($items as $item) {
    $qty = intval($item['quantity']);
    $variantWeight = intval($item['variant_weight'] ?? 0);
    
    $doughWeight = 0;
    if (!empty($item['recipe_data'])) {
        $recipeData = json_decode($item['recipe_data'], true);
        $doughWeight = intval($recipeData['doughWeight'] ?? 0);
    }
    $weight = $doughWeight > 0 ? $doughWeight : ($variantWeight > 0 ? $variantWeight : 300);
    
    if ($item['recipe_id'] && $item['recipe_data']) {
        $recipeId = $item['recipe_id'];
        if (!isset($recipes[$recipeId])) {
            $recipes[$recipeId] = [
                'name' => $item['recipe_name'],
                'data' => json_decode($item['recipe_data'], true),
                'products' => [],
                'total_qty' => 0,
                'total_weight' => 0
            ];
        }
        if (!isset($recipes[$recipeId]['products'][$item['product_name']])) {
            $recipes[$recipeId]['products'][$item['product_name']] = ['qty' => 0, 'weight' => $weight];
        }
        $recipes[$recipeId]['products'][$item['product_name']]['qty'] += $qty;
        $recipes[$recipeId]['total_qty'] += $qty;
        $recipes[$recipeId]['total_weight'] += $qty * $weight;
    } else {
        if (!isset($noRecipe['products'][$item['product_name']])) {
            $noRecipe['products'][$item['product_name']] = ['qty' => 0, 'weight' => $weight];
        }
        $noRecipe['products'][$item['product_name']]['qty'] += $qty;
        $noRecipe['total_qty'] += $qty;
        $noRecipe['total_weight'] += $qty * $weight;
    }
}

function calculateIngredients($recipeData, $totalQty, $totalWeight) {
    $numberOfBalls = $totalQty;
    $weightPerBall = $totalQty > 0 ? $totalWeight / $totalQty : 300;
    
    $hydration = $recipeData['hydration'] ?? 62;
    $saltPct = $recipeData['saltPct'] ?? 2.6;
    
    $totalDoughWeight = $numberOfBalls * $weightPerBall;
    $totalFlour = $totalDoughWeight / (1 + $hydration/100 + $saltPct/100);
    $totalWater = $totalFlour * ($hydration/100);
    $saltWeight = $totalFlour * ($saltPct/100);
    
    $result = [
        'totalFlour' => round($totalFlour),
        'totalWater' => round($totalWater),
        'saltWeight' => round($saltWeight),
        'totalDoughWeight' => round($totalDoughWeight),
        'hydration' => $hydration,
        'saltPct' => $saltPct,
        'grains' => [],
        'leveners' => [],
        'mixins' => [],
        'toppings' => [],
        'preFerment' => null,
        'sourdough' => null
    ];
    
    $grainTypes = [
        'wheat_white' => 'Tarwe wit', 'wheat_whole' => 'Tarwe volkoren',
        'spelt_white' => 'Spelt wit', 'spelt_whole' => 'Spelt volkoren',
        'durum' => 'Durum', 'emmer' => 'Emmer',
        'rye_white' => 'Rogge wit', 'rye_whole' => 'Rogge volkoren',
        'einkorn' => 'Einkorn', 'buckwheat' => 'Boekweit',
        'rice' => 'Rijst', 'barley' => 'Gerst', 'teff' => 'Teff'
    ];
    
    $mainFlour = $totalFlour;
    
    if (!empty($recipeData['useSourdough']) && !empty($recipeData['sourdoughPct'])) {
        $sdPct = $recipeData['sourdoughPct'];
        $sdHydration = $recipeData['sourdoughHydration'] ?? 100;
        $sdWeight = $totalFlour * ($sdPct / 100);
        $sdFlour = $sdWeight / (1 + $sdHydration/100);
        $sdWater = $sdWeight - $sdFlour;
        $mainFlour -= $sdFlour;
        $result['sourdough'] = [
            'weight' => round($sdWeight),
            'flour' => round($sdFlour),
            'water' => round($sdWater),
            'hydration' => $sdHydration,
            'pct' => $sdPct
        ];
    }
    
    if (!empty($recipeData['usePreFerment']) && !empty($recipeData['preFermentPct'])) {
        $pfPct = $recipeData['preFermentPct'];
        $pfHydration = $recipeData['preFermentHydration'] ?? 100;
        $pfWeight = $totalFlour * ($pfPct / 100);
        $pfFlour = $pfWeight / (1 + $pfHydration/100);
        $pfWater = $pfWeight - $pfFlour;
        $mainFlour -= $pfFlour;
        $result['preFerment'] = [
            'weight' => round($pfWeight),
            'flour' => round($pfFlour),
            'water' => round($pfWater),
            'hydration' => $pfHydration,
            'pct' => $pfPct
        ];
    }
    
    $mainGrains = $recipeData['mainDoughGrains'] ?? [['type' => 'wheat_white', 'pct' => 100]];
    foreach ($mainGrains as $grain) {
        if ($grain['pct'] > 0) {
            $grainWeight = $mainFlour * ($grain['pct'] / 100);
            $result['grains'][] = [
                'name' => $grainTypes[$grain['type']] ?? $grain['type'],
                'weight' => round($grainWeight),
                'pct' => $grain['pct']
            ];
        }
    }
    
    if (!empty($recipeData['useYeast']) && !empty($recipeData['yeastPct'])) {
        $yeastTypes = [
            'fresh_yeast' => 'Verse gist',
            'instant_yeast' => 'Instant gist',
            'sourdough_culture' => 'Desemcultuur'
        ];
        $yeastWeight = $totalFlour * ($recipeData['yeastPct'] / 100);
        $result['leveners'][] = [
            'name' => $yeastTypes[$recipeData['yeastType']] ?? 'Gist',
            'weight' => round($yeastWeight),
            'pct' => $recipeData['yeastPct']
        ];
    }
    
    $mixins = $recipeData['mixins'] ?? [];
    $mixinMode = $recipeData['mixinMode'] ?? 'flour';
    $baseForMixin = $mixinMode === 'dough' ? $totalDoughWeight : $totalFlour;
    foreach ($mixins as $m) {
        if (!empty($m['ingredient']) && $m['pct'] > 0) {
            $mWeight = $baseForMixin * ($m['pct'] / 100);
            $result['mixins'][] = [
                'name' => $m['ingredient'],
                'weight' => round($mWeight),
                'pct' => $m['pct'],
                'category' => $m['category'] ?? 'non-integrated'
            ];
        }
    }
    
    $toppings = $recipeData['toppings'] ?? [];
    foreach ($toppings as $t) {
        if (!empty($t['ingredient']) && $t['pct'] > 0) {
            $tWeight = $totalDoughWeight * ($t['pct'] / 100);
            $result['toppings'][] = [
                'name' => $t['ingredient'],
                'weight' => round($tWeight),
                'pct' => $t['pct']
            ];
        }
    }
    
    return $result;
}

function getDutchDayName($date) {
    $dagen = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
    return $dagen[$date->format('w')];
}

function getDutchMonthName($date) {
    $maanden = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    return $maanden[$date->format('n') - 1];
}

function formatDutchDate($date) {
    return getDutchDayName($date) . ' ' . $date->format('j') . ' ' . getDutchMonthName($date);
}

$totalProducts = 0;
$totalWeight = 0;
foreach ($recipes as $r) {
    $totalProducts += $r['total_qty'];
    $totalWeight += $r['total_weight'];
}
$totalProducts += $noRecipe['total_qty'];
$totalWeight += $noRecipe['total_weight'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dagproductie <?= $bereidingDate->format('d-m-Y') ?> | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
            color: #333;
        }
        .header {
            background: linear-gradient(135deg, #c8913a, #a0722e);
            color: white;
            padding: 1.25rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .header h1 { 
            font-size: 1.5rem; 
            display: flex; 
            align-items: center; 
            gap: 0.5rem;
        }
        .header-links { display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .header a:hover { background: rgba(255,255,255,0.3); }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 2rem;
        }
        .date-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .date-nav a {
            padding: 0.5rem 1rem;
            background: white;
            border-radius: 8px;
            text-decoration: none;
            color: #8b5a2b;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .date-nav a:hover { background: #fff5f0; }
        .date-nav .current {
            font-size: 1.3rem;
            font-weight: 700;
            color: #5c3d1e;
        }
        .summary-bar {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .summary-stat {
            background: white;
            padding: 1.25rem 1.75rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .summary-stat .label {
            font-size: 0.8rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.3rem;
        }
        .summary-stat .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #c8913a;
        }
        .recipe-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        .recipe-header {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .recipe-header h2 {
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .recipe-header .stats {
            display: flex;
            gap: 1.5rem;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .recipe-body {
            padding: 1.5rem;
        }
        .products-used {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #f0e6d8;
        }
        .product-tag {
            background: #f5f0e8;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            color: #5c3d1e;
        }
        .product-tag strong {
            color: #c8913a;
            margin-right: 0.3rem;
        }
        .ingredients-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .ingredient-section {
            background: #faf8f4;
            border-radius: 12px;
            padding: 1.25rem;
        }
        .ingredient-section h3 {
            font-size: 0.85rem;
            color: #8b5a2b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e8e0d5;
        }
        .ingredient-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid #f0e6d8;
        }
        .ingredient-row:last-child { border-bottom: none; }
        .ingredient-name { color: #5c3d1e; }
        .ingredient-weight {
            font-weight: 700;
            color: #c8913a;
            font-size: 1.1rem;
        }
        .ingredient-pct {
            font-size: 0.8rem;
            color: #999;
            margin-left: 0.5rem;
        }
        .total-row {
            background: #fff;
            border-radius: 8px;
            padding: 0.75rem;
            margin-top: 0.75rem;
            display: flex;
            justify-content: space-between;
            font-weight: 700;
        }
        .total-row .label { color: #5c3d1e; }
        .total-row .value { color: #c8913a; font-size: 1.2rem; }
        .no-recipe {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .no-recipe h3 {
            color: #856404;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .no-recipe-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .print-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #c8913a, #a0722e);
            color: white;
        }
        .btn-primary:hover { background: linear-gradient(135deg, #a0722e, #8b5a2b); }
        .btn-secondary {
            background: white;
            color: #8b5a2b;
            border: 2px solid #e0d5c7;
        }
        .btn-secondary:hover { border-color: #8b5a2b; background: #faf6f1; }
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 1rem;
        }
        .empty-state p {
            color: #888;
            font-size: 1.1rem;
        }
        @media print {
            body { background: white; }
            .header, .date-nav, .print-section { display: none !important; }
            .container { max-width: 100%; padding: 0; }
            .recipe-card { break-inside: avoid; box-shadow: none; border: 1px solid #ddd; }
            .recipe-header { background: #8b5a2b !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .ingredient-section { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media (max-width: 768px) {
            .header { padding: 1rem; }
            .header h1 { font-size: 1.2rem; }
            .container { padding: 1rem; }
            .summary-bar { gap: 1rem; }
            .summary-stat { padding: 1rem; flex: 1; min-width: 140px; }
            .recipe-body { padding: 1rem; }
            .ingredients-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="bi bi-calculator"></i> Dagproductie</h1>
        <div class="header-links">
            <a href="bereiden.php?date=<?= $date ?>&mode=day"><i class="bi bi-fire"></i> Bereiden</a>
            <a href="bakker-dashboard.php"><i class="bi bi-grid"></i> Overzicht</a>
            <a href="../index.php"><i class="bi bi-house"></i> Admin</a>
        </div>
    </div>

    <div class="container">
        <div class="date-nav">
            <?php 
            $prevDate = clone $bereidingDate;
            $prevDate->modify('-1 day');
            $nextDate = clone $bereidingDate;
            $nextDate->modify('+1 day');
            ?>
            <a href="?date=<?= $prevDate->format('Y-m-d') ?>"><i class="bi bi-chevron-left"></i> Vorige</a>
            <span class="current"><?= formatDutchDate($bereidingDate) ?></span>
            <a href="?date=<?= $nextDate->format('Y-m-d') ?>">Volgende <i class="bi bi-chevron-right"></i></a>
            <?php if ($date !== date('Y-m-d')): ?>
                <a href="?date=<?= date('Y-m-d') ?>">Vandaag</a>
            <?php endif; ?>
        </div>

        <?php if (empty($recipes) && empty($noRecipe['products'])): ?>
            <div class="empty-state">
                <i class="bi bi-emoji-smile"></i>
                <p>Geen bestellingen om te bereiden op deze dag</p>
            </div>
        <?php else: ?>

            <div class="print-section">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print overzicht
                </button>
            </div>

            <div class="summary-bar">
                <div class="summary-stat">
                    <div class="label">Totaal producten</div>
                    <div class="value"><?= $totalProducts ?> stuks</div>
                </div>
                <div class="summary-stat">
                    <div class="label">Totaal deeggewicht</div>
                    <div class="value"><?= number_format($totalWeight/1000, 1, ',', '.') ?> kg</div>
                </div>
                <div class="summary-stat">
                    <div class="label">Recepten</div>
                    <div class="value"><?= count($recipes) ?></div>
                </div>
            </div>

            <?php if (!empty($noRecipe['products'])): ?>
                <div class="no-recipe">
                    <h3><i class="bi bi-exclamation-triangle"></i> Producten zonder recept</h3>
                    <div class="no-recipe-list">
                        <?php foreach ($noRecipe['products'] as $name => $data): ?>
                            <span class="product-tag"><strong><?= $data['qty'] ?>x</strong> <?= htmlspecialchars($name) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($recipes as $recipeId => $recipe): 
                $calc = calculateIngredients($recipe['data'], $recipe['total_qty'], $recipe['total_weight']);
            ?>
                <div class="recipe-card">
                    <div class="recipe-header">
                        <h2><i class="bi bi-journal-bookmark"></i> <?= htmlspecialchars($recipe['name']) ?></h2>
                        <div class="stats">
                            <span><i class="bi bi-box"></i> <?= $recipe['total_qty'] ?> stuks</span>
                            <span><i class="bi bi-speedometer"></i> <?= number_format($recipe['total_weight']/1000, 1, ',', '.') ?> kg</span>
                            <span><i class="bi bi-droplet"></i> <?= $calc['hydration'] ?>%</span>
                        </div>
                    </div>
                    <div class="recipe-body">
                        <div class="products-used">
                            <?php foreach ($recipe['products'] as $name => $data): ?>
                                <span class="product-tag"><strong><?= $data['qty'] ?>x</strong> <?= htmlspecialchars($name) ?> (<?= $data['weight'] ?>g)</span>
                            <?php endforeach; ?>
                        </div>

                        <div class="ingredients-grid">
                            <div class="ingredient-section">
                                <h3><i class="bi bi-moisture"></i> Meel</h3>
                                <?php foreach ($calc['grains'] as $grain): ?>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name"><?= htmlspecialchars($grain['name']) ?></span>
                                        <span>
                                            <span class="ingredient-weight"><?= $grain['weight'] ?>g</span>
                                            <span class="ingredient-pct">(<?= $grain['pct'] ?>%)</span>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                                <div class="total-row">
                                    <span class="label">Totaal meel</span>
                                    <span class="value"><?= $calc['totalFlour'] ?>g</span>
                                </div>
                            </div>

                            <div class="ingredient-section">
                                <h3><i class="bi bi-droplet"></i> Water & Zout</h3>
                                <div class="ingredient-row">
                                    <span class="ingredient-name">Water</span>
                                    <span>
                                        <span class="ingredient-weight"><?= $calc['totalWater'] ?>g</span>
                                        <span class="ingredient-pct">(<?= $calc['hydration'] ?>%)</span>
                                    </span>
                                </div>
                                <div class="ingredient-row">
                                    <span class="ingredient-name">Zout</span>
                                    <span>
                                        <span class="ingredient-weight"><?= $calc['saltWeight'] ?>g</span>
                                        <span class="ingredient-pct">(<?= number_format($calc['saltPct'], 1, ',', '.') ?>%)</span>
                                    </span>
                                </div>
                                <?php foreach ($calc['leveners'] as $lev): ?>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name"><?= htmlspecialchars($lev['name']) ?></span>
                                        <span>
                                            <span class="ingredient-weight"><?= $lev['weight'] ?>g</span>
                                            <span class="ingredient-pct">(<?= $lev['pct'] ?>%)</span>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($calc['sourdough']): ?>
                                <div class="ingredient-section">
                                    <h3><i class="bi bi-fire"></i> Zuurdesem</h3>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name">Meel (in zuurdesem)</span>
                                        <span class="ingredient-weight"><?= $calc['sourdough']['flour'] ?>g</span>
                                    </div>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name">Water (in zuurdesem)</span>
                                        <span class="ingredient-weight"><?= $calc['sourdough']['water'] ?>g</span>
                                    </div>
                                    <div class="total-row">
                                        <span class="label">Zuurdesem totaal (<?= $calc['sourdough']['hydration'] ?>%)</span>
                                        <span class="value"><?= $calc['sourdough']['weight'] ?>g</span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($calc['preFerment']): ?>
                                <div class="ingredient-section">
                                    <h3><i class="bi bi-layers"></i> Voordeeg</h3>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name">Meel (in voordeeg)</span>
                                        <span class="ingredient-weight"><?= $calc['preFerment']['flour'] ?>g</span>
                                    </div>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name">Water (in voordeeg)</span>
                                        <span class="ingredient-weight"><?= $calc['preFerment']['water'] ?>g</span>
                                    </div>
                                    <div class="total-row">
                                        <span class="label">Voordeeg totaal (<?= $calc['preFerment']['hydration'] ?>%)</span>
                                        <span class="value"><?= $calc['preFerment']['weight'] ?>g</span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($calc['mixins'])): ?>
                                <div class="ingredient-section">
                                    <h3><i class="bi bi-plus-circle"></i> Mix-ins</h3>
                                    <?php foreach ($calc['mixins'] as $mixin): ?>
                                        <div class="ingredient-row">
                                            <span class="ingredient-name"><?= htmlspecialchars($mixin['name']) ?></span>
                                            <span>
                                                <span class="ingredient-weight"><?= $mixin['weight'] ?>g</span>
                                                <span class="ingredient-pct">(<?= $mixin['pct'] ?>%)</span>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($calc['toppings'])): ?>
                                <div class="ingredient-section">
                                    <h3><i class="bi bi-stars"></i> Toppings</h3>
                                    <?php foreach ($calc['toppings'] as $topping): ?>
                                        <div class="ingredient-row">
                                            <span class="ingredient-name"><?= htmlspecialchars($topping['name']) ?></span>
                                            <span>
                                                <span class="ingredient-weight"><?= $topping['weight'] ?>g</span>
                                                <span class="ingredient-pct">(<?= $topping['pct'] ?>%)</span>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>
</body>
</html>
