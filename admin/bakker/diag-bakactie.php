<?php
require_once '../config.php';
requireLogin();
header('Content-Type: text/plain; charset=utf-8');

$date = $_GET['date'] ?? '2026-04-23';

$stmtVd = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
$stmtVd->execute();
$voorbereidingDagen = (int)($stmtVd->fetchColumn() ?: 3);
echo "voorbereidingDagen: $voorbereidingDagen\n\n";

$bereidingDate = new DateTime($date);
$windowEnd = clone $bereidingDate;
$windowEnd->modify('+7 days');

$stmt = $pdo->prepare("
    SELECT bo.id as order_id, bo.delivery_date,
        boi.product_name, boi.quantity,
        pv.recipe_id, pv.gewicht as variant_weight,
        br.name as recipe_name, br.recipe_data, br.dough_type_id,
        COALESCE(dt.name, 'Geen deegsoort') as dough_type_name,
        dt.recipe_data as dough_type_recipe_data
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

echo count($allItems) . " total items fetched from DB\n\n";

foreach ($allItems as $item) {
    $deliveryDt   = new DateTime($item['delivery_date']);
    $doughTypeName = $item['dough_type_name'];
    $hasRecipeId   = !empty($item['recipe_id']);
    $hasRecipeData = !empty($item['recipe_data']);
    $methodDaysCount = $voorbereidingDagen;

    if ($hasRecipeId && $hasRecipeData) {
        $recipeData = json_decode($item['recipe_data'], true);
        if (!empty($recipeData['methodDays'])) {
            $methodDaysCount = count($recipeData['methodDays']);
            $source = 'recipe.methodDays';
        } elseif (!empty($item['dough_type_recipe_data'])) {
            $dtData = json_decode($item['dough_type_recipe_data'], true);
            if (!empty($dtData['methodDays'])) {
                $methodDaysCount = count($dtData['methodDays']);
                $source = 'doughtype.methodDays';
            } else {
                $source = 'fallback (no methodDays in either)';
            }
        } else {
            $source = 'fallback (no dough_type_recipe_data)';
        }
    } else {
        $source = 'no recipe';
    }

    $prepStart = clone $deliveryDt;
    $prepStart->modify('-' . ($methodDaysCount - 1) . ' days');
    $inWindow = ($bereidingDate >= $prepStart && $bereidingDate <= $deliveryDt);

    echo "{$doughTypeName} | {$item['product_name']} x{$item['quantity']}"
       . " | recipe_id=" . ($item['recipe_id'] ?: 'NULL')
       . " | methodDays=$methodDaysCount ($source)"
       . " | delivery={$item['delivery_date']}"
       . " | prepStart=" . $prepStart->format('Y-m-d')
       . " | inWindow=" . ($inWindow ? 'YES' : 'NO')
       . "\n";
}
