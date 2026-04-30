<?php

function consumeIngredient($pdo, $ingredientId, $quantityGrams, $orderId = null, $bakactieId = null, $movementId = null) {
    $ingStmt = $pdo->prepare("SELECT use_verpakkingen FROM ingredients WHERE id = ?");
    $ingStmt->execute([$ingredientId]);
    $useVerpakkingen = (bool)($ingStmt->fetch()['use_verpakkingen'] ?? false);

    $orderClause = $useVerpakkingen
        ? "ORDER BY is_open DESC, COALESCE(thd_date, '9999-12-31') ASC, purchase_date ASC"
        : "ORDER BY COALESCE(thd_date, '9999-12-31') ASC, purchase_date ASC";

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT id, quantity_remaining, price_per_kg
            FROM ingredient_batches
            WHERE ingredient_id = ? AND quantity_remaining > 0
            $orderClause
            FOR UPDATE
        ");
        $stmt->execute([$ingredientId]);
        $batches = $stmt->fetchAll();

        $remaining = $quantityGrams;
        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $useFromBatch = min($remaining, floatval($batch['quantity_remaining']));
            $costForBatch = ($useFromBatch / 1000) * floatval($batch['price_per_kg']);
            $newRemaining = floatval($batch['quantity_remaining']) - $useFromBatch;

            $pdo->prepare("UPDATE ingredient_batches SET quantity_remaining = quantity_remaining - ?, is_open = ? WHERE id = ?")
                ->execute([$useFromBatch, $newRemaining > 0 ? 1 : 0, $batch['id']]);

            $pdo->prepare("INSERT INTO inventory_consumption (ingredient_id, batch_id, order_id, bakactie_id, movement_id, quantity_consumed, cost) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$ingredientId, $batch['id'], $orderId, $bakactieId, $movementId, $useFromBatch, $costForBatch]);

            $remaining -= $useFromBatch;
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('consumeIngredient fout: ' . $e->getMessage());
    }
}

function calculateSourdoughIngredients($recipeData, $totalWeightGrams, $pdo, $brandSelections = []) {
    $sdPct = (float)($recipeData['sourdoughPct'] ?? 0);
    if (empty($recipeData['useSourdough']) || $sdPct <= 0) return [];

    $hydration = (float)($recipeData['hydration'] ?? 62);
    $saltPct   = (float)($recipeData['saltPct']   ?? 2.6);
    $sdHyd     = (float)($recipeData['sourdoughHydration'] ?? 100);

    $totalFlour = $totalWeightGrams / (1 + $hydration / 100 + $saltPct / 100);
    $sdFlour    = ($totalFlour * ($sdPct / 100)) / (1 + $sdHyd / 100);
    if ($sdFlour <= 0) return [];

    // Flour type comes from recipe (first mainDoughGrain); brand from baker's 'sourdough' selection
    $grainType = $recipeData['mainDoughGrains'][0]['type'] ?? 'wheat_white';
    $ingId = !empty($brandSelections['sourdough'])
        ? (int)$brandSelections['sourdough']
        : _findIngredientId($pdo, $grainType, []);

    if (!$ingId) return [];
    return [['ingredient_id' => $ingId, 'quantity' => round($sdFlour, 1)]];
}

function calculateBakactieIngredients($recipeData, $totalWeightGrams, $pdo, $brandSelections = []) {
    $ingredients = [];
    $hydration = (float)($recipeData['hydration'] ?? 62);
    $saltPct   = (float)($recipeData['saltPct']   ?? 2.6);

    $totalFlour = $totalWeightGrams / (1 + $hydration / 100 + $saltPct / 100);
    $saltWeight = $totalFlour * ($saltPct / 100);

    // Subtract sourdough flour so mainDoughGrain percentages apply to the correct base
    $sdPct   = (float)($recipeData['sourdoughPct'] ?? 0);
    $sdHyd   = (float)($recipeData['sourdoughHydration'] ?? 100);
    $sdFlour = (!empty($recipeData['useSourdough']) && $sdPct > 0)
        ? ($totalFlour * ($sdPct / 100)) / (1 + $sdHyd / 100)
        : 0;
    $pfPct   = (float)($recipeData['preFermentPct'] ?? 0);
    $pfHyd   = (float)($recipeData['preFermentHydration'] ?? 100);
    $pfFlour = (!empty($recipeData['usePreFerment']) && $pfPct > 0)
        ? ($totalFlour * ($pfPct / 100)) / (1 + $pfHyd / 100)
        : 0;
    $mainFlour = $totalFlour - $sdFlour - $pfFlour;

    foreach ($recipeData['mainDoughGrains'] ?? [['type' => 'wheat_white', 'pct' => 100]] as $grain) {
        $grainWeight = $mainFlour * (($grain['pct'] ?? 0) / 100);
        if ($grainWeight > 0) {
            $ingId = _findIngredientId($pdo, $grain['type'], $brandSelections);
            if ($ingId) $ingredients[] = ['ingredient_id' => $ingId, 'quantity' => $grainWeight];
        }
    }

    $saltId = !empty($brandSelections['Zout'])
        ? (int)$brandSelections['Zout']
        : _findIngredientByName($pdo, 'Zout');
    if ($saltId) $ingredients[] = ['ingredient_id' => $saltId, 'quantity' => $saltWeight];

    if (!empty($recipeData['useYeast']) && !empty($recipeData['yeastType'])) {
        $yeastPct    = $recipeData['yeastPct'] ?? 1.3;
        $yeastWeight = $totalFlour * ($yeastPct / 100);
        $yeastId     = _findIngredientId($pdo, $recipeData['yeastType'], $brandSelections);
        if ($yeastId) $ingredients[] = ['ingredient_id' => $yeastId, 'quantity' => $yeastWeight];
    }

    foreach ($recipeData['mixins'] ?? [] as $mixin) {
        if (!empty($mixin['ingredient']) && ($mixin['pct'] ?? 0) > 0) {
            $mixinWeight = $totalFlour * ($mixin['pct'] / 100);
            $mixinId = !empty($brandSelections[$mixin['ingredient']])
                ? (int)$brandSelections[$mixin['ingredient']]
                : _findIngredientByName($pdo, $mixin['ingredient']);
            if ($mixinId) $ingredients[] = ['ingredient_id' => $mixinId, 'quantity' => $mixinWeight];
        }
    }

    foreach ($recipeData['toppings'] ?? [] as $topping) {
        if (!empty($topping['ingredient']) && ($topping['pct'] ?? 0) > 0) {
            $toppingWeight = $totalWeightGrams * ($topping['pct'] / 100);
            $toppingId = !empty($brandSelections[$topping['ingredient']])
                ? (int)$brandSelections[$topping['ingredient']]
                : _findIngredientByName($pdo, $topping['ingredient']);
            if ($toppingId) $ingredients[] = ['ingredient_id' => $toppingId, 'quantity' => $toppingWeight];
        }
    }

    return $ingredients;
}

function _buildIngredientPreview($pdo, $ingredients) {
    $preview = [];
    foreach ($ingredients as $ing) {
        if ($ing['quantity'] <= 0) continue;

        $ingRow = $pdo->prepare("
            SELECT CASE WHEN p.name IS NOT NULL THEN p.name ELSE i.name END as group_name,
                   i.brand_name, i.use_verpakkingen
            FROM ingredients i
            LEFT JOIN ingredients p ON p.id = i.parent_id
            WHERE i.id = ?
        ");
        $ingRow->execute([$ing['ingredient_id']]);
        $ingInfo  = $ingRow->fetch();
        $ingName  = $ingInfo['group_name'] ?? 'Onbekend';
        $brandLbl = $ingInfo['brand_name'] ?: null;
        $useV     = (bool)($ingInfo['use_verpakkingen'] ?? false);

        $orderClause = $useV
            ? "ORDER BY b.is_open DESC, COALESCE(b.thd_date, '9999-12-31') ASC, b.purchase_date ASC"
            : "ORDER BY COALESCE(b.thd_date, '9999-12-31') ASC, b.purchase_date ASC";

        $batchStmt = $pdo->prepare("
            SELECT b.id, b.quantity_remaining, b.thd_date, b.is_open,
                   COALESCE(NULLIF(i.brand_name, ''), p.name, i.name) as display_name
            FROM ingredient_batches b
            JOIN ingredients i ON i.id = b.ingredient_id
            LEFT JOIN ingredients p ON p.id = i.parent_id
            WHERE b.ingredient_id = ? AND b.quantity_remaining > 0
            $orderClause
        ");
        $batchStmt->execute([$ing['ingredient_id']]);
        $batches = $batchStmt->fetchAll();

        $needed         = (float)$ing['quantity'];
        $totalAvailable = array_sum(array_column($batches, 'quantity_remaining'));
        $breakdown      = [];
        foreach ($batches as $batch) {
            if ($needed <= 0) break;
            $fromBatch = min($needed, floatval($batch['quantity_remaining']));
            $breakdown[] = [
                'display_name'      => $batch['display_name'],
                'available_g'       => floatval($batch['quantity_remaining']),
                'from_batch_g'      => $fromBatch,
                'remaining_after_g' => floatval($batch['quantity_remaining']) - $fromBatch,
                'thd_date'          => $batch['thd_date'],
                'is_open'           => (bool)$batch['is_open'],
            ];
            $needed -= $fromBatch;
        }

        $preview[] = [
            'ingredient_name' => $ingName . ($brandLbl ? ' — ' . $brandLbl : ''),
            'needed_g'        => (float)$ing['quantity'],
            'available_g'     => $totalAvailable,
            'shortage_g'      => max(0.0, $needed),
            'batches'         => $breakdown,
        ];
    }
    return $preview;
}

function previewBakactieInventory($pdo, $bakactieId) {
    $ba = $pdo->prepare("SELECT locked_recipe_data, total_weight_g, notes_data, skip_inventory, inventory_consumed FROM bak_acties WHERE id = ?");
    $ba->execute([$bakactieId]);
    $row = $ba->fetch();
    if (!$row) return null;
    if ($row['inventory_consumed']) return ['already_consumed' => true];
    if ($row['skip_inventory'])    return ['skip_inventory'    => true];

    $recipeData      = $row['locked_recipe_data'] ? json_decode($row['locked_recipe_data'], true) : null;
    $nd              = $row['notes_data'] ? json_decode($row['notes_data'], true) : [];
    $brandSelections = $nd['ingredient_brands'] ?? [];

    if (!$recipeData || !$row['total_weight_g']) return ['no_recipe' => true];

    $ingredients = calculateBakactieIngredients($recipeData, (float)$row['total_weight_g'], $pdo, $brandSelections);
    return ['ingredients' => _buildIngredientPreview($pdo, $ingredients)];
}

function previewSourdoughInventory($pdo, $bakactieId) {
    $ba = $pdo->prepare("SELECT locked_recipe_data, total_weight_g, notes_data, sourdough_consumed FROM bak_acties WHERE id = ?");
    $ba->execute([$bakactieId]);
    $row = $ba->fetch();
    if (!$row) return null;
    if ($row['sourdough_consumed']) return ['already_consumed' => true];

    $recipeData      = $row['locked_recipe_data'] ? json_decode($row['locked_recipe_data'], true) : null;
    $nd              = $row['notes_data'] ? json_decode($row['notes_data'], true) : [];
    $brandSelections = $nd['ingredient_brands'] ?? [];

    if (!$recipeData || !$row['total_weight_g']) return ['no_recipe' => true];
    if (empty($recipeData['useSourdough']) || empty($recipeData['sourdoughPct'])) return ['no_sourdough' => true];

    $ingredients = calculateSourdoughIngredients($recipeData, (float)$row['total_weight_g'], $pdo, $brandSelections);
    if (empty($ingredients)) return ['no_sourdough' => true];
    return ['ingredients' => _buildIngredientPreview($pdo, $ingredients)];
}

function _findIngredientId($pdo, $typeId, $brandSelections = []) {
    // Numeric IDs are direct ingredient row IDs (from the DB-backed grain selector).
    // Look up the group name and delegate to _findIngredientByName so parent→child
    // resolution and brand selection work the same as for string-key types.
    if (is_numeric($typeId) && (int)$typeId > 0) {
        $typeId = (int)$typeId;
        static $nameCache = [];
        if (!isset($nameCache[$typeId])) {
            $r = $pdo->prepare("SELECT COALESCE(p.name, i.name) as name FROM ingredients i LEFT JOIN ingredients p ON p.id = i.parent_id WHERE i.id = ?");
            $r->execute([$typeId]);
            $nameCache[$typeId] = ($r->fetch()['name'] ?? null);
        }
        $name = $nameCache[$typeId];
        if (!$name) return null;
        if (!empty($brandSelections[$name])) return (int)$brandSelections[$name];
        return _findIngredientByName($pdo, $name);
    }

    // String keys (legacy recipe format)
    $grainMap = [
        'wheat_white'   => 'Tarwebloem',     'wheat_whole'   => 'Tarwemeel',
        'spelt_white'   => 'Speltbloem',     'spelt_whole'   => 'Speltvollekorn',
        'rye_white'     => 'Roggebloem',     'rye_whole'     => 'Roggemeel',
        'durum'         => 'Durumbloem',     'emmer'         => 'Emmer',
        'einkorn'       => 'Einkorn',        'buckwheat'     => 'Boekweit',
        'rice'          => 'Rijstmeel',      'barley'        => 'Gerst',
        'teff'          => 'Teff',
        'fresh_yeast'   => 'Verse gist',     'instant_yeast' => 'Instant gist',
    ];
    $name = $grainMap[$typeId] ?? $typeId;
    if (!empty($brandSelections[$name])) return (int)$brandSelections[$name];
    return _findIngredientByName($pdo, $name);
}

function _findIngredientByName($pdo, $name) {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];
    // First: find any ingredient (child or direct) matching this name that actually has stock
    $stmt = $pdo->prepare("
        SELECT i.id FROM ingredients i
        LEFT JOIN ingredients p ON p.id = i.parent_id
        JOIN ingredient_batches b ON b.ingredient_id = i.id AND b.quantity_remaining > 0
        WHERE i.is_active = 1 AND (i.name = ? OR p.name = ?)
        ORDER BY (i.parent_id IS NOT NULL) DESC, i.id ASC
        LIMIT 1
    ");
    $stmt->execute([$name, $name]);
    $id = $stmt->fetchColumn() ?: null;
    if (!$id) {
        // No stock found; prefer children of a group with this name
        $stmt = $pdo->prepare("
            SELECT c.id FROM ingredients p
            JOIN ingredients c ON c.parent_id = p.id AND c.is_active = 1
            WHERE p.name = ? AND p.parent_id IS NULL AND p.is_active = 1
            ORDER BY c.id ASC LIMIT 1
        ");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn() ?: null;
    }
    if (!$id) {
        // Absolute fallback: direct name match, children before parents
        $stmt = $pdo->prepare("SELECT id FROM ingredients WHERE name = ? AND is_active = 1 ORDER BY (parent_id IS NULL) ASC, id ASC LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn() ?: null;
    }
    $cache[$name] = $id;
    return $id;
}
