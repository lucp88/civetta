<?php
require_once '../config.php';
require_once '../../lib/includes/functions.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'E-mail templates';
$currentPage = 'email-templates';
$adminBasePath = '../';

$success = '';
$error = '';

$categories = [
    'Bestellingen' => ['bestelbevestiging', 'annulering', 'bestelling_aangepast'],
    'Terugkerende bestellingen' => ['terugkerend_bevestiging', 'terugkerend_gepauzeerd', 'terugkerend_hervat', 'terugkerend_gewijzigd'],
    'Levering' => ['levering_onderweg'],
    'Account' => ['account_aanvraag', 'account_goedgekeurd', 'account_uitnodiging', 'wachtwoord_reset', 'wachtwoord_vergeten'],
    'Factuur' => ['factuur'],
    'Admin notificaties' => ['admin_nieuwe_bestelling', 'admin_bestelling_gewijzigd'],
];

$templates = [
    'bestelbevestiging' => [
        'label' => 'Bestelbevestiging',
        'description' => 'Wordt verstuurd naar de klant na het plaatsen van een bestelling. De e-mail bevat automatisch een overzicht van de producten, het totaalbedrag en de leverdatum.',
        'subject_key' => 'email_tpl_bestelbevestiging_subject',
        'body_key'    => 'email_tpl_bestelbevestiging_body',
        'default_subject' => 'Bestelling ontvangen — Bakkerij Civetta',
        'default_body' => 'Wij hebben uw bestelling in goede orde ontvangen. Hieronder vindt u een overzicht van uw bestelling.',
        'placeholders' => [
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'   => 'Naam van het bedrijf',
            '{bestelnummer}'   => 'Bestelnummer (#ID)',
            '{leverdatum}'     => 'Datum van levering',
        ],
    ],
    'annulering' => [
        'label' => 'Annulering bevestiging',
        'description' => 'Wordt verstuurd wanneer een bestelling wordt geannuleerd. De e-mail bevat automatisch de annuleringsdetails en eventuele terugbetalingsinformatie.',
        'subject_key' => 'email_tpl_annulering_subject',
        'body_key'    => 'email_tpl_annulering_body',
        'default_subject' => 'Bestelling geannuleerd — Bakkerij Civetta',
        'default_body' => 'Hierbij bevestigen wij de annulering van uw bestelling. De bestelling zal niet worden geleverd.',
        'placeholders' => [
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'   => 'Naam van het bedrijf',
            '{bestelnummer}'   => 'Bestelnummer (#ID)',
            '{leverdatum}'     => 'Oorspronkelijke leverdatum',
        ],
    ],
    'bestelling_aangepast' => [
        'label' => 'Bestelling aangepast (door admin)',
        'description' => 'Wordt verstuurd naar de klant wanneer een admin de bestelling wijzigt. De e-mail bevat automatisch het bijgewerkte productoverzicht.',
        'subject_key' => 'email_tpl_bestelling_aangepast_subject',
        'body_key'    => 'email_tpl_bestelling_aangepast_body',
        'default_subject' => 'Uw bestelling is aangepast — Bakkerij Civetta',
        'default_body' => 'Wij hebben wijzigingen aangebracht in uw bestelling. Hieronder vindt u het bijgewerkte overzicht.',
        'placeholders' => [
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'   => 'Naam van het bedrijf',
            '{bestelnummer}'   => 'Bestelnummer (#ID)',
            '{leverdatum}'     => 'Leverdatum',
        ],
    ],
    'terugkerend_bevestiging' => [
        'label' => 'Terugkerende bestelling bevestigd',
        'description' => 'Wordt verstuurd bij het instellen van een nieuwe terugkerende bestelling. De e-mail bevat automatisch de producten, frequentie en ingeplande leverdata.',
        'subject_key' => 'email_tpl_terugkerend_bevestiging_subject',
        'body_key'    => 'email_tpl_terugkerend_bevestiging_body',
        'default_subject' => 'Terugkerende bestelling bevestigd — Bakkerij Civetta',
        'default_body' => 'Bedankt! Uw terugkerende bestelling is succesvol ingesteld. U ontvangt nu automatisch uw bestelling volgens onderstaand schema.',
        'placeholders' => [
            '{contactpersoon}'  => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'    => 'Naam van het bedrijf',
            '{naam_bestelling}' => 'Naam van de terugkerende bestelling',
            '{frequentie}'      => 'Wekelijks / Tweewekelijks / Maandelijks',
        ],
    ],
    'terugkerend_gepauzeerd' => [
        'label' => 'Terugkerende bestelling gepauzeerd',
        'description' => 'Wordt verstuurd wanneer een terugkerende bestelling wordt gepauzeerd. De e-mail bevat automatisch welke leveringen zijn gepauzeerd.',
        'subject_key' => 'email_tpl_terugkerend_gepauzeerd_subject',
        'body_key'    => 'email_tpl_terugkerend_gepauzeerd_body',
        'default_subject' => 'Terugkerende bestelling gepauzeerd — Bakkerij Civetta',
        'default_body' => 'Uw terugkerende bestelling is gepauzeerd. De onderstaande leveringen worden niet uitgevoerd tenzij u de bestelling weer activeert via uw dashboard.',
        'placeholders' => [
            '{contactpersoon}'  => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'    => 'Naam van het bedrijf',
            '{naam_bestelling}' => 'Naam van de terugkerende bestelling',
        ],
    ],
    'terugkerend_hervat' => [
        'label' => 'Terugkerende bestelling hervat',
        'description' => 'Wordt verstuurd wanneer een gepauzeerde terugkerende bestelling wordt hervat.',
        'subject_key' => 'email_tpl_terugkerend_hervat_subject',
        'body_key'    => 'email_tpl_terugkerend_hervat_body',
        'default_subject' => 'Terugkerende bestelling hervat — Bakkerij Civetta',
        'default_body' => 'Uw terugkerende bestelling is weer actief. De onderstaande leveringen worden weer uitgevoerd.',
        'placeholders' => [
            '{contactpersoon}'  => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'    => 'Naam van het bedrijf',
            '{naam_bestelling}' => 'Naam van de terugkerende bestelling',
        ],
    ],
    'terugkerend_gewijzigd' => [
        'label' => 'Terugkerende bestelling gewijzigd',
        'description' => 'Wordt verstuurd wanneer de producten van een terugkerende bestelling worden gewijzigd. De e-mail bevat automatisch de nieuwe producten en bijgewerkte leveringen.',
        'subject_key' => 'email_tpl_terugkerend_gewijzigd_subject',
        'body_key'    => 'email_tpl_terugkerend_gewijzigd_body',
        'default_subject' => 'Terugkerende bestelling aangepast — Bakkerij Civetta',
        'default_body' => 'Hierbij bevestigen wij de wijziging van uw terugkerende bestelling. De nieuwe producten gelden voor alle toekomstige leveringen.',
        'placeholders' => [
            '{contactpersoon}'  => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'    => 'Naam van het bedrijf',
            '{naam_bestelling}' => 'Naam van de terugkerende bestelling',
        ],
    ],
    'levering_onderweg' => [
        'label' => 'Bestelling onderweg',
        'description' => 'Wordt verstuurd wanneer de bakker de leverroute start. De e-mail bevat automatisch het leveradres en de bestelde producten.',
        'subject_key' => 'email_tpl_levering_onderweg_subject',
        'body_key'    => 'email_tpl_levering_onderweg_body',
        'default_subject' => 'Uw bestelling is onderweg! — Bakkerij Civetta',
        'default_body' => 'Goed nieuws! Onze bakker is vertrokken met uw versgebakken bestelling. We zijn nu onderweg naar {bedrijfsnaam}.',
        'placeholders' => [
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'   => 'Naam van het bedrijf',
            '{leveradres}'     => 'Volledig leveradres',
            '{bestelnummer}'   => 'Bestelnummer (#ID)',
        ],
    ],
    'account_aanvraag' => [
        'label' => 'Account aanvraag ontvangen',
        'description' => 'Bevestiging aan de klant dat de zakelijke accountaanvraag is ontvangen en in behandeling is.',
        'subject_key' => 'email_tpl_account_aanvraag_subject',
        'body_key'    => 'email_tpl_account_aanvraag_body',
        'default_subject' => 'Accountaanvraag ontvangen — Bakkerij Civetta',
        'default_body' => 'Bedankt voor uw interesse in Bakkerij Civetta. Wij hebben uw zakelijke accountaanvraag voor {bedrijfsnaam} in goede orde ontvangen.',
        'placeholders' => [
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'   => 'Naam van het bedrijf',
            '{email}'          => 'E-mailadres van de klant',
        ],
    ],
    'account_goedgekeurd' => [
        'label' => 'Account goedgekeurd',
        'description' => 'Wordt verstuurd wanneer een zakelijk account wordt goedgekeurd. De e-mail bevat automatisch de inloggegevens.',
        'subject_key' => 'email_tpl_account_goedgekeurd_subject',
        'body_key'    => 'email_tpl_account_goedgekeurd_body',
        'default_subject' => 'Uw account is goedgekeurd — Bakkerij Civetta',
        'default_body' => "Goed nieuws! Uw zakelijke accountaanvraag voor {bedrijfsnaam} is goedgekeurd.\n\nU kunt nu inloggen op ons zakelijk portaal en direct beginnen met bestellen van ons ambachtelijke brood en gebak.",
        'placeholders' => [
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'   => 'Naam van het bedrijf',
            '{email}'          => 'E-mailadres (gebruikersnaam)',
            '{wachtwoord}'     => 'Tijdelijk wachtwoord',
            '{login_url}'      => 'Link naar de loginpagina',
        ],
    ],
    'account_uitnodiging' => [
        'no_toggle' => true,
        'label' => 'Account uitnodiging',
        'description' => 'Wordt verstuurd wanneer een account wordt aangemaakt via het admin-paneel. De e-mail bevat automatisch de activatielink.',
        'subject_key' => 'email_tpl_account_uitnodiging_subject',
        'body_key'    => 'email_tpl_account_uitnodiging_body',
        'default_subject' => 'Activeer uw account — Bakkerij Civetta',
        'default_body' => 'Uw zakelijke account voor {bedrijfsnaam} is aangemaakt. Klik op de knop hieronder om uw eigen wachtwoord in te stellen en uw account te activeren.',
        'placeholders' => [
            '{contactpersoon}'  => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'    => 'Naam van het bedrijf',
            '{uitnodiging_url}' => 'Activatielink (automatisch toegevoegd)',
        ],
    ],
    'wachtwoord_reset' => [
        'no_toggle' => true,
        'label' => 'Wachtwoord reset (door admin)',
        'description' => 'Wordt verstuurd wanneer een admin het wachtwoord van een klant reset. De e-mail bevat automatisch het nieuwe tijdelijke wachtwoord.',
        'subject_key' => 'email_tpl_wachtwoord_reset_subject',
        'body_key'    => 'email_tpl_wachtwoord_reset_body',
        'default_subject' => 'Nieuw wachtwoord — Bakkerij Civetta',
        'default_body' => 'Er is een nieuw wachtwoord aangemaakt voor uw zakelijke account bij Bakkerij Civetta.',
        'placeholders' => [
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{email}'          => 'E-mailadres (gebruikersnaam)',
            '{wachtwoord}'     => 'Het nieuwe tijdelijke wachtwoord',
            '{login_url}'      => 'Link naar de loginpagina',
        ],
    ],
    'wachtwoord_vergeten' => [
        'no_toggle' => true,
        'label' => 'Wachtwoord vergeten',
        'description' => 'Wordt verstuurd wanneer een klant een wachtwoord-reset aanvraagt via de loginpagina. De e-mail bevat automatisch de reset-link.',
        'subject_key' => 'email_tpl_wachtwoord_vergeten_subject',
        'body_key'    => 'email_tpl_wachtwoord_vergeten_body',
        'default_subject' => 'Wachtwoord opnieuw instellen — Bakkerij Civetta',
        'default_body' => 'We hebben een verzoek ontvangen om het wachtwoord van uw account bij Bakkerij Civetta opnieuw in te stellen.',
        'placeholders' => [
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{reset_url}'      => 'Reset-link (automatisch toegevoegd)',
        ],
    ],
    'factuur' => [
        'label' => 'Factuur',
        'description' => 'Wordt verstuurd bij het factureren van een bestelling. De factuur-PDF wordt automatisch als bijlage toegevoegd.',
        'subject_key' => 'email_tpl_factuur_subject',
        'body_key'    => 'email_tpl_factuur_body',
        'default_subject' => 'Uw factuur van Bakkerij Civetta',
        'default_body' => 'Hartelijk dank voor uw bestelling! Bijgaand vindt u uw factuur.',
        'placeholders' => [
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{bedrijfsnaam}'   => 'Naam van het bedrijf',
            '{factuurnummer}'  => 'Factuurnummer',
            '{leverdatum}'     => 'Leverdatum van de bestelling',
        ],
    ],
    'admin_nieuwe_bestelling' => [
        'label' => 'Nieuwe bestelling (admin notificatie)',
        'description' => 'Notificatie naar de admin wanneer een klant een nieuwe bestelling plaatst.',
        'subject_key' => 'email_tpl_admin_nieuwe_bestelling_subject',
        'body_key'    => 'email_tpl_admin_nieuwe_bestelling_body',
        'default_subject' => 'Nieuwe bestelling #{bestelnummer} — {bedrijfsnaam}',
        'default_body' => "Er is een nieuwe bestelling ontvangen.\n\nBedrijf: {bedrijfsnaam}\nContactpersoon: {contactpersoon}\nBestelnummer: #{bestelnummer}\nLeverdatum: {leverdatum}\n\nBekijk de bestelling in het admin-paneel.",
        'placeholders' => [
            '{bedrijfsnaam}'   => 'Naam van het bedrijf',
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{bestelnummer}'   => 'Bestelnummer (#ID)',
            '{leverdatum}'     => 'Leverdatum',
            '{totaalbedrag}'   => 'Totaalbedrag incl. BTW',
        ],
    ],
    'admin_bestelling_gewijzigd' => [
        'label' => 'Bestelling gewijzigd (admin notificatie)',
        'description' => 'Notificatie naar de admin wanneer een klant een bestaande bestelling wijzigt.',
        'subject_key' => 'email_tpl_admin_bestelling_gewijzigd_subject',
        'body_key'    => 'email_tpl_admin_bestelling_gewijzigd_body',
        'default_subject' => 'Bestelling gewijzigd #{bestelnummer} — {bedrijfsnaam}',
        'default_body' => "Een bestelling is gewijzigd door de klant.\n\nBedrijf: {bedrijfsnaam}\nContactpersoon: {contactpersoon}\nBestelnummer: #{bestelnummer}\nLeverdatum: {leverdatum}\n\nBekijk de gewijzigde bestelling in het admin-paneel.",
        'placeholders' => [
            '{bedrijfsnaam}'   => 'Naam van het bedrijf',
            '{contactpersoon}' => 'Naam van de contactpersoon',
            '{bestelnummer}'   => 'Bestelnummer (#ID)',
            '{leverdatum}'     => 'Leverdatum',
            '{totaalbedrag}'   => 'Nieuw totaalbedrag incl. BTW',
        ],
    ],
];

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_templates') {
    foreach ($templates as $slug => $tpl) {
        foreach ([$tpl['subject_key'], $tpl['body_key']] as $key) {
            $value = str_replace("\r\n", "\n", $_POST[$key] ?? '');
            setSetting($pdo, $key, $value !== '' ? $value : null);
        }
        if (empty($tpl['no_toggle'])) {
            $enabled = ($_POST['email_tpl_' . $slug . '_enabled'] ?? '0') === '1' ? '1' : '0';
            setSetting($pdo, 'email_tpl_' . $slug . '_enabled', $enabled);
        }
    }
    $success = 'E-mail templates opgeslagen.';
}

// Handle single template reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_single') {
    $slug = $_POST['template_slug'] ?? '';
    if (isset($templates[$slug])) {
        $tpl = $templates[$slug];
        setSetting($pdo, $tpl['subject_key'], null);
        setSetting($pdo, $tpl['body_key'], null);
        $success = "'{$tpl['label']}' teruggezet naar standaard.";
    }
}

// Handle reset all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_templates') {
    foreach ($templates as $tpl) {
        setSetting($pdo, $tpl['subject_key'], null);
        setSetting($pdo, $tpl['body_key'], null);
    }
    $success = 'Alle templates teruggezet naar standaard.';
}

// Precompute which templates have been customized
$customizedSlugs = [];
foreach ($templates as $slug => $tpl) {
    $dbSubj = getSetting($pdo, $tpl['subject_key'], null);
    $dbBody = getSetting($pdo, $tpl['body_key'], null);
    if (($dbSubj !== null && $dbSubj !== $tpl['default_subject'])
     || ($dbBody !== null && $dbBody !== $tpl['default_body'])) {
        $customizedSlugs[$slug] = true;
    }
}

ob_start(); ?>
<style>
.tpl-selector {
    margin-bottom: 1.5rem;
}
.tpl-selector select {
    width: 100%;
    max-width: 420px;
    padding: 0.6rem 0.85rem;
    font-family: inherit;
    font-size: 0.92rem;
    color: var(--text-primary);
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%238a918a' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    padding-right: 2.5rem;
}
.tpl-selector select:focus {
    outline: none;
    border-color: var(--green-medium);
    box-shadow: 0 0 0 2px rgba(61,107,61,0.15);
}
.tpl-selector select optgroup {
    font-weight: 700;
    color: var(--text-primary);
    font-style: normal;
}
.tpl-selector select option {
    font-weight: 400;
}

.tpl-panel { display: none; }
.tpl-panel.active { display: block; }

.tpl-layout {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 900px) {
    .tpl-layout { grid-template-columns: 1fr; }
}

.tpl-card {
    background: white;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    padding: 1.75rem;
}

.tpl-description {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 1.25rem;
    line-height: 1.5;
}

.tpl-form-group {
    margin-bottom: 1.25rem;
}
.tpl-form-group label {
    display: block;
    margin-bottom: 0.4rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
}
.tpl-form-group input[type="text"] {
    width: 100%;
    padding: 0.65rem 0.85rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 0.9rem;
    font-family: inherit;
    color: var(--text-primary);
    background: #fff;
}
.tpl-form-group input[type="text"]:focus {
    outline: none;
    border-color: var(--green-medium);
    box-shadow: 0 0 0 2px rgba(61,107,61,0.12);
}
.tpl-form-group textarea {
    width: 100%;
    padding: 0.75rem 0.85rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 0.87rem;
    font-family: 'Courier New', monospace;
    line-height: 1.6;
    min-height: 220px;
    resize: vertical;
    color: var(--text-primary);
    background: #fafbfa;
}
.tpl-form-group textarea:focus {
    outline: none;
    border-color: var(--green-medium);
    box-shadow: 0 0 0 2px rgba(61,107,61,0.12);
    background: #fff;
}

.tpl-placeholders {
    background: var(--cream);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 1rem;
    position: sticky;
    top: 1rem;
}
.tpl-placeholders h4 {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 0.25rem;
}
.tpl-placeholders-hint {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin: 0 0 0.75rem;
}
.placeholder-item {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    padding: 0.35rem;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    border-radius: 4px;
    transition: background 0.12s;
}
.placeholder-item:last-child { border-bottom: none; }
.placeholder-item:hover { background: rgba(61,107,61,0.06); }
.placeholder-tag {
    font-family: 'Courier New', monospace;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--green-medium);
    white-space: nowrap;
    flex-shrink: 0;
}
.placeholder-desc {
    font-size: 0.76rem;
    color: var(--text-muted);
    line-height: 1.3;
}
.placeholder-copied {
    font-size: 0.7rem;
    color: var(--green-medium);
    font-weight: 600;
    opacity: 0;
    transition: opacity 0.2s;
    margin-left: auto;
    white-space: nowrap;
}
.placeholder-item.copied .placeholder-copied { opacity: 1; }

.tpl-reset-link {
    font-size: 0.82rem;
    color: var(--text-muted);
    background: none;
    border: none;
    cursor: pointer;
    text-decoration: underline;
    padding: 0;
    font-family: inherit;
    margin-top: 0.75rem;
    display: inline-block;
}
.tpl-reset-link:hover { color: #c0392b; }

.tpl-customized-badge {
    display: inline-block;
    font-size: 0.7rem;
    font-weight: 700;
    color: #8b5a2b;
    background: rgba(139,90,43,0.1);
    border: 1px solid rgba(139,90,43,0.25);
    border-radius: 3px;
    padding: 0.15rem 0.5rem;
    margin-right: 0.5rem;
    vertical-align: middle;
    letter-spacing: 0.03em;
    text-transform: uppercase;
}

.tpl-toggle-row {
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border);
}
.tpl-toggle-label {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    cursor: pointer;
    user-select: none;
}
.tpl-enabled-cb {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.tpl-toggle-switch {
    position: relative;
    display: inline-block;
    width: 38px;
    height: 22px;
    background: var(--border);
    border-radius: 11px;
    transition: background 0.2s;
    flex-shrink: 0;
}
.tpl-toggle-switch::after {
    content: '';
    position: absolute;
    top: 3px; left: 3px;
    width: 16px; height: 16px;
    background: #fff;
    border-radius: 50%;
    transition: left 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.tpl-enabled-cb:checked + .tpl-toggle-switch { background: var(--green-medium); }
.tpl-enabled-cb:checked + .tpl-toggle-switch::after { left: 19px; }
.tpl-toggle-text {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
}
.tpl-enabled-cb:checked ~ .tpl-toggle-text { color: var(--green-medium); }
.tpl-panel.tpl-disabled .tpl-form-group {
    opacity: 0.4;
    pointer-events: none;
}

.tpl-save-bar {
    position: sticky;
    bottom: 0;
    background: var(--cream);
    border-top: 1px solid var(--border);
    padding: 1rem 0;
    margin-top: 1.5rem;
    display: flex;
    gap: 1rem;
    align-items: center;
    z-index: 10;
}
.tpl-save-btn {
    background: linear-gradient(135deg, var(--green-medium), var(--green));
    color: white;
    padding: 0.65rem 1.5rem;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
}
.tpl-save-btn:hover { opacity: 0.9; }
.tpl-secondary-btn {
    background: white;
    color: var(--text-secondary);
    padding: 0.65rem 1.25rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 0.88rem;
    cursor: pointer;
    font-family: inherit;
}
.tpl-secondary-btn:hover { border-color: var(--green-medium); color: var(--green); }
.tpl-modified {
    display: none;
    font-size: 0.82rem;
    color: #8b5a2b;
    font-weight: 600;
}
.tpl-modified.visible { display: inline; }

.tpl-alert {
    padding: 0.85rem 1rem;
    border-radius: var(--radius-sm);
    margin-bottom: 1.25rem;
    font-size: 0.9rem;
}
.tpl-alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.tpl-alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

.tpl-preview-btn {
    background: none;
    border: 1px solid var(--green-medium);
    color: var(--green-medium);
    padding: 0.45rem 0.9rem;
    border-radius: var(--radius-sm);
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
}
.tpl-preview-btn:hover { background: var(--green-medium); color: white; }

.tpl-preview-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    z-index: 2000;
    overflow-y: auto;
    padding: 2rem 1rem;
}
.tpl-preview-modal.open { display: flex; align-items: flex-start; justify-content: center; }
.tpl-preview-inner {
    background: #f5f2ed;
    border-radius: 12px;
    overflow: hidden;
    width: 100%;
    max-width: 660px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.3);
}
.tpl-preview-bar {
    background: #5c3d1e;
    color: white;
    padding: 0.85rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.tpl-preview-bar strong { font-size: 0.9rem; }
.tpl-preview-close { background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; line-height: 1; padding: 0 0.25rem; }
.tpl-preview-iframe { width: 100%; border: none; min-height: 600px; display: block; }
</style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">E-mail templates</span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
                <div class="breadcrumb" style="margin-bottom:1.5rem;">
                    <a href="../index.php" style="color:var(--green-medium);text-decoration:none;">Dashboard</a>
                    <span style="color:var(--text-muted);margin:0 0.4rem;">›</span>
                    E-mail templates
                </div>

                <?php if ($success): ?>
                    <div class="tpl-alert tpl-alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="tpl-alert tpl-alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="tpl-selector">
                    <select id="template-select">
                        <?php $first = true; foreach ($categories as $catLabel => $slugs): ?>
                            <optgroup label="<?= htmlspecialchars($catLabel) ?>">
                                <?php foreach ($slugs as $slug):
                                    if (!isset($templates[$slug])) continue;
                                    $label = $templates[$slug]['label'];
                                    $isCustom = isset($customizedSlugs[$slug]);
                                ?>
                                    <option value="<?= $slug ?>"<?= $first ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($label) . ($isCustom ? ' ✎' : '') ?>
                                    </option>
                                <?php $first = false; endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <form method="POST" id="templates-form">
                    <input type="hidden" name="action" value="save_templates">

                    <?php $first = true; foreach ($templates as $slug => $tpl):
                        $currentSubject = getSetting($pdo, $tpl['subject_key'], null) ?? $tpl['default_subject'];
                        $currentBody    = getSetting($pdo, $tpl['body_key'], null) ?? $tpl['default_body'];
                        $isEnabled      = !empty($tpl['no_toggle']) || getSetting($pdo, 'email_tpl_' . $slug . '_enabled', null) !== '0';
                        $isCustomized   = isset($customizedSlugs[$slug]);
                    ?>
                        <div class="tpl-panel<?= $first ? ' active' : '' ?><?= (!empty($tpl['no_toggle']) ? '' : (!$isEnabled ? ' tpl-disabled' : '')) ?>" id="panel-<?= $slug ?>">
                            <div class="tpl-layout">
                                <div class="tpl-editor">
                                    <div class="tpl-card">

                                        <?php if (empty($tpl['no_toggle'])): ?>
                                        <div class="tpl-toggle-row">
                                            <label class="tpl-toggle-label">
                                                <input type="hidden" name="email_tpl_<?= $slug ?>_enabled" value="0">
                                                <input type="checkbox" class="tpl-enabled-cb" name="email_tpl_<?= $slug ?>_enabled" value="1"<?= $isEnabled ? ' checked' : '' ?>>
                                                <span class="tpl-toggle-switch"></span>
                                                <span class="tpl-toggle-text"><?= $isEnabled ? 'Actief' : 'Uitgeschakeld' ?></span>
                                            </label>
                                        </div>
                                        <?php endif; ?>

                                        <p class="tpl-description"><?= htmlspecialchars($tpl['description']) ?></p>

                                        <div class="tpl-form-group">
                                            <label>Onderwerp</label>
                                            <input type="text"
                                                   name="<?= $tpl['subject_key'] ?>"
                                                   value="<?= htmlspecialchars($currentSubject) ?>"
                                                   data-default="<?= htmlspecialchars($tpl['default_subject']) ?>"
                                                   placeholder="<?= htmlspecialchars($tpl['default_subject']) ?>">
                                        </div>

                                        <div class="tpl-form-group">
                                            <label>Berichttekst</label>
                                            <textarea name="<?= $tpl['body_key'] ?>"
                                                      id="body-<?= $slug ?>"
                                                      data-default="<?= htmlspecialchars($tpl['default_body']) ?>"><?= htmlspecialchars($currentBody) ?></textarea>
                                        </div>

                                        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:0.5rem;">
                                            <?php if ($isCustomized): ?>
                                                <span class="tpl-customized-badge">Aangepast</span>
                                            <?php endif; ?>
                                            <button type="button" class="tpl-reset-link"
                                                    data-slug="<?= $slug ?>"
                                                    data-label="<?= htmlspecialchars($tpl['label']) ?>"
                                                    onclick="resetTemplate(this)">
                                                Terugzetten naar standaard
                                            </button>
                                            <button type="button" class="tpl-preview-btn" onclick="openPreview('<?= $slug ?>')">
                                                Voorbeeld bekijken
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tpl-placeholders">
                                    <h4>Placeholders</h4>
                                    <p class="tpl-placeholders-hint">Klik om te kopiëren</p>
                                    <?php foreach ($tpl['placeholders'] as $tag => $desc): ?>
                                        <div class="placeholder-item" onclick="copyPlaceholder(this, '<?= $tag ?>')" title="Klik om te kopiëren">
                                            <span class="placeholder-tag"><?= htmlspecialchars($tag) ?></span>
                                            <span class="placeholder-desc"><?= htmlspecialchars($desc) ?></span>
                                            <span class="placeholder-copied">gekopieerd</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php $first = false; endforeach; ?>

                    <div class="tpl-save-bar">
                        <button type="submit" class="tpl-save-btn">Opslaan</button>
                        <span class="tpl-modified" id="modified-indicator">Niet-opgeslagen wijzigingen</span>
                        <button type="button" class="tpl-secondary-btn" style="margin-left:auto;"
                                onclick="if(confirm('Weet je zeker dat je ALLE templates wilt resetten naar de standaardteksten?')) { document.getElementById('reset-all-form').submit(); }">
                            Alles resetten
                        </button>
                    </div>
                </form>

                <form method="POST" id="reset-all-form" style="display:none;">
                    <input type="hidden" name="action" value="reset_templates">
                </form>
                <form method="POST" id="reset-single-form" style="display:none;">
                    <input type="hidden" name="action" value="reset_single">
                    <input type="hidden" name="template_slug" id="reset-single-slug" value="">
                </form>
            </div>

<script>
var select = document.getElementById('template-select');

function showPanel(slug) {
    document.querySelectorAll('.tpl-panel.active').forEach(function(p) {
        p.classList.remove('active');
    });
    var panel = document.getElementById('panel-' + slug);
    if (panel) panel.classList.add('active');
    history.replaceState(null, '', '#' + slug);
}

select.addEventListener('change', function() { showPanel(this.value); });

if (window.location.hash) {
    var hashSlug = window.location.hash.substring(1);
    var opt = select.querySelector('option[value="' + hashSlug + '"]');
    if (opt) {
        select.value = hashSlug;
        showPanel(hashSlug);
    }
}

function copyPlaceholder(el, tag) {
    navigator.clipboard.writeText(tag).then(function() {
        el.classList.add('copied');
        setTimeout(function() { el.classList.remove('copied'); }, 1200);
    });
}

function resetTemplate(btn) {
    var slug = btn.dataset.slug;
    var label = btn.dataset.label;
    if (!confirm("'" + label + "' terugzetten naar de standaardtekst?")) return;

    var panel = document.getElementById('panel-' + slug);
    var subjectInput = panel.querySelector('input[data-default]');
    if (subjectInput) subjectInput.value = subjectInput.dataset.default;

    var bodyTextarea = document.getElementById('body-' + slug);
    if (bodyTextarea) bodyTextarea.value = bodyTextarea.dataset.default;

    document.getElementById('reset-single-slug').value = slug;
    document.getElementById('reset-single-form').submit();
}

document.querySelectorAll('.tpl-enabled-cb').forEach(function(cb) {
    function updateToggle() {
        var panel = cb.closest('.tpl-panel');
        var labelText = cb.closest('.tpl-toggle-label').querySelector('.tpl-toggle-text');
        if (cb.checked) {
            labelText.textContent = 'Actief';
            panel.classList.remove('tpl-disabled');
        } else {
            labelText.textContent = 'Uitgeschakeld';
            panel.classList.add('tpl-disabled');
        }
        markChanged();
    }
    cb.addEventListener('change', updateToggle);
});

var hasChanges = false;
function markChanged() {
    if (!hasChanges) {
        hasChanges = true;
        document.getElementById('modified-indicator').classList.add('visible');
    }
}
document.getElementById('templates-form').addEventListener('input', markChanged);

window.addEventListener('beforeunload', function(e) {
    if (hasChanges) { e.preventDefault(); e.returnValue = ''; }
});

document.getElementById('templates-form').addEventListener('submit', function() {
    hasChanges = false;
});

function openPreview(slug) {
    var bodyVal = document.getElementById('body-' + slug).value;
    var modal   = document.getElementById('preview-modal');
    var iframe  = document.getElementById('preview-iframe');
    iframe.srcdoc = '<p style="text-align:center;padding:3rem;color:#666;font-family:sans-serif;">Laden…</p>';
    modal.classList.add('open');
    var fd = new FormData();
    fd.append('slug', slug);
    fd.append('body', bodyVal);
    fetch('email-preview.php', { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(html) { iframe.srcdoc = html; });
}
function closePreview() {
    document.getElementById('preview-modal').classList.remove('open');
}
document.getElementById('preview-modal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});
</script>

<div class="tpl-preview-modal" id="preview-modal">
    <div class="tpl-preview-inner">
        <div class="tpl-preview-bar">
            <strong>E-mail voorbeeld</strong>
            <button class="tpl-preview-close" onclick="closePreview()" title="Sluiten">&#x2715;</button>
        </div>
        <iframe class="tpl-preview-iframe" id="preview-iframe" srcdoc=""></iframe>
    </div>
</div>

        </div>
    </div>
</body>
</html>
