<?php
require_once '../admin/config.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'check':
        echo json_encode([
            'success' => true,
            'authenticated' => isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true,
            'user' => $_SESSION['admin_user'] ?? null
        ]);
        break;
        
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            break;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        
        if (!$username || !$password) {
            echo json_encode(['success' => false, 'error' => 'Vul alle velden in']);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user'] = $user['username'];
                echo json_encode(['success' => true, 'user' => $user['username']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Ongeldige gebruikersnaam of wachtwoord']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'change_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            break;
        }
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (strlen($newPassword) < 8) {
            echo json_encode(['success' => false, 'error' => 'Wachtwoord moet minimaal 8 tekens zijn']);
            break;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$_SESSION['admin_user']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPassword, $user['password'])) {
                echo json_encode(['success' => false, 'error' => 'Huidig wachtwoord onjuist']);
                break;
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$newHash, $user['id']]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
