<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../lib/allergen-trace.php';

$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bestel_wijzig_deadline_uren'");
$stmt->execute();
$deadlineHours = intval($stmt->fetchColumn() ?: 48);

$cutoffTime = date('Y-m-d H:i:s', strtotime("+{$deadlineHours} hours"));

$stmt = $pdo->prepare("
    SELECT bo.id, bo.delivery_date, oi.product_id, oi.variant_id, oi.quantity,
           p.naam as product_naam, pv.recipe_id, pv.gewicht
    FROM business_orders bo
    JOIN business_order_items oi ON bo.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN product_variants pv ON oi.variant_id = pv.id
    WHERE bo.is_cancelled = 0
    AND bo.inventory_consumed = 0
    AND bo.delivery_date <= ?
    AND bo.delivery_status IN ('geplaatst', 'wordt_bereid')
    ORDER BY bo.delivery_date ASC
");
$stmt->execute([$cutoffTime]);
$orderItems = $stmt->fetchAll();

if (empty($orderItems)) {
    echo "Geen orders om te verwerken.\n";
    exit;
}

$ordersToMark = [];
$markedOrders = [];
$consumedIngredientIds = [];

foreach ($orderItems as $item) {
    $orderId = $item['id'];
    $ordersToMark[$orderId] = true;

    if (!$item['recipe_id']) {
        continue;
    }
    
    $stmt = $pdo->prepare("SELECT recipe_data FROM baker_recipes WHERE id = ?");
    $stmt->execute([$item['recipe_id']]);
    $recipeRow = $stmt->fetch();
    
    if (!$recipeRow) {
        continue;
    }
    
    $recipeData = json_decode($recipeRow['recipe_data'], true);
    if (!$recipeData) {
        continue;
    }
    
    $weightPerPiece = $item['gewicht'] ?: 300;
    $totalWeight = $weightPerPiece * $item['quantity'];
    
    $ingredients = calculateRecipeIngredients($recipeData, $totalWeight, $pdo);
    
    foreach ($ingredients as $ing) {
        if ($ing['quantity'] > 0) {
            consumeIngredient($pdo, $ing['ingredient_id'], $ing['quantity'], $orderId);
            $consumedIngredientIds[] = $ing['ingredient_id'];
        }
    }
    
    echo "Order #{$orderId}: {$item['quantity']}x {$item['product_naam']} ({$totalWeight}g) - voorraad afgeschreven\n";

    // Mark order immediately after processing to prevent double-consumption on partial failure
    if (!isset($markedOrders[$orderId])) {
        $stmt = $pdo->prepare("UPDATE business_orders SET inventory_consumed = 1 WHERE id = ?");
        $stmt->execute([$orderId]);
        $markedOrders[$orderId] = true;
    }
}

// Update allergen trace status for all consumed ingredients
$consumedIngredientIds = array_unique($consumedIngredientIds);
foreach ($consumedIngredientIds as $cIngId) {
    updateAllergenTraceStatus($pdo, $cIngId);
}
autoClearDepletedAllergens($pdo);

echo "Klaar. " . count($ordersToMark) . " orders verwerkt.\n";

function calculateRecipeIngredients($recipeData, $totalWeightGrams, $pdo) {
    $ingredients = [];
    
    $hydration = $recipeData['hydration'] ?? 62;
    $saltPct = $recipeData['saltPct'] ?? 2.6;
    
    $totalFlour = $totalWeightGrams / (1 + $hydration / 100 + $saltPct / 100);
    $totalWater = $totalFlour * ($hydration / 100);
    $saltWeight = $totalFlour * ($saltPct / 100);
    
    $mainDoughGrains = $recipeData['mainDoughGrains'] ?? [['type' => 'wheat_white', 'pct' => 100]];
    foreach ($mainDoughGrains as $grain) {
        $grainWeight = $totalFlour * (($grain['pct'] ?? 0) / 100);
        if ($grainWeight > 0) {
            $ingId = findIngredientId($pdo, $grain['type']);
            if ($ingId) {
                $ingredients[] = ['ingredient_id' => $ingId, 'quantity' => $grainWeight];
            }
        }
    }
    
    $saltId = findIngredientByName($pdo, 'Zout');
    if ($saltId) {
        $ingredients[] = ['ingredient_id' => $saltId, 'quantity' => $saltWeight];
    }
    
    if (!empty($recipeData['useYeast']) && !empty($recipeData['yeastType'])) {
        $yeastPct = $recipeData['yeastPct'] ?? 1.3;
        $yeastWeight = $totalFlour * ($yeastPct / 100);
        $yeastId = findIngredientId($pdo, $recipeData['yeastType']);
        if ($yeastId) {
            $ingredients[] = ['ingredient_id' => $yeastId, 'quantity' => $yeastWeight];
        }
    }
    
    $mixins = $recipeData['mixins'] ?? [];
    foreach ($mixins as $mixin) {
        if (!empty($mixin['ingredient']) && ($mixin['pct'] ?? 0) > 0) {
            $mixinWeight = $totalFlour * ($mixin['pct'] / 100);
            $mixinId = findIngredientByName($pdo, $mixin['ingredient']);
            if ($mixinId) {
                $ingredients[] = ['ingredient_id' => $mixinId, 'quantity' => $mixinWeight];
            }
        }
    }
    
    $toppings = $recipeData['toppings'] ?? [];
    foreach ($toppings as $topping) {
        if (!empty($topping['ingredient']) && ($topping['pct'] ?? 0) > 0) {
            $toppingWeight = $totalWeightGrams * ($topping['pct'] / 100);
            $toppingId = findIngredientByName($pdo, $topping['ingredient']);
            if ($toppingId) {
                $ingredients[] = ['ingredient_id' => $toppingId, 'quantity' => $toppingWeight];
            }
        }
    }
    
    return $ingredients;
}

function findIngredientId($pdo, $typeId) {
    $grainMap = [
        'wheat_white' => 'Tarwe wit',
        'wheat_whole' => 'Tarwe volkoren',
        'spelt_white' => 'Spelt wit',
        'spelt_whole' => 'Spelt volkoren',
        'durum' => 'Durum',
        'emmer' => 'Emmer',
        'rye_white' => 'Rogge wit',
        'rye_whole' => 'Rogge volkoren',
        'einkorn' => 'Einkorn',
        'buckwheat' => 'Boekweit',
        'rice' => 'Rijstmeel',
        'barley' => 'Gerst',
        'teff' => 'Teff',
        'fresh_yeast' => 'Verse gist',
        'instant_yeast' => 'Instant gist',
    ];
    
    $name = $grainMap[$typeId] ?? null;
    if (!$name) {
        return findIngredientByName($pdo, $typeId);
    }
    
    return findIngredientByName($pdo, $name);
}

function findIngredientByName($pdo, $name) {
    static $cache = [];
    
    if (isset($cache[$name])) {
        return $cache[$name];
    }
    
    $stmt = $pdo->prepare("SELECT id FROM ingredients WHERE name = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    
    $cache[$name] = $id ?: null;
    return $cache[$name];
}

function consumeIngredient($pdo, $ingredientId, $quantityGrams, $orderId = null) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT id, quantity_remaining, price_per_kg
            FROM ingredient_batches
            WHERE ingredient_id = ? AND quantity_remaining > 0
            ORDER BY purchase_date ASC
            FOR UPDATE
        ");
        $stmt->execute([$ingredientId]);
        $batches = $stmt->fetchAll();

        $remaining = $quantityGrams;
        $totalCost = 0;

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;

            $useFromBatch = min($remaining, $batch['quantity_remaining']);
            $costForBatch = ($useFromBatch / 1000) * $batch['price_per_kg'];

            $stmt = $pdo->prepare("UPDATE ingredient_batches SET quantity_remaining = quantity_remaining - ? WHERE id = ?");
            $stmt->execute([$useFromBatch, $batch['id']]);

            $stmt = $pdo->prepare("
                INSERT INTO inventory_consumption (ingredient_id, batch_id, order_id, quantity_consumed, cost)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ingredientId, $batch['id'], $orderId, $useFromBatch, $costForBatch]);

            $totalCost += $costForBatch;
            $remaining -= $useFromBatch;
        }

        $pdo->commit();
        return ['consumed' => $quantityGrams - $remaining, 'cost' => $totalCost, 'shortage' => $remaining];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
