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

require BASE_PATH . 'src/connectToDB_Login.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $commentId = $_POST['commentId'] ?? null;
    $content = $_POST['content'] ?? null;
    $name = $_POST['name'] ?? 'Anonymous';

    if ($commentId && $content) {
        // Get user ID from session and log it
        $userName = $_SESSION['username'] ?? 'guest';
        error_log("Reply being posted by user: " . $userId);

        $stmt = $conn->prepare("INSERT INTO Replies (CommentID, Username, Name, Content) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database prepare error.']);
            exit;
        }

        $stmt->bind_param('isss', $commentId, $userName, $name, $content);

        if ($stmt->execute()) {
            $replyId = $stmt->insert_id;
            echo json_encode([
                'success' => true,
                'reply' => [
                    'id' => $replyId,
                    'name' => $name,
                    'content' => $content
                ]
            ]);

            if ($environment == 'production') {
                exec('/opt/update-db-dump.sh 2>&1', $output, $return_code);
                if ($return_code === 0) {
                    error_log("Backup completed successfully!");
                } else {
                    error_log("Backup failed. Return code: $return_code. Output: " . implode("\n", $output));
                } 
            }
        } else {
            error_log("Execute failed: " . $stmt->error);
            echo json_encode(['success' => false, 'message' => 'Failed to post reply.']);
        }

        $stmt->close();
    } else {
        error_log("Missing commentId or content. CommentId: $commentId, Content: $content");
        echo json_encode(['success' => false, 'message' => 'Missing comment ID or content.']);
    }
}
?>
