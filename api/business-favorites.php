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
        try {
            $stmt = $pdo->prepare("SELECT * FROM business_favorites WHERE account_id = ? ORDER BY naam ASC");
            $stmt->execute([$accountId]);
            $favorites = $stmt->fetchAll();
            
            foreach ($favorites as &$favorite) {
                $stmt = $pdo->prepare("
                    SELECT bfi.*, p.foto as product_image
                    FROM business_favorite_items bfi
                    LEFT JOIN products p ON p.id = bfi.product_id
                    WHERE bfi.favorite_id = ?
                ");
                $stmt->execute([$favorite['id']]);
                $favorite['items'] = $stmt->fetchAll();
            }
            unset($favorite);
            
            echo json_encode(['success' => true, 'favorites' => $favorites]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $naam = trim($data['naam'] ?? '');
        $items = $data['items'] ?? [];
        
        if (empty($naam)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Geef een naam op voor de favoriet']);
            exit;
        }
        
        if (empty($items)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Selecteer minimaal één product']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO business_favorites (account_id, naam) VALUES (?, ?)");
            $stmt->execute([$accountId, $naam]);
            $favoriteId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("
                INSERT INTO business_favorite_items (favorite_id, product_id, product_name, quantity, unit_price)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($items as $item) {
                $stmt->execute([
                    $favoriteId,
                    $item['product_id'] ?? null,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price']
                ]);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'favorite_id' => $favoriteId,
                'message' => 'Favoriet opgeslagen'
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'PUT':

                $pdo->prepare("DELETE FROM business_favorite_items WHERE favorite_id = ?")->execute([$favoriteId]);
                
                $stmt = $pdo->prepare("
                    INSERT INTO business_favorite_items (favorite_id, product_id, product_name, quantity, unit_price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($items as $item) {
                    $stmt->execute([
                        $favoriteId,
                        $item['product_id'] ?? null,
                        $item['product_name'],
                        $item['quantity'],
                        $item['unit_price']
                    ]);
                }
            }
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Favoriet bijgewerkt']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        $favoriteId = intval($data['id'] ?? 0);
        
        if (!$favoriteId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Favoriet ID ontbreekt']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT id FROM business_favorites WHERE id = ? AND account_id = ?");
        $stmt->execute([$favoriteId, $accountId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Geen toegang tot deze favoriet']);
            exit;
        }
        
        try {
            $pdo->prepare("DELETE FROM business_favorites WHERE id = ?")->execute([$favoriteId]);
            echo json_encode(['success' => true, 'message' => 'Favoriet verwijderd']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Methode niet toegestaan']);
}
