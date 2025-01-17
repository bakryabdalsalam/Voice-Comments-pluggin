<?php
/*
Plugin Name: Voice Comments
Description: Allow users to submit voice comments on your WordPress posts.
Version: 1.0
Author: Bakry Abdelsalam
*/

// Enqueue scripts and styles
function voice_comments_scripts() {
    wp_enqueue_script('voice-comments', plugin_dir_url(__FILE__) . 'voice-comments.js', array(), '1.0', true);
    wp_enqueue_script('voice-comments-reactions', plugin_dir_url(__FILE__) . 'voice-comments-reactions.js', array('jquery'), '1.0', true);
    wp_enqueue_style('voice-comments', plugin_dir_url(__FILE__) . 'voice-comments.css');
}
add_action('wp_enqueue_scripts', 'voice_comments_scripts');

// Add voice comment form to the comment section
function voice_comment_form() {
    echo '
    <div id="voice-comment-container">
        <button id="start-record">' . __('Start Recording', 'voice-comments') . '</button>
        <button id="stop-record" disabled>' . __('Stop Recording', 'voice-comments') . '</button>
        <div id="audio-playback"></div>
    </div>
    ';
}
add_action('comment_form', 'voice_comment_form');

// Handle audio file upload via AJAX
function handle_voice_comment_upload() {
    if (!empty($_FILES['voice_comment'])) {
        $file = $_FILES['voice_comment'];
        $upload = wp_handle_upload($file, array('test_form' => false));
        if (isset($upload['file'])) {
            echo json_encode(array('status' => 'success', 'url' => $upload['url']));
        } else {
            echo json_encode(array('status' => 'error'));
        }
    }
    wp_die();
}
add_action('wp_ajax_upload_voice_comment', 'handle_voice_comment_upload');
add_action('wp_ajax_nopriv_upload_voice_comment', 'handle_voice_comment_upload');

// Save voice comment URL as comment meta
function save_voice_comment_meta($comment_id) {
    if (!empty($_POST['voice_comment_url'])) {
        add_comment_meta($comment_id, 'voice_comment_url', esc_url($_POST['voice_comment_url']));
    }
}
add_action('comment_post', 'save_voice_comment_meta');

// Display voice comments
function display_voice_comment($comment_text, $comment) {
    $voice_url = get_comment_meta($comment->comment_ID, 'voice_comment_url', true);
    if ($voice_url) {
        $comment_text .= '<audio src="' . esc_url($voice_url) . '" controls></audio>';
    }
    return $comment_text;
}
add_filter('comment_text', 'display_voice_comment', 10, 2);

// Add like/dislike buttons to voice comments
function add_reaction_buttons($comment_text, $comment) {
    $voice_url = get_comment_meta($comment->comment_ID, 'voice_comment_url', true);
    if ($voice_url) {
        $likes = get_comment_meta($comment->comment_ID, 'voice_comment_likes', true) ?: 0;
        $dislikes = get_comment_meta($comment->comment_ID, 'voice_comment_dislikes', true) ?: 0;

        $comment_text .= '
        <div class="voice-comment-reactions">
            <button class="voice-comment-like" data-comment-id="' . esc_attr($comment->comment_ID) . '">👍 ' . intval($likes) . '</button>
            <button class="voice-comment-dislike" data-comment-id="' . esc_attr($comment->comment_ID) . '">👎 ' . intval($dislikes) . '</button>
        </div>
        ';
    }
    return $comment_text;
}
add_filter('comment_text', 'add_reaction_buttons', 10, 2);

// Handle like/dislike reactions via AJAX
function handle_voice_comment_reaction() {
    if (!isset($_POST['comment_id']) || !isset($_POST['reaction'])) {
        wp_send_json_error('Invalid request');
    }

    $comment_id = intval($_POST['comment_id']);
    $reaction = sanitize_text_field($_POST['reaction']);

    if ($reaction === 'like') {
        $likes = get_comment_meta($comment_id, 'voice_comment_likes', true) ?: 0;
        update_comment_meta($comment_id, 'voice_comment_likes', $likes + 1);
    } elseif ($reaction === 'dislike') {
        $dislikes = get_comment_meta($comment_id, 'voice_comment_dislikes', true) ?: 0;
        update_comment_meta($comment_id, 'voice_comment_dislikes', $dislikes + 1);
    }

    wp_send_json_success();
}
add_action('wp_ajax_voice_comment_reaction', 'handle_voice_comment_reaction');
add_action('wp_ajax_nopriv_voice_comment_reaction', 'handle_voice_comment_reaction');

// Add leaderboard shortcode
function voice_comments_leaderboard_shortcode() {
    global $wpdb;

    $leaderboard = $wpdb->get_results("
        SELECT user_id, COUNT(*) AS comment_count, SUM(meta_value) AS like_count
        FROM $wpdb->comments
        LEFT JOIN $wpdb->commentmeta ON $wpdb->comments.comment_ID = $wpdb->commentmeta.comment_id AND meta_key = 'voice_comment_likes'
        WHERE meta_key = 'voice_comment_url'
        GROUP BY user_id
        ORDER BY comment_count DESC, like_count DESC
        LIMIT 10
    ");

    if (!$leaderboard) {
        return '<p>' . __('No voice comments found.', 'voice-comments') . '</p>';
    }

    ob_start();
    echo '<div class="voice-comments-leaderboard">';
    echo '<h2>' . __('Voice Comments Leaderboard', 'voice-comments') . '</h2>';
    echo '<table>';
    echo '<thead><tr><th>' . __('User', 'voice-comments') . '</th><th>' . __('Comments', 'voice-comments') . '</th><th>' . __('Likes', 'voice-comments') . '</th></tr></thead>';
    echo '<tbody>';
    foreach ($leaderboard as $user) {
        $user_info = get_userdata($user->user_id);
        echo '<tr>';
        echo '<td>' . esc_html($user_info->display_name) . '</td>';
        echo '<td>' . intval($user->comment_count) . '</td>';
        echo '<td>' . intval($user->like_count) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    return ob_get_clean();
}
add_shortcode('voice_comments_leaderboard', 'voice_comments_leaderboard_shortcode');