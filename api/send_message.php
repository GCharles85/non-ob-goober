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

// For debugging
error_log("Send message request received");
error_log("Current user: " . ($_SESSION['username'] ?? 'Not set'));

if (!isset($_SESSION['username'])) {
    header("HTTP/1.1 401 Unauthorized");
    echo json_encode(['status' => 'failed', 'message' => 'Not authenticated']);
    exit();
} 

$data = json_decode(file_get_contents('php://input'), true);
error_log("Received data: " . json_encode($data));

if (!isset($data['conversation_id'], $data['content'], $data['receiver_user'], $data['sender_user'])) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['status' => 'failed', 'message' => 'Missing required data']);
    exit();
}

try { 
    $conn->begin_transaction();
    // For debugging, temporarily allow the message without session validation
    $conversation_id = intval($data['conversation_id']);
    $sender_user = $data['sender_user']; // Use the sender from request data
    $receiver_user = $data['receiver_user'];
    // Just store the content as plain text - we'll handle escaping when displaying
    $content = $data['content'];
    
    // Use mysqli since that's what your original code used
    $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_user, receiver_user, content) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $conversation_id, $sender_user, $receiver_user, $content);
    
    error_log("Executing insert for conversation_id=$conversation_id, sender=$sender_user, receiver=$receiver_user");
    
    if ($stmt->execute()) {
        $conn->commit();
        header("HTTP/1.1 201 Created");
        echo json_encode(['status' => 'success']);
    } else {
        $conn->rollback();
        error_log("Database error on insert: " . $stmt->error);
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(['status' => 'failed', 'message' => 'Failed to insert message']);
    }
    
    $stmt->close();
    
} catch(Exception $e) {
    $conn->rollback();
    error_log("Exception in send_messages.php: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['status' => 'failed', 'message' => 'An error occurred']);
}

$conn->close();
?>