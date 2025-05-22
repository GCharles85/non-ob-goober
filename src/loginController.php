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

require_once BASE_PATH . 'src/userModel.php';

class LoginController {
    private $userModel;
    
    public function __construct() {
        $this->userModel = new UserModel();
    }
    
    public function handleLogin($username, $password) {
        return $this->userModel->login($username, $password);
    }
    
    public function handleRegister($username, $password) {
        return $this->userModel->register($username, $password);
    }
}

?>
