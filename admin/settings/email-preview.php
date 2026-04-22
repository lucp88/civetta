<?php
require_once '../config.php';
requireLogin();
require_once '../../api/email-templates.php';

$slug     = trim($_POST['slug'] ?? '');
$bodyText = trim($_POST['body'] ?? '');

$sampleValues = [
    '{contactpersoon}'  => 'Jan de Vries',
    '{bedrijfsnaam}'    => 'Voorbeeld Bedrijf B.V.',
    '{bestelnummer}'    => '1042',
    '{leverdatum}'      => '25 april 2026',
    '{naam_bestelling}' => 'Wekelijkse broodlevering',
    '{frequentie}'      => 'Wekelijks',
    '{leveradres}'      => 'Voorbeeldstraat 12, 1234 AB Amsterdam',
    '{factuurnummer}'   => 'F2026-0042',
    '{email}'           => 'jan@voorbeeldbedrijf.nl',
    '{wachtwoord}'      => '••••••••',
    '{totaalbedrag}'    => '€ 47,25',
    '{login_url}'       => '#',
    '{reset_url}'       => '#',
    '{uitnodiging_url}' => '#',
];

$autoContent = [
    'bestelbevestiging' => [
        'Bestelgegevens (bestelbonnummer, betaalstatus)',
        'Levergegevens (datum + leveradres)',
        'Productentabel met prijzen en hoeveelheden',
        'BTW-overzicht en totaalbedrag',
        'Knop: "Bekijk bestelling in dashboard"',
    ],
    'annulering' => [
        'Geannuleerde bestelgegevens (nummer, datum, bedrag)',
        'Productentabel (doorgestreept)',
        'Terugbetalingsinformatie (indien betaald via iDEAL)',
    ],
    'bestelling_aangepast' => [
        'Bestelgegevens (bestelnummer, leverdatum)',
        'Bijgewerkte productentabel met prijzen',
        'BTW-overzicht en nieuw totaalbedrag',
        'Verschil t.o.v. oorspronkelijk bedrag',
        'Knop: "Bekijk in dashboard"',
    ],
    'terugkerend_bevestiging' => [
        'Bestelnaam, frequentie en eerste leveringsdatum',
        'Producten per levering',
        'Overzicht van alle ingeplande leveringen (max. 12)',
        'Perioodetotaal',
        'Knop: "Bekijk in dashboard"',
    ],
    'terugkerend_gepauzeerd' => [
        'Bestelnaam, frequentie en huidige status',
        'Lijst van gepauzeerde leveringen met bedragen',
        'Leveringen die al in bereiding zijn (gaan gewoon door)',
    ],
    'terugkerend_hervat' => [
        'Bestelnaam, frequentie en huidige status',
        'Lijst van hervatte leveringen met bedragen',
        'Gemiste leveringen (deadline verstreken)',
    ],
    'terugkerend_gewijzigd' => [
        'Bestelnaam en frequentie',
        'Nieuwe producten per levering',
        'Verschil in bedrag per levering',
        'Bijgewerkt leveringsoverzicht (max. 8)',
    ],
    'levering_onderweg' => [
        'Leveradres en bestelnummer',
        'Positie op de route (bij meerdere stops)',
        'Productenoverzicht',
    ],
    'account_aanvraag' => [
        'Bedrijfsgegevens (naam, contactpersoon, e-mail, adres)',
        'Informatie over het goedkeuringsproces',
        'Knop: "Naar het dashboard"',
    ],
    'account_goedgekeurd' => [
        'Inloggegevens (e-mailadres + tijdelijk wachtwoord)',
        'Knop: "Nu inloggen"',
        'Tip: wachtwoord wijzigen na eerste inlog',
        'Overzicht van mogelijkheden in het dashboard',
    ],
    'account_uitnodiging' => [
        'Knop: "Account activeren" (persoonlijke activatielink)',
        'Overzicht van mogelijkheden in het dashboard',
        'Activatielink als platte tekst (als backup)',
    ],
    'wachtwoord_reset' => [
        'Inloggegevens (e-mailadres + nieuw tijdelijk wachtwoord)',
        'Knop: "Nu inloggen"',
    ],
    'wachtwoord_vergeten' => [
        'Knop: "Nieuw wachtwoord instellen" (eenmalige link)',
        'Reset-link als platte tekst (als backup)',
    ],
    'factuur' => [
        'Factuurgegevens (nummer, leverdatum, bedrijfsnaam)',
        'Productentabel met prijzen',
        'BTW-overzicht en totaalbedrag',
        'Knop: "Bekijk uw bestellingen"',
        'PDF-bijlage: factuur',
    ],
    'admin_nieuwe_bestelling' => [
        'Klantgegevens (bedrijf, contactpersoon, e-mail)',
        'Bestelnummer, leverdatum en betaalwijze',
        'Productentabel met totaal',
        'Opmerkingen van de klant (indien aanwezig)',
        'Knop: "Bekijk in admin"',
    ],
    'admin_bestelling_gewijzigd' => [
        'Klantgegevens (bedrijf, contactpersoon, e-mail)',
        'Bestelnummer, leverdatum en betaalwijze',
        'Bijgewerkte productentabel met totaal',
        'Opmerkingen van de klant (indien aanwezig)',
        'Knop: "Bekijk in admin"',
    ],
];

$isAdmin   = in_array($slug, ['admin_nieuwe_bestelling', 'admin_bestelling_gewijzigd']);
$autoItems = $autoContent[$slug] ?? [];

$previewText = str_replace(array_keys($sampleValues), array_values($sampleValues), $bodyText);
$introHtml   = nl2br(htmlspecialchars($previewText, ENT_QUOTES, 'UTF-8'));

$html  = getEmailHeader('Voorbeeld e-mail');
$html .= '<div class="email-body">';

if (!$isAdmin) {
    $html .= '<p class="greeting">Beste Jan de Vries,</p>';
}

if ($introHtml !== '') {
    $html .= '<p>' . $introHtml . '</p>';
}

if (!empty($autoItems)) {
    $html .= '
        <div style="background:#f5f0e8;border:2px dashed #8b5a2b;border-radius:8px;padding:18px 20px;margin:20px 0;">
            <p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#8b5a2b;text-transform:uppercase;letter-spacing:0.6px;">Automatisch gegenereerde inhoud</p>
            <ul style="margin:0;padding-left:18px;color:#666;">';
    foreach ($autoItems as $item) {
        $html .= '<li style="font-size:13px;margin-bottom:4px;">' . htmlspecialchars($item) . '</li>';
    }
    $html .= '
            </ul>
        </div>';
}

if (!$isAdmin) {
    $html .= '<p>Met vriendelijke groet,<br><strong>Bakkerij Civetta</strong></p>';
}

$html .= '</div>';
$html .= getEmailFooter([]);

echo $html;
