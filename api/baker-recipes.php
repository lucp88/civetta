<?php
session_start();
require_once '../admin/config.php';

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

// Upsert the dough_types row for an "Is deegsoort" recipe.
// Returns the dough_type_id.
function upsertDoughType(PDO $pdo, ?int $existingDoughTypeId, string $name, array $recipeData): int {
    // Strip recipe-specific fields — keep only the base dough composition
    $recipeSpecificFields = ['doughWeight', 'numberOfBalls', 'weightFromOrder', 'mixinMode', 'mixins', 'toppings'];
    $baseData = array_diff_key($recipeData, array_flip($recipeSpecificFields));

    if ($existingDoughTypeId) {
        $pdo->prepare("UPDATE dough_types SET name = ?, recipe_data = ? WHERE id = ?")
            ->execute([$name, json_encode($baseData), $existingDoughTypeId]);
        return $existingDoughTypeId;
    } else {
        $pdo->prepare("INSERT INTO dough_types (name, recipe_data) VALUES (?, ?)")
            ->execute([$name, json_encode($baseData)]);
        return (int)$pdo->lastInsertId();
    }
}

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM baker_recipes WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $recipe = $stmt->fetch();
            if ($recipe) {
                $recipe['recipe_data'] = json_decode($recipe['recipe_data'], true);
                // Include the dough type's base recipe_data so the UI knows which fields are inherited
                $recipe['base_recipe_data'] = null;
                if ($recipe['dough_type_id'] && !$recipe['is_dough_type']) {
                    $dtStmt = $pdo->prepare("SELECT recipe_data FROM dough_types WHERE id = ?");
                    $dtStmt->execute([$recipe['dough_type_id']]);
                    $dtRow = $dtStmt->fetch();
                    if ($dtRow && $dtRow['recipe_data']) {
                        $recipe['base_recipe_data'] = json_decode($dtRow['recipe_data'], true);
                    }
                }
                echo json_encode(['success' => true, 'recipe' => $recipe]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Recept niet gevonden']);
            }
        } else {
            $stmt = $pdo->query("
                SELECT r.id, r.name, r.dough_type_id, r.is_dough_type, r.recipe_data, r.created_at, r.updated_at, dt.name as dough_type_name,
                       EXISTS(SELECT 1 FROM product_variants WHERE recipe_id = r.id) as linked_to_product
                FROM baker_recipes r
                LEFT JOIN dough_types dt ON r.dough_type_id = dt.id
                ORDER BY dt.sort_order ASC, dt.name ASC, r.sort_order ASC, r.name ASC
            ");
            $recipes = $stmt->fetchAll();
            foreach ($recipes as &$recipe) {
                $rd = json_decode($recipe['recipe_data'], true);
                $recipe['recipe_data'] = ['description' => $rd['description'] ?? ''];
            }
            unset($recipe);
            echo json_encode(['success' => true, 'recipes' => $recipes]);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['name'])) {
            echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
            break;
        }
        $isDoughType = !empty($data['is_dough_type']) ? 1 : 0;
        $doughTypeId = isset($data['dough_type_id']) ? ($data['dough_type_id'] ?: null) : null;

        if ($isDoughType) {
            $doughTypeId = upsertDoughType($pdo, $doughTypeId, $data['name'], $data['recipe_data'] ?? []);
        }

        $stmt = $pdo->prepare("INSERT INTO baker_recipes (name, dough_type_id, is_dough_type, recipe_data) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['name'], $doughTypeId, $isDoughType, json_encode($data['recipe_data'])]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'dough_type_id' => $doughTypeId]);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            break;
        }
        $isDoughType = !empty($data['is_dough_type']) ? 1 : 0;
        $doughTypeId = isset($data['dough_type_id']) ? ($data['dough_type_id'] ?: null) : null;

        if ($isDoughType) {
            $doughTypeId = upsertDoughType($pdo, $doughTypeId, $data['name'], $data['recipe_data'] ?? []);
        }

        $stmt = $pdo->prepare("UPDATE baker_recipes SET name = ?, dough_type_id = ?, is_dough_type = ?, recipe_data = ? WHERE id = ?");
        $stmt->execute([$data['name'], $doughTypeId, $isDoughType, json_encode($data['recipe_data']), $data['id']]);
        echo json_encode(['success' => true, 'dough_type_id' => $doughTypeId]);
        break;

    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        if (($data['action'] ?? '') === 'reorder' && !empty($data['items'])) {
            $stmt = $pdo->prepare("UPDATE baker_recipes SET sort_order = ? WHERE id = ?");
            foreach ($data['items'] as $item) {
                $stmt->execute([$item['sort_order'], $item['id']]);
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
        }
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            break;
        }
        $stmt = $pdo->prepare("DELETE FROM baker_recipes WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;
}
