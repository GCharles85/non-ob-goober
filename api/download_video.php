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

require BASE_PATH . 'vendor/autoload.php';
use Aws\S3\S3Client;

$videoPath = $_GET['path'];

$s3 = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'credentials' => [
        'key' => getenv('ACCESS_KEY'),
        'secret' => getenv('SECRET_ACCESS_KEY')
    ]
]);

try {
    if($environment == 'production'){
        $result = $s3->getObject([
            'Bucket' => 'gooberbucketgc6788',
            'Key' => $videoPath
        ]);
    }else{
        $result = $s3->getObject([
            'Bucket' => 'gooberbucketgc6788test',
            'Key' => $videoPath
        ]);
    }
    
    // Force download headers
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="video.mp4"');
    header('Content-Length: ' . $result['ContentLength']);
    header('Cache-Control: no-cache');
    
    // Stream the file
    echo $result['Body'];
    
} catch (Exception $e) {
    http_response_code(404);
    exit('File not found');
}
?>