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
                if ($recipe['dough_type_id']) {
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
                SELECT r.id, r.name, r.dough_type_id, r.created_at, r.updated_at, dt.name as dough_type_name,
                       EXISTS(SELECT 1 FROM product_variants WHERE recipe_id = r.id) as linked_to_product
                FROM baker_recipes r
                LEFT JOIN dough_types dt ON r.dough_type_id = dt.id
                ORDER BY dt.name ASC, r.name ASC
            ");
            echo json_encode(['success' => true, 'recipes' => $stmt->fetchAll()]);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['name'])) {
            echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
            break;
        }
        $doughTypeId = isset($data['dough_type_id']) ? ($data['dough_type_id'] ?: null) : null;
        $stmt = $pdo->prepare("INSERT INTO baker_recipes (name, dough_type_id, recipe_data) VALUES (?, ?, ?)");
        $stmt->execute([$data['name'], $doughTypeId, json_encode($data['recipe_data'])]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            break;
        }
        $doughTypeId = isset($data['dough_type_id']) ? ($data['dough_type_id'] ?: null) : null;
        $stmt = $pdo->prepare("UPDATE baker_recipes SET name = ?, dough_type_id = ?, recipe_data = ? WHERE id = ?");
        $stmt->execute([$data['name'], $doughTypeId, json_encode($data['recipe_data']), $data['id']]);
        echo json_encode(['success' => true]);
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
