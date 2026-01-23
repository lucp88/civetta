<?php
require_once 'config.php';
requireLogin();

$message = '';
$messageType = '';

$bedrijfsVelden = [
    'bedrijf_naam' => 'Bedrijfsnaam',
    'bedrijf_contactpersoon' => 'Contactpersoon',
    'bedrijf_adres' => 'Adres',
    'bedrijf_postcode' => 'Postcode',
    'bedrijf_plaats' => 'Plaats',
    'bedrijf_telefoon' => 'Telefoon',
    'bedrijf_email' => 'E-mailadres',
    'bedrijf_kvk' => 'KvK nummer',
    'bedrijf_btw_id' => 'BTW-id'
];

$rekeningVelden = [
    'bedrijf_iban' => 'IBAN',
    'bedrijf_bic' => 'BIC',
    'bedrijf_rekeninghouder' => 'Naam rekeninghouder'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $alleVelden = array_merge($bedrijfsVelden, $rekeningVelden);
        foreach ($alleVelden as $key => $label) {
            $value = trim($_POST[$key] ?? '');
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        $message = 'Bedrijfsgegevens opgeslagen!';
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = 'Fout bij opslaan: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$huidigeWaarden = [];
$alleVelden = array_merge($bedrijfsVelden, $rekeningVelden);
foreach (array_keys($alleVelden) as $key) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $huidigeWaarden[$key] = $stmt->fetchColumn() ?: '';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bedrijfsgegevens | Civetta Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 1.5rem; }
        .header-links { display: flex; gap: 1rem; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
        }
        .container {
            max-width: 700px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .card + .card {
            margin-top: 2rem;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 2rem;
        }
        .card h2 {
            color: #5c3d1e;
            margin-bottom: 1.5rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #5c3d1e;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }
        .form-group input:focus {
            outline: none;
            border-color: #8b5a2b;
        }
        .btn {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .message {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Bedrijfsgegevens</h1>
        <div class="header-links">
            <a href="index.php">Dashboard</a>
            <a href="logout.php">Uitloggen</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <h2>Bedrijfsgegevens Bakkerij</h2>
            
            <?php if ($message): ?>
                <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="bedrijf_naam">Bedrijfsnaam</label>
                    <input type="text" id="bedrijf_naam" name="bedrijf_naam" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_naam']) ?>">
                </div>
                
                <div class="form-group">
                    <label for="bedrijf_contactpersoon">Contactpersoon</label>
                    <input type="text" id="bedrijf_contactpersoon" name="bedrijf_contactpersoon" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_contactpersoon']) ?>">
                </div>
                
                <div class="form-group">
                    <label for="bedrijf_adres">Adres</label>
                    <input type="text" id="bedrijf_adres" name="bedrijf_adres" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_adres']) ?>">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="bedrijf_postcode">Postcode</label>
                        <input type="text" id="bedrijf_postcode" name="bedrijf_postcode" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_postcode']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="bedrijf_plaats">Plaats</label>
                        <input type="text" id="bedrijf_plaats" name="bedrijf_plaats" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_plaats']) ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="bedrijf_telefoon">Telefoon</label>
                    <input type="text" id="bedrijf_telefoon" name="bedrijf_telefoon" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_telefoon']) ?>">
                </div>
                
                <div class="form-group">
                    <label for="bedrijf_email">E-mailadres</label>
                    <input type="email" id="bedrijf_email" name="bedrijf_email" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_email']) ?>">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="bedrijf_kvk">KvK nummer</label>
                        <input type="text" id="bedrijf_kvk" name="bedrijf_kvk" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_kvk']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="bedrijf_btw_id">BTW-id</label>
                        <input type="text" id="bedrijf_btw_id" name="bedrijf_btw_id" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_btw_id']) ?>">
                    </div>
                </div>
                
                <button type="submit" class="btn">Opslaan</button>
            </form>
        </div>
        
        <div class="card">
            <h2>Bedrijfsrekening (voor QR betalingen)</h2>
            <p style="color: #666; margin-bottom: 1.5rem; font-size: 0.9rem;">Deze gegevens worden gebruikt voor het genereren van EPC QR codes waarmee klanten eenvoudig kunnen betalen via hun bank-app.</p>
            
            <form method="POST">
                <?php foreach ($bedrijfsVelden as $key => $label): ?>
                    <input type="hidden" name="<?= $key ?>" value="<?= htmlspecialchars($huidigeWaarden[$key]) ?>">
                <?php endforeach; ?>
                
                <div class="form-group">
                    <label for="bedrijf_iban">IBAN</label>
                    <input type="text" id="bedrijf_iban" name="bedrijf_iban" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_iban']) ?>" placeholder="NL00BANK0123456789">
                </div>
                
                <div class="form-group">
                    <label for="bedrijf_bic">BIC</label>
                    <input type="text" id="bedrijf_bic" name="bedrijf_bic" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_bic']) ?>" placeholder="INGBNL2A">
                </div>
                
                <div class="form-group">
                    <label for="bedrijf_rekeninghouder">Naam rekeninghouder</label>
                    <input type="text" id="bedrijf_rekeninghouder" name="bedrijf_rekeninghouder" value="<?= htmlspecialchars($huidigeWaarden['bedrijf_rekeninghouder']) ?>" placeholder="Bakkerij Civetta">
                </div>
                
                <button type="submit" class="btn">Opslaan</button>
            </form>
        </div>
    </div>
</body>
</html>
