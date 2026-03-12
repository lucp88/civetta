<?php
require_once '../admin/config.php';
require_once '../lib/shared.php';
require_once 'email-templates.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode niet toegestaan']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ongeldig e-mailadres']);
    exit;
}

// Always return success to prevent email enumeration
try {
    $stmt = $pdo->prepare("SELECT * FROM business_accounts WHERE email = ? AND status = 'approved'");
    $stmt->execute([$email]);
    $account = $stmt->fetch();

    if ($account) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $stmt = $pdo->prepare("UPDATE business_accounts SET pw_reset_token = ?, pw_reset_expires = ? WHERE id = ?");
        $stmt->execute([$token, $expires, $account['id']]);

        $resetUrl = 'https://bakkerij-civetta.nl/wachtwoord-resetten.php?token=' . $token;
        $bedrijf = getBedrijfsGegevens($pdo);
        $htmlBody = buildForgotPasswordEmail($account, $resetUrl, $bedrijf);
        sendHtmlEmail($email, 'Wachtwoord opnieuw instellen — Bakkerij Civetta', $htmlBody);
    }
} catch (PDOException $e) {
    // Silently fail to not reveal account existence
}

echo json_encode(['success' => true]);
