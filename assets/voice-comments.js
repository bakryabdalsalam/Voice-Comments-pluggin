/**
 * Handles the browser-based recording and uploading of voice comments.
 * - We rely on MediaRecorder, so only modern browsers and HTTPS/localhost.
 */

let mediaRecorder = null;
let audioChunks = [];

document.addEventListener('DOMContentLoaded', function() {
    const startBtn    = document.getElementById('wvc-start-record');
    const stopBtn     = document.getElementById('wvc-stop-record');
    const playbackDiv = document.getElementById('wvc-playback');
    const commentForm = document.getElementById('commentform');

    // If the elements aren't found, exit.
    if (!startBtn || !stopBtn || !playbackDiv || !commentForm) {
        return;
    }

    // Start Recording
    startBtn.addEventListener('click', async () => {
        try {
            // Reset
            audioChunks = [];
            playbackDiv.innerHTML = '';

            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream, {
                // mimeType: 'audio/webm; codecs=opus' // recommended for cross-browser
            });

            mediaRecorder.start();
            startBtn.disabled = true;
            stopBtn.disabled  = false;

            mediaRecorder.ondataavailable = (e) => {
                audioChunks.push(e.data);
            };

            mediaRecorder.onstop = () => {
                // Turn the recorded chunks into a Blob
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                const audioUrl  = URL.createObjectURL(audioBlob);

                // Add an <audio> element for playback
                const audioEl = document.createElement('audio');
                audioEl.controls = true;
                audioEl.src = audioUrl;
                playbackDiv.appendChild(audioEl);

                // Upload to server via AJAX
                const formData = new FormData();
                formData.append('action', 'wvc_upload_audio');
                formData.append('voice_comment', audioBlob, 'comment.webm');

                fetch(wvcData.ajaxUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        // We have an attachment ID and a URL
                        const attachId = result.data.attachment_id;

                        // Place the attachId into a hidden field so that
                        // when the user clicks the normal "Submit Comment",
                        // our plugin can link that audio to the comment.
                        let hiddenField = document.querySelector('input[name="wvc_attachment_id"]');
                        if (!hiddenField) {
                            hiddenField = document.createElement('input');
                            hiddenField.type = 'hidden';
                            hiddenField.name = 'wvc_attachment_id';
                            commentForm.appendChild(hiddenField);
                        }
                        hiddenField.value = attachId;

                    } else {
                        alert('Error uploading audio: ' + (result.data.message || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error('Upload error:', err);
                    alert('Failed to upload the voice comment. Check console for details.');
                });

                startBtn.disabled = false;
                stopBtn.disabled  = true;
            };

        } catch (error) {
            console.error('Could not start recording:', error);
            alert('Could not start recording. Check HTTPS or microphone permissions.');
        }
    });

    // Stop Recording
    stopBtn.addEventListener('click', () => {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
        }
    });


    /*********************************************
     * Optional: Like/Dislike Reaction Handling
     *********************************************/
    // We attach delegated event listeners for buttons
    document.addEventListener('click', function(e) {
        // Handle clicks on .wvc-voice-like or .wvc-voice-dislike
        if (e.target.classList.contains('wvc-voice-like') || e.target.classList.contains('wvc-voice-dislike')) {
            const btn = e.target;
            const commentId = btn.getAttribute('data-cid');
            const reaction = btn.classList.contains('wvc-voice-like') ? 'like' : 'dislike';

            // Send to AJAX
            const data = new FormData();
            data.append('action', 'wvc_voice_reaction');
            data.append('comment_id', commentId);
            data.append('reaction', reaction);

            fetch(wvcData.ajaxUrl, {
                method: 'POST',
                body: data
            })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    // Increase numeric count in button text
                    const oldText = btn.innerText; // e.g. "👍 3"
                    const numMatch = oldText.match(/\d+/);
                    let count = numMatch ? parseInt(numMatch[0], 10) : 0;
                    count++;
                    if (reaction === 'like') {
                        btn.innerText = '👍 ' + count;
                    } else {
                        btn.innerText = '👎 ' + count;
                    }
                } else {
                    alert('Failed to update reaction: ' + response.data);
                }
            })
            .catch(() => {
                alert('Error communicating with server.');
            });
        }
    });
});
