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

// Set header to allow JSON response
header('Content-Type: application/json');

// Get the POST data
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// Check if we have the required data
if (!isset($data['voice_info']) || !isset($data['voice_name'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameters'
    ]);
    exit;
}

$voice_info = $data['voice_info'];
$voice_name = $data['voice_name'];

// Function to find voice ID by name
function find_voice_by_name($voice_info, $voice_name) {
    $default_voice = null;
    $default_voice_name = "W. Darth Oxley";
    
    // First, look for the default voice to use if no match is found
    foreach ($voice_info as $voice) {
        if ($voice['name'] === $default_voice_name) {
            $default_voice = $voice;
            break;
        }
    }
    
    // If we couldn't find W. Darth Oxley as a preset, use the first voice as fallback
    if (!$default_voice && !empty($voice_info)) {
        $default_voice = $voice_info[0];
    }
    
    // If no voices available at all, create a placeholder default
    if (!$default_voice) {
        $default_voice = [
            'id' => "9BWtsMINqrJLrRacOk9x", // Default ID for W. Darth Oxley
            'name' => $default_voice_name,
            'description' => "A deep, commanding voice with authoritative presence.",
            'labels' => "accent: American, gender: male, age: middle-aged, description: deep, use_case: narration",
            'accent' => "American",
            'gender' => "male",
            'age' => "middle-aged",
            'use_case' => "narration",
            'languages' => "English (American)"
        ];
    }
    
    // Look for exact match first
    foreach ($voice_info as $voice) {
        if (strcasecmp($voice['name'], $voice_name) === 0) {
            return $voice;
        }
    }
    
    // Look for partial match if no exact match
    foreach ($voice_info as $voice) {
        if (stripos($voice['name'], $voice_name) !== false) {
            return $voice;
        }
    }
    
    // Return default voice if no match found
    return $default_voice;
}

// Call the function
$result = find_voice_by_name($voice_info, $voice_name);

// Return the result as JSON
echo json_encode([
    'success' => true,
    'voice' => $result,
    'message' => ($result['name'] !== $voice_name && stripos($result['name'], $voice_name) === false) 
        ? "Voice '$voice_name' not found. Using default voice: {$result['name']}" 
        : "Found voice: {$result['name']}"
]);
?>