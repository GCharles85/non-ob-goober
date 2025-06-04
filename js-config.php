<?php
session_start();
// Create a file called js-config.php in your root directory
header('Content-Type: application/javascript');

// Define the web root for JavaScript
$jsWebRoot = defined('WEB_ROOT') ? WEB_ROOT : '/';
?>

// Configuration variables for JavaScript
const CONFIG = {
  WEB_ROOT: "<?php echo $jsWebRoot; ?>",
  API_BASE: "<?php echo $jsWebRoot; ?>api/",
  SRC_BASE: "<?php echo $jsWebRoot; ?>src/",
  CURRENT_USER: "<?php echo $_SESSION['username']; ?>",
  ADMIN: "bumbameal882",
  ENV: "<?php echo getenv('APP_ENV') ?: 'development'; ?>"
};