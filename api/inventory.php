<?php
require_once 'cors.php';
require_once '../admin/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $action = $_GET['action'] ?? 'batches';

            if ($action === 'batches') {
                $ingredientId = $_GET['ingredient_id'] ?? null;
                $hideEmpty = isset($_GET['hide_empty']) && $_GET['hide_empty'] == '1';

                $where = "1=1";
                $params = [];

                if ($hideEmpty) {
                    $where .= " AND b.quantity_remaining > 0";
                }
                if ($ingredientId) {
                    $where .= " AND b.ingredient_id = ?";
                    $params[] = $ingredientId;
                }

                $sql = "SELECT b.*, i.name as ingredient_name, i.category, i.unit
                        FROM ingredient_batches b
                        JOIN ingredients i ON b.ingredient_id = i.id
                        WHERE $where
                        ORDER BY i.name, b.purchase_date ASC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $batches = $stmt->fetchAll();

                echo json_encode(['success' => true, 'batches' => $batches]);

            } elseif ($action === 'stock_summary') {
                $sql = "SELECT i.id, i.name, i.category, i.unit,
                        COALESCE(SUM(b.quantity_remaining), 0) as total_stock,
                        COUNT(b.id) as batch_count,
                        (SELECT price_per_kg FROM ingredient_batches
                         WHERE ingredient_id = i.id AND quantity_remaining > 0
                         ORDER BY purchase_date ASC LIMIT 1) as fifo_price_per_kg
                        FROM ingredients i
                        LEFT JOIN ingredient_batches b ON i.id = b.ingredient_id AND b.quantity_remaining > 0
                        WHERE i.is_active = 1
                        GROUP BY i.id
                        ORDER BY i.category, i.name";

                $stmt = $pdo->query($sql);
                $summary = $stmt->fetchAll();

                echo json_encode(['success' => true, 'summary' => $summary]);

            } elseif ($action === 'calculate_cost') {
                $ingredientId = $_GET['ingredient_id'] ?? null;
                $quantity = floatval($_GET['quantity'] ?? 0);

                if (!$ingredientId || $quantity <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'ingredient_id en quantity zijn verplicht']);
                    exit;
                }

                $result = calculateFifoCost($pdo, $ingredientId, $quantity);
                echo json_encode(['success' => true, 'cost_calculation' => $result]);

            } elseif ($action === 'history') {
                $ingredientId = $_GET['ingredient_id'] ?? null;
                $limit = intval($_GET['limit'] ?? 50);

                $where = "1=1";
                $params = [];

                if ($ingredientId) {
                    $where .= " AND c.ingredient_id = ?";
                    $params[] = $ingredientId;
                }

                $sql = "SELECT c.*, i.name as ingredient_name, b.price_per_kg
                        FROM inventory_consumption c
                        JOIN ingredients i ON c.ingredient_id = i.id
                        JOIN ingredient_batches b ON c.batch_id = b.id
                        WHERE $where
                        ORDER BY c.consumed_at DESC
                        LIMIT $limit";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $history = $stmt->fetchAll();

                echo json_encode(['success' => true, 'history' => $history]);

            } elseif ($action === 'consolidations') {
                $stmt = $pdo->query("
                    SELECT c.*, COUNT(ci.id) as item_count
                    FROM inventory_consolidations c
                    LEFT JOIN inventory_consolidation_items ci ON c.id = ci.consolidation_id
                    GROUP BY c.id
                    ORDER BY c.consolidation_date DESC
                    LIMIT 10
                ");
                $consolidations = $stmt->fetchAll();

                // Load items for each consolidation
                foreach ($consolidations as &$con) {
                    $stmt2 = $pdo->prepare("
                        SELECT ci.*, i.name as ingredient_name
                        FROM inventory_consolidation_items ci
                        JOIN ingredients i ON ci.ingredient_id = i.id
                        WHERE ci.consolidation_id = ?
                        ORDER BY i.name
                    ");
                    $stmt2->execute([$con['id']]);
                    $con['items'] = $stmt2->fetchAll();
                }
                unset($con);

                echo json_encode(['success' => true, 'consolidations' => $consolidations]);

            } elseif ($action === 'forecast') {
                $today = date('Y-m-d');
                $endDate = date('Y-m-d', strtotime('+14 days'));

                // Load all active ingredients for name/id matching
                $stmt = $pdo->query("SELECT id, name, category FROM ingredients WHERE is_active = 1");
                $allIngredients = $stmt->fetchAll();
                $ingredientsByName = [];
                $ingredientsById = [];
                foreach ($allIngredients as $ing) {
                    $ingredientsByName[strtolower($ing['name'])] = $ing;
                    $ingredientsById[$ing['id']] = $ing;
                }

                // Load current stock per ingredient
                $stmt = $pdo->query("
                    SELECT ingredient_id, COALESCE(SUM(quantity_remaining), 0) as available_grams
                    FROM ingredient_batches
                    WHERE quantity_remaining > 0
                    GROUP BY ingredient_id
                ");
                $stockMap = [];
                foreach ($stmt->fetchAll() as $row) {
                    $stockMap[$row['ingredient_id']] = floatval($row['available_grams']);
                }

                // Load future orders with recipe data
                $stmt = $pdo->prepare("
                    SELECT boi.product_name, boi.quantity,
                           p.recipe_id, COALESCE(p.standard_weight, 300) as standard_weight,
                           br.recipe_data
                    FROM business_orders bo
                    JOIN business_order_items boi ON bo.id = boi.order_id
                    LEFT JOIN products p ON LOWER(boi.product_name) = LOWER(p.naam)
                    LEFT JOIN baker_recipes br ON p.recipe_id = br.id
                    WHERE bo.delivery_date BETWEEN ? AND ?
                    AND bo.is_cancelled = 0
                ");
                $stmt->execute([$today, $endDate]);
                $orderItems = $stmt->fetchAll();

                // Accumulate needed grams per ingredient_id
                $needed = []; // ingredient_id => grams

                foreach ($orderItems as $item) {
                    if (empty($item['recipe_data'])) continue;
                    $recipeData = json_decode($item['recipe_data'], true);
                    if (!$recipeData) continue;

                    $qty = intval($item['quantity']);
                    $weight = floatval($item['standard_weight']);
                    $reqs = calculateForecastIngredients($recipeData, $qty, $qty * $weight, $ingredientsByName, $ingredientsById);

                    foreach ($reqs as $ingId => $grams) {
                        $needed[$ingId] = ($needed[$ingId] ?? 0) + $grams;
                    }
                }

                // Build result
                $result = [];
                foreach ($needed as $ingId => $neededGrams) {
                    if (!isset($ingredientsById[$ingId])) continue;
                    $available = $stockMap[$ingId] ?? 0;
                    $deficit = $available - $neededGrams;
                    if ($neededGrams > 0) {
                        $ratio = $available / $neededGrams;
                        $status = $ratio >= 1.2 ? 'ok' : ($ratio >= 1.0 ? 'laag' : 'tekort');
                    } else {
                        $status = 'ok';
                    }
                    $result[] = [
                        'ingredient_id' => $ingId,
                        'name' => $ingredientsById[$ingId]['name'],
                        'category' => $ingredientsById[$ingId]['category'],
                        'needed_grams' => round($neededGrams),
                        'available_grams' => round($available),
                        'deficit_grams' => round($deficit),
                        'status' => $status
                    ];
                }

                // Sort: tekort first, then laag, then ok
                usort($result, function($a, $b) {
                    $order = ['tekort' => 0, 'laag' => 1, 'ok' => 2];
                    return ($order[$a['status']] ?? 3) - ($order[$b['status']] ?? 3);
                });

                echo json_encode([
                    'success' => true,
                    'forecast' => $result,
                    'period' => ['from' => $today, 'to' => $endDate],
                    'orders_with_recipe' => count(array_filter($orderItems, fn($i) => !empty($i['recipe_data']))),
                    'orders_without_recipe' => count(array_filter($orderItems, fn($i) => empty($i['recipe_data'])))
                ]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            $action = $data['action'] ?? 'add_batch';

            if ($action === 'add_batch') {
                if (empty($data['ingredient_id']) || empty($data['quantity']) || empty($data['price_per_kg'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'ingredient_id, quantity en price_per_kg zijn verplicht']);
                    exit;
                }

                $quantityGrams = floatval($data['quantity']);
                if (isset($data['unit']) && $data['unit'] === 'kg') {
                    $quantityGrams *= 1000;
                }

                $thdDate = !empty($data['thd_date']) ? $data['thd_date'] : null;

                $stmt = $pdo->prepare("
                    INSERT INTO ingredient_batches (ingredient_id, quantity_purchased, quantity_remaining, price_per_kg, purchase_date, thd_date)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['ingredient_id'],
                    $quantityGrams,
                    $quantityGrams,
                    floatval($data['price_per_kg']),
                    $data['purchase_date'] ?? date('Y-m-d'),
                    $thdDate
                ]);

                $id = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Voorraad toegevoegd']);

            } elseif ($action === 'consume') {
                if (empty($data['ingredient_id']) || empty($data['quantity'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'ingredient_id en quantity zijn verplicht']);
                    exit;
                }

                $result = consumeIngredient(
                    $pdo,
                    $data['ingredient_id'],
                    floatval($data['quantity']),
                    $data['order_id'] ?? null
                );

                echo json_encode($result);

            } elseif ($action === 'adjust_batch') {
                if (empty($data['batch_id'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'batch_id is verplicht']);
                    exit;
                }

                $fields = [];
                $params = [];

                if (isset($data['quantity_remaining'])) {
                    $fields[] = "quantity_remaining = ?";
                    $params[] = floatval($data['quantity_remaining']);
                }
                if (isset($data['price_per_kg'])) {
                    $fields[] = "price_per_kg = ?";
                    $params[] = floatval($data['price_per_kg']);
                }
                if (array_key_exists('thd_date', $data)) {
                    $fields[] = "thd_date = ?";
                    $params[] = !empty($data['thd_date']) ? $data['thd_date'] : null;
                }

                if (empty($fields)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Geen velden om te updaten']);
                    exit;
                }

                $params[] = $data['batch_id'];
                $sql = "UPDATE ingredient_batches SET " . implode(", ", $fields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                echo json_encode(['success' => true, 'message' => 'Batch aangepast']);

            } elseif ($action === 'purge_batch') {
                if (empty($data['batch_id'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'batch_id is verplicht']);
                    exit;
                }

                $stmt = $pdo->prepare("SELECT id, ingredient_id, quantity_remaining FROM ingredient_batches WHERE id = ?");
                $stmt->execute([$data['batch_id']]);
                $batch = $stmt->fetch();

                if (!$batch) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Batch niet gevonden']);
                    exit;
                }

                $qtyRemaining = floatval($batch['quantity_remaining']);

                $pdo->beginTransaction();
                try {
                    if ($qtyRemaining > 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO inventory_consumption (ingredient_id, batch_id, order_id, reason, note, quantity_consumed, cost)
                            VALUES (?, ?, NULL, 'purge', ?, ?, ?)
                        ");
                        $note = $data['note'] ?? 'Weggegooid door gebruiker';
                        $cost = ($qtyRemaining / 1000) * floatval($batch['price_per_kg'] ?? 0);

                        // Get price from batch
                        $stmt2 = $pdo->prepare("SELECT price_per_kg FROM ingredient_batches WHERE id = ?");
                        $stmt2->execute([$data['batch_id']]);
                        $batchPrice = $stmt2->fetch()['price_per_kg'] ?? 0;
                        $cost = ($qtyRemaining / 1000) * floatval($batchPrice);

                        $stmt->execute([$batch['ingredient_id'], $batch['id'], $note, $qtyRemaining, $cost]);
                    }

                    $stmt = $pdo->prepare("UPDATE ingredient_batches SET quantity_remaining = 0 WHERE id = ?");
                    $stmt->execute([$data['batch_id']]);

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Batch weggegooid']);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

            } elseif ($action === 'consolidation') {
                if (empty($data['consolidation_date']) || empty($data['items'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'consolidation_date en items zijn verplicht']);
                    exit;
                }

                $pdo->beginTransaction();
                try {
                    // Create consolidation record
                    $stmt = $pdo->prepare("INSERT INTO inventory_consolidations (consolidation_date, notes) VALUES (?, ?)");
                    $stmt->execute([$data['consolidation_date'], $data['notes'] ?? null]);
                    $consolidationId = $pdo->lastInsertId();

                    foreach ($data['items'] as $item) {
                        $ingId = intval($item['ingredient_id']);
                        $countedGrams = floatval($item['counted_grams']);

                        // Get current expected stock
                        $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(quantity_remaining), 0) as total FROM ingredient_batches WHERE ingredient_id = ? AND quantity_remaining > 0");
                        $stmt2->execute([$ingId]);
                        $expectedGrams = floatval($stmt2->fetch()['total']);

                        $difference = $countedGrams - $expectedGrams;

                        // Save consolidation item
                        $stmt3 = $pdo->prepare("INSERT INTO inventory_consolidation_items (consolidation_id, ingredient_id, expected_grams, counted_grams) VALUES (?, ?, ?, ?)");
                        $stmt3->execute([$consolidationId, $ingId, $expectedGrams, $countedGrams]);

                        if ($difference < 0) {
                            // Derving: write off from top FIFO batch
                            $toDeduct = abs($difference);
                            $stmt4 = $pdo->prepare("
                                SELECT id, quantity_remaining, price_per_kg
                                FROM ingredient_batches
                                WHERE ingredient_id = ? AND quantity_remaining > 0
                                ORDER BY purchase_date ASC
                            ");
                            $stmt4->execute([$ingId]);
                            $batches = $stmt4->fetchAll();

                            foreach ($batches as $batch) {
                                if ($toDeduct <= 0) break;
                                $useFromBatch = min($toDeduct, floatval($batch['quantity_remaining']));
                                $cost = ($useFromBatch / 1000) * floatval($batch['price_per_kg']);

                                $stmt5 = $pdo->prepare("UPDATE ingredient_batches SET quantity_remaining = quantity_remaining - ? WHERE id = ?");
                                $stmt5->execute([$useFromBatch, $batch['id']]);

                                $stmt6 = $pdo->prepare("INSERT INTO inventory_consumption (ingredient_id, batch_id, order_id, reason, note, quantity_consumed, cost) VALUES (?, ?, NULL, 'consolidation', 'Derving bij consolidatie', ?, ?)");
                                $stmt6->execute([$ingId, $batch['id'], $useFromBatch, $cost]);

                                $toDeduct -= $useFromBatch;
                            }
                        } elseif ($difference > 0) {
                            // Overschot: voeg toe aan bestaande top batch of maak nieuw
                            $stmt4 = $pdo->prepare("
                                SELECT id, price_per_kg FROM ingredient_batches
                                WHERE ingredient_id = ? AND quantity_remaining > 0
                                ORDER BY purchase_date ASC LIMIT 1
                            ");
                            $stmt4->execute([$ingId]);
                            $topBatch = $stmt4->fetch();

                            if ($topBatch) {
                                $stmt5 = $pdo->prepare("UPDATE ingredient_batches SET quantity_remaining = quantity_remaining + ? WHERE id = ?");
                                $stmt5->execute([$difference, $topBatch['id']]);
                            } else {
                                // No existing batch, create new one with price 0
                                $stmt5 = $pdo->prepare("INSERT INTO ingredient_batches (ingredient_id, quantity_purchased, quantity_remaining, price_per_kg, purchase_date) VALUES (?, ?, ?, 0, ?)");
                                $stmt5->execute([$ingId, $difference, $difference, $data['consolidation_date']]);
                            }
                        }
                    }

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Consolidatie opgeslagen', 'id' => $consolidationId]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            break;

        case 'DELETE':
            $batchId = $_GET['batch_id'] ?? null;

            if (!$batchId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'batch_id is verplicht']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM ingredient_batches WHERE id = ?");
            $stmt->execute([$batchId]);

            echo json_encode(['success' => true, 'message' => 'Batch verwijderd']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

function calculateFifoCost($pdo, $ingredientId, $quantityGrams) {
    $stmt = $pdo->prepare("
        SELECT id, quantity_remaining, price_per_kg
        FROM ingredient_batches
        WHERE ingredient_id = ? AND quantity_remaining > 0
        ORDER BY purchase_date ASC
    ");
    $stmt->execute([$ingredientId]);
    $batches = $stmt->fetchAll();

    $remaining = $quantityGrams;
    $totalCost = 0;
    $breakdown = [];
    $insufficient = false;

    foreach ($batches as $batch) {
        if ($remaining <= 0) break;

        $useFromBatch = min($remaining, $batch['quantity_remaining']);
        $costForBatch = ($useFromBatch / 1000) * $batch['price_per_kg'];

        $breakdown[] = [
            'batch_id' => $batch['id'],
            'quantity_used' => $useFromBatch,
            'price_per_kg' => $batch['price_per_kg'],
            'cost' => $costForBatch
        ];

        $totalCost += $costForBatch;
        $remaining -= $useFromBatch;
    }

    if ($remaining > 0) {
        $insufficient = true;
        if (!empty($batches)) {
            $lastPrice = end($batches)['price_per_kg'];
            $extraCost = ($remaining / 1000) * $lastPrice;
            $breakdown[] = [
                'batch_id' => null,
                'quantity_used' => $remaining,
                'price_per_kg' => $lastPrice,
                'cost' => $extraCost,
                'note' => 'Onvoldoende voorraad - geschat op laatst bekende prijs'
            ];
            $totalCost += $extraCost;
        }
    }

    $avgPricePerKg = $quantityGrams > 0 ? ($totalCost / $quantityGrams) * 1000 : 0;

    return [
        'quantity_grams' => $quantityGrams,
        'total_cost' => round($totalCost, 4),
        'avg_price_per_kg' => round($avgPricePerKg, 4),
        'insufficient_stock' => $insufficient,
        'breakdown' => $breakdown
    ];
}

function consumeIngredient($pdo, $ingredientId, $quantityGrams, $orderId = null) {
    $stmt = $pdo->prepare("
        SELECT id, quantity_remaining, price_per_kg
        FROM ingredient_batches
        WHERE ingredient_id = ? AND quantity_remaining > 0
        ORDER BY purchase_date ASC
        FOR UPDATE
    ");
    $stmt->execute([$ingredientId]);
    $batches = $stmt->fetchAll();

    $pdo->beginTransaction();

    try {
        $remaining = $quantityGrams;
        $totalCost = 0;
        $consumptions = [];

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;

            $useFromBatch = min($remaining, $batch['quantity_remaining']);
            $costForBatch = ($useFromBatch / 1000) * $batch['price_per_kg'];

            $stmt = $pdo->prepare("UPDATE ingredient_batches SET quantity_remaining = quantity_remaining - ? WHERE id = ?");
            $stmt->execute([$useFromBatch, $batch['id']]);

            $stmt = $pdo->prepare("
                INSERT INTO inventory_consumption (ingredient_id, batch_id, order_id, reason, quantity_consumed, cost)
                VALUES (?, ?, ?, 'order', ?, ?)
            ");
            $stmt->execute([$ingredientId, $batch['id'], $orderId, $useFromBatch, $costForBatch]);

            $consumptions[] = [
                'batch_id' => $batch['id'],
                'quantity' => $useFromBatch,
                'cost' => $costForBatch
            ];

            $totalCost += $costForBatch;
            $remaining -= $useFromBatch;
        }

        $pdo->commit();

        return [
            'success' => true,
            'consumed' => $quantityGrams - $remaining,
            'total_cost' => round($totalCost, 4),
            'insufficient_stock' => $remaining > 0,
            'shortage' => $remaining > 0 ? $remaining : 0,
            'consumptions' => $consumptions
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function calculateForecastIngredients($recipeData, $totalQty, $totalWeight, $ingredientsByName, $ingredientsById) {
    if ($totalQty <= 0 || $totalWeight <= 0) return [];

    $hydration = $recipeData['hydration'] ?? 62;
    $saltPct = $recipeData['saltPct'] ?? 2.6;
    $totalDoughWeight = $totalWeight;
    $totalFlour = $totalDoughWeight / (1 + $hydration / 100 + $saltPct / 100);

    $result = []; // ingredient_id => grams

    $addIngredient = function($nameOrId, $grams) use (&$result, $ingredientsByName, $ingredientsById) {
        if ($grams <= 0) return;
        // Try numeric ID first
        if (is_numeric($nameOrId) && isset($ingredientsById[intval($nameOrId)])) {
            $id = intval($nameOrId);
            $result[$id] = ($result[$id] ?? 0) + $grams;
        } else {
            // Try name match
            $key = strtolower(trim((string)$nameOrId));
            if (isset($ingredientsByName[$key])) {
                $id = $ingredientsByName[$key]['id'];
                $result[$id] = ($result[$id] ?? 0) + $grams;
            }
        }
    };

    $mainFlour = $totalFlour;

    // Pre-ferment
    if (!empty($recipeData['usePreFerment']) && !empty($recipeData['preFermentPct'])) {
        $pfWeight = $totalFlour * ($recipeData['preFermentPct'] / 100);
        $pfHydration = $recipeData['preFermentHydration'] ?? 100;
        $pfFlour = $pfWeight / (1 + $pfHydration / 100);
        $mainFlour -= $pfFlour;
        foreach ($recipeData['preFermentGrains'] ?? [] as $grain) {
            if (($grain['pct'] ?? 0) > 0) {
                $addIngredient($grain['type'], $pfFlour * ($grain['pct'] / 100));
            }
        }
    }

    // Sourdough
    if (!empty($recipeData['useSourdough']) && !empty($recipeData['sourdoughPct'])) {
        $sdWeight = $totalFlour * ($recipeData['sourdoughPct'] / 100);
        $sdHydration = $recipeData['sourdoughHydration'] ?? 100;
        $sdFlour = $sdWeight / (1 + $sdHydration / 100);
        $mainFlour -= $sdFlour;
        foreach ($recipeData['sourdoughGrains'] ?? [] as $grain) {
            if (($grain['pct'] ?? 0) > 0) {
                $addIngredient($grain['type'], $sdFlour * ($grain['pct'] / 100));
            }
        }
    }

    // Main dough grains
    foreach ($recipeData['mainDoughGrains'] ?? [] as $grain) {
        if (($grain['pct'] ?? 0) > 0) {
            $addIngredient($grain['type'], $mainFlour * ($grain['pct'] / 100));
        }
    }

    // Yeast
    if (!empty($recipeData['useYeast']) && !empty($recipeData['yeastPct'])) {
        $yeastWeight = $totalFlour * ($recipeData['yeastPct'] / 100);
        $yeastName = $recipeData['yeastType'] ?? 'gist';
        $addIngredient($yeastName, $yeastWeight);
    }

    // Mixins
    $mixinMode = $recipeData['mixinMode'] ?? 'flour';
    $baseForMixin = $mixinMode === 'dough' ? $totalDoughWeight : $totalFlour;
    foreach ($recipeData['mixins'] ?? [] as $m) {
        if (!empty($m['ingredient']) && ($m['pct'] ?? 0) > 0) {
            $addIngredient($m['ingredient'], $baseForMixin * ($m['pct'] / 100));
        }
    }

    // Toppings
    foreach ($recipeData['toppings'] ?? [] as $t) {
        if (!empty($t['ingredient']) && ($t['pct'] ?? 0) > 0) {
            $addIngredient($t['ingredient'], $totalDoughWeight * ($t['pct'] / 100));
        }
    }

    return $result;
}
