<?php
require_once '../admin/config.php';
require_once '../lib/recipe/RecipePDF.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Geen data ontvangen']);
        exit;
    }
    
    $pdf = generateRecipePDF($input);
    
    $filename = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $input['name'] ?? 'Recept');
    $filename = 'Recept_' . $filename . '_' . date('Y-m-d') . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    
    $pdf->Output('D', $filename);
    exit;
}

if ($method === 'GET' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("SELECT * FROM baker_recipes WHERE id = ?");
    $stmt->execute([$id]);
    $recipe = $stmt->fetch();
    
    if (!$recipe) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Recept niet gevonden']);
        exit;
    }
    
    $data = [
        'name' => $recipe['name'],
        'recipe_data' => json_decode($recipe['recipe_data'], true)
    ];
    
    $pdf = generateRecipePDF($data);
    
    $filename = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $recipe['name']);
    $filename = 'Recept_' . $filename . '_' . date('Y-m-d') . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    
    $pdf->Output('D', $filename);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Methode niet toegestaan']);
