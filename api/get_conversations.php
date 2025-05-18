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

try {
    $username = $_SESSION['username'];
    
    // Get all conversations where this user is involved
    $stmt = $conn->prepare("
        SELECT DISTINCT
            conversation_id,
            CASE 
                WHEN sender_user = ? THEN receiver_user
                ELSE sender_user
            END as participant
        FROM messages
        WHERE sender_user = ? OR receiver_user = ?
        ORDER BY conversation_id
    ");
    
    $stmt->bind_param("sss", $username, $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $conversations = [];
    while ($row = $result->fetch_assoc()) {
        $conversations[] = $row;
    }
    
    // Get latest timestamp for each conversation
    foreach ($conversations as &$convo) {
        $stmt = $conn->prepare("
            SELECT MAX(timestamp) as last_message_time
            FROM messages
            WHERE conversation_id = ?
        ");
        $stmt->bind_param("i", $convo['conversation_id']);
        $stmt->execute();
        $time = $stmt->get_result()->fetch_assoc();
        $convo['last_message_time'] = $time['last_message_time'];
    }
    
    // Sort by latest message
    usort($conversations, function($a, $b) {
        return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
    });
    
    header('Content-Type: application/json');
    echo json_encode($conversations);
    
} catch(Exception $e) {
    error_log("Database error: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
}
?>