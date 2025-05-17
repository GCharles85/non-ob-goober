<?php
session_start();
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
    $logDir = rtrim(BASE_PATH, '/') . '/logs';
    
    // Alternative: Use WEB_ROOT if that's more appropriate for your setup
    // $logDir = rtrim($_SERVER['DOCUMENT_ROOT'] . WEB_ROOT, '/') . '/logs';
    
    // Make sure the logs directory exists
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/custom.log');
}

// Using PDO
$dsn = 'mysql:host=localhost;dbname=goober_box';
$username = 'root';
$password = 'Ht7877$$';

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      // Output to browser console
    //echo "<script>console.log('Connected successfully to MySQL server using PDO');</script>";
} catch (PDOException $e) {
    // Output error to browser console
    //echo "<script>console.error('Connection failed: " . addslashes($e->getMessage()) . "');</script>";
}

if (!isset($pdo) || !$pdo) {
    //echo "<script>console.error('PDO connection is not set or is not valid');</script>";
}

// Using PDO
$stmt = $pdo->prepare("SELECT path FROM items WHERE Name = :name");
$stmt->execute(['name' => 'test']);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
$video_path = $result[0]["path"];
//print_r($result);
//$videoPaths = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
