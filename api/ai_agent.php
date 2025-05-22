<?php
session_start();
require_once BASE_PATH . 'loadenv.php';
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';

require '../src/connectToDB_Login.php';

if (!isset($_SESSION['username'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit();
}

function generateResponse($conversation_id, $user_message, $sender_user, $receiver_user) {
    global $conn;
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Get conversation history to provide context to the AI
        $stmt = $conn->prepare("
            SELECT content, sender_user, receiver_user, timestamp
            FROM messages
            WHERE conversation_id = ?
            ORDER BY timestamp DESC
            LIMIT 20
        ");
        
        $stmt->bind_param("i", $conversation_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $conversation_history = [];
        while ($row = $result->fetch_assoc()) {
            $conversation_history[] = $row;
        }
        
        // Reverse the conversation history to get chronological order
        $conversation_history = array_reverse($conversation_history);
        
        // Format the conversation history for the AI
        $formatted_history = "";
        foreach ($conversation_history as $msg) {
            $formatted_history .= $msg['sender_user'] . ": " . $msg['content'] . "\n";
        }
        
        // Add the current user message to the context
        $formatted_history .= $sender_user . ": " . $user_message . "\n";
        
        require_once '../src/ai_config.php';
        $ai_response = generateAIResponse($formatted_history, $receiver_user, $user_message);
        
        // Insert the AI response as a message from the current user
        $stmt = $conn->prepare("
            INSERT INTO messages (conversation_id, sender_user, receiver_user, content) 
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->bind_param("isss", $conversation_id, $sender_user, $receiver_user, $ai_response);
        
        if ($stmt->execute()) {
            // Commit the transaction
            $conn->commit();
            return $ai_response;
        } else {
            // Rollback if insert fails
            $conn->rollback();
            throw new Exception("Failed to insert AI message");
        }
        
    } catch(Exception $e) {
        // Rollback on any exception
        if ($conn->connect_errno === 0) {
            $conn->rollback();
        }
        error_log("AI Agent error: " . $e->getMessage());
        return "Sorry, I encountered an error processing your request.";
    }
}

?>