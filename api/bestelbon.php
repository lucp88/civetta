<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../lib/bestelbon/functions.php';

if (!isset($_GET['action']) && !isset($_GET['order_id']) && !isset($_GET['recurring_group_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Geen parameters opgegeven']);
    exit;
}

$action = $_GET['action'] ?? 'view';
$orderId = intval($_GET['order_id'] ?? 0);
$recurringGroupId = $_GET['recurring_group_id'] ?? '';

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];
$isBusinessUser = isset($_SESSION['business_logged_in']) && $_SESSION['business_logged_in'];

if (!$isAdmin && !$isBusinessUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

if ($recurringGroupId) {
    if ($isBusinessUser && !$isAdmin) {
        $stmt = $pdo->prepare("SELECT id FROM business_orders WHERE recurring_group_id = ? AND account_id = ? LIMIT 1");
        $stmt->execute([$recurringGroupId, $_SESSION['business_account_id']]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Geen toegang']);
            exit;
        }
    }
    
    $bestelbonDir = __DIR__ . '/../bestelbonnen';
    if (!is_dir($bestelbonDir)) {
        mkdir($bestelbonDir, 0755, true);
    }
    
    $bestelbonFile = $bestelbonDir . '/bestelbon-recurring-' . $recurringGroupId . '.pdf';
    generateRecurringBestelbon($pdo, $recurringGroupId, $bestelbonFile);
    
    if (file_exists($bestelbonFile)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="overzicht-terugkerende-bestelling.pdf"');
        readfile($bestelbonFile);
    } else {
        http_response_code(500);
        echo 'Kon bestelbon niet genereren';
    }
    exit;
}

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Order ID ontbreekt']);
    exit;
}

if ($isBusinessUser && !$isAdmin) {
    $stmt = $pdo->prepare("SELECT id FROM business_orders WHERE id = ? AND account_id = ?");
    $stmt->execute([$orderId, $_SESSION['business_account_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Geen toegang tot deze bestelbon']);
        exit;
    }
}

$bestelbonDir = __DIR__ . '/../bestelbonnen';
if (!is_dir($bestelbonDir)) {
    mkdir($bestelbonDir, 0755, true);
}

$bestelbonFile = $bestelbonDir . '/bestelbon-' . $orderId . '.pdf';

if ($action === 'generate' || !file_exists($bestelbonFile)) {
    generateBestelbon($pdo, $orderId, $bestelbonFile);
}

if ($action === 'view' || $action === 'generate') {
    if (file_exists($bestelbonFile)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="bestelbon-' . $orderId . '.pdf"');
        readfile($bestelbonFile);
    } else {
        http_response_code(500);
        echo 'Kon bestelbon niet genereren';
    }
}

if ($action === 'send') {
    header('Content-Type: application/json');
    $result = sendBestelbonEmail($pdo, $orderId);
    echo json_encode(['success' => $result]);
}

if ($action === 'can_edit') {
    header('Content-Type: application/json');
    echo json_encode(['can_edit' => canOrderBeEdited($pdo, $orderId)]);
}
