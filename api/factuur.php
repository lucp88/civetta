<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../lib/factuur/functions.php';

if (!isset($_GET['action']) && !isset($_GET['order_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Geen parameters opgegeven']);
    exit;
}

$action = $_GET['action'] ?? 'view';
$orderId = intval($_GET['order_id'] ?? 0);

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Order ID ontbreekt']);
    exit;
}

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];
$isBusinessUser = isset($_SESSION['business_logged_in']) && $_SESSION['business_logged_in'];

if (!$isAdmin && !$isBusinessUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

if ($isBusinessUser && !$isAdmin) {
    $stmt = $pdo->prepare("SELECT id FROM business_orders WHERE id = ? AND account_id = ?");
    $stmt->execute([$orderId, $_SESSION['business_account_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Geen toegang tot deze factuur']);
        exit;
    }
}

$facturenDir = __DIR__ . '/../facturen';
if (!is_dir($facturenDir)) {
    mkdir($facturenDir, 0755, true);
}

$factuurFile = $facturenDir . '/factuur-' . $orderId . '.pdf';

if ($action === 'generate' || !file_exists($factuurFile)) {
    generateFactuur($pdo, $orderId, $factuurFile);
}

if ($action === 'view' || $action === 'generate') {
    if (file_exists($factuurFile)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="factuur-' . $orderId . '.pdf"');
        readfile($factuurFile);
    } else {
        http_response_code(500);
        echo 'Kon factuur niet genereren';
    }
}
