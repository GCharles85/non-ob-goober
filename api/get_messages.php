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

$conversation_id = filter_input(INPUT_GET, 'conversation_id', FILTER_VALIDATE_INT);

if (!$conversation_id) {
    header("HTTP/1.1 400 Bad Request");
    exit();
}

try {
    // Get messages directly based on conversation_id and where the current user is either sender or receiver
    $username = $_SESSION['username'];
    
    // Use mysqli since your connection file has both
    $stmt = $conn->prepare("
        SELECT message_id, content, sender_user as sender, timestamp
        FROM messages
        WHERE conversation_id = ?
        AND (sender_user = ? OR receiver_user = ?)
        ORDER BY timestamp ASC
    ");
    
    $stmt->bind_param("iss", $conversation_id, $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    // For debugging
    if (empty($messages)) {
        error_log("No messages found for conversation ID: $conversation_id and user: $username");
    }

    header('Content-Type: application/json');
    echo json_encode($messages);

} catch(Exception $e) {
    error_log("Database error: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
}
?>