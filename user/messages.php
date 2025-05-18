<?php
session_start();
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
// start output buffering
ob_start();
// At start of every script
session_set_cookie_params([
    'lifetime' => 86400,        // 24 hours
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,           // HTTPS only [2][8]
    'httponly' => true,         // No JS access [1]
    'samesite' => 'Strict'
]);

if (!isset($_SESSION['session_start'])) {
    $_SESSION['session_start'] = time();
} elseif (time() - $_SESSION['session_start'] > 86400) {
    // Max 24-hour session duration [6]
    session_unset();
    session_destroy();
    header("Location: " . WEB_ROOT . "user/login.php");
    exit();
}
if (!isset($_SESSION['username'])) {
    header("Location: " . WEB_ROOT . "user/login.php"); // Redirect unauthenticated users
    exit();
}
// end output buffering and discard content
ob_end_clean();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages</title>
    <link rel="stylesheet" href="<?php echo WEB_ROOT . 'style.css?v=' . filemtime(BASE_PATH . 'style.css'); ?>">
</head>
<body>
    <?php include BASE_PATH . 'src/nav.php'; ?>
    <div class="convo-container">
        <div class="conversations">
            <h2>Conversations</h2>
            <div class="search-container">
                <input type="text" id="userSearch" placeholder="Search users...">
                <button id="searchBtn">Search</button>
            </div>
            <div id="searchResults" class="search-results"></div>
            <ul id="conversationList">
                <!-- Conversation list will be populated here -->
            </ul>
        </div>
        <div class="messages">
            <h2 id="conversationTitle"></h2>
            <ul id="messageList">
                <!-- Messages will be populated here -->
            </ul>
            <div class="input-container">
                <!-- <div class="agent-toggle">
                    <input type="checkbox" id="useAgent" />
                    <label for="useAgent">Use AI Agent</label>
                </div> -->
                <input type="text" id="newMessage" placeholder="Type a message...">
                <button id="sendBtn">Send</button>
            </div>
        </div>
    </div>
    <script>
    // Pass PHP session data to JavaScript securely
    const currentUser = <?php echo json_encode($_SESSION['username'], JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    </script>
    <script src="<?php echo WEB_ROOT; ?>js-config.php"></script>
    <script src="<?php echo WEB_ROOT . 'JS/messages.js?v=' . filemtime(BASE_PATH . 'JS/messages.js'); ?>"></script>
    <?php require_once '../src/footer.php'; echo generateFooter(); ?>
</body>
</html>
