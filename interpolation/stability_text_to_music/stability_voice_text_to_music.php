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
$api_key = $_ENV['STABILITY_API_KEY'];
$rep_token = $_ENV['REPLICATE_API_KEY'];

/**
 * Function to generate music from text prompt
 * @param string $prompt The text prompt for music generation
 * @param int $duration Duration in seconds
 * @param string $api_key The Stability API key
 * @param string $output_path Where to save the audio file
 * @return bool Success or failure
 */
function generate_music($prompt, $duration, $api_key) {
    error_log("Generating music with prompt: $prompt");
    $output_path = __DIR__ . '/../output/music.wav';
    // Create output directory if it doesn't exist
    if (!is_dir(dirname($output_path))) {
        mkdir(dirname($output_path), 0777, true);
    }
    
    $ch = curl_init('https://api.stability.ai/v2beta/audio/stable-audio-2/text-to-audio');
    
    // Use form data (multipart/form-data) instead of JSON
    $postFields = [
        'prompt' => $prompt,
        'duration' => $duration,
        'output_format' => 'wav',
        'steps' => '30' // Adding steps parameter from the doc example
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Accept: audio/*' // Changed from Content-Type to Accept
        ],
        CURLOPT_POSTFIELDS => $postFields, // This will be sent as multipart/form-data
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log("Curl error: " . curl_error($ch));
    }
    
    curl_close($ch);
    
    if ($httpCode === 200) {
        // The response should be the audio data directly
        error_log("Saving audio to: $output_path");
        file_put_contents($output_path, $response);
        error_log("Saved to $output_path");
        return true;
    } else {
        error_log("Error: HTTP Code $httpCode");
        if (strlen($response) < 1000) { // Only print the response if it's not too large
            error_log("Response: $response");
        }
        return false;
    }
}

// Run the function if this script is called directly
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    generate_music($prompt, $duration, $api_key);
}
?>