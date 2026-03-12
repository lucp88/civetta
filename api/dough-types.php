<?php
require_once 'cors.php';
require_once '../admin/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $stmt = $pdo->query("SELECT id, name, recipe_data FROM dough_types ORDER BY sort_order ASC, name ASC");
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['recipe_data'] = $row['recipe_data'] ? json_decode($row['recipe_data'], true) : null;
        }
        echo json_encode(['success' => true, 'dough_types' => $rows]);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');

        if (!$name) {
            echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
            exit;
        }

        $recipeData = isset($data['recipe_data']) ? json_encode($data['recipe_data']) : null;
        $stmt = $pdo->prepare("INSERT INTO dough_types (name, recipe_data) VALUES (?, ?)");
        $stmt->execute([$name, $recipeData]);

        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = $data['id'] ?? null;
        $name = trim($data['name'] ?? '');

        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            exit;
        }

        $recipeData = $data['recipe_data'] ?? null;
        $pdo->prepare("UPDATE dough_types SET name = ?, recipe_data = ? WHERE id = ?")
            ->execute([$name, $recipeData !== null ? json_encode($recipeData) : null, $id]);

        // Cascade: re-merge all child baker_recipes with the updated base formula
        if ($recipeData !== null) {
            $recipeSpecificFields = ['doughWeight', 'numberOfBalls', 'weightFromOrder', 'mixinMode', 'mixins', 'toppings', 'method'];
            $children = $pdo->prepare("SELECT id, recipe_data FROM baker_recipes WHERE dough_type_id = ?");
            $children->execute([$id]);
            foreach ($children->fetchAll() as $child) {
                $existing = json_decode($child['recipe_data'], true) ?? [];
                $childFields = array_intersect_key($existing, array_flip($recipeSpecificFields));
                $merged = array_merge($recipeData, $childFields);
                $pdo->prepare("UPDATE baker_recipes SET recipe_data = ? WHERE id = ?")
                    ->execute([json_encode($merged), $child['id']]);
            }
        }

        echo json_encode(['success' => true]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;

        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            exit;
        }

        $pdo->prepare("UPDATE baker_recipes SET dough_type_id = NULL WHERE dough_type_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM dough_types WHERE id = ?")->execute([$id]);

        echo json_encode(['success' => true]);
        break;

    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        if (($data['action'] ?? '') === 'reorder' && !empty($data['items'])) {
            $stmt = $pdo->prepare("UPDATE dough_types SET sort_order = ? WHERE id = ?");
            foreach ($data['items'] as $item) {
                $stmt->execute([$item['sort_order'], $item['id']]);
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
