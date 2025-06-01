<?php
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
require_once BASE_PATH . 'loadenv.php';
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';

try {
    // Validate required fields FIRST
    $required_fields = [
        'dream_description', 
        'want_music', 
        'want_voice', 
        'specified_num_scenes'
    ];

    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Additional validation
    if ($_POST['want_voice'] === 'yes' && empty($_POST['voice_name'])) {
        throw new Exception("Voice name is required when voice is enabled");
    }

    if ($_POST['want_music'] === 'yes' && empty($_POST['audio_description'])) {
        throw new Exception("Audio description is required when music is enabled");
    }

    // Validate scene count
    $specified_num_scenes = intval($_POST['specified_num_scenes']);
    if ($specified_num_scenes < 1 || $specified_num_scenes > 8) {
        throw new Exception("Number of scenes must be between 1 and 8");
    }

    // All validation passed, now pass to background script
    $json_data = json_encode($_POST);
    $logFile = "/var/www/html/interpolation/dream2img.log";
    $cmd = "php " . __DIR__ . "/dream2img_actual.php " . escapeshellarg($json_data) . " >> " . $logFile . " 2>&1 &";
    exec($cmd);
    
    // Return success immediately
    echo json_encode([
        'status' => 'started',
        'message' => 'Dream generation started in background...'
    ]);
    
} catch (Exception $e) {
    // Return validation error immediately
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
    error_log("Issue in dream2img.php " . $e->getMessage());
}
?>