<?php
require_once 'admin/config.php';
require_once 'lib/shared.php';
require_once 'api/email-templates.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;
$alreadyAccepted = false;
$account = null;

if (strlen($token) !== 64 || !ctype_xdigit($token)) {
    $error = 'Ongeldige uitnodigingslink.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT * FROM business_accounts WHERE invite_token = ?");
        $stmt->execute([$token]);
        $account = $stmt->fetch();

        if (!$account) {
            $error = 'Ongeldige of verlopen uitnodigingslink.';
        } elseif ($account['invite_accepted_at'] !== null) {
            $alreadyAccepted = true;
        } elseif ($account['invite_opened_at'] === null) {
            $pdo->prepare("UPDATE business_accounts SET invite_opened_at = NOW() WHERE invite_token = ? AND invite_opened_at IS NULL")
                ->execute([$token]);
            $account['invite_opened_at'] = date('Y-m-d H:i:s');
        }
    } catch (PDOException $e) {
        $error = 'Er is een fout opgetreden. Probeer het later opnieuw.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account && !$alreadyAccepted && !$error) {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Wachtwoord moet minimaal 8 tekens bevatten.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Wachtwoorden komen niet overeen.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE business_accounts SET password_hash = ?, invite_accepted_at = NOW() WHERE invite_token = ? AND invite_accepted_at IS NULL");
            $stmt->execute([$hash, $token]);

            if ($stmt->rowCount() === 1) {
                $success = true;
            } else {
                $error = 'Deze uitnodiging is al gebruikt.';
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
    <title>Account activeren | <?= htmlspecialchars($bedrijfsnaam) ?></title>
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
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo h1 {
            color: #5c3d1e;
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.05em;
        }
        .logo p {
            color: #8b5a2b;
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }
        h2 {
            color: #2d4a2d;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }
        .greeting {
            color: #555;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        label {
            display: block;
            font-weight: 600;
            color: #2d4a2d;
            font-size: 0.85rem;
            margin-bottom: 0.35rem;
        }
        input[type="password"] {
            width: 100%;
            padding: 0.65rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: #8b5a2b;
            box-shadow: 0 0 0 3px rgba(139,90,43,0.1);
        }
        .hint {
            font-size: 0.8rem;
            color: #888;
            margin-top: 0.25rem;
        }
        .btn {
            width: 100%;
            padding: 0.75rem;
            background: #8b5a2b;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 0.5rem;
            transition: background 0.2s;
        }
        .btn:hover { background: #5c3d1e; }
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
        }
        .alert-error { background: #fdecea; color: #c62828; border: 1px solid #f5c6c6; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-info { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: #666;
        }
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
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <div class="login-link">
            <a href="/login.html">Terug naar inloggen</a>
        </div>

    <?php elseif ($alreadyAccepted): ?>
        <div class="alert alert-info">
            Je account is al geactiveerd. Je kunt direct inloggen.
        </div>
        <div class="login-link">
            <a href="/login.html">Naar inloggen →</a>
        </div>

    <?php elseif ($success): ?>
        <div class="alert alert-success">
            Gelukt! Je wachtwoord is ingesteld en je account is geactiveerd.
        </div>
        <h2>Klaar om in te loggen</h2>
        <p class="greeting">Je kunt nu inloggen met je e-mailadres en het wachtwoord dat je zojuist hebt ingesteld.</p>
        <div class="login-link" style="margin-top: 1rem;">
            <a href="/login.html">Nu inloggen →</a>
        </div>

    <?php else: ?>
        <h2>Welkom, <?= htmlspecialchars($account['contactpersoon']) ?>!</h2>
        <p class="greeting">
            Stel een wachtwoord in voor je zakelijke account bij <?= htmlspecialchars($bedrijfsnaam) ?>
            (<?= htmlspecialchars($account['bedrijfsnaam']) ?>).
        </p>

        <?php if ($error && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

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
            <button type="submit" class="btn">Account activeren</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
