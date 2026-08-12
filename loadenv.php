<?php

if (getenv('APP_ENV') === 'production') {
    // We're on Elastic Beanstalk
    return;
}

// At start of every script
session_set_cookie_params([
    'lifetime' => 86400,        // 24 hours
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,           // HTTPS only [2][8]
    'httponly' => true,         // No JS access [1]
    'samesite' => 'Strict'
]);
session_start();
require_once 'vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->load();
?>