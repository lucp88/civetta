<?php
require_once '../config.php';
requireLogin();

$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$bereidingDate = new DateTime($date);
$filterDoughType = isset($_GET['dough_type']) ? $_GET['dough_type'] : null;

// Load voorbereidingDagen for fallback method days
$stmtVd = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
$stmtVd->execute();
$voorbereidingDagen = (int)($stmtVd->fetchColumn() ?: 3);

// Load ingredient names from DB (for flour grain names)
$ingredientNames = [];
$ingStmt = $pdo->query("SELECT id, name FROM ingredients");
if ($ingStmt) {
    foreach ($ingStmt->fetchAll() as $ing) {
        $ingredientNames[$ing['id']] = $ing['name'];
        $ingredientNames[strval($ing['id'])] = $ing['name'];
    }
}

// Fetch orders in a window: from today up to 7 days ahead (covers all possible prep windows)
$maxPrepDays = 7;
$windowEnd = clone $bereidingDate;
$windowEnd->modify("+{$maxPrepDays} days");

$stmt = $pdo->prepare("
    SELECT
        bo.id as order_id,
        bo.delivery_date,
        ba.bedrijfsnaam,
        boi.product_name,
        boi.quantity,
        boi.unit_price,
        pv.recipe_id,
        pv.gewicht as variant_weight,
        br.name as recipe_name,
        br.recipe_data,
        br.dough_type_id,
        COALESCE(dt.name, 'Geen deegsoort') as dough_type_name,
        dt.recipe_data as dough_type_recipe_data
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
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
// Group by dough type (combining recipes that share the same dough)
$doughGroups = []; // doughTypeName => { data, products, recipes, total_qty, total_weight }
$noRecipeGroup = ['products' => [], 'total_qty' => 0, 'total_weight' => 0];

foreach ($allItems as $item) {
    $deliveryDt = new DateTime($item['delivery_date']);
    $doughTypeName = $item['dough_type_name'] ?? 'Geen deegsoort';

    // Apply dough type filter if specified
    if ($filterDoughType && $item['recipe_id'] && $doughTypeName !== $filterDoughType) {
        continue;
    }

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
        // When filtering by dough type, skip items without recipe
        if ($filterDoughType) continue;
    }

    // Prep starts (methodDaysCount - 1) days before delivery
    $prepStart = clone $deliveryDt;
    $prepStart->modify('-' . ($methodDaysCount - 1) . ' days');

    // Include if requested date is within prep window
    if ($bereidingDate >= $prepStart && $bereidingDate <= $deliveryDt) {
        $qty = intval($item['quantity']);
        $variantWeight = intval($item['variant_weight'] ?? 0);

        $doughWeight = 0;
        if (!empty($item['recipe_data'])) {
            $recipeData = json_decode($item['recipe_data'], true);
            $doughWeight = intval($recipeData['doughWeight'] ?? 0);
        }
        $weight = $doughWeight > 0 ? $doughWeight : ($variantWeight > 0 ? $variantWeight : 300);

        if ($item['recipe_id'] && $item['recipe_data']) {
            if (!isset($doughGroups[$doughTypeName])) {
                $dtRecipeData = !empty($item['dough_type_recipe_data']) ? json_decode($item['dough_type_recipe_data'], true) : null;
                $doughGroups[$doughTypeName] = [
                    'dough_type_data' => $dtRecipeData,
                    'recipes' => [],
                    'products' => [],
                    'orders' => [],
                    'method_days_count' => $methodDaysCount,
                    'delivery_date' => $item['delivery_date'],
                    'total_qty' => 0,
                    'total_weight' => 0
                ];
            }
            $doughGroups[$doughTypeName]['method_days_count'] = max($doughGroups[$doughTypeName]['method_days_count'], $methodDaysCount);

            // Track per-recipe info
            $recipeName = $item['recipe_name'];
            if (!isset($doughGroups[$doughTypeName]['recipes'][$recipeName])) {
                $doughGroups[$doughTypeName]['recipes'][$recipeName] = [
                    'data' => json_decode($item['recipe_data'], true),
                    'products' => [],
                    'total_qty' => 0,
                    'total_weight' => 0
                ];
            }
            if (!isset($doughGroups[$doughTypeName]['recipes'][$recipeName]['products'][$item['product_name']])) {
                $doughGroups[$doughTypeName]['recipes'][$recipeName]['products'][$item['product_name']] = ['qty' => 0, 'weight' => $weight];
            }
            $doughGroups[$doughTypeName]['recipes'][$recipeName]['products'][$item['product_name']]['qty'] += $qty;
            $doughGroups[$doughTypeName]['recipes'][$recipeName]['total_qty'] += $qty;
            $doughGroups[$doughTypeName]['recipes'][$recipeName]['total_weight'] += $qty * $weight;

            // Track per-order info (for sorting when out of oven)
            $orderId = $item['order_id'];
            if (!isset($doughGroups[$doughTypeName]['orders'][$orderId])) {
                $doughGroups[$doughTypeName]['orders'][$orderId] = [
                    'bedrijfsnaam' => $item['bedrijfsnaam'],
                    'items' => []
                ];
            }
            $productKey = $item['product_name'];
            if (!isset($doughGroups[$doughTypeName]['orders'][$orderId]['items'][$productKey])) {
                $doughGroups[$doughTypeName]['orders'][$orderId]['items'][$productKey] = 0;
            }
            $doughGroups[$doughTypeName]['orders'][$orderId]['items'][$productKey] += $qty;

            // Track at dough type level
            if (!isset($doughGroups[$doughTypeName]['products'][$item['product_name']])) {
                $doughGroups[$doughTypeName]['products'][$item['product_name']] = ['qty' => 0, 'weight' => $weight];
            }
            $doughGroups[$doughTypeName]['products'][$item['product_name']]['qty'] += $qty;
            $doughGroups[$doughTypeName]['total_qty'] += $qty;
            $doughGroups[$doughTypeName]['total_weight'] += $qty * $weight;
        } else {
            if (!isset($noRecipeGroup['products'][$item['product_name']])) {
                $noRecipeGroup['products'][$item['product_name']] = ['qty' => 0, 'weight' => $weight];
            }
            $noRecipeGroup['products'][$item['product_name']]['qty'] += $qty;
            $noRecipeGroup['total_qty'] += $qty;
            $noRecipeGroup['total_weight'] += $qty * $weight;
        }
    }
}

function calculateIngredients($recipeData, $totalQty, $totalWeight, $ingredientNames = []) {
    $numberOfBalls = $totalQty;
    $weightPerBall = $totalQty > 0 ? $totalWeight / $totalQty : 300;

    $hydration = $recipeData['hydration'] ?? 62;
    $saltPct = $recipeData['saltPct'] ?? 2.6;

    $totalDoughWeight = $numberOfBalls * $weightPerBall;
    $totalFlour = $totalDoughWeight / (1 + $hydration/100 + $saltPct/100);
    $totalWater = $totalFlour * ($hydration/100);
    $saltWeight = $totalFlour * ($saltPct/100);

    // Fallback grain type names (old-style string IDs)
    $grainTypesFallback = [
        'wheat_white' => 'Tarwe wit', 'wheat_whole' => 'Tarwe volkoren',
        'spelt_white' => 'Spelt wit', 'spelt_whole' => 'Spelt volkoren',
        'durum' => 'Durum', 'emmer' => 'Emmer',
        'rye_white' => 'Rogge wit', 'rye_whole' => 'Rogge volkoren',
        'einkorn' => 'Einkorn', 'buckwheat' => 'Boekweit',
        'rice' => 'Rijst', 'barley' => 'Gerst', 'teff' => 'Teff'
    ];

    $mainFlour = $totalFlour;

    $sourdough = null;
    if (!empty($recipeData['useSourdough']) && !empty($recipeData['sourdoughPct'])) {
        $sdPct = $recipeData['sourdoughPct'];
        $sdHydration = $recipeData['sourdoughHydration'] ?? 100;
        $sdWeight = $totalFlour * ($sdPct / 100);
        $sdFlour = $sdWeight / (1 + $sdHydration/100);
        $sdWater = $sdWeight - $sdFlour;
        $mainFlour -= $sdFlour;
        $sourdough = [
            'weight' => round($sdWeight),
            'flour' => round($sdFlour),
            'water' => round($sdWater),
            'hydration' => $sdHydration,
            'pct' => $sdPct
        ];
    }

    $preFerment = null;
    if (!empty($recipeData['usePreFerment']) && !empty($recipeData['preFermentPct'])) {
        $pfPct = $recipeData['preFermentPct'];
        $pfHydration = $recipeData['preFermentHydration'] ?? 100;
        $pfWeight = $totalFlour * ($pfPct / 100);
        $pfFlour = $pfWeight / (1 + $pfHydration/100);
        $pfWater = $pfWeight - $pfFlour;
        $mainFlour -= $pfFlour;
        $preFerment = [
            'weight' => round($pfWeight),
            'flour' => round($pfFlour),
            'water' => round($pfWater),
            'hydration' => $pfHydration,
            'pct' => $pfPct
        ];
    }

    // Main dough water = total water minus water in sourdough/pre-ferment
    $mainWater = $totalWater - ($sourdough ? $sourdough['water'] : 0) - ($preFerment ? $preFerment['water'] : 0);

    $grains = [];
    $mainGrains = $recipeData['mainDoughGrains'] ?? [['type' => 'wheat_white', 'pct' => 100]];
    foreach ($mainGrains as $grain) {
        if ($grain['pct'] > 0) {
            $grainWeight = $mainFlour * ($grain['pct'] / 100);
            // Resolve grain name: try DB ingredients first, then fallback map, then raw type
            $grainType = $grain['type'] ?? '';
            $grainName = $ingredientNames[$grainType] ?? $grainTypesFallback[$grainType] ?? $grainType;
            $grains[] = [
                'name' => $grainName,
                'weight' => round($grainWeight),
                'pct' => $grain['pct']
            ];
        }
    }
    
    $leveners = [];
    if (!empty($recipeData['useYeast']) && !empty($recipeData['yeastPct'])) {
        $yeastTypesFallback = [
            'fresh_yeast' => 'Verse gist',
            'instant_yeast' => 'Instant gist',
            'sourdough_culture' => 'Desemcultuur'
        ];
        $yeastType = $recipeData['yeastType'] ?? '';
        $yeastWeight = $totalFlour * ($recipeData['yeastPct'] / 100);
        $leveners[] = [
            'name' => $ingredientNames[$yeastType] ?? $yeastTypesFallback[$yeastType] ?? 'Gist',
            'weight' => round($yeastWeight),
            'pct' => $recipeData['yeastPct']
        ];
    }

    $mixins = [];
    $mixinData = $recipeData['mixins'] ?? [];
    $mixinMode = $recipeData['mixinMode'] ?? 'flour';
    $baseForMixin = $mixinMode === 'dough' ? $totalDoughWeight : $totalFlour;
    foreach ($mixinData as $m) {
        if (!empty($m['ingredient']) && $m['pct'] > 0) {
            $mWeight = $baseForMixin * ($m['pct'] / 100);
            $mixins[] = [
                'name' => $m['ingredient'],
                'weight' => round($mWeight),
                'pct' => $m['pct'],
                'category' => $m['category'] ?? 'non-integrated'
            ];
        }
    }

    $toppingsResult = [];
    $toppings = $recipeData['toppings'] ?? [];
    foreach ($toppings as $t) {
        if (!empty($t['ingredient']) && $t['pct'] > 0) {
            $tWeight = $totalDoughWeight * ($t['pct'] / 100);
            $toppingsResult[] = [
                'name' => $t['ingredient'],
                'weight' => round($tWeight),
                'pct' => $t['pct']
            ];
        }
    }

    return [
        'totalFlour' => round($totalFlour),
        'mainFlour' => round($mainFlour),
        'totalWater' => round($totalWater),
        'mainWater' => round($mainWater),
        'saltWeight' => round($saltWeight),
        'totalDoughWeight' => round($totalDoughWeight),
        'hydration' => $hydration,
        'saltPct' => $saltPct,
        'grains' => $grains,
        'leveners' => $leveners,
        'mixins' => $mixins,
        'toppings' => $toppingsResult,
        'preFerment' => $preFerment,
        'sourdough' => $sourdough
    ];
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
$totalDoughTypeCount = count($doughGroups);
foreach ($doughGroups as $dg) {
    $totalProducts += $dg['total_qty'];
    $totalWeight += $dg['total_weight'];
}
$totalProducts += $noRecipeGroup['total_qty'];
$totalWeight += $noRecipeGroup['total_weight'];
?>
<?php
$adminPageTitle = 'Dagproductie';
$adminBasePath = '../';
$currentPage = 'dagproductie';
ob_start();
?>
    <link rel="stylesheet" href="/css/bootstrap-icons.min.css">
    <style>
        .container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 2rem;
        }
        .page-layout {
            display: grid;
            grid-template-columns: 1fr 260px;
            gap: 2rem;
            align-items: start;
        }
        .page-main { min-width: 0; }
        .page-sidebar { position: sticky; top: 1.5rem; }
        @media (max-width: 900px) {
            .page-layout { grid-template-columns: 1fr; }
            .page-sidebar { position: static; }
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
            color: #3d6b3d;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .date-nav a:hover { background: #fff5f0; }
        .date-nav .current {
            font-size: 1.3rem;
            font-weight: 700;
            color: #2d4a2d;
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
            background: linear-gradient(135deg, #3d6b3d, #2d4a2d);
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
            color: #2d4a2d;
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
            color: #3d6b3d;
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
        .ingredient-name { color: #2d4a2d; }
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
        .total-row .label { color: #2d4a2d; }
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
        .btn-primary:hover { background: linear-gradient(135deg, #a0722e, #3d6b3d); }
        .btn-secondary {
            background: white;
            color: #3d6b3d;
            border: 2px solid #e0d5c7;
        }
        .btn-secondary:hover { border-color: #3d6b3d; background: #faf6f1; }
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
        .delivery-date-header {
            margin: 2rem 0 1rem;
            padding: 0.75rem 1.25rem;
            background: linear-gradient(135deg, #e8f4fd, #d4ecf9);
            border-radius: 12px;
            border-left: 4px solid #2196f3;
        }
        .delivery-date-label {
            font-weight: 700;
            font-size: 1.1rem;
            color: #1565c0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .delivery-date-days {
            font-weight: 400;
            font-size: 0.85rem;
            color: #5c8db8;
            margin-left: 0.5rem;
        }
        .watertemp-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .watertemp-card h3 {
            font-size: 0.8rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .watertemp-inputs { display: flex; flex-direction: column; gap: 0.6rem; }
        .watertemp-field { display: flex; flex-direction: column; gap: 0.25rem; }
        .watertemp-field label { font-size: 0.72rem; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
        .watertemp-field.optional label { color: #b0b8c5; }
        .watertemp-input-row { display: flex; align-items: stretch; }
        .watertemp-input { flex: 1; min-width: 0; padding: 0.5rem 0.5rem; border: 1px solid #d1d5db; border-right: none; border-radius: 6px 0 0 6px; font-size: 1.1rem; font-weight: 600; color: #1f2937; }
        .watertemp-input::-webkit-inner-spin-button { opacity: 1; transform: scale(1.4); transform-origin: right center; }
        .watertemp-input:focus { outline: none; border-color: #c8913a; }
        .watertemp-input.optional-input { background: #f9fafb; color: #6b7280; }
        .watertemp-input.stale { border-color: #fbbf24; background: #fffbeb; }
        .watertemp-stale-note { font-size: 0.68rem; color: #b45309; margin-top: 0.2rem; display: flex; align-items: center; gap: 0.25rem; }
        .watertemp-unit-badge { padding: 0.5rem 0.5rem; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 0 6px 6px 0; font-size: 0.8rem; color: #6b7280; font-weight: 600; display: flex; align-items: center; white-space: nowrap; }
        .watertemp-divider { height: 1px; background: #e5e7eb; margin: 0.25rem 0; }
        .watertemp-result-box { padding: 0.75rem; border-radius: 10px; text-align: center; margin-top: 1rem; transition: background 0.25s; }
        .watertemp-result-value { font-size: 2.4rem; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
        .watertemp-result-label { font-size: 0.75rem; margin-top: 0.3rem; opacity: 0.75; }
        .watertemp-formula { font-size: 0.7rem; color: #9ca3af; margin-top: 0.6rem; text-align: center; line-height: 1.4; }
        .watertemp-cold  { background: #eff6ff; color: #1d4ed8; }
        .watertemp-cool  { background: #f0fdf4; color: #166534; }
        .watertemp-warm  { background: #fff7ed; color: #c2410c; }
        .watertemp-hot   { background: #fef2f2; color: #b91c1c; }
        /* Inline water temp badge in recipe cards */
        .wt-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.6rem; border-radius: 20px; font-size: 0.82rem; font-weight: 700; margin-left: 0.5rem; font-variant-numeric: tabular-nums; vertical-align: middle; }
        @media print {
            .watertemp-card, .page-sidebar { display: none !important; }
            .page-layout { grid-template-columns: 1fr !important; }
        }
        @media print {
            .topbar, .admin-topbar, .sidebar, .date-nav, .print-section { display: none !important; }
            .admin-main { margin-left: 0 !important; }
            .container { max-width: 100%; padding: 0; }
            .recipe-card { break-inside: avoid; box-shadow: none; border: 1px solid #ddd; }
            .recipe-header { background: #3d6b3d !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .ingredient-section { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .summary-bar { gap: 1rem; }
            .summary-stat { padding: 1rem; flex: 1; min-width: 140px; }
            .recipe-body { padding: 1rem; }
            .ingredients-grid { grid-template-columns: 1fr; }
        }
    </style>
<?php $adminExtraHead = ob_get_clean(); require_once '../components/sidebar.php'; ?>

        <header class="topbar">
            <div class="topbar-left">
                <span class="topbar-title"><i class="bi bi-calculator"></i> Dagproductie<?= $filterDoughType ? ' — ' . htmlspecialchars($filterDoughType) : '' ?></span>
            </div>
            <div class="topbar-right">
                <?php if ($filterDoughType): ?>
                    <a class="topbar-link" href="dagproductie.php?date=<?= $date ?>"><i class="bi bi-list"></i> <span>Alle deegsoorten</span></a>
                <?php endif; ?>
                <a class="topbar-link" href="planning.php?filter=bakken&date=<?= $date ?>&mode=day"><i class="bi bi-fire"></i> <span>Bereiden</span></a>
            </div>
        </header>

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

        <?php if (empty($doughGroups) && empty($noRecipeGroup['products'])): ?>
            <div class="empty-state">
                <i class="bi bi-emoji-smile"></i>
                <p>Geen bestellingen om te bereiden op deze dag</p>
            </div>
        <?php else: ?>
        <div class="page-layout">
        <div class="page-main">

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
                    <div class="label">Deegsoorten</div>
                    <div class="value"><?= $totalDoughTypeCount ?></div>
                </div>
            </div>

            <?php if (!empty($noRecipeGroup['products'])): ?>
                <div class="no-recipe">
                    <h3><i class="bi bi-exclamation-triangle"></i> Producten zonder recept</h3>
                    <div class="no-recipe-list">
                        <?php foreach ($noRecipeGroup['products'] as $name => $data): ?>
                            <span class="product-tag"><strong><?= $data['qty'] ?>x</strong> <?= htmlspecialchars($name) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($doughGroups as $doughTypeName => $doughGroup):
                // Use first recipe's data for the combined calculation
                $firstRecipe = reset($doughGroup['recipes']);
                $calcData = $firstRecipe['data'];
                $calc = calculateIngredients($calcData, $doughGroup['total_qty'], $doughGroup['total_weight'], $ingredientNames);
            ?>
                <div class="recipe-card">
                    <div class="recipe-header">
                        <h2><i class="bi bi-layers"></i> <?= htmlspecialchars($doughTypeName) ?></h2>
                        <div class="stats">
                            <span><i class="bi bi-box"></i> <?= $doughGroup['total_qty'] ?> stuks</span>
                            <span><i class="bi bi-speedometer"></i> <?= number_format($doughGroup['total_weight']/1000, 1, ',', '.') ?> kg deeg</span>
                            <span><i class="bi bi-droplet"></i> <?= $calc['hydration'] ?>%</span>
                        </div>
                    </div>
                    <div class="recipe-body">
                        <div class="ingredients-grid">
                            <div class="ingredient-section">
                                <h3><i class="bi bi-moisture"></i> Hoofddeeg — Meel</h3>
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
                                    <span class="label">Totaal meel (hoofddeeg)</span>
                                    <span class="value"><?= $calc['mainFlour'] ?>g</span>
                                </div>
                            </div>

                            <div class="ingredient-section">
                                <h3><i class="bi bi-droplet"></i> Hoofddeeg — Water & Zout</h3>
                                <div class="ingredient-row">
                                    <span class="ingredient-name">Water <span class="wt-badge watertemp-cool" data-wt-badge>28°C</span></span>
                                    <span>
                                        <span class="ingredient-weight"><?= $calc['mainWater'] ?>g</span>
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

                                <div class="ingredient-section">
                                    <h3><i class="bi bi-clipboard-check"></i> Totaal</h3>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name">Totaal meel (incl. zuurdesem/voordeeg)</span>
                                        <span class="ingredient-weight"><?= $calc['totalFlour'] ?>g</span>
                                    </div>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name">Totaal water (incl. zuurdesem/voordeeg)</span>
                                        <span class="ingredient-weight"><?= $calc['totalWater'] ?>g</span>
                                    </div>
                                    <div class="total-row">
                                        <span class="label">Totaal deeggewicht</span>
                                        <span class="value"><?= number_format($calc['totalDoughWeight']) ?>g</span>
                                    </div>
                                </div>
                            </div>

                            <?php
                            // Baking process timeline
                            $methodDaysCount = $doughGroup['method_days_count'];
                            $deliveryDt = new DateTime($doughGroup['delivery_date']);
                            // Get methodDays steps from dough type data or first recipe
                            $firstRecipeData = reset($doughGroup['recipes'])['data'] ?? [];
                            $dtData = $doughGroup['dough_type_data'];
                            $methodDays = $dtData['methodDays'] ?? $firstRecipeData['methodDays'] ?? null;
                            if ($methodDays && count($methodDays) > 0):
                                $prepStartDt = clone $deliveryDt;
                                $prepStartDt->modify('-' . (count($methodDays) - 1) . ' days');
                            ?>
                            <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:2px solid #f0e6d8">
                                <h3 style="font-size:0.95rem;color:#2d4a2d;margin-bottom:1rem"><i class="bi bi-calendar-week"></i> Bakproces <span style="font-weight:400;color:#888;font-size:0.85rem">(<?= count($methodDays) ?> dagen, levering <?= formatDutchDate($deliveryDt) ?>)</span></h3>
                                <?php
                                $today = new DateTime(date('Y-m-d'));
                                foreach ($methodDays as $di => $day):
                                    $dayDt = clone $prepStartDt;
                                    $dayDt->modify('+' . $di . ' days');
                                    $isToday = ($dayDt->format('Y-m-d') === $today->format('Y-m-d'));
                                    $daysDiff = (int)$today->diff($dayDt)->format('%r%a');
                                    $isPast = ($daysDiff < 0);
                                    $dayLabel = $day['label'] ?? ('Dag ' . ($di + 1));

                                    // Determine status badge
                                    $statusBadge = '';
                                    if ($isToday) {
                                        $statusBadge = '<span style="font-size:0.8rem;background:#ff6b35;color:white;padding:0.15rem 0.5rem;border-radius:4px;font-weight:600">Vandaag</span>';
                                    } elseif ($isPast) {
                                        $statusBadge = '<span style="font-size:0.8rem;color:#999"><i class="bi bi-check-circle-fill" style="color:#4caf50"></i></span>';
                                    } elseif ($daysDiff === 1) {
                                        $statusBadge = '<span style="font-size:0.8rem;background:#fff3cd;color:#856404;padding:0.15rem 0.5rem;border-radius:4px;font-weight:600">Morgen</span>';
                                    } else {
                                        $statusBadge = '<span style="font-size:0.8rem;background:#e3f2fd;color:#1565c0;padding:0.15rem 0.5rem;border-radius:4px;font-weight:600">Nog ' . $daysDiff . ' dagen</span>';
                                    }
                                ?>
                                    <div style="margin-bottom:0.75rem;padding:0.75rem;border-radius:8px;<?= $isToday ? 'background:#fff5f0;border:2px solid #ff6b35;' : ($isPast ? 'background:#f5f5f5;border:1px solid #e0e0e0;opacity:0.7;' : 'background:#faf8f4;border:1px solid #e8e0d5;') ?>">
                                        <div style="font-weight:700;color:<?= $isToday ? '#ff6b35' : ($isPast ? '#999' : '#2d4a2d') ?>;margin-bottom:0.3rem;display:flex;align-items:center;gap:0.5rem">
                                            <?php if ($isToday): ?><i class="bi bi-arrow-right-circle-fill" style="color:#ff6b35"></i><?php endif; ?>
                                            <?= htmlspecialchars($dayLabel) ?> — <?= getDutchDayName($dayDt) ?> <?= $dayDt->format('j') ?> <?= getDutchMonthName($dayDt) ?>
                                            <?= $statusBadge ?>
                                        </div>
                                        <?php if (!empty($day['steps'])): ?>
                                            <?php foreach ($day['steps'] as $si => $step): ?>
                                                <?php if (trim($step)): ?>
                                                    <div style="color:#666;font-size:0.9rem;padding-left:1.5rem;margin-top:0.2rem"><span style="color:#c8913a;font-weight:600">Stap <?= $si + 1 ?>:</span> <?= htmlspecialchars($step) ?></div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:2px solid #f0e6d8">
                                <h3 style="font-size:0.95rem;color:#2d4a2d;margin-bottom:1rem"><i class="bi bi-box-seam"></i> Broden uit dit deeg</h3>
                                <?php foreach ($doughGroup['recipes'] as $recipeName => $recipeInfo): ?>
                                    <div style="margin-bottom:0.75rem">
                                        <div style="font-weight:600;color:#3d6b3d;margin-bottom:0.3rem"><i class="bi bi-journal-bookmark" style="color:#c8913a"></i> <?= htmlspecialchars($recipeName) ?></div>
                                        <div class="products-used" style="margin-bottom:0">
                                            <?php foreach ($recipeInfo['products'] as $name => $data): ?>
                                                <span class="product-tag"><strong><?= $data['qty'] ?>x</strong> <?= htmlspecialchars($name) ?> (<?= $data['weight'] ?>g)</span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:2px solid #f0e6d8">
                                <h3 style="font-size:0.95rem;color:#2d4a2d;margin-bottom:1rem"><i class="bi bi-people"></i> Per bestelling</h3>
                                <?php foreach ($doughGroup['orders'] as $orderId => $orderInfo): ?>
                                    <div style="margin-bottom:0.5rem;padding:0.6rem 0.8rem;background:#faf8f4;border-radius:8px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem">
                                        <div>
                                            <span style="font-weight:600;color:#2d4a2d"><?= htmlspecialchars($orderInfo['bedrijfsnaam']) ?></span>
                                            <span style="color:#999;font-size:0.8rem;margin-left:0.5rem">#<?= $orderId ?></span>
                                        </div>
                                        <div class="products-used" style="margin-bottom:0;gap:0.3rem">
                                            <?php foreach ($orderInfo['items'] as $productName => $qty): ?>
                                                <span class="product-tag" style="font-size:0.8rem;padding:0.2rem 0.6rem"><strong><?= $qty ?>x</strong> <?= htmlspecialchars($productName) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

        </div><!-- /.page-main -->
        <div class="page-sidebar">
            <div class="watertemp-card">
                <h3><i class="bi bi-thermometer-half"></i> Watertemperatuur</h3>
                <div class="watertemp-inputs">
                    <div class="watertemp-field">
                        <label>DDT (gewenste deegtemp)</label>
                        <div class="watertemp-input-row">
                            <input type="number" id="wt-dough" class="watertemp-input" value="24" min="0" max="40" step="0.5" oninput="calcWaterTemp()">
                            <span class="watertemp-unit-badge">°C</span>
                        </div>
                    </div>
                    <div class="watertemp-field">
                        <label>Meeltemperatuur</label>
                        <div class="watertemp-input-row">
                            <input type="number" id="wt-flour" class="watertemp-input" value="20" min="-10" max="40" step="0.5" oninput="wtClearStale('wt-flour'); calcWaterTemp()">
                            <span class="watertemp-unit-badge">°C</span>
                        </div>
                        <div id="wt-flour-stale" class="watertemp-stale-note" style="display:none"><i class="bi bi-clock-history"></i> Geschat — vul bakkerijtemp in</div>
                    </div>
                    <div class="watertemp-field">
                        <label>Omgevingstemperatuur</label>
                        <div class="watertemp-input-row">
                            <input type="number" id="wt-ambient" class="watertemp-input" value="20" min="-10" max="40" step="0.5" oninput="wtClearStale('wt-ambient'); calcWaterTemp()">
                            <span class="watertemp-unit-badge">°C</span>
                        </div>
                        <div id="wt-ambient-stale" class="watertemp-stale-note" style="display:none"><i class="bi bi-clock-history"></i> Geschat — vul bakkerijtemp in</div>
                    </div>
                    <div class="watertemp-divider"></div>
                    <div class="watertemp-field optional">
                        <label>Voordeeg/levain temp</label>
                        <div class="watertemp-input-row">
                            <input type="number" id="wt-preferment" class="watertemp-input optional-input" placeholder="—" min="-10" max="40" step="0.5" oninput="calcWaterTemp()">
                            <span class="watertemp-unit-badge">°C</span>
                        </div>
                    </div>
                    <div class="watertemp-field optional">
                        <label>Wrijvingsfactor kneder</label>
                        <div class="watertemp-input-row">
                            <input type="number" id="wt-friction" class="watertemp-input optional-input" placeholder="0" value="0" min="0" max="30" step="1" oninput="calcWaterTemp()">
                            <span class="watertemp-unit-badge">°C</span>
                        </div>
                    </div>
                </div>
                <div id="wt-result" class="watertemp-result-box watertemp-cool">
                    <div id="wt-result-value" class="watertemp-result-value">28°C</div>
                    <div class="watertemp-result-label">Watertemperatuur</div>
                </div>
                <div id="wt-formula" class="watertemp-formula">(DDT × 3) − (meel + omgeving + wrijving)</div>
            </div>
        </div><!-- /.page-sidebar -->
        </div><!-- /.page-layout -->

        <?php endif; ?>
    </div>
    <script>
    var WT_KEY = 'civetta_watertemp';
    var BT_KEY = 'civetta_bakery_temp';
    var TODAY  = '<?= date('Y-m-d') ?>';

    function wtSave() {
        localStorage.setItem(WT_KEY, JSON.stringify({
            dough:      document.getElementById('wt-dough').value,
            flour:      document.getElementById('wt-flour').value,
            ambient:    document.getElementById('wt-ambient').value,
            preferment: document.getElementById('wt-preferment').value,
            friction:   document.getElementById('wt-friction').value
        }));
    }

    function wtSetStale(id, stale) {
        var input = document.getElementById(id);
        var note  = document.getElementById(id + '-stale');
        if (!input || !note) return;
        if (stale) {
            input.classList.add('stale');
            note.style.display = '';
        } else {
            input.classList.remove('stale');
            note.style.display = 'none';
        }
    }

    function wtClearStale(id) {
        wtSetStale(id, false);
    }

    function wtLoad() {
        try {
            // 1. Start with saved watertemp values
            var saved = JSON.parse(localStorage.getItem(WT_KEY)) || {};
            if (saved.dough      !== undefined) document.getElementById('wt-dough').value      = saved.dough;
            if (saved.preferment !== undefined) document.getElementById('wt-preferment').value = saved.preferment;
            if (saved.friction   !== undefined) document.getElementById('wt-friction').value   = saved.friction;

            // 2. Overlay bakery temp for flour + ambient
            var bt = JSON.parse(localStorage.getItem(BT_KEY));
            if (bt && bt.value !== undefined) {
                var stale = (bt.date !== TODAY);
                document.getElementById('wt-flour').value   = bt.value;
                document.getElementById('wt-ambient').value = bt.value;
                wtSetStale('wt-flour',   stale);
                wtSetStale('wt-ambient', stale);
            } else {
                // Fall back to saved watertemp values
                if (saved.flour   !== undefined) document.getElementById('wt-flour').value   = saved.flour;
                if (saved.ambient !== undefined) document.getElementById('wt-ambient').value = saved.ambient;
            }
        } catch(e) {}
    }

    function calcWaterTemp() {
        var ddt        = parseFloat(document.getElementById('wt-dough').value)    || 0;
        var flour      = parseFloat(document.getElementById('wt-flour').value)    || 0;
        var ambient    = parseFloat(document.getElementById('wt-ambient').value)  || 0;
        var friction   = parseFloat(document.getElementById('wt-friction').value) || 0;
        var prefVal    = document.getElementById('wt-preferment').value.trim();
        var hasPref    = prefVal !== '' && !isNaN(parseFloat(prefVal));
        var preferment = hasPref ? parseFloat(prefVal) : null;

        var water, formulaText;
        if (hasPref) {
            water       = ddt * 4 - (flour + ambient + preferment + friction);
            formulaText = '(DDT × 4) − (meel + omgeving + voordeeg + wrijving)';
        } else {
            water       = ddt * 3 - (flour + ambient + friction);
            formulaText = '(DDT × 3) − (meel + omgeving + wrijving)';
        }
        water = Math.round(water * 10) / 10;

        var colorClass = water <= 5 ? 'watertemp-cold' : water <= 20 ? 'watertemp-cool' : water <= 35 ? 'watertemp-warm' : 'watertemp-hot';
        var text = water + '°C';

        document.getElementById('wt-result-value').textContent = text;
        document.getElementById('wt-formula').textContent = formulaText;
        document.getElementById('wt-result').className = 'watertemp-result-box ' + colorClass;

        document.querySelectorAll('[data-wt-badge]').forEach(function(el) {
            el.textContent = text;
            el.className = 'wt-badge ' + colorClass;
        });

        wtSave();
    }

    wtLoad();
    calcWaterTemp();
    </script>
</div><!-- /.admin-main -->
</div><!-- /.admin-layout -->
</body>
</html>
