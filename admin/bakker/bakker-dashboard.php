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
$stmtExtra->execute([$today, date('Y-m-d', strtotime('+14 days'))]);
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
for ($d = 1; $d <= 30; $d++) {
    $checkDate = date('Y-m-d', strtotime("+{$d} days"));
    if (isBakdag($checkDate, $bakdagenPatroon, $extraDatums)) {
        if (countBakdagenBetween($today, $checkDate, $bakdagenPatroon, $extraDatums) >= $voorbereidingDagen) {
            $nextBakdag = $checkDate; $nextBakdagDt = new DateTime($checkDate); break;
        }
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
    LEFT JOIN product_variants pv ON boi.variant_id = pv.id
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

// Existing bakacties for $date
$existingBakactiesByType = [];
$stmtAllBa = $pdo->prepare("SELECT id, COALESCE(dough_type_name,'') as dough_type_name, status, notes_data FROM bak_acties WHERE DATE(datum) = ?");
$stmtAllBa->execute([$date]);
foreach ($stmtAllBa->fetchAll() as $ba) {
    $nd = $ba['notes_data'] ? json_decode($ba['notes_data'], true) : [];
    $existingBakactiesByType[$ba['dough_type_name']] = [
        'id'        => (int)$ba['id'],
        'status'    => $ba['status'],
        'day_times' => $nd['day_times'] ?? [],
    ];
}
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

    /* Summary bar */
    .summary-bar { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .summary-stat { background: var(--cream); padding: 0.85rem 1.25rem; border-radius: 10px; border: 1px solid var(--border); }
    .summary-stat .label { font-size: 0.72rem; color: #888; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.2rem; }
    .summary-stat .value { font-size: 1.2rem; font-weight: 700; color: #c8913a; }

    /* Dough type nav */
    .dough-type-nav { display: flex; flex-direction: column; gap: 0.6rem; }
    .dough-type-nav-card { display: flex; align-items: center; padding: 1rem 1.25rem; background: var(--cream); border-radius: 10px; color: inherit; border-left: 4px solid #3d6b3d; gap: 1rem; border: 1px solid var(--border); border-left-width: 4px; }
    .dough-type-nav-info { flex: 1; min-width: 0; }
    .dough-type-nav-name { font-size: 1rem; font-weight: 700; color: #2d4a2d; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; flex-wrap: wrap; }
    .dough-type-nav-stats { font-size: 0.8rem; color: #888; display: flex; gap: 1rem; flex-wrap: wrap; }
    .dough-type-nav-badge { background: #d4edda; color: #155724; font-size: 0.68rem; padding: 0.1rem 0.45rem; border-radius: 10px; font-weight: 600; }
    .dough-type-nav-links { display: flex; gap: 0.5rem; flex-shrink: 0; align-items: center; }
    .dough-type-nav-links a { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; text-decoration: none; white-space: nowrap; }
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
    .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
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
        <p><?php if ($todayIsBakdag): ?>Vandaag is een bakdag.<?php elseif ($nextBakdagDt): ?>Volgende bakdag: <?= $dutchDayNames[(int)$nextBakdagDt->format('w')] ?> <?= $nextBakdagDt->format('j-m') ?>.<?php else: ?>Geen bakdagen gepland.<?php endif; ?></p>
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
            </div>

            <?php if (empty($doughGroups) && empty($noRecipeGroup['products'])): ?>
                <div class="empty-state">
                    <i class="bi bi-emoji-smile"></i>
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
                    ?>
                    <div class="dough-type-nav-card">
                        <div class="dough-type-nav-info">
                            <div class="dough-type-nav-name">
                                <i class="bi bi-layers"></i>
                                <?= htmlspecialchars($doughTypeName) ?>
                                <span style="font-size:0.72rem;color:#888;font-weight:400">v<?= $doughGroup['dough_type_version'] ?></span>
                                <span class="ba-status-badge ba-status-<?= $baStatus ?>"><?= $baLabels[$baStatus] ?></span>
                            </div>
                            <div class="dough-type-nav-stats">
                                <span><i class="bi bi-box"></i> <?= $doughGroup['total_qty'] ?> stuks</span>
                                <span><i class="bi bi-speedometer"></i> <?= number_format($doughGroup['total_weight']/1000, 1, ',', '.') ?> kg deeg</span>
                            </div>
                        </div>
                        <div class="dough-type-nav-links">
                            <a href="?date=<?= $date ?>&dough_type=<?= urlencode($doughTypeName) ?>" class="nav-link-overzicht"><i class="bi bi-list-ul"></i> Overzicht</a>
                            <?php if ($baEntry): ?>
                                <a href="bak-actie.php?id=<?= $baEntry['id'] ?>" class="nav-link-bakactie"><i class="bi bi-journal-bookmark"></i> Bakactie</a>
                            <?php else: ?>
                                <a href="bak-actie.php?date=<?= urlencode($date) ?>&dough_type=<?= urlencode($doughTypeName) ?>&dough_type_id=<?= $doughGroup['dough_type_id'] ?>&qty=<?= $doughGroup['total_qty'] ?>&weight=<?= $doughGroup['total_weight'] ?>" class="nav-link-bakactie"><i class="bi bi-journal-plus"></i> Bakactie</a>
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
                        <?php if ($existingBakactieId): ?>
                            <a href="bak-actie.php?id=<?= (int)$existingBakactieId ?>" class="btn btn-bakactie"><i class="bi bi-journal-bookmark"></i> Bakactie</a>
                        <?php else: ?>
                            <a href="bak-actie.php?date=<?= urlencode($date) ?>&dough_type=<?= urlencode($filterDoughType) ?>&dough_type_id=<?= $bakactieSimple ? (int)$bakactieSimple['dough_type_id'] : 0 ?>&qty=<?= $bakactieSimple ? (int)$bakactieSimple['total_qty'] : 0 ?>&weight=<?= $bakactieSimple ? (int)$bakactieSimple['total_weight_g'] : 0 ?>" class="btn btn-bakactie"><i class="bi bi-journal-plus"></i> Bakactie</a>
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
                                        <span><span class="ingredient-weight"><?= $grain['weight'] ?>g</span><span class="ingredient-pct">(<?= $grain['pct'] ?>%)</span></span>
                                    </div>
                                <?php endforeach; ?>
                                <div class="total-row"><span class="label">Totaal meel (hoofddeeg)</span><span class="value"><?= $calc['mainFlour'] ?>g</span></div>
                            </div>

                            <div class="ingredient-section">
                                <h3><i class="bi bi-droplet"></i> Hoofddeeg — Water &amp; Zout</h3>
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
                                <h3><i class="bi bi-stars"></i> Toppings</h3>
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
                <h3><i class="bi bi-truck" style="color:#1976d2"></i> Bezorging vandaag</h3>
                <a href="planning.php?filter=bezorging&mode=day" class="summary-header-link">Bekijk alles</a>
            </div>
            <div class="summary-body">
                <?php if (empty($upcomingDeliveries)): ?>
                    <div class="empty-state"><i class="bi bi-emoji-smile"></i>Geen leveringen vandaag</div>
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
                <h3><i class="bi bi-thermometer-half" style="color:#c8913a"></i> Bakkerij temp</h3>
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
</script>
</body>
</html>
