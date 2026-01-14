<?php
function setCorsHeaders() {
    $allowedOrigins = ['https://bakkerij-civetta.nl', 'https://www.bakkerij-civetta.nl'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } elseif (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['SERVER_NAME'] ?? '', 'localhost') !== false) {
        header('Access-Control-Allow-Origin: *');
    }
    
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
}
