<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../admin/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT id, naam, ingredienten, beschrijving, prijs, foto FROM products ORDER BY naam ASC");
            $products = $stmt->fetchAll();
            
            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
            $btwTarief = floatval($stmt->fetchColumn() ?: 9);
            
            echo json_encode(['success' => true, 'products' => $products, 'btw_tarief' => $btwTarief]);
            break;
            
        case 'POST':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("INSERT INTO products (naam, ingredienten, beschrijving, prijs, foto) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['naam'],
                $data['ingredienten'] ?? '',
                $data['beschrijving'] ?? '',
                $data['prijs'] ?? null,
                $data['foto'] ?? ''
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
            
        case 'PUT':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("UPDATE products SET naam = ?, ingredienten = ?, beschrijving = ?, prijs = ?, foto = ? WHERE id = ?");
            $stmt->execute([
                $data['naam'],
                $data['ingredienten'] ?? '',
                $data['beschrijving'] ?? '',
                $data['prijs'] ?? null,
                $data['foto'] ?? '',
                $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;
            
        case 'DELETE':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$data['id']]);
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
