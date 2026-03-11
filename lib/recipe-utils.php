<?php
/**
 * Recipe / flour composition helpers.
 *
 * Used by baker tools for ingredient lists, grain composition, and volkoren percentages.
 */

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

function computeIngredientList($recipe, $lookup = [], $biologischNames = [], $allergeenNames = []) {
    $yeastNames = ['fresh_yeast' => 'verse gist', 'instant_yeast' => 'gist', 'sourdough_culture' => 'desemcultuur'];

    $typeMap = buildFlourTypeMap($recipe, $lookup);
    $grains  = [];
    foreach ($typeMap as $e) {
        if ($e['totalPct'] > 0) {
            $name = grainDisplayName($e['type'], $lookup);
            $bio = false;
            $allergeen = false;
            $allergeenNaam = null;
            if (is_numeric($e['type']) && isset($lookup[(int)$e['type']])) {
                $bio = (bool)($lookup[(int)$e['type']]['is_biologisch'] ?? false);
                $allergeen = (bool)($lookup[(int)$e['type']]['is_allergeen'] ?? false);
                $allergeenNaam = $lookup[(int)$e['type']]['allergeen_naam'] ?? null;
            } else {
                if (isset($biologischNames[strtolower($name)])) $bio = true;
                if (array_key_exists(strtolower($name), $allergeenNames)) {
                    $allergeen = true;
                    $allergeenNaam = $allergeenNames[strtolower($name)];
                }
            }
            if ($bio) $name .= '*';
            $grains[] = ['name' => $name, 'amount' => $e['totalPct'], 'allergeen' => $allergeen, 'allergeen_naam' => $allergeenNaam];
        }
    }
    usort($grains, fn($a, $b) => $b['amount'] <=> $a['amount']);

    $othersMap = [
        'water' => (float)($recipe['hydration'] ?? 65),
        'zout'  => (float)($recipe['saltPct']   ?? 2.6),
    ];
    $bioOthers = [];
    $allergeenOthers = [];
    if (isset($biologischNames['water'])) $bioOthers['water'] = true;
    if (isset($biologischNames['zout'])) $bioOthers['zout'] = true;
    if (array_key_exists('water', $allergeenNames)) $allergeenOthers['water'] = $allergeenNames['water'];
    if (array_key_exists('zout', $allergeenNames)) $allergeenOthers['zout'] = $allergeenNames['zout'];

    if (!empty($recipe['useYeast'])) {
        $yn = $yeastNames[$recipe['yeastType'] ?? 'instant_yeast'] ?? 'gist';
        $othersMap[$yn] = ($othersMap[$yn] ?? 0) + (float)($recipe['yeastPct'] ?? 1);
        if (isset($biologischNames[strtolower($yn)])) $bioOthers[$yn] = true;
        if (array_key_exists(strtolower($yn), $allergeenNames)) $allergeenOthers[$yn] = $allergeenNames[strtolower($yn)];
    }
    foreach (array_merge($recipe['mixins'] ?? [], $recipe['toppings'] ?? []) as $item) {
        if (!empty($item['ingredient']) && ($item['pct'] ?? 0) > 0) {
            $key = strtolower($item['ingredient']);
            $othersMap[$key] = ($othersMap[$key] ?? 0) + (float)$item['pct'];
            if (isset($biologischNames[$key])) $bioOthers[$key] = true;
            if (array_key_exists($key, $allergeenNames)) $allergeenOthers[$key] = $allergeenNames[$key];
        }
    }
    $others = [];
    foreach ($othersMap as $name => $amount) {
        $displayName = $name;
        if (isset($bioOthers[$name])) $displayName .= '*';
        $allergeen = array_key_exists($name, $allergeenOthers);
        $allergeenNaam = $allergeenOthers[$name] ?? null;
        $others[] = ['name' => $displayName, 'amount' => $amount, 'allergeen' => $allergeen, 'allergeen_naam' => $allergeenNaam];
    }
    usort($others, fn($a, $b) => $b['amount'] <=> $a['amount']);

    $items = array_merge($grains, $others);
    $names = array_column($items, 'name');
    if (empty($names)) return null;

    return [
        'text'  => implode(', ', $names),
        'items' => array_map(fn($i) => ['name' => $i['name'], 'allergeen' => $i['allergeen'], 'allergeen_naam' => $i['allergeen_naam']], $items),
    ];
}

function computeRecipeDetails($recipe, $lookup = []) {
    $typeMap = buildFlourTypeMap($recipe, $lookup);
    $active  = array_filter($typeMap, fn($e) => $e['totalPct'] > 0);
    if (empty($active)) return ['volkoren_pct' => 0, 'grains' => []];

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
