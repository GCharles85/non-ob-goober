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

// video.php - Stream video through your server
$videoPath = $_GET['path'];

require BASE_PATH . 'vendor/autoload.php';
use Aws\S3\S3Client;

$s3 = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'credentials' => [
        'key' => $_ENV['ACCESS_KEY'],
        'secret' => $_ENV['SECRET_ACCESS_KEY']
    ]
]);

try {
    // Get video from S3
    $result = $s3->getObject([
        'Bucket' => 'gooberbucketgc6788',
        'Key' => $videoPath
    ]);
    
    // Set appropriate headers for video
    header('Content-Type: ' . $result['ContentType']);
    header('Content-Length: ' . $result['ContentLength']);
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Stream the video
    echo $result['Body'];
    
} catch (Exception $e) {
    error_log("From stream_video.php, Video not found: " . $e->getMessage());
    http_response_code(404);
    exit('Video not found');
}
?>