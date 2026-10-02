<?php
// Shared S3 setup.
// Production (Elastic Beanstalk): credentials come from the EC2 instance role.
// Local dev: set ACCESS_KEY / SECRET_ACCESS_KEY in .env.
require_once BASE_PATH . 'vendor/autoload.php';

use Aws\S3\S3Client;

function goober_s3_bucket() {
    return getenv('APP_ENV') === 'production' ? 'gooberbucketgc6788' : 'gooberbucketgc6788test';
}

function goober_s3_client() {
    $config = [
        'version' => 'latest',
        'region'  => 'us-east-1',
    ];

    $key = getenv('ACCESS_KEY');
    $secret = getenv('SECRET_ACCESS_KEY');
    if ($key && $secret) {
        $config['credentials'] = ['key' => $key, 'secret' => $secret];
    }

    return new S3Client($config);
}

// Build a short-lived presigned GET URL so the browser fetches media straight from S3.
// S3 supports HTTP range requests natively (needed for <video> audio + seeking);
// proxying bytes through PHP does not. $extra adds GetObject params, e.g.
// ['ResponseContentDisposition' => 'attachment; filename="video.mp4"'].
function goober_s3_presigned_url($key, $expires = '+20 minutes', array $extra = []) {
    $s3 = goober_s3_client();
    $cmd = $s3->getCommand('GetObject', array_merge([
        'Bucket' => goober_s3_bucket(),
        'Key'    => $key,
    ], $extra));
    return (string) $s3->createPresignedRequest($cmd, $expires)->getUri();
}
?>
