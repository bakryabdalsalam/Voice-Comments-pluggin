let mediaRecorder;
let audioChunks = [];

document.getElementById('start-record').addEventListener('click', async () => {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    mediaRecorder = new MediaRecorder(stream);
    mediaRecorder.start();

    document.getElementById('start-record').disabled = true;
    document.getElementById('stop-record').disabled = false;

    mediaRecorder.ondataavailable = (event) => {
        audioChunks.push(event.data);
    };

    mediaRecorder.onstop = () => {
        const audioBlob = new Blob(audioChunks, { type: 'audio/wav' });
        const audioUrl = URL.createObjectURL(audioBlob);

        const audio = new Audio(audioUrl);
        audio.controls = true;
        document.getElementById('audio-playback').appendChild(audio);

        const formData = new FormData();
        formData.append('voice_comment', audioBlob, 'comment.wav');
        fetch('/wp-admin/admin-ajax.php?action=upload_voice_comment', {
            method: 'POST',
            body: formData,
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'voice_comment_url';
                input.value = data.url;
                document.getElementById('commentform').appendChild(input);
            }
        });

        audioChunks = [];
        document.getElementById('start-record').disabled = false;
        document.getElementById('stop-record').disabled = true;
    };
});

document.getElementById('stop-record').addEventListener('click', () => {
    mediaRecorder.stop();
});