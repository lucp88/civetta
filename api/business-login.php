<?php
require_once '../admin/config.php';

header('Content-Type: application/json');
$allowedOrigins = ['https://bakkerij-civetta.nl', 'https://www.bakkerij-civetta.nl'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'];
$data = json_decode(file_get_contents('php://input'), true);

$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (!checkLoginAttempts($pdo, $ip)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.']);
    exit;
}

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'E-mailadres en wachtwoord zijn verplicht']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM business_accounts WHERE email = ? AND status = 'approved'");
    $stmt->execute([$email]);
    $account = $stmt->fetch();
    
    if (!$account || !$account['password_hash'] || !password_verify($password, $account['password_hash'])) {
        recordLoginAttempt($pdo, $ip, false);
        sleep(1);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Ongeldige inloggegevens']);
        exit;
    }
    
    recordLoginAttempt($pdo, $ip, true);
    regenerateSession();
    $_SESSION['business_logged_in'] = true;
    $_SESSION['business_account_id'] = $account['id'];
    $_SESSION['business_name'] = $account['bedrijfsnaam'];
    $_SESSION['business_email'] = $account['email'];
    
    echo json_encode([
        'success' => true, 
        'message' => 'Succesvol ingelogd',
        'account' => [
            'id' => $account['id'],
            'bedrijfsnaam' => $account['bedrijfsnaam'],
            'contactpersoon' => $account['contactpersoon'],
            'email' => $account['email']
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database fout']);
}
?>
