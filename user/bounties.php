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

// Include database connection
require_once BASE_PATH . 'src/connectToDB_Login.php';

// At start of every script
session_set_cookie_params([
    'lifetime' => 86400,        // 24 hours
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,           // HTTPS only
    'httponly' => true,         // No JS access
    'samesite' => 'Strict'
]);

if (!isset($_SESSION['session_start'])) {
    $_SESSION['session_start'] = time();
} elseif (time() - $_SESSION['session_start'] > 86400) {
    // Max 24-hour session duration
    // Set a flag before clearing session
    $_SESSION['login_required'] = true; // This flag works for both expired sessions or never logged in
    
    // Store the flag in a temporary variable
    $login_required = true;
    session_unset();
    session_destroy();
    // Start a new session and restore the flag
    session_start();
    $_SESSION['login_required'] = $login_required;
}

// We can use the same flag elsewhere
if (!isset($_SESSION['username'])) {
    $_SESSION['login_required'] = true;
}

if (isset($_SESSION['login_required']) && $_SESSION['login_required']){
    echo "<div class='alert alert-warning'>You aren't logged in. Some features may be unavailable until you log in.</div>";
    echo "<script>
            setTimeout(() => {
                const toasts = document.querySelectorAll('.alert.alert-warning');
                toasts.forEach(toast => {
                    if (toast) {
                        toast.parentNode.removeChild(toast);
                    }
                });
            }, 3600);
          </script>"; 
    unset($_SESSION['login_required']); // Clear the flag after showing the message
}

// // Define directories
// $source_dir = BASE_PATH . "uploads/";  // Source directory for items
// $image_dir = BASE_PATH . "images/";    // Target directory for home page
// // Correctly clear existing images in the image directory
// $files = glob($image_dir . '*');  // Get all files in the directory
// if (!empty($files)) {
//     foreach($files as $file) {
//         if (is_file($file)) {      // Make sure it's a file, not a directory
//             if (unlink($file)) {   // Attempt to delete the file
//                 error_log("Deleted file: $file");  // Log deletion (instead of echo)
//             } else {
//                 error_log("Failed to delete file: $file");  // Log failure
//             }
//         }
//     }
// } else {
//     error_log("No files found in image directory to delete.");  // Log if empty
// }


try {
    $stmt = $conn->prepare("SELECT Path FROM items ORDER BY likes DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $top_items = [];
    while ($row = $result->fetch_column()) {
        $top_items[] = $row;
    }

    // // Copy top items to images directory
    // $copied_files = [];
    // // Log the count of items in the array
    // error_log('Number of items in $top_items: ' . count($top_items));
    // foreach ($top_items as $item) {
    //     $source_path = $source_dir . basename($item);
    //     $dest_path = $image_dir . basename($item);
    //     error_log("Source path: $source_path");
    //     error_log("Destination path: $dest_path");

    //     if (file_exists($source_path)) {
    //         if (copy($source_path, $dest_path)) {
    //             $copied_files[] = basename($item);
    //         } else {
    //             // Log error if copy fails
    //             error_log("Failed to copy file: $item");
    //         }
    //     }else{
    //         error_log("File not found at source path: $source_path");
    //     }
    //}
} catch(Exception $e) {
    // Log any errors
    error_log("Error: " . $e->getMessage());
    // Optionally redirect to an error page
    header("Location:" . BASE_PATH . "error.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="<?php echo WEB_ROOT . 'style.css?v=' . filemtime(BASE_PATH . 'style.css'); ?>">
    <title>Home</title>
</head>
<body style="background-image: url(<?php echo WEB_ROOT; ?>assets/goober.jpg);">
    <?php include BASE_PATH . 'src/nav.php'; ?>
    <div class="scroll-container">
        <?php foreach ($top_items as $file) { ?>
            <?php $file = ltrim($file, '/'); 
                  error_log("File in bounties: $file", 3, BASE_PATH . 'logs/custom.log');
                  $fileID = explode('video_',$file)[1];
                  error_log("File ID in bounties: $fileID", 3, BASE_PATH . 'logs/custom.log');

            ?>
            <br>
            <div class="scroll-item">
                <video controls width="100%" height="100%" style="object-fit: contain; flex: 1" preload="metadata">
                    <source src="/api/stream_video.php?path=<?php echo htmlspecialchars($file); ?>" type="video/mp4">
                    <p style="flex: 1">Your browser does not support HTML5 video. 
                        <a href="/api/download_video.php?path=<?php echo htmlspecialchars($file); ?>">Download the video</a> instead.
                    </p>
                </video>
                <br>
                <a href="/user/explore.php?itemName=<?php echo urlencode($fileID); ?>" class="scroll-item-btn" style="flex: 1;">
                    See what people think
                </a>
            </div>
        <?php } ?>
    </div>
    <?php require_once BASE_PATH . 'src/footer.php'; echo generateFooter(); ?>
</body>
</html>