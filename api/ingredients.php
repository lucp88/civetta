<?php
require_once 'cors.php';
require_once '../admin/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("SELECT * FROM ingredients WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $ingredient = $stmt->fetch();
                if ($ingredient) {
                    echo json_encode(['success' => true, 'ingredient' => $ingredient]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Ingrediënt niet gevonden']);
                }
            } else {
                $where = "1=1";
                $params = [];
                
                if (isset($_GET['category']) && $_GET['category']) {
                    $where .= " AND category = ?";
                    $params[] = $_GET['category'];
                }
                
                if (!isset($_GET['include_inactive'])) {
                    $where .= " AND is_active = 1";
                }
                
                $sql = "SELECT i.*, 
                        COALESCE(SUM(b.quantity_remaining), 0) as total_stock,
                        (SELECT price_per_kg FROM ingredient_batches 
                         WHERE ingredient_id = i.id AND quantity_remaining > 0 
                         ORDER BY purchase_date ASC LIMIT 1) as current_price_per_kg
                        FROM ingredients i
                        LEFT JOIN ingredient_batches b ON i.id = b.ingredient_id AND b.quantity_remaining > 0
                        WHERE $where
                        GROUP BY i.id
                        ORDER BY i.category, i.name";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $ingredients = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'ingredients' => $ingredients]);
            }
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
                exit;
            }
            
            $stmt = $pdo->prepare("INSERT INTO ingredients (name, category, unit, is_whole_grain, grain_type_id, is_biologisch, is_allergeen, allergeen_naam) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($data['name']),
                $data['category'] ?? 'overig',
                $data['unit'] ?? 'g',
                isset($data['is_whole_grain']) ? ($data['is_whole_grain'] ? 1 : 0) : 0,
                !empty($data['grain_type_id']) ? intval($data['grain_type_id']) : null,
                isset($data['is_biologisch']) ? ($data['is_biologisch'] ? 1 : 0) : 0,
                isset($data['is_allergeen']) ? ($data['is_allergeen'] ? 1 : 0) : 0,
                !empty($data['allergeen_naam']) ? trim($data['allergeen_naam']) : null
            ]);
            
            $id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Ingrediënt aangemaakt']);
            break;
            
        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
                exit;
            }
            
            $fields = [];
            $params = [];
            
            if (isset($data['name'])) {
                $fields[] = "name = ?";
                $params[] = trim($data['name']);
            }
            if (isset($data['category'])) {
                $fields[] = "category = ?";
                $params[] = $data['category'];
            }
            if (isset($data['unit'])) {
                $fields[] = "unit = ?";
                $params[] = $data['unit'];
            }
            if (isset($data['is_active'])) {
                $fields[] = "is_active = ?";
                $params[] = $data['is_active'] ? 1 : 0;
            }
            if (isset($data['is_whole_grain'])) {
                $fields[] = "is_whole_grain = ?";
                $params[] = $data['is_whole_grain'] ? 1 : 0;
            }
            if (array_key_exists('grain_type_id', $data)) {
                $fields[] = "grain_type_id = ?";
                $params[] = !empty($data['grain_type_id']) ? intval($data['grain_type_id']) : null;
            }
            if (isset($data['is_biologisch'])) {
                $fields[] = "is_biologisch = ?";
                $params[] = $data['is_biologisch'] ? 1 : 0;
            }
            if (isset($data['is_allergeen'])) {
                $fields[] = "is_allergeen = ?";
                $params[] = $data['is_allergeen'] ? 1 : 0;
            }
            if (array_key_exists('allergeen_naam', $data)) {
                $fields[] = "allergeen_naam = ?";
                $params[] = !empty($data['allergeen_naam']) ? trim($data['allergeen_naam']) : null;
            }
            
            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Geen velden om te updaten']);
                exit;
            }
            
            $params[] = $data['id'];
            $sql = "UPDATE ingredients SET " . implode(", ", $fields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['success' => true, 'message' => 'Ingrediënt bijgewerkt']);
            break;
            
        case 'DELETE':
            $id = $_GET['id'] ?? null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE ingredients SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => 'Ingrediënt gedeactiveerd']);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
