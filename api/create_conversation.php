<?php
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
require BASE_PATH . 'src/connectToDB_Login.php';
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


if (!isset($_SESSION['username'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['username'])) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['status' => 'failed', 'message' => 'Missing username']);
    exit();
}

try {
    $currentUser = $_SESSION['username'];
    $otherUser = $data['username'];
    $conn->begin_transaction();


    // Check if this user exists 
        $stmt = $conn->prepare("SELECT Username FROM Users WHERE Username = ?");
        $stmt->bind_param("s", $otherUser);
        $stmt->execute();
        $userResult = $stmt->get_result();
        if ($userResult->num_rows === 0) {
            header("HTTP/1.1 404 Not Found");
            echo json_encode(['status' => 'failed', 'message' => 'User not found']);
            exit();
        }
    
    
    // First check if a conversation already exists between these users
    $stmt = $conn->prepare("
        SELECT DISTINCT conversation_id
        FROM messages
        WHERE (sender_user = ? AND receiver_user = ?) 
           OR (sender_user = ? AND receiver_user = ?)
        LIMIT 1
    ");
    
    $stmt->bind_param("ssss", $currentUser, $otherUser, $otherUser, $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Conversation exists, return its ID
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success', 
            'conversation_id' => $row['conversation_id'],
            'is_new' => false,
            'username' => $otherUser
        ]);
        exit();
    }
    
    // No existing conversation, create a new one
    // First, insert into conversations table
    $stmt = $conn->prepare("INSERT INTO conversations (created_at) VALUES (CURRENT_TIMESTAMP)");
    $stmt->execute();
    $conversationId = $conn->insert_id;
    error_log("Conversation ID from create_conversation.php: " . $conversationId);
    // Now create an initial system message to make this conversation appear in the list
    $systemMessage = "Conversation started";
    $stmt = $conn->prepare("
        INSERT INTO messages (conversation_id, sender_user, receiver_user, content) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $conversationId, $currentUser, $otherUser, $systemMessage);
    $stmt->execute();

    $conn->commit();
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success', 
        'conversation_id' => $conversationId,
        'is_new' => true,
        'username' => $otherUser
    ]);
    
} catch(Exception $e) {
    $conn->rollback();
    error_log("Database error: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['status' => 'failed', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>