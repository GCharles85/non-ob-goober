// Global variable to track if agent is active
let agentActive = false;

// Initialize agent toggle
// function initAgentToggle() {
//     const agentToggle = document.getElementById('useAgent');
    
//     agentToggle.addEventListener('change', function() {
//         agentActive = this.checked;
//         console.log('Agent active:', agentActive);
        
//         // Show notification when agent is activated
//         if (agentActive) {
//             showNotification('AI Agent activated. The agent will respond on your behalf.');
//         } else {
//             showNotification('AI Agent deactivated. You are now in control of the conversation.');
//         }
//     });
// }

// Show a notification
function showNotification(message) {
    const notification = document.createElement('div');
    notification.className = 'notification';
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('show');
    }, 100);
    
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            notification.remove();
        }, 500);
    }, 3000);
}

// Update the sendMessage function to use the agent when active
async function sendMessage() {
    const messageText = document.getElementById('newMessage').value.trim();
    if (!messageText) return;
    
    // Find the active conversation
    const activeConversation = document.querySelector('#conversationList li.active');
    if (!activeConversation) {
        alert('Please select a conversation first');
        return;
    }
    
    const conversationId = activeConversation.dataset.conversationId;
    const receiver = document.getElementById('conversationTitle').textContent.trim();
    
    console.log('Sending message to:', receiver);
    console.log('Conversation ID:', conversationId);
    console.log('Current user:', currentUser);
    console.log('Agent active:', agentActive);
    
    try {
        // Always send the user's message first
        const response = await fetch(CONFIG.API_BASE + 'send_message.php',  {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                conversation_id: conversationId,
                content: messageText,
                sender_user: currentUser,
                receiver_user: receiver
            })
        });

        if (!response.ok) {
            throw new Error(`Failed to send message: ${response.status}`);
        }
        
        // Clear the input field
        document.getElementById('newMessage').value = '';
        
        // Create a temporary display of the message
        displayTemporaryMessage(messageText, 'sent');
        
        // Reload the conversation to ensure consistency
        setTimeout(() => fetchAndDisplayMessages(conversationId), 500);
        
    } catch (error) {
        console.error('Error sending message:', error);
        alert('Network error when sending message.');
    }
}

// Display a temporary message in the chat
function displayTemporaryMessage(text, type) {
    const messageList = document.getElementById('messageList');
    
    // Remove any system messages or "no messages" placeholders
    const systemMessages = messageList.querySelectorAll('.system-message, .no-messages');
    systemMessages.forEach(msg => msg.remove());
    
    // Create new message element
    const li = document.createElement('li');
    li.className = type;
    
    const contentSpan = document.createElement('span');
    contentSpan.className = 'message-content';
    contentSpan.textContent = text;
    li.appendChild(contentSpan);
    
    const timeSpan = document.createElement('span');
    timeSpan.className = 'message-time';
    const now = new Date();
    timeSpan.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    li.appendChild(timeSpan);
    
    messageList.appendChild(li);
    messageList.scrollTop = messageList.scrollHeight;
}

// Show a typing indicator when the AI is preparing a response
function showTypingIndicator() {
    const messageList = document.getElementById('messageList');
    
    // Create typing indicator
    const li = document.createElement('li');
    li.className = 'typing-indicator';
    li.innerHTML = '<span>AI is typing</span><span class="dot">.</span><span class="dot">.</span><span class="dot">.</span>';
    
    messageList.appendChild(li);
    messageList.scrollTop = messageList.scrollHeight;
}

// Remove the typing indicator
function removeTypingIndicator() {
    const indicator = document.querySelector('.typing-indicator');
    if (indicator) {
        indicator.remove();
    }
}

// Update the DOMContentLoaded event listener
window.addEventListener('DOMContentLoaded', () => {
    populateConversations();
    updateSendButton();
    //initAgentToggle();
});

// Update the handleIncomingMessages function to use the provided code pattern
async function handleIncomingMessages(newMessages) {
    // If the agent is active and there are new messages from the other user
    if (agentActive) {
        const receivedMessages = newMessages.filter(msg => msg.sender !== currentUser);
        
        if (receivedMessages.length > 0) {
            // Get the active conversation
            const activeConversation = document.querySelector('#conversationList li.active');
            if (activeConversation) {
                const conversationId = activeConversation.dataset.conversationId;
                const receiver = document.getElementById('conversationTitle').textContent.trim();
                
                // For each received message, let the agent respond
                for (const message of receivedMessages) {
                    // Show typing indicator
                    showTypingIndicator();
                    
                    try {
                        const agentResponse = await fetch(CONFIG.API_BASE + 'ai_agent.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                conversation_id: conversationId,
                                user_message: `RESPONDING TO: ${message.content}`,
                                receiver_user: message.sender // This should be the sender of the message
                            })
                        });
                        
                        // Remove typing indicator
                        removeTypingIndicator();
                        
                        if (!agentResponse.ok) {
                            throw new Error(`Agent failed: ${agentResponse.status}`);
                        }
                        
                        const agentData = await agentResponse.json();
                        console.log('AI Agent response:', agentData);
                        
                        // No need to display the AI response as it will be picked up by the message polling
                    } catch (agentError) {
                        console.error('AI Agent error:', agentError);
                        showNotification('AI Agent failed to respond. Switching to manual mode.');
                        document.getElementById('useAgent').checked = false;
                        agentActive = false;
                    }
                }
                
                // Reload the conversation to ensure consistency
                setTimeout(() => fetchAndDisplayMessages(conversationId), 500);
            }
        }
    }
}

// Update the appendNewMessages function to call handleIncomingMessages
function appendNewMessages(newMessages) {
    const messageList = document.getElementById('messageList');
    let lastSender = getLastMessageSender();
    
    newMessages.forEach(message => {
        // Update lastMessageId to the highest message ID
        if (message.message_id > lastMessageId) {
            lastMessageId = parseInt(message.message_id);
        }
        
        const li = document.createElement('li');
        li.className = message.sender === currentUser ? 'sent' : 'received';
        li.dataset.messageId = message.message_id;
        
        // Only show sender name if it's different from the previous message
        if (message.sender !== lastSender) {
            const senderSpan = document.createElement('span');
            senderSpan.className = 'sender-name';
            senderSpan.textContent = message.sender + " ";
            li.appendChild(senderSpan);
        }
        
        // Create message content
        const contentSpan = document.createElement('span');
        contentSpan.className = 'message-content';
        contentSpan.textContent = message.content + " ";
        li.appendChild(contentSpan);
        
        // Add timestamp if available
        if (message.timestamp) {
            const timeSpan = document.createElement('span');
            timeSpan.className = 'message-time';
            const messageDate = new Date(message.timestamp);
            timeSpan.textContent = messageDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            li.appendChild(timeSpan);
        }
        
        messageList.appendChild(li);
        lastSender = message.sender;
    });
    
    // Scroll to the bottom of the message list
    messageList.scrollTop = messageList.scrollHeight;
   
    // Handle incoming messages with the agent if needed
    handleIncomingMessages(newMessages);
}

// Function to handle user search
async function searchUsers() {
    const searchTerm = document.getElementById('userSearch').value.trim();
    if (searchTerm.length < 2) {
        document.getElementById('searchResults').innerHTML = '';
        return;
    }
    
    try {
        const response = await fetch(`${CONFIG.API_BASE}search_users.php?term=${encodeURIComponent(searchTerm)}`);
        if (!response.ok) throw new Error('Search failed');
        
        const users = await response.json();
        const searchResults = document.getElementById('searchResults');
        const filteredUsers = users.filter(username => username !== "AI Assistant");
        searchResults.innerHTML = '';
        
        if (filteredUsers.length === 0) {
            searchResults.innerHTML = '<p>No users found</p>';
            return;
        }

        filteredUsers.forEach(username => {
            const userDiv = document.createElement('div');
            userDiv.className = 'user-result';
            userDiv.textContent = username;
            userDiv.addEventListener('click', () => startConversation(username));
            searchResults.appendChild(userDiv);
        });
    } catch (error) {
        console.error('Error searching users:', error);
    }
}


async function startConversation(username) {
    try {
        console.log('Starting conversation with:', username);
        
        const response = await fetch(CONFIG.API_BASE + 'create_conversation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                username: username
            })
        });
        
        if (!response.ok) {
            console.error('Failed to create conversation:', response.status);
            throw new Error('Failed to create conversation');
        }
        
        const data = await response.json();
        console.log('Conversation created/found:', data);
        
        // Clear search results
        document.getElementById('userSearch').value = '';
        document.getElementById('searchResults').innerHTML = '';
        
        // Set conversation title immediately so user has feedback
        document.getElementById('conversationTitle').textContent = username;
        
        // Force a fresh reload of conversations
        await populateConversations();
        
        // Explicitly add this conversation to the list if it's not there
        const conversationList = document.getElementById('conversationList');
        let found = false;
        
        // Check if conversation is already in the list
        const items = conversationList.querySelectorAll('li');
        items.forEach(item => {
            if (parseInt(item.dataset.conversationId) === data.conversation_id) {
                found = true;
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
        
        // If not found in the list, add it manually
        if (!found) {
            const li = document.createElement('li');
            li.textContent = username;
            li.dataset.conversationId = data.conversation_id;
            li.classList.add('active'); // Mark as active
            li.addEventListener('click', () => loadConversation(data.conversation_id));
            conversationList.appendChild(li);
        }
        
        // Load the conversation messages
        await fetchAndDisplayMessages(data.conversation_id);
        
        // Add a welcome message for first-time users
        const messageList = document.getElementById('messageList');
        if (messageList.children.length === 0) {
            const li = document.createElement('li');
            li.className = 'system-message';
            li.textContent = `Start chatting with ${username}!`;
            messageList.appendChild(li);
        }
        
        // Focus on message input
        document.getElementById('newMessage').focus();
    } catch (error) {
        console.error('Error starting conversation:', error);
        alert('Failed to start conversation. Please try again.');
    }
}

// Add event listeners
document.getElementById('searchBtn').addEventListener('click', searchUsers);
document.getElementById('userSearch').addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
        searchUsers();
    }
});

async function fetchConversations() {
    try {
        const response = await fetch(CONFIG.API_BASE + 'get_conversations.php');
        if (!response.ok) throw new Error('Failed to fetch');
        return await response.json();
    } catch (error) {
        console.error('Error:', error);
        return [];
    }
}

// Updated populate function with better click handling
// Populate conversations list
async function populateConversations() {
    try {
        const response = await fetch(CONFIG.API_BASE + 'get_conversations.php');
        if (!response.ok) {
            console.error('Failed to fetch conversations:', response.status);
            throw new Error('Failed to fetch conversations');
        }
        
        const conversations = await response.json();
        console.log('Conversations loaded:', conversations);
        
        const conversationList = document.getElementById('conversationList');
        conversationList.innerHTML = '';

        if (conversations.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'No conversations yet.';
            conversationList.appendChild(li);
            return;
        }
        
        conversations.forEach(convo => {
            const li = document.createElement('li');
            li.textContent = convo.participant;
            li.dataset.conversationId = convo.conversation_id;
            li.addEventListener('click', () => loadConversation(convo.conversation_id));
            conversationList.appendChild(li);
        });
        
        // Select the first conversation by default
        if (conversations.length > 0) {
            loadConversation(conversations[0].conversation_id);
        }
    } catch (error) {
        console.error('Error loading conversations:', error);
    }
}

// Send message function
document.getElementById('sendBtn').addEventListener('click', sendMessage);
document.getElementById('newMessage').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        sendMessage();
    }
});


// Message polling variables and functions
let messagePollingInterval;
let lastMessageId = 0;

function startMessagePolling(conversationId) {
    // Clear any existing polling
    if (messagePollingInterval) {
        clearInterval(messagePollingInterval);
    }
    
    // Set lastMessageId to the ID of the most recent message currently displayed
    updateLastMessageId();
    
    // Start polling every 3 seconds
    messagePollingInterval = setInterval(() => {
        if (conversationId) {
            checkForNewMessages(conversationId);
        }
    }, 3000);
    
    console.log('Message polling started for conversation:', conversationId);
}

function updateLastMessageId() {
    // Find all messages currently in the message list
    const messages = document.querySelectorAll('#messageList li[data-message-id]');
    if (messages.length > 0) {
        // Get the last message's ID
        const lastMessage = messages[messages.length - 1];
        if (lastMessage.dataset.messageId) {
            lastMessageId = parseInt(lastMessage.dataset.messageId);
            console.log('Last message ID updated to:', lastMessageId);
        }
    }
}

async function checkForNewMessages(conversationId) {
    //console.log(`checking last id in checkForNewMessages ${lastMessageId}`);
    try {
        const response = await fetch(`${CONFIG.API_BASE}get_new_messages.php?conversation_id=${conversationId}&last_id=${lastMessageId}`);
        if (!response.ok) {
            console.error('Failed to check for new messages:', response.status);
            return;
        }
        
        const newMessages = await response.json();
        if (newMessages.length > 0) {
            console.log('New messages received:', newMessages);
            appendNewMessages(newMessages);
        }
    } catch (error) {
        console.error('Error checking for new messages:', error);
    }
}

function appendNewMessages(newMessages) {
    const messageList = document.getElementById('messageList');
    let lastSender = getLastMessageSender();
    
    newMessages.forEach(message => {
        // Update lastMessageId to the highest message ID
        if (message.message_id > lastMessageId) {
            lastMessageId = parseInt(message.message_id);
        }
        
        const li = document.createElement('li');
        li.className = message.sender === currentUser ? 'sent' : 'received';
        li.dataset.messageId = message.message_id;
        
        // Only show sender name if it's different from the previous message
        if (message.sender !== lastSender) {
            const senderSpan = document.createElement('span');
            senderSpan.className = 'sender-name';
            senderSpan.textContent = message.sender + " ";
            li.appendChild(senderSpan);
        }
        
        // Create message content
        const contentSpan = document.createElement('span');
        contentSpan.className = 'message-content';
        contentSpan.textContent = message.content + " ";
        li.appendChild(contentSpan);
        
        // Add timestamp if available
        if (message.timestamp) {
            const timeSpan = document.createElement('span');
            timeSpan.className = 'message-time';
            const messageDate = new Date(message.timestamp);
            timeSpan.textContent = messageDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            li.appendChild(timeSpan);
        }
        
        messageList.appendChild(li);
        lastSender = message.sender;
    });
    
    // Scroll to the bottom of the message list
    messageList.scrollTop = messageList.scrollHeight;

    handleIncomingMessages(newMessages);
}

function getLastMessageSender() {
    const messages = document.querySelectorAll('#messageList li');
    if (messages.length > 0) {
        const lastMessage = messages[messages.length - 1];
        const senderSpan = lastMessage.querySelector('.sender-name');
        return senderSpan ? senderSpan.textContent.trim() : null;
    }
    return null;
}

// Load conversation function with polling integration
function loadConversation(conversationId) {
    // First, mark the selected conversation as active
    const items = document.querySelectorAll('#conversationList li');
    items.forEach(item => {
        if (item.dataset.conversationId == conversationId) {
            item.classList.add('active');
            // Store the conversation partner's username for sending messages
            const partner = item.textContent.trim();
            document.getElementById('conversationTitle').textContent = partner;
        } else {
            item.classList.remove('active');
        }
    });
    
    // Reset lastMessageId when changing conversations
    lastMessageId = 0;
    
    // Then fetch and display messages
    fetchAndDisplayMessages(conversationId).then(() => {
        // Start polling for new messages after initial messages are loaded
        startMessagePolling(conversationId);
    });
}

// Updated fetchAndDisplayMessages function to include message IDs
async function fetchAndDisplayMessages(conversationId) {
    try {
        const response = await fetch(`${CONFIG.API_BASE}get_messages.php?conversation_id=${conversationId}`);
        if (!response.ok) {
            console.error('Failed to load messages:', response.status);
            throw new Error('Failed to load messages');
        }
        const messages = await response.json();
        console.log('Messages received:', messages);
        
        const messageList = document.getElementById('messageList');
        messageList.innerHTML = '';
        
        if (messages.length === 0) {
            const li = document.createElement('li');
            li.className = 'no-messages';
            li.textContent = 'No messages yet. Start a conversation!';
            messageList.appendChild(li);
            return;
        }
        
        let lastSender = null;
        
        messages.forEach((message, index) => {
            const li = document.createElement('li');
            li.className = message.sender === currentUser ? 'sent' : 'received';
            
            // Store message ID in data attribute
            if (message.message_id) {
                li.dataset.messageId = message.message_id;
                
                // Update lastMessageId to track the most recent message
                if (parseInt(message.message_id) > lastMessageId) {
                    lastMessageId = parseInt(message.message_id);
                }
            }
            
            // Only show sender name if it's different from the previous message
            if (message.sender !== lastSender) {
                const senderSpan = document.createElement('span');
                senderSpan.className = 'sender-name';
                senderSpan.textContent = message.sender + " ";
                li.appendChild(senderSpan);
            }
            
            // Create message content
            const contentSpan = document.createElement('span');
            contentSpan.className = 'message-content';
            contentSpan.textContent = message.content + " ";
            li.appendChild(contentSpan);
            
            // Add timestamp if available
            if (message.timestamp) {
                const timeSpan = document.createElement('span');
                timeSpan.className = 'message-time';
                const messageDate = new Date(message.timestamp);
                timeSpan.textContent = messageDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                li.appendChild(timeSpan);
            }
            
            messageList.appendChild(li);
            lastSender = message.sender;
        });
        
        // Scroll to the bottom of the message list
        messageList.scrollTop = messageList.scrollHeight;
    } catch (error) {
        console.error('Error loading messages:', error);
    }
}

// Clear polling when navigating away from the page
window.addEventListener('beforeunload', () => {
    if (messagePollingInterval) {
        clearInterval(messagePollingInterval);
    }
});

// Update send button with icon
function updateSendButton() {
    const sendBtn = document.getElementById('sendBtn');
    sendBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>';
}

// Call this when the page loads
window.addEventListener('DOMContentLoaded', () => {
    populateConversations();
    updateSendButton();
});