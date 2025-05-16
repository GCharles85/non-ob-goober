<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define application environment
$environment = getenv('APP_ENV') ?: 'development';

// Set base path for the application
if ($environment === 'production') {
    if (getenv('VERCEL') === '1') {
        // Vercel production path
        define('BASE_PATH', '/var/task/');
    } else {
        // Generic production path if not on Vercel
        define('BASE_PATH', $_SERVER['DOCUMENT_ROOT'] . '/');
    }
} else {
    // Local development path
    define('BASE_PATH', __DIR__ . '/');
}

// For error logs
if (getenv('VERCEL') === '1') {
    // Vercel uses stdout/stderr for logs
    ini_set('error_log', 'stderr');
} else if ($environment === 'production') {
    // Generic production logging
    ini_set('error_log', '/var/log/php/custom.log');
}
// Define web root for URLs
define('WEB_ROOT', '/');

// Load environment variables
require_once BASE_PATH . 'loadenv.php';
?>