<?php
require_once '../admin/config.php';
require_once 'cors.php';

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
        
        try {
            if ($status) {
                $stmt = $pdo->prepare("SELECT * FROM business_accounts WHERE status = ? ORDER BY created_at DESC");
                $stmt->execute([$status]);
            } else {
                $stmt = $pdo->query("SELECT * FROM business_accounts ORDER BY created_at DESC");
            }
            $accounts = $stmt->fetchAll();
            echo json_encode(['success' => true, 'accounts' => $accounts]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
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
                INSERT INTO business_accounts (bedrijfsnaam, adres, postcode, plaats, contactpersoon, email, telefoon, website, kvk_nummer, btw_id, opmerkingen, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$bedrijfsnaam, $adres, $postcode, $plaats, $contactpersoon, $email, $telefoon, $website, $kvk_nummer, $btw_id, $opmerkingen]);
            
            $id = $pdo->lastInsertId();
            
            $to = "laurens@bakkerij-civetta.nl";
            $subject = "Nieuwe zakelijke accountaanvraag: $bedrijfsnaam";
            $body = "Er is een nieuwe zakelijke accountaanvraag binnengekomen.\n\n";
            $body .= "Bedrijfsnaam: $bedrijfsnaam\n";
            $body .= "Adres: $adres, $postcode $plaats\n";
            $body .= "KVK-nummer: $kvk_nummer\n";
            $body .= "BTW-ID: $btw_id\n";
            $body .= "Contactpersoon: $contactpersoon\n";
            $body .= "E-mail: $email\n";
            $body .= "Telefoon: $telefoon\n";
            $body .= "Website: $website\n";
            $body .= "Opmerkingen: $opmerkingen\n\n";
            $body .= "Bekijk en beheer deze aanvraag in het admin panel.";
            
            $headers = "From: noreply@bakkerij-civetta.nl\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            @mail($to, $subject, $body, $headers);
            
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Aanvraag succesvol ingediend']);
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
                $password = bin2hex(random_bytes(8));
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("UPDATE business_accounts SET status = 'approved', approved_at = NOW(), password_hash = ? WHERE id = ?");
                $stmt->execute([$passwordHash, $id]);
                
                $to = $account['email'];
                $subject = "Uw zakelijk account bij Bakkerij Civetta is goedgekeurd!";
                $body = "Beste " . $account['contactpersoon'] . ",\n\n";
                $body .= "Goed nieuws! Uw zakelijke accountaanvraag voor " . $account['bedrijfsnaam'] . " is goedgekeurd.\n\n";
                $body .= "U kunt nu inloggen op ons zakelijk portaal met de volgende gegevens:\n\n";
                $body .= "Inlogpagina: https://bakkerij-civetta.nl/login-bedrijven.html\n";
                $body .= "Gebruikersnaam: " . $account['email'] . "\n";
                $body .= "Wachtwoord: " . $password . "\n\n";
                $body .= "Wij raden u aan om uw wachtwoord te wijzigen na de eerste keer inloggen.\n\n";
                $body .= "Heeft u vragen? Neem gerust contact met ons op.\n\n";
                $body .= "Met vriendelijke groet,\n";
                $body .= "Bakkerij Civetta\n";
                $body .= "laurens@bakkerij-civetta.nl";
                
                $headers = "From: noreply@bakkerij-civetta.nl\r\n";
                $headers .= "Reply-To: laurens@bakkerij-civetta.nl\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                
                @mail($to, $subject, $body, $headers);
                
                echo json_encode(['success' => true, 'message' => 'Account goedgekeurd en e-mail met inloggegevens verzonden']);
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
                
                $stmt = $pdo->prepare("
                    UPDATE business_accounts 
                    SET bedrijfsnaam = ?, adres = ?, postcode = ?, plaats = ?, contactpersoon = ?, email = ?, telefoon = ?, website = ?, kvk_nummer = ?, btw_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$bedrijfsnaam, $adres, $postcode, $plaats, $contactpersoon, $email, $telefoon, $website, $kvk_nummer, $btw_id, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Account bijgewerkt']);
            } elseif ($action === 'reset_password') {
                $password = bin2hex(random_bytes(8));
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("UPDATE business_accounts SET password_hash = ? WHERE id = ?");
                $stmt->execute([$passwordHash, $id]);
                
                $to = $account['email'];
                $subject = "Nieuw wachtwoord voor uw Bakkerij Civetta account";
                $body = "Beste " . $account['contactpersoon'] . ",\n\n";
                $body .= "Er is een nieuw wachtwoord aangemaakt voor uw account.\n\n";
                $body .= "Uw nieuwe inloggegevens:\n";
                $body .= "Gebruikersnaam: " . $account['email'] . "\n";
                $body .= "Wachtwoord: " . $password . "\n\n";
                $body .= "Met vriendelijke groet,\n";
                $body .= "Bakkerij Civetta";
                
                $headers = "From: noreply@bakkerij-civetta.nl\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                
                @mail($to, $subject, $body, $headers);
                
                echo json_encode(['success' => true, 'message' => 'Nieuw wachtwoord verzonden naar ' . $account['email']]);
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
            
            $stmt = $pdo->prepare("DELETE FROM business_accounts WHERE id = ?");
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
