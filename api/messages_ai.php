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
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

require BASE_PATH . 'src/connectToDB_Login.php';
require BASE_PATH . 'ai_agent.php'; // Include your AI agent script

if (!isset($_SESSION['username'])) {
    header("HTTP/1.1 401 Unauthorized");
    echo json_encode(['status' => 'failed', 'message' => 'Not authenticated']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['message'])) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(['status' => 'failed', 'message' => 'Missing message']);
    exit();
}

$user_message = $data['message'];
$receiver_user = "AI Assistant"; // Or however you want to identify the AI
$sender_user = $_SESSION['username'];

try {

    //call create_conversation.php to get the convo id of the ai convo to pass to generate Response
    // Step 1: Only send 'username' => 'AI Assistant'
    $data = ['username' => 'AI Assistant'];
    error_log("Sending cURL request to create_conversation.php");
    session_write_close();  // Releases the session lock

    // Step 2: Initialize cURL to create_conversation.php
    $ch = curl_init('http://localhost/api/create_conversation.php');
    $sessionId = session_id();
    curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 seconds max execution
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // 2 seconds to connect
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    // Step 3: Execute request and get response
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    error_log("HTTP Status Code from messages_ai.php: " . $httpcode);
    // Step 4: Decode response (expecting JSON)
    $responseData = json_decode($response, true);
    error_log("Decoded Response Data from messages_ai.php: " . print_r($responseData, true));

    // Step 5: Extract conversation ID
    $conversation_id = $responseData['conversation_id'] ?? null;
    
    if (!$conversation_id) {
        //create an error and log it to error.log
        error_log("messages_ai.php error: Failed to create conversation");
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(['status' => 'failed', 'message' => 'Server error: Failed to create conversation']);
        exit();
    }
    
    // call ai_agent.php
    $ai_response = generateResponse($conversation_id, $user_message, $sender_user, $receiver_user);

    echo json_encode(['status' => 'success', 'ai_response' => $ai_response]);

} catch (Exception $e) {  
    error_log("messages_ai.php error: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['status' => 'failed', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>