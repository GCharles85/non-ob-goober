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

include BASE_PATH . 'title_from_db.php';
// end output buffering and discard content
//ob_end_clean();
// Assuming this script is at /var/www/html/video_handler.php
// and you've configured Apache to route /videos/ to this script

$cachePath = '/var/cache/apache2/mod_cache_disk/';
$videoPath = '/mnt/d/PTC/'; // Path to your D drive videos

if (isset($_GET['video'])) {
    $videoFile = $_GET['video'];
    $cacheFile = $cachePath . $videoFile;

    if (file_exists($cacheFile)) {
        // Serve from cache
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="' . $videoFile . '"');
        readfile($cacheFile);
    } else {
        // Fetch from D drive and cache it
        $sourceFile = $videoPath . $videoFile;
        if (file_exists($sourceFile)) {
            // Cache the file
            copy($sourceFile, $cacheFile);
            header('Content-Type: video/mp4');
            header('Content-Disposition: attachment; filename="' . $videoFile . '"');
            readfile($cacheFile);
        } else {
            http_response_code(404);
            echo "File not found.";
        }
    }
}
?>
