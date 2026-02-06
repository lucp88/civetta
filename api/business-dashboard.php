<?php
require_once '../admin/config.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

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
                    SELECT id, delivery_date, status, total_amount, notes, created_at,
                           is_recurring, recurring_name, recurring_frequency, recurring_day, recurring_end_date
                    FROM business_orders 
                    WHERE account_id = ? 
                    ORDER BY created_at DESC
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
        
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        $password = $data['password'] ?? '';
        
        if (!$password) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Wachtwoord is verplicht']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT password_hash FROM business_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $account = $stmt->fetch();
            
            if (!$account || !password_verify($password, $account['password_hash'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Wachtwoord is onjuist']);
                exit;
            }
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT id FROM business_orders WHERE account_id = ?");
            $stmt->execute([$accountId]);
            $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($orderIds)) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $pdo->prepare("DELETE FROM business_order_items WHERE order_id IN ($placeholders)")->execute($orderIds);
            }
            
            $pdo->prepare("DELETE FROM business_orders WHERE account_id = ?")->execute([$accountId]);
            
            $stmt = $pdo->prepare("SELECT id FROM business_favorites WHERE account_id = ?");
            $stmt->execute([$accountId]);
            $favoriteIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($favoriteIds)) {
                $placeholders = implode(',', array_fill(0, count($favoriteIds), '?'));
                $pdo->prepare("DELETE FROM business_favorite_items WHERE favorite_id IN ($placeholders)")->execute($favoriteIds);
            }
            
            $pdo->prepare("DELETE FROM business_favorites WHERE account_id = ?")->execute([$accountId]);
            
            $pdo->prepare("DELETE FROM business_accounts WHERE id = ?")->execute([$accountId]);
            
            $pdo->commit();
            
            session_destroy();
            
            echo json_encode(['success' => true, 'message' => 'Account verwijderd']);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout bij verwijderen']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
