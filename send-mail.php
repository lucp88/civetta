<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require_once 'admin/config.php';

    // Honeypot spam filter - bots fill in hidden fields
    if (!empty($_POST["_honey"])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO honeypot_logs (pagina, ip_adres, user_agent, ingevulde_waarde) VALUES (?, ?, ?, ?)");
            $stmt->execute(['contact', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', substr($_POST["_honey"], 0, 255)]);
        } catch (Exception $e) {}
        header("Location: bedankt.html");
        exit;
    }

    // reCAPTCHA v3 verification
    if (recaptchaSiteKey() && !verifyRecaptcha($_POST['recaptcha_token'] ?? '', 'contact')) {
        header("Location: contact.html?error=1");
        exit;
    }

    $name = strip_tags(trim($_POST["name"]));
    $email = filter_var(trim($_POST["email"]), FILTER_SANITIZE_EMAIL);
    $message = trim($_POST["message"]);

    if (empty($name) || empty($message) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        header("Location: contact.html?error=1");
        exit;
    }
    
    $to = "info@bakkerij-civetta.nl";
    $subject = "Nieuw bericht via website van $name";
    
    $body = "Je hebt een nieuw bericht ontvangen via de website.\n\n";
    $body .= "Naam: $name\n";
    $body .= "E-mail: $email\n\n";
    $body .= "Bericht:\n$message\n";
    
    $headers = "From: noreply@bakkerij-civetta.nl\r\n";
    $headers .= "Reply-To: $email\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    if (mail($to, $subject, $body, $headers)) {
        header("Location: bedankt.html");
    } else {
        header("Location: contact.html?error=1");
    }
    exit;
}

header("Location: contact.html");
exit;
?>
