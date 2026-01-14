<?php
require_once 'config.php';
require_once '../api/eboekhouden.php';
requireLogin();

$message = '';
$messageType = '';
$testResult = null;

$boekhoudingVelden = [
    'facturatie_systeem' => 'eigen',
    'eboekhouden_api_token' => '',
    'eboekhouden_template_id' => '',
    'eboekhouden_ledger_id' => '',
    'btw_tarief' => '9'
];

$huidigeWaarden = [];
foreach (array_keys($boekhoudingVelden) as $key) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetchColumn();
    $huidigeWaarden[$key] = $result !== false ? $result : $boekhoudingVelden[$key];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($boekhoudingVelden) as $key) {
        if (isset($_POST[$key])) {
            $huidigeWaarden[$key] = trim($_POST[$key]);
        }
    }
    
    if (isset($_POST['test_connection'])) {
        $apiToken = trim($_POST['eboekhouden_api_token'] ?? '');
        if ($apiToken) {
            $client = new EBoekhoudenClient($apiToken);
            $testResult = $client->testConnection();
        } else {
            $testResult = ['success' => false, 'message' => 'Vul eerst een API token in'];
        }
    } else {
        try {
            foreach (array_keys($boekhoudingVelden) as $key) {
                $value = trim($_POST[$key] ?? '');
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
                $huidigeWaarden[$key] = $value;
            }
            $message = 'Boekhouding instellingen opgeslagen!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Fout bij opslaan: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boekhouding | Civetta Admin</title>
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
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
        .card h2 {
            color: #5c3d1e;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f5f2ed;
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
        .form-group input[type="text"],
        .form-group input[type="number"] {
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
        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .radio-option {
            display: flex;
            align-items: center;
            padding: 1rem;
            border: 2px solid #e8dfd2;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .radio-option:hover {
            border-color: #d4a574;
        }
        .radio-option.selected {
            border-color: #8b5a2b;
            background: #faf8f5;
        }
        .radio-option input[type="radio"] {
            margin-right: 0.75rem;
            width: 18px;
            height: 18px;
        }
        .radio-option .option-content h3 {
            color: #5c3d1e;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        .radio-option .option-content p {
            color: #888;
            font-size: 0.85rem;
        }
        .eboekhouden-settings {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: #faf8f5;
            border-radius: 8px;
            border: 1px solid #e8dfd2;
            display: none;
        }
        .eboekhouden-settings.active {
            display: block;
        }
        .eboekhouden-settings h3 {
            color: #5c3d1e;
            font-size: 1rem;
            margin-bottom: 1rem;
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
        .btn-test {
            background: #6c757d;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .test-result {
            margin-top: 0.75rem;
            padding: 0.75rem;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .test-result.success {
            background: #d4edda;
            color: #155724;
        }
        .test-result.error {
            background: #f8d7da;
            color: #721c24;
        }
        .help-text {
            font-size: 0.8rem;
            color: #888;
            margin-top: 0.25rem;
        }
        .input-suffix {
            display: flex;
            align-items: center;
        }
        .input-suffix input {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
            flex: 1;
        }
        .input-suffix span {
            background: #e8dfd2;
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            border-left: none;
            border-radius: 0 6px 6px 0;
            color: #5c3d1e;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Boekhouding</h1>
        <div class="header-links">
            <a href="index.php">Dashboard</a>
            <a href="logout.php">Uitloggen</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="card">
                <h2>Facturatiesysteem</h2>
                
                <div class="radio-group">
                    <label class="radio-option <?= $huidigeWaarden['facturatie_systeem'] === 'eigen' ? 'selected' : '' ?>">
                        <input type="radio" name="facturatie_systeem" value="eigen" <?= $huidigeWaarden['facturatie_systeem'] === 'eigen' ? 'checked' : '' ?>>
                        <div class="option-content">
                            <h3>Eigen facturatie (Civetta)</h3>
                            <p>Facturen worden lokaal gegenereerd en verstuurd via de website</p>
                        </div>
                    </label>
                    
                    <label class="radio-option <?= $huidigeWaarden['facturatie_systeem'] === 'eboekhouden' ? 'selected' : '' ?>">
                        <input type="radio" name="facturatie_systeem" value="eboekhouden" <?= $huidigeWaarden['facturatie_systeem'] === 'eboekhouden' ? 'checked' : '' ?>>
                        <div class="option-content">
                            <h3>e-Boekhouden</h3>
                            <p>Facturen worden aangemaakt en verstuurd via e-Boekhouden.nl</p>
                        </div>
                    </label>
                </div>
                
                <div class="eboekhouden-settings <?= $huidigeWaarden['facturatie_systeem'] === 'eboekhouden' ? 'active' : '' ?>">
                    <h3>e-Boekhouden Instellingen</h3>
                    
                    <div class="form-group">
                        <label for="eboekhouden_api_token">API Token</label>
                        <input type="text" id="eboekhouden_api_token" name="eboekhouden_api_token" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_api_token']) ?>">
                        <p class="help-text">Te vinden in e-Boekhouden: Beheer > Instellingen > API/SOAP</p>
                        <button type="submit" name="test_connection" value="1" class="btn btn-test">Test verbinding</button>
                        <?php if ($testResult): ?>
                            <div class="test-result <?= $testResult['success'] ? 'success' : 'error' ?>">
                                <?= htmlspecialchars($testResult['message']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="eboekhouden_template_id">Factuur Template ID</label>
                        <input type="text" id="eboekhouden_template_id" name="eboekhouden_template_id" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_template_id']) ?>">
                        <p class="help-text">ID van je factuursjabloon in e-Boekhouden</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="eboekhouden_ledger_id">Grootboekrekening ID (Omzet)</label>
                        <input type="text" id="eboekhouden_ledger_id" name="eboekhouden_ledger_id" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_ledger_id']) ?>">
                        <p class="help-text">Gebruik het interne ID (bijv. 48086686), niet de code (8000). Te vinden via API: /api/debug-eboekhouden.php?action=ledgers</p>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h2>BTW Instellingen</h2>
                
                <div class="form-group">
                    <label for="btw_tarief">BTW-tarief</label>
                    <div class="input-suffix">
                        <input type="number" id="btw_tarief" name="btw_tarief" value="<?= htmlspecialchars($huidigeWaarden['btw_tarief']) ?>" min="0" max="100" step="0.1">
                        <span>%</span>
                    </div>
                    <p class="help-text">Standaard 9% voor voedingsmiddelen</p>
                </div>
            </div>
            
            <button type="submit" class="btn">Opslaan</button>
        </form>
    </div>
    
    <script>
        document.querySelectorAll('input[name="facturatie_systeem"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.radio-option').forEach(opt => opt.classList.remove('selected'));
                this.closest('.radio-option').classList.add('selected');
                
                const eboekhoudenSettings = document.querySelector('.eboekhouden-settings');
                if (this.value === 'eboekhouden') {
                    eboekhoudenSettings.classList.add('active');
                } else {
                    eboekhoudenSettings.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>
