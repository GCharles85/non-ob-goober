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
    
    // Prepare and execute insertion query
    $stmt = $conn->prepare("INSERT INTO items (Name, Keywords, Path, Description, uploaded_by) VALUES (?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }
    
    $stmt->bind_param("sssss", $name, $keywords, $path, $description, $username);
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