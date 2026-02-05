<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../lib/web-push.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? '';

        if ($action === 'vapid-key') {
            $vapid = getOrCreateVapidKeys($pdo);
            echo json_encode(['success' => true, 'publicKey' => $vapid['public']]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $endpoint = $data['endpoint'] ?? '';
        $p256dh = $data['keys']['p256dh'] ?? '';
        $auth = $data['keys']['auth'] ?? '';

        if (!$endpoint || !$p256dh || !$auth) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ongeldige subscription data']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO push_subscriptions (endpoint, key_p256dh, key_auth)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE key_p256dh = VALUES(key_p256dh), key_auth = VALUES(key_auth)
            ");
            $stmt->execute([$endpoint, $p256dh, $auth]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        $endpoint = $data['endpoint'] ?? '';

        if (!$endpoint) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Endpoint ontbreekt']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
            $stmt->execute([$endpoint]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
