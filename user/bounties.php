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

// Include database connection
require_once BASE_PATH . 'src/connectToDB_Login.php';

// At start of every script
session_set_cookie_params([
    'lifetime' => 86400,        // 24 hours
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,           // HTTPS only
    'httponly' => true,         // No JS access
    'samesite' => 'Strict'
]);

if (!isset($_SESSION['session_start'])) {
    $_SESSION['session_start'] = time();
} elseif (time() - $_SESSION['session_start'] > 86400) {
    // Max 24-hour session duration
    // Set a flag before clearing session
    $_SESSION['login_required'] = true; // This flag works for both expired sessions or never logged in
    
    // Store the flag in a temporary variable
    $login_required = true;
    session_unset();
    session_destroy();
    // Start a new session and restore the flag
    session_start();
    $_SESSION['login_required'] = $login_required;
}

// We can use the same flag elsewhere
if (!isset($_SESSION['username'])) {
    $_SESSION['login_required'] = true;
}else{
    $_SESSION['login_required'] = false;
}

if (isset($_SESSION['login_required']) && $_SESSION['login_required']){
    echo "<div class='alert alert-warning'>You aren't logged in. Some features may be unavailable until you log in.</div>";
    echo "<script>
            setTimeout(() => {
                const toasts = document.querySelectorAll('.alert.alert-warning');
                toasts.forEach(toast => {
                    if (toast) {
                        toast.parentNode.removeChild(toast);
                    }
                });
            }, 3600);
          </script>"; 
    unset($_SESSION['login_required']); // Clear the flag after showing the message
}

try {
    $stmt = $conn->prepare("SELECT Path, likes FROM items ORDER BY likes DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $top_items = [];
    while ($row = $result->fetch_assoc()) {
        $top_items[] = $row;
    }

    // If user is logged in, check which ones they've liked
    $userLikedItems = [];
    if (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
        $username = $_SESSION['username'];
        
        // Simple query - just get the uploadIds this user has liked
        $likedStmt = $conn->prepare("SELECT uploadId FROM user_likes WHERE username = ?");
        $likedStmt->bind_param("s", $username);
        $likedStmt->execute();
        $likedResult = $likedStmt->get_result();
        
        while ($likedRow = $likedResult->fetch_assoc()) {
            $userLikedItems[] = $likedRow['uploadId'];
        }
    }
} catch(Exception $e) {
    // Log any errors
    error_log("Error: " . $e->getMessage());
    // Optionally redirect to an error page
    header("Location:" . BASE_PATH . "error.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="<?php echo WEB_ROOT . 'style.css?v=' . filemtime(BASE_PATH . 'style.css'); ?>">
    <title>Home</title>
</head>
<body style="background-image: url(<?php echo WEB_ROOT; ?>assets/goober.jpg);">
    <?php include BASE_PATH . 'src/nav.php'; ?>
    <div class="scroll-container">
        <?php foreach ($top_items as $item) { ?>
            <?php $file = ltrim($item['Path'], '/'); 
                  $fileForItemName = pathinfo($file, PATHINFO_FILENAME);
                  error_log("File in bounties: $file", 3, BASE_PATH . 'logs/custom.log');
                  $fileID = explode('video_',$fileForItemName)[1];
                  error_log("File ID in bounties: $fileID", 3, BASE_PATH . 'logs/custom.log');

                  // Get likes from the query result
                  $currentLikes = (int)$item['likes'];
                  // Simple check - is this fileID in the user's liked items?
                  $userHasLiked = in_array($fileID, $userLikedItems);
            ?>
            <br>
            <div class="scroll-item">
                <video controls width="100%" height="100%" style="object-fit: contain; flex: 1" preload="metadata">
                    <source src="/api/stream_video.php?path=<?php echo htmlspecialchars($file); ?>" type="video/mp4">
                    <p style="flex: 1">Your browser does not support HTML5 video. 
                        <a href="/api/download_video.php?path=<?php echo htmlspecialchars($file); ?>">Download the video</a> instead.
                    </p>
                </video>
                <br>
                <button class="like-btn" onclick="toggleLike(this, '<?php echo htmlspecialchars($fileID); ?>')">
                   <?php echo !isset($_SESSION['username']) ? 'disabled title="Please login to like"' : ''; ?>>
            <span class="heart"><?php echo $userHasLiked ? '♥' : '♡'; ?></span>
            <span class="like-text"><?php echo $userHasLiked ? 'Liked' : 'Like'; ?></span>
            <span class="like-count">(<?php echo $currentLikes; ?>)</span> 
                </button>
                <a href="/user/explore.php?itemName=<?php echo urlencode($fileID); ?>" class="scroll-item-btn" style="flex: 1;">
                    See what people think
                </a>
            </div>
        <?php } ?>
    </div>
    <?php require_once BASE_PATH . 'src/footer.php'; echo generateFooter(); ?>
</body>
<script>
function toggleLike(button, fileId) {
    const heart = button.querySelector('.heart');
    const likeText = button.querySelector('.like-text');
    const likeCount = button.querySelector('.like-count');
    
    const isCurrentlyLiked = button.classList.contains('liked');
    const newLikedState = !isCurrentlyLiked;
    
    // Make the API call first
    fetch('/api/toggle_like.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            fileId: fileId,
            liked: newLikedState
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Only update UI if the API call was successful
            if (newLikedState) {
                // Like
                button.classList.add('liked');
                heart.textContent = '♥';
                likeText.textContent = 'Liked';
            } else {
                // Unlike
                button.classList.remove('liked');
                heart.textContent = '♡';
                likeText.textContent = 'Like';
            }
            // Update the like count display
            likeCount.textContent = `(${data.newLikeCount})`;
        } else {
            // Handle error - don't change UI
            console.error('Error updating like status:', data.error);
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        // Don't change UI on network error
    });
}
</script>
</html>