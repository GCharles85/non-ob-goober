<?php
// video.php - Stream video through your server
if (!isset($_GET['id']) || !isUserAuthorized($_GET['id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$videoId = $_GET['id'];
$videoPath = getVideoPath($videoId); // Map ID to S3 path

require 'vendor/autoload.php';
use Aws\S3\S3Client;

$s3 = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1'
]);

try {
    // Get video from S3
    $result = $s3->getObject([
        'Bucket' => 'your-videos-bucket',
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
    http_response_code(404);
    exit('Video not found');
}

function isUserAuthorized($videoId) {
    // Your authorization logic
    session_start();
    return isset($_SESSION['user_id']);
}

function getVideoPath($videoId) {
    // Map video ID to actual S3 path
    $mapping = [
        'video1' => 'videos/course1/lesson1.mp4',
        'video2' => 'videos/course1/lesson2.mp4'
    ];
    return $mapping[$videoId] ?? null;
}
?>