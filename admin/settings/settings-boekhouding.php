<?php
require_once '../config.php';
require_once '../../api/eboekhouden.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage = 'settings-boekhouding';
$adminBasePath = '../';

$message = '';
$messageType = '';
$testResult = null;
$ledgers = [];
$templates = [];

$boekhoudingVelden = [
    'facturatie_systeem' => 'eigen',
    'eboekhouden_api_token' => '',
    'eboekhouden_template_id_betaald' => '',
    'eboekhouden_template_id_openstaand' => '',
    'eboekhouden_ledger_id' => '',
    'eboekhouden_debiteuren_ledger_id' => '',
    'eboekhouden_bank_ledger_id' => '',
    'btw_tarief' => '9',
    'facturatie_moment' => 'op_leverdag',
    'facturatie_uur' => '17:00',
    'facturatie_dagen_offset' => '0',
    'bestel_wijzig_deadline_uren' => '48'
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
    } elseif (isset($_POST['load_ledgers'])) {
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

if ($huidigeWaarden['facturatie_systeem'] === 'eboekhouden' && !empty($huidigeWaarden['eboekhouden_api_token'])) {
    try {
        $client = new EBoekhoudenClient($huidigeWaarden['eboekhouden_api_token']);
        $ledgersResult = $client->getLedgers();
        $ledgers = $ledgersResult['items'] ?? [];
        
        $templatesResult = $client->getInvoiceTemplates();
        $templates = $templatesResult['items'] ?? $templatesResult['data'] ?? [];
    } catch (Exception $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boekhouding | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--cream);
            min-height: 100vh;
        }
        .admin-content {
            padding: 2rem;
            max-width: 700px;
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
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
        .form-group input[type="time"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }
        .form-group input[type="time"]:focus {
            outline: none;
            border-color: #8b5a2b;
        }
        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            background: white;
            cursor: pointer;
        }
        .form-select:focus {
            outline: none;
            border-color: #8b5a2b;
        }
        .no-ledgers-notice {
            padding: 0.75rem;
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #856404;
        }
        .select-with-manual {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .select-with-manual .form-select {
            flex: 2;
        }
        .select-with-manual .manual-input {
            flex: 1;
            min-width: 120px;
        }
        .breadcrumb {
            margin-bottom: 1.5rem;
        }
        .breadcrumb a {
            color: #8b5a2b;
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .breadcrumb span {
            color: #888;
            margin: 0 0.5rem;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include '../components/sidebar.php'; ?>

        <div class="admin-main">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-toggle" onclick="toggleSidebar()">
                        <i class="bi bi-list"></i>
                    </button>
                    <span class="topbar-title">Boekhouding</span>
                </div>
                <div class="topbar-right">
                    <a href="settings-bedrijf.php" class="topbar-link">
                        <i class="bi bi-building"></i> <span>Bedrijfsgegevens</span>
                    </a>
                </div>
            </header>

            <div class="admin-content">
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a>
                    <span>›</span>
                    Boekhouding
                </div>

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
                                <label for="eboekhouden_template_id_betaald">Factuur Template - Betaald</label>
                                <?php if (!empty($templates)): ?>
                                <div class="select-with-manual">
                                    <select id="eboekhouden_template_id_betaald_select" class="form-select" onchange="document.getElementById('eboekhouden_template_id_betaald').value = this.value">
                                        <option value="">-- Selecteer template --</option>
                                        <?php foreach ($templates as $template): ?>
                                        <option value="<?= $template['id'] ?>" <?= $huidigeWaarden['eboekhouden_template_id_betaald'] == $template['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($template['name'] ?? $template['description'] ?? 'Template ' . $template['id']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" id="eboekhouden_template_id_betaald" name="eboekhouden_template_id_betaald" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_template_id_betaald']) ?>" class="manual-input" placeholder="of handmatig ID">
                                </div>
                                <?php else: ?>
                                <input type="text" id="eboekhouden_template_id_betaald" name="eboekhouden_template_id_betaald" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_template_id_betaald']) ?>">
                                <?php endif; ?>
                                <p class="help-text">Template voor facturen die direct betaald zijn (via Mollie)</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="eboekhouden_template_id_openstaand">Factuur Template - Openstaand</label>
                                <?php if (!empty($templates)): ?>
                                <div class="select-with-manual">
                                    <select id="eboekhouden_template_id_openstaand_select" class="form-select" onchange="document.getElementById('eboekhouden_template_id_openstaand').value = this.value">
                                        <option value="">-- Selecteer template --</option>
                                        <?php foreach ($templates as $template): ?>
                                        <option value="<?= $template['id'] ?>" <?= $huidigeWaarden['eboekhouden_template_id_openstaand'] == $template['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($template['name'] ?? $template['description'] ?? 'Template ' . $template['id']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" id="eboekhouden_template_id_openstaand" name="eboekhouden_template_id_openstaand" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_template_id_openstaand']) ?>" class="manual-input" placeholder="of handmatig ID">
                                </div>
                                <?php else: ?>
                                <input type="text" id="eboekhouden_template_id_openstaand" name="eboekhouden_template_id_openstaand" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_template_id_openstaand']) ?>">
                                <?php endif; ?>
                                <p class="help-text">Template voor facturen met betaaltermijn (betaal later)</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="eboekhouden_ledger_id">Grootboekrekening (Omzet)</label>
                                <?php if (!empty($ledgers)): ?>
                                <div class="select-with-manual">
                                    <select id="eboekhouden_ledger_id_select" class="form-select" onchange="document.getElementById('eboekhouden_ledger_id').value = this.value">
                                        <option value="">-- Selecteer grootboek --</option>
                                        <?php foreach ($ledgers as $ledger): ?>
                                        <option value="<?= $ledger['id'] ?>" <?= $huidigeWaarden['eboekhouden_ledger_id'] == $ledger['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ledger['code'] . ' - ' . $ledger['description']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" id="eboekhouden_ledger_id" name="eboekhouden_ledger_id" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_ledger_id']) ?>" class="manual-input" placeholder="of handmatig ID">
                                </div>
                                <?php else: ?>
                                <input type="text" id="eboekhouden_ledger_id" name="eboekhouden_ledger_id" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_ledger_id']) ?>">
                                <?php endif; ?>
                                <p class="help-text">Meestal 8000 (Omzet). Hier wordt de omzet op geboekt.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="eboekhouden_debiteuren_ledger_id">Grootboekrekening (Debiteuren)</label>
                                <?php if (!empty($ledgers)): ?>
                                <div class="select-with-manual">
                                    <select id="eboekhouden_debiteuren_ledger_id_select" class="form-select" onchange="document.getElementById('eboekhouden_debiteuren_ledger_id').value = this.value">
                                        <option value="">-- Selecteer grootboek --</option>
                                        <?php foreach ($ledgers as $ledger): ?>
                                        <option value="<?= $ledger['id'] ?>" <?= $huidigeWaarden['eboekhouden_debiteuren_ledger_id'] == $ledger['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ledger['code'] . ' - ' . $ledger['description']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" id="eboekhouden_debiteuren_ledger_id" name="eboekhouden_debiteuren_ledger_id" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_debiteuren_ledger_id']) ?>" class="manual-input" placeholder="of handmatig ID">
                                </div>
                                <?php else: ?>
                                <input type="text" id="eboekhouden_debiteuren_ledger_id" name="eboekhouden_debiteuren_ledger_id" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_debiteuren_ledger_id']) ?>">
                                <?php endif; ?>
                                <p class="help-text">Meestal 1300 (Debiteuren). Nodig om facturen in openstaande posten te boeken.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="eboekhouden_bank_ledger_id">Grootboekrekening (Bank)</label>
                                <?php if (!empty($ledgers)): ?>
                                <div class="select-with-manual">
                                    <select id="eboekhouden_bank_ledger_id_select" class="form-select" onchange="document.getElementById('eboekhouden_bank_ledger_id').value = this.value">
                                        <option value="">-- Selecteer grootboek --</option>
                                        <?php foreach ($ledgers as $ledger): ?>
                                        <option value="<?= $ledger['id'] ?>" <?= $huidigeWaarden['eboekhouden_bank_ledger_id'] == $ledger['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ledger['code'] . ' - ' . $ledger['description']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" id="eboekhouden_bank_ledger_id" name="eboekhouden_bank_ledger_id" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_bank_ledger_id']) ?>" class="manual-input" placeholder="of handmatig ID">
                                </div>
                                <?php else: ?>
                                <input type="text" id="eboekhouden_bank_ledger_id" name="eboekhouden_bank_ledger_id" value="<?= htmlspecialchars($huidigeWaarden['eboekhouden_bank_ledger_id']) ?>">
                                <?php endif; ?>
                                <p class="help-text">Meestal 1010 (Bank). Nodig voor automatische betalingscontrole via bankmutaties.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <h2>Automatische Facturatie</h2>
                        
                        <div class="form-group">
                            <label>Facturatiemoment</label>
                            <div class="radio-group">
                                <label class="radio-option <?= $huidigeWaarden['facturatie_moment'] === 'voor_leverdag' ? 'selected' : '' ?>">
                                    <input type="radio" name="facturatie_moment" value="voor_leverdag" <?= $huidigeWaarden['facturatie_moment'] === 'voor_leverdag' ? 'checked' : '' ?>>
                                    <div class="option-content">
                                        <h3>Voor leverdag</h3>
                                        <p>Factuur wordt verstuurd X dagen voor de levering</p>
                                    </div>
                                </label>
                                <label class="radio-option <?= $huidigeWaarden['facturatie_moment'] === 'op_leverdag' ? 'selected' : '' ?>">
                                    <input type="radio" name="facturatie_moment" value="op_leverdag" <?= $huidigeWaarden['facturatie_moment'] === 'op_leverdag' ? 'checked' : '' ?>>
                                    <div class="option-content">
                                        <h3>Op leverdag</h3>
                                        <p>Factuur wordt verstuurd op de dag van levering</p>
                                    </div>
                                </label>
                                <label class="radio-option <?= $huidigeWaarden['facturatie_moment'] === 'na_leverdag' ? 'selected' : '' ?>">
                                    <input type="radio" name="facturatie_moment" value="na_leverdag" <?= $huidigeWaarden['facturatie_moment'] === 'na_leverdag' ? 'checked' : '' ?>>
                                    <div class="option-content">
                                        <h3>Na leverdag</h3>
                                        <p>Factuur wordt verstuurd X dagen na de levering</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="facturatie_dagen_offset">Aantal dagen offset</label>
                                <input type="number" id="facturatie_dagen_offset" name="facturatie_dagen_offset" value="<?= htmlspecialchars($huidigeWaarden['facturatie_dagen_offset']) ?>" min="0" max="30">
                                <p class="help-text">0 = op leverdag zelf, 1 = 1 dag voor/na, etc.</p>
                            </div>
                            <div class="form-group">
                                <label for="facturatie_uur">Tijdstip</label>
                                <input type="time" id="facturatie_uur" name="facturatie_uur" value="<?= htmlspecialchars($huidigeWaarden['facturatie_uur']) ?>">
                                <p class="help-text">Tijdstip waarop de cronjob facturen verstuurt</p>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="bestel_wijzig_deadline_uren">Bestelling wijzigen deadline</label>
                            <div class="input-suffix">
                                <input type="number" id="bestel_wijzig_deadline_uren" name="bestel_wijzig_deadline_uren" value="<?= htmlspecialchars($huidigeWaarden['bestel_wijzig_deadline_uren']) ?>" min="0" max="168">
                                <span>uren voor levering</span>
                            </div>
                            <p class="help-text">Klanten kunnen hun bestelling aanpassen tot dit aantal uren voor de leverdatum. Standaard: 48 uur.</p>
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
        </div>
    </div>
    
    <script>
        document.querySelectorAll('input[name="facturatie_systeem"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.radio-option').forEach(opt => {
                    if (opt.querySelector('input[name="facturatie_systeem"]')) {
                        opt.classList.remove('selected');
                    }
                });
                this.closest('.radio-option').classList.add('selected');
                
                const eboekhoudenSettings = document.querySelector('.eboekhouden-settings');
                if (this.value === 'eboekhouden') {
                    eboekhoudenSettings.classList.add('active');
                } else {
                    eboekhoudenSettings.classList.remove('active');
                }
            });
        });
        
        document.querySelectorAll('input[name="facturatie_moment"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.radio-option').forEach(opt => {
                    if (opt.querySelector('input[name="facturatie_moment"]')) {
                        opt.classList.remove('selected');
                    }
                });
                this.closest('.radio-option').classList.add('selected');
            });
        });
    </script>
</body>
</html>
