<?php

// ── Recipe / flour composition helpers ──────────────────────────────────────

function grainIsWhole($type, $lookup = []) {
    if (is_numeric($type) && isset($lookup[(int)$type])) {
        return (bool)$lookup[(int)$type]['is_whole_grain'];
    }
    return strpos((string)$type, '_whole') !== false;
}

function grainDisplayName($type, $lookup = [], $capitalize = false) {
    if (is_numeric($type) && isset($lookup[(int)$type])) {
        $name = $lookup[(int)$type]['name'];
        return $capitalize ? $name : strtolower($name);
    }
    $legacy = [
        'wheat_white' => 'tarwebloem',      'wheat_whole' => 'volkorenmeel',
        'spelt_white' => 'speltbloem',       'spelt_whole' => 'volkorenspeltmeel',
        'durum'       => 'durummeel',         'emmer'       => 'emmermeel',
        'rye_white'   => 'roggebloem',        'rye_whole'   => 'volkorenroggemeel',
        'einkorn'     => 'einkornmeel',       'buckwheat'   => 'boekweitmeel',
        'rice'        => 'rijstmeel',         'barley'      => 'gerstemeel',
        'teff'        => 'teffmeel',
    ];
    $name = $legacy[$type] ?? (string)$type;
    return $capitalize ? ucfirst($name) : $name;
}

// Aggregates all grain sections into a map keyed by grain type, with each
// grain's pct weighted by that section's fraction of total flour.
// sourdoughFlour = (totalFlour × sourdoughPct/100) / (1 + sourdoughHydration/100)
// mainDoughFlour = totalFlour − sourdoughFlour − preFermentFlour
function buildFlourTypeMap($recipe, $lookup = []) {
    $useSourdough  = !empty($recipe['useSourdough']);
    $usePreFerment = !empty($recipe['usePreFerment']);
    $sdHydration   = (float)($recipe['sourdoughHydration']  ?? 100);
    $pfHydration   = (float)($recipe['preFermentHydration'] ?? 100);
    $sdFraction    = $useSourdough  ? ($recipe['sourdoughPct']  ?? 0) / 100 / (1 + $sdHydration / 100) : 0;
    $pfFraction    = $usePreFerment ? ($recipe['preFermentPct'] ?? 0) / 100 / (1 + $pfHydration / 100) : 0;
    $mdFraction    = 1 - $sdFraction - $pfFraction;

    $sections = [
        ['key' => 'sourdoughGrains',  'fraction' => $sdFraction, 'active' => $useSourdough],
        ['key' => 'preFermentGrains', 'fraction' => $pfFraction, 'active' => $usePreFerment],
        ['key' => 'mainDoughGrains',  'fraction' => $mdFraction, 'active' => true],
    ];

    $typeMap = [];
    foreach ($sections as $section) {
        if (!$section['active']) continue;
        foreach ($recipe[$section['key']] ?? [] as $grain) {
            $pct = (float)($grain['pct'] ?? 0);
            if ($pct <= 0) continue;
            $t      = $grain['type'] ?? '';
            $mapKey = is_numeric($t) ? 'id_' . (int)$t : $t;
            if (!isset($typeMap[$mapKey])) {
                $typeMap[$mapKey] = ['type' => $t, 'totalPct' => 0, 'isWhole' => grainIsWhole($t, $lookup)];
            }
            $typeMap[$mapKey]['totalPct'] += $pct * $section['fraction'];
        }
    }
    return $typeMap;
}

function computeIngredientList($recipe, $lookup = []) {
    $yeastNames = ['fresh_yeast' => 'verse gist', 'instant_yeast' => 'gist', 'sourdough_culture' => 'desemcultuur'];

    $typeMap = buildFlourTypeMap($recipe, $lookup);
    $grains  = [];
    foreach ($typeMap as $e) {
        if ($e['totalPct'] > 0) $grains[] = ['name' => grainDisplayName($e['type'], $lookup), 'amount' => $e['totalPct']];
    }
    usort($grains, fn($a, $b) => $b['amount'] <=> $a['amount']);

    $othersMap = [
        'water' => (float)($recipe['hydration'] ?? 65),
        'zout'  => (float)($recipe['saltPct']   ?? 2.6),
    ];
    if (!empty($recipe['useYeast'])) {
        $yn = $yeastNames[$recipe['yeastType'] ?? 'instant_yeast'] ?? 'gist';
        $othersMap[$yn] = ($othersMap[$yn] ?? 0) + (float)($recipe['yeastPct'] ?? 1);
    }
    foreach (array_merge($recipe['mixins'] ?? [], $recipe['toppings'] ?? []) as $item) {
        if (!empty($item['ingredient']) && ($item['pct'] ?? 0) > 0) {
            $key = strtolower($item['ingredient']);
            $othersMap[$key] = ($othersMap[$key] ?? 0) + (float)$item['pct'];
        }
    }
    $others = [];
    foreach ($othersMap as $name => $amount) {
        $others[] = ['name' => $name, 'amount' => $amount];
    }
    usort($others, fn($a, $b) => $b['amount'] <=> $a['amount']);

    $names = array_column(array_merge($grains, $others), 'name');
    return !empty($names) ? implode(', ', $names) : null;
}

function computeRecipeDetails($recipe, $lookup = []) {
    $typeMap = buildFlourTypeMap($recipe, $lookup);
    $active  = array_filter($typeMap, fn($e) => $e['totalPct'] > 0);
    if (empty($active)) return ['volkoren_pct' => 0, 'grains' => []];

    // Largest remainder method: round grain percentages to integers summing to exactly 100
    $total      = array_sum(array_column($active, 'totalPct'));
    $normalized = array_map(fn($e) => $e['totalPct'] / $total * 100, $active);
    $floored    = array_map('floor', $normalized);
    $remaining  = 100 - (int)array_sum($floored);
    $fractions  = array_map(fn($v) => $v - floor($v), $normalized);
    arsort($fractions);
    foreach (array_keys($fractions) as $key) {
        if ($remaining-- <= 0) break;
        $floored[$key]++;
    }

    // Derive volkoren_pct from the rounded values so it is consistent with the display
    $grains   = [];
    $wholePct = 0;
    foreach ($active as $mapKey => $e) {
        $pct = (int)$floored[$mapKey];
        $grains[] = ['name' => grainDisplayName($e['type'], $lookup, true), 'pct' => $pct];
        if ($e['isWhole']) $wholePct += $pct;
    }
    usort($grains, fn($a, $b) => $b['pct'] <=> $a['pct']);

    return [
        'volkoren_pct' => $wholePct,
        'grains'       => $grains,
    ];
}

// ── Shared utilities ─────────────────────────────────────────────────────────

function getBedrijfsGegevens($pdo) {
    $velden = ['bedrijf_naam', 'bedrijf_contactpersoon', 'bedrijf_adres', 'bedrijf_postcode', 'bedrijf_plaats', 'bedrijf_telefoon', 'bedrijf_email', 'bedrijf_kvk', 'bedrijf_btw_id'];
    $gegevens = [];
    foreach ($velden as $veld) {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$veld]);
        $gegevens[$veld] = $stmt->fetchColumn() ?: '';
    }
    return $gegevens;
}

function euro($amount) {
    return chr(128) . ' ' . number_format($amount, 2, ',', '.');
}
