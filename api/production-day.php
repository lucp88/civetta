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

// Load voorbereidingDagen for fallback method days
$stmtVd = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
$stmtVd->execute();
$voorbereidingDagen = (int)($stmtVd->fetchColumn() ?: 3);

// Fetch orders in a window: from today up to 7 days ahead (covers all possible prep windows)
$maxPrepDays = 7;
$windowEnd = clone $bereidingDate;
$windowEnd->modify("+{$maxPrepDays} days");

$stmt = $pdo->prepare("
    SELECT
        bo.delivery_date,
        boi.product_name,
        boi.quantity,
        boi.unit_price,
        pv.recipe_id,
        COALESCE(pv.gewicht, 300) as variant_weight,
        br.name as recipe_name,
        br.recipe_data,
        dt.recipe_data as dough_type_recipe_data
    FROM business_orders bo
    JOIN business_order_items boi ON bo.id = boi.order_id
    LEFT JOIN product_variants pv ON boi.variant_id = pv.id
    LEFT JOIN products p ON COALESCE(boi.product_id, pv.product_id) = p.id
    LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
    LEFT JOIN dough_types dt ON br.dough_type_id = dt.id
    WHERE bo.delivery_date BETWEEN ? AND ?
    AND bo.is_cancelled = 0
    ORDER BY bo.delivery_date ASC
");
$stmt->execute([$bereidingDate->format('Y-m-d'), $windowEnd->format('Y-m-d')]);
$allItems = $stmt->fetchAll();

// Filter items: only include if requested date is within the prep window
// Group by delivery date
$deliveryGroups = [];

foreach ($allItems as $item) {
    $deliveryDt = new DateTime($item['delivery_date']);

    // Determine method days count
    if ($item['recipe_id'] && $item['recipe_data']) {
        $methodDaysCount = $voorbereidingDagen;
        $recipeData = json_decode($item['recipe_data'], true);
        if (!empty($recipeData['methodDays'])) {
            $methodDaysCount = count($recipeData['methodDays']);
        } elseif (!empty($item['dough_type_recipe_data'])) {
            $dtData = json_decode($item['dough_type_recipe_data'], true);
            if (!empty($dtData['methodDays'])) {
                $methodDaysCount = count($dtData['methodDays']);
            }
        }
    } else {
        $methodDaysCount = 1;
    }

    $prepStart = clone $deliveryDt;
    $prepStart->modify('-' . ($methodDaysCount - 1) . ' days');

    if ($bereidingDate >= $prepStart && $bereidingDate <= $deliveryDt) {
        $dKey = $item['delivery_date'];
        if (!isset($deliveryGroups[$dKey])) {
            $deliveryGroups[$dKey] = [
                'recipes' => [],
                'noRecipe' => ['products' => [], 'total_qty' => 0, 'total_weight' => 0]
            ];
        }

        $qty = intval($item['quantity']);
        $weight = intval($item['variant_weight']);
        $productKey = $item['product_name'] . '_' . $weight . 'g';

        if ($item['recipe_id'] && $item['recipe_data']) {
            $recipeId = $item['recipe_id'];
            if (!isset($deliveryGroups[$dKey]['recipes'][$recipeId])) {
                $deliveryGroups[$dKey]['recipes'][$recipeId] = [
                    'id' => $recipeId,
                    'name' => $item['recipe_name'],
                    'data' => json_decode($item['recipe_data'], true),
                    'products' => [],
                    'total_qty' => 0,
                    'total_weight' => 0
                ];
            }
            if (!isset($deliveryGroups[$dKey]['recipes'][$recipeId]['products'][$productKey])) {
                $deliveryGroups[$dKey]['recipes'][$recipeId]['products'][$productKey] = ['name' => $item['product_name'], 'qty' => 0, 'weight' => $weight];
            }
            $deliveryGroups[$dKey]['recipes'][$recipeId]['products'][$productKey]['qty'] += $qty;
            $deliveryGroups[$dKey]['recipes'][$recipeId]['total_qty'] += $qty;
            $deliveryGroups[$dKey]['recipes'][$recipeId]['total_weight'] += $qty * $weight;
        } else {
            if (!isset($deliveryGroups[$dKey]['noRecipe']['products'][$productKey])) {
                $deliveryGroups[$dKey]['noRecipe']['products'][$productKey] = ['name' => $item['product_name'], 'qty' => 0, 'weight' => $weight];
            }
            $deliveryGroups[$dKey]['noRecipe']['products'][$productKey]['qty'] += $qty;
            $deliveryGroups[$dKey]['noRecipe']['total_qty'] += $qty;
            $deliveryGroups[$dKey]['noRecipe']['total_weight'] += $qty * $weight;
        }
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

$totalRecipeCount = 0;
foreach ($deliveryGroups as $group) {
    $totalRecipeCount += count($group['recipes']);
}

$output = [
    'success' => true,
    'date' => $date,
    'delivery_groups' => [],
    'recipes' => [],
    'no_recipe' => null,
    'totals' => [
        'products' => 0,
        'weight' => 0,
        'recipe_count' => $totalRecipeCount
    ]
];

foreach ($deliveryGroups as $deliveryDateStr => $group) {
    $groupOutput = [
        'delivery_date' => $deliveryDateStr,
        'recipes' => [],
        'no_recipe' => null
    ];

    foreach ($group['recipes'] as $recipeId => $recipe) {
        $calc = calculateIngredients($recipe['data'], $recipe['total_qty'], $recipe['total_weight']);
        $recipeOutput = [
            'id' => $recipe['id'],
            'name' => $recipe['name'],
            'products' => $recipe['products'],
            'total_qty' => $recipe['total_qty'],
            'total_weight' => $recipe['total_weight'],
            'ingredients' => $calc
        ];
        $groupOutput['recipes'][] = $recipeOutput;
        // Also add to flat list for backward compatibility
        $output['recipes'][] = $recipeOutput;
        $output['totals']['products'] += $recipe['total_qty'];
        $output['totals']['weight'] += $recipe['total_weight'];
    }

    if (!empty($group['noRecipe']['products'])) {
        $groupOutput['no_recipe'] = $group['noRecipe'];
        if ($output['no_recipe'] === null) {
            $output['no_recipe'] = ['products' => [], 'total_qty' => 0, 'total_weight' => 0];
        }
        foreach ($group['noRecipe']['products'] as $key => $prod) {
            $output['no_recipe']['products'][$key] = $prod;
        }
        $output['no_recipe']['total_qty'] += $group['noRecipe']['total_qty'];
        $output['no_recipe']['total_weight'] += $group['noRecipe']['total_weight'];
        $output['totals']['products'] += $group['noRecipe']['total_qty'];
        $output['totals']['weight'] += $group['noRecipe']['total_weight'];
    }

    $output['delivery_groups'][] = $groupOutput;
}

echo json_encode($output);
