<?php
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php';
}
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once BASE_PATH . 'loadenv.php';
require_once BASE_PATH . 'src/s3_client.php';

$videoPath = ltrim($_GET['path'] ?? '', '/');
// Only serve generated videos, never other objects in the bucket
if ($videoPath === '' || strpos($videoPath, 'uploads/') !== 0 || strpos($videoPath, '..') !== false) {
    http_response_code(404);
    exit('Video not found');
}

try {
    // Redirect to a presigned S3 URL. S3 serves the bytes with full HTTP range
    // support, which the browser needs for video+audio playback and seeking.
    $url = goober_s3_presigned_url($videoPath, '+20 minutes', [
        'ResponseContentType' => 'video/mp4',
    ]);
    header('Location: ' . $url, true, 302);
    exit;
} catch (Exception $e) {
    error_log("stream_video.php presign error: " . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}
?>
