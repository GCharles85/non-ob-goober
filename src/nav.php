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
    <div class="menu-toggle">☰</div>
    <ul>
        <?php if (isset($_SESSION['username']) && !empty($_SESSION['username'])): ?>
            <li>
                <span class="nav-link"><?php echo "Welcome, " . htmlspecialchars($_SESSION['username']) . "!"; ?></span>
            </li>
        <?php else: ?>
            <li><a href="<?php echo WEB_ROOT; ?>user/login.php" class="<?php echo ($current_page == 'login.php') ? 'active' : ''; ?>">Login</a></li>
        <?php endif; ?>
        <li><a href="<?php echo WEB_ROOT; ?>user/bounties.php" class="<?php echo ($current_page == 'bounties.php') ? 'active' : ''; ?>">Bounties</a></li>
        <li><a href="<?php echo WEB_ROOT; ?>user/upload.php" class="<?php echo ($current_page == 'upload.php') ? 'active' : ''; ?>">Upload</a></li>
        <li><a href="<?php echo WEB_ROOT; ?>user/messages.php" class="<?php echo ($current_page == 'messages.php') ? 'active' : ''; ?>">Messages</a></li>
        <li><a href="<?php echo WEB_ROOT; ?>user/logout.php" class="<?php echo ($current_page == 'logout.php') ? 'active' : ''; ?>">Logout</a></li>
        <?php if (isset($_SESSION['username']) && !empty($_SESSION['username'])): ?>
            <li>
                <button class="delete-btn" style="font-weight: bold; font-size: 14px;">Delete Account?</button>
            </li>
        <?php endif; ?>
    </ul>
</nav>
<script>
        document.querySelector('.menu-toggle').addEventListener('click', function() {
            document.querySelector('nav ul').classList.toggle('show');
        });

        document.querySelector('.delete-btn').addEventListener('click', function() {
            const confirmDelete = confirm('Are you sure you want to delete your account?');
            if (confirmDelete) {
                //window.location.href = '<?php echo WEB_ROOT; ?>user/delete_account.php';
            }
        });
</script>
</body>
</html>