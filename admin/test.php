<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Step 1: PHP OK<br>";

echo "Step 2: PDO class exists: " . (class_exists('PDO') ? 'YES' : 'NO') . "<br>";

echo "Step 3: Loading config...<br>";
require_once 'config.php';

echo "Step 4: Config loaded, DB connected<br>";

echo "Step 5: Logged in: " . (isLoggedIn() ? 'YES' : 'NO') . "<br>";

echo "DONE";
