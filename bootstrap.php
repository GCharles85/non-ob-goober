<?php
// Disable error display to users, but log everything important
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set error log location
if (getenv('APP_ENV') === 'production') {
    // We're on Elastic Beanstalk
    ini_set('error_log', '/var/log/php-fpm/www-error.log');
} else {
    // Local or other environment
    ini_set('error_log', __DIR__ . '/logs/custom.log');
    
    // Create logs directory if it doesn't exist
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0755, true);
    }
}

// Define base path and web root constants
define('BASE_PATH', __DIR__ . '/');
define('WEB_ROOT', '/');

// Load environment variables
require_once BASE_PATH . 'loadenv.php';
?>