<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "PDO exists: " . (class_exists('PDO') ? 'YES' : 'NO') . "<br>";
echo "PHP version: " . phpversion() . "<br>";
echo "SAPI: " . php_sapi_name() . "<br>";
