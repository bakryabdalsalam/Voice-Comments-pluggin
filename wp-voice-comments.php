<?php
/**
 * Plugin Name: WP Voice Comments
 * Description: Allow users to record and attach voice comments to WordPress comments. Audio is stored in the Media Library.
 * Version:     1.0
 * Author:      Bakry
 * Text Domain: wp-voice-comments
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct file access
}

/**
 * Enqueue scripts & styles
 */
function wvc_enqueue_scripts() {
    // Front-end JS (for recording & uploading)
    wp_enqueue_script(
        'wvc-voice-comments',
        plugin_dir_url(__FILE__) . 'assets/voice-comments.js',
        array('jquery'),
        '1.0',
        true
    );

    // Localize so JS knows our admin-ajax.php URL
    wp_localize_script('wvc-voice-comments', 'wvcData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ));

    // CSS
    wp_enqueue_style(
        'wvc-voice-comments',
        plugin_dir_url(__FILE__) . 'assets/voice-comments.css',
        array(),
        '1.0'
    );
}
add_action('wp_enqueue_scripts', 'wvc_enqueue_scripts');

/**
 * Render the voice recorder UI inside the WordPress comment form.
 * We hook into both 'comment_form_logged_in_after' and 'comment_form_after_fields'
 * to cover logged-in/logged-out scenarios.
 */
function wvc_add_recorder_ui() {
    ?>
    <div id="wvc-recorder-container">
        <button id="wvc-start-record" type="button">
            <?php esc_html_e('Start Recording', 'wp-voice-comments'); ?>
        </button>
        <button id="wvc-stop-record" type="button" disabled>
            <?php esc_html_e('Stop Recording', 'wp-voice-comments'); ?>
        </button>

        <div id="wvc-playback"></div>
    </div>
    <?php
}
add_action('comment_form_logged_in_after', 'wvc_add_recorder_ui');
add_action('comment_form_after_fields', 'wvc_add_recorder_ui');

/**
 * Handle the AJAX upload of the recorded audio.
 * 1. Receive the file from the browser (audio blob).
 * 2. Use wp_handle_upload() to put it in /uploads/ but do not finalize yet.
 * 3. Create an attachment with wp_insert_attachment() so it appears in Media Library.
 * 4. Return the attachment ID (and URL) to the front end.
 */
function wvc_handle_audio_upload() {
    // Security check: typically you'd verify nonces, etc. Minimal check here.
    if (!isset($_FILES['voice_comment'])) {
        wp_send_json_error(array('message' => 'No audio file received.'));
    }

    // Use wp_handle_upload to store the file
    $file = $_FILES['voice_comment'];
    $upload_overrides = array('test_form' => false);
    $uploaded_file = wp_handle_upload($file, $upload_overrides);

    if (isset($uploaded_file['error'])) {
        wp_send_json_error(array('message' => $uploaded_file['error']));
    }

    // At this point, $uploaded_file has 'file', 'url', 'type'
    $file_url  = $uploaded_file['url'];
    $file_path = $uploaded_file['file'];
    $file_type = $uploaded_file['type'];

    // Construct a name for the attachment post title
    $filename = basename($file_path);

    // Prepare attachment data
    $attachment = array(
        'guid'           => $file_url,
        'post_mime_type' => $file_type,
        'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
        'post_content'   => '',
        'post_status'    => 'inherit',
    );

    // Insert attachment into the Media Library
    $attach_id = wp_insert_attachment($attachment, $file_path);

    if (is_wp_error($attach_id) || !$attach_id) {
        wp_send_json_error(array('message' => 'Failed to create attachment.'));
    }

    // Generate metadata (like length, etc.) for the attachment
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $file_path));

    // Return the attachment ID and URL
    wp_send_json_success(array(
        'attachment_id' => $attach_id,
        'url'           => $file_url,
    ));
}
add_action('wp_ajax_wvc_upload_audio', 'wvc_handle_audio_upload');
add_action('wp_ajax_nopriv_wvc_upload_audio', 'wvc_handle_audio_upload');

/**
 * When the comment is actually posted, if we have an attachment ID in $_POST,
 * store it in comment meta. That way, we know which audio file is associated
 * with which comment.
 */
function wvc_save_comment_audio($comment_id) {
    if (!empty($_POST['wvc_attachment_id'])) {
        $attachment_id = intval($_POST['wvc_attachment_id']);
        add_comment_meta($comment_id, 'wvc_audio_attachment_id', $attachment_id);
    }
}
add_action('comment_post', 'wvc_save_comment_audio');

/**
 * Show the audio player in the posted comment (front end).
 * We do this by filtering the comment text.
 */
function wvc_display_comment_audio($comment_text, $comment) {
    $attachment_id = get_comment_meta($comment->comment_ID, 'wvc_audio_attachment_id', true);
    if ($attachment_id) {
        // Get the attachment’s URL
        $audio_url = wp_get_attachment_url($attachment_id);
        if ($audio_url) {
            // Append an audio player to the text
            $comment_text .= '<div class="wvc-comment-audio">';
            $comment_text .= sprintf(
                '<audio controls src="%s"></audio>',
                esc_url($audio_url)
            );
            $comment_text .= '</div>';
        }
    }
    return $comment_text;
}
add_filter('comment_text', 'wvc_display_comment_audio', 10, 2);

/* --------------------------------------------------------
   OPTIONAL: Like/Dislike for Voice Comments
   -------------------------------------------------------- */

/**
 * Add like/dislike buttons if the comment has a voice attachment.
 */
function wvc_add_like_dislike_buttons($comment_text, $comment) {
    $attachment_id = get_comment_meta($comment->comment_ID, 'wvc_audio_attachment_id', true);
    if ($attachment_id) {
        $likes    = (int) get_comment_meta($comment->comment_ID, 'wvc_voice_likes', true);
        $dislikes = (int) get_comment_meta($comment->comment_ID, 'wvc_voice_dislikes', true);

        ob_start();
        ?>
        <div class="wvc-voice-reactions">
            <button class="wvc-voice-like" data-cid="<?php echo esc_attr($comment->comment_ID); ?>">
                👍 <?php echo $likes; ?>
            </button>
            <button class="wvc-voice-dislike" data-cid="<?php echo esc_attr($comment->comment_ID); ?>">
                👎 <?php echo $dislikes; ?>
            </button>
        </div>
        <?php
        $comment_text .= ob_get_clean();
    }
    return $comment_text;
}
add_filter('comment_text', 'wvc_add_like_dislike_buttons', 11, 2);

/**
 * AJAX handler for like/dislike
 */
function wvc_ajax_voice_reaction() {
    $comment_id = isset($_POST['comment_id']) ? intval($_POST['comment_id']) : 0;
    $reaction   = isset($_POST['reaction']) ? sanitize_text_field($_POST['reaction']) : '';

    if (!$comment_id || !in_array($reaction, array('like', 'dislike'), true)) {
        wp_send_json_error('Invalid data');
    }

    if ($reaction === 'like') {
        $likes = (int) get_comment_meta($comment_id, 'wvc_voice_likes', true);
        update_comment_meta($comment_id, 'wvc_voice_likes', $likes + 1);
    } else {
        // dislike
        $dislikes = (int) get_comment_meta($comment_id, 'wvc_voice_dislikes', true);
        update_comment_meta($comment_id, 'wvc_voice_dislikes', $dislikes + 1);
    }

    wp_send_json_success();
}
add_action('wp_ajax_wvc_voice_reaction', 'wvc_ajax_voice_reaction');
add_action('wp_ajax_nopriv_wvc_voice_reaction', 'wvc_ajax_voice_reaction');
