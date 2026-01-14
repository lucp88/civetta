<?php
session_start();

$_SESSION['business_logged_in'] = false;
$_SESSION['business_account_id'] = null;
$_SESSION['business_name'] = null;
$_SESSION['business_email'] = null;

session_destroy();

header('Content-Type: application/json');
echo json_encode(['success' => true]);
?>
