<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../admin/config.php';

if (!isset($_SESSION['business_logged_in']) || !$_SESSION['business_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

$accountId = $_SESSION['business_account_id'];
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'profile';
        
        try {
            if ($action === 'profile') {
                $stmt = $pdo->prepare("
                    SELECT id, bedrijfsnaam, adres, postcode, plaats, contactpersoon, email, telefoon, website, kvk_nummer, btw_id, created_at, approved_at 
                    FROM business_accounts 
                    WHERE id = ? AND status = 'approved'
                ");
                $stmt->execute([$accountId]);
                $account = $stmt->fetch();
                
                if (!$account) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Account niet gevonden']);
                    exit;
                }
                
                echo json_encode(['success' => true, 'account' => $account]);
                
            } elseif ($action === 'orders') {
                $stmt = $pdo->prepare("
                    SELECT id, order_date, status, total_amount, notes, created_at 
                    FROM business_orders 
                    WHERE account_id = ? 
                    ORDER BY order_date DESC
                ");
                $stmt->execute([$accountId]);
                $orders = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'orders' => $orders]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
        
        try {
            if ($action === 'update_profile') {
                $bedrijfsnaam = trim($data['bedrijfsnaam'] ?? '');
                $adres = trim($data['adres'] ?? '');
                $postcode = trim($data['postcode'] ?? '');
                $plaats = trim($data['plaats'] ?? '');
                $contactpersoon = trim($data['contactpersoon'] ?? '');
                $telefoon = trim($data['telefoon'] ?? '');
                $website = trim($data['website'] ?? '');
                $kvk_nummer = trim($data['kvk_nummer'] ?? '');
                $btw_id = trim($data['btw_id'] ?? '');
                
                if (!$bedrijfsnaam || !$adres || !$contactpersoon) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Vul alle verplichte velden in']);
                    exit;
                }
                
                $stmt = $pdo->prepare("
                    UPDATE business_accounts 
                    SET bedrijfsnaam = ?, adres = ?, postcode = ?, plaats = ?, contactpersoon = ?, telefoon = ?, website = ?, kvk_nummer = ?, btw_id = ?
                    WHERE id = ? AND status = 'approved'
                ");
                $stmt->execute([$bedrijfsnaam, $adres, $postcode, $plaats, $contactpersoon, $telefoon, $website, $kvk_nummer, $btw_id, $accountId]);
                
                echo json_encode(['success' => true, 'message' => 'Gegevens bijgewerkt']);
                
            } elseif ($action === 'change_password') {
                $currentPassword = $data['current_password'] ?? '';
                $newPassword = $data['new_password'] ?? '';
                
                if (!$currentPassword || !$newPassword) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Vul beide wachtwoorden in']);
                    exit;
                }
                
                if (strlen($newPassword) < 8) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Nieuw wachtwoord moet minimaal 8 tekens zijn']);
                    exit;
                }
                
                $stmt = $pdo->prepare("SELECT password_hash FROM business_accounts WHERE id = ?");
                $stmt->execute([$accountId]);
                $account = $stmt->fetch();
                
                if (!password_verify($currentPassword, $account['password_hash'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Huidig wachtwoord is onjuist']);
                    exit;
                }
                
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE business_accounts SET password_hash = ? WHERE id = ?");
                $stmt->execute([$newHash, $accountId]);
                
                echo json_encode(['success' => true, 'message' => 'Wachtwoord gewijzigd']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
