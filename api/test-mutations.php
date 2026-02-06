<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../admin/config.php';
    require_once __DIR__ . '/eboekhouden.php';
    
    $ebClient = getEBoekhoudenClient($pdo);
    
    if (!$ebClient) {
        echo json_encode(['error' => 'e-Boekhouden niet geconfigureerd']);
        exit;
    }
    
    $dateFrom = $_GET['dateFrom'] ?? '2024-01-01';
    
    $mutations = $ebClient->getMutations([
        'dateFrom' => $dateFrom
    ]);
    
    echo json_encode([
        'success' => true,
        'dateFrom' => $dateFrom,
        'count' => $mutations['count'] ?? count($mutations['items'] ?? []),
        'raw_response' => $mutations,
        'first_mutation' => $mutations['items'][0] ?? null
    ], JSON_PRETTY_PRINT);
    
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
