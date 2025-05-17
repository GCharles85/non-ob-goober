<?php 
session_start();
$current_page = basename($_SERVER['PHP_SELF']);
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';
if ($environment === 'production') {
    ini_set('error_log', 'stderr'); // Works for Vercel and many cloud platforms
} else {
    // Use BASE_PATH to determine log directory location
    // Make sure BASE_PATH is already defined before this code runs
    $logDir = rtrim(BASE_PATH, '/') . '/logs';
    
    // Alternative: Use WEB_ROOT if that's more appropriate for your setup
    // $logDir = rtrim($_SERVER['DOCUMENT_ROOT'] . WEB_ROOT, '/') . '/logs';
    
    // Make sure the logs directory exists
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/custom.log');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="<?php echo WEB_ROOT . 'style.css?v=' . filemtime(BASE_PATH . 'style.css'); ?>">
</head>
<body>
<nav>
    <div class="menu-toggle">☰ Menu</div>
    <ul>
        <li><a href="<?php echo WEB_ROOT; ?>user/login.php" class="<?php echo ($current_page == 'login.php') ? 'active' : ''; ?>">Login</a></li>
        <li><a href="<?php echo WEB_ROOT; ?>user/bounties.php" class="<?php echo ($current_page == 'bounties.php') ? 'active' : ''; ?>">Bounties</a></li>
        <li><a href="<?php echo WEB_ROOT; ?>user/upload.php" class="<?php echo ($current_page == 'upload.php') ? 'active' : ''; ?>">Upload</a></li>
        <li><a href="<?php echo WEB_ROOT; ?>user/messages.php" class="<?php echo ($current_page == 'messages.php') ? 'active' : ''; ?>">Messages</a></li>
        <li><a href="<?php echo WEB_ROOT; ?>user/logout.php" class="<?php echo ($current_page == 'logout.php') ? 'active' : ''; ?>">Logout</a></li>
    </ul>
</nav>
<script>
        document.querySelector('.menu-toggle').addEventListener('click', function() {
            document.querySelector('nav ul').classList.toggle('show');
        });
</script>
</body>
</html>