<?php
// This file will handle AI configuration and actual integration
// with your preferred AI service provider
// tail -f /var/log/apache2/error.log to print log 
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';

// AI Provider Configuration
class AIConfig {
    // In ai_config.php, update the AIConfig class
    private static $config = [
        'provider' => 'openai',  // Change to openai
        'endpoint' => 'https://api.openai.com/v1/chat/completions',  // OpenAI API endpoint
        'model' => 'gpt-3.5-turbo',  // Or your preferred OpenAI model
        'stream' => false,  // Set to true if you want streaming responses
        'max_tokens' => 50,
        'temperature' => 0.7,
        'system_message' => "You're a friendly assistant in a chat app. Respond clearly and helpfully to user messages."
    ];
    
    // Get the current configuration
    public static function getConfig() {
        $config = self::$config;
        $config['api_key'] = $_ENV['OPENAI_API_KEY'];
        return $config;
    }
    
    // Update configuration
    public static function updateConfig($newConfig) {
        self::$config = array_merge(self::$config, $newConfig);
    }
}

// AI Service Integration
class AIService {
    private $config;
    
    public function __construct() {
        $this->config = AIConfig::getConfig();
    }
    
    public function generateResponse($conversation_history, $user_message, $receiver_user) {
        // Choose the appropriate provider
        switch($this->config['provider']) {
            case 'openai':
                return $this->callOpenAI($conversation_history, $user_message, $receiver_user);
            default:
                return $this->fallbackResponse($user_message);
        }
    }

    private function callOpenAI($conversation_history, $user_message, $receiver_user) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->config['api_key']
        ]);
        
        $messages = [
            ['role' => 'system', 'content' => $this->config['system_message']]
        ];
        
        // Add conversation history to messages
        if (!empty($conversation_history)) {
            $messages[] = ['role' => 'user', 'content' => "Here's my conversation history with $receiver_user:\n$conversation_history"];
        }
        
        // Add current user message
        $messages[] = ['role' => 'user', 'content' => "\"$user_message\""];
        
        $data = [
            'model' => $this->config['model'],
            'messages' => $messages,
            'max_tokens' => $this->config['max_tokens'],
            'temperature' => $this->config['temperature']
        ];

        // Log what you're sending
        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
        error_log("Sending to Completions: " . $jsonData);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);

        // Check for cURL error
        if (curl_errno($ch)) {
            error_log("OpenAI API cURL Error: " . curl_error($ch));
            curl_close($ch);
            return $this->fallbackResponse($user_message);
        } else {
            error_log("Raw API Response " . $response);
        }

        curl_close($ch);
        $result = json_decode($response, true);
        error_log("Decoded Response: " . print_r($result, true));

        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        } else {
            error_log("OpenAI API Error: Invalid response format");
            return $this->fallbackResponse($user_message);
        }
    }
    
    private function fallbackResponse($user_message) {
        // For testing or when API calls fail
        $responses = [
            "I'm responding on behalf of the user. Thanks for your message!",
            "The user has activated their AI assistant. I'll help with this conversation.",
            "Hello! I'm the user's AI assistant. How can I help you today?",
            "I'm handling this conversation as an automated assistant. I'll make sure the user sees your messages.",
        ];
        
        return $responses[array_rand($responses)];
    }
    }

// Update the generateAIResponse function in ai_agent.php to use this class
function generateAIResponse($conversation_history, $receiver_user, $user_message = '') {
    $aiService = new AIService();
    return $aiService->generateResponse($conversation_history, $user_message, $receiver_user);
}
?>