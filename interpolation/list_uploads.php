<?php
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
// list_uploads.php
header('Content-Type: application/json');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';

require BASE_PATH . 'vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$s3Client = new S3Client([
    'region' => 'us-east-1',
    'version' => 'latest',
    'credentials' => [
        'key' => $_ENV['ACCESS_KEY'],
        'secret' => $_ENV['SECRET_ACCESS_KEY']
    ]
]);

// $uploadsDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads';
// $files = [];

// if (is_dir($uploadsDir)) {
//     $dirContents = scandir($uploadsDir);
    
//     foreach ($dirContents as $file) {
//         // Only include mp4 files
//         if (pathinfo($file, PATHINFO_EXTENSION) === 'mp4') {
//             $files[] = $file;
//         }
//     }
// }

// Fetch files from S3 bucket
try {
    if($environment == 'production'){
        $result = $s3Client->listObjects([
            'Bucket' => 'gooberbucketgc6788',
            'Prefix' => 'uploads/'
        ]);
    }else{
        $result = $s3Client->listObjects([
            'Bucket' => 'gooberbucketgc6788test',
            'Prefix' => 'uploads/'
        ]);
    }
    
    foreach ($result['Contents'] as $object) {
        $files[] = $object['Key'];
    }
} catch (AwsException $e) {
    error_log("Error fetching files from S3: " . $e->getMessage());
}

echo json_encode($files);
?>