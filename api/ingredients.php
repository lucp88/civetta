<?php
require_once 'cors.php';
require_once '../admin/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$MACRO_COLS = ['kcal','protein_g','carbs_g','carbs_sugars_g','fat_g','fat_saturated_g','fiber_g','salt_g'];

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("SELECT * FROM ingredients WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $ingredient = $stmt->fetch();
                if (!$ingredient) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Ingrediënt niet gevonden']);
                    break;
                }
                $aStmt = $pdo->prepare("SELECT allergeen_naam FROM ingredient_allergenen WHERE ingredient_id = ?");
                $aStmt->execute([$_GET['id']]);
                $ingredient['allergenen'] = $aStmt->fetchAll(PDO::FETCH_COLUMN);

                $cStmt = $pdo->prepare(
                    "SELECT i.*, COALESCE(SUM(b.quantity_remaining),0) as total_stock,
                            (SELECT price_per_kg FROM ingredient_batches
                             WHERE ingredient_id = i.id AND quantity_remaining > 0
                             ORDER BY COALESCE(thd_date,'9999-12-31') ASC, purchase_date ASC LIMIT 1) as current_price_per_kg
                     FROM ingredients i
                     LEFT JOIN ingredient_batches b ON b.ingredient_id = i.id AND b.quantity_remaining > 0
                     WHERE i.parent_id = ? GROUP BY i.id ORDER BY i.brand_name"
                );
                $cStmt->execute([$_GET['id']]);
                $children = $cStmt->fetchAll();
                foreach ($children as &$c) {
                    $ca = $pdo->prepare("SELECT allergeen_naam FROM ingredient_allergenen WHERE ingredient_id = ?");
                    $ca->execute([$c['id']]);
                    $c['allergenen'] = $ca->fetchAll(PDO::FETCH_COLUMN);
                }
                unset($c);
                $ingredient['children'] = $children;
                echo json_encode(['success' => true, 'ingredient' => $ingredient]);
            } else {
                $where = "1=1";
                $params = [];
                if (isset($_GET['category']) && $_GET['category']) {
                    $where .= " AND i.category = ?";
                    $params[] = $_GET['category'];
                }
                if (!isset($_GET['include_inactive'])) {
                    $where .= " AND i.is_active = 1";
                }
                // Groups (parent_id IS NULL): total_stock = sum of children's batches.
                // Sub-products (parent_id IS NOT NULL): total_stock = own batches.
                $sql = "SELECT i.*,
                        CASE WHEN i.parent_id IS NULL THEN
                            COALESCE((SELECT SUM(b2.quantity_remaining)
                                      FROM ingredient_batches b2
                                      JOIN ingredients ci ON b2.ingredient_id = ci.id
                                      WHERE ci.parent_id = i.id AND b2.quantity_remaining > 0), 0)
                        ELSE
                            COALESCE(SUM(b.quantity_remaining), 0)
                        END as total_stock,
                        CASE WHEN i.parent_id IS NOT NULL THEN
                            (SELECT price_per_kg FROM ingredient_batches
                             WHERE ingredient_id = i.id AND quantity_remaining > 0
                             ORDER BY COALESCE(thd_date,'9999-12-31') ASC, purchase_date ASC LIMIT 1)
                        ELSE NULL END as current_price_per_kg,
                        GROUP_CONCAT(ia.allergeen_naam ORDER BY ia.allergeen_naam SEPARATOR ',') as allergenen_str
                        FROM ingredients i
                        LEFT JOIN ingredient_batches b ON i.id = b.ingredient_id AND b.quantity_remaining > 0
                        LEFT JOIN ingredient_allergenen ia ON ia.ingredient_id = i.id
                        WHERE $where
                        GROUP BY i.id
                        ORDER BY COALESCE(i.parent_id, i.id), i.parent_id IS NULL DESC, i.sort_order, i.name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $ingredients = $stmt->fetchAll();
                foreach ($ingredients as &$ing) {
                    $ing['allergenen'] = $ing['allergenen_str'] ? explode(',', $ing['allergenen_str']) : [];
                    unset($ing['allergenen_str']);
                }
                unset($ing);
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
            $macroVals = array_map(
                fn($f) => isset($data[$f]) && $data[$f] !== '' ? floatval($data[$f]) : null,
                array_combine($MACRO_COLS, $MACRO_COLS)
            );
            $stmt = $pdo->prepare(
                "INSERT INTO ingredients
                    (name, category, unit, is_whole_grain, grain_type_id, is_biologisch, is_allergeen,
                     allergeen_naam, use_verpakkingen, parent_id, brand_name,
                     kcal, protein_g, carbs_g, carbs_sugars_g, fat_g, fat_saturated_g, fiber_g, salt_g)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                trim($data['name']),
                $data['category'] ?? 'overig',
                $data['unit'] ?? 'g',
                !empty($data['is_whole_grain']) ? 1 : 0,
                !empty($data['grain_type_id']) ? intval($data['grain_type_id']) : null,
                !empty($data['is_biologisch']) ? 1 : 0,
                !empty($data['is_allergeen']) ? 1 : 0,
                !empty($data['allergeen_naam']) ? trim($data['allergeen_naam']) : null,
                !empty($data['use_verpakkingen']) ? 1 : 0,
                !empty($data['parent_id']) ? intval($data['parent_id']) : null,
                !empty($data['brand_name']) ? trim($data['brand_name']) : null,
                ...array_values($macroVals),
            ]);
            $id = $pdo->lastInsertId();
            $allergenen = array_filter(array_map('trim', (array)($data['allergenen'] ?? [])));
            if (!empty($allergenen)) {
                $iaStmt = $pdo->prepare("INSERT IGNORE INTO ingredient_allergenen (ingredient_id, allergeen_naam) VALUES (?, ?)");
                foreach ($allergenen as $naam) { $iaStmt->execute([$id, $naam]); }
                $pdo->prepare("UPDATE ingredients SET is_allergeen = 1, allergeen_naam = ? WHERE id = ?")->execute([reset($allergenen), $id]);
                require_once '../lib/allergen-trace.php';
                updateAllergenTraceStatus($pdo, $id);
            }
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
            $setStr = function($f, $v) use (&$fields, &$params) { $fields[] = "$f = ?"; $params[] = $v; };
            $setBool = function($f, $v) use (&$fields, &$params) { $fields[] = "$f = ?"; $params[] = $v ? 1 : 0; };

            if (isset($data['name']))            $setStr('name', trim($data['name']));
            if (isset($data['category']))         $setStr('category', $data['category']);
            if (isset($data['unit']))             $setStr('unit', $data['unit']);
            if (isset($data['is_active']))        $setBool('is_active', $data['is_active']);
            if (isset($data['is_whole_grain']))   $setBool('is_whole_grain', $data['is_whole_grain']);
            if (isset($data['is_biologisch']))    $setBool('is_biologisch', $data['is_biologisch']);
            if (isset($data['is_allergeen']))     $setBool('is_allergeen', $data['is_allergeen']);
            if (isset($data['use_verpakkingen'])) $setBool('use_verpakkingen', $data['use_verpakkingen']);
            if (array_key_exists('grain_type_id', $data))
                $setStr('grain_type_id', !empty($data['grain_type_id']) ? intval($data['grain_type_id']) : null);
            if (array_key_exists('allergeen_naam', $data))
                $setStr('allergeen_naam', !empty($data['allergeen_naam']) ? trim($data['allergeen_naam']) : null);
            if (array_key_exists('brand_name', $data))
                $setStr('brand_name', !empty($data['brand_name']) ? trim($data['brand_name']) : null);
            foreach ($MACRO_COLS as $f) {
                if (array_key_exists($f, $data))
                    $setStr($f, $data[$f] !== '' && $data[$f] !== null ? floatval($data[$f]) : null);
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Geen velden om te updaten']);
                exit;
            }
            $params[] = $data['id'];
            $pdo->prepare("UPDATE ingredients SET " . implode(", ", $fields) . " WHERE id = ?")->execute($params);

            if (array_key_exists('allergenen', $data)) {
                $pdo->prepare("DELETE FROM ingredient_allergenen WHERE ingredient_id = ?")->execute([$data['id']]);
                $allergenen = array_filter(array_map('trim', (array)$data['allergenen']));
                if (!empty($allergenen)) {
                    $iaStmt = $pdo->prepare("INSERT IGNORE INTO ingredient_allergenen (ingredient_id, allergeen_naam) VALUES (?, ?)");
                    foreach ($allergenen as $naam) { $iaStmt->execute([$data['id'], $naam]); }
                    $pdo->prepare("UPDATE ingredients SET is_allergeen = 1, allergeen_naam = ? WHERE id = ?")->execute([reset($allergenen), $data['id']]);
                } else {
                    $pdo->prepare("UPDATE ingredients SET is_allergeen = 0, allergeen_naam = NULL WHERE id = ?")->execute([$data['id']]);
                }
                require_once '../lib/allergen-trace.php';
                updateAllergenTraceStatus($pdo, $data['id']);
            }
            echo json_encode(['success' => true, 'message' => 'Ingrediënt bijgewerkt']);
            break;

        case 'PATCH':
            $data = json_decode(file_get_contents('php://input'), true);
            if (($data['action'] ?? '') === 'reorder' && !empty($data['items'])) {
                $stmt = $pdo->prepare("UPDATE ingredients SET sort_order = ? WHERE id = ?");
                foreach ($data['items'] as $item) { $stmt->execute([$item['sort_order'], $item['id']]); }
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
            }
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'ID is verplicht']); exit; }
            $pdo->prepare("UPDATE ingredients SET is_active = 0 WHERE id = ?")->execute([$id]);
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
