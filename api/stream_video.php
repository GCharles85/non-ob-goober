<?php
// video.php - Stream video through your server
$videoPath = $_GET['path'];

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
?>