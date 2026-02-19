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
                
                $where = "b.quantity_remaining > 0";
                $params = [];
                
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
                
                $stmt = $pdo->prepare("
                    INSERT INTO ingredient_batches (ingredient_id, quantity_purchased, quantity_remaining, price_per_kg, purchase_date)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['ingredient_id'],
                    $quantityGrams,
                    $quantityGrams,
                    floatval($data['price_per_kg']),
                    $data['purchase_date'] ?? date('Y-m-d')
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
                INSERT INTO inventory_consumption (ingredient_id, batch_id, order_id, quantity_consumed, cost)
                VALUES (?, ?, ?, ?, ?)
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
