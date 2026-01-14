<?php
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Database verbinding mislukt");
}

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();

function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function regenerateSession() {
    session_regenerate_id(true);
}

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900);

function checkLoginAttempts($pdo, $identifier) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$identifier, LOCKOUT_TIME]);
    return $stmt->fetchColumn() < MAX_LOGIN_ATTEMPTS;
}

function recordLoginAttempt($pdo, $identifier, $success = false) {
    if ($success) {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?");
        $stmt->execute([$identifier]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (identifier, attempt_time) VALUES (?, NOW())");
        $stmt->execute([$identifier]);
    }
}

function cleanOldLoginAttempts($pdo) {
    $pdo->exec("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 1 DAY)");
}
?>
