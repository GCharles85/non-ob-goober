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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $commentId = $_POST['commentId'] ?? null;

    if ($commentId) {
        $stmt = $conn->prepare("SELECT ReplyID, Username, Content, CreatedAt FROM Replies WHERE CommentID = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            echo json_encode([]);
            exit;
        }

        $stmt->bind_param('i', $commentId);

        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            echo json_encode([]);
            exit;
        }

        $result = $stmt->get_result();
        $replies = [];
        while ($row = $result->fetch_assoc()) {
            $replies[] = $row;
        }

        echo json_encode($replies);

        $stmt->close();
    } else {
        error_log("Missing commentId in fetch_replies.php");
        echo json_encode([]);
    }
}
?>
