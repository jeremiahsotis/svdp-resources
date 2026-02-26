<?php
/**
 * Template: Questionnaire Question
 *
 * Variables available:
 * - $question: Array of question data
 * - $answer_options: Array of answer options (for multiple_choice/yes_no)
 * - $session: Session data (optional)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$question_type = $question['question_type'];
$is_required = $question['required'];
?>

<div class="questionnaire-question" data-question-id="<?php echo esc_attr($question['id']); ?>" data-question-type="<?php echo esc_attr($question_type); ?>">

    <!-- Question Text -->
    <div class="question-content">
        <h3 class="question-text">
            <?php echo esc_html($question['question_text']); ?>
            <?php if ($is_required): ?>
                <span class="required-indicator" aria-label="Required">*</span>
            <?php endif; ?>
        </h3>

        <?php if (!empty($question['help_text'])): ?>
            <p class="question-help-text"><?php echo esc_html($question['help_text']); ?></p>
        <?php endif; ?>
    </div>

    <!-- Answer Section -->
    <div class="question-answers">

        <?php if ($question_type === 'multiple_choice'): ?>
            <!-- Multiple Choice -->
            <div class="answer-options answer-options-multiple-choice">
                <?php foreach ($answer_options as $index => $option): ?>
                    <label class="answer-option">
                        <input type="radio"
                               name="answer"
                               value="<?php echo esc_attr($option['id']); ?>"
                               id="option-<?php echo esc_attr($option['id']); ?>"
                               <?php echo $is_required ? 'required' : ''; ?>
                               aria-describedby="help-text-<?php echo esc_attr($option['id']); ?>">
                        <span class="answer-text"><?php echo esc_html($option['answer_text']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

        <?php elseif ($question_type === 'yes_no'): ?>
            <!-- Yes/No -->
            <div class="answer-options answer-options-yes-no">
                <?php foreach ($answer_options as $option): ?>
                    <button type="button"
                            class="btn btn-answer btn-<?php echo strtolower($option['answer_text']); ?>"
                            data-answer-option-id="<?php echo esc_attr($option['id']); ?>"
                            aria-label="<?php echo esc_attr($option['answer_text']); ?>">
                        <?php echo esc_html($option['answer_text']); ?>
                    </button>
                <?php endforeach; ?>
            </div>

        <?php elseif ($question_type === 'text'): ?>
            <!-- Text Input -->
            <div class="answer-text-input">
                <label for="text-answer" class="screen-reader-text">Your Answer</label>
                <textarea id="text-answer"
                          name="text_answer"
                          class="text-answer-field"
                          rows="4"
                          placeholder="Enter your answer here..."
                          <?php echo $is_required ? 'required' : ''; ?>
                          aria-describedby="text-answer-help"></textarea>
                <p id="text-answer-help" class="help-text">
                    Share as much or as little as you're comfortable with.
                </p>
            </div>

        <?php elseif ($question_type === 'info_only'): ?>
            <!-- Information Only (no answer needed) -->
            <div class="answer-info-only">
                <p class="info-message">
                    This is informational. Click "Continue" when you're ready to proceed.
                </p>
            </div>

        <?php endif; ?>

    </div>

    <!-- Action Buttons -->
    <div class="question-actions">
        <?php if ($question_type === 'multiple_choice' || $question_type === 'text'): ?>
            <button type="button"
                    class="btn btn-primary btn-large btn-submit-answer"
                    <?php if ($question_type === 'multiple_choice'): ?>
                        disabled
                    <?php endif; ?>
                    aria-label="Continue to next question">
                <span class="btn-text">Continue</span>
                <span class="btn-loading" style="display: none;">
                    <span class="spinner"></span> Loading...
                </span>
            </button>
        <?php elseif ($question_type === 'info_only'): ?>
            <button type="button"
                    class="btn btn-primary btn-large btn-continue"
                    aria-label="Continue">
                <span class="btn-text">Continue</span>
                <span class="btn-loading" style="display: none;">
                    <span class="spinner"></span> Loading...
                </span>
            </button>
        <?php endif; ?>

        <!-- Skip button for optional questions -->
        <?php if (!$is_required && in_array($question_type, array('text', 'multiple_choice'))): ?>
            <button type="button" class="btn btn-secondary btn-skip" aria-label="Skip this question">
                Skip This Question
            </button>
        <?php endif; ?>
    </div>

    <!-- Error Message Container -->
    <div class="question-error" style="display: none;" role="alert" aria-live="polite"></div>
</div>

<style>
/* Question Display Styles */
.questionnaire-question {
    background: #ffffff;
    padding: 30px;
    border-radius: 8px;
    border: 1px solid #ddd;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.question-content {
    margin-bottom: 30px;
}

.question-text {
    font-size: 1.5em;
    color: #2c3e50;
    margin: 0 0 15px 0;
    font-weight: 600;
    line-height: 1.4;
}

.required-indicator {
    color: #d32f2f;
    font-weight: bold;
    margin-left: 4px;
}

.question-help-text {
    font-size: 1.05em;
    color: #666;
    margin: 0;
    line-height: 1.6;
    font-style: italic;
}

/* Answer Options */
.question-answers {
    margin-bottom: 30px;
}

/* Multiple Choice Answers */
.answer-options-multiple-choice {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.answer-option {
    display: flex;
    align-items: center;
    padding: 18px 20px;
    background: #f8f9fa;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    min-height: 60px; /* Touch target */
    font-size: 1.1em;
}

.answer-option:hover {
    background: #e8f5e9;
    border-color: #4CAF50;
}

.answer-option:has(input:checked) {
    background: #e8f5e9;
    border-color: #4CAF50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
}

.answer-option input[type="radio"] {
    width: 24px;
    height: 24px;
    margin-right: 15px;
    cursor: pointer;
    flex-shrink: 0;
}

.answer-option .answer-text {
    flex: 1;
    color: #2c3e50;
}

/* Yes/No Buttons */
.answer-options-yes-no {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.btn-answer {
    padding: 20px;
    font-size: 1.3em;
    font-weight: 600;
    min-height: 80px;
}

.btn-yes {
    background: #4CAF50;
    color: white;
}

.btn-yes:hover {
    background: #45a049;
}

.btn-no {
    background: #f44336;
    color: white;
}

.btn-no:hover {
    background: #da190b;
}

/* Text Input */
.text-answer-field {
    width: 100%;
    padding: 15px;
    font-size: 1.1em;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-family: inherit;
    line-height: 1.6;
    transition: border-color 0.3s ease;
}

.text-answer-field:focus {
    outline: none;
    border-color: #4CAF50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
}

/* Info Only */
.answer-info-only {
    padding: 20px;
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
    border-radius: 4px;
}

.info-message {
    margin: 0;
    color: #1565c0;
    font-size: 1.05em;
}

/* Action Buttons */
.question-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.btn-submit-answer:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
}

.btn-skip {
    font-size: 0.95em;
}

/* Error Messages */
.question-error {
    background: #ffebee;
    border: 1px solid #f44336;
    color: #c62828;
    padding: 15px;
    border-radius: 6px;
    margin-top: 15px;
}

/* Mobile Responsive */
@media (max-width: 600px) {
    .questionnaire-question {
        padding: 20px;
    }

    .question-text {
        font-size: 1.3em;
    }

    .answer-option {
        padding: 15px;
        font-size: 1em;
    }

    .answer-options-yes-no {
        grid-template-columns: 1fr;
    }

    .btn-answer {
        min-height: 60px;
        font-size: 1.1em;
    }
}
</style>
