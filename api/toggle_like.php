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

// Include database connection
require_once BASE_PATH . 'src/connectToDB_Login.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['fileId']) || empty($input['fileId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid fileId']);
    exit;
}

if (!isset($input['isLiked'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing liked status']);
    exit;
}

$fileId = $input['fileId'];
$isLiked = (bool)$input['isLiked'];
$username = $_SESSION['username'];
error_log("logging isLiked: $isLiked");

try {
    // Use your existing $conn object
    
    if ($conn) {
        if (!$isLiked) {
            // Just try to insert - if it fails due to unique constraint, it means already liked
            $likeStmt = $conn->prepare("INSERT INTO user_likes (username, uploadId) VALUES (?, ?)");
            $likeStmt->bind_param("ss", $username, $fileId);
            
            if (!$likeStmt->execute()) {
                // Insert failed - probably already liked
                http_response_code(400);
                error_log("error inserting into user_likes");
                echo json_encode(['error' => 'error inserting into user_likes']);
                exit;
            }
        
            // Increment likes count
            $stmt = $conn->prepare("UPDATE items SET likes = likes + 1 WHERE uploadId = ?");
            $stmt->bind_param("s", $fileId);
            $stmt->execute();
            $action = 'liked';

             // Check if any rows were affected
             if ($stmt->affected_rows === 0) {
                http_response_code(404);
                error_log("Item not found when incrementing likes in items");
                echo json_encode(['error' => 'Item not found when incrementing likes in items']);
                exit;
            }
        } else {
             // Just try to delete - if no rows affected, they hadn't liked it
            $unlikeStmt = $conn->prepare("DELETE FROM user_likes WHERE username = ? AND uploadId = ?");
            $unlikeStmt->bind_param("ss", $username, $fileId);
            $unlikeStmt->execute();
        
            if ($unlikeStmt->affected_rows === 0) {
                http_response_code(400);
                error_log("error deleting from user_likes");
                echo json_encode(['error' => 'error deleting from user_likes']);
                exit;
            }
        
            // Decrement likes count
            $stmt = $conn->prepare("UPDATE items SET likes = GREATEST(likes - 1, 0) WHERE uploadId = ?");
            $stmt->bind_param("s", $fileId);
            $stmt->execute();
            $action = 'unliked';

            // Check if any rows were affected
            if ($stmt->affected_rows === 0) {
                http_response_code(404);
                error_log("Item not found when decrementing likes in items");
                echo json_encode(['error' => 'Item not found when decrementing likes in items']);
                exit;
            }
        }
        
        // Get updated like count
        $stmt = $conn->prepare("SELECT likes FROM items WHERE uploadId = ?");
        $stmt->bind_param("s", $fileId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if (!$row) {
            http_response_code(404);
            error_log("Item not found when getting like count");
            echo json_encode(['error' => 'Item not found when getting like count']);
            exit;
        }
        
        // Return success response
        echo json_encode([
            'success' => true,
            'action' => $action,
            'fileId' => $fileId,
            'newLikeCount' => (int)$row['likes']
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
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>