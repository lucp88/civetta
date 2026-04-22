<?php
require_once '../admin/config.php';
require_once 'cors.php';
require_once __DIR__ . '/email-templates.php';
require_once __DIR__ . '/../lib/shared.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
            exit;
        }
        
        $status = $_GET['status'] ?? null;
        $accountType = $_GET['account_type'] ?? null;

        try {
            $where = [];
            $params = [];

            if ($status) {
                $where[] = "status = ?";
                $params[] = $status;
            }
            if ($accountType) {
                $where[] = "account_type = ?";
                $params[] = $accountType;
            }

            $sql = "SELECT * FROM business_accounts";
            if ($where) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }
            $sql .= " ORDER BY created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $accounts = $stmt->fetchAll();
            echo json_encode(['success' => true, 'accounts' => $accounts]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        // reCAPTCHA v3 verification (only for public registrations, not admin_create)
        $isAdminCreate = !empty($data['admin_create']) && isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];
        if (!$isAdminCreate && recaptchaSiteKey() && !verifyRecaptcha($data['recaptcha_token'] ?? '', 'register')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Spam verificatie mislukt. Probeer het opnieuw.']);
            exit;
        }

        $bedrijfsnaam = trim($data['bedrijfsnaam'] ?? '');
        $adres = trim($data['adres'] ?? '');
        $postcode = trim($data['postcode'] ?? '');
        $plaats = trim($data['plaats'] ?? '');
        $contactpersoon = trim($data['contactpersoon'] ?? '');
        $email = trim($data['email'] ?? '');
        $telefoon = trim($data['telefoon'] ?? '');
        $website = trim($data['website'] ?? '');
        $kvk_nummer = trim($data['kvk_nummer'] ?? '');
        $btw_id = trim($data['btw_id'] ?? '');
        $opmerkingen = trim($data['opmerkingen'] ?? '');
        $accountType = $data['account_type'] ?? 'zakelijk';
        $hasBalance = !empty($data['has_balance']) ? 1 : 0;
        $deliveryEnabled = isset($data['delivery_enabled']) ? (!empty($data['delivery_enabled']) ? 1 : 0) : ($accountType === 'zakelijk' ? 1 : 0);
        $deliveryCost = isset($data['delivery_cost']) ? floatval($data['delivery_cost']) : 0;

        if (!in_array($accountType, ['zakelijk', 'particulier'])) {
            $accountType = 'zakelijk';
        }

        if (!$bedrijfsnaam || !$adres || !$contactpersoon || !$email) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Vul alle verplichte velden in']);
            exit;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ongeldig e-mailadres']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT id FROM business_accounts WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Dit e-mailadres is al geregistreerd']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO business_accounts (account_type, bedrijfsnaam, adres, postcode, plaats, contactpersoon, email, telefoon, website, kvk_nummer, btw_id, has_balance, opmerkingen, delivery_enabled, delivery_cost, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$accountType, $bedrijfsnaam, $adres, $postcode, $plaats, $contactpersoon, $email, $telefoon, $website, $kvk_nummer, $btw_id, $hasBalance, $opmerkingen, $deliveryEnabled, $deliveryCost]);
            
            $id = $pdo->lastInsertId();

            $adminCreate = !empty($data['admin_create']) && isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

            if ($adminCreate) {
                $stmt = $pdo->prepare("UPDATE business_accounts SET status = 'approved', approved_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Account aangemaakt. Stuur een uitnodiging wanneer je klaar bent.']);
            } else {
                $account = [
                    'bedrijfsnaam' => $bedrijfsnaam,
                    'contactpersoon' => $contactpersoon,
                    'email' => $email,
                    'adres' => $adres,
                    'postcode' => $postcode,
                    'plaats' => $plaats,
                ];
                
                $bedrijf = getBedrijfsGegevens($pdo);
                $htmlBody = buildAccountRegistrationEmail($account, $bedrijf, $pdo);
                sendHtmlEmail($email, getEmailSubject($pdo, 'account_aanvraag', 'Aanvraag ontvangen - Bakkerij Civetta'), $htmlBody);
                
                $adminHtml = getEmailHeader('Nieuwe accountaanvraag');
                $adminHtml .= '
                    <div class="email-body">
                        <h2>Nieuwe zakelijke accountaanvraag</h2>
                        <div class="info-box">
                            <p><strong>Bedrijf:</strong> ' . htmlspecialchars($bedrijfsnaam) . '</p>
                            <p><strong>Adres:</strong> ' . htmlspecialchars("$adres, $postcode $plaats") . '</p>
                            <p><strong>KVK:</strong> ' . htmlspecialchars($kvk_nummer ?: '-') . '</p>
                            <p><strong>BTW-ID:</strong> ' . htmlspecialchars($btw_id ?: '-') . '</p>
                            <p><strong>Contactpersoon:</strong> ' . htmlspecialchars($contactpersoon) . '</p>
                            <p><strong>E-mail:</strong> ' . htmlspecialchars($email) . '</p>
                            <p><strong>Telefoon:</strong> ' . htmlspecialchars($telefoon ?: '-') . '</p>
                            <p><strong>Website:</strong> ' . htmlspecialchars($website ?: '-') . '</p>
                            ' . ($opmerkingen ? '<p><strong>Opmerkingen:</strong> ' . nl2br(htmlspecialchars($opmerkingen)) . '</p>' : '') . '
                        </div>
                        <p style="text-align: center;">
                            <a href="https://bakkerij-civetta.nl/admin/accounts/accounts-bedrijven.php" class="cta-button">Bekijk in admin panel</a>
                        </p>
                    </div>';
                $adminHtml .= getEmailFooter($bedrijf);
                sendHtmlEmail('info@bakkerij-civetta.nl', "Nieuwe accountaanvraag: $bedrijfsnaam", $adminHtml, [], $email);
                
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Aanvraag succesvol ingediend']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'PUT':
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        $action = $data['action'] ?? null;
        
        if (!$id || !$action) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID en actie zijn verplicht']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM business_accounts WHERE id = ?");
            $stmt->execute([$id]);
            $account = $stmt->fetch();
            
            if (!$account) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Account niet gevonden']);
                exit;
            }
            
            if ($action === 'approve') {
                $inviteToken = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("UPDATE business_accounts SET status = 'approved', approved_at = NOW(), invite_token = ? WHERE id = ?");
                $stmt->execute([$inviteToken, $id]);

                echo json_encode(['success' => true, 'message' => 'Goedgekeurd! Stuur een uitnodiging wanneer je klaar bent.']);
            } elseif ($action === 'reject') {
                $stmt = $pdo->prepare("UPDATE business_accounts SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Account afgewezen']);
            } elseif ($action === 'update') {
                $bedrijfsnaam = trim($data['bedrijfsnaam'] ?? '');
                $adres = trim($data['adres'] ?? '');
                $postcode = trim($data['postcode'] ?? '');
                $plaats = trim($data['plaats'] ?? '');
                $contactpersoon = trim($data['contactpersoon'] ?? '');
                $email = trim($data['email'] ?? '');
                $telefoon = trim($data['telefoon'] ?? '');
                $website = trim($data['website'] ?? '');
                $kvk_nummer = trim($data['kvk_nummer'] ?? '');
                $btw_id = trim($data['btw_id'] ?? '');
                
                if (!$bedrijfsnaam || !$adres || !$contactpersoon || !$email) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Vul alle verplichte velden in']);
                    exit;
                }
                
                if ($email !== $account['email']) {
                    $stmt = $pdo->prepare("SELECT id FROM business_accounts WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $id]);
                    if ($stmt->fetch()) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Dit e-mailadres is al in gebruik']);
                        exit;
                    }
                }
                
                $hasBalanceUpdate = isset($data['has_balance']) ? (!empty($data['has_balance']) ? 1 : 0) : null;
                $deliveryEnabledUpdate = isset($data['delivery_enabled']) ? (!empty($data['delivery_enabled']) ? 1 : 0) : null;
                $deliveryCostUpdate = isset($data['delivery_cost']) ? floatval($data['delivery_cost']) : null;

                $sql = "UPDATE business_accounts SET bedrijfsnaam = ?, adres = ?, postcode = ?, plaats = ?, contactpersoon = ?, email = ?, telefoon = ?, website = ?, kvk_nummer = ?, btw_id = ?";
                $params = [$bedrijfsnaam, $adres, $postcode, $plaats, $contactpersoon, $email, $telefoon, $website, $kvk_nummer, $btw_id];

                if ($hasBalanceUpdate !== null) {
                    $sql .= ", has_balance = ?";
                    $params[] = $hasBalanceUpdate;
                }

                if ($deliveryEnabledUpdate !== null) {
                    $sql .= ", delivery_enabled = ?";
                    $params[] = $deliveryEnabledUpdate;
                }

                if ($deliveryCostUpdate !== null) {
                    $sql .= ", delivery_cost = ?";
                    $params[] = $deliveryCostUpdate;
                }

                $sql .= " WHERE id = ?";
                $params[] = $id;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                echo json_encode(['success' => true, 'message' => 'Account bijgewerkt']);
            } elseif ($action === 'set_password') {
                $password = $data['password'] ?? '';
                if (strlen($password) < 8) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Wachtwoord moet minimaal 8 tekens bevatten']);
                    exit;
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE business_accounts SET password_hash = ?, invite_accepted_at = NOW() WHERE id = ?");
                $stmt->execute([$hash, $id]);
                echo json_encode(['success' => true, 'message' => 'Wachtwoord ingesteld en account geactiveerd']);
            } elseif ($action === 'send_invite' || $action === 'reset_password') {
                // Generate fresh invite token; clears previous accepted state so link can be used
                $inviteToken = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("UPDATE business_accounts SET invite_token = ?, invite_accepted_at = NULL WHERE id = ?");
                $stmt->execute([$inviteToken, $id]);

                $inviteUrl = 'https://bakkerij-civetta.nl/uitnodiging.php?token=' . $inviteToken;
                $bedrijf = getBedrijfsGegevens($pdo);
                $htmlBody = buildAccountInviteEmail($account, $inviteUrl, $bedrijf, $pdo);
                $subject = $action === 'reset_password'
                    ? getEmailSubject($pdo, 'wachtwoord_reset', 'Wachtwoord opnieuw instellen — Bakkerij Civetta')
                    : getEmailSubject($pdo, 'account_uitnodiging', 'Uitnodiging — Bakkerij Civetta');
                sendHtmlEmail($account['email'], $subject, $htmlBody);

                echo json_encode(['success' => true, 'message' => 'Uitnodigingsmail verstuurd naar ' . $account['email']]);
            } elseif ($action === 'send_tweede_invite' || $action === 'reset_tweede_password') {
                $tweedeEmail = trim($data['tweede_email'] ?? $account['tweede_email'] ?? '');
                $tweedeContactpersoon = trim($data['tweede_contactpersoon'] ?? $account['tweede_contactpersoon'] ?? '');

                if (!$tweedeEmail || !filter_var($tweedeEmail, FILTER_VALIDATE_EMAIL)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Geldig e-mailadres voor tweede contactpersoon is verplicht']);
                    exit;
                }

                // Ensure tweede_email is unique across all accounts (excluding this one)
                $stmt = $pdo->prepare("SELECT id FROM business_accounts WHERE tweede_email = ? AND id != ?");
                $stmt->execute([$tweedeEmail, $id]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Dit e-mailadres is al in gebruik']);
                    exit;
                }
                $stmt = $pdo->prepare("SELECT id FROM business_accounts WHERE email = ? AND id != ?");
                $stmt->execute([$tweedeEmail, $id]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Dit e-mailadres is al in gebruik als primair e-mailadres']);
                    exit;
                }

                $tweedeToken = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("UPDATE business_accounts SET tweede_email = ?, tweede_contactpersoon = ?, tweede_invite_token = ?, tweede_invite_accepted_at = NULL WHERE id = ?");
                $stmt->execute([$tweedeEmail, $tweedeContactpersoon, $tweedeToken, $id]);

                $inviteUrl = 'https://bakkerij-civetta.nl/uitnodiging.php?token=' . $tweedeToken;
                $bedrijf = getBedrijfsGegevens($pdo);
                $tweedeAccount = array_merge($account, ['contactpersoon' => $tweedeContactpersoon ?: $account['contactpersoon']]);
                $htmlBody = buildAccountInviteEmail($tweedeAccount, $inviteUrl, $bedrijf, $pdo);
                $subject = $action === 'reset_tweede_password'
                    ? getEmailSubject($pdo, 'wachtwoord_reset', 'Wachtwoord opnieuw instellen — Bakkerij Civetta')
                    : getEmailSubject($pdo, 'account_uitnodiging', 'Uitnodiging — Bakkerij Civetta');
                sendHtmlEmail($tweedeEmail, $subject, $htmlBody);

                echo json_encode(['success' => true, 'message' => 'Uitnodigingsmail verstuurd naar ' . $tweedeEmail]);
            } elseif ($action === 'update_tweede_contact') {
                $tweedeEmail = trim($data['tweede_email'] ?? '');
                $tweedeContactpersoon = trim($data['tweede_contactpersoon'] ?? '');

                if ($tweedeEmail && !filter_var($tweedeEmail, FILTER_VALIDATE_EMAIL)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Ongeldig e-mailadres']);
                    exit;
                }
                if ($tweedeEmail) {
                    $stmt = $pdo->prepare("SELECT id FROM business_accounts WHERE tweede_email = ? AND id != ?");
                    $stmt->execute([$tweedeEmail, $id]);
                    if ($stmt->fetch()) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Dit e-mailadres is al in gebruik']);
                        exit;
                    }
                    $stmt = $pdo->prepare("SELECT id FROM business_accounts WHERE email = ? AND id != ?");
                    $stmt->execute([$tweedeEmail, $id]);
                    if ($stmt->fetch()) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Dit e-mailadres is al in gebruik als primair e-mailadres']);
                        exit;
                    }
                }
                $stmt = $pdo->prepare("UPDATE business_accounts SET tweede_contactpersoon = ?, tweede_email = ? WHERE id = ?");
                $stmt->execute([$tweedeContactpersoon ?: null, $tweedeEmail ?: null, $id]);
                echo json_encode(['success' => true, 'message' => 'Tweede contactpersoon bijgewerkt']);
            } elseif ($action === 'remove_tweede_contact') {
                $stmt = $pdo->prepare("UPDATE business_accounts SET tweede_contactpersoon = NULL, tweede_email = NULL, tweede_password_hash = NULL, tweede_invite_token = NULL, tweede_invite_accepted_at = NULL, tweede_invite_opened_at = NULL, tweede_pw_reset_token = NULL, tweede_pw_reset_expires = NULL WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Tweede contactpersoon verwijderd']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ongeldige actie']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'DELETE':
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
            exit;
        }
        
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM business_accounts WHERE id = ?");
            $stmt->execute([$id]);
            $account = $stmt->fetch();

            if (!$account) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Account niet gevonden']);
                exit;
            }

            // GDPR: anonymize PII instead of hard-deleting, so invoices and orders remain intact
            $stmt = $pdo->prepare("
                UPDATE business_accounts SET
                    bedrijfsnaam           = '[Verwijderd]',
                    contactpersoon         = '',
                    email                  = CONCAT('deleted_', id, '@deleted.invalid'),
                    telefoon               = '',
                    website                = '',
                    adres                  = '',
                    postcode               = '',
                    plaats                 = '',
                    kvk_nummer             = '',
                    btw_id                 = '',
                    opmerkingen            = '',
                    password_hash          = NULL,
                    invite_token           = NULL,
                    tweede_contactpersoon  = NULL,
                    tweede_email           = NULL,
                    tweede_password_hash   = NULL,
                    tweede_invite_token    = NULL,
                    tweede_invite_accepted_at = NULL,
                    status                 = 'rejected'
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Account verwijderd']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
