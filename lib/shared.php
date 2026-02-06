<?php

function getBedrijfsGegevens($pdo) {
    $velden = ['bedrijf_naam', 'bedrijf_contactpersoon', 'bedrijf_adres', 'bedrijf_postcode', 'bedrijf_plaats', 'bedrijf_telefoon', 'bedrijf_email', 'bedrijf_kvk', 'bedrijf_btw_id'];
    $gegevens = [];
    foreach ($velden as $veld) {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$veld]);
        $gegevens[$veld] = $stmt->fetchColumn() ?: '';
    }
    return $gegevens;
}

function euro($amount) {
    return chr(128) . ' ' . number_format($amount, 2, ',', '.');
}
