<?php
/**
 * Template: Questionnaire Progress Indicator
 *
 * Variables available:
 * - $questions_answered: Number of questions answered
 * - $session: Session data (optional)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$questions_answered = isset($questions_answered) ? intval($questions_answered) : 0;
?>

<div class="questionnaire-progress" role="status" aria-live="polite">
    <div class="progress-container">
        <div class="progress-text">
            <span class="progress-label">Progress:</span>
            <span class="progress-count">
                <strong><?php echo $questions_answered; ?></strong>
                <?php echo $questions_answered === 1 ? 'question' : 'questions'; ?> answered
            </span>
        </div>

        <!-- Indeterminate progress bar (we don't know total questions due to branching) -->
        <div class="progress-bar-container">
            <div class="progress-bar">
                <div class="progress-bar-fill"></div>
            </div>
        </div>

        <p class="progress-note">
            We're learning about your needs. Answer as many questions as you're comfortable with.
        </p>
    </div>
</div>

<style>
/* Progress Indicator Styles */
.questionnaire-progress {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #e0e0e0;
}

.progress-container {
    max-width: 100%;
}

.progress-text {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 8px;
}

.progress-label {
    font-weight: 600;
    color: #2c3e50;
    font-size: 1.05em;
}

.progress-count {
    color: #666;
    font-size: 1em;
}

.progress-count strong {
    color: #4CAF50;
    font-size: 1.2em;
}

.progress-bar-container {
    margin-bottom: 10px;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #4CAF50, #66BB6A);
    border-radius: 4px;
    transition: width 0.4s ease;
    position: relative;
    overflow: hidden;
}

/* Animated shimmer effect for indeterminate progress */
.progress-bar-fill::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: shimmer 2s infinite;
}

@keyframes shimmer {
    0% {
        left: -100%;
    }
    100% {
        left: 100%;
    }
}

.progress-note {
    margin: 0;
    font-size: 0.9em;
    color: #777;
    font-style: italic;
    text-align: center;
}

/* Mobile Responsive */
@media (max-width: 600px) {
    .questionnaire-progress {
        padding: 15px;
    }

    .progress-text {
        flex-direction: column;
        align-items: flex-start;
    }

    .progress-label {
        font-size: 1em;
    }

    .progress-count {
        font-size: 0.95em;
    }
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .progress-bar-fill {
        transition: none;
    }

    .progress-bar-fill::after {
        animation: none;
    }
}
</style>
