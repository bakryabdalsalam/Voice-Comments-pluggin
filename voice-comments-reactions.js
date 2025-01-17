jQuery(document).ready(function($) {
    $('.voice-comment-like, .voice-comment-dislike').on('click', function() {
        var button = $(this);
        var commentId = button.data('comment-id');
        var reaction = button.hasClass('voice-comment-like') ? 'like' : 'dislike';

        $.ajax({
            url: '/wp-admin/admin-ajax.php',
            method: 'POST',
            data: {
                action: 'voice_comment_reaction',
                comment_id: commentId,
                reaction: reaction,
            },
            success: function(response) {
                if (response.success) {
                    var count = parseInt(button.text().match(/\d+/)[0]);
                    button.text(reaction === 'like' ? '👍 ' + (count + 1) : '👎 ' + (count + 1));
                }
            },
        });
    });
});