<?php
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: " . WEB_ROOT . "user/login.php");
    exit();
}


// Session configuration
session_set_cookie_params([
    'lifetime' => 86400,        // 24 hours
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,           // HTTPS only
    'httponly' => true,         // No JS access
    'samesite' => 'Strict'
]);

if (!isset($_SESSION['session_start'])) {
    $_SESSION['session_start'] = time();
} elseif (time() - $_SESSION['session_start'] > 86400) {
    // Max 24-hour session duration
    session_unset();
    session_destroy();
    header("Location: " . WEB_ROOT . "user/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="<?php echo WEB_ROOT . 'style.css?v=' . filemtime(BASE_PATH . 'style.css'); ?>">
    <script src="<?php echo WEB_ROOT; ?>js-config.php"></script>
    <title>Upload Item</title>  

    <style>
        #ai-chat-container {
            position: relative;  
            width: 95vw;          
            height: 75vh; 
            top: 1%;
            left: 1.5%;
            overflow: auto;       /* Adds scrollbars if content exceeds dimensions */
            background-color: white;
            border: 1px solid #ccc;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
        }

        #chat-header {
            background-color: #f0f0f0;
            padding: 10px;
            border-bottom: 1px solid #ccc;
        }

        #message-area {
            flex-grow: 1;
            overflow-y: scroll;
            padding: 10px;
        }

        #input-area button {
            font-size: 20px;
            position: sticky;
            bottom: 1%;
            width: 80px;
            height: 50px;
            margin-left: 25px;
        }
        
        #input-area {
            position: sticky;
            bottom: 0;
            width: 95%;
            padding: 10px;
            border-top: 1px solid #ccc;
            display: flex;
        }

        #input-area textarea {
            position: sticky;
            bottom: 0;
            width: 95%;
            padding: 10px;
            border-top: 1px solid #ccc;
            display: flex;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="background-image: url(<?php echo WEB_ROOT . 'assets/goober.jpg'; ?>);">
    <?php include BASE_PATH . 'src/nav.php'; ?>
    <div id="ai-chat-container">
        <div id="chat-header">
            AI Chatbot - Feel free to ask about earlier conversations
        </div>
        <div id="message-area">
        </div>
        <div id="input-area">
            <textarea id="user-input" style="font-size: 20px;"></textarea>
            <button id="send-button">Send</button>
        </div>
        <button id="stop-button" style="display: none; font-size: 14px; background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
            🛑 Stop Generation
        </button>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {  
            function stopProcess() {
                // Run cleanup first
                localStorage.removeItem("videoCheckTimer");
                let id = window.setTimeout(() => {}, 0);
                while (id--) clearTimeout(id);
                if (confirm('Are you sure you want to stop the video generation?')) {
                    fetch(CONFIG.API_BASE +'stop_process.php', {
                        method: 'POST'
                    })
                    .then(response => response.text())
                    .then(result => {
                        //console.log(result);
                        if(result == 15 || result == 0){
                            displayMessage("Generation stopped successfully", "Chat");
                        }else{
                            displayMessage("Failed to stop generation", "Chat");
                        }
                    })
                    .catch(error => {
                        //console.log('Error stopping process: ' + error);
                    });
                }
            }

            document.getElementById('stop-button').addEventListener('click', stopProcess);

             // Chat functionality
            const chatContainer = document.getElementById('ai-chat-container');
            const messageArea = document.getElementById('message-area');
            const userInput = document.getElementById('user-input');
            const sendButton = document.getElementById('send-button');

            function get_user_input() {
                return new Promise((resolve) => {        
                    // Function to handle the input submission
                    function handleSubmit() { 
                        const userText =  userInput.value.trim();
                        if (userText) {
                            // Clear the input field
                            userInput.value = '';
                            
                            // Remove the event listeners
                            sendButton.removeEventListener('click', handleSubmit);
                            userInput.removeEventListener('keydown', handleKeydown);
                            
                            // Resolve the promise with the user's input
                            resolve(userText);
                         }
                    }
                    
                    // Function to handle Enter key
                    function handleKeydown(event) {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            //event.preventDefault(); // Prevent default form submission
                            handleSubmit();
                        }
                    }
    
                    // Add event listeners
                    sendButton.addEventListener('click', handleSubmit);
                    userInput.addEventListener('keydown', handleKeydown);
                    
                    // Focus the input element
                    userInput.focus();
                });
            }
            
            async function start_conversation() {
                // Fetch voice information first
                // Fetch voice information using AJAX
                // Attempt to use previously cached data if available
                let voice_info = null;
                const cachedData = localStorage.getItem('cached_voice_info');
                if (cachedData) {
                    //console.log('Using previously cached voice information');
                    voice_info = JSON.parse(cachedData);
                }else{
                    try {
                        const response = await fetch('<?php echo WEB_ROOT; ?>interpolation/fetch_voice_info.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            }
                        });
                    
                    const data = await response.json();
                    if (data.success) {
                        voice_info = data.voice_info;
                        // Additionally, you might want to cache this in localStorage for persistence
                        localStorage.setItem('cached_voice_info', JSON.stringify(voice_info));
                        //console.log('Voice information cached successfully');
                    } else {
                        //console.error('Error fetching voice info:', data.error);
                        displayMessage('Sorry, there is an issue on our end, try again in a few minutes', 'Chat');
                        return;
                    }
                } catch (error) {
                    //console.error('Error fetching voice info:', error);
                    displayMessage('Sorry, there is an issue on our end, try again in a few minutes', 'Chat');
                    return;
                }
              }
                const voice_names = [];
                
                voice_info.forEach(voice => {
                    voice_names.push(voice.name + (voice.gender ? " (" + voice.gender + ")" : ""));
                });
                
                // Voice descriptions string for the prompt
                const voice_names_text = voice_names.join("\n");
                
                // Initialize conversation with system and first assistant message
                // Display the first message in the chat UI
                displayMessage("Hi there! I'd love to help you visualize your prompt. Could you describe what you'd like to turn into a video? It's important to give as much detail as possible.", 'Chat');
                // User's dream description
                const dream_description = await get_user_input();
                displayMessage("\n" + dream_description + "\n", 'User');

                    
                // Ask about audio
                displayMessage("Thanks for the description! Would you like to add audio to your video?", 'Chat');
                const music_choice = await get_user_input();
                displayMessage("\n" + music_choice + "\n", 'User');
                    
                // Determine if they want audio and which type
                let want_music = "no";
                let audio_description = "";
                if (music_choice.toLowerCase().includes("yes") || 
                    music_choice.toLowerCase().includes("sure") || 
                    music_choice.toLowerCase().includes("ok")) {
                    want_music = "yes";
                    displayMessage("Great! What kind of mood or sounds would you like for the background music?", "Chat");
                    audio_description = await get_user_input();
                    displayMessage("\n" + audio_description + "\n", 'User');
                }

                // Then ask about voiceover
                displayMessage("Would you also like to add a voiceover narration? (yes/no)", "Chat");
                const voice_choice = await get_user_input();
                displayMessage("\n" + voice_choice + "\n", 'User');
                
                let want_voice = "no";
                let voice_description = "";
                let selected_voice_details = {};
                let selected_voice_name = "";
                let want_auto_split = false;
                
                if (voice_choice.toLowerCase().includes("yes") || 
                    voice_choice.toLowerCase().includes("sure") || 
                    voice_choice.toLowerCase().includes("ok")) {
                    want_voice = "yes";
                    
                    // List available voices for the user to choose from with richer descriptions
                    displayMessage("\nAvailable voices:\n", "Chat");
                    voice_info.forEach((voice, index) => {
                        let voice_display = `${voice.name}`;
                        if (voice.gender && voice.accent) {
                            voice_display += ` (${voice.gender}, ${voice.accent} accent)`;
                        }
                        // Only display description if it exists and is not empty or "No description available"
                        if (voice.description && voice.description !== "No description available") {
                            voice_display += ` - ${voice.description}`;
                        }
                        displayMessage(`${index + 1}. ${voice_display}\n`, "Chat");
                    });
                    
                    displayMessage("Enter the name of the voice you'd like to use (e.g. 'Oxley', 'Rachel'):", "Chat");
                    const voice_name_input = await get_user_input();
                    displayMessage("\n" + voice_name_input + "\n", 'User');
                    
                    // Match voice by name using PHP endpoint
                    try {
                        const response = await fetch('<?php echo WEB_ROOT; ?>interpolation/find_voice_by_name.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                voice_info: voice_info,
                                voice_name: voice_name_input
                            })
                        });
                        
                        const data = await response.json();
                        if (data.success) {
                            selected_voice_details = data.voice;
                            selected_voice_name = selected_voice_details.name;
                            
                            // Display message about voice match if provided
                            if (data.message) {
                                displayMessage(data.message, "Chat");
                            }
                        } else {
                            //console.error('Error finding voice:', data.error);
                            // Fall back to first voice in the list
                            selected_voice_details = voice_info.length > 0 ? voice_info[0] : {};
                            selected_voice_name = selected_voice_details.name || "Default Voice";
                            displayMessage("Had trouble finding that voice. Using " + selected_voice_name + " instead.", "Chat");
                        }
                    } catch (error) {
                        //console.error('Error in voice selection:', error);
                        // Fall back to first voice in the list
                        selected_voice_details = voice_info.length > 0 ? voice_info[0] : {};
                        selected_voice_name = selected_voice_details.name || "Default Voice";
                        displayMessage("Had trouble with voice selection. Using " + selected_voice_name + " instead.", "Chat");
                    }

                    displayMessage("Selected voice: " + selected_voice_name, "Chat");
                    if (selected_voice_details.gender && selected_voice_details.accent) {
                        displayMessage(" (" + selected_voice_details.gender + ", " + selected_voice_details.accent + " accent)", "Chat");
                    }

                    displayMessage("Perfect! What vibe would you like the narration to have? You can provide your own snippet to inspire the Goober!", "Chat");
                    voice_description = await get_user_input();
                    displayMessage("\n" + voice_description + "\n", 'User');
                    
                    // Ask if they want to let AI split the narration across scenes
                    displayMessage("Would you like me to automatically split your narration across multiple scenes? (yes/no)", "Chat");
                    const auto_split = await get_user_input();
                    displayMessage("\n" + auto_split + "\n", 'User');
                    
                    want_auto_split = (auto_split.toLowerCase().includes("yes") || 
                                    auto_split.toLowerCase().includes("sure") || 
                                    auto_split.toLowerCase().includes("ok"));
                }
                
                // Ask about the number of scenes/frames
                displayMessage("How many scenes would you like in your video? (I'll analyze your prompt to suggest scenes, but you can specify a number if you prefer)", "Chat");
                const num_scenes_input = await get_user_input();
                displayMessage("\n" + num_scenes_input + "\n", 'User');
                
                // Check if the user specified a number
                let specified_num_scenes = 0;
                const matches = num_scenes_input.match(/(\d+)/);
                if (matches) {
                    specified_num_scenes = parseInt(matches[1], 10);
                    // Enforce limits (2-8 scenes)
                    specified_num_scenes = Math.max(2, Math.min(8, specified_num_scenes));
                }
                
                // Confirmation and summary
                let summary_prompt = "Thanks for all that information! To summarize: I'll create a video based on your description: \"" + dream_description + "\"";
                
                if (specified_num_scenes > 0) {
                    summary_prompt += " with " + specified_num_scenes + " scenes";
                } else {
                    summary_prompt += " with multiple scenes based on my analysis of your description";
                }
                
                if (want_music === "yes") {  
                    summary_prompt += " with ambient music that sounds like: \"" + audio_description + "\"";
                } 

                if (want_voice === "yes") {
                    summary_prompt += " with a voiceover saying: \"" + voice_description + "\"";
                    if (want_auto_split) {
                        summary_prompt += " (automatically split across scenes)";
                    }
                } else {
                    summary_prompt += " without any voiceover";
                }
                summary_prompt += ". Does that sound good? (yes/no)";
                
                displayMessage(summary_prompt, "Chat");
                const confirmation = await get_user_input();
                displayMessage("\n" + confirmation + "\n", 'User');
                
                if (!confirmation.toLowerCase().includes("yes") && 
                    !confirmation.toLowerCase().includes("ok") && 
                    !confirmation.toLowerCase().includes("sure")) {
                    displayMessage("\nNo problem! Let's start over to get your video just right.\n", "Chat");
                    return start_conversation(); // Restart the conversation
                }
                
                // Final confirmation
                displayMessage("Great! I'll generate your video now. This might take a few minutes to process. Feel free to leave and come back!", "Chat");
                document.getElementById('stop-button').style.display = 'block';

                // Return the complete information
                const formData = new FormData();
                formData.append('dream_description', dream_description);
                formData.append('want_music', want_music);
                formData.append('audio_description', audio_description);
                formData.append('want_voice', want_voice);
                formData.append('voice_description', voice_description);
                formData.append('voice_name', selected_voice_name);
                formData.append('voice_details', JSON.stringify(selected_voice_details));
                formData.append('want_auto_split', want_auto_split);
                formData.append('specified_num_scenes', specified_num_scenes);
                formData.append('voice_info', JSON.stringify(voice_info));
                
                // First, get the current list of videos in the uploads folder
                fetch('<?php echo WEB_ROOT; ?>interpolation/list_uploads.php')
                    .then(response => response.json())
                    .then(initialFiles => {
                        // Store the initial list of files
                        const initialFilesList = initialFiles;
                                
                        // Now proceed with the dream generation
                        fetch('<?php echo WEB_ROOT; ?>interpolation/dream2img.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => {
                            // // Check if we got a timeout response
                            // if (response.status === 504) {
                            //     displayMessage("Your dream is taking longer than expected to generate. Checking for completion in a minute :)", "Chat");
                                
                            //     // Wait 2 minutes then check for new files
                            //     setTimeout(() => {
                            //         checkForNewVideos(initialFilesList, dream_description);
                            //     }, 75000); // 1.25 minutes
                                
                            //     return Promise.reject('expected_timeout');
                            // }
                            
                            // if (!response.ok) {
                            //     throw new Error(`Server responded with status: ${response.status}`);
                            // }
                            //console.log('Got a response from dream2img');
                            return response.json();
                        })
                        .then(result => {
                            //console.log('About to set timeout');
                            localStorage.setItem('videoCheckTimer', JSON.stringify({
                                startTime: Date.now(),
                                delay: 75000,
                                initialFilesList: initialFilesList,
                                dream_description: dream_description,
                                numberOfTimesChecked: 0
                            }));
                            // Wait 2 minutes then check for new files
                            setTimeout(() => {
                                checkForNewVideos(initialFilesList, dream_description, 0);
                            }, 75000); // 1.25 minutes

                          
                        })
                        .catch(error => {
                            // Skip the error message for our expected timeout
                            // if (error === 'expected_timeout') {
                            //     return;
                            // }
                            
                            // Your existing error handling
                            //console.error('Error:', error);
                            displayMessage("Error generating video, try again later.", "Chat");
                        });
                    })
                    .catch(error => {
                        //console.error('Error checking uploads folder:', error);
                        // Continue with the normal fetch process even if we couldn't check the uploads folder
                        // Insert your existing fetch code here as a fallback    
                });
            }

            function displayMessage(text, sender, isHTML = false) {
                const messageDiv = document.createElement('div');
                messageDiv.classList.add('message-box');
                
                // Add the sender-specific class
                const senderClass = sender.toLowerCase() === 'chat' ? 'chat-message' : 'user-message';
                messageDiv.classList.add(senderClass);
                
                // Create the sender label
                const senderLabel = document.createElement('div');
                senderLabel.classList.add('message-sender');
                senderLabel.textContent = sender;
                messageDiv.appendChild(senderLabel);
                
                // Create the message content
                const messageContent = document.createElement('div');
                messageContent.classList.add('message-content');
                
                // Set the message content as HTML or text
                if (isHTML) {
                    messageContent.innerHTML = text;
                } else {
                    messageContent.textContent = text;
                }
                
                messageDiv.appendChild(messageContent);
                messageArea.appendChild(messageDiv);
                messageArea.scrollTop = messageArea.scrollHeight;
                
                // Add styling if not already in CSS
                addMessageStyles();
            }

            // Function to add message styles if not already defined in CSS
            function addMessageStyles() {
                // Check if styles are already added
                if (document.getElementById('message-box-styles')) return;
                
                const styleElement = document.createElement('style');
                styleElement.id = 'message-box-styles';
                styleElement.textContent = `
                    .message-box {
                        margin: 10px 0;
                        padding: 10px;
                        border-radius: 10px;
                        max-width: 80%;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                        position: relative;
                        clear: both;
                    }
                    
                    .chat-message {
                        background-color: #e3f2fd; /* Light blue */
                        float: left;
                        margin-right: 15%;
                        border-top-left-radius: 0; /* Speech bubble effect */
                    }
                    
                    .chat-message:before {
                        content: '';
                        position: absolute;
                        left: -10px;
                        top: 0;
                        border-top: 10px solid transparent;
                        border-right: 10px solid #e3f2fd;
                    }
                    
                    .user-message {
                        background-color: #bbdefb; /* Slightly darker blue */
                        float: right;
                        margin-left: 15%;
                        border-top-right-radius: 0; /* Speech bubble effect */
                    }
                    
                    .user-message:before {
                        content: '';
                        position: absolute;
                        right: -10px;
                        top: 0;
                        border-top: 10px solid transparent;
                        border-left: 10px solid #bbdefb;
                    }
                    
                    .message-sender {
                        font-weight: bold;
                        margin-bottom: 5px;
                        font-size: 0.9em;
                        color: #1565c0; /* Dark blue */
                    }
                    
                    .message-content {
                        word-wrap: break-word;
                    }
                    
                    .message-content video {
                        max-width: 100%;
                        border-radius: 5px;
                        margin: 5px 0;
                    }
                    
                    .message-content a {
                        color: #0d47a1;
                        text-decoration: none;
                        font-weight: bold;
                    }
                    
                    .message-content a:hover {
                        text-decoration: underline;
                    }
                    
                    .explore-link {
                        display: inline-block;
                        margin-top: 8px;
                        padding: 6px 12px;
                        background-color: #2196f3;
                        color: white !important;
                        border-radius: 4px;
                        text-decoration: none;
                        transition: background-color 0.2s;
                    }
                    
                    .explore-link:hover {
                        background-color: #1976d2;
                        text-decoration: none !important;
                    }
                    
                    /* Fix to ensure messages don't overlap */
                    #messageArea:after {
                        content: "";
                        display: table;
                        clear: both;
                    }
                `;
                
                document.head.appendChild(styleElement);
            }

            // Function to save video to items table
            function saveVideoToDatabase(uploadId, username, videoPath, fileName, dream_description) {
                    // Get the current user's username (adjust this based on your authentication system)
                    //console.log('username is: ' + username);
                    // Prepare the data
                    const videoData = {
                        uploadId: uploadId,
                        name: fileName,
                        path: videoPath,
                        username: username,
                        keywords: "dream, video, generated",
                        description: dream_description
                    };
                    
                    // Send request to save the video info
                    fetch('<?php echo WEB_ROOT; ?>api/save_video_item.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(videoData)
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.status === 'success') {
                            //console.log('Video saved to database with ID:', result.item_id);
                        } else {
                            //console.error('Error saving video to database:', result.message);
                        }
                    })
                    .catch(error => {
                        //console.error('Database save error:', error);
                    });
            }

            // Function to check for new videos
            function checkForNewVideos(initialFiles, description, numberOfTimesChecked) {
                //console.log("Checking for new videos...");
                fetch('<?php echo WEB_ROOT; ?>interpolation/list_uploads.php')
                .then(response => response.json())
                .then(currentFiles => {
                    // Find new files by comparing with the initial list
                    const newFiles = currentFiles.filter(file => !initialFiles.includes(file));
                    //const newFiles = [];
                    
                    if (newFiles.length > 0) {
                        // Found at least one new file - assume it's our video (most recent first)
                        const newestFile = newFiles[0]; // Or sort by date if list includes timestamps
                        
                        // Extract filename for database and display
                        const fileName = newestFile.split('/').pop();
                        const videoPath = 'uploads/' + fileName; // Adjust path as needed
                        const currentUsername = '<?php echo isset($_SESSION["username"]) ? $_SESSION["username"] : "anonymous"; ?>';
                        const fileNameBase = fileName.replace('.mp4', '');
                        const uploadId = fileNameBase.split('video_')[1];

                        // Save to database
                        saveVideoToDatabase(uploadId,currentUsername, videoPath, fileNameBase, description);

                        // Success message
                        const successMessage = `Video generation complete! 
                            <a href="/user/explore.php?itemName=${encodeURIComponent(uploadId)}" class="explore-link">
                                Click here to view your video
                            </a>`;
                        
                        displayMessage(successMessage, "Chat", true);

                        // Run cleanup first
                        localStorage.removeItem("videoCheckTimer");
                        let id = window.setTimeout(() => {}, 0);
                        while (id--) clearTimeout(id);

                    } else {
                        // No new files found, check again or give up
                        displayMessage("Your video is still being generated. Checking again in 1 minute...", "Chat");
                        
                        if(numberOfTimesChecked == 3){
                            //console.log("Checked too many times, stopping video gen");
                            displayMessage("I'm sorry but generation has failed. Try again.", "Chat");
                            // Run cleanup first
                            localStorage.removeItem("videoCheckTimer");
                            let id = window.setTimeout(() => {}, 0);
                            while (id--) clearTimeout(id);
                            fetch(CONFIG.API_BASE +'stop_process.php', {
                                method: 'POST'
                            })
                            .then(response => response.text())
                            .then(result => {
                                //console.log(result);
                                if(result == 15 || result == 0){
                                    //console.log("Successfully stopped video gen after checking too many times");
                                }else{
                                    //console.log("Failed to stop gen after checking too many times", "Chat");
                                }
                            })
                            .catch(error => {
                                //console.log('Error stopping process: ' + error);
                            });
                            return;
                        }
                        // Check again in 1 minute
                        setTimeout(() => {
                            checkForNewVideos(initialFiles, description, numberOfTimesChecked);
                        }, 60000); // 1 minute
                        numberOfTimesChecked++;
                    }
                })
                .catch(error => {
                    //console.error('Error checking for new videos:', error);
                    displayMessage("Error checking video status. Please check the 'My Dreams' section later.", "Chat");
                });
            }

            const timerData = JSON.parse(localStorage.getItem('videoCheckTimer') || '{}');
            
            if (timerData.startTime) {
                const elapsed = Date.now() - timerData.startTime;                   
                if (elapsed >= timerData.delay) {
                    // Time's up, check for videos    
                    checkForNewVideos(timerData.initialFilesList, timerData.dream_description, timerData.numberOfTimesChecked);
                    localStorage.removeItem('videoCheckTimer');
                } else {
                    // Still waiting, set up remaining timeout
                    displayMessage("We're still generating that film for you!", "Chat");
                    setTimeout(() => {
                        checkForNewVideos(timerData.initialFilesList, timerData.dream_description, timerData.numberOfTimesChecked);
                        localStorage.removeItem('videoCheckTimer');
                    }, timerData.delay - elapsed);
                }
            }else{
                start_conversation();
            }
        });              
    </script>
    <?php require_once '../src/footer.php'; echo generateFooter(); ?>
</body>
</html>