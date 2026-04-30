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

// Pre-fill bakkerij temp from most recent bakactie when creating a new one
$latestBakkerijTemp = null;
if (!$existing) {
    $btStmt = $pdo->query("SELECT bakkerij_temp FROM bak_acties WHERE bakkerij_temp IS NOT NULL ORDER BY datum DESC, created_at DESC LIMIT 1");
    $latestBakkerijTemp = $btStmt->fetchColumn() ?: null;
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
        // Use the bakactie's locked recipe data when available (preserves the version used at creation)
        if ($existing && !empty($existing['locked_recipe_data'])) {
            $recipeData = json_decode($existing['locked_recipe_data'], true);
            // Fall back if locked_recipe_data is JSON null or invalid JSON
            if ($recipeData === null) {
                $recipeData = $doughTypeRow['recipe_data'] ? json_decode($doughTypeRow['recipe_data'], true) : null;
            }
        } else {
            $recipeData = $doughTypeRow['recipe_data'] ? json_decode($doughTypeRow['recipe_data'], true) : null;
        }
        $methodDays  = $recipeData['methodDays'] ?? null;
    }
}

// Load available versions for the version switcher
$availableVersions = [];
if ($doughTypeId) {
    $vStmt = $pdo->prepare("SELECT id, version_number, note FROM dough_type_versions WHERE dough_type_id = ? ORDER BY version_number DESC");
    $vStmt->execute([$doughTypeId]);
    $availableVersions = $vStmt->fetchAll();
}
$lockedVersionId = $existing ? (int)($existing['dough_type_version_id'] ?? $existing['recipe_version_id'] ?? 0) : 0;

// Resolve selected dough type major version number (for mismatch detection)
$selectedDtVersionNumber = null;
foreach ($availableVersions as $av) {
    if ((int)$av['id'] === $lockedVersionId) {
        $selectedDtVersionNumber = (int)$av['version_number'];
        break;
    }
}
// Fallback: newest available
if ($selectedDtVersionNumber === null && !empty($availableVersions)) {
    $selectedDtVersionNumber = (int)$availableVersions[0]['version_number'];
}

// If recipe data is still null, load it from dough_type_versions (locked version, or latest)
if ($recipeData === null && $doughTypeId) {
    $vFallbackId = $lockedVersionId ?: (!empty($availableVersions) ? (int)$availableVersions[0]['id'] : 0);
    if ($vFallbackId) {
        $vFbStmt = $pdo->prepare("SELECT id, recipe_data FROM dough_type_versions WHERE id = ?");
        $vFbStmt->execute([$vFallbackId]);
        $vFbRow = $vFbStmt->fetch();
        if ($vFbRow && $vFbRow['recipe_data']) {
            $recipeData = json_decode($vFbRow['recipe_data'], true);
            $lockedVersionId = $lockedVersionId ?: (int)$vFbRow['id'];
            $methodDays = $recipeData['methodDays'] ?? null;
        }
    }
}

// Actions are dough-type-level; overlay from current dough type when locked_recipe_data predates action tracking
if ($methodDays && $doughTypeRow) {
    $dtCurrentData = $doughTypeRow['recipe_data'] ? json_decode($doughTypeRow['recipe_data'], true) : [];
    $dtCurrentMethodDays = $dtCurrentData['methodDays'] ?? [];
    foreach ($methodDays as $di => &$md) {
        if (empty($md['actions']) && !empty($dtCurrentMethodDays[$di]['actions'])) {
            $md['actions'] = $dtCurrentMethodDays[$di]['actions'];
        }
    }
    unset($md);
}

$sdConsumed = !empty($existing['sourdough_consumed']);

// ── Totals ────────────────────────────────────────────────────────────────────
$totalQty    = $existing ? (int)($existing['total_qty'] ?? 0)      : $paramQty;
$totalWeightG= $existing ? (int)($existing['total_weight_g'] ?? 0) : $paramWeight;

// Auto-created gepland bakacties have total_qty=0; recalculate live from current orders
if ($totalQty === 0 && $doughType && $date) {
    $dynStmt = $pdo->prepare("
        SELECT boi.quantity, pv.gewicht as variant_weight, br.recipe_data
        FROM business_orders bo
        JOIN business_order_items boi ON boi.order_id = bo.id
        LEFT JOIN product_variants pv ON pv.id = boi.variant_id
        LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
        LEFT JOIN dough_types dt ON br.dough_type_id = dt.id
        WHERE bo.delivery_date = ?
          AND bo.is_cancelled = 0
          AND COALESCE(dt.name, 'Geen deegsoort') = ?
    ");
    $dynStmt->execute([$date, $doughType]);
    $dynQty = 0; $dynWeightG = 0;
    foreach ($dynStmt->fetchAll() as $_item) {
        $qty = (int)$_item['quantity'];
        $rd  = $_item['recipe_data'] ? json_decode($_item['recipe_data'], true) : [];
        $dw  = intval($rd['doughWeight'] ?? 0);
        $w   = $dw > 0 ? $dw : (intval($_item['variant_weight'] ?? 0) ?: 300);
        $dynQty     += $qty;
        $dynWeightG += $qty * $w;
    }
    if ($dynQty > 0) {
        $totalQty     = $dynQty;
        $totalWeightG = $dynWeightG;
    }
}

// ── Loaf breakdown from orders for this date + dough type ────────────────────
$loafBreakdown = [];
if ($doughType && $date) {
    $lbStmt = $pdo->prepare("
        SELECT
            COALESCE(br.name, CONCAT(COALESCE(dt.name,''), ' ', COALESCE(CAST(pv.gewicht AS CHAR),''), 'g')) as recipe_name,
            br.id as recipe_id,
            br.current_version,
            brv.dough_type_version_number,
            brv.loaf_minor_version,
            pv.gewicht as variant_weight,
            SUM(boi.quantity) as quantity
        FROM business_orders bo
        JOIN business_order_items boi ON boi.order_id = bo.id
        LEFT JOIN product_variants pv ON pv.id = boi.variant_id
        LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
        LEFT JOIN baker_recipe_versions brv ON brv.recipe_id = br.id AND brv.version_number = br.current_version
        LEFT JOIN dough_types dt ON br.dough_type_id = dt.id
        WHERE DATE(bo.delivery_date) = ?
          AND bo.is_cancelled = 0
          AND COALESCE(dt.name, '') = ?
        GROUP BY br.id, pv.gewicht, brv.dough_type_version_number, brv.loaf_minor_version
        ORDER BY SUM(boi.quantity) DESC
    ");
    $lbStmt->execute([$date, $doughType]);
    $loafBreakdown = $lbStmt->fetchAll();
}
$lockedLoafVersions = ($existing && !empty($existing['locked_loaf_versions']))
    ? json_decode($existing['locked_loaf_versions'], true)
    : null;

$plannedLoafVersions = ($existing && !empty($existing['planned_loaf_versions']))
    ? json_decode($existing['planned_loaf_versions'], true)
    : [];

// Available versions per loaf recipe, filtered to the selected dough major (only needed before locking)
$versionsByRecipe = [];
if ($selectedDtVersionNumber && !empty($loafBreakdown) && !$lockedLoafVersions) {
    $recipeIds = array_values(array_filter(array_unique(array_column($loafBreakdown, 'recipe_id'))));
    if ($recipeIds) {
        $vPh = implode(',', array_fill(0, count($recipeIds), '?'));
        $vParams = array_merge($recipeIds, [$selectedDtVersionNumber]);
        $vqStmt = $pdo->prepare("
            SELECT recipe_id, id as version_id, dough_type_version_number, loaf_minor_version
            FROM baker_recipe_versions
            WHERE recipe_id IN ($vPh) AND dough_type_version_number = ?
            ORDER BY loaf_minor_version DESC
        ");
        $vqStmt->execute($vParams);
        foreach ($vqStmt->fetchAll() as $row) {
            $versionsByRecipe[(int)$row['recipe_id']][] = $row;
        }
    }
}

// ── Loaf recipe version (baker_recipe_version) info ───────────────────────────
$recipeVersionInfo = null;
if ($existing && !empty($existing['recipe_version_id'])) {
    $rvStmt = $pdo->prepare("
        SELECT brv.version_number, br.name as recipe_name
        FROM baker_recipe_versions brv
        JOIN baker_recipes br ON br.id = brv.recipe_id
        WHERE brv.id = ?
    ");
    $rvStmt->execute([(int)$existing['recipe_version_id']]);
    $recipeVersionInfo = $rvStmt->fetch() ?: null;
}

$calc        = ($recipeData && $totalQty > 0 && $totalWeightG > 0)
    ? calculateIngredientsBA($recipeData, $totalQty, $totalWeightG, $ingredientNames)
    : null;

// ── Brand picker: load sub-products with stock per ingredient group ───────────
$savedBrands = $existing['notes_data']['ingredient_brands'] ?? [];
if ($calc) {
    $brandRows = $pdo->query(
        "SELECT i.id as group_id, i.name as group_name,
                c.id as child_id, c.brand_name, c.is_biologisch,
                COALESCE(SUM(b.quantity_remaining), 0) as stock_g,
                MIN(CASE WHEN b.quantity_remaining > 0 THEN b.thd_date ELSE NULL END) as earliest_thd_date
         FROM ingredients i
         JOIN ingredients c ON c.parent_id = i.id AND c.is_active = 1
         LEFT JOIN ingredient_batches b ON b.ingredient_id = c.id AND b.quantity_remaining > 0
         WHERE i.parent_id IS NULL AND i.is_active = 1
         GROUP BY c.id
         ORDER BY i.name, c.is_biologisch DESC, c.brand_name"
    )->fetchAll();
    $brandsByName = [];
    foreach ($brandRows as $brow) {
        $brandsByName[$brow['group_name']][] = [
            'id'                => (int)$brow['child_id'],
            'brand_name'        => $brow['brand_name'] ?: null,
            'is_biologisch'     => (bool)$brow['is_biologisch'],
            'stock_g'           => (float)$brow['stock_g'],
            'earliest_thd_date' => $brow['earliest_thd_date'],
        ];
    }
    $assignBrands = function(array &$items, string $nameKey) use ($brandsByName) {
        foreach ($items as &$item) {
            $pool = $brandsByName[$item[$nameKey]] ?? [];
            // Sort by FIFO: in-stock first, then by closest expiry date (nulls = far future)
            usort($pool, function($a, $b) {
                $aStock = $a['stock_g'] > 0;
                $bStock = $b['stock_g'] > 0;
                if ($aStock !== $bStock) return $bStock <=> $aStock;
                if (!$aStock) return 0;
                $aThd = $a['earliest_thd_date'] ?: '9999-12-31';
                $bThd = $b['earliest_thd_date'] ?: '9999-12-31';
                return strcmp($aThd, $bThd);
            });
            $item['brands'] = array_values($pool);
        }
        unset($item);
    };
    $assignBrands($calc['grains'],  'name');
    $assignBrands($calc['leveners'],'name');
    $assignBrands($calc['mixins'],  'name');
    $saltPool = $brandsByName['Zout'] ?? [];
    usort($saltPool, function($a, $b) {
        $aStock = $a['stock_g'] > 0; $bStock = $b['stock_g'] > 0;
        if ($aStock !== $bStock) return $bStock <=> $aStock;
        if (!$aStock) return 0;
        return strcmp($a['earliest_thd_date'] ?: '9999-12-31', $b['earliest_thd_date'] ?: '9999-12-31');
    });
    $calc['saltBrands'] = array_values($saltPool);
}

// ── Delivery date from method ─────────────────────────────────────────────────
// $date is the dagproductie/delivery date (last baking day); prep starts (nDays-1) before it
$bereidingDate = new DateTime($date);
$deliveryDt    = clone $bereidingDate;

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

// ── Focused day ───────────────────────────────────────────────────────────────
$focusDay = null;
if ($hasMethodDays && isset($_GET['day'])) {
    $fd = (int)$_GET['day'];
    if ($fd >= 0 && $fd < count($methodDays)) $focusDay = $fd;
}
$focusDayActions = ($focusDay !== null) ? (array)($methodDays[$focusDay]['actions'] ?? []) : [];
$focusHasPF     = in_array('pre-ferment', $focusDayActions);
$focusHasDeeg   = in_array('deeg',        $focusDayActions);
$focusHasVormen = in_array('vormen',      $focusDayActions);
$focusHasBakken = in_array('bakken',      $focusDayActions);

function buildBaUrl($existingId, $date, $doughType, $doughTypeId, $qty, $weight, $dayParam = null) {
    $base = $existingId
        ? "bak-actie.php?id=$existingId"
        : "bak-actie.php?date=".urlencode($date)."&dough_type=".urlencode($doughType)."&dough_type_id=$doughTypeId&qty=$qty&weight=$weight";
    return $dayParam !== null ? "$base&day=$dayParam" : $base;
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

    /* Per-day action sections */
    .day-action-section { margin-top: 0.75rem; border-top: 1px solid #f3f4f6; padding-top: 0.75rem; }
    .day-action-title { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.6rem; display: flex; align-items: center; gap: 0.35rem; }
    .day-action-title.pf   { color: #166534; }
    .day-action-title.deeg { color: #1e40af; }
    .day-action-title.bak  { color: #9f1239; }
    .day-temp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 0.6rem; }
    .day-temp-field label { display: block; font-size: 0.68rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.25rem; }
    .day-temp-input-outer { display: flex; align-items: stretch; }
    .day-temp-in { flex: 1; padding: 0.4rem 0.5rem; border: 1px solid #d1d5db; border-right: none; border-radius: 6px 0 0 6px; font-size: 0.95rem; font-weight: 600; color: #1f2937; width: 100%; font-family: inherit; }
    .day-temp-in:focus { outline: none; border-color: #1e40af; }
    .day-temp-unit { padding: 0.4rem 0.45rem; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 0 6px 6px 0; font-size: 0.75rem; color: #6b7280; font-weight: 600; display: flex; align-items: center; white-space: nowrap; }

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
    .btn-heropen { padding: 0.65rem 1.25rem; background: #fff; border: 1px solid #9ca3af; color: #374151; border-radius: 8px; font-size: 0.88rem; font-weight: 600; cursor: pointer; }
    .btn-heropen:hover { background: #f3f4f6; }
    .btn-secondary { padding: 0.65rem 1.25rem; background: #fff; border: 1px solid #d1d5db; color: #374151; border-radius: 8px; font-size: 0.88rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; }
    .btn-secondary:hover { background: #f9fafb; }
    .save-msg { font-size: 0.85rem; color: #059669; font-weight: 600; display: none; }
    .save-msg.error { color: #dc2626; }

    /* Stat pills */
    .stat-pills { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .stat-pill { background: #f3f4f6; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.82rem; color: #374151; }
    .stat-pill strong { color: #92400e; }

    /* Timing card */
    .timing-group { margin-bottom: 1rem; }
    .timing-group:last-child { margin-bottom: 0; }
    .timing-group-title { font-size: 0.68rem; font-weight: 700; color: #92400e; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
    .timing-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem; }
    .timing-row label { font-size: 0.78rem; color: #6b7280; width: 4rem; flex-shrink: 0; }
    .timing-dt { font-size: 0.82rem; padding: 0.3rem 0.5rem; border: 1px solid #e5e7eb; border-radius: 4px; color: #1f2937; background: #fff; flex: 1; min-width: 0; }
    .timing-dt:focus { outline: none; border-color: #92400e; box-shadow: 0 0 0 2px rgba(146,64,14,0.1); }
    .timing-now-btn { padding: 0.25rem 0.5rem; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 0.72rem; color: #374151; cursor: pointer; white-space: nowrap; flex-shrink: 0; }
    .timing-now-btn:hover { background: #e5e7eb; }
    .timing-duration { font-size: 0.8rem; color: #059669; font-weight: 600; margin-top: 0.15rem; margin-bottom: 0.5rem; min-height: 1rem; padding-left: 4.5rem; }
    .timing-duration.gap { color: #7c3aed; }

    /* Day nav tabs */
    .day-nav-bar { display: flex; gap: 0.4rem; padding: 0.6rem 2rem; background: #f9fafb; border-bottom: 1px solid #e5e7eb; flex-wrap: wrap; align-items: center; }
    .day-tab { display: inline-flex; flex-direction: column; align-items: center; padding: 0.35rem 0.75rem; border: 1px solid #e5e7eb; border-radius: 8px; text-decoration: none; font-size: 0.8rem; font-weight: 600; color: #6b7280; background: #fff; gap: 0.1rem; transition: all 0.15s; }
    .day-tab:hover { border-color: #92400e; color: #92400e; }
    .day-tab.active { background: #92400e; border-color: #92400e; color: #fff; }
    .day-tab .dt-sub { font-size: 0.65rem; font-weight: 400; opacity: 0.8; }
    .day-tab-overview { border-style: dashed; }
    .day-tab-overview.active { background: #374151; border-color: #374151; color: #fff; border-style: solid; }
</style>
<?php
$adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php';
?>

<div class="ba-topbar">
    <a href="bakker-dashboard.php?date=<?= htmlspecialchars($date) ?>" class="ba-back">
        <i class="bi bi-arrow-left"></i> Dashboard
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
        <?php else: ?>
        <button class="btn-heropen" onclick="heropenBakactie()">
            <i class="bi bi-arrow-counterclockwise"></i> Heropen
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($hasMethodDays):
    $_prepStartNav = clone $deliveryDt;
    $_prepStartNav->modify('-' . (count($methodDays) - 1) . ' days');
    $catIcons = ['deeg'=>'bi-layers','bakken'=>'bi-fire'];
?>
<div class="day-nav-bar">
    <?php foreach ($methodDays as $_di => $_day):
        $_dayDtNav = clone $_prepStartNav;
        $_dayDtNav->modify('+' . $_di . ' days');
        $_dayUrl   = buildBaUrl($existing ? $existing['id'] : null, $date, $doughType, $doughTypeId, $totalQty, $totalWeightG, $_di);
        $_acts     = (array)($_day['actions'] ?? []);
        $_label    = $_day['label'] ?? ('Dag ' . ($_di + 1));
        $_isActive = ($focusDay === $_di);
        $_isToday  = ($_dayDtNav->format('Y-m-d') === date('Y-m-d'));
    ?>
    <a href="<?= $_dayUrl ?>" class="day-tab <?= $_isActive ? 'active' : '' ?><?= $currentStatus === 'voltooid' && !$_isActive ? ' day-tab-done' : '' ?>">
        <span>
            <?= htmlspecialchars($_label) ?>
            <?php if ($currentStatus === 'voltooid'): ?>
                <i class="bi bi-check-circle-fill" style="font-size:0.65rem;margin-left:0.2rem;color:<?= $_isActive ? 'rgba(255,255,255,0.8)' : '#059669' ?>"></i>
            <?php else: ?>
                <?php foreach ($_acts as $_act): if (isset($catIcons[$_act])): ?><i class="bi <?= $catIcons[$_act] ?>" style="font-size:0.7rem;margin-left:0.2rem"></i><?php endif; endforeach; ?>
                <?php if ($_isToday): ?><span style="font-size:0.6rem;background:<?= $_isActive ? 'rgba(255,255,255,0.3)' : '#ff6b35' ?>;color:#fff;border-radius:3px;padding:0 0.25rem;margin-left:0.2rem">●</span><?php endif; ?>
            <?php endif; ?>
        </span>
        <span class="dt-sub"><?= getDutchDayNameBA($_dayDtNav) ?> <?= $_dayDtNav->format('j') ?></span>
    </a>
    <?php endforeach; ?>
    <a href="<?= buildBaUrl($existing ? $existing['id'] : null, $date, $doughType, $doughTypeId, $totalQty, $totalWeightG) ?>" class="day-tab day-tab-overview <?= $focusDay === null ? 'active' : '' ?>" style="margin-left:auto">
        <i class="bi bi-grid-3x3-gap"></i> Overzicht
    </a>
</div>
<?php endif; ?>

<div class="ba-wrap">
    <div class="ba-layout">

        <!-- ── Left column: Recept + Methode ── -->
        <div>

            <?php if ($currentStatus === 'voltooid' && $existing && $focusDay === null):
                $ovNd       = $existing['notes_data'] ?? [];
                $ovQuality  = (int)($ovNd['quality'] ?? 0);
                $ovDayTimes = $ovNd['day_times'] ?? [];
                $ovTemps    = array_filter([
                    'Bakkerij'  => $existing['bakkerij_temp'] ?? null,
                    'Meel'      => $existing['flour_temp']    ?? null,
                    'Omgeving'  => $existing['ambient_temp']  ?? null,
                    'Water'     => $existing['water_temp']    ?? null,
                    'Deeg (FDT)'=> $existing['dough_temp']    ?? null,
                    'Oven'      => $existing['oven_temp']     ?? null,
                ], fn($v) => $v !== null && $v !== '');
                $ovBakeTime = ($existing['bake_time_minutes'] ?? null) ?: null;
                $ovDev      = $ovNd['deviations']   ?? null;
                $ovObs      = $ovNd['observations'] ?? null;
                $ovGen      = $ovNd['general']      ?? null;
            ?>
            <div class="ba-card" style="border-top:3px solid #059669">
                <div class="ba-card-header">
                    <i class="bi bi-check-circle-fill" style="color:#059669"></i>
                    <h2>Overzicht — voltooid</h2>
                    <?php if ($ovQuality): ?>
                    <div style="margin-left:auto;display:flex;gap:0.2rem;align-items:center">
                        <?php for ($s=1;$s<=5;$s++): ?><span style="color:<?= $s<=$ovQuality?'#f59e0b':'#d1d5db' ?>;font-size:1.1rem">★</span><?php endfor; ?>
                        <span style="font-size:0.78rem;color:#6b7280;margin-left:0.3rem"><?= $ovQuality ?>/5</span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="ba-card-body">
                    <?php if ($ovTemps || $ovBakeTime): ?>
                    <div class="ing-section-title" style="margin-bottom:0.5rem">Temperaturen</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:0.5rem;margin-bottom:1rem">
                        <?php foreach ($ovTemps as $lbl => $val): ?>
                        <div style="background:#faf8f4;border-radius:6px;padding:0.4rem 0.65rem">
                            <div style="font-size:0.68rem;color:#9ca3af;font-weight:700;text-transform:uppercase;letter-spacing:0.04em"><?= $lbl ?></div>
                            <div style="font-size:1.05rem;font-weight:800;color:#92400e"><?= $val ?>°C</div>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($ovBakeTime): ?>
                        <div style="background:#faf8f4;border-radius:6px;padding:0.4rem 0.65rem">
                            <div style="font-size:0.68rem;color:#9ca3af;font-weight:700;text-transform:uppercase;letter-spacing:0.04em">Baktijd</div>
                            <div style="font-size:1.05rem;font-weight:800;color:#92400e"><?= $ovBakeTime ?>min</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($ovDayTimes && $methodDays): ?>
                    <div class="ing-section-title" style="margin-bottom:0.5rem">Tijden per dag</div>
                    <?php
                    $ovPrepStart = clone $deliveryDt;
                    $ovPrepStart->modify('-' . (count($methodDays) - 1) . ' days');
                    foreach ($methodDays as $odi => $oday):
                        $oDayDt  = clone $ovPrepStart; $oDayDt->modify('+' . $odi . ' days');
                        $oTimes  = $ovDayTimes[(string)$odi] ?? [];
                        $oStart  = $oTimes['start'] ?? null;
                        $oEnd    = $oTimes['end']   ?? null;
                        $oLabel  = $oday['label']   ?? ('Dag ' . ($odi + 1));
                        $oDurStr = '';
                        if ($oStart && $oEnd) {
                            list($sh,$sm) = explode(':', $oStart); list($eh,$em) = explode(':', $oEnd);
                            $mins = ($eh*60+$em) - ($sh*60+$sm);
                            if ($mins > 0) $oDurStr = floor($mins/60) . 'u' . ($mins%60 ? ' ' . ($mins%60) . 'min' : '');
                        }
                        $oNote = $ovNd['step_notes'][(string)$odi] ?? null;
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:baseline;padding:0.45rem 0;border-bottom:1px solid #fafafa;flex-wrap:wrap;gap:0.3rem">
                        <div>
                            <span style="font-weight:600;color:#374151"><?= htmlspecialchars($oLabel) ?></span>
                            <span style="font-size:0.78rem;color:#9ca3af;margin-left:0.4rem"><?= getDutchDayNameBA($oDayDt) ?> <?= $oDayDt->format('j') ?></span>
                            <?php if ($oNote): ?><div style="font-size:0.78rem;color:#6b7280;font-style:italic;margin-top:0.1rem"><?= htmlspecialchars($oNote) ?></div><?php endif; ?>
                        </div>
                        <div style="font-size:0.9rem;text-align:right">
                            <?php if ($oStart || $oEnd): ?>
                            <span style="color:#374151"><?= $oStart ?: '—' ?><?= $oEnd ? ' – ' . $oEnd : '' ?></span>
                            <?php if ($oDurStr): ?><span style="color:#059669;font-weight:700;margin-left:0.35rem">(<?= $oDurStr ?>)</span><?php endif; ?>
                            <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div style="margin-bottom:1rem"></div>
                    <?php endif; ?>

                    <?php if ($ovDev || $ovObs || $ovGen): ?>
                    <div class="ing-section-title" style="margin-bottom:0.5rem">Notities</div>
                    <?php if ($ovDev): ?><div style="margin-bottom:0.6rem"><span style="font-size:0.68rem;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:0.04em">Afwijkingen</span><div style="font-size:0.88rem;color:#374151;margin-top:0.15rem"><?= nl2br(htmlspecialchars($ovDev)) ?></div></div><?php endif; ?>
                    <?php if ($ovObs): ?><div style="margin-bottom:0.6rem"><span style="font-size:0.68rem;font-weight:700;color:#3d6b3d;text-transform:uppercase;letter-spacing:0.04em">Observaties</span><div style="font-size:0.88rem;color:#374151;margin-top:0.15rem"><?= nl2br(htmlspecialchars($ovObs)) ?></div></div><?php endif; ?>
                    <?php if ($ovGen): ?><div><span style="font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em">Notities</span><div style="font-size:0.88rem;color:#374151;margin-top:0.15rem"><?= nl2br(htmlspecialchars($ovGen)) ?></div></div><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($doughType): ?>
            <div class="ba-card">
                <div class="ba-card-header">
                    <i class="bi bi-layers" style="color:#92400e"></i>
                    <h2>Recept — <?= htmlspecialchars($doughType) ?></h2>
                    <?php if ($doughTypeRow): ?>
                    <div style="margin-left:auto;display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap">
                        <?php if ($recipeVersionInfo): ?>
                        <span style="font-size:0.72rem;color:#6b7280;border:1px solid #e5e7eb;padding:0.1rem 0.4rem" title="Broodrecept versie: <?= htmlspecialchars($recipeVersionInfo['recipe_name']) ?>">
                            <?= htmlspecialchars(mb_strimwidth($recipeVersionInfo['recipe_name'], 0, 20, '…')) ?> v<?= $recipeVersionInfo['version_number'] ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($existing && count($availableVersions) > 1): ?>
                        <select id="versionSwitcher" onchange="switchVersion(this.value)" style="font-size:0.75rem;color:#6b7280;border:1px solid #e5e7eb;padding:0.15rem 0.4rem;background:#fff;cursor:pointer" title="Deegrecept versie">
                            <option value="">— deegversie —</option>
                            <?php foreach ($availableVersions as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= $v['id'] == $lockedVersionId ? 'selected' : '' ?>>
                                v<?= $v['version_number'] ?><?= $v['note'] ? ' · ' . htmlspecialchars(mb_strimwidth($v['note'], 0, 30, '…')) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <span style="font-size:0.75rem;color:#6b7280;padding:0.15rem 0.5rem;border:1px solid #e5e7eb" title="Deegrecept versie">v<?= $doughTypeRow['current_version'] ?></span>
                        <?php endif; ?>
                        <a href="recepten.php#dt-<?= $doughTypeId ?>/versies" style="font-size:0.75rem;color:#6b7280;text-decoration:none;padding:0.15rem 0.4rem;border:1px solid #e5e7eb"><i class="bi bi-box-arrow-up-right" style="font-size:0.65rem"></i></a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="ba-card-body">
                    <?php if (!$recipeData): ?>
                    <div style="color:#9ca3af;font-size:0.88rem;padding:0.25rem 0;display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap">
                        <span><i class="bi bi-exclamation-circle"></i> Geen receptdata beschikbaar.</span>
                        <?php if ($existing && $doughTypeId): ?>
                        <button onclick="loadRecipeNow()" style="padding:0.3rem 0.75rem;background:#92400e;color:#fff;border:none;border-radius:4px;font-size:0.8rem;font-weight:600;cursor:pointer"><i class="bi bi-arrow-clockwise"></i> Laad recept</button>
                        <?php else: ?>
                        <a href="recepten.php" style="color:#92400e">Stel recept in via Recepten</a>
                        <?php endif; ?>
                    </div>
                    <?php elseif (!$calc): ?>
                    <p style="color:#9ca3af;font-size:0.88rem;padding:0.25rem 0"><i class="bi bi-info-circle"></i> Geen bestellingen gevonden — aantallen worden dynamisch berekend zodra orders zijn geplaatst.</p>
                    <?php else: ?>
                    <div class="stat-pills">
                        <span class="stat-pill"><strong><?= $totalQty ?></strong> broden</span>
                        <span class="stat-pill"><strong><?= number_format($totalWeightG/1000, 1, ',', '.') ?> kg</strong> deeg</span>
                        <?php if ($focusDay === null || $focusHasDeeg): ?>
                        <span class="stat-pill"><strong><?= $calc['hydration'] ?>%</strong> hydratatie</span>
                        <span class="stat-pill">Zout <strong><?= $calc['saltPct'] ?>%</strong></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($focusDay === null || $focusHasDeeg): ?>
                    <!-- ── FULL VIEW (overview or deeg day) ── -->

                    <!-- Sourdough -->
                    <?php if ($calc['sourdough']): $sd = $calc['sourdough']; ?>
                    <div class="ing-section">
                        <div class="ing-section-title"><i class="bi bi-fire"></i> Zuurdesem (<?= $sd['pct'] ?>% v/h meel, <?= $sd['hydration'] ?>% hydratatie)</div>
                        <div class="ing-row"><span class="ing-name">Meel in zuurdesem</span><span class="ing-weight"><?= $sd['flour'] ?>g</span></div>
                        <div class="ing-row"><span class="ing-name">Water in zuurdesem</span><span class="ing-weight"><?= $sd['water'] ?>g</span></div>
                        <div class="ing-total"><span class="label">Zuurdesem totaal</span><span class="val"><?= $sd['weight'] ?>g</span></div>
                        <?php if (!empty($calc['grains'][0]['brands'])): ?>
                        <div class="ing-brand-row" style="margin-top:0.35rem">
                            <select class="ing-brand-select" data-ing="sourdough">
                                <option value="">— Meel voor zuurdesem (FIFO) —</option>
                                <?php foreach ($calc['grains'][0]['brands'] as $br): ?>
                                <option value="<?= $br['id'] ?>" <?= ($savedBrands['sourdough'] ?? null) == $br['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($br['brand_name'] ?: '') ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,2,',','.') ?>kg
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if ($existing): ?>
                        <div style="margin-top:0.75rem">
                            <?php if ($sdConsumed): ?>
                            <button onclick="openSdMovementsModal()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.9rem;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;font-size:0.82rem;font-weight:700;cursor:pointer;">
                                <i class="bi bi-check-circle-fill"></i> Klaar — desem afgeschreven
                            </button>
                            <?php else: ?>
                            <button onclick="openSdInvForConsumption()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.9rem;background:#7c3aed;color:#fff;border:none;font-size:0.82rem;font-weight:700;cursor:pointer;">
                                <i class="bi bi-fire"></i> Gedaan — registreer verbruik
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Pre-ferment -->
                    <?php if ($calc['preFerment']): $pf = $calc['preFerment']; ?>
                    <div class="ing-section">
                        <div class="ing-section-title">Voordeeg (<?= $pf['pct'] ?>%)</div>
                        <div class="ing-row"><span class="ing-name">Meel in voordeeg</span><span class="ing-weight"><?= $pf['flour'] ?>g</span></div>
                        <div class="ing-row"><span class="ing-name">Water in voordeeg</span><span class="ing-weight"><?= $pf['water'] ?>g</span></div>
                        <div class="ing-total"><span class="label">Voordeeg totaal</span><span class="val"><?= $pf['weight'] ?>g</span></div>
                    </div>
                    <?php endif; ?>

                    <!-- Main flour -->
                    <div class="ing-section">
                        <div class="ing-section-title">Hoofddeeg — Meel</div>
                        <?php foreach ($calc['grains'] as $g): ?>
                        <div class="ing-row">
                            <span class="ing-name"><?= htmlspecialchars($g['name']) ?></span>
                            <span><span class="ing-weight"><?= $g['weight'] ?>g</span><span class="ing-pct">(<?= $g['pct'] ?>%)</span></span>
                        </div>
                        <?php if (!empty($g['brands'])):
                            $_fd = null; foreach ($g['brands'] as $_b) { if ($_b['stock_g'] > 0) { $_fd = $_b; break; } }
                        ?>
                        <div class="ing-brand-row">
                            <select class="ing-brand-select" data-ing="<?= htmlspecialchars($g['name']) ?>">
                                <option value=""><?= $_fd ? '— Automatisch (FIFO) —' : '— Geen voorraad —' ?></option>
                                <?php foreach ($g['brands'] as $br): ?>
                                <option value="<?= $br['id'] ?>"
                                    <?= ($savedBrands[$g['name']] ?? null) == $br['id'] ? 'selected' : '' ?>
                                    <?= $br['stock_g'] <= 0 ? 'disabled' : '' ?>>
                                    <?= ($_fd && $br['id'] == $_fd['id']) ? '↓ ' : '' ?><?= htmlspecialchars($br['brand_name'] ?: $g['name']) ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,1,',','.') ?>kg<?= ($br['stock_g'] > 0 && $br['stock_g'] < $g['weight']) ? ' ⚠ tekort' : '' ?><?= $br['stock_g'] <= 0 ? ' (geen voorraad)' : '' ?>
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
                        <div class="ing-section-title">Hoofddeeg — Water & Zout</div>
                        <div class="ing-row"><span class="ing-name">Water</span><span class="ing-weight"><?= $calc['mainWater'] ?>g</span></div>
                        <div class="ing-row"><span class="ing-name">Zout</span><span><span class="ing-weight"><?= $calc['saltWeight'] ?>g</span><span class="ing-pct">(<?= $calc['saltPct'] ?>%)</span></span></div>
                        <?php if (!empty($calc['saltBrands'])):
                            $_fd = null; foreach ($calc['saltBrands'] as $_b) { if ($_b['stock_g'] > 0) { $_fd = $_b; break; } }
                        ?>
                        <div class="ing-brand-row">
                            <select class="ing-brand-select" data-ing="Zout">
                                <option value=""><?= $_fd ? '— Automatisch (FIFO) —' : '— Geen voorraad —' ?></option>
                                <?php foreach ($calc['saltBrands'] as $br): ?>
                                <option value="<?= $br['id'] ?>"
                                    <?= ($savedBrands['Zout'] ?? null) == $br['id'] ? 'selected' : '' ?>
                                    <?= $br['stock_g'] <= 0 ? 'disabled' : '' ?>>
                                    <?= ($_fd && $br['id'] == $_fd['id']) ? '↓ ' : '' ?><?= htmlspecialchars($br['brand_name'] ?: 'Zout') ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,1,',','.') ?>kg<?= ($br['stock_g'] > 0 && $br['stock_g'] < $calc['saltWeight']) ? ' ⚠ tekort' : '' ?><?= $br['stock_g'] <= 0 ? ' (geen voorraad)' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Leveners -->
                    <?php if (!empty($calc['leveners'])): ?>
                    <div class="ing-section">
                        <div class="ing-section-title">Rijsmiddel</div>
                        <?php foreach ($calc['leveners'] as $l): ?>
                        <div class="ing-row"><span class="ing-name"><?= htmlspecialchars($l['name']) ?></span><span><span class="ing-weight"><?= $l['weight'] ?>g</span><span class="ing-pct">(<?= $l['pct'] ?>%)</span></span></div>
                        <?php if (!empty($l['brands'])):
                            $_fd = null; foreach ($l['brands'] as $_b) { if ($_b['stock_g'] > 0) { $_fd = $_b; break; } }
                        ?>
                        <div class="ing-brand-row">
                            <select class="ing-brand-select" data-ing="<?= htmlspecialchars($l['name']) ?>">
                                <option value=""><?= $_fd ? '— Automatisch (FIFO) —' : '— Geen voorraad —' ?></option>
                                <?php foreach ($l['brands'] as $br): ?>
                                <option value="<?= $br['id'] ?>"
                                    <?= ($savedBrands[$l['name']] ?? null) == $br['id'] ? 'selected' : '' ?>
                                    <?= $br['stock_g'] <= 0 ? 'disabled' : '' ?>>
                                    <?= ($_fd && $br['id'] == $_fd['id']) ? '↓ ' : '' ?><?= htmlspecialchars($br['brand_name'] ?: $l['name']) ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,1,',','.') ?>kg<?= ($br['stock_g'] > 0 && $br['stock_g'] < $l['weight']) ? ' ⚠ tekort' : '' ?><?= $br['stock_g'] <= 0 ? ' (geen voorraad)' : '' ?>
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
                        <?php if (!empty($m['brands'])):
                            $_fd = null; foreach ($m['brands'] as $_b) { if ($_b['stock_g'] > 0) { $_fd = $_b; break; } }
                        ?>
                        <div class="ing-brand-row">
                            <select class="ing-brand-select" data-ing="<?= htmlspecialchars($m['name']) ?>">
                                <option value=""><?= $_fd ? '— Automatisch (FIFO) —' : '— Geen voorraad —' ?></option>
                                <?php foreach ($m['brands'] as $br): ?>
                                <option value="<?= $br['id'] ?>"
                                    <?= ($savedBrands[$m['name']] ?? null) == $br['id'] ? 'selected' : '' ?>
                                    <?= $br['stock_g'] <= 0 ? 'disabled' : '' ?>>
                                    <?= ($_fd && $br['id'] == $_fd['id']) ? '↓ ' : '' ?><?= htmlspecialchars($br['brand_name'] ?: $m['name']) ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,1,',','.') ?>kg<?= ($br['stock_g'] > 0 && $br['stock_g'] < $m['weight']) ? ' ⚠ tekort' : '' ?><?= $br['stock_g'] <= 0 ? ' (geen voorraad)' : '' ?>
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
                    <?php if ($existing): ?>
                    <div style="margin-top:0.75rem">
                        <?php if (!empty($existing['inventory_consumed'])): ?>
                        <button onclick="openMovementsModal()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.9rem;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;font-size:0.82rem;font-weight:700;cursor:pointer;">
                            <i class="bi bi-check-circle-fill"></i> Klaar — hoofddeeg afgeschreven
                        </button>
                        <?php else: ?>
                        <button onclick="openInvForConsumption()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.9rem;background:#92400e;color:#fff;border:none;font-size:0.82rem;font-weight:700;cursor:pointer;">
                            <i class="bi bi-box-seam"></i> Gedaan — registreer verbruik
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php elseif ($focusHasPF): ?>
                    <!-- ── PRE-FERMENT FOCUSED VIEW ── -->
                    <?php if ($calc['sourdough']): $sd = $calc['sourdough']; ?>
                    <div class="ing-section">
                        <div class="ing-section-title"><i class="bi bi-fire"></i> Zuurdesem (<?= $sd['pct'] ?>%, <?= $sd['hydration'] ?>% hydratatie)</div>
                        <div class="ing-row"><span class="ing-name">Meel in zuurdesem</span><span class="ing-weight"><?= $sd['flour'] ?>g</span></div>
                        <div class="ing-row"><span class="ing-name">Water in zuurdesem</span><span class="ing-weight"><?= $sd['water'] ?>g</span></div>
                        <div class="ing-total"><span class="label">Zuurdesem totaal</span><span class="val"><?= $sd['weight'] ?>g</span></div>
                        <?php if (!empty($calc['grains'][0]['brands'])): ?>
                        <div class="ing-brand-row" style="margin-top:0.35rem">
                            <select class="ing-brand-select" data-ing="sourdough">
                                <option value="">— Meel voor zuurdesem (FIFO) —</option>
                                <?php foreach ($calc['grains'][0]['brands'] as $br): ?>
                                <option value="<?= $br['id'] ?>" <?= ($savedBrands['sourdough'] ?? null) == $br['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($br['brand_name'] ?: '') ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,2,',','.') ?>kg
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top:0.75rem">
                            <?php if ($sdConsumed): ?>
                            <button onclick="openSdMovementsModal()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.9rem;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;font-size:0.82rem;font-weight:700;cursor:pointer;">
                                <i class="bi bi-check-circle-fill"></i> Klaar — desem afgeschreven
                            </button>
                            <?php else: ?>
                            <button onclick="openSdInvForConsumption()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.9rem;background:#7c3aed;color:#fff;border:none;font-size:0.82rem;font-weight:700;cursor:pointer;">
                                <i class="bi bi-fire"></i> Gedaan — registreer verbruik
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($calc['preFerment']): $pf = $calc['preFerment']; ?>
                    <div class="ing-section">
                        <div class="ing-section-title">Voordeeg (<?= $pf['pct'] ?>%, <?= $pf['hydration'] ?>% hydratatie)</div>
                        <div class="ing-row"><span class="ing-name">Meel in voordeeg</span><span class="ing-weight"><?= $pf['flour'] ?>g</span></div>
                        <div class="ing-row"><span class="ing-name">Water in voordeeg</span><span class="ing-weight"><?= $pf['water'] ?>g</span></div>
                        <div class="ing-total"><span class="label">Voordeeg totaal</span><span class="val"><?= $pf['weight'] ?>g</span></div>
                        <?php if (!empty($calc['grains'][0]['brands'])): ?>
                        <div class="ing-brand-row" style="margin-top:0.35rem">
                            <select class="ing-brand-select" data-ing="preferment">
                                <option value="">— Meel voor voordeeg (FIFO) —</option>
                                <?php foreach ($calc['grains'][0]['brands'] as $br): ?>
                                <option value="<?= $br['id'] ?>" <?= ($savedBrands['preferment'] ?? null) == $br['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($br['brand_name'] ?: '') ?><?= $br['is_biologisch'] ? ' (BIO)' : '' ?> — <?= number_format($br['stock_g']/1000,2,',','.') ?>kg
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!$calc['sourdough'] && !$calc['preFerment']): ?>
                    <p style="color:#9ca3af;font-size:0.85rem;padding:0.5rem 0">Geen zuurdesem of voordeeg in dit recept.</p>
                    <?php endif; ?>

                    <?php else: ?>
                    <!-- ── VORMEN / BAKKEN FOCUSED VIEW ── -->
                    <div class="ing-total" style="font-size:0.95rem">
                        <span class="label">Totaal deeggewicht</span>
                        <span class="val"><?= number_format($calc['totalDoughWeight']/1000,3,',','.') ?> kg</span>
                    </div>
                    <?php if ($focusHasBakken && !empty($calc['grains'])): ?>
                    <div class="ing-section" style="margin-top:0.75rem">
                        <div class="ing-section-title">Meel</div>
                        <?php foreach ($calc['grains'] as $g): ?>
                        <div class="ing-row"><span class="ing-name"><?= htmlspecialchars($g['name']) ?></span><span class="ing-weight"><?= $g['weight'] ?>g</span></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php endif; // $calc ?>
                </div>
            </div>
            <?php endif; // $recipeData && $doughTypeRow ?>

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
                    <?php if ($focusDay !== null): ?>
                    <h2><?= htmlspecialchars($methodDays[$focusDay]['label'] ?? 'Dag ' . ($focusDay + 1)) ?></h2>
                    <?php else: ?>
                    <h2>Methode — <?= count($methodDays) ?> dagen</h2>
                    <?php endif; ?>
                    <span style="margin-left:auto;font-size:0.8rem;color:#6b7280">Levering <?= formatDutchDateBA($deliveryDt) ?></span>
                </div>
                <div class="ba-card-body">
                    <?php foreach ($methodDays as $di => $day):
                        if ($focusDay !== null && $di !== $focusDay) continue;
                        $dayDt    = clone $prepStart;
                        $dayDt->modify('+' . $di . ' days');
                        $isToday  = ($dayDt->format('Y-m-d') === $today->format('Y-m-d'));
                        $daysDiff = (int)$today->diff($dayDt)->format('%r%a');
                        $isPast   = ($daysDiff < 0);
                        $dayLabel = $day['label'] ?? ('Dag ' . ($di + 1));
                        $headerClass = $isToday ? 'today' : ($isPast ? 'past' : '');
                        $noteKey    = (string)$di;
                        $dayStart   = $dayTimes[$noteKey]['start'] ?? '';
                        $dayEnd     = $dayTimes[$noteKey]['end']   ?? '';
                        $dayActions = isset($day['actions']) ? (array)$day['actions'] : [];
                        $hasDeeg    = in_array('deeg',        $dayActions);
                        $hasBakken  = in_array('bakken',      $dayActions);
                        $hasPF      = in_array('pre-ferment', $dayActions);
                        // Derive per-day status
                        if ($dayEnd)        $dayStatus = 'afgerond';
                        elseif ($dayStart)  $dayStatus = 'bezig';
                        else                $dayStatus = 'gepland';
                        // When the whole bakactie is voltooid, treat all days as done
                        if ($currentStatus === 'voltooid' && $dayStatus === 'gepland') $dayStatus = 'afgerond';
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

                            <?php if ($hasPF): ?>
                            <div class="day-action-section">
                                <div class="day-action-title pf">Pre-ferment / Zuurdesem</div>
                                <div class="day-temp-grid">
                                    <div class="day-temp-field">
                                        <label>Temp. starter</label>
                                        <div class="day-temp-input-outer">
                                            <input type="number" class="day-temp-in" id="pf_temp_<?= $di ?>" data-day-idx="<?= $di ?>" data-day-field="pf_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($dayTimes[$noteKey]['pf_temp'] ?? '') ?>">
                                            <span class="day-temp-unit">°C</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($hasDeeg): ?>
                            <div class="day-action-section">
                                <div class="day-action-title deeg"><i class="bi bi-layers"></i> Deeg temperaturen</div>
                                <div class="day-temp-grid">
                                    <div class="day-temp-field">
                                        <label>Meeltemp</label>
                                        <div class="day-temp-input-outer">
                                            <input type="number" class="day-temp-in" id="flour_temp_<?= $di ?>" data-day-idx="<?= $di ?>" data-day-field="flour_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($dayTimes[$noteKey]['flour_temp'] ?? '') ?>" oninput="calcDayDDT(<?= $di ?>)">
                                            <span class="day-temp-unit">°C</span>
                                        </div>
                                    </div>
                                    <div class="day-temp-field">
                                        <label>Omgevingstemp</label>
                                        <div class="day-temp-input-outer">
                                            <input type="number" class="day-temp-in" id="ambient_temp_<?= $di ?>" data-day-idx="<?= $di ?>" data-day-field="ambient_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($dayTimes[$noteKey]['ambient_temp'] ?? '') ?>" oninput="calcDayDDT(<?= $di ?>)">
                                            <span class="day-temp-unit">°C</span>
                                        </div>
                                    </div>
                                    <div class="day-temp-field">
                                        <label>Watertemp</label>
                                        <div class="day-temp-input-outer">
                                            <input type="number" class="day-temp-in" id="water_temp_<?= $di ?>" data-day-idx="<?= $di ?>" data-day-field="water_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($dayTimes[$noteKey]['water_temp'] ?? '') ?>">
                                            <span class="day-temp-unit">°C</span>
                                        </div>
                                    </div>
                                    <div class="day-temp-field">
                                        <label>Deegtemp (FDT)</label>
                                        <div class="day-temp-input-outer">
                                            <input type="number" class="day-temp-in" id="dough_temp_<?= $di ?>" data-day-idx="<?= $di ?>" data-day-field="dough_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($dayTimes[$noteKey]['dough_temp'] ?? '') ?>">
                                            <span class="day-temp-unit">°C</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- DDT calculator -->
                                <div class="ddt-calc" style="margin-top:0.75rem">
                                    <div class="ddt-calc-title"><i class="bi bi-calculator"></i> Watertemperatuur berekenen</div>
                                    <div class="ddt-row">
                                        <div class="ddt-field">
                                            <label>Gewenste deegtemp (DDT)</label>
                                            <div class="ddt-input-wrap">
                                                <input type="number" class="ddt-input" id="ddt_dough_<?= $di ?>" value="24" min="0" max="40" step="0.5" oninput="calcDayDDT(<?= $di ?>)">
                                                <span class="ddt-unit">°C</span>
                                            </div>
                                        </div>
                                        <div class="ddt-field">
                                            <label>Voordeeg/levain <span style="color:#d1d5db">(opt.)</span></label>
                                            <div class="ddt-input-wrap">
                                                <input type="number" class="ddt-input" id="ddt_pref_<?= $di ?>" placeholder="—" min="-10" max="40" step="0.5" oninput="calcDayDDT(<?= $di ?>)">
                                                <span class="ddt-unit">°C</span>
                                            </div>
                                        </div>
                                        <div class="ddt-field">
                                            <label>Wrijving kneder <span style="color:#d1d5db">(opt.)</span></label>
                                            <div class="ddt-input-wrap">
                                                <input type="number" class="ddt-input" id="ddt_fric_<?= $di ?>" value="8" min="0" max="30" step="1" oninput="calcDayDDT(<?= $di ?>)">
                                                <span class="ddt-unit">°C</span>
                                            </div>
                                        </div>
                                        <div>
                                            <div style="font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.2rem">Watertemp</div>
                                            <div id="ddt_result_<?= $di ?>" class="ddt-result ddt-empty">—</div>
                                        </div>
                                    </div>
                                    <div id="ddt_formula_<?= $di ?>" class="ddt-formula"></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($hasBakken): ?>
                            <div class="day-action-section">
                                <div class="day-action-title bak"><i class="bi bi-fire"></i> Bakken</div>
                                <div class="day-temp-grid">
                                    <div class="day-temp-field">
                                        <label>Oventemp</label>
                                        <div class="day-temp-input-outer">
                                            <input type="number" class="day-temp-in" id="oven_temp_<?= $di ?>" data-day-idx="<?= $di ?>" data-day-field="oven_temp" step="1" placeholder="—" value="<?= htmlspecialchars($dayTimes[$noteKey]['oven_temp'] ?? '') ?>">
                                            <span class="day-temp-unit">°C</span>
                                        </div>
                                    </div>
                                    <div class="day-temp-field">
                                        <label>Baktijd</label>
                                        <div class="day-temp-input-outer">
                                            <input type="number" class="day-temp-in" id="bake_time_<?= $di ?>" data-day-idx="<?= $di ?>" data-day-field="bake_time" step="1" min="0" placeholder="—" value="<?= htmlspecialchars($dayTimes[$noteKey]['bake_time'] ?? '') ?>">
                                            <span class="day-temp-unit">min</span>
                                        </div>
                                    </div>
                                    <div class="day-temp-field">
                                        <label>Deegtemp (FDT)</label>
                                        <div class="day-temp-input-outer">
                                            <input type="number" class="day-temp-in" id="fdt_temp_<?= $di ?>" data-day-idx="<?= $di ?>" data-day-field="fdt_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($dayTimes[$noteKey]['fdt_temp'] ?? '') ?>">
                                            <span class="day-temp-unit">°C</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

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

            <?php if ($loafBreakdown): ?>
            <!-- Broodtypes breakdown -->
            <div class="ba-card">
                <div class="ba-card-header">
                    <i class="bi bi-grid-3x3-gap" style="color:#92400e"></i>
                    <h2>Broodtypes</h2>
                    <span style="margin-left:auto;font-size:0.78rem;color:#6b7280"><?= $totalQty ?> stuks · <?= number_format($totalWeightG/1000,1,',','.') ?>kg</span>
                </div>
                <div class="ba-card-body" style="padding-top:0.25rem">
                    <?php foreach ($loafBreakdown as $lb): ?>
                    <?php
                    $rid = (string)$lb['recipe_id'];
                    $hasVersions = !empty($versionsByRecipe[(int)$lb['recipe_id']]);
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.45rem 0;border-bottom:1px solid #fafafa;gap:0.5rem">
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:600;color:#1f2937;font-size:0.88rem;margin-bottom:0.2rem"><?= htmlspecialchars($lb['recipe_name'] ?: 'Onbekend') ?></div>
                            <?php if ($lockedLoafVersions && isset($lockedLoafVersions[$rid])): ?>
                                <?php
                                $lv = $lockedLoafVersions[$rid];
                                $lstr = ($lv['dough_type_version_number'] !== null && $lv['loaf_minor_version'] !== null)
                                    ? 'v' . $lv['dough_type_version_number'] . '.' . $lv['loaf_minor_version']
                                    : 'v' . $lv['version_number'];
                                ?>
                                <span style="font-size:0.72rem;color:#92400e;border:1px solid #d4a373;padding:0.05rem 0.3rem;border-radius:3px" title="Versie op moment van afschrijven"><?= $lstr ?></span>
                            <?php elseif ($existing && $hasVersions): ?>
                                <?php
                                // Determine currently selected version for this recipe
                                $selVid = isset($plannedLoafVersions[$rid]) ? (int)$plannedLoafVersions[$rid]['version_id'] : null;
                                // Default to highest minor (first row, ordered DESC) if nothing planned
                                if (!$selVid) $selVid = (int)$versionsByRecipe[(int)$lb['recipe_id']][0]['version_id'];
                                ?>
                                <select onchange="savePlannedLoafVersion(<?= (int)$existing['id'] ?>, '<?= $rid ?>', this)"
                                        style="font-size:0.75rem;border:1px solid #d1d5db;border-radius:4px;padding:0.1rem 0.25rem;color:#374151;background:#fff;cursor:pointer">
                                    <?php foreach ($versionsByRecipe[(int)$lb['recipe_id']] as $vopt): ?>
                                    <option value="<?= (int)$vopt['version_id'] ?>:<?= (int)$vopt['dough_type_version_number'] ?>:<?= (int)$vopt['loaf_minor_version'] ?>"
                                        <?= ((int)$vopt['version_id'] === $selVid ? 'selected' : '') ?>>
                                        v<?= (int)$vopt['dough_type_version_number'] ?>.<?= (int)$vopt['loaf_minor_version'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($lb['current_version']): ?>
                                <?php
                                $dispVer = ($lb['dough_type_version_number'] !== null && $lb['loaf_minor_version'] !== null)
                                    ? 'v' . $lb['dough_type_version_number'] . '.' . $lb['loaf_minor_version']
                                    : 'v' . $lb['current_version'];
                                $isMismatch = $selectedDtVersionNumber && $lb['dough_type_version_number'] !== null
                                    && (int)$lb['dough_type_version_number'] !== $selectedDtVersionNumber;
                                $badgeStyle = $isMismatch ? 'color:#dc2626;border-color:#fca5a5;background:#fff1f2' : 'color:#6b7280;border-color:#e5e7eb';
                                $badgeTitle = $isMismatch
                                    ? 'Deegsoort v' . $lb['dough_type_version_number'] . ' — bak-actie gebruikt v' . $selectedDtVersionNumber
                                    : 'Huidige versie (geen v' . $selectedDtVersionNumber . ' beschikbaar)';
                                ?>
                                <span style="font-size:0.72rem;<?= $badgeStyle ?>;border:1px solid;padding:0.05rem 0.3rem;border-radius:3px" title="<?= htmlspecialchars($badgeTitle) ?>"><?= htmlspecialchars($dispVer) ?><?= $isMismatch ? ' ⚠' : '' ?></span>
                            <?php endif; ?>
                        </div>
                        <span style="font-weight:700;color:#92400e;font-size:0.9rem;white-space:nowrap"><?= (int)$lb['quantity'] ?>×</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

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

            <!-- Temperaturen (only shown when no per-day method) -->
            <?php if (!$hasMethodDays): ?>
            <div class="ba-card">
                <div class="ba-card-header">
                    <h2>Temperaturen</h2>
                    <span id="bt-info" style="margin-left:auto;font-size:0.75rem;color:#6b7280"></span>
                </div>
                <div class="ba-card-body">
                    <div class="temp-grid">
                        <div class="temp-field">
                            <label>Bakkerijtemperatuur</label>
                            <div class="temp-input-wrap">
                                <input type="number" class="temp-input" id="bakkerij_temp" step="0.1" placeholder="—" value="<?= htmlspecialchars($existing['bakkerij_temp'] ?? $latestBakkerijTemp ?? '') ?>" oninput="onBakkerijTempInput()">
                                <span class="temp-unit">°C</span>
                            </div>
                        </div>
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
            <?php endif; ?>

            <!-- Rijstijden -->
            <?php
                $showGlobalNotes = ($focusDay === null || $focusHasBakken || ($focusDay === count($methodDays ?? []) - 1));
                function fmtDt($val) {
                    // MySQL datetime → datetime-local value (YYYY-MM-DDTHH:MM)
                    if (!$val) return '';
                    return substr(str_replace(' ', 'T', $val), 0, 16);
                }
            ?>
            <?php if ($showGlobalNotes): ?>
            <div class="ba-card">
                <div class="ba-card-header">
                    <i class="bi bi-hourglass-split" style="color:#92400e"></i>
                    <h2>Rijstijden</h2>
                </div>
                <div class="ba-card-body">

                    <!-- Desem gevoed → deeg gemaakt -->
                    <div class="timing-group">
                        <div class="timing-group-title">Desem</div>
                        <div class="timing-row">
                            <label>Gevoed</label>
                            <input type="datetime-local" class="timing-dt" id="sourdough_fed_at"
                                   value="<?= fmtDt($existing['sourdough_fed_at'] ?? '') ?>"
                                   onchange="updateTimingDurations()">
                            <button class="timing-now-btn" onclick="setTimingNow('sourdough_fed_at')">Nu</button>
                        </div>
                        <div class="timing-row">
                            <label>Deeg gemaakt</label>
                            <input type="datetime-local" class="timing-dt" id="dough_mixed_at"
                                   value="<?= fmtDt($existing['dough_mixed_at'] ?? '') ?>"
                                   onchange="updateTimingDurations()">
                            <button class="timing-now-btn" onclick="setTimingNow('dough_mixed_at')">Nu</button>
                        </div>
                        <div id="dur-feed-to-mix" class="timing-duration gap"></div>
                    </div>

                    <!-- Bulk rijs -->
                    <div class="timing-group">
                        <div class="timing-group-title">Bulk rijs</div>
                        <div class="timing-row">
                            <label>Start</label>
                            <input type="datetime-local" class="timing-dt" id="bulk_rise_started_at"
                                   value="<?= fmtDt($existing['bulk_rise_started_at'] ?? '') ?>"
                                   onchange="updateTimingDurations()">
                            <button class="timing-now-btn" onclick="setTimingNow('bulk_rise_started_at')">Nu</button>
                        </div>
                        <div class="timing-row">
                            <label>Einde</label>
                            <input type="datetime-local" class="timing-dt" id="bulk_rise_ended_at"
                                   value="<?= fmtDt($existing['bulk_rise_ended_at'] ?? '') ?>"
                                   onchange="updateTimingDurations()">
                            <button class="timing-now-btn" onclick="setTimingNow('bulk_rise_ended_at')">Nu</button>
                        </div>
                        <div id="dur-bulk" class="timing-duration"></div>
                    </div>

                    <!-- Eindgisting -->
                    <div class="timing-group">
                        <div class="timing-group-title">Eindgisting</div>
                        <div class="timing-row">
                            <label>Start</label>
                            <input type="datetime-local" class="timing-dt" id="final_proof_started_at"
                                   value="<?= fmtDt($existing['final_proof_started_at'] ?? '') ?>"
                                   onchange="updateTimingDurations()">
                            <button class="timing-now-btn" onclick="setTimingNow('final_proof_started_at')">Nu</button>
                        </div>
                        <div class="timing-row">
                            <label>Einde</label>
                            <input type="datetime-local" class="timing-dt" id="final_proof_ended_at"
                                   value="<?= fmtDt($existing['final_proof_ended_at'] ?? '') ?>"
                                   onchange="updateTimingDurations()">
                            <button class="timing-now-btn" onclick="setTimingNow('final_proof_ended_at')">Nu</button>
                        </div>
                        <div id="dur-proof" class="timing-duration"></div>
                    </div>

                </div>
            </div>
            <?php endif; // showGlobalNotes — rijstijden ?>

            <!-- Notities (always visible in overview; in focused mode only for bakken/last day) -->
            <?php
                $nd = $existing['notes_data'] ?? [];
                $quality = (int)($nd['quality'] ?? 0);
            ?>
            <?php if ($showGlobalNotes): ?>
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
            <?php endif; // showGlobalNotes ?>


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

<!-- Voltooid guard modal -->
<div id="voltooiPromptModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:250;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;max-width:420px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.25)">
        <div style="padding:1.1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:0.75rem">
            <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;font-size:1.2rem"></i>
            <div style="font-weight:700;color:#1f2937;font-size:0.95rem">Verbruik nog niet geregistreerd</div>
            <button onclick="closeVoltooiPrompt()" style="margin-left:auto;background:none;border:none;font-size:1.4rem;color:#9ca3af;cursor:pointer;line-height:1">×</button>
        </div>
        <div style="padding:1.25rem">
            <p id="voltooiPromptMsg" style="margin:0 0 1.25rem;font-size:0.9rem;color:#374151;line-height:1.55"></p>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end">
                <button onclick="closeVoltooiPrompt()" style="padding:0.55rem 1.25rem;background:#fff;border:1px solid #d1d5db;font-size:0.88rem;cursor:pointer;color:#374151">Annuleren</button>
                <button id="voltooiPromptActionBtn" style="padding:0.55rem 1.5rem;background:#92400e;color:#fff;border:none;font-size:0.88rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:0.4rem"></button>
            </div>
        </div>
    </div>
</div>

<!-- Main inventory consume modal -->
<div id="invPreviewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:200;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;max-width:560px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.25)">
        <div style="padding:1.1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:0.75rem">
            <i class="bi bi-box-seam" style="color:#92400e;font-size:1.2rem"></i>
            <div>
                <div style="font-weight:700;color:#1f2937;font-size:0.95rem">Hoofddeeg — verbruik registreren</div>
                <div style="font-size:0.78rem;color:#6b7280">Controleer de afschrijving en bevestig</div>
            </div>
            <button onclick="closeInvModal()" style="margin-left:auto;background:none;border:none;font-size:1.4rem;color:#9ca3af;cursor:pointer;line-height:1">×</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:1.25rem">
            <div id="invModalLoading" style="text-align:center;padding:2rem;color:#9ca3af"><i class="bi bi-hourglass-split"></i> Laden…</div>
            <div id="invModalContent" style="display:none"></div>
        </div>
        <div style="padding:0.9rem 1.25rem;border-top:1px solid #f3f4f6;display:flex;gap:0.75rem;justify-content:flex-end;background:#fafafa">
            <button onclick="closeInvModal()" style="padding:0.55rem 1.25rem;background:#fff;border:1px solid #d1d5db;font-size:0.88rem;cursor:pointer;color:#374151">Annuleren</button>
            <button id="invConfirmBtn" onclick="confirmConsumption()" disabled style="padding:0.55rem 1.5rem;background:#92400e;color:#fff;border:none;font-size:0.88rem;font-weight:700;cursor:not-allowed;opacity:0.5;display:flex;align-items:center;gap:0.4rem">
                <i class="bi bi-check-lg"></i> Bevestigen &amp; afschrijven
            </button>
        </div>
    </div>
</div>

<!-- Main inventory movements modal (read-only) -->
<div id="movementsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:200;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;max-width:520px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.25)">
        <div style="padding:1.1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:0.75rem">
            <i class="bi bi-check-circle-fill" style="color:#059669;font-size:1.2rem"></i>
            <div>
                <div style="font-weight:700;color:#1f2937;font-size:0.95rem">Afgeschreven ingrediënten</div>
                <div style="font-size:0.78rem;color:#6b7280">Overzicht van afschrijvingen voor deze bakactie</div>
            </div>
            <button onclick="closeMovementsModal()" style="margin-left:auto;background:none;border:none;font-size:1.4rem;color:#9ca3af;cursor:pointer;line-height:1">×</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:1.25rem">
            <div id="movModalLoading" style="text-align:center;padding:2rem;color:#9ca3af"><i class="bi bi-hourglass-split"></i> Laden…</div>
            <div id="movModalContent" style="display:none"></div>
        </div>
        <div style="padding:0.9rem 1.25rem;border-top:1px solid #f3f4f6;background:#fafafa;text-align:right">
            <button onclick="closeMovementsModal()" style="padding:0.55rem 1.25rem;background:#fff;border:1px solid #d1d5db;font-size:0.88rem;cursor:pointer;color:#374151">Sluiten</button>
        </div>
    </div>
</div>

<!-- Version change modal (shown when inventory already consumed) -->
<div id="versionChangeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:250;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;max-width:580px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.25)">
        <div style="padding:1.1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:0.75rem">
            <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;font-size:1.2rem"></i>
            <div>
                <div style="font-weight:700;color:#1f2937;font-size:0.95rem">Receptversie wijzigen</div>
                <div style="font-size:0.78rem;color:#6b7280">Voorraadafschrijvingen zijn al geregistreerd voor deze bakactie</div>
            </div>
            <button onclick="closeVersionChangeModal()" style="margin-left:auto;background:none;border:none;font-size:1.4rem;color:#9ca3af;cursor:pointer;line-height:1">×</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:1.25rem">
            <div id="vcModalLoading" style="text-align:center;padding:2rem;color:#9ca3af"><i class="bi bi-hourglass-split"></i> Laden…</div>
            <div id="vcModalContent" style="display:none">
                <div style="background:#fef3c7;border:1px solid #fcd34d;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.85rem;color:#92400e;border-radius:4px">
                    <strong>Let op:</strong> De onderstaande afschrijvingen worden verwijderd en de voorraad wordt teruggeboekt. Daarna wordt de geselecteerde receptversie ingeladen.
                </div>
                <div id="vcMovementsList"></div>
            </div>
        </div>
        <div style="padding:0.9rem 1.25rem;border-top:1px solid #f3f4f6;display:flex;gap:0.75rem;justify-content:flex-end;background:#fafafa;flex-wrap:wrap">
            <button onclick="closeVersionChangeModal()" style="padding:0.55rem 1.25rem;background:#fff;border:1px solid #d1d5db;font-size:0.88rem;cursor:pointer;color:#374151">Annuleren</button>
            <button id="vcBtnLater" onclick="confirmVersionChange(false)" disabled style="padding:0.55rem 1.25rem;background:#fff;border:1px solid #d97706;color:#92400e;font-size:0.85rem;font-weight:600;cursor:not-allowed;opacity:0.5">
                Verwijder &amp; wijzig versie
            </button>
            <button id="vcBtnNow" onclick="confirmVersionChange(true)" disabled style="padding:0.55rem 1.5rem;background:#92400e;color:#fff;border:none;font-size:0.85rem;font-weight:700;cursor:not-allowed;opacity:0.5;display:inline-flex;align-items:center;gap:0.4rem">
                <i class="bi bi-arrow-repeat"></i> Verwijder &amp; schrijf direct opnieuw af
            </button>
        </div>
    </div>
</div>

<!-- Sourdough consume modal -->
<div id="sdInvPreviewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:200;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;max-width:520px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.25)">
        <div style="padding:1.1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:0.75rem">
            <i class="bi bi-fire" style="color:#7c3aed;font-size:1.2rem"></i>
            <div>
                <div style="font-weight:700;color:#1f2937;font-size:0.95rem">Desemmeel afschrijven</div>
                <div id="sdInvSubtitle" style="font-size:0.78rem;color:#6b7280">Controleer de afschrijving en bevestig</div>
            </div>
            <button onclick="closeSdInvModal()" style="margin-left:auto;background:none;border:none;font-size:1.4rem;color:#9ca3af;cursor:pointer;line-height:1">×</button>
        </div>
        <div id="sdInvModalBody" style="flex:1;overflow-y:auto;padding:1.25rem">
            <div id="sdInvModalLoading" style="text-align:center;padding:2rem;color:#9ca3af"><i class="bi bi-hourglass-split"></i> Laden…</div>
            <div id="sdInvModalContent" style="display:none"></div>
        </div>
        <div style="padding:0.9rem 1.25rem;border-top:1px solid #f3f4f6;display:flex;gap:0.75rem;justify-content:flex-end;background:#fafafa">
            <button id="sdInvCancelBtn" onclick="cancelSdInvModal()" style="padding:0.55rem 1.25rem;background:#fff;border:1px solid #d1d5db;font-size:0.88rem;cursor:pointer;color:#374151">Annuleren</button>
            <button id="sdInvConfirmBtn" onclick="confirmSdConsumption()" disabled style="padding:0.55rem 1.5rem;background:#7c3aed;color:#fff;border:none;font-size:0.88rem;font-weight:700;cursor:not-allowed;opacity:0.5;display:flex;align-items:center;gap:0.4rem">
                <i class="bi bi-check-lg"></i> Bevestigen &amp; afschrijven
            </button>
        </div>
    </div>
</div>

<!-- Sourdough movements modal -->
<div id="sdMovementsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:200;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;max-width:520px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.25)">
        <div style="padding:1.1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:0.75rem">
            <i class="bi bi-check-circle-fill" style="color:#059669;font-size:1.2rem"></i>
            <div>
                <div style="font-weight:700;color:#1f2937;font-size:0.95rem">Afgeschreven desemmeel</div>
                <div style="font-size:0.78rem;color:#6b7280">Overzicht van desemafschrijving voor deze bakactie</div>
            </div>
            <button onclick="closeSdMovementsModal()" style="margin-left:auto;background:none;border:none;font-size:1.4rem;color:#9ca3af;cursor:pointer;line-height:1">×</button>
        </div>
        <div id="sdMovModalBody" style="flex:1;overflow-y:auto;padding:1.25rem">
            <div id="sdMovModalLoading" style="text-align:center;padding:2rem;color:#9ca3af"><i class="bi bi-hourglass-split"></i> Laden…</div>
            <div id="sdMovModalContent" style="display:none"></div>
        </div>
        <div style="padding:0.9rem 1.25rem;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;background:#fafafa">
            <button onclick="closeSdMovementsModal()" style="padding:0.55rem 1.25rem;background:#fff;border:1px solid #d1d5db;font-size:0.88rem;cursor:pointer;color:#374151">Sluiten</button>
        </div>
    </div>
</div>

<script>
var BA_EXISTING_ID         = <?= $existing ? (int)$existing['id'] : 'null' ?>;
var BA_DATE                = <?= json_encode($date) ?>;
var BA_DOUGH_TYPE          = <?= json_encode($doughType) ?>;
var BA_DOUGH_TYPE_ID       = <?= json_encode($doughTypeId) ?>;
var BA_QTY                 = <?= (int)$totalQty ?>;
var BA_WEIGHT              = <?= (int)$totalWeightG ?>;
var BA_HAS_METHOD          = <?= $hasMethodDays ? 'true' : 'false' ?>;
var BA_TODAY_DAY_IDX       = <?= $todayDayIndex !== null ? (int)$todayDayIndex : 'null' ?>;
var BA_DAY_ACTIONS         = <?= json_encode(array_map(fn($d) => $d['actions'] ?? [], $methodDays ?? [])) ?>;
var BA_FOCUS_DAY           = <?= $focusDay !== null ? (int)$focusDay : 'null' ?>;
var BA_CURRENT_STATUS      = <?= json_encode($currentStatus) ?>;
var BA_INVENTORY_CONSUMED  = <?= json_encode((bool)($existing['inventory_consumed'] ?? false)) ?>;
var BA_SKIP_INVENTORY      = <?= json_encode((bool)($existing['skip_inventory'] ?? false)) ?>;
var BA_SOURDOUGH_CONSUMED  = <?= json_encode($sdConsumed) ?>;
var BA_SD_FLOUR            = <?= $calc && $calc['sourdough'] ? (int)$calc['sourdough']['flour'] : 0 ?>;
var BA_RECIPE_DATA         = <?= $recipeData ? json_encode($recipeData) : 'null' ?>;
var BA_RECIPE_LOCKED       = <?= ($existing && !empty($existing['locked_recipe_data'])) ? 'true' : 'false' ?>;
var BA_SD_FED_AT           = <?= !empty($existing['sourdough_fed_at'])       ? json_encode($existing['sourdough_fed_at'])       : 'null' ?>;
var BA_DOUGH_MIXED         = <?= !empty($existing['dough_mixed_at'])         ? json_encode($existing['dough_mixed_at'])         : 'null' ?>;
var BA_BULK_START          = <?= !empty($existing['bulk_rise_started_at'])   ? json_encode($existing['bulk_rise_started_at'])   : 'null' ?>;
var BA_BULK_END            = <?= !empty($existing['bulk_rise_ended_at'])     ? json_encode($existing['bulk_rise_ended_at'])     : 'null' ?>;
var BA_PROOF_START         = <?= !empty($existing['final_proof_started_at']) ? json_encode($existing['final_proof_started_at']) : 'null' ?>;
var BA_PROOF_END           = <?= !empty($existing['final_proof_ended_at'])   ? json_encode($existing['final_proof_ended_at'])   : 'null' ?>;
var _sdFromVoltooid = false;
var BT_KEY = 'civetta_bakery_temp';
var TODAY  = <?= json_encode(date('Y-m-d')) ?>;

function onBakkerijTempInput() {
    var val = document.getElementById('bakkerij_temp').value;
    // Propagate into flour and ambient if still empty
    var flourEl   = document.getElementById('flour_temp');
    var ambientEl = document.getElementById('ambient_temp');
    if (flourEl   && !flourEl.value)   { flourEl.value   = val; }
    if (ambientEl && !ambientEl.value) { ambientEl.value = val; }
    calcDDT();
    // Keep localStorage in sync so the dashboard widget stays current
    if (val !== '') {
        try {
            var now = new Date();
            var timeStr = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
            localStorage.setItem(BT_KEY, JSON.stringify({ value: parseFloat(val), date: TODAY, time: timeStr }));
        } catch(e) {}
    }
}

// Pre-fill flour_temp and ambient_temp from bakkerij temp if fields are empty
(function() {
    var btEl = document.getElementById('bakkerij_temp');
    if (btEl && btEl.value) {
        // Field already has a value (existing bakactie or PHP-prefilled from DB) — propagate
        var flourEl   = document.getElementById('flour_temp');
        var ambientEl = document.getElementById('ambient_temp');
        if (flourEl   && !flourEl.value)   flourEl.value   = btEl.value;
        if (ambientEl && !ambientEl.value) ambientEl.value = btEl.value;
        calcDDT();
        return;
    }
    try {
        var bt = JSON.parse(localStorage.getItem(BT_KEY));
        if (!bt || bt.value === undefined) return;
        if (BA_HAS_METHOD) {
            for (var di = 0; di < BA_DAY_ACTIONS.length; di++) {
                if ((BA_DAY_ACTIONS[di] || []).indexOf('deeg') !== -1) {
                    var flourEl   = document.getElementById('flour_temp_' + di);
                    var ambientEl = document.getElementById('ambient_temp_' + di);
                    var prefEl    = document.getElementById('ddt_pref_' + di);
                    if (flourEl   && !flourEl.value)   { flourEl.value   = bt.value; calcDayDDT(di); }
                    if (ambientEl && !ambientEl.value) { ambientEl.value = bt.value; calcDayDDT(di); }
                    if (prefEl    && !prefEl.value)    prefEl.value    = bt.value;
                }
            }
        } else {
            var flourEl   = document.getElementById('flour_temp');
            var ambientEl = document.getElementById('ambient_temp');
            var prefEl    = document.getElementById('ddt-preferment');
            if (flourEl   && !flourEl.value)   flourEl.value   = bt.value;
            if (ambientEl && !ambientEl.value) ambientEl.value = bt.value;
            if (prefEl    && !prefEl.value)    prefEl.value    = bt.value;
            var infoEl = document.getElementById('bt-info');
            if (infoEl) {
                var isToday = bt.date === TODAY;
                var when = isToday ? ('vandaag ' + (bt.time || '')) : (bt.date + ' (oud)');
                infoEl.textContent = 'Bakkerij: ' + bt.value + '°C — ' + when;
                infoEl.style.color = isToday ? '#059669' : '#b45309';
            }
            calcDDT();
        }
    } catch(e) {}
})();

updateTimingDurations();

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

function calcDayDDT(di) {
    var ddtEl  = document.getElementById('ddt_dough_' + di);
    var ddt    = ddtEl ? (parseFloat(ddtEl.value) || 0) : 24;
    var flour  = parseFloat((document.getElementById('flour_temp_' + di)   || {}).value) || 0;
    var ambient= parseFloat((document.getElementById('ambient_temp_' + di) || {}).value) || 0;
    var fricEl = document.getElementById('ddt_fric_' + di);
    var friction = fricEl ? (parseFloat(fricEl.value) || 0) : 8;
    var prefEl = document.getElementById('ddt_pref_' + di);
    var prefRaw= prefEl ? prefEl.value.trim() : '';
    var hasPref= prefRaw !== '' && !isNaN(parseFloat(prefRaw));
    var pref   = hasPref ? parseFloat(prefRaw) : 0;
    var resultEl = document.getElementById('ddt_result_' + di);
    var formulaEl= document.getElementById('ddt_formula_' + di);
    if (!resultEl) return;
    if (!ddt) { resultEl.textContent = '—'; resultEl.className = 'ddt-result ddt-empty'; if (formulaEl) formulaEl.textContent = ''; return; }
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
    if (formulaEl) formulaEl.textContent = formula + ' = ' + water + '°C';
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
    document.querySelectorAll('[data-day-field]').forEach(function(el) {
        var idx   = el.dataset.dayIdx;
        var field = el.dataset.dayField;
        if (idx === undefined || !field) return;
        if (!dayTimes[idx]) dayTimes[idx] = {};
        if (el.value !== '') dayTimes[idx][field] = el.value;
    });
    return dayTimes;
}

function deriveOverallStatus(markVoltooid) {
    if (markVoltooid) return 'voltooid';
    if (!BA_HAS_METHOD) return 'bezig';
    var dayTimes = collectDayTimes();
    var keys = Object.keys(dayTimes);
    if (!keys.length) return 'bezig';
    var anyStarted = false, allDone = true;
    for (var k in dayTimes) {
        if (dayTimes[k].start) anyStarted = true;
        if (!dayTimes[k].end)  allDone = false;
    }
    if (allDone && anyStarted) return 'voltooid';
    return 'bezig';
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

function _fmtTimingDuration(ms) {
    if (ms <= 0) return '';
    var totalMins = Math.round(ms / 60000);
    var h = Math.floor(totalMins / 60), m = totalMins % 60;
    return h > 0 ? (h + 'u' + (m ? ' ' + m + 'min' : '')) : (m + 'min');
}
function _dtVal(id) {
    var el = document.getElementById(id);
    return el && el.value ? new Date(el.value) : null;
}
function setTimingNow(id) {
    var el = document.getElementById(id);
    if (!el) return;
    var now = new Date();
    var pad = function(n) { return String(n).padStart(2,'0'); };
    el.value = now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate()) +
               'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
    updateTimingDurations();
    saveBakactie(false);
}
function updateTimingDurations() {
    var fed    = _dtVal('sourdough_fed_at');
    var mixed  = _dtVal('dough_mixed_at');
    var bulkS  = _dtVal('bulk_rise_started_at');
    var bulkE  = _dtVal('bulk_rise_ended_at');
    var proofS = _dtVal('final_proof_started_at');
    var proofE = _dtVal('final_proof_ended_at');

    var feedEl  = document.getElementById('dur-feed-to-mix');
    var bulkEl  = document.getElementById('dur-bulk');
    var proofEl = document.getElementById('dur-proof');

    if (feedEl)  feedEl.textContent  = (fed && mixed && mixed > fed) ? ('Gevoed → deeg: ' + _fmtTimingDuration(mixed - fed)) : '';
    if (bulkEl)  bulkEl.textContent  = (bulkS && bulkE && bulkE > bulkS) ? ('Duur: ' + _fmtTimingDuration(bulkE - bulkS)) : '';
    if (proofEl) proofEl.textContent = (proofS && proofE && proofE > proofS) ? ('Duur: ' + _fmtTimingDuration(proofE - proofS)) : '';
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
    } else if (BA_CURRENT_STATUS === 'voltooid') {
        badge.textContent = 'Afgerond'; badge.className = 'day-status-badge day-status-afgerond';
    } else {
        badge.textContent = 'Gepland';  badge.className = 'day-status-badge day-status-gepland';
    }
    if (startBtn) startBtn.style.display = (hasStart || BA_CURRENT_STATUS === 'voltooid') ? 'none' : '';
    if (endBtn)   endBtn.style.display   = (hasStart && !hasEnd && BA_CURRENT_STATUS !== 'voltooid') ? '' : 'none';
}

function nowTime() {
    var now = new Date();
    return String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
}

function startDay(di) {
    var startEl  = document.getElementById('day_start_' + di);
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
    // When marking Voltooid: auto-fill end times for days that started but weren't closed
    if (markVoltooid && BA_HAS_METHOD) {
        var now = nowTime();
        for (var k in dayTimes) {
            if (dayTimes[k].start && !dayTimes[k].end) {
                dayTimes[k].end = now;
                var endEl = document.getElementById('day_end_' + k);
                if (endEl && !endEl.value) { endEl.value = now; updateDayDuration(parseInt(k)); updateDayStatus(parseInt(k)); }
            }
        }
    }
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
    // Derive global temp DB columns from per-day data or global fields
    var waterTemp = '', flourTemp = '', ambientTemp = '', doughTemp = '', ovenTemp = '', bakeTimeMin = '';
    if (BA_HAS_METHOD) {
        for (var di = 0; di < BA_DAY_ACTIONS.length; di++) {
            var acts = BA_DAY_ACTIONS[di] || [];
            var dt   = dayTimes[String(di)] || {};
            if (acts.indexOf('deeg') !== -1) {
                if (!waterTemp   && dt.water_temp)   waterTemp   = dt.water_temp;
                if (!flourTemp   && dt.flour_temp)   flourTemp   = dt.flour_temp;
                if (!ambientTemp && dt.ambient_temp) ambientTemp = dt.ambient_temp;
                if (!doughTemp   && dt.dough_temp)   doughTemp   = dt.dough_temp;
            }
            if (acts.indexOf('bakken') !== -1) {
                if (!ovenTemp    && dt.oven_temp)  ovenTemp    = dt.oven_temp;
                if (!bakeTimeMin && dt.bake_time)  bakeTimeMin = dt.bake_time;
            }
        }
    } else {
        waterTemp   = document.getElementById('water_temp').value;
        flourTemp   = document.getElementById('flour_temp').value;
        ambientTemp = document.getElementById('ambient_temp').value;
        doughTemp   = document.getElementById('dough_temp').value;
        ovenTemp    = document.getElementById('oven_temp').value;
        bakeTimeMin = document.getElementById('bake_time_minutes').value;
    }
    // Build action_categories: unique categories across all days, in canonical order
    var actionCategories = '';
    if (BA_HAS_METHOD) {
        var order = ['pre-ferment','deeg','vormen','bakken'];
        var seen = {}, allCats = [];
        for (var di = 0; di < BA_DAY_ACTIONS.length; di++) {
            (BA_DAY_ACTIONS[di] || []).forEach(function(c) { if (!seen[c]) { seen[c] = true; allCats.push(c); } });
        }
        allCats.sort(function(a, b) { return order.indexOf(a) - order.indexOf(b); });
        actionCategories = allCats.join(',');
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
        water_temp:        waterTemp,
        flour_temp:        flourTemp,
        ambient_temp:      ambientTemp,
        bakkerij_temp:     (document.getElementById('bakkerij_temp') || {}).value || '',
        dough_temp:        doughTemp,
        oven_temp:         ovenTemp,
        bake_time_minutes: bakeTimeMin,
        action_categories: actionCategories,
        notes_data:        notesData,
        locked_recipe_data: BA_RECIPE_DATA,
    };
    ['sourdough_fed_at','dough_mixed_at','bulk_rise_started_at','bulk_rise_ended_at','final_proof_started_at','final_proof_ended_at'].forEach(function(k) {
        var el = document.getElementById(k);
        if (el && el.value) payload[k] = el.value;
    });
    return payload;
}

function closeVoltooiPrompt() {
    document.getElementById('voltooiPromptModal').style.display = 'none';
}

async function savePlannedLoafVersion(bakactieId, recipeId, selectEl) {
    const [versionId, major, minor] = selectEl.value.split(':').map(Number);
    selectEl.disabled = true;
    try {
        const res = await fetch('../../api/bak-acties.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                _action: 'update_planned_loaf_version',
                id: bakactieId,
                recipe_id: String(recipeId),
                version_id: versionId,
                dough_type_version_number: major,
                loaf_minor_version: minor,
            }),
        });
        const data = await res.json();
        if (!data.success) console.error('Versie opslaan mislukt:', data.error);
    } catch (e) { console.error(e); }
    selectEl.disabled = false;
}

function saveBakactie(markVoltooid) {
    if (markVoltooid && !BA_SKIP_INVENTORY && !BA_INVENTORY_CONSUMED) {
        var msg = document.getElementById('voltooiPromptMsg');
        var btn = document.getElementById('voltooiPromptActionBtn');
        msg.textContent = 'Schrijf de ingrediënten af voordat je de bakactie als voltooid markeert.';
        btn.innerHTML = '<i class="bi bi-box-seam"></i> Afschrijven';
        btn.onclick = function() { closeVoltooiPrompt(); openInvForConsumption(); };
        document.getElementById('voltooiPromptModal').style.display = 'flex';
        return;
    }
    if (markVoltooid && BA_SD_FLOUR > 0 && !BA_SOURDOUGH_CONSUMED) {
        _saveForVoltooid();
        return;
    }

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

function _saveForVoltooid() {
    var btn = document.getElementById('btnSave');
    var msg = document.getElementById('saveMsg');
    if (btn) btn.disabled = true;
    msg.style.display = 'none';

    var payload = collectPayload(true);
    var isNew = !BA_EXISTING_ID;
    if (!isNew) payload.id = BA_EXISTING_ID;

    fetch('/api/bak-acties.php', {
        method: isNew ? 'POST' : 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (btn) btn.disabled = false;
        if (data.success) {
            if (isNew && data.id) BA_EXISTING_ID = data.id;
            _sdFromVoltooid = true;
            var subtitle = document.getElementById('sdInvSubtitle');
            if (subtitle) subtitle.textContent = 'Bakactie opgeslagen als voltooid — schrijf desem af om af te sluiten.';
            var cancelBtn = document.getElementById('sdInvCancelBtn');
            if (cancelBtn) cancelBtn.textContent = 'Overslaan → naar logboek';
            openSdInvForConsumption();
        } else {
            msg.textContent = 'Fout: ' + (data.error || 'Onbekende fout');
            msg.className = 'save-msg error';
            msg.style.display = 'inline';
        }
    })
    .catch(function(e) {
        if (btn) btn.disabled = false;
        msg.textContent = 'Fout: ' + e.message;
        msg.className = 'save-msg error';
        msg.style.display = 'inline';
    });
}

// ── Main inventory functions ─────────────────────────────────────────────────

function openInvForConsumption() {
    if (!BA_EXISTING_ID) return;
    var modal = document.getElementById('invPreviewModal');
    modal.style.display = 'flex';
    document.getElementById('invModalLoading').style.display = '';
    document.getElementById('invModalContent').style.display = 'none';
    document.getElementById('invModalContent').innerHTML = '';
    var btn = document.getElementById('invConfirmBtn');
    btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';

    // Sync current recipe data first, then load preview
    var prepare = (BA_RECIPE_DATA && BA_WEIGHT > 0)
        ? fetch('/api/bak-acties.php', {
              method: 'PUT',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: BA_EXISTING_ID, locked_recipe_data: BA_RECIPE_DATA, total_weight_g: BA_WEIGHT })
          }).then(function(r) { return r.json(); })
        : Promise.resolve();

    prepare.then(function() {
        return fetch('/api/bak-acties.php?preview_inventory=1&id=' + BA_EXISTING_ID);
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        document.getElementById('invModalLoading').style.display = 'none';
        var content = document.getElementById('invModalContent');
        content.style.display = '';
        if (!data.success) { content.innerHTML = '<p style="color:#dc2626">Fout: ' + (data.error || 'onbekend') + '</p>'; return; }
        var p = data.preview;
        if (!p) { content.innerHTML = '<p style="color:#6b7280">Geen recept beschikbaar.</p>'; return; }
        if (p.already_consumed) { content.innerHTML = '<p style="color:#059669"><i class="bi bi-check-circle"></i> Voorraad al eerder afgeschreven.</p>'; return; }
        if (p.no_recipe) { content.innerHTML = '<p style="color:#f59e0b"><i class="bi bi-exclamation-triangle"></i> Geen receptdata — kan niet berekenen.</p>'; return; }
        if (!p.ingredients || !p.ingredients.length) { content.innerHTML = '<p style="color:#6b7280">Geen ingrediënten in het recept.</p>'; return; }
        var hasShortage = p.ingredients.some(function(i) { return i.shortage_g > 0; });
        var html = hasShortage
            ? '<div style="background:#fef2f2;border:1px solid #fca5a5;padding:0.65rem 0.9rem;margin-bottom:1rem;font-size:0.83rem;color:#b91c1c"><i class="bi bi-exclamation-triangle"></i> Let op: onvoldoende voorraad voor één of meer ingrediënten.</div>'
            : '<div style="background:#f0fdf4;border:1px solid #86efac;padding:0.65rem 0.9rem;margin-bottom:1rem;font-size:0.83rem;color:#166534"><i class="bi bi-check-circle"></i> Voldoende voorraad beschikbaar.</div>';
        p.ingredients.forEach(function(ing) {
            html += '<div style="margin-bottom:0.85rem;border:1px solid #f3f4f6;overflow:hidden">';
            html += '<div style="background:#faf8f4;padding:0.5rem 0.9rem;display:flex;justify-content:space-between;align-items:baseline">';
            html += '<span style="font-weight:700;color:#1f2937;font-size:0.88rem">' + ing.ingredient_name + '</span>';
            html += '<span style="font-size:0.83rem"><span style="color:#92400e;font-weight:800">' + Math.round(ing.needed_g) + 'g</span> nodig';
            if (ing.shortage_g > 0) html += ' <span style="color:#dc2626;font-weight:700">— ' + Math.round(ing.shortage_g) + 'g tekort</span>';
            html += '</span></div>';
            if (ing.batches.length > 0) {
                html += '<table style="width:100%;font-size:0.78rem;border-collapse:collapse">';
                html += '<thead><tr style="color:#9ca3af;font-size:0.68rem;text-transform:uppercase"><th style="text-align:left;padding:0.25rem 0.9rem;border-bottom:1px solid #f3f4f6">Batch</th><th style="text-align:right;padding:0.25rem 0.9rem;border-bottom:1px solid #f3f4f6">Beschikbaar</th><th style="text-align:right;padding:0.25rem 0.9rem;border-bottom:1px solid #f3f4f6">Gebruik</th><th style="text-align:right;padding:0.25rem 0.9rem;border-bottom:1px solid #f3f4f6">Na</th></tr></thead><tbody>';
                ing.batches.forEach(function(b) {
                    var ac = b.remaining_after_g < 0 ? '#dc2626' : (b.remaining_after_g < 100 ? '#f59e0b' : '#059669');
                    html += '<tr><td style="padding:0.25rem 0.9rem;color:#374151">' + b.display_name;
                    if (b.thd_date) html += ' <span style="color:#9ca3af;font-size:0.68rem">THD ' + b.thd_date + '</span>';
                    html += '</td><td style="text-align:right;padding:0.25rem 0.9rem;color:#6b7280">' + Math.round(b.available_g) + 'g</td>';
                    html += '<td style="text-align:right;padding:0.25rem 0.9rem;color:#92400e;font-weight:700">−' + Math.round(b.from_batch_g) + 'g</td>';
                    html += '<td style="text-align:right;padding:0.25rem 0.9rem;font-weight:600;color:' + ac + '">' + Math.round(b.remaining_after_g) + 'g</td></tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<div style="padding:0.5rem 0.9rem;color:#dc2626;font-size:0.8rem"><i class="bi bi-exclamation-triangle"></i> Geen voorraad beschikbaar!</div>';
            }
            html += '</div>';
        });
        content.innerHTML = html;
        btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
    })
    .catch(function(e) {
        document.getElementById('invModalLoading').style.display = 'none';
        document.getElementById('invModalContent').innerHTML = '<p style="color:#dc2626">Fout: ' + e.message + '</p>';
        document.getElementById('invModalContent').style.display = '';
    });
}

function closeInvModal() {
    document.getElementById('invPreviewModal').style.display = 'none';
}

function confirmConsumption() {
    var btn = document.getElementById('invConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig…';
    fetch('/api/bak-acties.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _action: 'consume_inventory', id: BA_EXISTING_ID, extras: [] })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            BA_INVENTORY_CONSUMED = true;
            closeInvModal();
            document.querySelectorAll('[onclick="openInvForConsumption()"]').forEach(function(b) {
                b.outerHTML = '<button onclick="openMovementsModal()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.9rem;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;font-size:0.82rem;font-weight:700;cursor:pointer;"><i class="bi bi-check-circle-fill"></i> Klaar — hoofddeeg afgeschreven</button>';
            });
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Bevestigen & afschrijven';
            alert('Fout: ' + (data.error || 'Onbekende fout'));
        }
    })
    .catch(function(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Bevestigen & afschrijven';
        alert('Verbindingsfout: ' + e.message);
    });
}

function openMovementsModal() {
    var modal = document.getElementById('movementsModal');
    modal.style.display = 'flex';
    document.getElementById('movModalLoading').style.display = '';
    document.getElementById('movModalContent').style.display = 'none';
    fetch('/api/inventory.php?action=history&bakactie_id=' + BA_EXISTING_ID + '&limit=50')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('movModalLoading').style.display = 'none';
            var content = document.getElementById('movModalContent');
            content.style.display = '';
            if (!data.success || !data.history || !data.history.length) {
                content.innerHTML = '<p style="color:#6b7280;text-align:center;padding:1.5rem">Geen afschrijvingen gevonden.</p>';
                return;
            }
            var totalCost = 0;
            var html = '<table style="width:100%;border-collapse:collapse;font-size:0.82rem">';
            html += '<thead><tr style="color:#9ca3af;font-size:0.7rem;text-transform:uppercase;border-bottom:2px solid #f3f4f6"><th style="text-align:left;padding:0.35rem 0.75rem">Ingrediënt</th><th style="text-align:left;padding:0.35rem 0.75rem">THD</th><th style="text-align:right;padding:0.35rem 0.75rem">Hoeveelheid</th><th style="text-align:right;padding:0.35rem 0.75rem">Kosten</th></tr></thead><tbody>';
            data.history.forEach(function(m) {
                totalCost += parseFloat(m.cost || 0);
                html += '<tr style="border-bottom:1px solid #f9fafb"><td style="padding:0.35rem 0.75rem;font-weight:600;color:#1f2937">' + (m.group_name || m.ingredient_name);
                if (m.brand_name) html += ' <span style="color:#9ca3af;font-weight:400">· ' + m.brand_name + '</span>';
                html += '</td><td style="padding:0.35rem 0.75rem;color:#9ca3af">' + (m.thd_date || '—') + '</td>';
                html += '<td style="text-align:right;padding:0.35rem 0.75rem;color:#92400e;font-weight:700">' + Math.round(m.quantity_consumed) + 'g</td>';
                html += '<td style="text-align:right;padding:0.35rem 0.75rem;color:#374151">€' + parseFloat(m.cost).toFixed(2) + '</td></tr>';
            });
            html += '<tr style="border-top:2px solid #e5e7eb;font-weight:700"><td colspan="3" style="padding:0.5rem 0.75rem;color:#374151">Totaal</td><td style="text-align:right;padding:0.5rem 0.75rem;color:#065f46">€' + totalCost.toFixed(2) + '</td></tr>';
            html += '</tbody></table>';
            content.innerHTML = html;
        })
        .catch(function(e) {
            document.getElementById('movModalLoading').style.display = 'none';
            document.getElementById('movModalContent').innerHTML = '<p style="color:#dc2626">Fout: ' + e.message + '</p>';
            document.getElementById('movModalContent').style.display = '';
        });
}

function closeMovementsModal() {
    document.getElementById('movementsModal').style.display = 'none';
}

// ── Sourdough inventory functions ─────────────────────────────────────────────

var _sdIngredientId = 0;

function openSdInvForConsumption() {
    if (!BA_EXISTING_ID || !BA_SD_FLOUR) return;
    // Read selected brand from the sourdough dropdown (any on the page)
    var sdSel = document.querySelector('[data-ing="sourdough"]');
    _sdIngredientId = sdSel && sdSel.value ? parseInt(sdSel.value) : 0;

    var modal = document.getElementById('sdInvPreviewModal');
    modal.style.display = 'flex';
    document.getElementById('sdInvModalLoading').style.display = '';
    document.getElementById('sdInvModalContent').style.display = 'none';
    _setSdInvConfirmEnabled(false);

    // Always sync current recipe data to DB before preview (ensures stale locked_recipe_data is overwritten)
    var ensureLocked = (BA_RECIPE_DATA && BA_WEIGHT > 0 && BA_EXISTING_ID)
        ? fetch('/api/bak-acties.php', {
              method: 'PUT',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: BA_EXISTING_ID, locked_recipe_data: BA_RECIPE_DATA, total_weight_g: BA_WEIGHT })
          }).then(function(r) { return r.json(); }).then(function() { BA_RECIPE_LOCKED = true; })
        : Promise.resolve();

    ensureLocked
        .then(function() { return fetch('/api/bak-acties.php?preview_sourdough&id=' + BA_EXISTING_ID); })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('sdInvModalLoading').style.display = 'none';
            var content = document.getElementById('sdInvModalContent');
            content.style.display = '';
            var p = data.preview;
            if (!p) { content.innerHTML = '<p style="color:#6b7280">Geen voorraaddata beschikbaar.</p>'; return; }
            if (p.already_consumed) { content.innerHTML = '<p style="color:#059669"><i class="bi bi-check-circle"></i> Desem al eerder afgeschreven.</p>'; return; }
            if (p.no_sourdough || p.no_recipe) { content.innerHTML = '<p style="color:#9ca3af">Geen zuurdesem in dit recept.</p>'; return; }
            if (!p.ingredients || !p.ingredients.length) { content.innerHTML = '<p style="color:#9ca3af">Geen ingrediënten te verwerken.</p>'; return; }
            var html = '';
            var hasShortage = false;
            p.ingredients.forEach(function(ing) {
                if (ing.shortage_g > 0) hasShortage = true;
                html += '<div style="margin-bottom:1rem">';
                html += '<div style="font-weight:700;color:#1f2937;font-size:0.9rem;margin-bottom:0.4rem">'
                    + ing.ingredient_name + ' — <span style="color:#7c3aed">' + Math.round(ing.needed_g) + 'g</span></div>';
                if (ing.shortage_g > 0) {
                    html += '<div style="color:#dc2626;font-size:0.8rem;margin-bottom:0.35rem"><i class="bi bi-exclamation-triangle"></i> Tekort: '
                        + Math.round(ing.shortage_g) + 'g</div>';
                }
                ing.batches.forEach(function(b) {
                    html += '<div style="display:flex;justify-content:space-between;font-size:0.8rem;color:#374151;padding:0.2rem 0;border-bottom:1px solid #fafafa">';
                    html += '<span>' + b.display_name + (b.thd_date ? ' · THD: ' + b.thd_date : '') + '</span>';
                    html += '<span style="font-weight:700;color:#7c3aed">' + Math.round(b.from_batch_g) + 'g</span>';
                    html += '</div>';
                });
                html += '</div>';
            });
            content.innerHTML = html;
            _setSdInvConfirmEnabled(true);
        })
        .catch(function(e) {
            document.getElementById('sdInvModalLoading').style.display = 'none';
            document.getElementById('sdInvModalContent').innerHTML = '<p style="color:#dc2626">Fout: ' + e.message + '</p>';
            document.getElementById('sdInvModalContent').style.display = '';
        });
}

function _setSdInvConfirmEnabled(enabled) {
    var btn = document.getElementById('sdInvConfirmBtn');
    btn.disabled = !enabled;
    btn.style.opacity = enabled ? '1' : '0.5';
    btn.style.cursor  = enabled ? 'pointer' : 'not-allowed';
}

function closeSdInvModal() {
    document.getElementById('sdInvPreviewModal').style.display = 'none';
    _sdFromVoltooid = false;
    var subtitle = document.getElementById('sdInvSubtitle');
    if (subtitle) subtitle.textContent = 'Controleer de afschrijving en bevestig';
    var cancelBtn = document.getElementById('sdInvCancelBtn');
    if (cancelBtn) cancelBtn.textContent = 'Annuleren';
}

function cancelSdInvModal() {
    var fromVoltooid = _sdFromVoltooid;
    closeSdInvModal();
    if (fromVoltooid) window.location.href = 'logboek.php';
}

function confirmSdConsumption() {
    if (!BA_EXISTING_ID || BA_SOURDOUGH_CONSUMED) return;
    var btn = document.getElementById('sdInvConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Bezig…';

    fetch('/api/bak-acties.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            _action: 'consume_sourdough',
            id: BA_EXISTING_ID,
            ingredient_id: _sdIngredientId,
            quantity_g: BA_SD_FLOUR
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            BA_SOURDOUGH_CONSUMED = true;
            if (_sdFromVoltooid) {
                window.location.href = 'logboek.php';
                return;
            }
            closeSdInvModal();
            // Update all sourdough Gedaan buttons to Klaar
            document.querySelectorAll('[onclick="openSdInvForConsumption()"]').forEach(function(btn) {
                btn.outerHTML = '<button onclick="openSdMovementsModal()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.9rem;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;font-size:0.82rem;font-weight:700;cursor:pointer;">'
                    + '<i class="bi bi-check-circle-fill"></i> Klaar — desem afgeschreven</button>';
            });
        } else {
            _setSdInvConfirmEnabled(true);
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Bevestigen & afschrijven';
            alert(d.error || 'Er is een fout opgetreden');
        }
    })
    .catch(function(e) {
        _setSdInvConfirmEnabled(true);
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Bevestigen & afschrijven';
        alert('Verbindingsfout: ' + e.message);
    });
}

function openSdMovementsModal() {
    var modal = document.getElementById('sdMovementsModal');
    modal.style.display = 'flex';
    document.getElementById('sdMovModalLoading').style.display = '';
    document.getElementById('sdMovModalContent').style.display = 'none';

    fetch('/api/inventory.php?action=history&bakactie_id=' + BA_EXISTING_ID + '&limit=10')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('sdMovModalLoading').style.display = 'none';
            var content = document.getElementById('sdMovModalContent');
            content.style.display = '';
            if (!data.success || !data.history || !data.history.length) {
                closeSdMovementsModal();
                // Flag is stale — reset in DB and flip all buttons back to registration state
                fetch('/api/bak-acties.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ _action: 'reset_sourdough_consumed', id: BA_EXISTING_ID })
                });
                document.querySelectorAll('[onclick="openSdMovementsModal()"]').forEach(function(btn) {
                    btn.outerHTML = '<button onclick="openSdInvForConsumption()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.9rem;background:#7c3aed;color:#fff;border:none;font-size:0.82rem;font-weight:700;cursor:pointer;"><i class="bi bi-fire"></i> Gedaan — registreer verbruik</button>';
                });
                return;
            }
            // Filter to only show flour-type movements (sourdough flour)
            var totalCost = 0;
            var html = '<table style="width:100%;border-collapse:collapse;font-size:0.82rem">';
            html += '<thead><tr style="color:#9ca3af;font-size:0.7rem;text-transform:uppercase;border-bottom:2px solid #f3f4f6">';
            html += '<th style="text-align:left;padding:0.35rem 0.75rem">Ingrediënt</th>';
            html += '<th style="text-align:right;padding:0.35rem 0.75rem">Hoeveelheid</th>';
            html += '<th style="text-align:right;padding:0.35rem 0.75rem">Kosten</th></tr></thead><tbody>';
            data.history.forEach(function(m) {
                totalCost += parseFloat(m.cost || 0);
                html += '<tr style="border-bottom:1px solid #f9fafb">';
                html += '<td style="padding:0.35rem 0.75rem;font-weight:600;color:#1f2937">' + (m.group_name || m.ingredient_name);
                if (m.brand_name) html += ' <span style="color:#9ca3af;font-weight:400">· ' + m.brand_name + '</span>';
                html += '</td>';
                html += '<td style="text-align:right;padding:0.35rem 0.75rem;color:#7c3aed;font-weight:700">' + Math.round(m.quantity_consumed) + 'g</td>';
                html += '<td style="text-align:right;padding:0.35rem 0.75rem;color:#374151">€' + parseFloat(m.cost).toFixed(2) + '</td>';
                html += '</tr>';
            });
            html += '<tr style="border-top:2px solid #e5e7eb;font-weight:700">';
            html += '<td colspan="2" style="padding:0.5rem 0.75rem;color:#374151">Totaal</td>';
            html += '<td style="text-align:right;padding:0.5rem 0.75rem;color:#065f46">€' + totalCost.toFixed(2) + '</td></tr>';
            html += '</tbody></table>';
            content.innerHTML = html;
        })
        .catch(function(e) {
            document.getElementById('sdMovModalLoading').style.display = 'none';
            document.getElementById('sdMovModalContent').innerHTML = '<p style="color:#dc2626">Fout: ' + e.message + '</p>';
            document.getElementById('sdMovModalContent').style.display = '';
        });
}

function closeSdMovementsModal() {
    document.getElementById('sdMovementsModal').style.display = 'none';
}

function loadRecipeNow() {
    var sel = document.getElementById('versionSwitcher');
    var vId = sel ? sel.value : null;
    if (!vId) {
        // No version switcher — fetch latest version for this dough type
        fetch('/api/dough-types.php?id=<?= (int)$doughTypeId ?>')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success || !d.dough_type) { alert('Deegtype niet gevonden'); return; }
                var v = d.dough_type.versions && d.dough_type.versions[0];
                if (!v || !v.recipe_data) { alert('Geen receptdata beschikbaar in dit deegtype. Stel het recept eerst in via Recepten.'); return; }
                _saveVersionData(v.id, v.name, v.recipe_data);
            })
            .catch(function(e) { alert('Fout: ' + e.message); });
        return;
    }
    fetch('/api/dough-types.php?version_id=' + vId)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success || !d.version) { alert('Versie niet gevonden'); return; }
            var v = d.version;
            if (!v.recipe_data) { alert('Versie ' + vId + ' heeft geen receptdata. Stel het recept in via Recepten en sla een nieuwe versie op.'); return; }
            _saveVersionData(v.id, v.name, v.recipe_data);
        })
        .catch(function(e) { alert('Fout: ' + e.message); });
}
function _saveVersionData(vId, vName, recipeData) {
    fetch('/api/bak-acties.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: BA_EXISTING_ID,
            dough_type_version_id: vId,
            locked_recipe_name: vName || BA_DOUGH_TYPE,
            locked_recipe_data: recipeData
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) window.location.reload();
        else alert('Fout bij opslaan: ' + (data.error || 'onbekend'));
    });
}

function switchVersion(versionId) {
    if (!BA_EXISTING_ID || !versionId) return;
    if (BA_INVENTORY_CONSUMED) {
        openVersionChangeModal(versionId);
        return;
    }
    if (!confirm('Weet je zeker dat je het recept van deze bakactie wilt wijzigen naar de geselecteerde versie? De receptdata wordt herladen.')) {
        var sel = document.getElementById('versionSwitcher');
        if (sel) sel.value = sel.querySelector('option[selected]')?.value || '';
        return;
    }
    fetch('/api/dough-types.php?version_id=' + versionId)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success || !d.version) { alert('Versie niet gevonden'); return; }
            var v = d.version;
            _saveVersionData(v.id, v.name || BA_DOUGH_TYPE, v.recipe_data);
        })
        .catch(function(e) { alert('Fout: ' + e.message); });
}

var _vcPendingVersionId = null;

function openVersionChangeModal(versionId) {
    _vcPendingVersionId = versionId;
    var modal = document.getElementById('versionChangeModal');
    modal.style.display = 'flex';
    document.getElementById('vcModalLoading').style.display = '';
    document.getElementById('vcModalContent').style.display = 'none';
    ['vcBtnLater','vcBtnNow'].forEach(function(id) {
        var b = document.getElementById(id);
        b.disabled = true; b.style.opacity = '0.5'; b.style.cursor = 'not-allowed';
    });

    fetch('/api/inventory.php?action=history&limit=200&bakactie_id=' + BA_EXISTING_ID)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('vcModalLoading').style.display = 'none';
            var content = document.getElementById('vcModalContent');
            content.style.display = '';
            var list = document.getElementById('vcMovementsList');
            if (!data.success || !data.history || !data.history.length) {
                list.innerHTML = '<p style="color:#6b7280;font-size:0.85rem">Geen afschrijvingen gevonden.</p>';
            } else {
                var html = '<table style="width:100%;font-size:0.82rem;border-collapse:collapse">';
                html += '<thead><tr style="color:#9ca3af;font-size:0.72rem;text-transform:uppercase">'
                    + '<th style="text-align:left;padding:0.25rem 0.5rem;border-bottom:1px solid #f3f4f6">Ingrediënt</th>'
                    + '<th style="text-align:right;padding:0.25rem 0.5rem;border-bottom:1px solid #f3f4f6">Gebruikt</th>'
                    + '<th style="text-align:right;padding:0.25rem 0.5rem;border-bottom:1px solid #f3f4f6">Kosten</th>'
                    + '</tr></thead><tbody>';
                data.history.forEach(function(m) {
                    html += '<tr><td style="padding:0.3rem 0.5rem;color:#374151">' + (m.group_name || '');
                    if (m.brand_name) html += ' <span style="color:#9ca3af">· ' + m.brand_name + '</span>';
                    html += '</td><td style="text-align:right;padding:0.3rem 0.5rem;font-weight:600;color:#92400e">' + Math.round(m.quantity_consumed) + 'g</td>';
                    html += '<td style="text-align:right;padding:0.3rem 0.5rem;color:#6b7280">' + (m.cost ? '€' + parseFloat(m.cost).toFixed(2) : '—') + '</td></tr>';
                });
                html += '</tbody></table>';
                list.innerHTML = html;
            }
            ['vcBtnLater','vcBtnNow'].forEach(function(id) {
                var b = document.getElementById(id);
                b.disabled = false; b.style.opacity = '1'; b.style.cursor = 'pointer';
            });
        })
        .catch(function(e) {
            document.getElementById('vcModalLoading').style.display = 'none';
            document.getElementById('vcModalContent').innerHTML = '<p style="color:#dc2626">Fout: ' + e.message + '</p>';
            document.getElementById('vcModalContent').style.display = '';
        });
}

function closeVersionChangeModal() {
    document.getElementById('versionChangeModal').style.display = 'none';
    var sel = document.getElementById('versionSwitcher');
    if (sel) {
        var cur = sel.querySelector('option[selected]');
        sel.value = cur ? cur.value : '';
    }
    _vcPendingVersionId = null;
}

function confirmVersionChange(reRegisterAfter) {
    if (!_vcPendingVersionId) return;
    var vId = _vcPendingVersionId;
    ['vcBtnLater','vcBtnNow'].forEach(function(id) {
        var b = document.getElementById(id);
        b.disabled = true; b.style.opacity = '0.5'; b.style.cursor = 'not-allowed';
    });

    fetch('/api/bak-acties.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _action: 'revert_inventory', id: BA_EXISTING_ID })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) { alert('Fout bij verwijderen afschrijvingen: ' + (data.error || 'onbekend')); return Promise.reject(); }
        return fetch('/api/dough-types.php?version_id=' + vId);
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success || !d.version) { alert('Versie niet gevonden'); return; }
        if (reRegisterAfter) sessionStorage.setItem('ba_open_inv_after_reload', '1');
        _saveVersionData(d.version.id, d.version.name || BA_DOUGH_TYPE, d.version.recipe_data);
    })
    .catch(function(e) {
        if (e) alert('Fout: ' + e.message);
        ['vcBtnLater','vcBtnNow'].forEach(function(id) {
            var b = document.getElementById(id);
            b.disabled = false; b.style.opacity = '1'; b.style.cursor = 'pointer';
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    if (sessionStorage.getItem('ba_open_inv_after_reload') && BA_EXISTING_ID && !BA_INVENTORY_CONSUMED) {
        sessionStorage.removeItem('ba_open_inv_after_reload');
        openInvForConsumption();
    }
});

function heropenBakactie() {
    if (!BA_EXISTING_ID) return;
    fetch('/api/bak-acties.php', {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: BA_EXISTING_ID, status: 'bezig'})
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) window.location.reload();
    });
}
</script>

</div><!-- /.admin-main -->
</div><!-- /.admin-layout -->
</body>
</html>
