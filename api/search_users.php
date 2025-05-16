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
    $logDir = rtrim(BASE_PATH, '/') . '/logs';
    
    // Alternative: Use WEB_ROOT if that's more appropriate for your setup
    // $logDir = rtrim($_SERVER['DOCUMENT_ROOT'] . WEB_ROOT, '/') . '/logs';
    
    // Make sure the logs directory exists
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/custom.log');
}
require BASE_PATH . 'src/connectToDB_Login.php';

if (!isset($_SESSION['username'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit();
}

$searchTerm = filter_input(INPUT_GET, 'term', FILTER_SANITIZE_STRING);

if (!$searchTerm || strlen($searchTerm) < 2) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(["error" => "Search term too short"]);
    exit();
}

try {
    $currentUser = $_SESSION['username'];
    $searchParam = "%$searchTerm%";
    
    // Search for users that match the search term but exclude the current user
    $stmt = $conn->prepare("
        SELECT Username 
        FROM Users 
        WHERE Username LIKE ? AND Username != ? AND role = 'user'
        LIMIT 10
    ");
    
    $stmt->bind_param("ss", $searchParam, $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row['Username'];
    }
    
    header('Content-Type: application/json');
    echo json_encode($users);
    
} catch(Exception $e) {
    error_log("Database error: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => "Server error"]);
}
?>