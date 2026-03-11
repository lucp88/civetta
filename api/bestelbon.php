<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../lib/bestelbon/functions.php';

if (!isset($_GET['action']) && !isset($_GET['order_id']) && !isset($_GET['recurring_group_id'])) {
    jsonError('Geen parameters opgegeven');
}

$action = $_GET['action'] ?? 'view';
$orderId = intval($_GET['order_id'] ?? 0);
$recurringGroupId = $_GET['recurring_group_id'] ?? '';

requireAnyAuthApi();

if ($recurringGroupId) {
    if (isBusinessUser() && !isAdmin()) {
        $stmt = $pdo->prepare("SELECT id FROM business_orders WHERE recurring_group_id = ? AND account_id = ? LIMIT 1");
        $stmt->execute([$recurringGroupId, getBusinessAccountId()]);
        if (!$stmt->fetch()) {
            jsonError('Geen toegang', 403);
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
    jsonError('Order ID ontbreekt');
}

if (isBusinessUser() && !isAdmin()) {
    $stmt = $pdo->prepare("SELECT id FROM business_orders WHERE id = ? AND account_id = ?");
    $stmt->execute([$orderId, getBusinessAccountId()]);
    if (!$stmt->fetch()) {
        jsonError('Geen toegang tot deze bestelbon', 403);
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
    $result = sendBestelbonEmail($pdo, $orderId);
    jsonSuccess(['sent' => $result]);
}

if ($action === 'can_edit') {
    jsonSuccess(['can_edit' => canOrderBeEdited($pdo, $orderId)]);
}
