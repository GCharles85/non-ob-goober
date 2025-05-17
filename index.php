<?php
// At start of every script
session_set_cookie_params([
    'lifetime' => 86400,        // 24 hours
    'path' => '/',
    'domain' => '',
    //'secure' => true,           // HTTPS only [2][8]
    'httponly' => true,         // No JS access [1]
    'samesite' => 'Strict'
]);
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/bootstrap.php'; // Adjust path as needed to reach bootstrap.php
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

// Function to create directory with proper permissions
function createDirectoryIfNotExists($path, $permissions = 0755) {
    // Get the absolute path from document root
    $absolutePath = BASE_PATH . $path;
    
    // Check if directory exists
    if (!file_exists($absolutePath)) {
        // Create the directory with specified permissions
        if (mkdir($absolutePath, $permissions, true)) {
            //error_log("Created directory: $absolutePath with permissions: " . decoct($permissions));
            return true;
        } else {
            //error_log("Failed to create directory: $absolutePath");
            return false;
        }
    } else {
        // Directory exists, update permissions if needed
        if (chmod($absolutePath, $permissions)) {
            //error_log("Updated permissions for existing directory: $absolutePath to " . decoct($permissions));
        }
        return true;
    }
}

// Create directories
createDirectoryIfNotExists('images', 0755); // rwxr-xr-x
createDirectoryIfNotExists('uploads', 0777); // rwxrwxrwx

// Optional: Log results
    //error_log("Directory check/creation completed at " . date('Y-m-d H:i:s'));

// Optional: Output success message if this is being run directly
if (basename($_SERVER['SCRIPT_NAME']) == basename(__FILE__)) {
    //error_log("Directory setup completed successfully at " . date('Y-m-d H:i:s'));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="<?php echo WEB_ROOT; ?>style.css?v=<?php echo filemtime(BASE_PATH . 'style.css'); ?>">
    <title>GooberBox</title>
</head>
<body style="background-image: url(<?php echo WEB_ROOT; ?>assets/goober.jpg);">
<div class="index-div" style="
    position: fixed;
    min-height: 500px;  /* Adjust value as needed */
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background-color: #d4b996; 
    padding: 20px;  /* Reduced padding for mobile */
    border-radius: 25px;
    text-align: center;
    width: 90%;  /* Wider on small screens */
    max-width: 600px;  /* Maximum width on larger screens */
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    font-family: 'Comic Sans MS', 'Marker Felt', 'Arial Rounded MT Bold', sans-serif;
    font-weight: bold;
    color: white;
    font-size: 30px;  /* Smaller base font size */
    line-height: 1.5;
    z-index: 999;
    box-sizing: border-box;  /* Important for correct sizing */
">
    <h2 style="margin-top: 0; font-size: 26px; text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);">Hello, fellow Goober!</h2>
    <div style="display: flex; flex-direction: column; justify-content: center;">
        <p style="margin-bottom: 0;">
            GooberBox is a platform that leverages AI to provide short high quality AI generated films with music and narration.
        </p>
    </div>
</div>
    <?php include BASE_PATH . 'src/nav.php'; ?>
    <?php require_once BASE_PATH . 'src/footer.php'; echo generateFooter(); ?>
</body>
</html>