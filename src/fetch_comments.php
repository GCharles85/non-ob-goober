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

require_once BASE_PATH . 'loadenv.php';
// Include the database connection
require BASE_PATH . 'src/connectToDB_Login.php';

// Get the image ID from the AJAX request
$imageId = isset($_POST['imageId']) ? $_POST['imageId'] : '';

if (empty($imageId)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No image ID provided']);
    error_log("fetch_comments.php: No image ID was provided");
    exit;
}

try {
    error_log("fetch_comments.php: Fetching comments for image ID: " . $imageId);
    // Prepare and execute query to fetch comments for this image
    $stmt = $conn->prepare("SELECT CommentID as id, Username, Content as comment, Name as name, CreatedAt as created_at 
                           FROM Comments 
                           WHERE Name = ? 
                           ORDER BY CreatedAt DESC");
    $stmt->bind_param("s", $imageId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $comments = [];
    
    while ($row = $result->fetch_assoc()) {
        $comments[] = $row;
    }
    
    $stmt->close();
    
    // Return comments as JSON
    // Log comments for debugging
    //error_log("fetch_comments.php: Comments fetched: " . json_encode($comments));
    header('Content-Type: application/json');
    echo json_encode($comments);
} catch (Exception $e) {
    error_log("fetch_comments.php: Error fetching comments: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    // Return empty array on error
    echo json_encode([]);
}
?>