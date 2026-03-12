<?php
require_once 'admin/config.php';
require_once 'lib/shared.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;
$account = null;

if (strlen($token) !== 64 || !ctype_xdigit($token)) {
    $error = 'Ongeldige resetlink.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT * FROM business_accounts WHERE pw_reset_token = ?");
        $stmt->execute([$token]);
        $account = $stmt->fetch();

        if (!$account) {
            $error = 'Ongeldige of verlopen resetlink.';
        } elseif ($account['pw_reset_expires'] === null || new DateTime() > new DateTime($account['pw_reset_expires'])) {
            $error = 'Deze resetlink is verlopen. Vraag een nieuwe aan via <a href="/wachtwoord-vergeten.php">wachtwoord vergeten</a>.';
            $account = null;
        }
    } catch (PDOException $e) {
        $error = 'Er is een fout opgetreden. Probeer het later opnieuw.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account && !$error) {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Wachtwoord moet minimaal 8 tekens bevatten.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Wachtwoorden komen niet overeen.';
    } else {
        try {
            // Re-check expiry at submit time
            $stmt = $pdo->prepare("SELECT pw_reset_expires FROM business_accounts WHERE pw_reset_token = ?");
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            if (!$row || new DateTime() > new DateTime($row['pw_reset_expires'])) {
                $error = 'Deze resetlink is verlopen. Vraag een nieuwe aan via <a href="/wachtwoord-vergeten.php">wachtwoord vergeten</a>.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE business_accounts SET password_hash = ?, pw_reset_token = NULL, pw_reset_expires = NULL WHERE pw_reset_token = ?");
                $stmt->execute([$hash, $token]);
                if ($stmt->rowCount() === 1) {
                    $success = true;
                } else {
                    $error = 'Er is een fout opgetreden. Probeer het opnieuw.';
                }
            }
        } catch (PDOException $e) {
            $error = 'Er is een fout opgetreden. Probeer het later opnieuw.';
        }
    }
}

$bedrijf = [];
try {
    $bedrijf = getBedrijfsGegevens($pdo);
} catch (Exception $e) {}

$bedrijfsnaam = $bedrijf['bedrijfsnaam'] ?? 'Bakkerij Civetta';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nieuw wachtwoord instellen | <?= htmlspecialchars($bedrijfsnaam) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
        }
        .logo { text-align: center; margin-bottom: 2rem; }
        .logo h1 { color: #5c3d1e; font-size: 1.5rem; font-weight: 700; letter-spacing: 0.05em; }
        .logo p { color: #8b5a2b; font-size: 0.9rem; margin-top: 0.25rem; }
        h2 { color: #2d4a2d; font-size: 1.2rem; margin-bottom: 1rem; }
        .greeting { color: #555; font-size: 0.95rem; margin-bottom: 1.5rem; line-height: 1.5; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: 600; color: #2d4a2d; font-size: 0.85rem; margin-bottom: 0.35rem; }
        input[type="password"] {
            width: 100%; padding: 0.65rem 0.75rem; border: 1px solid #ddd;
            border-radius: 8px; font-size: 1rem; transition: border-color 0.2s;
        }
        input[type="password"]:focus { outline: none; border-color: #8b5a2b; box-shadow: 0 0 0 3px rgba(139,90,43,0.1); }
        .hint { font-size: 0.8rem; color: #888; margin-top: 0.25rem; }
        .btn {
            width: 100%; padding: 0.75rem; background: #8b5a2b; color: white;
            border: none; border-radius: 8px; font-size: 1rem; font-weight: 600;
            cursor: pointer; margin-top: 0.5rem; transition: background 0.2s;
        }
        .btn:hover { background: #5c3d1e; }
        .alert { padding: 0.85rem 1rem; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.25rem; }
        .alert-error { background: #fdecea; color: #c62828; border: 1px solid #f5c6c6; }
        .alert-error a { color: #c62828; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .login-link { text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: #666; }
        .login-link a { color: #8b5a2b; text-decoration: none; font-weight: 600; }
        .login-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1><?= htmlspecialchars($bedrijfsnaam) ?></h1>
        <p>Zakelijk portaal</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
        <div class="login-link"><a href="/login.html">Terug naar inloggen</a></div>

    <?php elseif ($success): ?>
        <div class="alert alert-success">Uw wachtwoord is succesvol gewijzigd.</div>
        <h2>Klaar om in te loggen</h2>
        <p class="greeting">U kunt nu inloggen met uw e-mailadres en het nieuwe wachtwoord.</p>
        <div class="login-link" style="margin-top: 1rem;">
            <a href="/login.html">Nu inloggen →</a>
        </div>

    <?php else: ?>
        <h2>Nieuw wachtwoord instellen</h2>
        <p class="greeting">Stel een nieuw wachtwoord in voor uw account (<?= htmlspecialchars($account['email']) ?>).</p>

        <form method="POST">
            <div class="form-group">
                <label for="password">Nieuw wachtwoord</label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
                <p class="hint">Minimaal 8 tekens</p>
            </div>
            <div class="form-group">
                <label for="password_confirm">Wachtwoord bevestigen</label>
                <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn">Wachtwoord opslaan</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
