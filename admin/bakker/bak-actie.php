<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

// ── Params ────────────────────────────────────────────────────────────────────
$date       = isset($_GET['date'])         ? $_GET['date']               : date('Y-m-d');
$doughType  = isset($_GET['dough_type'])   ? trim($_GET['dough_type'])   : '';
$loadId     = isset($_GET['id'])           ? (int)$_GET['id']           : null;
$paramQty   = isset($_GET['qty'])          ? (int)$_GET['qty']           : 0;
$paramWeight= isset($_GET['weight'])       ? (int)$_GET['weight']        : 0;
$paramDtId  = isset($_GET['dough_type_id'])? (int)$_GET['dough_type_id'] : 0;

// ── Load or detect existing bakactie ─────────────────────────────────────────
$existing = null;
if ($loadId) {
    $stmt = $pdo->prepare("SELECT * FROM bak_acties WHERE id = ?");
    $stmt->execute([$loadId]);
    $existing = $stmt->fetch() ?: null;
} elseif ($doughType && $date) {
    $stmt = $pdo->prepare("SELECT * FROM bak_acties WHERE DATE(datum) = ? AND dough_type_name = ?");
    $stmt->execute([$date, $doughType]);
    $existing = $stmt->fetch() ?: null;
}

if ($existing) {
    $date       = date('Y-m-d', strtotime($existing['datum']));
    $doughType  = $existing['dough_type_name'] ?? $doughType;
    $existing['notes_data'] = $existing['notes_data'] ? json_decode($existing['notes_data'], true) : [];
    $existing['order_ids']  = $existing['order_ids']  ? json_decode($existing['order_ids'],  true) : [];
}

// ── Helper functions ──────────────────────────────────────────────────────────
function getDutchDayNameBA($date) {
    $d = ['zondag','maandag','dinsdag','woensdag','donderdag','vrijdag','zaterdag'];
    return $d[$date->format('w')];
}
function getDutchMonthNameBA($date) {
    $m = ['januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];
    return $m[$date->format('n') - 1];
}
function formatDutchDateBA($date) {
    return getDutchDayNameBA($date) . ' ' . $date->format('j') . ' ' . getDutchMonthNameBA($date);
}

function calculateIngredientsBA($recipeData, $totalQty, $totalWeightG, $ingredientNames = []) {
    if ($totalQty <= 0 || $totalWeightG <= 0) return null;
    $weightPerBall    = $totalWeightG / $totalQty;
    $hydration        = $recipeData['hydration'] ?? 62;
    $saltPct          = $recipeData['saltPct']   ?? 2.6;
    $totalDoughWeight = $totalQty * $weightPerBall;
    $totalFlour       = $totalDoughWeight / (1 + $hydration/100 + $saltPct/100);
    $totalWater       = $totalFlour * ($hydration / 100);
    $saltWeight       = $totalFlour * ($saltPct   / 100);
    $mainFlour        = $totalFlour;
    $grainFallback    = [
        'wheat_white' => 'Tarwebloem', 'wheat_whole' => 'Tarwemeel',
        'spelt_white' => 'Speltbloem', 'spelt_whole' => 'Speltvollekorn',
        'rye_white'   => 'Roggebloem', 'rye_whole'   => 'Roggemeel',
        'durum'       => 'Durumbloem', 'emmer'       => 'Emmer',
        'einkorn'     => 'Einkorn',    'buckwheat'   => 'Boekweit',
    ];

    $sourdough = null;
    if (!empty($recipeData['useSourdough']) && !empty($recipeData['sourdoughPct'])) {
        $sdPct  = $recipeData['sourdoughPct'];
        $sdHyd  = $recipeData['sourdoughHydration'] ?? 100;
        $sdW    = $totalFlour * ($sdPct / 100);
        $sdF    = $sdW / (1 + $sdHyd/100);
        $mainFlour -= $sdF;
        $sourdough = ['weight'=>(int)ceil($sdW),'flour'=>(int)ceil($sdF),'water'=>(int)ceil($sdW-$sdF),'pct'=>$sdPct,'hydration'=>$sdHyd];
    }
    $preFerment = null;
    if (!empty($recipeData['usePreFerment']) && !empty($recipeData['preFermentPct'])) {
        $pfPct = $recipeData['preFermentPct'];
        $pfHyd = $recipeData['preFermentHydration'] ?? 100;
        $pfW   = $totalFlour * ($pfPct / 100);
        $pfF   = $pfW / (1 + $pfHyd/100);
        $mainFlour -= $pfF;
        $preFerment = ['weight'=>(int)ceil($pfW),'flour'=>(int)ceil($pfF),'water'=>(int)ceil($pfW-$pfF),'pct'=>$pfPct,'hydration'=>$pfHyd];
    }
    $mainWater = $totalWater
        - ($sourdough   ? $sourdough['water']   : 0)
        - ($preFerment  ? $preFerment['water']  : 0);

    $grains = [];
    foreach ($recipeData['mainDoughGrains'] ?? [['type'=>'wheat_white','pct'=>100]] as $g) {
        if ($g['pct'] > 0) {
            $n = $ingredientNames[$g['type']] ?? $grainFallback[$g['type']] ?? $g['type'];
            $grains[] = ['name'=>$n,'weight'=>(int)ceil($mainFlour*($g['pct']/100)),'pct'=>$g['pct'],'type'=>$g['type']];
        }
    }
    $leveners = [];
    if (!empty($recipeData['useYeast']) && !empty($recipeData['yeastPct'])) {
        $yt = $recipeData['yeastType'] ?? '';
        $ytNames = ['fresh_yeast'=>'Verse gist','instant_yeast'=>'Instant gist','sourdough_culture'=>'Desemcultuur'];
        $leveners[] = ['name'=>$ingredientNames[$yt]??$ytNames[$yt]??'Gist','weight'=>(int)ceil($totalFlour*($recipeData['yeastPct']/100)),'pct'=>$recipeData['yeastPct']];
    }
    $mixins = [];
    $mixinBase = ($recipeData['mixinMode']??'flour')==='dough' ? $totalDoughWeight : $totalFlour;
    foreach ($recipeData['mixins'] ?? [] as $m) {
        if (!empty($m['ingredient']) && $m['pct'] > 0)
            $mixins[] = ['name'=>$m['ingredient'],'weight'=>(int)ceil($mixinBase*($m['pct']/100)),'pct'=>$m['pct']];
    }
    $toppings = [];
    foreach ($recipeData['toppings'] ?? [] as $t) {
        if (!empty($t['ingredient']) && $t['pct'] > 0)
            $toppings[] = ['name'=>$t['ingredient'],'weight'=>(int)ceil($totalDoughWeight*($t['pct']/100)),'pct'=>$t['pct']];
    }
    $totalFlour = (int)ceil($totalFlour);
    $mainFlour  = (int)ceil($mainFlour);
    $totalWater = (int)ceil($totalWater);
    $mainWater  = (int)ceil($mainWater);
    $saltWeight = (int)ceil($saltWeight);
    return compact('totalFlour','mainFlour','totalWater','mainWater','saltWeight',
                   'totalDoughWeight','hydration','saltPct','grains','leveners',
                   'mixins','toppings','sourdough','preFerment');
}

// ── Ingredient name lookup ────────────────────────────────────────────────────
$ingredientNames = [];
try {
    foreach ($pdo->query("SELECT id, name FROM ingredients")->fetchAll() as $ing)
        $ingredientNames[$ing['id']] = $ing['name'];
} catch (PDOException $e) {}

// ── Load dough type from DB ───────────────────────────────────────────────────
$doughTypeRow  = null;
$recipeData    = null;
$methodDays    = null;
$doughTypeId   = $paramDtId;

if ($doughType) {
    $stmt = $pdo->prepare("SELECT id, name, recipe_data, COALESCE(current_version,1) as current_version FROM dough_types WHERE name = ?");
    $stmt->execute([$doughType]);
    $doughTypeRow = $stmt->fetch() ?: null;
    if ($doughTypeRow) {
        $doughTypeId = (int)$doughTypeRow['id'];
        $recipeData  = $doughTypeRow['recipe_data'] ? json_decode($doughTypeRow['recipe_data'], true) : null;
        $methodDays  = $recipeData['methodDays'] ?? null;
    }
}

// ── Totals ────────────────────────────────────────────────────────────────────
$totalQty    = $existing ? (int)($existing['total_qty'] ?? 0)      : $paramQty;
$totalWeightG= $existing ? (int)($existing['total_weight_g'] ?? 0) : $paramWeight;
$calc        = ($recipeData && $totalQty > 0 && $totalWeightG > 0)
    ? calculateIngredientsBA($recipeData, $totalQty, $totalWeightG, $ingredientNames)
    : null;

// ── Brand picker: load sub-products with stock per ingredient group ───────────
$savedBrands = $existing['notes_data']['ingredient_brands'] ?? [];
if ($calc) {
    $brandRows = $pdo->query(
        "SELECT i.id as group_id, i.name as group_name, i.grain_type_id,
                c.id as child_id, c.brand_name, c.is_biologisch,
                COALESCE(SUM(b.quantity_remaining), 0) as stock_g
         FROM ingredients i
         JOIN ingredients c ON c.parent_id = i.id AND c.is_active = 1
         LEFT JOIN ingredient_batches b ON b.ingredient_id = c.id AND b.quantity_remaining > 0
         WHERE i.parent_id IS NULL AND i.is_active = 1
         GROUP BY c.id
         ORDER BY i.name, c.is_biologisch DESC, c.brand_name"
    )->fetchAll();
    $brandsByGrainType = [];
    $brandsByName      = [];
    foreach ($brandRows as $brow) {
        $child = [
            'id'            => (int)$brow['child_id'],
            'brand_name'    => $brow['brand_name'] ?: 'Standaard',
            'is_biologisch' => (bool)$brow['is_biologisch'],
            'stock_g'       => (float)$brow['stock_g'],
        ];
        if ($brow['grain_type_id'])
            $brandsByGrainType[(int)$brow['grain_type_id']][] = $child;
        $brandsByName[$brow['group_name']][] = $child;
    }
    foreach ($calc['grains'] as &$grain) {
        $gtype = $grain['type'] ?? null;
        $pool = $gtype && isset($brandsByGrainType[$gtype])
            ? $brandsByGrainType[$gtype]
            : ($brandsByName[$grain['name']] ?? []);
        $grain['brands'] = array_values(array_filter($pool, fn($c) => $c['stock_g'] >= $grain['weight']));
    }
    unset($grain);
    foreach ($calc['leveners'] as &$lev) {
        $pool = $brandsByName[$lev['name']] ?? [];
        $lev['brands'] = array_values(array_filter($pool, fn($c) => $c['stock_g'] >= $lev['weight']));
    }
    unset($lev);
    foreach ($calc['mixins'] as &$mixin) {
        $pool = $brandsByName[$mixin['name']] ?? [];
        $mixin['brands'] = array_values(array_filter($pool, fn($c) => $c['stock_g'] >= $mixin['weight']));
    }
    unset($mixin);
}

// ── Delivery date from method ─────────────────────────────────────────────────
$bereidingDate = new DateTime($date);
$deliveryDt    = clone $bereidingDate;
if ($methodDays && count($methodDays) > 1)
    $deliveryDt->modify('+' . (count($methodDays) - 1) . ' days');

// ── Today's day index within method days ──────────────────────────────────────
$todayDayIndex = null;
$hasMethodDays = $methodDays && count($methodDays) > 0;
if ($hasMethodDays) {
    $todayStr  = date('Y-m-d');
    $prepStart0 = clone $deliveryDt;
    $prepStart0->modify('-' . (count($methodDays) - 1) . ' days');
    foreach ($methodDays as $_di => $_day) {
        $_dayDt = clone $prepStart0;
        $_dayDt->modify('+' . $_di . ' days');
        if ($_dayDt->format('Y-m-d') === $todayStr) { $todayDayIndex = $_di; break; }
    }
}

// ── Sidebar / page setup ──────────────────────────────────────────────────────
$adminPageTitle = 'Bakactie' . ($doughType ? ' — ' . $doughType : '');
$currentPage    = 'logboek';
$adminBasePath  = '../';

ob_start(); ?>
<style>
    *, *::before, *::after { box-sizing: border-box; }
    .ba-wrap { max-width: 1200px; margin: 0 auto; padding: 1.5rem 2rem; }
    .ba-topbar {
        display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        background: #fff; border-bottom: 1px solid #e5e7eb;
        padding: 0.85rem 2rem; position: sticky; top: 0; z-index: 40;
    }
    .ba-topbar-title { font-size: 1.05rem; font-weight: 700; color: #1f2937; flex: 1; display: flex; align-items: center; gap: 0.6rem; }
    .ba-back { color: #6b7280; text-decoration: none; font-size: 0.88rem; display: flex; align-items: center; gap: 0.3rem; }
    .ba-back:hover { color: #111827; }
    .status-badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .status-gepland  { background: #fef3c7; color: #92400e; }
    .status-bezig    { background: #dbeafe; color: #1e40af; }
    .status-voltooid { background: #d1fae5; color: #065f46; }

    .ba-layout { display: grid; grid-template-columns: 1fr 340px; gap: 1.5rem; align-items: start; margin-top: 1.5rem; }
    @media (max-width: 900px) { .ba-layout { grid-template-columns: 1fr; } }

    .ba-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); margin-bottom: 1.25rem; overflow: hidden; }
    .ba-card-header { padding: 1rem 1.25rem 0.75rem; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; gap: 0.5rem; }
    .ba-card-header h2 { font-size: 0.88rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.05em; margin: 0; }
    .ba-card-body { padding: 1.25rem; }

    /* Ingredient table */
    .ing-section { margin-bottom: 1.25rem; }
    .ing-section-title { font-size: 0.8rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.6rem; padding-bottom: 0.4rem; border-bottom: 2px solid #f3f4f6; }
    .ing-row { display: flex; justify-content: space-between; align-items: baseline; padding: 0.45rem 0; font-size: 1rem; border-bottom: 1px solid #fafafa; }
    .ing-row:last-child { border-bottom: none; }
    .ing-name { color: #374151; font-size: 1rem; }
    .ing-weight { font-weight: 800; color: #92400e; font-size: 1.15rem; font-variant-numeric: tabular-nums; }
    .ing-pct { font-size: 0.8rem; color: #9ca3af; margin-left: 0.4rem; }
    .ing-total { background: #faf8f4; border-radius: 8px; padding: 0.55rem 0.8rem; display: flex; justify-content: space-between; align-items: baseline; font-weight: 700; font-size: 1rem; margin-top: 0.5rem; border: 1px solid #ede8e0; }
    .ing-total .label { color: #6b7280; }
    .ing-total .val   { color: #92400e; font-size: 1.2rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .ing-brand-row { padding: 0.2rem 0 0.45rem; border-bottom: 1px solid #fafafa; }
    .ing-brand-select { font-size: 0.8rem; color: #5c3d1e; background: #fdf8f3; border: 1px solid #e8ddd0; border-radius: 5px; padding: 0.2rem 0.4rem; width: 100%; cursor: pointer; }
    .ing-brand-select:focus { outline: none; border-color: #8b5a2b; }

    /* Per-dag status & tijden */
    .day-status-badge { display: inline-flex; align-items: center; padding: 0.15rem 0.55rem; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
    .day-status-gepland  { background: #fef3c7; color: #92400e; }
    .day-status-bezig    { background: #dbeafe; color: #1e40af; }
    .day-status-afgerond { background: #d1fae5; color: #065f46; }
    .day-time-section { border-top: 1px solid #f3f4f6; margin-top: 0.75rem; padding-top: 0.75rem; }
    .day-time-row { display: flex; gap: 0.6rem; align-items: flex-end; flex-wrap: wrap; }
    .day-time-field { display: flex; flex-direction: column; gap: 0.25rem; }
    .day-time-field label { font-size: 0.7rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.04em; }
    .day-time-input-wrap { display: flex; }
    .day-time-input-wrap input[type=time] { padding: 0.4rem 0.5rem; border: 1px solid #d1d5db; border-right: none; border-radius: 6px 0 0 6px; font-size: 0.9rem; color: #1f2937; font-family: inherit; width: 100px; }
    .day-time-input-wrap input[type=time]:focus { outline: none; border-color: #92400e; }
    .day-btn-nu { padding: 0.4rem 0.55rem; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 0 6px 6px 0; font-size: 0.75rem; font-weight: 700; color: #374151; cursor: pointer; white-space: nowrap; }
    .day-btn-nu:hover { background: #e5e7eb; }
    .day-duration { font-size: 0.82rem; font-weight: 700; color: #059669; align-self: flex-end; padding-bottom: 0.4rem; white-space: nowrap; }

    /* Method days */
    .method-day { border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 0.9rem; overflow: hidden; }
    .method-day-header { padding: 0.65rem 1rem; background: #f9fafb; display: flex; align-items: center; gap: 0.6rem; font-weight: 700; font-size: 0.9rem; color: #1f2937; }
    .method-day-header.today { background: #fff5f0; border-left: 3px solid #ff6b35; color: #92400e; }
    .method-day-header.past  { background: #f9fafb; color: #9ca3af; }
    .method-day-body { padding: 0.75rem 1rem; }
    .method-step { font-size: 0.85rem; color: #4b5563; padding: 0.2rem 0; padding-left: 1rem; position: relative; }
    .method-step::before { content: '·'; position: absolute; left: 0.3rem; color: #d1d5db; }
    .method-day-note { margin-top: 0.6rem; }
    .method-day-note label { font-size: 0.72rem; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 0.3rem; }
    .method-day-note textarea { width: 100%; border: 1px solid #e5e7eb; border-radius: 7px; padding: 0.5rem 0.6rem; font-size: 0.85rem; color: #374151; resize: vertical; min-height: 54px; font-family: inherit; background: #fafafa; }
    .method-day-note textarea:focus { outline: none; border-color: #92400e; background: #fff; }

    /* Form fields */
    .field { margin-bottom: 1rem; }
    .field label { display: block; font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; }
    .field input, .field textarea, .field select {
        width: 100%; padding: 0.55rem 0.75rem; border: 1px solid #d1d5db; border-radius: 8px;
        font-size: 0.92rem; color: #1f2937; font-family: inherit; background: #fff;
    }
    .field input:focus, .field textarea:focus, .field select:focus { outline: none; border-color: #92400e; box-shadow: 0 0 0 3px rgba(146,64,14,0.08); }
    .field textarea { resize: vertical; min-height: 70px; }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .field-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; }

    /* Temp fields */
    .temp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .temp-field label { display: block; font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.3rem; }
    .temp-input-wrap { display: flex; align-items: stretch; }
    .temp-input { flex: 1; padding: 0.5rem 0.6rem; border: 1px solid #d1d5db; border-right: none; border-radius: 7px 0 0 7px; font-size: 1rem; font-weight: 600; color: #1f2937; width: 100%; font-family: inherit; }
    .temp-input:focus { outline: none; border-color: #92400e; }
    .temp-unit { padding: 0.5rem 0.5rem; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 0 7px 7px 0; font-size: 0.8rem; color: #6b7280; font-weight: 600; display: flex; align-items: center; white-space: nowrap; }

    /* DDT water calculator */
    .ddt-calc { margin-top: 1rem; border-top: 2px solid #eff6ff; padding-top: 1rem; }
    .ddt-calc-title { font-size: 0.72rem; font-weight: 700; color: #1976d2; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.65rem; display: flex; align-items: center; gap: 0.4rem; }
    .ddt-row { display: flex; align-items: flex-end; gap: 0.6rem; flex-wrap: wrap; }
    .ddt-field { display: flex; flex-direction: column; gap: 0.2rem; }
    .ddt-field label { font-size: 0.68rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; }
    .ddt-input-wrap { display: flex; align-items: stretch; }
    .ddt-input { width: 72px; padding: 0.45rem 0.5rem; border: 1px solid #d1d5db; border-right: none; border-radius: 6px 0 0 6px; font-size: 1rem; font-weight: 700; color: #1f2937; font-family: inherit; font-variant-numeric: tabular-nums; }
    .ddt-input:focus { outline: none; border-color: #1976d2; }
    .ddt-unit { padding: 0.45rem 0.45rem; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 0 6px 6px 0; font-size: 0.78rem; color: #6b7280; font-weight: 600; display: flex; align-items: center; white-space: nowrap; }
    .ddt-result { padding: 0.55rem 1rem; border-radius: 10px; font-size: 1.5rem; font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; align-self: flex-end; min-width: 90px; text-align: center; transition: background 0.2s; }
    .ddt-formula { font-size: 0.68rem; color: #9ca3af; margin-top: 0.4rem; }
    .ddt-cold { background: #eff6ff; color: #1d4ed8; }
    .ddt-cool { background: #f0fdf4; color: #166534; }
    .ddt-warm { background: #fff7ed; color: #c2410c; }
    .ddt-hot  { background: #fef2f2; color: #b91c1c; }
    .ddt-empty { background: #f3f4f6; color: #9ca3af; }

    /* Quality stars */
    .star-row { display: flex; gap: 0.4rem; margin-top: 0.35rem; }
    .star-btn { background: none; border: 2px solid #d1d5db; border-radius: 50%; width: 36px; height: 36px; cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; justify-content: center; transition: all 0.15s; color: #d1d5db; }
    .star-btn.active { border-color: #f59e0b; color: #f59e0b; background: #fffbeb; }
    .star-btn:hover  { border-color: #f59e0b; color: #f59e0b; }

    /* Action buttons */
    .ba-actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .btn-save { padding: 0.65rem 1.75rem; background: linear-gradient(135deg, #92400e, #78350f); color: #fff; border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; }
    .btn-save:hover { background: linear-gradient(135deg, #78350f, #5c3d1e); }
    .btn-save:disabled { opacity: 0.6; cursor: not-allowed; }
    .btn-voltooid { padding: 0.65rem 1.5rem; background: #fff; border: 2px solid #059669; color: #065f46; border-radius: 8px; font-size: 0.92rem; font-weight: 700; cursor: pointer; }
    .btn-voltooid:hover { background: #d1fae5; }
    .btn-secondary { padding: 0.65rem 1.25rem; background: #fff; border: 1px solid #d1d5db; color: #374151; border-radius: 8px; font-size: 0.88rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; }
    .btn-secondary:hover { background: #f9fafb; }
    .save-msg { font-size: 0.85rem; color: #059669; font-weight: 600; display: none; }
    .save-msg.error { color: #dc2626; }

    /* Stat pills */
    .stat-pills { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .stat-pill { background: #f3f4f6; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.82rem; color: #374151; }
    .stat-pill strong { color: #92400e; }
</style>
<?php
$adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php';
?>

<div class="ba-topbar">
    <a href="dagproductie.php?date=<?= htmlspecialchars($date) ?>&dough_type=<?= urlencode($doughType) ?>" class="ba-back">
        <i class="bi bi-arrow-left"></i> Dagproductie
    </a>
    <div class="ba-topbar-title">
        <i class="bi bi-journal-bookmark" style="color:#92400e"></i>
        Bakactie — <?= htmlspecialchars($doughType) ?>
        <?php
            $currentStatus = $existing['status'] ?? 'gepland';
            $statusLabels  = ['gepland'=>'Gepland','bezig'=>'Bezig','voltooid'=>'Voltooid'];
        ?>
        <span class="status-badge status-<?= $currentStatus ?>"><?= $statusLabels[$currentStatus] ?></span>
    </div>
    <div class="ba-actions">
        <span class="save-msg" id="saveMsg"></span>
        <button class="btn-save" id="btnSave" onclick="saveBakactie(false)">
            <i class="bi bi-floppy"></i> Opslaan
        </button>
        <?php if ($currentStatus !== 'voltooid'): ?>
        <button class="btn-voltooid" onclick="saveBakactie(true)">
            <i class="bi bi-check-circle"></i> Markeer als voltooid
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="ba-wrap">
    <div class="ba-layout">

        <!-- ── Left column: Recept + Methode ── -->
        <div>

            <?php if ($calc): ?>
            <div class="ba-card">
                <div class="ba-card-header">
                    <i class="bi bi-layers" style="color:#92400e"></i>
                    <h2>Recept — <?= htmlspecialchars($doughType) ?></h2>
                    <?php if ($doughTypeRow): ?>
                    <a href="recepten.php#dt-<?= $doughTypeId ?>/versies" style="margin-left:auto;font-size:0.75rem;color:#6b7280;text-decoration:none;padding:0.15rem 0.5rem;border:1px solid #e5e7eb;border-radius:4px">
                        v<?= $doughTypeRow['current_version'] ?> <i class="bi bi-box-arrow-up-right" style="font-size:0.65rem"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <div class="ba-card-body">
                    <div class="stat-pills">
                        <span class="stat-pill"><strong><?= $totalQty ?></strong> broden</span>
                        <span class="stat-pill"><strong><?= number_format($totalWeightG/1000, 1, ',', '.') ?> kg</strong> deeg</span>
                        <span class="stat-pill"><strong><?= $calc['hydration'] ?>%</strong> hydratatie</span>
                        <span class="stat-pill">Zout <strong><?= $calc['saltPct'] ?>%</strong></span>
                    </div>

                    <!-- Sourdough -->
                    <?php if ($calc['sourdough']): $sd = $calc['sourdough']; ?>
                    <div class="ing-section">
                        <div class="ing-section-title"><i class="bi bi-fire"></i> Zuurdesem (<?= $sd['pct'] ?>% v/h meel, <?= $sd['hydration'] ?>% hydratatie)</div>
                        <div class="ing-row"><span class="ing-name">Meel in zuurdesem</span><span class="ing-weight"><?= $sd['flour'] ?>g</span></div>
                        <div class="ing-row"><span class="ing-name">Water in zuurdesem</span><span class="ing-weight"><?= $sd['water'] ?>g</span></div>
                        <div class="ing-total"><span class="label">Zuurdesem totaal</span><span class="val"><?= $sd['weight'] ?>g</span></div>
                    </div>
                    <?php endif; ?>

                    <!-- Pre-ferment -->
                    <?php if ($calc['preFerment']): $pf = $calc['preFerment']; ?>
                    <div class="ing-section">
                        <div class="ing-section-title"><i class="bi bi-droplet-half"></i> Voordeeg (<?= $pf['pct'] ?>%)</div>
                        <div class="ing-row"><span class="ing-name">Meel in voordeeg</span><span class="ing-weight"><?= $pf['flour'] ?>g</span></div>
                        <div class="ing-row"><span class="ing-name">Water in voordeeg</span><span class="ing-weight"><?= $pf['water'] ?>g</span></div>
                        <div class="ing-total"><span class="label">Voordeeg totaal</span><span class="val"><?= $pf['weight'] ?>g</span></div>
                    </div>
                    <?php endif; ?>

                    <!-- Main flour -->
                    <div class="ing-section">
                        <div class="ing-section-title"><i class="bi bi-moisture"></i> Hoofddeeg — Meel</div>
                        <?php foreach ($calc['grains'] as $g): ?>
                        <div class="ing-row">
                            <span class="ing-name"><?= htmlspecialchars($g['name']) ?></span>
                            <span><span class="ing-weight"><?= $g['weight'] ?>g</span><span class="ing-pct">(<?= $g['pct'] ?>%)</span></span>
                        </div>
                        <?php if (!empty($g['brands'])): ?>
                        <div class="ing-brand-row">
                            <select class="ing-brand-select" data-ing="<?= htmlspecialchars($g['name']) ?>">
                                <option value="">— Automatisch FIFO —</option>
                                <?php foreach ($g['brands'] as $br): ?>
                                <option value="<?= $br['id'] ?>" <?= ($savedBrands[$g['name']] ?? null) == $br['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($br['brand_name']) ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,2,',','.') ?>kg voorraad
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <div class="ing-total"><span class="label">Totaal meel hoofddeeg</span><span class="val"><?= $calc['mainFlour'] ?>g</span></div>
                    </div>

                    <!-- Water & salt -->
                    <div class="ing-section">
                        <div class="ing-section-title"><i class="bi bi-droplet"></i> Hoofddeeg — Water & Zout</div>
                        <div class="ing-row"><span class="ing-name">Water</span><span class="ing-weight"><?= $calc['mainWater'] ?>g</span></div>
                        <div class="ing-row"><span class="ing-name">Zout</span><span><span class="ing-weight"><?= $calc['saltWeight'] ?>g</span><span class="ing-pct">(<?= $calc['saltPct'] ?>%)</span></span></div>
                    </div>

                    <!-- Leveners -->
                    <?php if (!empty($calc['leveners'])): ?>
                    <div class="ing-section">
                        <div class="ing-section-title"><i class="bi bi-stars"></i> Rijsmiddel</div>
                        <?php foreach ($calc['leveners'] as $l): ?>
                        <div class="ing-row"><span class="ing-name"><?= htmlspecialchars($l['name']) ?></span><span><span class="ing-weight"><?= $l['weight'] ?>g</span><span class="ing-pct">(<?= $l['pct'] ?>%)</span></span></div>
                        <?php if (!empty($l['brands'])): ?>
                        <div class="ing-brand-row">
                            <select class="ing-brand-select" data-ing="<?= htmlspecialchars($l['name']) ?>">
                                <option value="">— Automatisch FIFO —</option>
                                <?php foreach ($l['brands'] as $br): ?>
                                <option value="<?= $br['id'] ?>" <?= ($savedBrands[$l['name']] ?? null) == $br['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($br['brand_name']) ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,2,',','.') ?>kg voorraad
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Mixins -->
                    <?php if (!empty($calc['mixins'])): ?>
                    <div class="ing-section">
                        <div class="ing-section-title"><i class="bi bi-plus-circle"></i> Toevoegingen</div>
                        <?php foreach ($calc['mixins'] as $m): ?>
                        <div class="ing-row"><span class="ing-name"><?= htmlspecialchars($m['name']) ?></span><span><span class="ing-weight"><?= $m['weight'] ?>g</span><span class="ing-pct">(<?= $m['pct'] ?>%)</span></span></div>
                        <?php if (!empty($m['brands'])): ?>
                        <div class="ing-brand-row">
                            <select class="ing-brand-select" data-ing="<?= htmlspecialchars($m['name']) ?>">
                                <option value="">— Automatisch FIFO —</option>
                                <?php foreach ($m['brands'] as $br): ?>
                                <option value="<?= $br['id'] ?>" <?= ($savedBrands[$m['name']] ?? null) == $br['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($br['brand_name']) ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,2,',','.') ?>kg voorraad
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Totals -->
                    <div class="ing-total" style="font-size:0.95rem;margin-top:0.75rem">
                        <span class="label">Totaal deeggewicht</span>
                        <span class="val"><?= number_format($calc['totalDoughWeight']/1000,3,',','.') ?> kg</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Methode -->
            <?php if ($hasMethodDays):
                $today = new DateTime(date('Y-m-d'));
                $prepStart = clone $deliveryDt;
                $prepStart->modify('-' . (count($methodDays) - 1) . ' days');
                $nd = $existing['notes_data'] ?? [];
                $stepNotes = $nd['step_notes'] ?? [];
                $dayTimes  = $nd['day_times']  ?? [];
            ?>
            <div class="ba-card">
                <div class="ba-card-header">
                    <i class="bi bi-calendar-week" style="color:#3d6b3d"></i>
                    <h2>Methode — <?= count($methodDays) ?> dagen</h2>
                    <span style="margin-left:auto;font-size:0.8rem;color:#6b7280">Levering <?= formatDutchDateBA($deliveryDt) ?></span>
                </div>
                <div class="ba-card-body">
                    <?php foreach ($methodDays as $di => $day):
                        $dayDt    = clone $prepStart;
                        $dayDt->modify('+' . $di . ' days');
                        $isToday  = ($dayDt->format('Y-m-d') === $today->format('Y-m-d'));
                        $daysDiff = (int)$today->diff($dayDt)->format('%r%a');
                        $isPast   = ($daysDiff < 0);
                        $dayLabel = $day['label'] ?? ('Dag ' . ($di + 1));
                        $headerClass = $isToday ? 'today' : ($isPast ? 'past' : '');
                        $noteKey  = (string)$di;
                        $dayStart = $dayTimes[$noteKey]['start'] ?? '';
                        $dayEnd   = $dayTimes[$noteKey]['end']   ?? '';
                        // Derive per-day status
                        if ($dayEnd)        $dayStatus = 'afgerond';
                        elseif ($dayStart)  $dayStatus = 'bezig';
                        else                $dayStatus = 'gepland';
                        $dayStatusLabels = ['gepland'=>'Gepland','bezig'=>'Bezig','afgerond'=>'Afgerond'];
                    ?>
                    <div class="method-day">
                        <div class="method-day-header <?= $headerClass ?>" style="gap:0.5rem">
                            <?php if ($isToday): ?><i class="bi bi-arrow-right-circle-fill" style="color:#ff6b35"></i><?php endif; ?>
                            <?php if ($isPast):  ?><i class="bi bi-check-circle-fill" style="color:#4caf50"></i><?php endif; ?>
                            <?= htmlspecialchars($dayLabel) ?> — <?= getDutchDayNameBA($dayDt) ?> <?= $dayDt->format('j') ?> <?= getDutchMonthNameBA($dayDt) ?>
                            <span id="day-status-badge-<?= $di ?>" class="day-status-badge day-status-<?= $dayStatus ?>"><?= $dayStatusLabels[$dayStatus] ?></span>
                            <?php if ($isToday): ?>
                                <span style="margin-left:auto;font-size:0.75rem;background:#ff6b35;color:#fff;padding:0.1rem 0.45rem;border-radius:4px">Vandaag</span>
                            <?php elseif ($daysDiff === 1): ?>
                                <span style="margin-left:auto;font-size:0.75rem;background:#fef3c7;color:#92400e;padding:0.1rem 0.45rem;border-radius:4px">Morgen</span>
                            <?php elseif (!$isPast): ?>
                                <span style="margin-left:auto;font-size:0.75rem;color:#6b7280">Nog <?= $daysDiff ?> dagen</span>
                            <?php endif; ?>
                        </div>
                        <div class="method-day-body">
                            <?php foreach ($day['steps'] ?? [] as $si => $step):
                                $stepTitle = is_array($step) ? ($step['title'] ?? '') : (string)$step;
                                if (trim($stepTitle)): ?>
                                <div class="method-step"><span style="color:#92400e;font-weight:600">Stap <?= $si+1 ?>:</span> <?= htmlspecialchars($stepTitle) ?></div>
                            <?php endif; endforeach; ?>

                            <div class="day-time-section">
                                <div class="day-time-row">
                                    <div class="day-time-field">
                                        <label>Starttijd</label>
                                        <div class="day-time-input-wrap">
                                            <input type="time" id="day_start_<?= $di ?>" data-day-start="<?= $di ?>" value="<?= htmlspecialchars($dayStart) ?>" oninput="updateDayDuration(<?= $di ?>);updateDayStatus(<?= $di ?>)">
                                            <button type="button" class="day-btn-nu" id="day-start-btn-<?= $di ?>" onclick="startDay(<?= $di ?>)" <?= $dayStart ? 'style="display:none"' : '' ?>>Nu</button>
                                        </div>
                                    </div>
                                    <div class="day-time-field">
                                        <label>Eindtijd</label>
                                        <div class="day-time-input-wrap">
                                            <input type="time" id="day_end_<?= $di ?>" data-day-end="<?= $di ?>" value="<?= htmlspecialchars($dayEnd) ?>" oninput="updateDayDuration(<?= $di ?>);updateDayStatus(<?= $di ?>)">
                                            <button type="button" class="day-btn-nu" id="day-end-btn-<?= $di ?>" onclick="endDay(<?= $di ?>)" <?= (!$dayStart || $dayEnd) ? 'style="display:none"' : '' ?>>Nu</button>
                                        </div>
                                    </div>
                                    <div class="day-duration" id="day-duration-<?= $di ?>"><?php
                                        if ($dayStart && $dayEnd) {
                                            list($sh,$sm) = explode(':', $dayStart);
                                            list($eh,$em) = explode(':', $dayEnd);
                                            $mins = ($eh*60+$em) - ($sh*60+$sm);
                                            if ($mins > 0) {
                                                $h = floor($mins/60); $m = $mins%60;
                                                echo $h . 'u' . ($m ? ' ' . $m . 'min' : '');
                                            }
                                        }
                                    ?></div>
                                </div>
                            </div>

                            <div class="method-day-note">
                                <label>Notitie dag <?= $di+1 ?></label>
                                <textarea name="step_note_<?= $di ?>" placeholder="Observaties voor deze dag..."><?= htmlspecialchars($stepNotes[$noteKey] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- end left column -->

        <!-- ── Right column: Basisinfo + Temps + Notities ── -->
        <div>

            <!-- Basisgegevens -->
            <div class="ba-card">
                <div class="ba-card-header">
                    <i class="bi bi-person-badge" style="color:#3d6b3d"></i>
                    <h2>Basisgegevens</h2>
                </div>
                <div class="ba-card-body">
                    <div class="field">
                        <label>Bakker</label>
                        <input type="text" id="bakker" placeholder="Naam bakker" value="<?= htmlspecialchars($existing['bakker'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>Datum</label>
                        <input type="date" id="datum" value="<?= htmlspecialchars($date) ?>">
                    </div>
                    <?php if (!$hasMethodDays): ?>
                    <div class="field-row">
                        <div class="field">
                            <label>Starttijd</label>
                            <div class="day-time-input-wrap">
                                <input type="time" id="start_time" value="<?= htmlspecialchars($existing['start_time'] ?? '') ?>">
                                <button type="button" class="day-btn-nu" id="single-start-btn" onclick="startSingleDay()"<?= !empty($existing['start_time']) ? ' style="display:none"' : '' ?>>Nu</button>
                            </div>
                        </div>
                        <div class="field">
                            <label>Eindtijd</label>
                            <div class="day-time-input-wrap">
                                <input type="time" id="end_time" value="<?= htmlspecialchars($existing['end_time'] ?? '') ?>">
                                <button type="button" class="day-btn-nu" id="single-end-btn" onclick="endSingleDay()"<?= (empty($existing['start_time']) || !empty($existing['end_time'])) ? ' style="display:none"' : '' ?>>Nu</button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Temperaturen -->
            <div class="ba-card">
                <div class="ba-card-header">
                    <i class="bi bi-thermometer-half" style="color:#2196f3"></i>
                    <h2>Temperaturen</h2>
                    <span id="bt-info" style="margin-left:auto;font-size:0.75rem;color:#6b7280"></span>
                </div>
                <div class="ba-card-body">
                    <div class="temp-grid">
                        <div class="temp-field">
                            <label>Meeltemperatuur</label>
                            <div class="temp-input-wrap">
                                <input type="number" class="temp-input" id="flour_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($existing['flour_temp'] ?? '') ?>" oninput="calcDDT()">
                                <span class="temp-unit">°C</span>
                            </div>
                        </div>
                        <div class="temp-field">
                            <label>Omgevingstemperatuur</label>
                            <div class="temp-input-wrap">
                                <input type="number" class="temp-input" id="ambient_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($existing['ambient_temp'] ?? '') ?>" oninput="calcDDT()">
                                <span class="temp-unit">°C</span>
                            </div>
                        </div>
                        <div class="temp-field">
                            <label>Watertemperatuur</label>
                            <div class="temp-input-wrap">
                                <input type="number" class="temp-input" id="water_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($existing['water_temp'] ?? '') ?>">
                                <span class="temp-unit">°C</span>
                            </div>
                        </div>
                        <div class="temp-field">
                            <label>Deegtemperatuur (FDT)</label>
                            <div class="temp-input-wrap">
                                <input type="number" class="temp-input" id="dough_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($existing['dough_temp'] ?? '') ?>">
                                <span class="temp-unit">°C</span>
                            </div>
                        </div>
                        <div class="temp-field">
                            <label>Oventemperatuur</label>
                            <div class="temp-input-wrap">
                                <input type="number" class="temp-input" id="oven_temp" step="1" placeholder="—" value="<?= htmlspecialchars($existing['oven_temp'] ?? '') ?>">
                                <span class="temp-unit">°C</span>
                            </div>
                        </div>
                        <div class="temp-field">
                            <label>Baktijd</label>
                            <div class="temp-input-wrap">
                                <input type="number" class="temp-input" id="bake_time_minutes" step="1" min="0" placeholder="—" value="<?= htmlspecialchars($existing['bake_time_minutes'] ?? '') ?>">
                                <span class="temp-unit">min</span>
                            </div>
                        </div>
                    </div>

                    <!-- DDT Water Calculator -->
                    <div class="ddt-calc">
                        <div class="ddt-calc-title"><i class="bi bi-calculator"></i> Watertemperatuur berekenen</div>
                        <div class="ddt-row">
                            <div class="ddt-field">
                                <label>Gewenste deegtemp (DDT)</label>
                                <div class="ddt-input-wrap">
                                    <input type="number" class="ddt-input" id="ddt-dough" value="24" min="0" max="40" step="0.5" oninput="calcDDT()">
                                    <span class="ddt-unit">°C</span>
                                </div>
                            </div>
                            <div class="ddt-field">
                                <label>Voordeeg/levain <span style="color:#d1d5db">(opt.)</span></label>
                                <div class="ddt-input-wrap">
                                    <input type="number" class="ddt-input" id="ddt-preferment" placeholder="—" min="-10" max="40" step="0.5" oninput="calcDDT()">
                                    <span class="ddt-unit">°C</span>
                                </div>
                            </div>
                            <div class="ddt-field">
                                <label>Wrijving kneder <span style="color:#d1d5db">(opt.)</span></label>
                                <div class="ddt-input-wrap">
                                    <input type="number" class="ddt-input" id="ddt-friction" value="8" min="0" max="30" step="1" oninput="calcDDT()">
                                    <span class="ddt-unit">°C</span>
                                </div>
                            </div>
                            <div>
                                <div style="font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.2rem">Watertemperatuur</div>
                                <div id="ddt-result" class="ddt-result ddt-empty">—</div>
                            </div>
                        </div>
                        <div id="ddt-formula" class="ddt-formula"></div>
                    </div>
                </div>
            </div>

            <!-- Notities -->
            <?php
                $nd = $existing['notes_data'] ?? [];
                $quality = (int)($nd['quality'] ?? 0);
            ?>
            <div class="ba-card">
                <div class="ba-card-header">
                    <i class="bi bi-journal-text" style="color:#92400e"></i>
                    <h2>Notities</h2>
                </div>
                <div class="ba-card-body">
                    <div class="field">
                        <label>Kwaliteitsbeoordeling</label>
                        <div class="star-row" id="starRow">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button type="button" class="star-btn <?= $quality >= $s ? 'active' : '' ?>" data-val="<?= $s ?>" onclick="setStar(<?= $s ?>)">★</button>
                            <?php endfor; ?>
                            <span id="starLabel" style="font-size:0.82rem;color:#6b7280;margin-left:0.3rem;align-self:center"><?= $quality > 0 ? $quality . '/5' : 'Nog niet beoordeeld' ?></span>
                        </div>
                        <input type="hidden" id="quality" value="<?= $quality ?>">
                    </div>
                    <div class="field">
                        <label><i class="bi bi-exclamation-triangle" style="color:#f59e0b"></i> Afwijkingen van recept</label>
                        <textarea id="notes_deviations" placeholder="Wat week af van het standaardrecept?"><?= htmlspecialchars($nd['deviations'] ?? '') ?></textarea>
                    </div>
                    <div class="field">
                        <label><i class="bi bi-eye" style="color:#3d6b3d"></i> Observaties</label>
                        <textarea id="notes_observations" placeholder="Hoe zag het deeg eruit? Hoe verliep de rijs?"><?= htmlspecialchars($nd['observations'] ?? '') ?></textarea>
                    </div>
                    <div class="field">
                        <label><i class="bi bi-chat-left-text"></i> Algemene notities</label>
                        <textarea id="notes_general" placeholder="Overige opmerkingen..."><?= htmlspecialchars($nd['general'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Bottom save -->
            <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;padding:0.25rem 0 1rem">
                <button class="btn-save" onclick="saveBakactie(false)">
                    <i class="bi bi-floppy"></i> Opslaan
                </button>
                <?php if ($currentStatus !== 'voltooid'): ?>
                <button class="btn-voltooid" onclick="saveBakactie(true)">
                    <i class="bi bi-check-circle"></i> Voltooid
                </button>
                <?php endif; ?>
                <a href="logboek.php" class="btn-secondary"><i class="bi bi-journal-check"></i> Logboek</a>
            </div>

        </div><!-- end right column -->
    </div>
</div><!-- /.ba-wrap -->

<script>
var BA_EXISTING_ID    = <?= $existing ? (int)$existing['id'] : 'null' ?>;
var BA_DATE           = <?= json_encode($date) ?>;
var BA_DOUGH_TYPE     = <?= json_encode($doughType) ?>;
var BA_DOUGH_TYPE_ID  = <?= json_encode($doughTypeId) ?>;
var BA_QTY            = <?= (int)$totalQty ?>;
var BA_WEIGHT         = <?= (int)$totalWeightG ?>;
var BA_HAS_METHOD     = <?= $hasMethodDays ? 'true' : 'false' ?>;
var BA_TODAY_DAY_IDX  = <?= $todayDayIndex !== null ? (int)$todayDayIndex : 'null' ?>;
var BT_KEY = 'civetta_bakery_temp';
var TODAY  = <?= json_encode(date('Y-m-d')) ?>;

// Pre-fill flour_temp and ambient_temp from bakkerij temp if fields are empty
(function() {
    try {
        var bt = JSON.parse(localStorage.getItem(BT_KEY));
        if (!bt || bt.value === undefined) return;
        var flourEl   = document.getElementById('flour_temp');
        var ambientEl = document.getElementById('ambient_temp');
        var prefEl    = document.getElementById('ddt-preferment');
        if (flourEl   && !flourEl.value)   flourEl.value   = bt.value;
        if (ambientEl && !ambientEl.value) ambientEl.value = bt.value;
        if (prefEl    && !prefEl.value)    prefEl.value    = bt.value;
        // Show info badge
        var infoEl = document.getElementById('bt-info');
        if (infoEl) {
            var isToday = bt.date === TODAY;
            var when = isToday ? ('vandaag ' + (bt.time || '')) : (bt.date + ' (oud)');
            infoEl.textContent = 'Bakkerij: ' + bt.value + '°C — ' + when;
            infoEl.style.color = isToday ? '#059669' : '#b45309';
        }
        calcDDT();
    } catch(e) {}
})();

function calcDDT() {
    var ddt      = parseFloat(document.getElementById('ddt-dough').value)    || 0;
    var flour    = parseFloat(document.getElementById('flour_temp').value)    || 0;
    var ambient  = parseFloat(document.getElementById('ambient_temp').value)  || 0;
    var friction = parseFloat(document.getElementById('ddt-friction').value)  || 0;
    var prefRaw  = document.getElementById('ddt-preferment').value.trim();
    var hasPref  = prefRaw !== '' && !isNaN(parseFloat(prefRaw));
    var pref     = hasPref ? parseFloat(prefRaw) : 0;
    var resultEl = document.getElementById('ddt-result');
    var formulaEl= document.getElementById('ddt-formula');
    if (!ddt) { resultEl.textContent = '—'; resultEl.className = 'ddt-result ddt-empty'; formulaEl.textContent = ''; return; }
    var water, formula;
    if (hasPref) {
        water   = ddt * 4 - (flour + ambient + pref + friction);
        formula = '(' + ddt + ' × 4) − (' + flour + ' + ' + ambient + ' + ' + pref + ' + ' + friction + ')';
    } else {
        water   = ddt * 3 - (flour + ambient + friction);
        formula = '(' + ddt + ' × 3) − (' + flour + ' + ' + ambient + (friction ? ' + ' + friction : '') + ')';
    }
    water = Math.round(water * 10) / 10;
    var cls = water <= 5 ? 'ddt-cold' : water <= 20 ? 'ddt-cool' : water <= 35 ? 'ddt-warm' : 'ddt-hot';
    resultEl.textContent = water + '°C';
    resultEl.className   = 'ddt-result ' + cls;
    formulaEl.textContent = formula + ' = ' + water + '°C';
}

function setStar(val) {
    document.getElementById('quality').value = val;
    document.querySelectorAll('.star-btn').forEach(function(b) {
        b.classList.toggle('active', parseInt(b.dataset.val) <= val);
    });
    document.getElementById('starLabel').textContent = val + '/5';
}

function collectStepNotes() {
    var stepNotes = {};
    document.querySelectorAll('textarea[name^="step_note_"]').forEach(function(ta) {
        var idx = ta.name.replace('step_note_', '');
        if (ta.value.trim()) stepNotes[idx] = ta.value.trim();
    });
    return stepNotes;
}

function collectDayTimes() {
    var dayTimes = {};
    document.querySelectorAll('[data-day-start]').forEach(function(el) {
        var idx = el.dataset.dayStart;
        if (!dayTimes[idx]) dayTimes[idx] = {};
        dayTimes[idx].start = el.value;
    });
    document.querySelectorAll('[data-day-end]').forEach(function(el) {
        var idx = el.dataset.dayEnd;
        if (!dayTimes[idx]) dayTimes[idx] = {};
        dayTimes[idx].end = el.value;
    });
    return dayTimes;
}

function deriveOverallStatus(markVoltooid) {
    if (markVoltooid) return 'voltooid';
    if (!BA_HAS_METHOD) return 'gepland'; // single-day: always save as gepland unless marked done
    var dayTimes = collectDayTimes();
    var keys = Object.keys(dayTimes);
    if (!keys.length) return 'gepland';
    var anyStarted = false, allDone = true;
    for (var k in dayTimes) {
        if (dayTimes[k].start) anyStarted = true;
        if (!dayTimes[k].end)  allDone = false;
    }
    if (allDone && anyStarted) return 'voltooid';
    if (anyStarted) return 'bezig';
    return 'gepland';
}

function updateDayDuration(di) {
    var startEl = document.getElementById('day_start_' + di);
    var endEl   = document.getElementById('day_end_'   + di);
    var durEl   = document.getElementById('day-duration-' + di);
    if (!durEl) return;
    if (startEl && startEl.value && endEl && endEl.value) {
        var s = startEl.value.split(':').map(Number);
        var e = endEl.value.split(':').map(Number);
        var mins = (e[0]*60+e[1]) - (s[0]*60+s[1]);
        if (mins > 0) {
            var h = Math.floor(mins/60), m = mins%60;
            durEl.textContent = h + 'u' + (m ? ' ' + m + 'min' : '');
        } else { durEl.textContent = ''; }
    } else { durEl.textContent = ''; }
}

function updateDayStatus(di) {
    var startEl   = document.getElementById('day_start_' + di);
    var endEl     = document.getElementById('day_end_'   + di);
    var badge     = document.getElementById('day-status-badge-' + di);
    var startBtn  = document.getElementById('day-start-btn-' + di);
    var endBtn    = document.getElementById('day-end-btn-'   + di);
    if (!badge) return;
    var hasStart = startEl && startEl.value;
    var hasEnd   = endEl   && endEl.value;
    if (hasEnd) {
        badge.textContent = 'Afgerond'; badge.className = 'day-status-badge day-status-afgerond';
    } else if (hasStart) {
        badge.textContent = 'Bezig';    badge.className = 'day-status-badge day-status-bezig';
    } else {
        badge.textContent = 'Gepland';  badge.className = 'day-status-badge day-status-gepland';
    }
    if (startBtn) startBtn.style.display = hasStart ? 'none' : '';
    if (endBtn)   endBtn.style.display   = (hasStart && !hasEnd) ? '' : 'none';
}

function nowTime() {
    var now = new Date();
    return String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
}

function startDay(di) {
    var startEl = document.getElementById('day_start_' + di);
    if (startEl && !startEl.value) startEl.value = nowTime();
    updateDayDuration(di); updateDayStatus(di);
    saveBakactie(false);
}

function endDay(di) {
    var endEl = document.getElementById('day_end_' + di);
    if (endEl && !endEl.value) endEl.value = nowTime();
    updateDayDuration(di); updateDayStatus(di);
    saveBakactie(false);
}

function startSingleDay() {
    var el = document.getElementById('start_time');
    if (el && !el.value) el.value = nowTime();
    document.getElementById('single-start-btn').style.display = 'none';
    document.getElementById('single-end-btn').style.display   = '';
    saveBakactie(false);
}

function endSingleDay() {
    var el = document.getElementById('end_time');
    if (el && !el.value) el.value = nowTime();
    document.getElementById('single-end-btn').style.display = 'none';
    saveBakactie(false);
}

function collectIngredientBrands() {
    var brands = {};
    document.querySelectorAll('.ing-brand-select').forEach(function(sel) {
        var ing = sel.getAttribute('data-ing');
        if (ing && sel.value) brands[ing] = parseInt(sel.value);
    });
    return brands;
}

function collectPayload(markVoltooid) {
    var dayTimes  = BA_HAS_METHOD ? collectDayTimes() : {};
    var notesData = {
        quality:      parseInt(document.getElementById('quality').value) || 0,
        deviations:   document.getElementById('notes_deviations').value.trim(),
        observations: document.getElementById('notes_observations').value.trim(),
        general:      document.getElementById('notes_general').value.trim(),
        step_notes:        collectStepNotes(),
        day_times:         dayTimes,
        ingredient_brands: collectIngredientBrands()
    };
    // For start_time/end_time DB columns: use first-day start and last-day end
    var startTime = '', endTime = '';
    if (BA_HAS_METHOD) {
        var keys = Object.keys(dayTimes).map(Number).sort(function(a,b){return a-b;});
        if (keys.length) {
            startTime = (dayTimes[keys[0]]   && dayTimes[keys[0]].start)   || '';
            endTime   = (dayTimes[keys[keys.length-1]] && dayTimes[keys[keys.length-1]].end) || '';
        }
    } else {
        var stEl = document.getElementById('start_time');
        var etEl = document.getElementById('end_time');
        startTime = stEl ? stEl.value : '';
        endTime   = etEl ? etEl.value : '';
    }
    return {
        datum:             document.getElementById('datum').value + ' 09:00:00',
        dough_type_name:   BA_DOUGH_TYPE,
        dough_type_id:     BA_DOUGH_TYPE_ID,
        total_qty:         BA_QTY,
        total_weight_g:    BA_WEIGHT,
        bakker:            document.getElementById('bakker').value.trim(),
        status:            deriveOverallStatus(markVoltooid),
        start_time:        startTime,
        end_time:          endTime,
        water_temp:        document.getElementById('water_temp').value,
        flour_temp:        document.getElementById('flour_temp').value,
        ambient_temp:      document.getElementById('ambient_temp').value,
        dough_temp:        document.getElementById('dough_temp').value,
        oven_temp:         document.getElementById('oven_temp').value,
        bake_time_minutes: document.getElementById('bake_time_minutes').value,
        notes_data:        notesData
    };
}

function saveBakactie(markVoltooid) {
    var btn = document.getElementById('btnSave');
    var msg = document.getElementById('saveMsg');
    if (btn) btn.disabled = true;
    msg.style.display = 'none';

    var payload = collectPayload(markVoltooid);
    var isNew   = !BA_EXISTING_ID;
    if (!isNew) payload.id = BA_EXISTING_ID;

    fetch('/api/bak-acties.php', {
        method:  isNew ? 'POST' : 'PUT',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (btn) btn.disabled = false;
        if (data.success) {
            if (isNew && data.id) BA_EXISTING_ID = data.id;
            msg.textContent = markVoltooid ? 'Bakactie voltooid!' : 'Opgeslagen';
            msg.className   = 'save-msg';
            msg.style.display = 'inline';
            setTimeout(function() { msg.style.display = 'none'; }, 3000);
            if (markVoltooid) setTimeout(function() { window.location.href = 'logboek.php'; }, 800);
        } else {
            msg.textContent = 'Fout: ' + (data.error || 'Onbekende fout');
            msg.className   = 'save-msg error';
            msg.style.display = 'inline';
        }
    })
    .catch(function(e) {
        if (btn) btn.disabled = false;
        msg.textContent = 'Fout: ' + e.message;
        msg.className   = 'save-msg error';
        msg.style.display = 'inline';
    });
}
</script>

</div><!-- /.admin-main -->
</div><!-- /.admin-layout -->
</body>
</html>
