<?php
require_once '../admin/config.php';
require_once 'eboekhouden.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Niet ingelogd']);
    exit;
}

$settings = getEBoekhoudenSettings($pdo);

$debugInfo = [
    'facturatie_systeem' => $settings['facturatie_systeem'],
    'api_token_set' => !empty($settings['eboekhouden_api_token']),
    'api_token_length' => strlen($settings['eboekhouden_api_token'] ?? ''),
    'template_id' => $settings['eboekhouden_template_id'],
    'ledger_id' => $settings['eboekhouden_ledger_id'],
    'btw_tarief' => $settings['btw_tarief'],
    'zou_eboekhouden_gebruiken' => ($settings['facturatie_systeem'] === 'eboekhouden' && !empty($settings['eboekhouden_api_token']))
];

$action = $_GET['action'] ?? 'info';

if ($action === 'ledgers' && !empty($settings['eboekhouden_api_token'])) {
    try {
        $client = new EBoekhoudenClient($settings['eboekhouden_api_token']);
        $ledgers = $client->getLedgers();
        $debugInfo['ledgers'] = $ledgers;
    } catch (Exception $e) {
        $debugInfo['ledgers_error'] = $e->getMessage();
    }
}

if ($action === 'templates' && !empty($settings['eboekhouden_api_token'])) {
    try {
        $client = new EBoekhoudenClient($settings['eboekhouden_api_token']);
        $templates = $client->getInvoiceTemplates();
        $debugInfo['templates'] = $templates;
    } catch (Exception $e) {
        $debugInfo['templates_error'] = $e->getMessage();
    }
}

echo json_encode($debugInfo, JSON_PRETTY_PRINT);
