<?php
/**
 * Template: Questionnaire Session Options
 *
 * Displayed when user has an existing in-progress session
 * Gives option to Resume or Start Over
 *
 * Variables available:
 * - $questionnaire: Array of questionnaire data
 * - $session: Array of session data
 * - $atts: Shortcode attributes
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Calculate progress
$total_answers = !empty($session['answers']) ? count(json_decode($session['answers'], true)) : 0;
$session_date = !empty($session['created_at']) ? date('F j, Y g:i A', strtotime($session['created_at'])) : 'Unknown';
?>

<div class="questionnaire-container questionnaire-session-options">

    <!-- Header -->
    <div class="questionnaire-header">
        <h2 class="questionnaire-title"><?php echo esc_html($questionnaire['name']); ?></h2>

        <?php if (!empty($questionnaire['description'])): ?>
            <p class="questionnaire-description"><?php echo esc_html($questionnaire['description']); ?></p>
        <?php endif; ?>
    </div>

    <!-- Session Found Notice -->
    <div class="session-options-notice">
        <div class="notice-icon">
            <span class="dashicons dashicons-backup"></span>
        </div>
        <div class="notice-content">
            <h3>We Found Your Previous Session</h3>
            <p>You started this questionnaire on <strong><?php echo esc_html($session_date); ?></strong>.</p>

            <?php if ($total_answers > 0): ?>
                <p>You've answered <strong><?php echo esc_html($total_answers); ?> question<?php echo $total_answers !== 1 ? 's' : ''; ?></strong> so far.</p>
            <?php endif; ?>

            <?php if (!empty($session['conference'])): ?>
                <p>Conference: <strong><?php echo esc_html($session['conference']); ?></strong></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Options -->
    <div class="session-options-actions">
        <h3>What would you like to do?</h3>

        <div class="session-option-cards">
            <!-- Resume Option -->
            <div class="session-option-card">
                <div class="option-icon">
                    <span class="dashicons dashicons-controls-play"></span>
                </div>
                <h4>Resume Where You Left Off</h4>
                <p>Continue from where you stopped. Your previous answers will be preserved.</p>
                <a href="<?php echo esc_url(add_query_arg('resume_session', '1')); ?>" class="btn btn-primary btn-large">
                    <span class="dashicons dashicons-controls-play"></span>
                    Resume Session
                </a>
            </div>

            <!-- Start Over Option -->
            <div class="session-option-card">
                <div class="option-icon">
                    <span class="dashicons dashicons-update"></span>
                </div>
                <h4>Start Over</h4>
                <p>Begin a fresh questionnaire. Your previous session will be replaced.</p>
                <a href="<?php echo esc_url(add_query_arg('clear_session', '1')); ?>" class="btn btn-secondary btn-large">
                    <span class="dashicons dashicons-update"></span>
                    Start New Session
                </a>
            </div>
        </div>
    </div>

</div>

<style>
/* Session Options Styling */
.questionnaire-session-options {
    max-width: 800px;
    margin: 0 auto;
}

.session-options-notice {
    display: flex;
    gap: 20px;
    background: #e7f3ff;
    border: 2px solid #0073aa;
    border-radius: 8px;
    padding: 30px;
    margin: 30px 0;
}

.session-options-notice .notice-icon {
    flex-shrink: 0;
}

.session-options-notice .notice-icon .dashicons {
    width: 48px;
    height: 48px;
    font-size: 48px;
    color: #0073aa;
}

.session-options-notice .notice-content h3 {
    margin-top: 0;
    color: #0073aa;
    font-size: 1.4em;
}

.session-options-notice .notice-content p {
    margin: 10px 0;
    font-size: 1.05em;
    line-height: 1.6;
}

.session-options-actions {
    margin: 40px 0;
}

.session-options-actions h3 {
    text-align: center;
    font-size: 1.3em;
    margin-bottom: 30px;
    color: #2c3338;
}

.session-option-cards {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-top: 20px;
}

.session-option-card {
    background: #fff;
    border: 2px solid #dcdcde;
    border-radius: 8px;
    padding: 30px;
    text-align: center;
    transition: all 0.3s ease;
}

.session-option-card:hover {
    border-color: #0073aa;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.session-option-card .option-icon .dashicons {
    width: 64px;
    height: 64px;
    font-size: 64px;
    color: #0073aa;
    margin-bottom: 15px;
}

.session-option-card h4 {
    font-size: 1.3em;
    margin: 15px 0;
    color: #2c3338;
}

.session-option-card p {
    font-size: 1.05em;
    line-height: 1.6;
    color: #50575e;
    margin-bottom: 25px;
    min-height: 3em;
}

.session-option-card .btn {
    width: 100%;
}

/* Responsive */
@media (max-width: 768px) {
    .session-options-notice {
        flex-direction: column;
        text-align: center;
        padding: 20px;
    }

    .session-option-cards {
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .session-option-card {
        padding: 20px;
    }
}
</style>
