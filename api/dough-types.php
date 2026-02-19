<?php
require_once 'cors.php';
require_once '../admin/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $stmt = $pdo->query("SELECT id, name FROM dough_types ORDER BY name ASC");
        echo json_encode(['success' => true, 'dough_types' => $stmt->fetchAll()]);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');
        
        if (!$name) {
            echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
            exit;
        }
        
        $stmt = $pdo->prepare("INSERT INTO dough_types (name) VALUES (?)");
        $stmt->execute([$name]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
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

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
