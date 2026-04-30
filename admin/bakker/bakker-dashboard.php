<?php
require_once '../config.php';
requireLogin();

$today = date('Y-m-d');
$date  = isset($_GET['date']) ? $_GET['date'] : $today;
$bereidingDate  = new DateTime($date);
$filterDoughType = isset($_GET['dough_type']) ? $_GET['dough_type'] : null;

// ── Bakdag config (for greeting) ──────────────────────────────────────────────
$stmtBp = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_patroon'");
$stmtBp->execute();
$bakdagenPatroonStr = $stmtBp->fetchColumn() ?: '';
$bakdagenPatroon = $bakdagenPatroonStr ? array_map('intval', explode(',', $bakdagenPatroonStr)) : [];

$stmtExtra = $pdo->prepare("SELECT datum FROM bakdagen_extra WHERE datum BETWEEN ? AND ? ORDER BY datum");
$stmtExtra->execute([$today, date('Y-m-d', strtotime('+90 days'))]);
$extraDatums = array_column($stmtExtra->fetchAll(), 'datum');

$stmtVd = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
$stmtVd->execute();
$voorbereidingDagen = (int)($stmtVd->fetchColumn() ?: 3);

$todayWeekday   = (int)(new DateTime($today))->format('N');
$todayIsBakdag  = in_array($todayWeekday, $bakdagenPatroon) || in_array($today, $extraDatums);

function isBakdag($dateStr, $patroon, $extra) {
    $wd = (int)(new DateTime($dateStr))->format('N');
    return in_array($wd, $patroon) || in_array($dateStr, $extra);
}
function countBakdagenBetween($todayStr, $targetStr, $patroon, $extra) {
    $count = 0; $d = new DateTime($todayStr); $target = new DateTime($targetStr);
    while ($d <= $target) { if (isBakdag($d->format('Y-m-d'), $patroon, $extra)) $count++; $d->modify('+1 day'); }
    return $count;
}

$nextBakdag = null; $nextBakdagDt = null;
for ($d = 1; $d <= 90; $d++) {
    $checkDate = date('Y-m-d', strtotime("+{$d} days"));
    if (isBakdag($checkDate, $bakdagenPatroon, $extraDatums)) {
        if (countBakdagenBetween($today, $checkDate, $bakdagenPatroon, $extraDatums) >= $voorbereidingDagen) {
            $nextBakdag = $checkDate; $nextBakdagDt = new DateTime($checkDate); break;
        }
    }
}

// Greeting: first upcoming bakdag regardless of preparation days
$greetingNextBakdagDt = null;
for ($d = 1; $d <= 90; $d++) {
    $checkDate = date('Y-m-d', strtotime("+{$d} days"));
    if (isBakdag($checkDate, $bakdagenPatroon, $extraDatums)) {
        $greetingNextBakdagDt = new DateTime($checkDate); break;
    }
}

$dutchDayNames = ['zondag','maandag','dinsdag','woensdag','donderdag','vrijdag','zaterdag'];

// ── Ingredient names ──────────────────────────────────────────────────────────
$ingredientNames = [];
$ingStmt = $pdo->query("SELECT id, name FROM ingredients");
if ($ingStmt) {
    foreach ($ingStmt->fetchAll() as $ing) {
        $ingredientNames[$ing['id']] = $ing['name'];
        $ingredientNames[strval($ing['id'])] = $ing['name'];
    }
}

// ── Dagproductie: full window-based query (same as dagproductie.php) ──────────
$maxPrepDays = 7;
$windowEnd   = clone $bereidingDate;
$windowEnd->modify("+{$maxPrepDays} days");

$stmt = $pdo->prepare("
    SELECT bo.id as order_id, bo.delivery_date, ba.bedrijfsnaam,
           boi.product_name, boi.quantity,
           pv.recipe_id, pv.gewicht as variant_weight,
           br.name as recipe_name, br.recipe_data, br.dough_type_id,
           COALESCE(dt.name,'Geen deegsoort') as dough_type_name,
           dt.recipe_data as dough_type_recipe_data,
           COALESCE(dt.current_version,1) as dough_type_version
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    JOIN business_order_items boi ON bo.id = boi.order_id
    LEFT JOIN product_variants pv ON pv.id = COALESCE(
        boi.variant_id,
        (SELECT pv2.id FROM product_variants pv2
         WHERE pv2.product_id = boi.product_id
           AND boi.product_id IS NOT NULL
           AND pv2.gewicht > 0
           AND pv2.gewicht = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(boi.product_name, '(', -1), 'g)', 1) AS UNSIGNED)
         ORDER BY pv2.recipe_id DESC
         LIMIT 1)
    )
    LEFT JOIN products p ON COALESCE(boi.product_id, pv.product_id) = p.id
    LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
    LEFT JOIN dough_types dt ON br.dough_type_id = dt.id
    WHERE bo.delivery_date BETWEEN ? AND ? AND bo.is_cancelled = 0
    ORDER BY bo.delivery_date ASC
");
$stmt->execute([$bereidingDate->format('Y-m-d'), $windowEnd->format('Y-m-d')]);
$allItems = $stmt->fetchAll();

$doughGroups   = [];
$noRecipeGroup = ['products' => [], 'total_qty' => 0, 'total_weight' => 0];

foreach ($allItems as $item) {
    $deliveryDt    = new DateTime($item['delivery_date']);
    $doughTypeName = $item['dough_type_name'] ?? 'Geen deegsoort';

    if ($filterDoughType && $item['recipe_id'] && $doughTypeName !== $filterDoughType) continue;

    if ($item['recipe_id'] && $item['recipe_data']) {
        $methodDaysCount = $voorbereidingDagen;
        $rd = json_decode($item['recipe_data'], true);
        if (!empty($rd['methodDays'])) {
            $methodDaysCount = count($rd['methodDays']);
        } elseif (!empty($item['dough_type_recipe_data'])) {
            $dtd = json_decode($item['dough_type_recipe_data'], true);
            if (!empty($dtd['methodDays'])) $methodDaysCount = count($dtd['methodDays']);
        }
    } else {
        $methodDaysCount = 1;
        if ($filterDoughType) continue;
    }

    $prepStart = clone $deliveryDt;
    $prepStart->modify('-' . ($methodDaysCount - 1) . ' days');
    if (!($bereidingDate >= $prepStart && $bereidingDate <= $deliveryDt)) continue;

    $qty           = intval($item['quantity']);
    $variantWeight = intval($item['variant_weight'] ?? 0);
    $doughWeight   = 0;
    if (!empty($item['recipe_data'])) { $rd2 = json_decode($item['recipe_data'], true); $doughWeight = intval($rd2['doughWeight'] ?? 0); }
    $weight = $doughWeight > 0 ? $doughWeight : ($variantWeight > 0 ? $variantWeight : 300);

    if ($item['recipe_id'] && $item['recipe_data']) {
        if (!isset($doughGroups[$doughTypeName])) {
            $dtRd = !empty($item['dough_type_recipe_data']) ? json_decode($item['dough_type_recipe_data'], true) : null;
            $doughGroups[$doughTypeName] = [
                'dough_type_id'      => (int)($item['dough_type_id'] ?? 0),
                'dough_type_data'    => $dtRd,
                'dough_type_version' => (int)$item['dough_type_version'],
                'recipes'            => [],
                'products'           => [],
                'orders'             => [],
                'method_days_count'  => $methodDaysCount,
                'delivery_date'      => $item['delivery_date'],
                'total_qty'          => 0,
                'total_weight'       => 0
            ];
        }
        $doughGroups[$doughTypeName]['method_days_count'] = max($doughGroups[$doughTypeName]['method_days_count'], $methodDaysCount);

        $recipeName = $item['recipe_name'];
        if (!isset($doughGroups[$doughTypeName]['recipes'][$recipeName])) {
            $doughGroups[$doughTypeName]['recipes'][$recipeName] = ['data' => json_decode($item['recipe_data'], true), 'products' => [], 'total_qty' => 0, 'total_weight' => 0];
        }
        if (!isset($doughGroups[$doughTypeName]['recipes'][$recipeName]['products'][$item['product_name']])) {
            $doughGroups[$doughTypeName]['recipes'][$recipeName]['products'][$item['product_name']] = ['qty' => 0, 'weight' => $weight];
        }
        $doughGroups[$doughTypeName]['recipes'][$recipeName]['products'][$item['product_name']]['qty'] += $qty;
        $doughGroups[$doughTypeName]['recipes'][$recipeName]['total_qty']    += $qty;
        $doughGroups[$doughTypeName]['recipes'][$recipeName]['total_weight'] += $qty * $weight;

        $orderId = $item['order_id'];
        if (!isset($doughGroups[$doughTypeName]['orders'][$orderId])) {
            $doughGroups[$doughTypeName]['orders'][$orderId] = ['bedrijfsnaam' => $item['bedrijfsnaam'], 'items' => []];
        }
        $pKey = $item['product_name'];
        if (!isset($doughGroups[$doughTypeName]['orders'][$orderId]['items'][$pKey])) $doughGroups[$doughTypeName]['orders'][$orderId]['items'][$pKey] = 0;
        $doughGroups[$doughTypeName]['orders'][$orderId]['items'][$pKey] += $qty;

        if (!isset($doughGroups[$doughTypeName]['products'][$item['product_name']])) {
            $doughGroups[$doughTypeName]['products'][$item['product_name']] = ['qty' => 0, 'weight' => $weight];
        }
        $doughGroups[$doughTypeName]['products'][$item['product_name']]['qty'] += $qty;
        $doughGroups[$doughTypeName]['total_qty']    += $qty;
        $doughGroups[$doughTypeName]['total_weight'] += $qty * $weight;
    } else {
        if (!isset($noRecipeGroup['products'][$item['product_name']])) $noRecipeGroup['products'][$item['product_name']] = ['qty' => 0, 'weight' => $weight];
        $noRecipeGroup['products'][$item['product_name']]['qty'] += $qty;
        $noRecipeGroup['total_qty']    += $qty;
        $noRecipeGroup['total_weight'] += $qty * $weight;
    }
}

function calculateIngredients($recipeData, $totalQty, $totalWeight, $ingredientNames = []) {
    $numberOfBalls  = $totalQty;
    $weightPerBall  = $totalQty > 0 ? $totalWeight / $totalQty : 300;
    $hydration      = $recipeData['hydration'] ?? 62;
    $saltPct        = $recipeData['saltPct'] ?? 2.6;
    $totalDoughWeight = $numberOfBalls * $weightPerBall;
    $totalFlour     = $totalDoughWeight / (1 + $hydration/100 + $saltPct/100);
    $totalWater     = $totalFlour * ($hydration/100);
    $saltWeight     = $totalFlour * ($saltPct/100);
    $grainTypesFallback = [
        'wheat_white'=>'Tarwe wit','wheat_whole'=>'Tarwe volkoren','spelt_white'=>'Spelt wit','spelt_whole'=>'Spelt volkoren',
        'durum'=>'Durum','emmer'=>'Emmer','rye_white'=>'Rogge wit','rye_whole'=>'Rogge volkoren',
        'einkorn'=>'Einkorn','buckwheat'=>'Boekweit','rice'=>'Rijst','barley'=>'Gerst','teff'=>'Teff'
    ];
    $mainFlour = $totalFlour;
    $sourdough = null;
    if (!empty($recipeData['useSourdough']) && !empty($recipeData['sourdoughPct'])) {
        $sdPct = $recipeData['sourdoughPct']; $sdH = $recipeData['sourdoughHydration'] ?? 100;
        $sdW = $totalFlour * ($sdPct/100); $sdF = $sdW / (1 + $sdH/100); $sdWater = $sdW - $sdF;
        $mainFlour -= $sdF;
        $sourdough = ['weight'=>round($sdW),'flour'=>round($sdF),'water'=>round($sdWater),'hydration'=>$sdH,'pct'=>$sdPct];
    }
    $preFerment = null;
    if (!empty($recipeData['usePreFerment']) && !empty($recipeData['preFermentPct'])) {
        $pfPct = $recipeData['preFermentPct']; $pfH = $recipeData['preFermentHydration'] ?? 100;
        $pfW = $totalFlour * ($pfPct/100); $pfF = $pfW / (1 + $pfH/100); $pfWater = $pfW - $pfF;
        $mainFlour -= $pfF;
        $preFerment = ['weight'=>round($pfW),'flour'=>round($pfF),'water'=>round($pfWater),'hydration'=>$pfH,'pct'=>$pfPct];
    }
    $mainWater = $totalWater - ($sourdough ? $sourdough['water'] : 0) - ($preFerment ? $preFerment['water'] : 0);
    $grains = [];
    foreach ($recipeData['mainDoughGrains'] ?? [['type'=>'wheat_white','pct'=>100]] as $grain) {
        if ($grain['pct'] > 0) {
            $gt = $grain['type'] ?? '';
            $grains[] = ['name'=>$ingredientNames[$gt] ?? $grainTypesFallback[$gt] ?? $gt, 'weight'=>round($mainFlour*($grain['pct']/100)), 'pct'=>$grain['pct']];
        }
    }
    $leveners = [];
    if (!empty($recipeData['useYeast']) && !empty($recipeData['yeastPct'])) {
        $yt = $recipeData['yeastType'] ?? ''; $yFallback = ['fresh_yeast'=>'Verse gist','instant_yeast'=>'Instant gist','sourdough_culture'=>'Desemcultuur'];
        $leveners[] = ['name'=>$ingredientNames[$yt] ?? $yFallback[$yt] ?? 'Gist', 'weight'=>round($totalFlour*($recipeData['yeastPct']/100)), 'pct'=>$recipeData['yeastPct']];
    }
    $mixins = []; $mixinMode = $recipeData['mixinMode'] ?? 'flour'; $baseForMixin = $mixinMode === 'dough' ? $totalDoughWeight : $totalFlour;
    foreach ($recipeData['mixins'] ?? [] as $m) {
        if (!empty($m['ingredient']) && $m['pct'] > 0) $mixins[] = ['name'=>$m['ingredient'],'weight'=>round($baseForMixin*($m['pct']/100)),'pct'=>$m['pct'],'category'=>$m['category']??'non-integrated'];
    }
    $toppingsResult = [];
    foreach ($recipeData['toppings'] ?? [] as $t) {
        if (!empty($t['ingredient']) && $t['pct'] > 0) $toppingsResult[] = ['name'=>$t['ingredient'],'weight'=>round($totalDoughWeight*($t['pct']/100)),'pct'=>$t['pct']];
    }
    return [
        'totalFlour'=>round($totalFlour),'mainFlour'=>round($mainFlour),'totalWater'=>round($totalWater),'mainWater'=>round($mainWater),
        'saltWeight'=>round($saltWeight),'totalDoughWeight'=>round($totalDoughWeight),'hydration'=>$hydration,'saltPct'=>$saltPct,
        'grains'=>$grains,'leveners'=>$leveners,'mixins'=>$mixins,'toppings'=>$toppingsResult,'preFerment'=>$preFerment,'sourdough'=>$sourdough
    ];
}

function getDutchDayName($date) {
    return ['zondag','maandag','dinsdag','woensdag','donderdag','vrijdag','zaterdag'][$date->format('w')];
}
function getDutchMonthName($date) {
    return ['januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'][$date->format('n') - 1];
}
function formatDutchDate($date) {
    return getDutchDayName($date) . ' ' . $date->format('j') . ' ' . getDutchMonthName($date);
}

// Totals
$totalProducts = 0; $totalWeight = 0; $totalDoughTypeCount = count($doughGroups);
foreach ($doughGroups as $dg) { $totalProducts += $dg['total_qty']; $totalWeight += $dg['total_weight']; }
$totalProducts += $noRecipeGroup['total_qty']; $totalWeight += $noRecipeGroup['total_weight'];

// Sourdough totals grouped by grain composition
$sourdoughTotals = [];
$sdGrainLabels = [
    'wheat_white'=>'Tarwe','wheat_whole'=>'Tarwe volkoren','spelt_white'=>'Spelt','spelt_whole'=>'Spelt volkoren',
    'durum'=>'Durum','emmer'=>'Emmer','rye_white'=>'Rogge','rye_whole'=>'Rogge volkoren',
    'einkorn'=>'Einkorn','buckwheat'=>'Boekweit','rice'=>'Rijst','barley'=>'Gerst','teff'=>'Teff',
];
foreach ($doughGroups as $doughTypeName => $dg) {
    $rd = $dg['dough_type_data'];
    if (!$rd) { $first = !empty($dg['recipes']) ? reset($dg['recipes']) : null; $rd = $first['data'] ?? null; }
    if (!$rd || empty($rd['useSourdough']) || empty($rd['sourdoughPct'])) continue;

    $hydration  = $rd['hydration'] ?? 62;
    $saltPct    = $rd['saltPct']   ?? 2.6;
    $totalFlour = $dg['total_weight'] / (1 + $hydration/100 + $saltPct/100);
    $sdWeight   = $totalFlour * ($rd['sourdoughPct'] / 100);

    $grains = $rd['sourdoughGrains'] ?? [['type' => 'wheat_white', 'pct' => 100]];
    usort($grains, fn($a, $b) => ($b['pct'] ?? 0) - ($a['pct'] ?? 0));
    $keyParts = []; $labelParts = [];
    foreach ($grains as $g) {
        if (($g['pct'] ?? 0) <= 0) continue;
        $t = (string)($g['type'] ?? '');
        $keyParts[]   = $t;
        $labelParts[] = $ingredientNames[$t] ?? $sdGrainLabels[$t] ?? $t;
    }
    $key   = implode('+', $keyParts) ?: 'desem';
    $label = (implode('/', array_unique($labelParts)) ?: 'Desem') . ' desem';
    if (!isset($sourdoughTotals[$key])) $sourdoughTotals[$key] = ['label' => $label, 'weight' => 0, 'dough_type_names' => []];
    $sourdoughTotals[$key]['weight'] += $sdWeight;
    $sourdoughTotals[$key]['dough_type_names'][] = $doughTypeName;
}

// Existing bakacties, keyed by dough_type_name, matched on each group's delivery date
$existingBakactiesByType = [];
if (!empty($doughGroups)) {
    $conditions = [];
    $baParams   = [];
    foreach ($doughGroups as $doughTypeName => $dg) {
        $conditions[] = "(dough_type_name = ? AND DATE(datum) = ?)";
        $baParams[] = $doughTypeName;
        $baParams[] = $dg['delivery_date'];
    }
    $stmtAllBa = $pdo->prepare(
        "SELECT id, COALESCE(dough_type_name,'') as dough_type_name, status, notes_data,
                total_weight_g, locked_recipe_data, sourdough_consumed
         FROM bak_acties WHERE " . implode(' OR ', $conditions)
    );
    $stmtAllBa->execute($baParams);
    foreach ($stmtAllBa->fetchAll() as $ba) {
        $nd = $ba['notes_data'] ? json_decode($ba['notes_data'], true) : [];
        $existingBakactiesByType[$ba['dough_type_name']] = [
            'id'                 => (int)$ba['id'],
            'status'             => $ba['status'],
            'day_times'          => $nd['day_times'] ?? [],
            'notes_data'         => $nd,
            'total_weight_g'     => (int)$ba['total_weight_g'],
            'locked_recipe_data' => $ba['locked_recipe_data'],
            'sourdough_consumed' => (bool)$ba['sourdough_consumed'],
        ];
    }
}

// Enrich sourdoughTotals with per-bakactie data for the dashboard modal
foreach ($sourdoughTotals as $key => &$sdTotal) {
    $sdTotal['bakacties'] = [];
    foreach ($sdTotal['dough_type_names'] as $dtName) {
        $ba = $existingBakactiesByType[$dtName] ?? null;
        if (!$ba || !$ba['id']) { $sdTotal['bakacties'][] = ['id'=>0,'dough_type_name'=>$dtName,'sd_flour_g'=>0,'brand_ingredient_id'=>0,'sourdough_consumed'=>false]; continue; }
        $lockedRd = $ba['locked_recipe_data'] ? json_decode($ba['locked_recipe_data'], true) : null;
        $rd = $lockedRd ?: ($doughGroups[$dtName]['dough_type_data'] ?? null);
        $sdFlour = 0;
        if ($rd && !empty($rd['useSourdough']) && !empty($rd['sourdoughPct'])) {
            $hyd = $rd['hydration'] ?? 62; $slt = $rd['saltPct'] ?? 2.6;
            $sdPct = $rd['sourdoughPct']; $sdHyd = $rd['sourdoughHydration'] ?? 100;
            $tf = $ba['total_weight_g'] / (1 + $hyd/100 + $slt/100);
            $sdFlour = (int)round($tf * ($sdPct/100) / (1 + $sdHyd/100));
        }
        $sdTotal['bakacties'][] = [
            'id'                  => $ba['id'],
            'dough_type_name'     => $dtName,
            'sd_flour_g'          => $sdFlour,
            'brand_ingredient_id' => (int)($ba['notes_data']['ingredient_brands']['sourdough'] ?? 0),
            'sourdough_consumed'  => $ba['sourdough_consumed'],
        ];
    }
}
unset($sdTotal);
$existingBakactieId = $filterDoughType ? ($existingBakactiesByType[$filterDoughType]['id'] ?? null) : null;

$bakactieSimple = null;
if ($filterDoughType && !empty($doughGroups[$filterDoughType])) {
    $dg = $doughGroups[$filterDoughType];
    $allOids = [];
    foreach (array_keys($dg['orders']) as $oid) { $allOids[] = (int)$oid; }
    $bakactieSimple = ['dough_type_id'=>(int)($dg['dough_type_id']??0),'version'=>(int)$dg['dough_type_version'],'total_qty'=>(int)$dg['total_qty'],'total_weight_g'=>(int)$dg['total_weight'],'order_ids'=>array_values(array_unique($allOids))];
}

// ── Bezorging vandaag (always $today) ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT bo.*, ba.bedrijfsnaam FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    WHERE bo.delivery_date = ? AND bo.is_cancelled = 0 ORDER BY bo.id LIMIT 6
");
$stmt->execute([$today]);
$upcomingDeliveries = $stmt->fetchAll();

// Sidebar counters
$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = ? AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute([$today]);
$sidebarUnprocessedOrders = $stmt->fetch()['count'];
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = ? AND is_cancelled = 0");
$stmt->execute([$date]);
$totalOrdersForDate = $stmt->fetch()['count'];

$adminPageTitle = 'Bakkersdashboard';
$currentPage    = 'bakker-dashboard';
$adminBasePath  = '../';

function getGreeting() {
    $hour = (int)date('H');
    if ($hour < 12) return 'Goedemorgen';
    if ($hour < 18) return 'Goedemiddag';
    return 'Goedenavond';
}
ob_start(); ?>
<link rel="stylesheet" href="/css/bootstrap-icons.min.css">
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--cream); color: var(--text-primary); min-height: 100vh; }
    .admin-content { padding: 2rem; }
    .page-header { margin-bottom: 2rem; text-align: center; }
    .page-header h2 { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.3px; }
    .page-header p { color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem; }
    .agenda-btn-wrap { margin-bottom: 2rem; }
    .agenda-btn {
        display: inline-flex; align-items: center; gap: 0.6rem;
        padding: 0.85rem 2rem;
        background: linear-gradient(135deg, #3d6b3d, #2d4a2d);
        color: white; border-radius: var(--radius-lg);
        text-decoration: none; font-size: 1rem; font-weight: 700;
        transition: all 0.2s; box-shadow: 0 2px 8px rgba(61,107,61,0.25);
    }
    .agenda-btn:hover { background: linear-gradient(135deg, #2d4a2d, #1e3a1e); transform: translateY(-2px); box-shadow: 0 4px 14px rgba(61,107,61,0.35); }

    /* Dagproductie card */
    .dag-card { background: var(--white); border-radius: var(--radius-md); border: 1px solid var(--border); overflow: hidden; margin-bottom: 1.5rem; }
    .dag-card-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
    .dag-card-header h3 { font-size: 0.9rem; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; }
    .dag-card-body { padding: 1.25rem; }

    /* Date nav */
    .date-nav { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .date-nav a { padding: 0.4rem 0.85rem; background: var(--cream); border-radius: 8px; text-decoration: none; color: #3d6b3d; font-weight: 500; font-size: 0.875rem; border: 1px solid var(--border); }
    .date-nav a:hover { background: #fff5f0; }
    .date-nav .current { font-size: 1.1rem; font-weight: 700; color: #2d4a2d; }
    .date-nav input[type=date] { padding: 0.4rem 0.65rem; background: var(--cream); border-radius: 8px; color: #3d6b3d; font-weight: 500; font-size: 0.875rem; border: 1px solid var(--border); font-family: inherit; cursor: pointer; }

    /* Summary bar */
    .summary-bar { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .summary-stat { background: var(--cream); padding: 0.85rem 1.25rem; border-radius: 10px; border: 1px solid var(--border); }
    .summary-stat .label { font-size: 0.72rem; color: #888; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.2rem; }
    .summary-stat .value { font-size: 1.2rem; font-weight: 700; color: #c8913a; }
    .summary-stat-clickable { cursor: pointer; border-color: #c4b5fd; }
    .summary-stat-clickable:hover { background: #f5f3ff; border-color: #7c3aed; }

    /* Dough type nav */
    .dough-type-nav { display: flex; flex-direction: column; gap: 0.6rem; }
    .dough-type-nav-card { display: flex; align-items: center; padding: 1rem 1.25rem; background: var(--cream); border-radius: 10px; color: inherit; border-left: 4px solid #9ca3af; gap: 1rem; border: 1px solid var(--border); border-left-width: 4px; }
    .dough-type-nav-card.ba-gepland { border-left-color: #f59e0b; }
    .dough-type-nav-card.ba-bezig   { border-left-color: #2196f3; background: #f0f7ff; }
    .dough-type-nav-card.ba-voltooid{ border-left-color: #059669; background: #f0fdf4; }
    .dough-type-nav-info { flex: 1; min-width: 0; }
    .dough-type-nav-name { font-size: 1rem; font-weight: 700; color: #2d4a2d; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; flex-wrap: wrap; }
    .dough-type-nav-stats { font-size: 0.8rem; color: #888; display: flex; gap: 1rem; flex-wrap: wrap; }
    .dough-type-nav-badge { background: #d4edda; color: #155724; font-size: 0.68rem; padding: 0.1rem 0.45rem; border-radius: 10px; font-weight: 600; }
    .dough-type-nav-links { display: flex; gap: 0.5rem; flex-shrink: 0; align-items: center; }
    .dough-type-nav-links a, .dough-type-nav-links button { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer; font-family: inherit; border: none; }
    .nav-link-overzicht { background: #e8f0e8; color: #2d4a2d; border: 1px solid #c5d9c5; }
    .nav-link-overzicht:hover { background: #d4e8d4; }
    .nav-link-bakactie { background: #92400e; color: white; }
    .nav-link-bakactie:hover { background: #78350f; }

    /* Recipe card (inside dagproductie) */
    .recipe-card { background: var(--cream); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 1.5rem; overflow: hidden; }
    .recipe-header { background: linear-gradient(135deg, #3d6b3d, #2d4a2d); color: white; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
    .recipe-header h2 { font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem; }
    .recipe-header .stats { display: flex; gap: 1.25rem; font-size: 0.85rem; opacity: 0.9; }
    .recipe-body { padding: 1.25rem; }
    .products-used { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1.25rem; padding-bottom: 1.25rem; border-bottom: 2px solid #f0e6d8; }
    .product-tag { background: #f5f0e8; padding: 0.3rem 0.65rem; border-radius: 20px; font-size: 0.82rem; color: #2d4a2d; }
    .product-tag strong { color: #c8913a; margin-right: 0.25rem; }
    .ingredients-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem; }
    .ingredient-section { background: white; border-radius: 10px; padding: 1rem; }
    .ingredient-section h3 { font-size: 0.8rem; color: #3d6b3d; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem; padding-bottom: 0.6rem; border-bottom: 2px solid #e8e0d5; }
    .ingredient-row { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #f0e6d8; }
    .ingredient-row:last-child { border-bottom: none; }
    .ingredient-name { color: #2d4a2d; font-size: 0.88rem; }
    .ingredient-weight { font-weight: 700; color: #c8913a; font-size: 1rem; }
    .ingredient-pct { font-size: 0.75rem; color: #999; margin-left: 0.4rem; }
    .total-row { background: var(--cream); border-radius: 6px; padding: 0.6rem 0.75rem; margin-top: 0.6rem; display: flex; justify-content: space-between; font-weight: 700; }
    .total-row .label { color: #2d4a2d; font-size: 0.85rem; }
    .total-row .value { color: #c8913a; font-size: 1.1rem; }
    .no-recipe { background: #fff3cd; border: 2px solid #ffc107; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem; }
    .no-recipe h3 { color: #856404; margin-bottom: 0.6rem; display: flex; align-items: center; gap: 0.4rem; font-size: 0.95rem; }
    .no-recipe-list { display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 8px; font-size: 0.88rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; text-decoration: none; }
    .btn-primary { background: linear-gradient(135deg, #c8913a, #a0722e); color: white; }
    .btn-primary:hover { background: linear-gradient(135deg, #a0722e, #3d6b3d); }
    .btn-secondary { background: white; color: #3d6b3d; border: 2px solid #e0d5c7; }
    .btn-secondary:hover { border-color: #3d6b3d; background: #faf6f1; }
    .btn-bakactie { background: linear-gradient(135deg, #92400e, #78350f); color: white; }
    .btn-bakactie:hover { background: linear-gradient(135deg, #78350f, #5c3d1e); }
    .print-row { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; }

    /* Status badge (bakactie status on dough-type-nav) */
    .ba-status-badge { display: inline-flex; align-items: center; padding: 0.12rem 0.45rem; border-radius: 20px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
    .ba-status-gepland  { background: #fef3c7; color: #92400e; }
    .ba-status-bezig    { background: #dbeafe; color: #1e40af; }
    .ba-status-voltooid { background: #d1fae5; color: #065f46; }

    /* Summary grid (bezorging + bt) */
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; }
    .summary-card { background: var(--white); border-radius: var(--radius-md); border: 1px solid var(--border); overflow: hidden; }
    .summary-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .summary-header h3 { font-size: 0.9rem; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; }
    .summary-header-link { font-size: 0.78rem; color: var(--brown-medium); text-decoration: none; font-weight: 500; }
    .summary-header-link:hover { text-decoration: underline; }
    .summary-body { padding: 0.75rem 1.25rem; }
    .product-row { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0; border-bottom: 1px solid var(--cream-dark); }
    .product-row:last-child { border-bottom: none; }
    .product-name { font-size: 0.88rem; font-weight: 500; color: var(--text-primary); }
    .product-qty { font-size: 0.85rem; font-weight: 600; color: #e55a2b; background: #fff0eb; padding: 0.2rem 0.65rem; border-radius: 6px; }
    .delivery-row { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0; border-bottom: 1px solid var(--cream-dark); }
    .delivery-row:last-child { border-bottom: none; }
    .delivery-name { font-size: 0.88rem; font-weight: 500; color: var(--text-primary); }
    .delivery-status { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 6px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
    .delivery-status.pending   { background: #fef5e7; color: #d68910; }
    .delivery-status.onderweg  { background: #eaf4fe; color: #1976d2; }
    .delivery-status.afgeleverd { background: #eafaf1; color: #1e8449; }
    .empty-state { text-align: center; padding: 2rem 1rem; color: var(--text-muted); font-size: 0.88rem; }
    .empty-state i { font-size: 1.5rem; display: block; margin-bottom: 0.5rem; opacity: 0.4; }
    .more-link { display: block; text-align: center; padding: 0.75rem; font-size: 0.82rem; color: var(--brown-medium); text-decoration: none; border-top: 1px solid var(--cream-dark); transition: background 0.15s; }
    .more-link:hover { background: var(--cream); }

    /* Bakkerij temp */
    .bt-card { background: var(--white); border-radius: var(--radius-md); border: 1px solid var(--border); overflow: hidden; }
    .bt-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .bt-header h3 { font-size: 0.9rem; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; }
    .bt-body { padding: 1rem 1.25rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .bt-input-wrap { display: flex; align-items: stretch; }
    .bt-input { width: 90px; padding: 0.55rem 0.65rem; border: 1px solid #d1d5db; border-right: none; border-radius: 8px 0 0 8px; font-size: 1.4rem; font-weight: 700; color: #1f2937; font-family: inherit; font-variant-numeric: tabular-nums; }
    .bt-input:focus { outline: none; border-color: #c8913a; }
    .bt-unit { padding: 0.55rem 0.65rem; background: #f3f4f6; border: 1px solid #d1d5db; border-left: none; border-radius: 0 8px 8px 0; font-size: 0.9rem; font-weight: 700; color: #6b7280; display: flex; align-items: center; }
    .bt-save-btn { padding: 0.6rem 1.25rem; background: linear-gradient(135deg, #c8913a, #a07230); color: white; border: none; border-radius: 8px; font-size: 0.9rem; font-weight: 700; cursor: pointer; }
    .bt-save-btn:hover { background: linear-gradient(135deg, #a07230, #7a5520); }
    .bt-last-saved { font-size: 0.82rem; color: var(--text-muted); flex: 1; }
    .bt-last-saved strong { color: var(--text-primary); font-variant-numeric: tabular-nums; }
    .bt-stale { color: #b45309; }

    @media (max-width: 768px) {
        .admin-content { padding: 1.25rem; }
        .summary-grid { grid-template-columns: 1fr; }
        .ingredients-grid { grid-template-columns: 1fr; }
    }
    @media print {
        .topbar, .admin-topbar, .sidebar, .date-nav, .print-row, .agenda-btn-wrap, .page-header, .summary-grid { display: none !important; }
        .admin-main { margin-left: 0 !important; }
        .recipe-card { break-inside: avoid; box-shadow: none; }
        .recipe-header { background: #3d6b3d !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .ingredient-section { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }

    /* FAB */
    .fab { position: fixed; bottom: 2rem; right: 2rem; width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, #3d6b3d, #2d4a2d); color: white; border: none; cursor: pointer; box-shadow: 0 4px 15px rgba(139,90,43,0.4); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; z-index: 900; transition: all 0.2s; }
    .fab:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(139,90,43,0.5); }
    @media (max-width: 768px) { .fab { bottom: 1.5rem; right: 1.5rem; width: 48px; height: 48px; font-size: 1.25rem; } }

    /* New order modal */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: flex-start; padding: 2rem; overflow-y: auto; }
    .modal-overlay.active { display: flex; }
    .modal { background: white; border-radius: 12px; width: 100%; max-width: 600px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); margin-top: 2rem; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-bottom: 1px solid #eee; }
    .modal-header h3 { margin: 0; color: #2d4a2d; font-size: 1.1rem; }
    .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #999; line-height: 1; }
    .modal-close:hover { color: #333; }
    .new-order-modal { max-width: 700px; }
    .new-order-modal .modal-body { padding: 1.25rem; }
    .new-order-modal .form-group { margin-bottom: 1rem; }
    .new-order-modal .form-group > label { display: block; font-size: 0.85rem; font-weight: 600; color: #333; margin-bottom: 0.4rem; }
    .new-order-modal .form-control { width: 100%; padding: 0.6rem 0.8rem; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 0.95rem; transition: border-color 0.2s; box-sizing: border-box; font-family: inherit; }
    .new-order-modal .form-control:focus { border-color: #3d6b3d; outline: none; }
    .product-select-row { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; }
    .product-select-row select.product-select { flex: 3; min-width: 0; }
    .product-select-row select.variant-select { flex: 2; min-width: 0; }
    .product-select-row input[type="number"] { flex: 1; min-width: 60px; }
    .product-select-row .product-price { flex: 1; min-width: 80px; text-align: right; color: #666; font-size: 0.9rem; white-space: nowrap; }
    .product-select-row .btn-remove { width: 32px; height: 32px; border: none; background: #f8d7da; color: #dc3545; border-radius: 6px; cursor: pointer; font-size: 1rem; flex-shrink: 0; }
    .product-select-row .btn-remove:hover { background: #dc3545; color: white; }
    .btn-add-product { padding: 0.4rem 1rem; border: 2px dashed #3d6b3d; background: transparent; color: #3d6b3d; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 500; }
    .btn-add-product:hover { background: #f5f2ed; }
    .order-total-bar { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; background: #f8f9fa; border-top: 1px solid #eee; font-size: 1.1rem; font-weight: 600; }
    .order-total-bar .total-amount { color: #2d4a2d; font-size: 1.3rem; }
    .btn-submit-order { padding: 0.75rem 2rem; background: linear-gradient(135deg, #3d6b3d, #2d4a2d); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 1rem; font-weight: 600; }
    .btn-submit-order:hover { background: linear-gradient(135deg, #2d4a2d, #3e2a14); }
    .btn-submit-order:disabled { opacity: 0.6; cursor: not-allowed; }
    .internal-toggle { display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 0.85rem; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 500; color: #444; user-select: none; }
    .internal-toggle:has(input:checked) { border-color: #3d6b3d; background: #f0f5f0; color: #2d4a2d; }
    .internal-toggle input[type="checkbox"] { width: 18px; height: 18px; accent-color: #3d6b3d; }
    .customer-info-card { display: none; margin-top: 0.75rem; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 0.85rem 1rem; }
    .customer-info-card.show { display: block; }
    .customer-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem 1rem; }
    .customer-info-item .ci-label { font-size: 0.72rem; color: #888; text-transform: uppercase; letter-spacing: 0.04em; }
    .customer-info-item .ci-value { font-size: 0.88rem; color: #333; font-weight: 500; }
    .bakdag-indicator { margin-top: 0.4rem; font-size: 0.85rem; }
    .bakdag-ok { color: #2e7d32; }
    .bakdag-warning { margin-top: 0.4rem; font-size: 0.85rem; color: #856404; background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 0.4rem 0.6rem; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
    .bakdag-warning strong { cursor: pointer; text-decoration: underline; }
</style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

<header class="topbar">
    <div class="topbar-left">
        <span class="topbar-title">Bakkersdashboard<?= $filterDoughType ? ' — ' . htmlspecialchars($filterDoughType) : '' ?></span>
    </div>
    <div class="topbar-right">
        <?php if ($filterDoughType): ?>
            <a class="topbar-link" href="bakker-dashboard.php?date=<?= $date ?>"><i class="bi bi-list"></i> <span>Alle deegsoorten</span></a>
        <?php endif; ?>
    </div>
</header>

<div class="admin-content">
    <div class="page-header">
        <h2><?= getGreeting() ?>!</h2>
        <p><?php if ($todayIsBakdag): ?>Vandaag is een bakdag.<?php elseif ($greetingNextBakdagDt): ?>Volgende bakdag: <?= $dutchDayNames[(int)$greetingNextBakdagDt->format('w')] ?> <?= $greetingNextBakdagDt->format('j-m') ?>.<?php else: ?>Geen bakdagen gepland.<?php endif; ?></p>
    </div>

    <div class="agenda-btn-wrap">
        <a href="planning.php?filter=bakken&bezorging" class="agenda-btn">
            <i class="bi bi-calendar2-week"></i> Agenda
        </a>
    </div>

    <!-- ── Dagproductie card ────────────────────────────────────────────── -->
    <div class="dag-card">
        <div class="dag-card-header">
            <h3><i class="bi bi-fire" style="color:#e55a2b"></i> Dagproductie</h3>
        </div>
        <div class="dag-card-body">
            <!-- Date navigation -->
            <?php
            $prevDate = clone $bereidingDate; $prevDate->modify('-1 day');
            $nextDate = clone $bereidingDate; $nextDate->modify('+1 day');
            ?>
            <div class="date-nav">
                <a href="?date=<?= $prevDate->format('Y-m-d') ?><?= $filterDoughType ? '&dough_type=' . urlencode($filterDoughType) : '' ?>"><i class="bi bi-chevron-left"></i> Vorige</a>
                <span class="current"><?= formatDutchDate($bereidingDate) ?></span>
                <a href="?date=<?= $nextDate->format('Y-m-d') ?><?= $filterDoughType ? '&dough_type=' . urlencode($filterDoughType) : '' ?>">Volgende <i class="bi bi-chevron-right"></i></a>
                <?php if ($date !== $today): ?>
                    <a href="?date=<?= $today ?>">Vandaag</a>
                <?php endif; ?>
                <input type="date" value="<?= $date ?>" onchange="location.href='?date='+this.value<?= $filterDoughType ? "+'&dough_type=<?= urlencode($filterDoughType) ?>'" : '' ?>" title="Ga naar datum">
            </div>

            <?php if (empty($doughGroups) && empty($noRecipeGroup['products'])): ?>
                <div class="empty-state">
                    Geen bestellingen om te bereiden op deze dag
                </div>
            <?php else: ?>
                <!-- Summary bar -->
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
                    <?php foreach ($sourdoughTotals as $sd):
                        $allConsumed = !empty($sd['bakacties']) && count(array_filter($sd['bakacties'], fn($b) => !$b['sourdough_consumed'])) === 0;
                        $hasBakacties = !empty(array_filter($sd['bakacties'], fn($b) => $b['id'] > 0));
                    ?>
                    <div class="summary-stat<?= $hasBakacties ? ' summary-stat-clickable' : '' ?>"
                         <?= $hasBakacties ? 'onclick="openSdGroupModal(' . htmlspecialchars(json_encode($sd['bakacties']), ENT_QUOTES) . ',' . htmlspecialchars(json_encode($sd['label']), ENT_QUOTES) . ')"' : '' ?>
                         title="<?= $hasBakacties ? 'Klik om desem af te schrijven' : '' ?>">
                        <div class="label"><?= htmlspecialchars($sd['label']) ?><?= $allConsumed ? ' <i class="bi bi-check-circle-fill" style="color:#059669;font-size:0.75rem"></i>' : '' ?></div>
                        <div class="value"><?= number_format($sd['weight']/1000, 2, ',', '.') ?> kg</div>
                    </div>
                    <?php endforeach; ?>
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

                <?php if (!$filterDoughType): ?>
                <!-- Overview: dough-type-nav cards -->
                <div class="dough-type-nav">
                    <?php foreach ($doughGroups as $doughTypeName => $doughGroup):
                        $baEntry = $existingBakactiesByType[$doughTypeName] ?? null;
                        $baStatus = 'gepland';
                        if ($baEntry) {
                            $dt2 = $baEntry['day_times']; $anyStart = false; $allEnd = true;
                            foreach ($dt2 as $dd) { if (!empty($dd['start'])) $anyStart = true; if (empty($dd['end'])) $allEnd = false; }
                            if ($anyStart && $allEnd && count($dt2)) $baStatus = 'voltooid';
                            elseif ($anyStart) $baStatus = 'bezig';
                            elseif ($baEntry['status'] === 'voltooid') $baStatus = 'voltooid';
                            elseif ($baEntry['status'] === 'bezig')    $baStatus = 'bezig';
                        }
                        $baLabels = ['gepland'=>'Gepland','bezig'=>'Bezig','voltooid'=>'Klaar'];
                        $dgDeliveryDt = new DateTime($doughGroup['delivery_date']);
                        $dgViewDt = new DateTime($date);
                        $dgDaysToDelivery = (int)$dgViewDt->diff($dgDeliveryDt)->format('%r%a');
                        $dgNDays = max(1, $doughGroup['method_days_count']);
                        $dgTodayIdx = $dgNDays - 1 - $dgDaysToDelivery;
                        $dgDayParam = ($dgNDays > 1 && $dgTodayIdx >= 0 && $dgTodayIdx < $dgNDays) ? '&day=' . $dgTodayIdx : '';
                    ?>
                    <div class="dough-type-nav-card<?= $baEntry ? ' ba-' . $baStatus : '' ?>">
                        <div class="dough-type-nav-info">
                            <div class="dough-type-nav-name">
                                <i class="bi bi-layers"></i>
                                <?= htmlspecialchars($doughTypeName) ?>
                                <span style="font-size:0.72rem;color:#888;font-weight:400">v<?= $doughGroup['dough_type_version'] ?></span>
                                <span class="ba-status-badge ba-status-<?= $baEntry ? $baStatus : 'gepland' ?>"><?= $baLabels[$baEntry ? $baStatus : 'gepland'] ?></span>
                            </div>
                            <div class="dough-type-nav-stats">
                                <span><i class="bi bi-box"></i> <?= $doughGroup['total_qty'] ?> stuks</span>
                                <span><i class="bi bi-speedometer"></i> <?= number_format($doughGroup['total_weight']/1000, 1, ',', '.') ?> kg deeg</span>
                                <?php if ($dgNDays > 1 && $dgTodayIdx >= 0 && $dgTodayIdx < $dgNDays): ?>
                                    <span style="color:#c8913a;font-weight:600"><i class="bi bi-calendar-day"></i> Dag <?= $dgTodayIdx + 1 ?> van <?= $dgNDays ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="dough-type-nav-links">
                            <a href="?date=<?= $date ?>&dough_type=<?= urlencode($doughTypeName) ?>" class="nav-link-overzicht"><i class="bi bi-list-ul"></i> Overzicht</a>
                            <?php if ($baEntry): ?>
                                <a href="bak-actie.php?id=<?= $baEntry['id'] ?><?= $dgDayParam ?>" class="nav-link-bakactie"><i class="bi bi-journal-bookmark"></i> Bakactie</a>
                            <?php else: ?>
                                <button onclick='createGeplandBakactie(this, <?= htmlspecialchars(json_encode(['datum' => $doughGroup['delivery_date'], 'dough_type_name' => $doughTypeName, 'day_param' => $dgDayParam]), ENT_QUOTES) ?>)' class="nav-link-bakactie"><i class="bi bi-journal-plus"></i> Aanmaken</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php else: ?>
                <!-- Filtered view: full recipe card -->
                <div class="print-row">
                    <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print overzicht</button>
                    <?php if (!empty($doughGroups) && $filterDoughType): ?>
                        <?php
                            $fgDg = $doughGroups[$filterDoughType];
                            $fgDeliveryDt = new DateTime($fgDg['delivery_date']);
                            $fgViewDt = new DateTime($date);
                            $fgDaysToDelivery = (int)$fgViewDt->diff($fgDeliveryDt)->format('%r%a');
                            $fgNDays = max(1, $fgDg['method_days_count']);
                            $fgTodayIdx = $fgNDays - 1 - $fgDaysToDelivery;
                            $fgDayParam = ($fgNDays > 1 && $fgTodayIdx >= 0 && $fgTodayIdx < $fgNDays) ? '&day=' . $fgTodayIdx : '';
                        ?>
                        <?php if ($existingBakactieId): ?>
                            <a href="bak-actie.php?id=<?= (int)$existingBakactieId ?><?= $fgDayParam ?>" class="btn btn-bakactie"><i class="bi bi-journal-bookmark"></i> Bakactie</a>
                        <?php else: ?>
                            <button onclick='createGeplandBakactie(this, <?= htmlspecialchars(json_encode(['datum' => $fgDg['delivery_date'], 'dough_type_name' => $filterDoughType, 'day_param' => $fgDayParam]), ENT_QUOTES) ?>)' class="btn btn-bakactie" style="border:none;cursor:pointer;font-family:inherit;font-size:inherit"><i class="bi bi-journal-plus"></i> Aanmaken</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php foreach ($doughGroups as $doughTypeName => $doughGroup):
                    $firstRecipe = !empty($doughGroup['recipes']) ? reset($doughGroup['recipes']) : [];
                    $calcData    = $firstRecipe['data'] ?? [];
                    $calc        = calculateIngredients($calcData, $doughGroup['total_qty'], $doughGroup['total_weight'], $ingredientNames);
                ?>
                <div class="recipe-card">
                    <div class="recipe-header">
                        <h2><i class="bi bi-layers"></i> <?= htmlspecialchars($doughTypeName) ?>
                            <?php if ($doughGroup['dough_type_id']): ?>
                                <a href="recepten.php#dt-<?= $doughGroup['dough_type_id'] ?>/versies" style="font-size:0.75rem;opacity:0.8;font-weight:400;color:rgba(255,255,255,0.85);text-decoration:none;border:1px solid rgba(255,255,255,0.3);padding:0.1rem 0.4rem;border-radius:4px;margin-left:0.4rem" title="Bekijk receptversies">v<?= $doughGroup['dough_type_version'] ?> <i class="bi bi-box-arrow-up-right" style="font-size:0.65rem"></i></a>
                            <?php else: ?>
                                <span style="font-size:0.78rem;opacity:0.7;font-weight:400">v<?= $doughGroup['dough_type_version'] ?></span>
                            <?php endif; ?>
                        </h2>
                        <div class="stats">
                            <span><i class="bi bi-box"></i> <?= $doughGroup['total_qty'] ?> stuks</span>
                            <span><i class="bi bi-speedometer"></i> <?= number_format($doughGroup['total_weight']/1000, 1, ',', '.') ?> kg</span>
                            <span><?= $calc['hydration'] ?>%</span>
                        </div>
                    </div>
                    <div class="recipe-body">
                        <div class="ingredients-grid">
                            <div class="ingredient-section">
                                <h3>Hoofddeeg — Meel</h3>
                                <?php foreach ($calc['grains'] as $grain): ?>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name"><?= htmlspecialchars($grain['name']) ?></span>
                                        <span><span class="ingredient-weight"><?= $grain['weight'] ?>g</span><span class="ingredient-pct">(<?= $grain['pct'] ?>%)</span></span>
                                    </div>
                                <?php endforeach; ?>
                                <div class="total-row"><span class="label">Totaal meel (hoofddeeg)</span><span class="value"><?= $calc['mainFlour'] ?>g</span></div>
                            </div>

                            <div class="ingredient-section">
                                <h3>Hoofddeeg — Water &amp; Zout</h3>
                                <div class="ingredient-row">
                                    <span class="ingredient-name">Water</span>
                                    <span class="ingredient-weight"><?= $calc['mainWater'] ?>g</span>
                                </div>
                                <div class="ingredient-row">
                                    <span class="ingredient-name">Zout</span>
                                    <span><span class="ingredient-weight"><?= $calc['saltWeight'] ?>g</span><span class="ingredient-pct">(<?= number_format($calc['saltPct'],1,',','.') ?>%)</span></span>
                                </div>
                                <?php foreach ($calc['leveners'] as $lev): ?>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name"><?= htmlspecialchars($lev['name']) ?></span>
                                        <span><span class="ingredient-weight"><?= $lev['weight'] ?>g</span><span class="ingredient-pct">(<?= $lev['pct'] ?>%)</span></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($calc['sourdough']): ?>
                            <div class="ingredient-section">
                                <h3><i class="bi bi-fire"></i> Zuurdesem</h3>
                                <div class="ingredient-row"><span class="ingredient-name">Meel (in zuurdesem)</span><span class="ingredient-weight"><?= $calc['sourdough']['flour'] ?>g</span></div>
                                <div class="ingredient-row"><span class="ingredient-name">Water (in zuurdesem)</span><span class="ingredient-weight"><?= $calc['sourdough']['water'] ?>g</span></div>
                                <div class="total-row"><span class="label">Zuurdesem totaal (<?= $calc['sourdough']['hydration'] ?>%)</span><span class="value"><?= $calc['sourdough']['weight'] ?>g</span></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($calc['preFerment']): ?>
                            <div class="ingredient-section">
                                <h3><i class="bi bi-layers"></i> Voordeeg</h3>
                                <div class="ingredient-row"><span class="ingredient-name">Meel (in voordeeg)</span><span class="ingredient-weight"><?= $calc['preFerment']['flour'] ?>g</span></div>
                                <div class="ingredient-row"><span class="ingredient-name">Water (in voordeeg)</span><span class="ingredient-weight"><?= $calc['preFerment']['water'] ?>g</span></div>
                                <div class="total-row"><span class="label">Voordeeg totaal (<?= $calc['preFerment']['hydration'] ?>%)</span><span class="value"><?= $calc['preFerment']['weight'] ?>g</span></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($calc['mixins'])): ?>
                            <div class="ingredient-section">
                                <h3><i class="bi bi-plus-circle"></i> Mix-ins</h3>
                                <?php foreach ($calc['mixins'] as $mixin): ?>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name"><?= htmlspecialchars($mixin['name']) ?></span>
                                        <span><span class="ingredient-weight"><?= $mixin['weight'] ?>g</span><span class="ingredient-pct">(<?= $mixin['pct'] ?>%)</span></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($calc['toppings'])): ?>
                            <div class="ingredient-section">
                                <h3>Toppings</h3>
                                <?php foreach ($calc['toppings'] as $topping): ?>
                                    <div class="ingredient-row">
                                        <span class="ingredient-name"><?= htmlspecialchars($topping['name']) ?></span>
                                        <span><span class="ingredient-weight"><?= $topping['weight'] ?>g</span><span class="ingredient-pct">(<?= $topping['pct'] ?>%)</span></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <div class="ingredient-section">
                                <h3><i class="bi bi-clipboard-check"></i> Totaal</h3>
                                <div class="ingredient-row"><span class="ingredient-name">Totaal meel (incl. zuurdesem/voordeeg)</span><span class="ingredient-weight"><?= $calc['totalFlour'] ?>g</span></div>
                                <div class="ingredient-row"><span class="ingredient-name">Totaal water (incl. zuurdesem/voordeeg)</span><span class="ingredient-weight"><?= $calc['totalWater'] ?>g</span></div>
                                <div class="total-row"><span class="label">Totaal deeggewicht</span><span class="value"><?= number_format($calc['totalDoughWeight']) ?>g</span></div>
                            </div>
                        </div>

                        <?php
                        $methodDaysCount2 = $doughGroup['method_days_count'];
                        $deliveryDt2      = new DateTime($doughGroup['delivery_date']);
                        $firstRecipeData2 = reset($doughGroup['recipes'])['data'] ?? [];
                        $dtData2          = $doughGroup['dough_type_data'];
                        $methodDays2      = $dtData2['methodDays'] ?? $firstRecipeData2['methodDays'] ?? null;
                        if ($methodDays2 && count($methodDays2) > 0):
                            $prepStartDt2 = clone $deliveryDt2;
                            $prepStartDt2->modify('-' . (count($methodDays2) - 1) . ' days');
                        ?>
                        <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:2px solid #f0e6d8">
                            <h3 style="font-size:0.9rem;color:#2d4a2d;margin-bottom:0.75rem"><i class="bi bi-calendar-week"></i> Bakproces <span style="font-weight:400;color:#888;font-size:0.8rem">(<?= count($methodDays2) ?> dagen, levering <?= formatDutchDate($deliveryDt2) ?>)</span></h3>
                            <?php
                            $todayDt2 = new DateTime(date('Y-m-d'));
                            foreach ($methodDays2 as $di => $day):
                                $dayDt2   = clone $prepStartDt2; $dayDt2->modify('+' . $di . ' days');
                                $isToday2 = ($dayDt2->format('Y-m-d') === $todayDt2->format('Y-m-d'));
                                $daysDiff2 = (int)$todayDt2->diff($dayDt2)->format('%r%a');
                                $isPast2   = ($daysDiff2 < 0);
                                $dayLabel2 = $day['label'] ?? ('Dag ' . ($di + 1));
                                if ($isToday2) $badge2 = '<span style="font-size:0.75rem;background:#ff6b35;color:white;padding:0.12rem 0.45rem;border-radius:4px;font-weight:600">Vandaag</span>';
                                elseif ($isPast2) $badge2 = '<span style="font-size:0.75rem;color:#999"><i class="bi bi-check-circle-fill" style="color:#4caf50"></i></span>';
                                elseif ($daysDiff2 === 1) $badge2 = '<span style="font-size:0.75rem;background:#fff3cd;color:#856404;padding:0.12rem 0.45rem;border-radius:4px;font-weight:600">Morgen</span>';
                                else $badge2 = '<span style="font-size:0.75rem;background:#e3f2fd;color:#1565c0;padding:0.12rem 0.45rem;border-radius:4px;font-weight:600">Nog ' . $daysDiff2 . ' dagen</span>';
                            ?>
                            <div style="margin-bottom:0.6rem;padding:0.6rem 0.75rem;border-radius:8px;<?= $isToday2 ? 'background:#fff5f0;border:2px solid #ff6b35;' : ($isPast2 ? 'background:#f5f5f5;border:1px solid #e0e0e0;opacity:0.7;' : 'background:#faf8f4;border:1px solid #e8e0d5;') ?>">
                                <div style="font-weight:700;color:<?= $isToday2 ? '#ff6b35' : ($isPast2 ? '#999' : '#2d4a2d') ?>;margin-bottom:0.25rem;display:flex;align-items:center;gap:0.4rem;font-size:0.9rem">
                                    <?php if ($isToday2): ?><i class="bi bi-arrow-right-circle-fill" style="color:#ff6b35"></i><?php endif; ?>
                                    <?= htmlspecialchars($dayLabel2) ?> — <?= getDutchDayName($dayDt2) ?> <?= $dayDt2->format('j') ?> <?= getDutchMonthName($dayDt2) ?>
                                    <?= $badge2 ?>
                                </div>
                                <?php if (!empty($day['steps'])): ?>
                                    <?php foreach ($day['steps'] as $si => $step): ?>
                                        <?php $stepTitle = is_array($step) ? ($step['title'] ?? '') : (string)$step; ?>
                                        <?php if (trim($stepTitle)): ?>
                                            <div style="color:#666;font-size:0.85rem;padding-left:1.25rem;margin-top:0.15rem"><span style="color:#c8913a;font-weight:600">Stap <?= $si+1 ?>:</span> <?= htmlspecialchars($stepTitle) ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:2px solid #f0e6d8">
                            <h3 style="font-size:0.9rem;color:#2d4a2d;margin-bottom:0.75rem"><i class="bi bi-box-seam"></i> Broden uit dit deeg</h3>
                            <?php foreach ($doughGroup['recipes'] as $recipeName => $recipeInfo): ?>
                                <div style="margin-bottom:0.6rem">
                                    <div style="font-weight:600;color:#3d6b3d;margin-bottom:0.25rem;font-size:0.88rem"><i class="bi bi-journal-bookmark" style="color:#c8913a"></i> <?= htmlspecialchars($recipeName) ?></div>
                                    <div class="products-used" style="margin-bottom:0">
                                        <?php foreach ($recipeInfo['products'] as $name => $data): ?>
                                            <span class="product-tag"><strong><?= $data['qty'] ?>x</strong> <?= htmlspecialchars($name) ?> (<?= $data['weight'] ?>g)</span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:2px solid #f0e6d8">
                            <h3 style="font-size:0.9rem;color:#2d4a2d;margin-bottom:0.75rem"><i class="bi bi-people"></i> Per bestelling</h3>
                            <?php foreach ($doughGroup['orders'] as $orderId => $orderInfo): ?>
                                <div style="margin-bottom:0.4rem;padding:0.5rem 0.7rem;background:#faf8f4;border-radius:8px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.4rem">
                                    <div>
                                        <span style="font-weight:600;color:#2d4a2d;font-size:0.88rem"><?= htmlspecialchars($orderInfo['bedrijfsnaam']) ?></span>
                                        <span style="color:#999;font-size:0.75rem;margin-left:0.4rem">#<?= $orderId ?></span>
                                    </div>
                                    <div class="products-used" style="margin-bottom:0;gap:0.25rem">
                                        <?php foreach ($orderInfo['items'] as $productName => $qty): ?>
                                            <span class="product-tag" style="font-size:0.78rem;padding:0.15rem 0.5rem"><strong><?= $qty ?>x</strong> <?= htmlspecialchars($productName) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div><!-- /.recipe-body -->
                </div><!-- /.recipe-card -->
                <?php endforeach; ?>
                <?php endif; // filterDoughType ?>
            <?php endif; // empty doughGroups ?>
        </div><!-- /.dag-card-body -->
    </div><!-- /.dag-card -->

    <!-- ── Bottom summary grid ──────────────────────────────────────────── -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-header">
                <h3><?= formatDutchDate($bereidingDate) ?></h3>
                <a href="/admin/bestellingen/orders.php?delivery_date=<?= $date ?>" class="summary-header-link">Bekijk alles</a>
            </div>
            <div class="summary-body">
                <div class="summary-bar" style="margin-bottom:0;gap:0.75rem">
                    <div class="summary-stat" style="flex:1">
                        <div class="label">Bestellingen</div>
                        <div class="value"><?= $totalOrdersForDate ?></div>
                    </div>
                    <div class="summary-stat" style="flex:1">
                        <div class="label">Broden</div>
                        <div class="value"><?= $totalProducts ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-header">
                <h3><i class="bi bi-truck" style="color:#1976d2"></i> Bezorging vandaag</h3>
                <a href="planning.php?filter=bezorging&mode=day" class="summary-header-link">Bekijk alles</a>
            </div>
            <div class="summary-body">
                <?php if (empty($upcomingDeliveries)): ?>
                    <div class="empty-state">Geen leveringen vandaag</div>
                <?php else: ?>
                    <?php foreach ($upcomingDeliveries as $delivery): ?>
                        <?php
                        $dStatus = $delivery['delivery_status'] ?? 'geplaatst';
                        $dClass  = $dStatus === 'afgeleverd' ? 'afgeleverd' : ($dStatus === 'onderweg' ? 'onderweg' : 'pending');
                        $dText   = $dStatus === 'afgeleverd' ? 'Afgeleverd' : ($dStatus === 'onderweg' ? 'Onderweg' : 'Gepland');
                        ?>
                        <div class="delivery-row">
                            <span class="delivery-name"><?= htmlspecialchars($delivery['bedrijfsnaam']) ?></span>
                            <span class="delivery-status <?= $dClass ?>"><?= $dText ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="bt-card">
            <div class="bt-header">
                <h3>Bakkerij temp</h3>
                <span id="bt-last-saved" class="bt-last-saved" style="font-size:0.75rem"></span>
            </div>
            <div class="bt-body">
                <div class="bt-input-wrap">
                    <input type="number" id="bt-value" class="bt-input" step="0.5" min="-10" max="50" placeholder="—">
                    <span class="bt-unit">°C</span>
                </div>
                <button class="bt-save-btn" onclick="saveBakkerijTemp()">Opslaan</button>
            </div>
        </div>
    </div>
</div>

<button class="fab" onclick="openNewOrderModal()" title="Nieuwe bestelling">
    <i class="bi bi-plus-lg"></i>
</button>

<!-- New order modal -->
<div class="modal-overlay" id="newOrderModal">
    <div class="modal new-order-modal">
        <div class="modal-header">
            <h3><i class="bi bi-plus-circle"></i> Nieuwe Bestelling</h3>
            <button class="modal-close" onclick="closeNewOrderModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="internal-toggle">
                    <input type="checkbox" id="newOrderInternal" onchange="onInternalToggle()">
                    <span>Interne bestelling (Civetta)</span>
                </label>
            </div>
            <div class="form-group" id="customerGroup">
                <label>Klant</label>
                <select class="form-control" id="newOrderCustomer" onchange="onCustomerChange()">
                    <option value="">Selecteer een klant...</option>
                </select>
                <div class="customer-info-card" id="customerInfoCard">
                    <div class="customer-info-grid">
                        <div class="customer-info-item"><div class="ci-label">Contactpersoon</div><div class="ci-value" id="ciContact">-</div></div>
                        <div class="customer-info-item"><div class="ci-label">Telefoon</div><div class="ci-value" id="ciPhone">-</div></div>
                        <div class="customer-info-item"><div class="ci-label">E-mail</div><div class="ci-value" id="ciEmail">-</div></div>
                        <div class="customer-info-item"><div class="ci-label">Leveradres</div><div class="ci-value" id="ciAddress">-</div></div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Bakdag / Leverdatum</label>
                <input type="date" class="form-control" id="newOrderDate" onchange="checkBakdag()">
                <div class="bakdag-indicator" id="bakdagIndicator" style="display:none;">
                    <span class="bakdag-ok"><i class="bi bi-check-circle-fill"></i> Dit is een bakdag</span>
                </div>
                <div class="bakdag-warning" id="bakdagWarning" style="display:none;">
                    <i class="bi bi-exclamation-triangle-fill"></i> Dit is geen bakdag. Eerstvolgende bakdag: <strong id="nextBakdag" onclick="selectNextBakdag()"></strong>
                </div>
            </div>
            <div class="form-group">
                <label>Producten</label>
                <div id="newOrderProducts"></div>
                <button type="button" class="btn-add-product" onclick="addProductRow()">
                    <i class="bi bi-plus"></i> Product toevoegen
                </button>
            </div>
            <div class="form-group">
                <label>Opmerkingen</label>
                <textarea class="form-control" id="newOrderNotes" rows="2" placeholder="Optionele opmerkingen..." style="resize:vertical;min-height:56px"></textarea>
            </div>
        </div>
        <div class="order-total-bar">
            <span>Totaal: <span class="total-amount" id="newOrderTotal">&euro;0,00</span></span>
            <button class="btn-submit-order" id="btnSubmitOrder" onclick="submitNewOrder()">
                <i class="bi bi-check-lg"></i> Bestelling plaatsen
            </button>
        </div>
    </div>
</div>

<script src="../../js/ui-notifications.js?v=1"></script>
<script>
var BT_KEY = 'civetta_bakery_temp';
var TODAY  = '<?= date('Y-m-d') ?>';

function saveBakkerijTemp() {
    var val = parseFloat(document.getElementById('bt-value').value);
    if (isNaN(val)) return;
    var now = new Date();
    var timeStr = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
    var record = { value: val, date: TODAY, time: timeStr };
    localStorage.setItem(BT_KEY, JSON.stringify(record));
    renderBtStatus(record);
}

function renderBtStatus(bt) {
    var el = document.getElementById('bt-last-saved');
    if (!el || !bt) return;
    var isToday = bt.date === TODAY;
    var when = isToday ? ('vandaag om ' + bt.time) : (bt.date + (bt.time ? ' om ' + bt.time : ''));
    el.innerHTML = '<strong>' + bt.value + '°C</strong> — opgeslagen ' + when + (isToday ? '' : ' <span class="bt-stale">(oud)</span>');
    el.className = 'bt-last-saved' + (isToday ? '' : ' bt-stale');
}

(function() {
    try {
        var bt = JSON.parse(localStorage.getItem(BT_KEY));
        if (bt && bt.value !== undefined) {
            document.getElementById('bt-value').value = bt.value;
            renderBtStatus(bt);
        }
    } catch(e) {}
})();

// ===== New Order Modal =====
var _noAllProducts = [];
var _noAllCustomers = [];
var _noAllBakdagen = [];
var _noProductIndex = 0;

function _noToLocalDateStr(d) {
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}
function _noEscHtml(str) { var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
function _noEscAttr(str) { return str.replace(/'/g, "\\'").replace(/"/g, '&quot;'); }

async function _noLoadData() {
    if (_noAllCustomers.length && _noAllProducts.length) return;
    try {
        var [custRes, prodRes] = await Promise.all([
            fetch('../../api/admin-orders.php?action=customers'),
            fetch('../../api/admin-orders.php?action=products')
        ]);
        var custData = await custRes.json(); var prodData = await prodRes.json();
        if (custData.success) _noAllCustomers = custData.customers;
        if (prodData.success) _noAllProducts = prodData.products;
        await _noLoadBakdagen();
    } catch(e) { console.error('Error loading data:', e); }
}

async function _noLoadBakdagen() {
    try {
        var today = new Date();
        var start = _noToLocalDateStr(today);
        var end = _noToLocalDateStr(new Date(today.getFullYear(), today.getMonth()+3, today.getDate()));
        var r = await fetch('../../api/bakdagen.php?start='+start+'&end='+end);
        var d = await r.json();
        if (d.success) _noAllBakdagen = d.bakdagen || [];
    } catch(e) {}
}

function _noGetAvailableBakdagen() {
    if (document.getElementById('newOrderInternal').checked) return 999;
    var dateStr = document.getElementById('newOrderDate').value;
    if (!dateStr) return 999;
    var today = new Date(); today.setHours(0,0,0,0);
    var target = new Date(dateStr+'T00:00');
    var count = 0; var d = new Date(today);
    while (d <= target) { if (_noAllBakdagen.includes(_noToLocalDateStr(d))) count++; d.setDate(d.getDate()+1); }
    return count;
}

function checkBakdag() {
    var date = document.getElementById('newOrderDate').value;
    var indicator = document.getElementById('bakdagIndicator');
    var warning = document.getElementById('bakdagWarning');
    if (!date) { indicator.style.display = 'none'; warning.style.display = 'none'; return; }
    if (_noAllBakdagen.includes(date)) {
        indicator.style.display = ''; warning.style.display = 'none';
    } else {
        indicator.style.display = 'none'; warning.style.display = '';
        var next = _noAllBakdagen.find(function(d) { return d > date; });
        document.getElementById('nextBakdag').textContent = next
            ? new Date(next+'T00:00').toLocaleDateString('nl-NL', {weekday:'long', day:'numeric', month:'long'})
            : 'onbekend';
    }
    _noRefreshProductOptions();
}

function selectNextBakdag() {
    var date = document.getElementById('newOrderDate').value;
    var next = _noAllBakdagen.find(function(d) { return d > date; });
    if (next) { document.getElementById('newOrderDate').value = next; checkBakdag(); }
}

function onInternalToggle() {
    var isInternal = document.getElementById('newOrderInternal').checked;
    document.getElementById('customerGroup').style.display = isInternal ? 'none' : '';
    if (!isInternal) document.getElementById('customerInfoCard').classList.remove('show');
    _noRefreshProductOptions();
}

function _noGetInternalAccountId() {
    var acc = _noAllCustomers.find(function(c) { return c.is_internal == 1; });
    return acc ? acc.id : null;
}

async function openNewOrderModal(prefillDate) {
    await _noLoadData();
    document.getElementById('newOrderInternal').checked = false;
    onInternalToggle();
    var custSelect = document.getElementById('newOrderCustomer');
    custSelect.innerHTML = '<option value="">Selecteer een klant...</option>';
    _noAllCustomers.filter(function(c) { return !c.is_internal; }).forEach(function(c) {
        custSelect.innerHTML += '<option value="'+c.id+'">'+_noEscHtml(c.bedrijfsnaam)+' ('+_noEscHtml(c.contactpersoon)+')</option>';
    });
    document.getElementById('newOrderDate').value = prefillDate || _noToLocalDateStr(new Date());
    document.getElementById('newOrderNotes').value = '';
    document.getElementById('newOrderProducts').innerHTML = '';
    _noProductIndex = 0;
    addProductRow();
    _noUpdateTotal();
    checkBakdag();
    document.getElementById('newOrderModal').classList.add('active');
}

function closeNewOrderModal() {
    document.getElementById('newOrderModal').classList.remove('active');
    document.getElementById('customerInfoCard').classList.remove('show');
}

function onCustomerChange() {
    var select = document.getElementById('newOrderCustomer');
    var card = document.getElementById('customerInfoCard');
    var customerId = parseInt(select.value);
    if (!customerId) { card.classList.remove('show'); return; }
    var customer = _noAllCustomers.find(function(c) { return c.id == customerId; });
    if (!customer) { card.classList.remove('show'); return; }
    document.getElementById('ciContact').textContent = customer.contactpersoon || '-';
    var phoneEl = document.getElementById('ciPhone');
    phoneEl.innerHTML = customer.telefoon ? '<a href="tel:'+_noEscHtml(customer.telefoon)+'">'+_noEscHtml(customer.telefoon)+'</a>' : '-';
    var emailEl = document.getElementById('ciEmail');
    emailEl.innerHTML = customer.email ? '<a href="mailto:'+_noEscHtml(customer.email)+'">'+_noEscHtml(customer.email)+'</a>' : '-';
    var address = (customer.delivery_same_as_business || !customer.delivery_adres)
        ? [customer.adres, customer.postcode, customer.plaats].filter(Boolean).join(', ')
        : [customer.delivery_adres, customer.delivery_postcode, customer.delivery_plaats].filter(Boolean).join(', ');
    document.getElementById('ciAddress').textContent = address || '-';
    card.classList.add('show');
}

function _noGetEarliestDeliveryDate(recipeDays) {
    if (!recipeDays || recipeDays <= 0) recipeDays = 1;
    var today = new Date(); today.setHours(0,0,0,0);
    var count = 0; var d = new Date(today); var iter = 0;
    while (count < recipeDays && iter < 365) {
        if (_noAllBakdagen.includes(_noToLocalDateStr(d))) count++;
        if (count < recipeDays) d.setDate(d.getDate()+1);
        iter++;
    }
    return _noToLocalDateStr(d);
}

function _noBuildProductOptions() {
    var isInternal = document.getElementById('newOrderInternal').checked;
    var available = _noGetAvailableBakdagen();
    var html = '<option value="">Kies product...</option>';
    _noAllProducts.forEach(function(p) {
        var days = p.recipe_days || 1;
        if (isInternal || days <= available) {
            html += '<option value="'+p.id+'">'+_noEscHtml(p.naam)+'</option>';
        } else {
            var earliest = _noGetEarliestDeliveryDate(days);
            var label = new Date(earliest+'T00:00').toLocaleDateString('nl-NL', {weekday:'short',day:'numeric',month:'short'});
            html += '<option value="'+p.id+'" disabled style="color:#999;">'+_noEscHtml(p.naam)+' — pas vanaf '+label+'</option>';
        }
    });
    return html;
}

function _noRefreshProductOptions() {
    var options = _noBuildProductOptions();
    document.querySelectorAll('#newOrderProducts .product-select-row').forEach(function(row) {
        var ps = row.querySelector('.product-select');
        if (!ps) return;
        var cur = ps.value; ps.innerHTML = options;
        if (cur) { var p = _noAllProducts.find(function(x) { return x.id == cur; }); if (p && (document.getElementById('newOrderInternal').checked || (p.recipe_days||1) <= _noGetAvailableBakdagen())) ps.value = cur; }
    });
    _noUpdateTotal();
}

function addProductRow() {
    var container = document.getElementById('newOrderProducts');
    var idx = _noProductIndex++;
    var row = document.createElement('div');
    row.className = 'product-select-row';
    row.innerHTML =
        '<select class="form-control product-select" data-idx="'+idx+'" onchange="_noOnProductSelect(this)">'+_noBuildProductOptions()+'</select>' +
        '<select class="form-control variant-select" data-idx="'+idx+'" onchange="_noOnVariantSelect(this)" style="display:none;"></select>' +
        '<input type="number" class="form-control product-qty" data-idx="'+idx+'" min="1" value="1" oninput="_noUpdateTotal()">' +
        '<span class="product-price" data-idx="'+idx+'">€0,00</span>' +
        '<button type="button" class="btn-remove" onclick="this.closest(\'.product-select-row\').remove();_noUpdateTotal()"><i class="bi bi-x"></i></button>';
    container.appendChild(row);
}

function _noOnProductSelect(select) {
    var idx = select.dataset.idx;
    var productId = parseInt(select.value);
    var variantSelect = document.querySelector('.variant-select[data-idx="'+idx+'"]');
    var priceEl = document.querySelector('.product-price[data-idx="'+idx+'"]');
    if (!productId) { variantSelect.style.display='none'; variantSelect.innerHTML=''; priceEl.textContent='€0,00'; _noUpdateTotal(); return; }
    var product = _noAllProducts.find(function(p) { return p.id == productId; });
    if (!product) return;
    if (product.variants && product.variants.length > 0) {
        var available = _noGetAvailableBakdagen();
        var opts = '<option value="">Kies variant...</option>';
        product.variants.forEach(function(v) {
            var label = v.gewicht+'g'+(v.naam ? ' - '+v.naam : '');
            var days = v.recipe_days || 1;
            if (days <= available || document.getElementById('newOrderInternal').checked) {
                opts += '<option value="'+v.id+'" data-price="'+v.prijs+'" data-weight="'+v.gewicht+'" data-naam="'+_noEscAttr(v.naam||'')+'">'+_noEscHtml(label)+' (€'+parseFloat(v.prijs).toFixed(2).replace('.',',')+')</option>';
            } else {
                var earliest = _noGetEarliestDeliveryDate(days);
                var lbl = new Date(earliest+'T00:00').toLocaleDateString('nl-NL',{weekday:'short',day:'numeric',month:'short'});
                opts += '<option value="'+v.id+'" disabled style="color:#999;">'+_noEscHtml(label)+' — pas vanaf '+lbl+'</option>';
            }
        });
        variantSelect.innerHTML = opts; variantSelect.style.display = ''; priceEl.textContent = '€0,00';
    } else {
        variantSelect.style.display = 'none'; variantSelect.innerHTML = '';
        priceEl.textContent = '€'+parseFloat(product.prijs).toFixed(2).replace('.',',');
    }
    _noUpdateTotal();
}

function _noOnVariantSelect(select) {
    var idx = select.dataset.idx;
    var option = select.options[select.selectedIndex];
    var price = parseFloat(option && option.dataset && option.dataset.price || 0);
    document.querySelector('.product-price[data-idx="'+idx+'"]').textContent = '€'+price.toFixed(2).replace('.',',');
    _noUpdateTotal();
}

function _noUpdateTotal() {
    var total = 0;
    document.querySelectorAll('#newOrderProducts .product-select-row').forEach(function(row) {
        var ps = row.querySelector('.product-select');
        var vs = row.querySelector('.variant-select');
        var qty = parseInt(row.querySelector('.product-qty').value) || 0;
        var price = 0;
        if (ps) {
            var productId = parseInt(ps.value);
            if (productId) {
                var p = _noAllProducts.find(function(x) { return x.id == productId; });
                if (p && p.variants && p.variants.length > 0 && vs && vs.value) {
                    price = parseFloat(vs.options[vs.selectedIndex].dataset.price || 0);
                } else if (p && (!p.variants || p.variants.length === 0)) {
                    price = parseFloat(p.prijs || 0);
                }
            }
        }
        total += qty * price;
    });
    document.getElementById('newOrderTotal').textContent = '€'+total.toFixed(2).replace('.',',');
}

async function submitNewOrder() {
    var isInternal = document.getElementById('newOrderInternal').checked;
    var accountId = isInternal ? _noGetInternalAccountId() : document.getElementById('newOrderCustomer').value;
    var deliveryDate = document.getElementById('newOrderDate').value;
    var notes = document.getElementById('newOrderNotes').value.trim();
    if (!isInternal && !accountId) { showToast('Selecteer een klant', 'error'); return; }
    if (isInternal && !accountId) { showToast('Intern account niet gevonden', 'error'); return; }
    if (!deliveryDate) { showToast('Selecteer een leverdatum', 'error'); return; }
    var items = [];
    document.querySelectorAll('#newOrderProducts .product-select-row').forEach(function(row) {
        var ps = row.querySelector('.product-select');
        var vs = row.querySelector('.variant-select');
        var qty = parseInt(row.querySelector('.product-qty').value) || 0;
        if (qty <= 0 || !ps) return;
        var productId = parseInt(ps.value);
        if (!productId) return;
        var p = _noAllProducts.find(function(x) { return x.id == productId; });
        if (!p) return;
        var productName = p.naam; var price = parseFloat(p.prijs || 0);
        if (p.variants && p.variants.length > 0 && vs && vs.value) {
            var vo = vs.options[vs.selectedIndex];
            price = parseFloat(vo.dataset.price || 0);
            productName = p.naam + (vo.dataset.naam ? ' - '+vo.dataset.naam+' ('+vo.dataset.weight+'g)' : ' ('+vo.dataset.weight+'g)');
        }
        items.push({ product_name: productName, quantity: qty, unit_price: price, variant_id: (vs && vs.value ? parseInt(vs.value)||null : null), product_id: productId || null });
    });
    if (items.length === 0) { showToast('Voeg minimaal één product toe', 'error'); return; }
    var payload = { account_id: parseInt(accountId), delivery_date: deliveryDate, items: items, notes: notes };
    if (isInternal) payload.is_internal = true;
    var btn = document.getElementById('btnSubmitOrder');
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig...';
    try {
        var res = await fetch('../../api/admin-orders.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
        var data = await res.json();
        if (data.success) {
            closeNewOrderModal(); showToast(data.message, 'success'); setTimeout(function() { location.reload(); }, 1500);
        } else if (data.needs_confirm) {
            var ok = await showConfirm(data.warning, 'Bakdag waarschuwing');
            if (ok) { payload.confirm_override = true; btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i> Bestelling plaatsen'; await submitNewOrder(); }
        } else {
            showToast(data.error || 'Onbekende fout', 'error');
        }
    } catch(e) {
        showToast('Er ging iets mis bij het plaatsen van de bestelling', 'error');
    } finally {
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i> Bestelling plaatsen';
    }
}

document.getElementById('newOrderModal').addEventListener('mousedown', function(e) { this._md = e.target === this; });
document.getElementById('newOrderModal').addEventListener('click', function(e) { if (e.target === this && this._md) closeNewOrderModal(); });

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('../sw.js', { scope: '/admin/' });
    if ('PushManager' in window) {
        navigator.serviceWorker.ready.then(async reg => {
            try {
                let permission = Notification.permission;
                if (permission === 'default') permission = await Notification.requestPermission();
                if (permission !== 'granted') return;
                let sub = await reg.pushManager.getSubscription();
                if (!sub) {
                    const r = await fetch('/api/push-subscriptions.php?action=vapid-key');
                    const { publicKey } = await r.json();
                    const padding = '='.repeat((4 - publicKey.length % 4) % 4);
                    const raw = atob((publicKey + padding).replace(/-/g,'+').replace(/_/g,'/'));
                    const key = Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
                    sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
                }
                const j = sub.toJSON();
                await fetch('/api/push-subscriptions.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ endpoint:j.endpoint, keys:{ p256dh:j.keys.p256dh, auth:j.keys.auth } }) });
            } catch(e) { console.error('Push setup failed:', e); }
        });
    }
}

// ── Sourdough group modal ─────────────────────────────────────────────────────

function openSdGroupModal(bakacties, label) {
    _sdGroupBakacties = bakacties;
    _sdGroupLabel = label;
    var modal = document.getElementById('sdGroupModal');
    modal.style.display = 'flex';

    var titleEl = document.getElementById('sdGroupModalTitle');
    titleEl.textContent = label.charAt(0).toUpperCase() + label.slice(1) + ' afschrijven';

    var body = document.getElementById('sdGroupModalBody');
    var allConsumed = bakacties.every(function(b) { return b.sourdough_consumed; });
    var noneHaveBakactie = bakacties.every(function(b) { return !b.id; });

    var html = '';
    if (noneHaveBakactie) {
        html = '<p style="color:#9ca3af;text-align:center;padding:1.5rem">Geen bakactie aangemaakt voor deze dag.</p>';
    } else {
        html += '<table style="width:100%;border-collapse:collapse;font-size:0.88rem;margin-bottom:0.75rem">';
        html += '<thead><tr style="color:#9ca3af;font-size:0.72rem;text-transform:uppercase;border-bottom:2px solid #f3f4f6">';
        html += '<th style="text-align:left;padding:0.35rem 0.5rem">Deegsoort</th>';
        html += '<th style="text-align:right;padding:0.35rem 0.5rem">Desemmeel</th>';
        html += '<th style="text-align:center;padding:0.35rem 0.5rem">Status</th>';
        html += '</tr></thead><tbody>';
        bakacties.forEach(function(b) {
            html += '<tr style="border-bottom:1px solid #f9fafb">';
            html += '<td style="padding:0.45rem 0.5rem;font-weight:600;color:#1f2937">' + b.dough_type_name + '</td>';
            if (!b.id) {
                html += '<td colspan="2" style="padding:0.45rem 0.5rem;color:#9ca3af;font-size:0.8rem">Geen bakactie</td>';
            } else {
                html += '<td style="text-align:right;padding:0.45rem 0.5rem;color:#7c3aed;font-weight:700">' + b.sd_flour_g + 'g</td>';
                html += '<td style="text-align:center;padding:0.45rem 0.5rem">';
                if (b.sourdough_consumed) {
                    html += '<span style="color:#059669;font-size:0.8rem"><i class="bi bi-check-circle-fill"></i> Klaar</span>';
                } else {
                    html += '<span style="color:#7c3aed;font-size:0.8rem">Te doen</span>';
                }
                html += '</td>';
            }
            html += '</tr>';
        });
        html += '</tbody></table>';
        if (allConsumed) {
            html += '<div style="background:#d1fae5;border:1px solid #6ee7b7;padding:0.65rem 1rem;font-size:0.85rem;color:#065f46;display:flex;align-items:center;gap:0.5rem">'
                + '<i class="bi bi-check-circle-fill"></i> Alle desem is al afgeschreven voor deze dag.</div>';
        }
    }
    body.innerHTML = html;

    var btn = document.getElementById('sdGroupConfirmBtn');
    var pending = bakacties.filter(function(b) { return b.id && !b.sourdough_consumed; });
    btn.style.display = (pending.length > 0) ? '' : 'none';
}

function closeSdGroupModal() {
    document.getElementById('sdGroupModal').style.display = 'none';
}

async function createGeplandBakactie(btn, data) {
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Aanmaken...';
    try {
        const res = await fetch('/api/bak-acties.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                datum: data.datum + ' 00:00:00',
                dough_type_name: data.dough_type_name,
                status: 'gepland'
            })
        });
        const json = await res.json();
        if (json.success) {
            window.location.href = 'bak-actie.php?id=' + json.id + (data.day_param || '');
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-journal-plus"></i> Aanmaken';
        }
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-journal-plus"></i> Aanmaken';
    }
}

var _sdGroupBakacties = [];
var _sdGroupLabel = '';

function confirmSdGroupConsumption() {
    var pending = _sdGroupBakacties.filter(function(b) { return b.id && !b.sourdough_consumed; });
    if (!pending.length) return;

    var btn = document.getElementById('sdGroupConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig…';

    var promises = pending.map(function(b) {
        return fetch('/api/bak-acties.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                _action: 'consume_sourdough',
                id: b.id,
                ingredient_id: b.brand_ingredient_id,
                quantity_g: b.sd_flour_g
            })
        }).then(function(r) { return r.json(); });
    });

    Promise.all(promises).then(function(results) {
        var anyFailed = results.some(function(d) { return !d.success; });
        if (anyFailed) {
            alert('Eén of meer afschrijvingen zijn mislukt. Controleer de individuele bakacties.');
        }
        closeSdGroupModal();
        window.location.reload();
    }).catch(function(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-fire"></i> Alles afschrijven';
        alert('Verbindingsfout: ' + e.message);
    });
}
</script>

<!-- Sourdough group modal -->
<div id="sdGroupModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:300;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;max-width:480px;width:100%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.25)">
        <div style="padding:1.1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:0.75rem">
            <i class="bi bi-fire" style="color:#7c3aed;font-size:1.2rem"></i>
            <div style="font-weight:700;color:#1f2937;font-size:0.95rem" id="sdGroupModalTitle">Desem afschrijven</div>
            <button onclick="closeSdGroupModal()" style="margin-left:auto;background:none;border:none;font-size:1.4rem;color:#9ca3af;cursor:pointer;line-height:1">×</button>
        </div>
        <div id="sdGroupModalBody" style="flex:1;overflow-y:auto;padding:1.25rem"></div>
        <div style="padding:0.9rem 1.25rem;border-top:1px solid #f3f4f6;display:flex;gap:0.75rem;justify-content:flex-end;background:#fafafa">
            <button onclick="closeSdGroupModal()" style="padding:0.55rem 1.25rem;background:#fff;border:1px solid #d1d5db;font-size:0.88rem;cursor:pointer;color:#374151">Sluiten</button>
            <button id="sdGroupConfirmBtn" onclick="confirmSdGroupConsumption()" style="padding:0.55rem 1.5rem;background:#7c3aed;color:#fff;border:none;font-size:0.88rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:0.4rem">
                <i class="bi bi-fire"></i> Alles afschrijven
            </button>
        </div>
    </div>
</div>

</body>
</html>
