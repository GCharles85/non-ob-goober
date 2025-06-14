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
require BASE_PATH . 'vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$s3Client = new S3Client([
    'region' => 'us-east-1',
    'version' => 'latest',
    'credentials' => [
        'key' => $_ENV['ACCESS_KEY'],
        'secret' => $_ENV['SECRET_ACCESS_KEY'],
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
    // API Keys
    $openai_api_key = $_ENV['OPENAI_API_KEY'];
    $elevenlabs_api_key = $_ENV['ELEVENLABS_API_KEY'];
    $stability_api_key = $_ENV['STABILITY_API_KEY'];

    $output_dir = __DIR__ . '/output';
    $interpolated_dir = __DIR__ . '/interpolated';
    $temp_dir = __DIR__ . '/temp';

    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0777, true);
        // Add this to verify directory exists and is writable
        if (!is_dir($output_dir) || !is_writable($output_dir)) {
            throw new Exception("Failed to create or write to output directory: $output_dir");
        }
    }

    if (!is_dir($interpolated_dir)) {
        mkdir($interpolated_dir, 0777, true);
    }

    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }

    // Process POST data from FormData
    // if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    //     throw new Exception("Invalid request method. POST expected.");
    // }

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

    // Extract user inputs from POST
    $user_inputs = [
        "dream_description" => $form_data['dream_description'],
        "want_music" => $form_data['want_music'],
        "want_voice" => $form_data['want_voice'],
        "audio_description" => $form_data['audio_description'] ?? '',
        "voice_description" => $form_data['voice_description'] ?? '',
        "voice_name" => $form_data['voice_name'] ?? '',
        "specified_num_scenes" => intval($form_data['specified_num_scenes']),
        "want_auto_split" => isset($form_data['want_auto_split']) && 
                           ($form_data['want_auto_split'] === 'true' || $form_data['want_auto_split'] === '1' || $form_data['want_auto_split'] === true)
    ];

    // Process JSON data for complex objects
    $user_inputs["voice_details"] = !empty($form_data['voice_details']) ? json_decode($form_data['voice_details'], true) : [];
    $user_inputs["voice_info"] = !empty($form_data['voice_info']) ? json_decode($form_data['voice_info'], true) : [];

    // Validate processed data
    if ($user_inputs["want_voice"] === "yes" && empty($user_inputs["voice_name"])) {
        throw new Exception("Voice name is required when voice is enabled");
    }

    if ($user_inputs["want_music"] === "yes" && empty($user_inputs["audio_description"])) {
        throw new Exception("Audio description is required when music is enabled");
    }

    // Function to generate TTS using voice name instead of ID
    function generate_tts_by_name($text, $api_key, $output_file, $voice_name) {
        log_message("Generating TTS using voice name: $voice_name");
        
        // First, get all available voices to find the ID
        $ch = curl_init('https://api.elevenlabs.io/v1/voices');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "xi-api-key: $api_key"
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        $voice_id = null;
        
        // Default to W. Darth Oxley if specified voice is not found
        $default_voice_id = "21m00Tcm4TlvDq8ikWAM";
        $default_voice_name = "W. Darth Oxley";
        
        if (isset($data['voices']) && count($data['voices']) > 0) {
            // Look for exact match first
            foreach ($data['voices'] as $voice) {
                if (strcasecmp($voice['name'], $voice_name) === 0) {
                    $voice_id = $voice['voice_id'];
                    $found_voice_name = $voice['name'];
                    log_message("Found exact match for voice: $found_voice_name (ID: $voice_id)");
                    break;
                }
            }
            
            // If no exact match, check for partial matches
            if (!$voice_id) {
                foreach ($data['voices'] as $voice) {
                    if (stripos($voice['name'], $voice_name) !== false) {
                        $voice_id = $voice['voice_id'];
                        $found_voice_name = $voice['name'];
                        log_message("Found partial match for voice: $found_voice_name (ID: $voice_id)");
                        break;
                    }
                }
            }
            
            // Check if we have a default voice in the available voices
            if (!$voice_id) {
                foreach ($data['voices'] as $voice) {
                    if (strcasecmp($voice['name'], $default_voice_name) === 0) {
                        $voice_id = $voice['voice_id'];
                        log_message("Using default voice: $default_voice_name (ID: $voice_id)");
                        break;
                    }
                }
            }
        }
        
        // If no voice ID was found, use the default
        if (!$voice_id) {
            $voice_id = $default_voice_id;
            log_message("Voice '$voice_name' not found, and default voice '$default_voice_name' not available. Using system default voice ID.");
        }
        
        // Now use the imported generate_tts function with the found ID
        return generate_tts($text, $elevenlabs_api_key, $output_file, $voice_id);
    }

    // Function to call OpenAI chat
    function call_openai_chat($messages, $api_key) {
        // Initialize cURL session
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        
        // Prepare the request data
        $request_data = [
            "model" => "gpt-4",
            "messages" => $messages,
            "temperature" => 0.8
        ];
        
        // Set cURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer $api_key"
            ],
            CURLOPT_POSTFIELDS => json_encode($request_data),
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        // Add debugging (only for prompts, not for conversation)
        if (count($messages) < 10) { // Only for non-conversation calls
            log_message("OpenAI Request Data: " . json_encode($request_data, JSON_PRETTY_PRINT));
        }
        
        // Execute the request
        $response = curl_exec($ch);
        
        // Get HTTP code and any cURL errors
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        curl_close($ch);
        
        // Debug the response (only for prompts, not for conversation)
        if (count($messages) < 10) { // Only for non-conversation calls
            log_message("OpenAI HTTP Code: $httpCode");
        }
        
        if (!empty($curl_error)) {
            log_message("OpenAI cURL Error: $curl_error");
            return '';
        }
        
        // Try to decode the JSON response
        $data = json_decode($response, true);
        
        // Check if JSON decode was successful
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_message("OpenAI JSON Decode Error: " . json_last_error_msg());
            log_message("Raw Response: $response");
            return '';
        }
        
        // Check for API errors
        if (isset($data['error'])) {
            log_message("OpenAI API Error: " . print_r($data['error'], true));
            return '';
        }
        
        // Check if the expected data structure exists
        if (!isset($data['choices'][0]['message']['content'])) {
            log_message("OpenAI Unexpected Response Structure: " . print_r($data, true));
            return '';
        }
        
        // Return the content
        return $data['choices'][0]['message']['content'];
    }

    // Function to interpolate images
    function interpolate_images($input_dir, $output_dir, $fps = 24) {
        log_message("Interpolating images with FFmpeg...");
        
        // Make sure output directory exists
        if (!is_dir($output_dir)) {
            mkdir($output_dir, 0777, true);
        }
        
        // Create an intermediate video from the frames
        $input_pattern = $input_dir . '/frame_%d.png';
        $intermediate_video = $output_dir . '/intermediate.mp4';
        $cmd = "ffmpeg -nostdin -y -framerate 1 -i \"$input_pattern\" -c:v libx264 -r $fps -pix_fmt yuv420p \"$intermediate_video\"";
        $cmd .= ' > /dev/null 2>&1';
        exec($cmd, $output, $return_var);
        
        if ($return_var !== 0) {
            log_message("Error creating intermediate video:\n" . implode("\n", $output));
            return false;
        }
        
        // Extract frames from the intermediate video
        $output_pattern = $output_dir . '/interp_frame_%04d.png';
        $cmd = "ffmpeg -nostdin -y -i \"$intermediate_video\" -vf \"fps=$fps\" \"$output_pattern\"";
        $cmd .= ' > /dev/null 2>&1';
        exec($cmd, $output, $return_var);
        
        if ($return_var !== 0) {
            log_message("Error extracting interpolated frames:\n" . implode("\n", $output));
            return false;
        }
        
        log_message("Interpolation complete. Output in $output_dir");
        return true;
    }

    // Function to create a scene with audio fading
    function create_scene_with_audio($image_path, $voice_path, $music_path, $output_path, $scene_duration, $fps = 24) {
        log_message("Creating scene with audio and fade effects...");
        
        // Create silent padding before and after voice
        $silence_before = 0.5; // Half a second before voice starts
        $voice_duration = 0; // Will be determined by ffprobe
        
        // Get voice duration if voice file exists
        if (file_exists($voice_path)) {
            $cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$voice_path\"";
            exec($cmd, $probe_output);
            if (!empty($probe_output)) {
                $voice_duration = floatval($probe_output[0]);
            }
        }
        
        // Calculate total duration needed
        $total_duration = max($scene_duration, $voice_duration + $silence_before + 1);
        
        // Create a temporary extended image video
        $temp_video = dirname($output_path) . '/temp_' . basename($output_path);
        $cmd = "ffmpeg -nostdin -y -loop 1 -i \"$image_path\" -c:v libx264 -t $total_duration -pix_fmt yuv420p \"$temp_video\"";
        $cmd .= ' > /dev/null 2>&1';
        exec($cmd);
        
        // Create audio mix with fades
        $temp_audio = dirname($output_path) . '/temp_audio_' . basename($output_path) . '.wav';
        
        if (file_exists($voice_path) && file_exists($music_path)) {
            // Mix music and voice with fades
            $fade_in_duration = 1.0;
            $fade_out_duration = 1.0;
            
            // Complex filter for audio mixing with fades
            $filter = "[0:a]afade=t=in:st=0:d=$fade_in_duration,afade=t=out:st=" . ($total_duration-$fade_out_duration) . ":d=$fade_out_duration,volume=0.15[music];";
            $filter .= "[1:a]adelay=" . ($silence_before*1000) . "|" . ($silence_before*1000) . "[voice];";
            $filter .= "[music][voice]amix=inputs=2:duration=longest";
            
            $cmd = "ffmpeg -nostdin -y -i \"$music_path\" -i \"$voice_path\" -filter_complex \"$filter\" \"$temp_audio\"";
            $cmd .= ' > /dev/null 2>&1';
            exec($cmd);
        } elseif (file_exists($music_path)) {
            // Just use music with fades
            $fade_in_duration = 1.0;
            $fade_out_duration = 1.0;
            
            $cmd = "ffmpeg -nostdin -y -i \"$music_path\" -af \"afade=t=in:st=0:d=$fade_in_duration,afade=t=out:st=" . ($total_duration-$fade_out_duration) . ":d=$fade_out_duration,volume=0.25\" \"$temp_audio\"";
            $cmd .= ' > /dev/null 2>&1';
            exec($cmd);
        } elseif (file_exists($voice_path)) {
            // Just use voice with delay
            $cmd = "ffmpeg -nostdin -y -i \"$voice_path\" -af \"adelay=" . ($silence_before*1000) . "|" . ($silence_before*1000) . "\" \"$temp_audio\"";
            $cmd .= ' > /dev/null 2>&1';
            exec($cmd);
        }
        
        // Combine video and audio
        if (file_exists($temp_audio)) {
            $cmd = "ffmpeg -nostdin -y -i \"$temp_video\" -i \"$temp_audio\" -c:v copy -c:a aac -shortest \"$output_path\"";
            $cmd .= ' > /dev/null 2>&1';
            exec($cmd);
            // Clean up temp files
            @unlink($temp_video);
            @unlink($temp_audio);
        } else {
            // If no audio, just rename the temp video
            rename($temp_video, $output_path);
        }
            
        log_message("Scene created at $output_path");
        return true;
    }

    // Function to combine multiple videos into one final video
    function combine_videos($video_paths, $output_path) {
        log_message("Combining all scenes into final video...");
        
        if (empty($video_paths)) {
            log_message("No video paths provided to combine.");
            return false;
        }
        
        // Create a text file listing all videos to concatenate
        $list_file = dirname($output_path) . '/video_list.txt';
        $file_content = '';
        foreach ($video_paths as $path) {
            if (file_exists($path)) {
                $file_content .= "file '" . $path . "'\n";
            }
        }
        
        file_put_contents($list_file, $file_content);
        
        // Use FFmpeg's concat demuxer to combine videos
        $cmd = "ffmpeg -nostdin -y -f concat -safe 0 -i \"$list_file\" -c copy \"$output_path\"";
        $cmd .= ' 2>&1';
        exec($cmd, $output, $return_var);
        
        // Clean up the list file
        @unlink($list_file);
        
        if ($return_var !== 0) {
            log_message("Error combining videos. Return code: $return_var\nOutput:\n" . implode("\n", $output));            return false;
        }
        
        log_message("Final video created at $output_path");
        return true;
    }

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
    $system_prompt = "You are a dream-to-video assistant. Analyze the user's dream description to determine distinct scenes, looking for transition words like 'then', 'next', 'after', 'and', etc. ";

    // If user specified number of scenes, use that
    if ($user_inputs["specified_num_scenes"] > 0) {
        $num_scenes = $user_inputs["specified_num_scenes"];
        $system_prompt .= "Create exactly $num_scenes scenes as specified by the user. ";
    } else {
        $system_prompt .= "Count the number of scenes (minimum 2, maximum 8) based on narrative transitions in the dream description. ";
    }

    $system_prompt .= "Output in EXACTLY this format:

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
        $system_prompt .= "Music Description: [detailed prompt for background music that matches the overall dream mood, including tempo, instruments, emotional tone] based on the user's audio preference: \"$audio_description\"

    ";
    }

    if ($want_voice == "yes") {
        $system_prompt .= "Voice Info: $voice_info_text\n\n";
        
        if ($user_inputs["want_auto_split"]) {
            $system_prompt .= "Split the user's narration: \"{$voice_description}\" across all scenes naturally, matching the content of each scene. Use the entire narration, divided logically.\n\n";
        } else {
            $system_prompt .= "Use the exact narration provided by the user for each scene: \"{$voice_description}\"\n\n";
        }
        
        $system_prompt .= "Adapt the narration to match the voice characteristics. ";
        
        if (!empty($voice_details['gender'])) {
            if (strtolower($voice_details['gender']) == 'male') {
                $system_prompt .= "Use language appropriate for a male voice. ";
            } else if (strtolower($voice_details['gender']) == 'female') {
                $system_prompt .= "Use language appropriate for a female voice. ";
            }
        }
        
        if (!empty($voice_details['accent'])) {
            $system_prompt .= "Consider the {$voice_details['accent']} accent when writing narration. ";
        }
        
        if (!empty($voice_details['use_case'])) {
            $system_prompt .= "The voice is best suited for {$voice_details['use_case']} content. ";
        }
    }

    $messages = [
        ["role" => "system", "content" => $system_prompt],
        ["role" => "user", "content" => "Generate creative scene breakdowns for this dream: $dream_prompt" . 
            ($want_music == "yes" ? " Include these audio details: $audio_description" : "")],
        ["role" => "assistant", "content" => "I'll analyze your dream and create scene breakdowns."],
        ["role" => "user", "content" => "Please generate the scene breakdown now."]
    ];

    log_message("Analyzing dream and creating scene breakdown...");
    $output = call_openai_chat($messages, $openai_api_key);
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
                generate_tts_by_name($scene['narration'], $elevenlabs_api_key, $voice_path, $voice_name);
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
        create_scene_with_audio($image_path, $voice_path, $music_path, $scene_video_path, $scene['duration']);
        
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
        $success = combine_videos($scene_videos, $final_video_path);
        
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
                clean_output_directory($output_dir);
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
            clean_output_directory($output_dir);
            
            log_message("Video generation failed. Cleaned up temporary files.", 'error');
        }
    } else {
        $status = "error";
        $message = "No scenes were processed successfully";
        
        // Clean up any artifacts
        clean_output_directory($output_dir);
        
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

/**
 * Cleans up the output directory but preserves the directory itself
 * @param string $dir The directory to clean
 */
function clean_output_directory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    // Recursively delete all files and subdirectories
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getRealPath());
        } else {
            unlink($item->getRealPath());
        }
    }
    
    log_message("Cleaned output directory: $dir");
}
?>