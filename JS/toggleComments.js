let currentImageId = null;

document.addEventListener('DOMContentLoaded', function() {
   // Select the single dropdown button
    const dropdownBtn = document.querySelector('.dropdown-btn');

    // Add click event listener
    dropdownBtn.addEventListener('click', function() {
        const imageId = this.getAttribute('data-image-id'); // "this" refers to the button that was clicked
        currentImageId = imageId;
        const content = this.nextElementSibling; // element next to/after the button

        // Send AJAX request to fetch comments
        fetchCommentsAjax(this, imageId, content);
    });

    // Add event listener for post comment buttons
    const postButtons = document.querySelectorAll('#post-comment-btn');
    //console.log('Found post buttons:', postButtons.length);
    postButtons.forEach(button => {
        button.addEventListener('click', function() {
            const imageId = this.getAttribute('data-image-id');
            const commentInput = this.parentElement.querySelector('#comment-input');
            const commentContent = commentInput.value.trim();
            
            if (commentContent) {
                postComment(imageId, commentContent);
                commentInput.value = ''; // Clear the input field
            }
        });
    });

    // Add event listener for Enter key in comment input fields
    const commentInputs = document.querySelectorAll('#comment-input');
    commentInputs.forEach(input => {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const imageId = this.parentElement.querySelector('#post-comment-btn').getAttribute('data-image-id');
                const commentContent = this.value.trim();
                
                if (commentContent) {
                    postComment(imageId, commentContent);
                    this.value = ''; // Clear the input field
                }
            }
        });
    });
});


function fetchCommentsAjax(button, imageId, content) {
    console.log(`Fetching comments for image ID: ${imageId}`);
    fetch(CONFIG.SRC_BASE + 'fetch_comments.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `imageId=${imageId}`,
    })
    .then(response => {
        console.log('Status:', response.status); // <--- Check status code here!
        if (!response.ok) {
          throw new Error('HTTP error! Status: ' + response.status);
        }
        return response.json(); // This is where it probably breaks!
      })
    .then(data => {
        // Clear any existing content
        content.innerHTML = '';

        // Display the fetched comments
        data.reverse().forEach(comment => {
            const commentDiv = document.createElement('div');
            commentDiv.classList.add('comment');

            // Create the paragraph but don't set text yet
            const commentText = document.createElement('p');
            commentText.style.whiteSpace = "pre-line";
            commentText.style.margin = "0 0 1em 0"; // Keep bottom margin for spacing with next element

            // Create username span with bold, smaller styling
            const usernameSpan = document.createElement('span');
            usernameSpan.textContent = comment.Username + " " + formatTimestamp(comment.created_at);
            usernameSpan.style.fontWeight = 'bold';
            usernameSpan.style.fontSize = '0.85rem';
            usernameSpan.style.display = 'block'; // Makes it act like a block element
            usernameSpan.style.marginRight = '5px'; 
            // Create comment span with larger styling
            const commentSpan = document.createElement('span');
            commentSpan.textContent = comment.comment;
            commentSpan.style.fontSize = '1rem'; 

            // Add both spans to the paragraph
            commentText.appendChild(usernameSpan);
            commentText.appendChild(document.createTextNode('\n')); // Keep the newline
            commentText.appendChild(commentSpan);

            // Add the paragraph to the comment div
            commentDiv.appendChild(commentText);
            const replyBtn = document.createElement('button');
            replyBtn.classList.add('reply-btn');
            replyBtn.textContent = 'Reply';
            replyBtn.dataset.commentId = comment.id;
        
            const repliesContainer = document.createElement('div');
            repliesContainer.classList.add('replies');
        
            commentDiv.appendChild(replyBtn);
            commentDiv.appendChild(repliesContainer);
            commentDiv.style.border = "4px solid #333"; // Sets border width, style, and color
            commentDiv.style.borderRadius = "8px";      // Makes the corners rounded
            commentDiv.style.padding = "10px";          // Adds padding inside the border
            content.appendChild(commentDiv);
            

            // // Add event listener for reply button
            replyBtn.addEventListener('click', function() {
                 const commentId = this.dataset.commentId;
                 const repliesContainer = this.nextElementSibling;

            //     // Toggle reply input
            //     if (!repliesContainer.querySelector('.reply-input')) {
            //         const replyInput = document.createElement('input');
            //         replyInput.type = 'text';
            //         replyInput.placeholder = 'Write a reply...';
            //         replyInput.classList.add('reply-input');
            //         replyInput.addEventListener('keypress', function(e) {
            //             if (e.key === 'Enter') {
            //                 const replyContent = this.value.trim();
            //                 const name = currentImageId;
                            
            //                 if (replyContent) {
            //                     postReply(commentId, replyContent, name, repliesContainer);
            //                     this.value = '';
            //                 }
            //             }
            //         });

            //         const replySubmit = document.createElement('button');
            //         replySubmit.textContent = 'Post Reply';
            //         replySubmit.classList.add('reply-submit');
                  
            //         repliesContainer.appendChild(replyInput);
            //         repliesContainer.appendChild(replySubmit);

            //         replySubmit.addEventListener('click', function() {
            //             const replyContent = replyInput.value.trim();
            //             const name = currentImageId; // Replace with session/user data if needed

            //             if (replyContent) {
            //                 postReply(commentId, replyContent, name, repliesContainer);
            //                 replyInput.value = '';
            //             }
            //         });
            //     }

                // Check if replies are already loaded
                if (repliesContainer.querySelectorAll('.reply').length === 0) {
                    fetchReplies(commentId, repliesContainer);
                } else {
                    // Toggle visibility of replies if already loaded
                    repliesContainer.classList.toggle('show');
                }
            });
        });

        // Show the content
        content.classList.add('show');

        // Toggle the button icon
        const icon = button.querySelector('i');
        if (icon.classList.contains('fa-caret-down')) {
            icon.classList.remove('fa-caret-down');
            icon.classList.add('fa-caret-up');
        } else {
            icon.classList.remove('fa-caret-up');
            icon.classList.add('fa-caret-down');
            content.innerHTML = '';
        }
    })
    .catch(error => console.error('Error fetching comments:', error));
}

// New function to post a comment
function postComment(imageId, commentContent) {    
    fetch(CONFIG.API_BASE + 'post_comment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `imageId=${encodeURIComponent(imageId)}&content=${encodeURIComponent(commentContent)}&name=${name}`,
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Find the comment content container for this image
            const container = document.querySelector(`.dropdown-btn[data-image-id="${imageId}"]`).nextElementSibling;
            
            // If the comments section is visible, add the new comment
            if (container.classList.contains('show')) {
                const commentDiv = document.createElement('div');
                commentDiv.classList.add('comment');
    
                // Create the paragraph but don't set text yet
                const commentText = document.createElement('p');
                commentText.style.whiteSpace = "pre-line";
                commentText.style.margin = "0 0 1em 0"; // Keep bottom margin for spacing with next element
    
                // Create username span with bold, smaller styling
                const usernameSpan = document.createElement('span');
                usernameSpan.textContent = data.comment.username + " " + formatTimestamp(data.comment.createdAt);
                usernameSpan.style.fontWeight = 'bold';
                usernameSpan.style.fontSize = '0.85rem';
                usernameSpan.style.display = 'block'; // Makes it act like a block element
                usernameSpan.style.marginRight = '5px'; 
                // Create comment span with larger styling
                const commentSpan = document.createElement('span');
                commentSpan.textContent = data.comment.comment;
                commentSpan.style.fontSize = '1rem'; 
    
                // Add both spans to the paragraph
                commentText.appendChild(usernameSpan);
                commentText.appendChild(document.createTextNode('\n')); // Keep the newline
                commentText.appendChild(commentSpan);
    
                // Add the paragraph to the comment div
                commentDiv.appendChild(commentText);
                const replyBtn = document.createElement('button');
                replyBtn.classList.add('reply-btn');
                replyBtn.textContent = 'Reply';
                replyBtn.dataset.commentId = data.comment.id;
            
                const repliesContainer = document.createElement('div');
                repliesContainer.classList.add('replies');
            
                commentDiv.appendChild(replyBtn);
                commentDiv.appendChild(repliesContainer);
                commentDiv.style.border = "4px solid #333"; // Sets border width, style, and color
                commentDiv.style.borderRadius = "8px";      // Makes the corners rounded
                commentDiv.style.padding = "10px";          // Adds padding inside the border
                container.appendChild(commentDiv);
                 
                // Add event listener for the new reply button
                replyBtn.addEventListener('click', function() {
                    const commentId = this.dataset.commentId;
                    const repliesContainer = this.nextElementSibling;
                    
                    // Check if replies are already loaded
                    if (repliesContainer.innerHTML === '') {
                        fetchReplies(commentId, repliesContainer);
                    } else {
                        // Toggle visibility of replies if already loaded
                        repliesContainer.classList.toggle('show');
                    }
                });
            }
        } else {
            console.error('Error posting comment:', data.message);
        }
    })
    .catch(error => console.error('Error posting comment:', error));
}

// Function to post a reply
function postReply(commentId, content, name, container) {
    fetch(CONFIG.API_BASE + 'post_replies.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `commentId=${commentId}&content=${encodeURIComponent(content)}&name=${encodeURIComponent(name)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            fetchReplies(commentId, container);
        } else {
            console.error('Error posting reply:', data.message);
        }
    })
    .catch(error => console.error('Error posting reply:', error));
}

function formatTimestamp(createdAt) {
    // Parse the timestamp string into a Date object
    const commentDate = new Date(createdAt);
    const today = new Date();
    
    // Reset time part for accurate day comparison
    const commentDay = new Date(commentDate.getFullYear(), commentDate.getMonth(), commentDate.getDate());
    const todayDay = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    
    // Calculate the difference in days
    const diffTime = todayDay - commentDay;
    const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
    
    if (diffDays === 0) {
      return "Today";
    } else if (diffDays === 1) {
      return "Yesterday";
    } else {
      return `${diffDays} days ago`;
    }
}

function fetchReplies(commentId, container) {
    fetch(CONFIG.SRC_BASE + 'fetch_replies.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `commentId=${commentId}`,
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! Status: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        // Remove existing replies (keep input box)
        container.querySelectorAll('.reply').forEach(replyEl => replyEl.remove());
        data.reverse().forEach(reply => {
            const replyDiv = document.createElement('div');
            replyDiv.classList.add('reply');
            // Add border and styling to the reply div
            replyDiv.style.border = "1px solid #e0e0e0";
            replyDiv.style.borderRadius = "8px";
            replyDiv.style.padding = "10px";
    
            console.log(`Reply from ${reply.Username}: ${reply.Content}`);
            
            // Create paragraph with styles
            const replyContent = document.createElement('p');
            replyContent.style.whiteSpace = "pre-line";
            replyContent.style.margin = "0px 0px 1em";
            
            // Create username/timestamp span with bold formatting
            const userSpan = document.createElement('span');
            userSpan.style.fontWeight = "bold";
            userSpan.style.fontSize = "0.85rem";
            userSpan.style.display = "block";
            userSpan.style.marginRight = "5px";
            userSpan.textContent = `${reply.Username} ${formatTimestamp(reply.CreatedAt)}`;
            
            // Create content span
            const contentSpan = document.createElement('span');
            contentSpan.style.fontSize = "1rem";
            contentSpan.textContent = reply.Content;
            
            // Append spans to paragraph
            replyContent.appendChild(userSpan);
            replyContent.appendChild(document.createTextNode('\n')); // Add newline between spans
            replyContent.appendChild(contentSpan);
            
            // Append paragraph to the reply div
            replyDiv.appendChild(replyContent);
            
            // Use appendChild since data is already reversed
            container.appendChild(replyDiv);
        });
    })
    .catch(error => console.error('Error fetching replies:', error));
    // Remove any existing input box (to re-append at the bottom)
    const oldInput = container.querySelector('.reply-input-container');
    if (oldInput) oldInput.remove();

    // Add input box at the bottom
    const replyInput = document.createElement('input');
    replyInput.type = 'text';
    replyInput.placeholder = 'Write a reply...';
    replyInput.classList.add('reply-input');
    replyInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const replyContent = this.value.trim();
            if (replyContent) {
                postReply(commentId, replyContent, currentImageId, container);
                this.value = '';
            }
        }
    });

    const replySubmit = document.createElement('button');
    replySubmit.textContent = 'Post Reply';
    replySubmit.classList.add('reply-submit');

    const replyInputContainer = document.createElement('div');
    replyInputContainer.classList.add('reply-input-container');
    replyInputContainer.appendChild(replyInput);
    replyInputContainer.appendChild(replySubmit);

    container.appendChild(replyInputContainer);

    // Add submit event (adjust your postReply function if needed)
    replySubmit.addEventListener('click', function() {
        const replyContent = replyInput.value.trim();
        if (replyContent) {
            postReply(commentId, replyContent, currentImageId, container);
            replyInput.value = '';
        }
    });

    // Always show the replies after fetching
    container.classList.add('show');
}

