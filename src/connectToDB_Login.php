<?php
session_start();

if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';
if ($environment === 'production') {
    ini_set('error_log', 'stderr'); // Works for Vercel and many cloud platforms
} else {
    // Use BASE_PATH to determine log directory location
    // Make sure BASE_PATH is already defined before this code runs
    $logDir = rtrim(BASE_PATH, '/') . 'logs';
    
    // Alternative: Use WEB_ROOT if that's more appropriate for your setup
    // $logDir = rtrim($_SERVER['DOCUMENT_ROOT'] . WEB_ROOT, '/') . '/logs';
    
    // Make sure the logs directory exists
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/custom.log');
}

// Configuration
require_once BASE_PATH . 'loadenv.php';
$dbHost = $_ENV['DB_HOST1'];
$dbName = $_ENV['DB_NAME'];
$dbUsername = $_ENV['DB_USERNAME1'];
$dbPassword = $_ENV['DB_PASSWORD1'];

// Create mysqli connection (used by some files)
try {
    $conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
    
    // Check mysqli connection
    if ($conn->connect_error) {
        throw new Exception("MySQLi Connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
} catch (mysqli_sql_exception $e) {
    error_log("MySQLi SQL Exception: " . $e->getMessage());
}
?>