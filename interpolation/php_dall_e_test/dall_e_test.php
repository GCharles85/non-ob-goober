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

$api_key = getenv('OPENAI_API_KEY');

// Make sure output directory exists
$output_dir = __DIR__ . '/../output';
if (!is_dir($output_dir)) {
    mkdir($output_dir, 0777, true);
}

/**
 * Function to generate DALL-E images
 * @param array $prompts List of prompts to generate images for
 * @param string $api_key OpenAI API key
 * @param string $output_dir Directory to save images
 * @return array List of generated image paths
 */
function generate_dalle_images($prompts, $api_key, $output_dir) {
    $generated_files = [];
    
    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0777, true);
    }
    
    foreach ($prompts as $index => $prompt) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/images/generations');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Add this line to fix SSL issues
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ];
        $post_fields = json_encode([
            'model' => 'gpt-image-2',
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log('Curl error: ' . curl_error($ch));
            $success = false;
        } else {
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $success = ($http_status === 200);
        }
        
        curl_close($ch);
        
        if ($success) {
            $data = json_decode($response, true);
            $b64_image = $data['data'][0]['b64_json'] ?? null;

            if ($b64_image) {
                $image_data = base64_decode($b64_image);

                if ($image_data) {
                    $filename = "$output_dir/frame_" . ($index + 1) . ".png";
                    file_put_contents($filename, $image_data);
                    error_log("Saved Frame " . ($index + 1) . " to $filename");
                    $generated_files[] = $filename;
                    continue; // Skip to next prompt if successful
                } else {
                    error_log("No image data received for Frame " . ($index + 1));
                }
            } else {
                error_log("No image data returned for Frame " . ($index + 1));
            }
        } else {
            // Log both the frame number, the API response, and the prompt (sanitized for log safety)
            $sanitized_prompt = substr(preg_replace('/[\r\n]+/', ' ', $prompt), 0, 100) . (strlen($prompt) > 100 ? '...' : '');
            error_log("Failed to generate image for Frame " . ($index + 1) . 
                      ". Prompt: '" . $sanitized_prompt . "'" .
                      ". Response: $response");
        }
        
        // If we reach here, image generation failed - implement fallback logic
        
        // Try to use a previously successful frame as fallback
        if ($index > 0) {
            $prev_frame = "$output_dir/frame_" . $index . ".png";
            if (file_exists($prev_frame)) {
                $filename = "$output_dir/frame_" . ($index + 1) . ".png";
                copy($prev_frame, $filename);
                error_log("Created fallback Frame " . ($index + 1) . " by copying previous frame");
                $generated_files[] = $filename;
                continue;
            }
        }
        
        // If no previous frame, try to find the next successful frame
        for ($j = $index + 1; $j < count($prompts); $j++) {
            $next_check = "$output_dir/frame_" . ($j + 1) . ".png";
            
            // We need to temporarily generate the next frame if it doesn't exist yet
            if (!file_exists($next_check)) {
                // Try generating the next frame
                $next_ch = curl_init();
                curl_setopt($next_ch, CURLOPT_URL, 'https://api.openai.com/v1/images/generations');
                curl_setopt($next_ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($next_ch, CURLOPT_POST, 1);
                curl_setopt($next_ch, CURLOPT_SSL_VERIFYPEER, false);
                
                $next_post_fields = json_encode([
                    'model' => 'gpt-image-2',
                    'prompt' => $prompts[$j],
                    'n' => 1,
                    'size' => '1024x1024'
                ]);

                curl_setopt($next_ch, CURLOPT_POSTFIELDS, $next_post_fields);
                curl_setopt($next_ch, CURLOPT_HTTPHEADER, $headers);
                $next_response = curl_exec($next_ch);

                if (!curl_errno($next_ch) && curl_getinfo($next_ch, CURLINFO_HTTP_CODE) === 200) {
                    $next_data = json_decode($next_response, true);
                    $next_b64_image = $next_data['data'][0]['b64_json'] ?? null;

                    if ($next_b64_image) {
                        $next_image_data = base64_decode($next_b64_image);

                        if ($next_image_data) {
                            file_put_contents($next_check, $next_image_data);
                            error_log("Saved future Frame " . ($j + 1) . " to use as fallback");
                            break;
                        }
                    }
                }

                curl_close($next_ch);
            }
            
            // If we have a future frame now, use it as fallback
            if (file_exists($next_check)) {
                $filename = "$output_dir/frame_" . ($index + 1) . ".png";
                copy($next_check, $filename);
                error_log("Created fallback Frame " . ($index + 1) . " by copying future frame");
                $generated_files[] = $filename;
                break;
            }
        }
        
        // If still no frame, create a blank frame
        if (!in_array("$output_dir/frame_" . ($index + 1) . ".png", $generated_files)) {
            $filename = "$output_dir/frame_" . ($index + 1) . ".png";
            
            // Create a simple 1024x1024 black image as absolute fallback
            $img = imagecreatetruecolor(1024, 1024);
            imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
            imagepng($img, $filename);
            imagedestroy($img);
            
            error_log("Created blank Frame " . ($index + 1) . " as fallback");
            $generated_files[] = $filename;
        }
    }
    
    return $generated_files;
}

// Default prompts for testing
$prompts = [
    "A cartoon aardvark asking himself if people like cheese, in a fun and colorful style",
    "The cartoon aardvark looking surprised as he realizes people like cheese, in the same style",
    "The cartoon aardvark offering cheese to a non-descript stick figure, friendly vibe, cartoon style",
    "The stick figure happily accepting cheese from the cartoon aardvark, fun cartoon style"
];

// Run the function if this script is called directly
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    generate_dalle_images($prompts, $api_key, $output_dir);
}
?>