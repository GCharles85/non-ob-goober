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
