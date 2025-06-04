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

// Database connection
require_once BASE_PATH . 'src/connectToDB_Login.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo WEB_ROOT . 'style.css?v=' . filemtime(BASE_PATH . 'style.css'); ?>" />
    <title>Explore</title>
    <script src="<?php echo WEB_ROOT; ?>js-config.php"></script>
    <script src="<?php echo WEB_ROOT . 'JS/toggleComments.js?v=' . filemtime(BASE_PATH . 'JS/toggleComments.js'); ?>"></script>
</head>
<body class="explore-body">
    <?php include BASE_PATH . 'src/nav.php'; ?>
    <div class="explore-container">
        <div class="video-container">
            <?php
                if (isset($_GET['itemName'])) {
                    $itemName = $_GET['itemName'];
                    // Check if the item exists
                    if ($conn) {
                        $stmt = $conn->prepare("SELECT Description, Path, uploaded_by, upload_timestamp FROM items WHERE uploadId = ?");
                        $stmt->bind_param("s", $itemName);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            $row = $result->fetch_assoc();
                            $uploadedTimestamp = $row['upload_timestamp'];
                            $uploadedBy = $row['uploaded_by']; // get the uploader
                            $description = $row['Description'];
                            $path = $row['Path'];
                            $formattedDate = date('F j, Y', strtotime($uploadedTimestamp));
                                        
                            echo '<div class="uploader-info">
                                    <span class="uploader-name">Uploaded by ' . htmlspecialchars($uploadedBy) . ' on ' . $formattedDate . '</span> 
                                </div>';    
                            echo '<video src="/api/stream_video.php?path=' . ltrim($path, '/') . '" style="width: 90%; height: 25vh; margin-left: 5%; margin-right: 5%; border-radius: 15px;" controls></video><br>';
                            echo '<p class="item-description" style="display: inline-block; background-color: #3498db; color: white;">' . 'Prompt: ' . htmlspecialchars($description) . '</p>';
                            echo '<a href="/api/download_video.php?path=' . $path . '" 
                            class="download-button" 
                            download>
                                Download
                            </a>';
                            if ($uploadedBy == $_SESSION['username']) {
                                echo '<button class="delete-btn-video" data-image-id="' . $itemName . '">
                                        <span class="delete-icon-video"></span>
                                        Delete
                                    </button>';
                            }
                        } else {
                            error_log("Item not found in db: " . $itemName);
                            echo '<p>Item not found.</p>';
                        }
                    } else {
                        error_log("Database connection error in explore.php");
                        echo '<p>Database connection error.</p>';
                    }
                } else {
                    error_log("Item not set in GET var in explore.php");
                    echo '<p>Item not found.</p>';
                }
            ?>
        </div>
        <div class="comment-container">
            <button class="dropdown-btn">Click to View Comments!<i class="fas fa-caret-down"></i></button>
            <div class="comment-content">
            </div>
            <div class="comment-form">
                <input type="text" id="comment-input" placeholder="Write a comment...">
                <button id="post-comment-btn">Post</button>
            </div>
        </div>
    </div>      
    <script>
        // Declare the itemName variable with the PHP value
        const itemName = "<?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?>";
        
        // Now use the dataset property to set the attributes
        document.addEventListener('DOMContentLoaded', function() {
            const dropdownBtn = document.querySelector('.dropdown-btn');
            const postCommentBtn = document.getElementById('post-comment-btn');
            
            // Set the data-image-id attributes
            dropdownBtn.dataset.imageId = itemName;
            postCommentBtn.dataset.imageId = itemName;
            dropdownBtn.click();
        });
    </script>
    <?php require_once '../src/footer.php'; echo generateFooter(); ?>
</body>
</html>