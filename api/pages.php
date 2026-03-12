<?php
require_once '../admin/config.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

try {
    switch ($method) {
        case 'GET':
            $slug = $_GET['slug'] ?? '';
            if (!$slug) {
                echo json_encode(['success' => false, 'error' => 'Geen pagina opgegeven']);
                break;
            }
            $key = 'page_' . preg_replace('/[^a-z0-9_]/', '', $slug);
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            echo json_encode(['success' => true, 'content' => $value ?: '']);
            break;

        case 'PUT':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $slug = $data['slug'] ?? '';
            $content = $data['content'] ?? '';
            $key = 'page_' . preg_replace('/[^a-z0-9_]/', '', $slug);

            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$key, $content, $content]);
            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database fout']);
}
?>
