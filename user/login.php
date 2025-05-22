<?php
session_start();

// Load bootstrap to set up paths
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';

// start output buffering
ob_start();
ob_end_clean();
// At start of every script
session_set_cookie_params([
    'lifetime' => 86400,        // 24 hours
    'path' => '/',
    'domain' => '',
    //'secure' => true,           // HTTPS only [2][8]
    'httponly' => true,         // No JS access [1]
    //'samesite' => 'Strict'
]);
session_regenerate_id(true); // Destroy old session ID
if(isset($_GET['logout'])) {
    unset($_SESSION['user_id']);
    // You can unset other session variables as needed
}

require_once BASE_PATH . 'src/loginController.php';
// end output buffering and discard content
// todo3 - add crypto for payments, what will I be selling? electronics, videos (but what kinda vids would people want?, thats the illegal shit maybe)
// what will the flow be? Like will I have a separate payment page? Say I do have a payment page. What can I make money off of if not
// data? Money off ollama.
// todo5 - hacker test

    
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $controller = new LoginController();
    if (isset($_POST['login'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        $result = $controller->handleLogin($username, $password);
        
       if ($result === true) {
            //echo "Login successful!";
            $_SESSION['username'] = $username;
            header("Location: " . WEB_ROOT . "user/bounties.php");
            exit;
        } else {
          error_log($result);
        }
        
    } elseif (isset($_POST['register'])) {
       $username = $_POST['username'];
       $password = $_POST['password'];
        
       $result = $controller->handleRegister($username, $password);
       if ($result === true) {
            //echo "Login successful!";
            $_SESSION['username'] = $username;
            header("Location: " . WEB_ROOT . "user/bounties.php");
            exit;
        } else {
            error_log($result);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" type="text/css" href="<?php echo WEB_ROOT; ?>../login.css?v=<?php echo filemtime(BASE_PATH . 'login.css'); ?>">
</head>
<body style="background-image: url(<?php echo WEB_ROOT; ?>assets/goober.jpg);">
    <?php include BASE_PATH . 'src/nav.php'; ?>
    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" style="margin-top: 10px;">
         <label for="username">Username</label><br>
         <input type="text" id="username" name="username" required><br>        
         <label for="password">Password</label><br>
         <input type="password" id="password" name="password" required>      
         <input type="submit" name="login" value="Login">
         <input type="submit" name="register" value="To register, fill out the form then click here!">
    </form>
</body>
<?php require_once BASE_PATH . 'src/footer.php'; echo generateFooter(); ?>
</html>

