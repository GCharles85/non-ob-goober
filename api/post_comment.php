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

// Check connection
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Connection failed']));
}

// Get POST parameters
$imageId = $_POST['imageId'] ?? '';
$content = $_POST['content'] ?? '';
$name = $_POST['name'] ?? null;
$userName = $_SESSION['username']; // Default user ID, replace with session user ID in production

// Validate input
if (empty($content) || empty($imageId)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Prepare and execute SQL statement
$stmt = $conn->prepare("INSERT INTO Comments (Username, Content, Name) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $userName, $content, $imageId);

if ($stmt->execute()) {
    $commentId = $conn->insert_id;

    // Query to get the exact timestamp from MySQL
    $query = "SELECT CreatedAt FROM Comments WHERE CommentID = ?";
    $stmtSelect = $conn->prepare($query);
    $stmtSelect->bind_param("i", $commentId);
    $stmtSelect->execute();
    $stmtSelect->bind_result($createdAt);
    $stmtSelect->fetch();
    $stmtSelect->close();
    
    // Return the new comment data
    echo json_encode([
        'success' => true,
        'comment' => [
            'id' => $commentId,
            'comment' => $content,
            'name' => $name,
            'username' => $userName,
            'createdAt' => $createdAt
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
    echo json_encode(['success' => false, 'message' => 'Error posting comment']);
}

$stmt->close();
$conn->close();
?>