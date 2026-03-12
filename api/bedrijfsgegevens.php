<?php
require_once '../admin/config.php';

header('Content-Type: application/json');

$velden = ['bedrijf_naam', 'bedrijf_contactpersoon', 'bedrijf_adres', 'bedrijf_postcode', 'bedrijf_plaats', 'bedrijf_telefoon', 'bedrijf_email', 'bedrijf_kvk', 'bedrijf_btw_id'];
$gegevens = [];

foreach ($velden as $veld) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$veld]);
    $key = str_replace('bedrijf_', '', $veld);
    $gegevens[$key] = $stmt->fetchColumn() ?: '';
}

echo json_encode($gegevens);
