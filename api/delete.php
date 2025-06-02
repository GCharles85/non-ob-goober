<?php
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
require_once BASE_PATH . 'loadenv.php';
require BASE_PATH . 'vendor/autoload.php';
require_once BASE_PATH . 'src/connectToDB_Login.php';


use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Environment check
$environment = getenv('APP_ENV') ?: 'development';

// Verify user is logged in
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Please log in']);
    exit;
}

// Get current user info
$current_username = $_SESSION['username'];

// Initialize S3 client
$s3 = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'credentials' => [
        'key' => $_ENV['ACCESS_KEY'],
        'secret' => $_ENV['SECRET_ACCESS_KEY']
    ]
]);

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($method === 'POST') {
    switch ($action) {
        case 'deleteComment':
            deleteComment();
            break;
        case 'deleteUser':
            deleteUser();
            break;
        case 'deletePost':
            deletePost();
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function deleteComment() {
    global $conn, $current_user_id;
    
    $comment_id = $_POST['comment_id'] ?? null;
    
    if (!$comment_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Comment ID required']);
        return;
    }
    
    try {
        // Verify user owns the comment or is admin
        $query = "SELECT user_id FROM comments WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $comment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $comment = $result->fetch_assoc();
        
        if (!$comment) {
            http_response_code(404);
            echo json_encode(['error' => 'Comment not found']);
            return;
        }
        
        // Check if user owns comment or is admin
        if ($comment['user_id'] != $current_user_id && !isAdmin($current_user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized to delete this comment']);
            return;
        }
        
        // Delete comment
        $query = "DELETE FROM comments WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $comment_id);
        $result = $stmt->execute();
        
        if ($result) {
            // Backup database
            backupDatabase();
            
            echo json_encode(['success' => 'Comment deleted successfully']);
        } else {
            throw new Exception('Failed to delete comment');
        }
        
    } catch (Exception $e) {
        error_log("Error deleting comment: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete comment']);
    }
}

function deleteUser() {
    global $conn, $s3Client, $current_user_id;
    
    $user_id = $_POST['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID required']);
        return;
    }
    
    try {
        // Verify user can delete (self or admin)
        if ($user_id != $current_user_id && !isAdmin($current_user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized to delete this user']);
            return;
        }
        
        $conn->autocommit(FALSE);
        
        // Get all user's posts for S3 cleanup
        $query = "SELECT file_path FROM items WHERE user_id = ? AND file_path IS NOT NULL";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_posts = $result->fetch_all(MYSQLI_ASSOC);
        
        // Get all user's messages for S3 cleanup
        $query = "SELECT file_path FROM messages WHERE sender_id = ? AND file_path IS NOT NULL";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_messages = $result->fetch_all(MYSQLI_ASSOC);
        
        // Delete user's comments
        $query = "DELETE FROM comments WHERE user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // Delete user's messages
        $query = "DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        
        // Delete user's posts
        $query = "DELETE FROM items WHERE user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // Delete user
        $query = "DELETE FROM users WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $result = $stmt->execute();
        
        if (!$result) {
            throw new Exception('Failed to delete user');
        }
        
        $conn->commit();
        $conn->autocommit(TRUE);
        
        // Delete files from S3
        deleteFilesFromS3(array_merge($user_posts, $user_messages));
        
        // Force logout if user deleted themselves
        if ($user_id == $current_user_id) {
            session_destroy();
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        // Backup database
        backupDatabase();
        
        echo json_encode(['success' => 'User deleted successfully', 'logout' => ($user_id == $current_user_id)]);
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(TRUE);
        error_log("Error deleting user: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete user']);
    }
}

function deletePost() {
    global $conn, $s3Client, $current_user_id;
    
    $post_id = $_POST['post_id'] ?? null;
    
    if (!$post_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Post ID required']);
        return;
    }
    
    try {
        // Get post info and verify ownership
        $query = "SELECT user_id, file_path FROM items WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $post = $result->fetch_assoc();
        
        if (!$post) {
            http_response_code(404);
            echo json_encode(['error' => 'Post not found']);
            return;
        }
        
        // Check if user owns post or is admin
        if ($post['user_id'] != $current_user_id && !isAdmin($current_user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized to delete this post']);
            return;
        }
        
        $conn->autocommit(FALSE);
        
        // Delete associated comments
        $query = "DELETE FROM comments WHERE post_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        
        // Delete post
        $query = "DELETE FROM items WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $post_id);
        $result = $stmt->execute();
        
        if (!$result) {
            throw new Exception('Failed to delete post');
        }
        
        $conn->commit();
        $conn->autocommit(TRUE);
        
        // Delete file from S3 if exists
        if ($post['file_path']) {
            deleteFilesFromS3([['file_path' => $post['file_path']]]);
        }
        
        // Backup database
        backupDatabase();
        
        echo json_encode(['success' => 'Post deleted successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(TRUE);
        error_log("Error deleting post: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete post']);
    }
}

function deleteFilesFromS3($files) {
    global $s3Client;
    
    $bucket = 'your-bucket-name'; // Replace with your bucket name
    
    foreach ($files as $file) {
        if (empty($file['file_path'])) continue;
        
        try {
            $s3Client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $file['file_path']
            ]);
            error_log("Deleted S3 file: " . $file['file_path']);
            
        } catch (AwsException $e) {
            error_log("Failed to delete S3 file " . $file['file_path'] . ": " . $e->getMessage());
        }
    }
}

function isAdmin($user_id) {
    global $conn;
    
    $query = "SELECT is_admin FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    return $user && $user['is_admin'];
}

function backupDatabase() {
    global $environment;
    
    if ($environment == 'production') {
        exec('/opt/update-db-dump.sh 2>&1', $output, $return_code);
        if ($return_code === 0) {
            error_log("Backup completed successfully!");
        } else {
            error_log("Backup failed. Return code: $return_code. Output: " . implode("\n", $output));
        }
    }
}
?>