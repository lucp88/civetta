<?php
session_start();
require_once '../admin/config.php';

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

header('Content-Type: application/json');

$date = $_GET['date'] ?? date('Y-m-d');
$bereidingDate = new DateTime($date);
$deliveryDate = clone $bereidingDate;
$deliveryDate->modify('+1 day');

$stmt = $pdo->prepare("
    SELECT
        boi.product_name,
        boi.quantity,
        boi.unit_price,
        pv.recipe_id,
        COALESCE(pv.gewicht, 300) as variant_weight,
        br.name as recipe_name,
        br.recipe_data
    FROM business_orders bo
    JOIN business_order_items boi ON bo.id = boi.order_id
    LEFT JOIN products p ON LOWER(TRIM(boi.product_name)) = LOWER(TRIM(p.naam))
    LEFT JOIN product_variants pv ON pv.product_id = p.id
        AND ROUND(pv.prijs, 2) = ROUND(boi.unit_price, 2)
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
    $weight = intval($item['variant_weight']);
    // Key by name + weight so different size variants appear separately
    $productKey = $item['product_name'] . '_' . $weight . 'g';

    if ($item['recipe_id'] && $item['recipe_data']) {
        $recipeId = $item['recipe_id'];
        if (!isset($recipes[$recipeId])) {
            $recipes[$recipeId] = [
                'id' => $recipeId,
                'name' => $item['recipe_name'],
                'data' => json_decode($item['recipe_data'], true),
                'products' => [],
                'total_qty' => 0,
                'total_weight' => 0
            ];
        }
        if (!isset($recipes[$recipeId]['products'][$productKey])) {
            $recipes[$recipeId]['products'][$productKey] = ['name' => $item['product_name'], 'qty' => 0, 'weight' => $weight];
        }
        $recipes[$recipeId]['products'][$productKey]['qty'] += $qty;
        $recipes[$recipeId]['total_qty'] += $qty;
        $recipes[$recipeId]['total_weight'] += $qty * $weight;
    } else {
        if (!isset($noRecipe['products'][$productKey])) {
            $noRecipe['products'][$productKey] = ['name' => $item['product_name'], 'qty' => 0, 'weight' => $weight];
        }
        $noRecipe['products'][$productKey]['qty'] += $qty;
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
                'type' => $grain['type'],
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
            'type' => $recipeData['yeastType'],
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

$output = [
    'success' => true,
    'date' => $date,
    'delivery_date' => $deliveryDate->format('Y-m-d'),
    'recipes' => [],
    'no_recipe' => null,
    'totals' => [
        'products' => 0,
        'weight' => 0,
        'recipe_count' => count($recipes)
    ]
];

foreach ($recipes as $recipeId => $recipe) {
    $calc = calculateIngredients($recipe['data'], $recipe['total_qty'], $recipe['total_weight']);
    $output['recipes'][] = [
        'id' => $recipe['id'],
        'name' => $recipe['name'],
        'products' => $recipe['products'],
        'total_qty' => $recipe['total_qty'],
        'total_weight' => $recipe['total_weight'],
        'ingredients' => $calc
    ];
    $output['totals']['products'] += $recipe['total_qty'];
    $output['totals']['weight'] += $recipe['total_weight'];
}

if (!empty($noRecipe['products'])) {
    $output['no_recipe'] = $noRecipe;
    $output['totals']['products'] += $noRecipe['total_qty'];
    $output['totals']['weight'] += $noRecipe['total_weight'];
}

echo json_encode($output);
