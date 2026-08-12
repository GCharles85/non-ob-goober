
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
 * Logs essential voice details along with the HTTP response code
 * @param int $httpCode The HTTP response code
 * @param array $voiceData The full voice data from API
 * @return void
 */
function log_voice_result($httpCode, $voiceData) {
    // Extract only the essential information
    $voiceName = $voiceData['name'] ?? 'unknown';
    $voiceId = $voiceData['voice_id'] ?? 'unknown';
    $gender = $voiceData['labels']['gender'] ?? 'unknown';
    $accent = $voiceData['labels']['accent'] ?? 'unknown';
    
    // Log the summary with HTTP code
    error_log("[ElevenLabs API] HTTP Code: $httpCode, Voice: $voiceName (ID: $voiceId), Gender: $gender, Accent: $accent");
    
    // Optionally log more verbose details for non-200 responses
    if ($httpCode != 200) {
        error_log("[ElevenLabs API] Error details: " . json_encode([
            'voice_id' => $voiceId,
            'error_message' => $voiceData['detail'] ?? 'No detail provided'
        ]));
    }
}

// Function to fetch voice information from ElevenLabs
function fetch_voice_info($elevenlabs_api_key) {
    // Step 1: Get available voices
    $ch = curl_init('https://api.elevenlabs.io/v1/voices');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "xi-api-key: $elevenlabs_api_key"
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    error_log("ElevenLabs API Fetch Voices Code: " . $http_code);
    error_log("ElevenLabs API Fetch Voices Response: " . $response);
    curl_close($ch);

    $data = json_decode($response, true);
    $voice_info = [];

    if (isset($data['voices']) && count($data['voices']) > 0) {
        foreach ($data['voices'] as $voice) {
            // Get detailed info for each voice
            $voice_id = $voice['voice_id'];
            $ch = curl_init("https://api.elevenlabs.io/v1/voices/$voice_id");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "xi-api-key: $elevenlabs_api_key"
                ],
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $voice_response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            error_log("ElevenLabs API Fetch Voice Details Code: " . $http_code);
            curl_close($ch);
            
            $voice_data = json_decode($voice_response, true);
            if ($voice_data) {
                // Use the compact logging function
                log_voice_result($http_code, $voice_data);
                // Extract rich voice information
                if (!isset($voice_data['description']) || empty($voice_data['description']) || $voice_data['description'] == "No description available") {
                    continue; // Skip this voice if it has no description
                }
                $description = $voice_data['description'];
                
                // Extract detailed labels
                $labels_info = "";
                if (isset($voice_data['labels'])) {
                    $labels_array = [];
                    foreach ($voice_data['labels'] as $key => $value) {
                        $labels_array[] = "$key: $value";
                    }
                    $labels_info = implode(", ", $labels_array);
                } else {
                    $labels_info = "";
                }
                
                // Get accent and gender info if available
                $accent = isset($voice_data['labels']['accent']) ? $voice_data['labels']['accent'] : "";
                $gender = isset($voice_data['labels']['gender']) ? $voice_data['labels']['gender'] : "";
                $age = isset($voice_data['labels']['age']) ? $voice_data['labels']['age'] : "";
                $use_case = isset($voice_data['labels']['use_case']) ? $voice_data['labels']['use_case'] : "";
                
                // Extract verified languages if available
                $languages = [];
                if (isset($voice_data['verified_languages'])) {
                    foreach ($voice_data['verified_languages'] as $lang) {
                        $languages[] = $lang['language'] . (isset($lang['accent']) ? " ({$lang['accent']})" : "");
                    }
                }
                $languages_info = !empty($languages) ? implode(", ", $languages) : "English";
                
                // Get preview URL if available
                $preview_url = isset($voice_data['preview_url']) ? $voice_data['preview_url'] : "";
                
                $voice_info[] = [
                    'id' => $voice_id,
                    'name' => $voice_data['name'] ?? $voice['name'],
                    'description' => $description,
                    'labels' => $labels_info,
                    'accent' => $accent,
                    'gender' => $gender,
                    'age' => $age,
                    'use_case' => $use_case,
                    'languages' => $languages_info,
                    'preview_url' => $preview_url
                ];
            }
        }
    } else {
        // Add default "W. Darth Oxley" voice as fallback
        error_log("Voice info was not fetched, only including Oxley");
        $voice_info[] = [
            'id' => "9BWtsMINqrJLrRacOk9x", // Default voice ID
            'name' => "W. Darth Oxley",
            'description' => "A deep, commanding voice with authoritative presence.",
            'labels' => "accent: American, gender: male, age: middle-aged, description: deep, use_case: narration",
            'accent' => "American",
            'gender' => "male",
            'age' => "middle-aged",
            'use_case' => "narration",
            'languages' => "English (American)",
            'preview_url' => ""
        ];
    }
    
    return $voice_info;
}

// API handling endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get API key from environment variable
    $elevenlabs_api_key = getenv('ELEVENLABS_API_KEY');
    
    // Fetch voice information using the provided API key
    $voice_info = fetch_voice_info($elevenlabs_api_key);
    
    // Check if we got valid voice information
    if (empty($voice_info)) {
        // Return error as JSON
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => "Could not retrieve voice information. Please check your API key and try again."
        ]);
        exit;
    } else {
        // Return voice info as JSON
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'voice_info' => $voice_info
        ]);
        exit;
    }
}
?>



