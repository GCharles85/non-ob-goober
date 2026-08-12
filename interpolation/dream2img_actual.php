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

// Set header for JSON response
header('Content-Type: application/json');

// Include required modules
require_once __DIR__ . '/elevenlabs_tts/11labs_text_to_speech.php';
require_once __DIR__ . '/stability_text_to_music/stability_voice_text_to_music.php';
require_once __DIR__ . '/php_dall_e_test/dall_e_test.php';
require_once __DIR__ . '/Utils/utils.php';
require BASE_PATH . 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$s3Client = new S3Client([
    'region' => 'us-east-1',
    'version' => 'latest',
    'credentials' => [
        'key' => getenv('ACCESS_KEY'),
        'secret' => getenv('SECRET_ACCESS_KEY'),
    ],
]);

// Create a log function to replace all echoes
function log_message($message) {
    error_log("[Dream Generator] " . $message);
}

try {

    $ffmpeg_available = shell_exec('which ffmpeg');
    if (empty($ffmpeg_available)) {
        throw new Exception("FFmpeg is not installed or not in the PATH. Please install FFmpeg.");
    }

    $openai_api_key = getenv('OPENAI_API_KEY');
    $elevenlabs_api_key = getenv('ELEVENLABS_API_KEY');
    $stability_api_key = getenv('STABILITY_API_KEY');

    $output_dir = __DIR__ . '/output';
    $interpolated_dir = __DIR__ . '/interpolated';
    $temp_dir = __DIR__ . '/temp';

    Utils::VerifyDirectories($output_dir, $interpolated_dir, $temp_dir);

    // Get the JSON data from command line argument
    $json_data = $argv[1] ?? null;
    if (!$json_data) {
        log_message("No data provided to background script");
        exit("No data provided");
    }

    // Decode the form data
    $form_data = json_decode($json_data, true);
    if (!$form_data) {
        log_message("Invalid JSON data provided");
        exit("Invalid data format");
    }

    // Validate required fields
    $required_fields = [
        'dream_description', 
        'want_music', 
        'want_voice', 
        'specified_num_scenes'
    ];

    foreach ($required_fields as $field) {
        if (!isset($form_data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    $user_input = Utils::ExtractFormInfo($form_data);

    // Clear previous output files to avoid confusion
    array_map('unlink', glob($output_dir . '/*.mp4'));
    array_map('unlink', glob($output_dir . '/*.wav'));
    array_map('unlink', glob($output_dir . '/*.png'));
    array_map('unlink', glob($interpolated_dir . '/*.png'));
    array_map('unlink', glob($interpolated_dir . '/*.mp4'));
    
    // Extract user inputs
    $dream_prompt = $user_inputs["dream_description"];
    $want_music = $user_inputs["want_music"];
    $want_voice = $user_inputs["want_voice"];
    $audio_description = $user_inputs["audio_description"];
    $voice_description = $user_inputs["voice_description"];
    $voice_name = $user_inputs["voice_name"];
    $voice_info = $user_inputs["voice_info"];
    $voice_details = $user_inputs["voice_details"];
    
    // Prepare voice info for system prompt
    $voice_info_text = "";
    if ($want_voice == "yes" && !empty($voice_name)) {
        $voice_info_text = "Selected Voice: {$voice_name}";
        
        if (!empty($voice_details)) {
            if (!empty($voice_details['description']) && $voice_details['description'] !== "No description available") {
                $voice_info_text .= ", Description: {$voice_details['description']}";
            }
            if (!empty($voice_details['labels']) && $voice_details['labels'] !== "No labels available") {
                $voice_info_text .= ", Labels: {$voice_details['labels']}";
            }
        }
    }

    // Build system prompt with scene analysis instructions
    // If user specified number of scenes, use that
    if ($user_inputs["specified_num_scenes"] > 0) {
        $num_scenes = $user_inputs["specified_num_scenes"];
        Utils::$system_prompt .= "For each scene, specified by $num_scenes, give me 30 frames. ";
    } else {
        Utils::$system_prompt .= "Count the number of scenes (minimum 2, maximum 8) based on narrative transitions in the dream description. Give me 30 frames for each scene.";
    }

    Utils::$system_prompt .= "Output in EXACTLY this format:

    Number of Scenes: [2-8]

    Scene 1:
    DALL-E: [detailed prompt for scene 1, focusing on visual elements of the dream. Include artistic style, lighting, mood, and specific details]
    Narration: [2-3 sentence narration for this scene, estimated 5-10 seconds when spoken]
    Duration: [estimated seconds needed for this scene, considering narration length]

    Scene 2: 
    DALL-E: [detailed prompt for scene 2, focusing on visual elements of the dream. Include artistic style, lighting, mood, and specific details]
    Narration: [2-3 sentence narration for this scene, estimated 5-10 seconds when spoken]
    Duration: [estimated seconds needed for this scene, considering narration length]

    [Continue for all scenes...]

    ";

    if ($want_music == "yes") {
        Utils::$system_prompt .= "Music Description: [detailed prompt for background music that matches the overall dream mood, including tempo, instruments, emotional tone] based on the user's audio preference: \"$audio_description\"

    ";
    }

    if ($want_voice == "yes") {
        Utils::$system_prompt .= "Voice Info: $voice_info_text\n\n";
        
        if ($user_inputs["want_auto_split"]) {
            Utils::$system_prompt .= "Split the user's narration: \"{$voice_description}\" across all scenes naturally, matching the content of each scene. Use the entire narration, divided logically.\n\n";
        } else {
            Utils::$system_prompt .= "Use the exact narration provided by the user for each scene: \"{$voice_description}\"\n\n";
        }
        
        Utils::$system_prompt .= "Adapt the narration to match the voice characteristics. ";
        
        if (!empty($voice_details['gender'])) {
            if (strtolower($voice_details['gender']) == 'male') {
                Utils::$system_prompt .= "Use language appropriate for a male voice. ";
            } else if (strtolower($voice_details['gender']) == 'female') {
                Utils::$system_prompt .= "Use language appropriate for a female voice. ";
            }
        }
        
        if (!empty($voice_details['accent'])) {
            Utils::$system_prompt .= "Consider the {$voice_details['accent']} accent when writing narration. ";
        }
        
        if (!empty($voice_details['use_case'])) {
            Utils::$system_prompt .= "The voice is best suited for {$voice_details['use_case']} content. ";
        }
    }

    $messages = [
        ["role" => "system", "content" => Utils::$system_prompt],
        ["role" => "user", "content" => "Generate creative scene breakdowns for this dream: $dream_prompt" . 
            ($want_music == "yes" ? " Include these audio details: $audio_description" : "")],
        ["role" => "assistant", "content" => "I'll analyze your dream and create scene breakdowns."],
        ["role" => "user", "content" => "Please generate the scene breakdown now."]
    ];

    log_message("Analyzing dream and creating scene breakdown...");
    $output = Utils::call_openai_chat($messages, $openai_api_key);
    log_message("GPT Response received");
    log_message("GPT Response: $output");
    // Parse the number of scenes
    $num_scenes = 2; // Default minimum
    if (preg_match('/Number of Scenes:\s*(\d+)/i', $output, $matches)) {
        $num_scenes = intval($matches[1]);
        // Enforce limits
        $num_scenes = max(2, min(8, $num_scenes));
    }

    log_message("Detected $num_scenes scenes");

    // Parse scene information
    $scenes = [];
    $scene_pattern = '/Scene\s+(\d+):\s*\n+DALL-E:\s*([^\n]+)\s*\n+Narration:\s*([^\n]+(?:\n[^\n]+)*?)(?:\s*\n+Duration:\s*([^\n]+)|$)/i';

    preg_match_all($scene_pattern, $output, $matches, PREG_SET_ORDER);
    log_message("Parsed scenes: " . count($matches));

    foreach ($matches as $match) {
        $scene_num = $match[1];
        $dalle_prompt = trim($match[2]);
        $narration = trim($match[3]);
        $duration = isset($match[4]) ? intval($match[4]) : 10; // Default 10 seconds if not specified
        
        $scenes[] = [
            'number' => $scene_num,
            'prompt' => $dalle_prompt,
            'narration' => $narration,
            'duration' => $duration
        ];
    }

    // Parse music description if present
    $music_description = $audio_description; // Default to user input
    if ($want_music == "yes" && preg_match('/Music Description:\s*([^\n]+(?:\n[^\n]+)*)/i', $output, $music_match)) {
        $music_description = trim($music_match[1]);
    }

    log_message("Parsed scenes and music info");

    // Generate and process each scene
    $scene_videos = [];
    $scene_data = []; // Store scene info for JSON response
    log_message("Starting to process " . count($scenes) . " scenes");
    for ($i = 0; $i < count($scenes); $i++) {
        $scene = $scenes[$i];
        log_message("Processing Scene " . ($i + 1));
        
        // Scene-specific directories
        $scene_dir = $output_dir . '/scene_' . ($i + 1);
        if (!is_dir($scene_dir)) {
            mkdir($scene_dir, 0777, true);
        }
        
        // Generate single image for this scene
        log_message("Generating DALL-E image for scene " . ($i + 1));
        $prompts = [$scene['prompt']];
        $image_paths = generate_dalle_images($prompts, $openai_api_key, $scene_dir);
        
        if (empty($image_paths)) {
            log_message("Failed to generate image for scene " . ($i + 1) . ". Skipping...");
            continue;
        }
        Utils::log_image_count($scene_dir);

        $image_path = $image_paths[0];
        
        // Generate voice narration for this scene if requested
        $voice_path = '';
        if ($want_voice == "yes" && !empty($scene['narration'])) {
            $voice_path = $scene_dir . '/voice.wav';
            log_message("Generating narration for scene " . ($i + 1));
            
            // Use the user's selected voice name and ID
            if (!empty($voice_details['id'])) {
                // If we have the ID, use it
                generate_tts($scene['narration'], $elevenlabs_api_key, $voice_path, $voice_details['id']);
            } else {
                // Otherwise use the voice name to generate TTS
                Utils::generate_tts_by_name($scene['narration'], $elevenlabs_api_key, $voice_path, $voice_name);
            }
        }
        
        // Generate unique music for each scene if requested
        $music_path = '';
        if ($want_music == "yes") {
            $music_path = $scene_dir . '/music.wav';
            log_message("Generating music for scene " . ($i + 1));
            generate_music($music_description, $scene['duration'] * 1.5, $stability_api_key); // Make music longer than scene
            
            // Copy the generated music file to scene directory
            copy(__DIR__ . "/output/music.wav", $music_path);
        }
        
        // Create scene video with audio
        $scene_video_path = $scene_dir . '/scene_video.mp4';
        Utils::create_scene_with_audio($image_path, $voice_path, $music_path, $scene_video_path, $scene['duration']);
        
        $scene_videos[] = $scene_video_path;
        
        // Store scene data for response
        $scene_data[] = [
            'number' => $i + 1,
            'prompt' => $scene['prompt'],
            'narration' => $scene['narration'],
            'duration' => $scene['duration'],
            'image_path' => str_replace($_SERVER['DOCUMENT_ROOT'], '', $image_path), // Convert to relative URL
            'video_path' => str_replace($_SERVER['DOCUMENT_ROOT'], '', $scene_video_path) // Convert to relative URL
        ];
    }

    // Combine all scene videos into the final video
    $final_video_path = '';
    
    // After the success check in your existing code:
    if (!empty($scene_videos)) {
        $final_video_path = $output_dir . '/final_dream_video_' . time() . '.mp4';
        $success = Utils::combine_videos($scene_videos, $final_video_path);
        
        if ($success) {
            $status = "success";
            $message = "Dream video generated successfully";
            
            // Create a more user-friendly filename
            $clean_filename = 'dream_video_' . date('Ymd_His') . '.mp4';
            
            // Define destination path in the uploads folder
            $dest_dir = BASE_PATH . 'uploads';
            
            // Make sure the destination directory exists
            if (!is_dir($dest_dir)) {
                mkdir($dest_dir, 0755, true);
            }
            
            $dest_path = $dest_dir . '/' . $clean_filename;
            // Increase volume of final video by 25%
            $volume_cmd = "ffmpeg -i \"$final_video_path\" -filter:a \"volume=1.25,alimiter\" \"$final_video_path.temp\"";            exec($volume_cmd);
            rename($final_video_path . '.temp', $final_video_path);

            // Upload file
            try {
                if($environment == 'production'){
                    $result = $s3Client->putObject([
                        'Bucket' => 'gooberbucketgc6788',
                        'Key' => 'uploads/' . $clean_filename,
                        'SourceFile' => $final_video_path,
                        'ACL' => 'private',
                    ]);
                }else{
                    $result = $s3Client->putObject([
                        'Bucket' => 'gooberbucketgc6788test',
                        'Key' => 'uploads/' . $clean_filename,
                        'SourceFile' => $final_video_path,
                        'ACL' => 'private',
                    ]);
                }
    
                $final_video_url = $result['ObjectURL'];
                 // Log success
                 log_message("Video successfully moved to uploads bucket: " . $result['ObjectURL']);
                // Clean up the output directory since we've successfully copied the file
                Utils::clean_output_directory($output_dir);
            } catch (AwsException $e) {
                log_message("Error uploading video to S3: " . $e->getMessage(), 'error');
                // If move failed, keep the original path but log the error
                $final_video_url = str_replace($_SERVER['DOCUMENT_ROOT'], '', $final_video_path);
                log_message("Failed to move video to uploads folder. Keeping in original location.", 'warning');
            }
            
        } else {
            $status = "error";
            $message = "Failed to combine scene videos";
            
            // Clean up any artifacts since the process failed
            Utils::clean_output_directory($output_dir);
            
            log_message("Video generation failed. Cleaned up temporary files.", 'error');
        }
    } else {
        $status = "error";
        $message = "No scenes were processed successfully";
        
        // Clean up any artifacts
        Utils::clean_output_directory($output_dir);
        
        log_message("No scenes processed. Cleaned up temporary files.", 'error');
    }

    // Return JSON response
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'num_scenes' => count($scene_videos),
        'scenes' => $scene_data,
        'final_video' => $final_video_url ?? '',
        'processing_time' => time() - $_SERVER['REQUEST_TIME']
    ]);

} catch (Exception $e) {
    // Log the error
    error_log("[Dream Generator Error] " . $e->getMessage());
    
    // Return error response
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>