<?php
require_once 'cors.php';
require_once '../admin/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT * FROM grain_types ORDER BY sort_order ASC, name ASC");
            $grainTypes = $stmt->fetchAll();
            echo json_encode(['success' => true, 'grain_types' => $grainTypes]);
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
                exit;
            }
            
            $stmt = $pdo->prepare("INSERT INTO grain_types (name) VALUES (?)");
            $stmt->execute([trim($data['name'])]);
            
            $id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Graansoort aangemaakt']);
            break;
            
        case 'PATCH':
            $data = json_decode(file_get_contents('php://input'), true);
            if (($data['action'] ?? '') === 'reorder' && !empty($data['items'])) {
                $stmt = $pdo->prepare("UPDATE grain_types SET sort_order = ? WHERE id = ?");
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
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM ingredients WHERE grain_type_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Kan niet verwijderen: er zijn nog ingrediënten gekoppeld aan deze graansoort']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM grain_types WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => 'Graansoort verwijderd']);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
