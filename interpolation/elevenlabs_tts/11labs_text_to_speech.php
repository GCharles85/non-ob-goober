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

/**
 * Function to generate TTS for other scripts to use
 * @param string $text The text to convert to speech
 * @param string $api_key The ElevenLabs API key
 * @param string $output_path Where to save the audio file
 * @param string $voice_id Optional specific voice ID to use
 * @return bool Success or failure
 */
function generate_tts($text, $api_key, $output_path, $voice_id = null) {
    $elevenlabs_api_key = $_ENV['ELEVENLABS_API_KEY'];
    // Create output directory if it doesn't exist
    if (!is_dir(dirname($output_path))) {
        mkdir(dirname($output_path), 0777, true);
    }
    
    // If no voice ID is provided, try to find one
    if (!$voice_id) {
        // Step 1: Get available voices
        $ch = curl_init('https://api.elevenlabs.io/v1/voices');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "xi-api-key: $api_key"
            ],
            CURLOPT_SSL_VERIFYPEER => false  // Add SSL fix
        ]);
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log("cURL error when getting voices: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Error getting voices. HTTP Code: $httpCode, Response: $response");
            return false;
        }
        
        $data = json_decode($response, true);
        if (!isset($data['voices']) || count($data['voices']) === 0) {
            error_log("No voices found or API key invalid.");
            // Use a hardcoded fallback voice ID
            $voice_id = "9BWtsMINqrJLrRacOk9x"; // Aria voice ID
            error_log("Using fallback voice ID: $voice_id");
        } else {
            // Step 2: Pick the first voice (or filter by name)
            $voice = $data['voices'][0]; 
            $voice_id = $voice['voice_id'];
        }
    }
    
    // Log the voice being used
    error_log("Using voice ID: $voice_id");
    
    // Step 3: Generate speech using the selected voice
    $tts_url = "https://api.elevenlabs.io/v1/text-to-speech/$voice_id";
    $ch = curl_init($tts_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "xi-api-key: $api_key",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode([
            "text" => $text,
            "model_id" => "eleven_multilingual_v2",
            "voice_settings" => [
                "stability" => 0.5,
                "similarity_boost" => 0.75
            ]
        ]),
        CURLOPT_SSL_VERIFYPEER => false  // Add SSL fix
    ]);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log("cURL error when generating speech: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        // Save the audio file
        file_put_contents($output_path, $response);
        error_log("Audio saved to $output_path");
        return true;
    } else {
        error_log("Error generating speech. HTTP Code: $httpCode, Response: " . substr($response, 0, 500));
        return false;
    }
}
?>