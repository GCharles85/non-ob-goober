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
require_once BASE_PATH . 'src/connectToDB_Login.php';

// Set header for JSON response
header('Content-Type: application/json');

// Main processing
try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }
    
    // Get POST data
    $requestData = json_decode(file_get_contents('php://input'), true);
    
    if (!$requestData) {
        // Try regular POST data
        $requestData = $_POST;
    }
    
    // Validate required fields
    $requiredFields = ['name', 'path', 'username'];
    foreach ($requiredFields as $field) {
        if (empty($requestData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Extract data
    $name = substr($requestData['name'], 0, 100); // Limit to varchar(20)
    $path = substr($requestData['path'], 0, 100); // Limit to varchar(100)
    $keywords = isset($requestData['keywords']) ? substr($requestData['keywords'], 0, 100) : "dream, video, generated";
    $description = isset($requestData['description']) ? $requestData['description'] : "Dream video generated on " . date('Y-m-d H:i:s');
    $username = $requestData['username'];
    $uploadId = $requestData['uploadId'];
    
    // Prepare and execute insertion query
    $stmt = $conn->prepare("INSERT INTO items (Name, Keywords, Path, Description, uploaded_by, uploadId) VALUES (?, ?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }
    
    $stmt->bind_param("ssssss", $name, $keywords, $path, $description, $username, $uploadId);
    $success = $stmt->execute();
    
    if (!$success) {
        throw new Exception("Error inserting record: " . $stmt->error);
    }
    
    $itemId = $conn->insert_id;
    
    // Close connection
    $stmt->close();
    $conn->close();
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'message' => 'Video saved to items table',
        'item_id' => $itemId
    ]);

    if ($environment == 'production') {
        exec('/opt/update-db-dump.sh 2>&1', $output, $return_code);
        if ($return_code === 0) {
            error_log("Backup completed successfully!");
        } else {
            error_log("Backup failed. Return code: $return_code. Output: " . implode("\n", $output));
        } 
    }
    
} catch (Exception $e) {
    // Log the error
    error_log("Save Video Item Error: " . $e->getMessage());
    
    // Return error response
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>