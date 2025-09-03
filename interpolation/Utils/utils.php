<?php
declare(strict_types=1);
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

class Utils {

    static $system_prompt = "You are a dream-to-video assistant. Analyze the user's dream description to determine distinct scenes, looking for transition words like 'then', 'next', 'after', 'and', etc. ";

    /**
     * Cleans up the output directory but preserves the directory itself
     * @param string $dir The directory to clean
     */
    static function clean_output_directory($dir) {
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
    // Function to call OpenAI chat
    static function call_openai_chat($messages, $api_key) {
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
    static function interpolate_images($input_dir, $output_dir, $fps = 24) {
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
    static function create_scene_with_audio($image_path, $voice_path, $music_path, $output_path, $scene_duration, $fps = 24) {
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

    static function log_image_count($scene_dir) {
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $count = 0;
        foreach ($image_extensions as $ext) {
            $files = glob($scene_dir . "/*.$ext");
            $count += count($files);
        }
        log_message("Found $count image(s) in $scene_dir");
    }

    // Function to combine multiple videos into one final video
    static function combine_videos($video_paths, $output_path) {
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

    // Function to generate TTS using voice name instead of ID
    static function generate_tts_by_name($text, $elevenlabs_api_key, $output_file, $voice_name) {
        log_message("Generating TTS using voice name: $voice_name");
        
        // First, get all available voices to find the ID
        $ch = curl_init('https://api.elevenlabs.io/v1/voices');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "xi-api-key: $elevenlabs_api_key"
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


    static function VerifyDirectories($output_dir, $interpolated_dir, $temp_dir){
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
    }

    static function ExtractFormInfo($form_data) : array {
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

        return $user_inputs;
    }
}
?>

