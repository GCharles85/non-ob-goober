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
?>
