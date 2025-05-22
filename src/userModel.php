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

require_once BASE_PATH . 'src/connectToDB_Login.php';

class UserModel {
    public function login($username, $password) {
        global $conn;
        
        $query = "SELECT PasswordHash FROM Users WHERE username = ? AND role = 'user'" ; //
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['PasswordHash'])) {
                // Login successful
                return true;
            } else {
                return "Incorrect password.";
            }
        } else {
            return "User not found.";
        }
    }
    
    public function register($username, $password) {
        global $conn;
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO Users (Username, PasswordHash) VALUES (?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $username, $hashedPassword);
        if ($stmt->execute()) {
            echo "<div class='alert alert-warning'>Registration was successful!</div>";
            return "User created successfully!";
            if ($environment == 'production') {
                exec('/opt/update-db-dump.sh 2>&1', $output, $return_code);
                if ($return_code === 0) {
                    error_log("Backup completed successfully!");
                } else {
                    error_log("Backup failed. Return code: $return_code. Output: " . implode("\n", $output));
                } 
            }
        } else {
            echo "<div class='alert alert-warning'>Failed to create user.</div>";
            return "Failed to create user.";
        }
    }
}

?>
