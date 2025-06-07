<?php
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php';
}
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$environment = getenv('APP_ENV') ?: 'development';
require_once BASE_PATH . 'loadenv.php';

$videoPath = $_GET['path'] ?? '';
if (empty($videoPath)) {
    http_response_code(400);
    exit('Video path required');
}

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

$bucket = ($environment == 'production') ? 'gooberbucketgc6788' : 'gooberbucketgc6788test';

try {
    // Check if object exists and get metadata first
    $headResult = $s3->headObject([
        'Bucket' => $bucket,
        'Key' => $videoPath
    ]);
    
    // Create ETag based on S3 object's LastModified + path
    $etag = md5($videoPath . $headResult['LastModified']->getTimestamp());
    
    // Set cache headers BEFORE checking if-none-match
    header('Cache-Control: public, max-age=86400, immutable');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
    header('ETag: "' . $etag . '"');
    
    // Check if browser has cached version
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && 
        $_SERVER['HTTP_IF_NONE_MATCH'] === '"' . $etag . '"') {
        http_response_code(304); // Not Modified
        exit;
    }
    
    // Get the actual video content
    $result = $s3->getObject([
        'Bucket' => $bucket,
        'Key' => $videoPath
    ]);
    
    // Set video headers
    header('Content-Type: ' . ($result['ContentType'] ?? 'video/mp4'));
    header('Content-Length: ' . ($result['ContentLength'] ?? 0));
    header('Accept-Ranges: bytes');
    
    // Stream the video
    echo $result['Body'];
    
} catch (Exception $e) {
    error_log("From stream_video.php, Video error: " . $e->getMessage());
    
    if (strpos($e->getMessage(), 'NoSuchKey') !== false) {
        http_response_code(404);
        exit('Video not found');
    } else {
        http_response_code(500);
        exit('Server error');
    }
}
?>