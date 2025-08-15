<?php
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
require_once BASE_PATH . 'loadenv.php';
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';

function VerifyDirectories($output_dir, $interpolated_dir, $temp_dir){
    if (!is_dir($output_dir)) {
    mkdir($output_dir, 0777, true);
    // Add this to verify directory exists and is writable
    if (!is_dir($output_dir) || !is_writable($output_dir)) {
        throw new Exception("Failed to create or write to output directory: $output_dir");
    }
    }

    if (!is_dir($interpolated_dir)) {
        mkdir($interpolated_dir, 0777, true);
    }

    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }
}
?>

