<?php
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';

require BASE_PATH . 'src/connectToDB_Login.php';
session_start();

if (!isset($_SESSION['username'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit();
}

$conversation_id = filter_input(INPUT_GET, 'conversation_id', FILTER_VALIDATE_INT);
$last_id = filter_input(INPUT_GET, 'last_id', FILTER_VALIDATE_INT);

if (!$conversation_id || $last_id === false) {
    header("HTTP/1.1 400 Bad Request");
    exit();
}

try {
    // Get only new messages based on the last message ID the client has
    $username = $_SESSION['username'];
    
    // Use mysqli since your connection file has both
    $stmt = $conn->prepare("
        SELECT message_id, content, sender_user as sender, timestamp
        FROM messages
        WHERE conversation_id = ?
        AND (sender_user = ? OR receiver_user = ?)
        AND message_id > ?
        ORDER BY timestamp ASC
    ");
    
    $stmt->bind_param("issi", $conversation_id, $username, $username, $last_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($messages);

    if ($environment == 'production') {
        exec('/opt/update-db-dump.sh 2>&1', $output, $return_code);
        if ($return_code === 0) {
            error_log("Backup completed successfully!");
        } else {
            error_log("Backup failed. Return code: $return_code. Output: " . implode("\n", $output));
        } 
    }

} catch(Exception $e) {
    error_log("Database error: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
}
?>