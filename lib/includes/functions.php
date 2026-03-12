<?php
/**
 * Shared helper functions for Civetta.
 *
 * Auth helpers, settings cache, JSON response helpers, formatting.
 */

// ── Auth helpers ─────────────────────────────────────────────────────────────

function isAdmin() {
    return !empty($_SESSION['admin_logged_in']);
}

function isBusinessUser() {
    return !empty($_SESSION['business_logged_in']);
}

function getBusinessAccountId() {
    return $_SESSION['business_account_id'] ?? null;
}

/**
 * Require admin login for API endpoints. Sends 401 JSON and exits if not logged in.
 */
function requireAdminApi() {
    if (!isAdmin()) {
        jsonError('Niet ingelogd', 401);
    }
}

/**
 * Require business user login for API endpoints. Sends 401 JSON and exits if not logged in.
 */
function requireBusinessApi() {
    if (!isBusinessUser()) {
        jsonError('Niet ingelogd', 401);
    }
}

/**
 * Require admin OR business user login. Sends 401 JSON and exits if neither.
 */
function requireAnyAuthApi() {
    if (!isAdmin() && !isBusinessUser()) {
        jsonError('Niet ingelogd', 401);
    }
}

// ── JSON response helpers ────────────────────────────────────────────────────

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function jsonSuccess($data = []) {
    jsonResponse(array_merge(['success' => true], $data));
}

function jsonError($message, $statusCode = 400) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// ── Settings cache ───────────────────────────────────────────────────────────

/**
 * Get a single setting value with caching.
 */
function getSetting($pdo, $key, $default = '') {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    $cache[$key] = ($value !== false) ? $value : $default;
    return $cache[$key];
}

/**
 * Get multiple settings at once (single query, cached).
 */
function getSettings($pdo, array $keys) {
    static $cache = [];
    $missing = array_diff($keys, array_keys($cache));

    if (!empty($missing)) {
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
        $stmt->execute(array_values($missing));
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
        // Cache missing keys as empty string
        foreach ($missing as $key) {
            if (!isset($cache[$key])) {
                $cache[$key] = '';
            }
        }
    }

    $result = [];
    foreach ($keys as $key) {
        $result[$key] = $cache[$key] ?? '';
    }
    return $result;
}

/**
 * Get bedrijfsgegevens (single query instead of 9 separate ones).
 */
function getBedrijfsGegevens($pdo) {
    $velden = ['bedrijf_naam', 'bedrijf_contactpersoon', 'bedrijf_adres', 'bedrijf_postcode', 'bedrijf_plaats', 'bedrijf_telefoon', 'bedrijf_email', 'bedrijf_kvk', 'bedrijf_btw_id'];
    return getSettings($pdo, $velden);
}

// ── Formatting ───────────────────────────────────────────────────────────────

function euro($amount) {
    return chr(128) . ' ' . number_format($amount, 2, ',', '.');
}


/**
 * Format a date string to Dutch locale.
 */
function formatDutchDate($dateStr, $format = 'l j F Y') {
    $dagNamen = ['Sunday' => 'zondag', 'Monday' => 'maandag', 'Tuesday' => 'dinsdag', 'Wednesday' => 'woensdag', 'Thursday' => 'donderdag', 'Friday' => 'vrijdag', 'Saturday' => 'zaterdag'];
    $maandNamen = ['January' => 'januari', 'February' => 'februari', 'March' => 'maart', 'April' => 'april', 'May' => 'mei', 'June' => 'juni', 'July' => 'juli', 'August' => 'augustus', 'September' => 'september', 'October' => 'oktober', 'November' => 'november', 'December' => 'december'];

    $formatted = date($format, strtotime($dateStr));
    foreach ($dagNamen as $en => $nl) $formatted = str_replace($en, $nl, $formatted);
    foreach ($maandNamen as $en => $nl) $formatted = str_replace($en, $nl, $formatted);
    return $formatted;
}

// ── HTML escaping shorthand ──────────────────────────────────────────────────

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
