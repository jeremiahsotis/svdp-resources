<?php
/**
 * Template: Question Builder
 *
 * Variables available:
 * - $questionnaire: Array of questionnaire data
 * - $questions: Array of questions for this questionnaire
 * - $outcomes: Array of outcomes for this questionnaire
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap questionnaire-builder-wrap">
    <h1>
        <?php echo esc_html($questionnaire['name']); ?>
        <span style="font-size: 0.7em; font-weight: normal; color: #666;">- Question Builder</span>
    </h1>

    <a href="<?php echo admin_url('admin.php?page=questionnaires-edit&id=' . $questionnaire['id']); ?>"
       class="page-title-action">
        &larr; Back to Questionnaire Settings
    </a>

    <?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
        <div class="notice notice-success is-dismissible">
            <p>Changes saved successfully.</p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="notice notice-error is-dismissible">
            <p>Error: <?php echo esc_html($_GET['error']); ?></p>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <h2 class="nav-tab-wrapper">
        <a href="#questions" class="nav-tab nav-tab-active" data-tab="questions">Questions</a>
        <a href="#outcomes" class="nav-tab" data-tab="outcomes">Outcomes</a>
        <a href="#preview" class="nav-tab" data-tab="preview">Flow Preview</a>
    </h2>

    <!-- QUESTIONS TAB -->
    <div id="tab-questions" class="tab-content active">
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-top: 20px;">

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">Questions (<?php echo count($questions); ?>)</h2>
                <button type="button" class="button button-primary" id="add-question-btn">
                    <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
                    Add Question
                </button>
            </div>

            <?php if (empty($questions)): ?>
                <div style="background: #f0f0f1; padding: 40px; text-align: center; border: 2px dashed #c3c4c7;">
                    <p style="font-size: 16px; color: #666; margin: 0;">
                        No questions yet. Click "Add Question" to get started!
                    </p>
                </div>
            <?php else: ?>

                <!-- Start Question Selection -->
                <?php if (count($questions) > 0): ?>
                <div style="background: #fffbcc; padding: 15px; margin-bottom: 20px; border-left: 4px solid #ffb900;">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin: 0;">
                        <input type="hidden" name="action" value="set_start_question">
                        <input type="hidden" name="questionnaire_id" value="<?php echo esc_attr($questionnaire['id']); ?>">
                        <?php wp_nonce_field('set_start_question_' . $questionnaire['id']); ?>

                        <label style="font-weight: bold;">
                            <span class="dashicons dashicons-arrow-right-alt" style="color: #ffb900;"></span>
                            Start Question (First Question Shown):
                        </label>
                        <select name="start_question_id" style="margin-left: 10px;">
                            <option value="">-- Select Start Question --</option>
                            <?php foreach ($questions as $q): ?>
                                <option value="<?php echo esc_attr($q['id']); ?>"
                                    <?php selected($questionnaire['start_question_id'], $q['id']); ?>>
                                    <?php echo esc_html($q['question_text']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-small" style="margin-left: 10px;">
                            Set Start Question
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Questions List -->
                <div id="questions-list">
                    <?php foreach ($questions as $index => $question): ?>
                        <?php
                        $answer_options = Question_Manager::get_answer_options($question['id']);
                        $is_start_question = ($questionnaire['start_question_id'] == $question['id']);
                        ?>
                        <div class="question-item" data-question-id="<?php echo esc_attr($question['id']); ?>">
                            <div class="question-header" style="background: <?php echo $is_start_question ? '#e7f5ff' : '#f6f7f7'; ?>; padding: 15px; border: 1px solid <?php echo $is_start_question ? '#0073aa' : '#dcdcde'; ?>; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <div style="flex: 1;">
                                    <?php if ($is_start_question): ?>
                                        <span style="background: #0073aa; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-right: 10px;">START</span>
                                    <?php endif; ?>
                                    <strong>Q<?php echo $index + 1; ?>:</strong>
                                    <span class="question-text-display"><?php echo esc_html($question['question_text']); ?></span>
                                    <span style="color: #666; font-size: 12px; margin-left: 10px;">
                                        (<?php echo esc_html($question['question_type']); ?>)
                                    </span>
                                </div>
                                <div>
                                    <button type="button" class="button button-small edit-question-btn">Edit</button>
                                    <button type="button" class="button button-small delete-question-btn" data-question-id="<?php echo esc_attr($question['id']); ?>">Delete</button>
                                    <span class="dashicons dashicons-arrow-down toggle-question-details" style="cursor: pointer;"></span>
                                </div>
                            </div>

                            <div class="question-details" style="display: none; border: 1px solid #dcdcde; border-top: none; padding: 20px; background: #fff;">

                                <!-- Edit Question Form -->
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="question-edit-form">
                                    <input type="hidden" name="action" value="save_question">
                                    <input type="hidden" name="questionnaire_id" value="<?php echo esc_attr($questionnaire['id']); ?>">
                                    <input type="hidden" name="question_id" value="<?php echo esc_attr($question['id']); ?>">
                                    <?php wp_nonce_field('save_question_' . $question['id']); ?>

                                    <table class="form-table">
                                        <tr>
                                            <th><label>Question Text</label></th>
                                            <td>
                                                <textarea name="question_text" class="large-text" rows="3" required><?php echo esc_textarea($question['question_text']); ?></textarea>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Question Type</label></th>
                                            <td>
                                                <select name="question_type" class="question-type-select" required>
                                                    <option value="multiple_choice" <?php selected($question['question_type'], 'multiple_choice'); ?>>Multiple Choice</option>
                                                    <option value="yes_no" <?php selected($question['question_type'], 'yes_no'); ?>>Yes/No</option>
                                                    <option value="text" <?php selected($question['question_type'], 'text'); ?>>Text Input</option>
                                                    <option value="info_only" <?php selected($question['question_type'], 'info_only'); ?>>Information Only (No Answer)</option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Help Text</label></th>
                                            <td>
                                                <textarea name="help_text" class="large-text" rows="2"><?php echo esc_textarea($question['help_text']); ?></textarea>
                                                <p class="description">Optional additional guidance shown below the question.</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Required</label></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="required" value="1" <?php checked($question['required'], 1); ?>>
                                                    User must answer this question
                                                </label>
                                            </td>
                                        </tr>
                                    </table>

                                    <!-- Answer Options (for multiple_choice and yes_no) -->
                                    <div class="answer-options-section" style="<?php echo in_array($question['question_type'], ['multiple_choice', 'yes_no']) ? '' : 'display: none;'; ?>">
                                        <h3>Answer Options</h3>
                                        <p class="description">Define the possible answers and what happens when each is selected.</p>

                                        <div class="answer-options-list">
                                            <?php if (!empty($answer_options)): ?>
                                                <?php foreach ($answer_options as $opt_index => $option): ?>
                                                    <div class="answer-option-row" style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border: 1px solid #ddd;">
                                                        <input type="hidden" name="answer_option_ids[]" value="<?php echo esc_attr($option['id']); ?>">

                                                        <div style="display: grid; grid-template-columns: 2fr 2fr 1fr; gap: 10px; align-items: start;">
                                                            <div>
                                                                <label style="font-weight: bold; display: block; margin-bottom: 5px;">Answer Text</label>
                                                                <input type="text"
                                                                       name="answer_texts[<?php echo esc_attr($option['id']); ?>]"
                                                                       value="<?php echo esc_attr($option['answer_text']); ?>"
                                                                       class="regular-text"
                                                                       required>
                                                            </div>

                                                            <div>
                                                                <label style="font-weight: bold; display: block; margin-bottom: 5px;">Then Go To</label>
                                                                <select name="next_action_type[<?php echo esc_attr($option['id']); ?>]" class="next-action-type-select">
                                                                    <option value="question" <?php selected(!empty($option['next_question_id'])); ?>>Next Question</option>
                                                                    <option value="outcome" <?php selected(!empty($option['outcome_id'])); ?>>Outcome/Result</option>
                                                                </select>

                                                                <select name="next_question_id[<?php echo esc_attr($option['id']); ?>]"
                                                                        class="next-question-select"
                                                                        style="margin-top: 5px; <?php echo !empty($option['next_question_id']) ? '' : 'display:none;'; ?>">
                                                                    <option value="">-- Select Question --</option>
                                                                    <?php foreach ($questions as $q): ?>
                                                                        <?php if ($q['id'] != $question['id']): // Don't allow loop to self ?>
                                                                            <option value="<?php echo esc_attr($q['id']); ?>"
                                                                                <?php selected($option['next_question_id'], $q['id']); ?>>
                                                                                <?php echo esc_html($q['question_text']); ?>
                                                                            </option>
                                                                        <?php endif; ?>
                                                                    <?php endforeach; ?>
                                                                </select>

                                                                <select name="outcome_id[<?php echo esc_attr($option['id']); ?>]"
                                                                        class="outcome-select"
                                                                        style="margin-top: 5px; <?php echo !empty($option['outcome_id']) ? '' : 'display:none;'; ?>">
                                                                    <option value="">-- Select Outcome --</option>
                                                                    <?php foreach ($outcomes as $outcome): ?>
                                                                        <option value="<?php echo esc_attr($outcome['id']); ?>"
                                                                            <?php selected($option['outcome_id'], $outcome['id']); ?>>
                                                                            <?php echo esc_html($outcome['name']); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>

                                                            <div style="text-align: right;">
                                                                <button type="button" class="button button-small delete-answer-option-btn" data-option-id="<?php echo esc_attr($option['id']); ?>">
                                                                    Remove
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>

                                        <button type="button" class="button add-answer-option-btn" data-question-id="<?php echo esc_attr($question['id']); ?>">
                                            <span class="dashicons dashicons-plus-alt"></span>
                                            Add Answer Option
                                        </button>
                                    </div>

                                    <p class="submit" style="margin-top: 20px;">
                                        <button type="submit" class="button button-primary">Save Question</button>
                                        <button type="button" class="button cancel-edit-btn">Cancel</button>
                                    </p>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- OUTCOMES TAB -->
    <div id="tab-outcomes" class="tab-content" style="display: none;">
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-top: 20px;">

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">Outcomes (<?php echo count($outcomes); ?>)</h2>
                <button type="button" class="button button-primary" id="add-outcome-btn">
                    <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
                    Add Outcome
                </button>
            </div>

            <?php if (empty($outcomes)): ?>
                <div style="background: #f0f0f1; padding: 40px; text-align: center; border: 2px dashed #c3c4c7;">
                    <p style="font-size: 16px; color: #666; margin: 0;">
                        No outcomes yet. Outcomes are the final results/recommendations shown to users.
                    </p>
                </div>
            <?php else: ?>
                <div id="outcomes-list">
                    <?php foreach ($outcomes as $index => $outcome): ?>
                        <?php
                        $filter_data = !empty($outcome['resource_filter_data']) ? json_decode($outcome['resource_filter_data'], true) : array();
                        ?>
                        <div class="outcome-item" data-outcome-id="<?php echo esc_attr($outcome['id']); ?>" style="margin-bottom: 20px;">
                            <div class="outcome-header" style="background: #f6f7f7; padding: 15px; border: 1px solid #dcdcde; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <div style="flex: 1;">
                                    <strong>Outcome <?php echo $index + 1; ?>:</strong>
                                    <span class="outcome-name-display"><?php echo esc_html($outcome['name']); ?></span>
                                    <span style="color: #666; font-size: 12px; margin-left: 10px;">
                                        (<?php echo esc_html($outcome['outcome_type']); ?>)
                                    </span>
                                </div>
                                <div>
                                    <button type="button" class="button button-small edit-outcome-btn">Edit</button>
                                    <button type="button" class="button button-small delete-outcome-btn" data-outcome-id="<?php echo esc_attr($outcome['id']); ?>">Delete</button>
                                    <span class="dashicons dashicons-arrow-down toggle-outcome-details" style="cursor: pointer;"></span>
                                </div>
                            </div>

                            <div class="outcome-details" style="display: none; border: 1px solid #dcdcde; border-top: none; padding: 20px; background: #fff;">
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="outcome-edit-form">
                                    <input type="hidden" name="action" value="save_outcome">
                                    <input type="hidden" name="questionnaire_id" value="<?php echo esc_attr($questionnaire['id']); ?>">
                                    <input type="hidden" name="outcome_id" value="<?php echo esc_attr($outcome['id']); ?>">
                                    <?php wp_nonce_field('save_outcome_' . $outcome['id']); ?>

                                    <table class="form-table">
                                        <tr>
                                            <th><label>Outcome Name</label></th>
                                            <td>
                                                <input type="text" name="name" class="regular-text" value="<?php echo esc_attr($outcome['name']); ?>" required>
                                                <p class="description">Admin reference name (not shown to users).</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label>Outcome Type</label></th>
                                            <td>
                                                <select name="outcome_type" class="outcome-type-select" required>
                                                    <option value="resources" <?php selected($outcome['outcome_type'], 'resources'); ?>>Show Resources Only</option>
                                                    <option value="guidance" <?php selected($outcome['outcome_type'], 'guidance'); ?>>Show Guidance Text Only</option>
                                                    <option value="hybrid" <?php selected($outcome['outcome_type'], 'hybrid'); ?>>Show Both Guidance & Resources</option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr class="guidance-text-row" style="<?php echo in_array($outcome['outcome_type'], ['guidance', 'hybrid']) ? '' : 'display:none;'; ?>">
                                            <th><label>Guidance Text</label></th>
                                            <td>
                                                <?php
                                                wp_editor(
                                                    $outcome['guidance_text'],
                                                    'guidance_text_' . $outcome['id'],
                                                    array(
                                                        'textarea_name' => 'guidance_text',
                                                        'textarea_rows' => 8,
                                                        'media_buttons' => false,
                                                        'teeny' => true,
                                                    )
                                                );
                                                ?>
                                                <p class="description">Text shown to user at the end of the questionnaire.</p>
                                            </td>
                                        </tr>
                                        <tr class="resource-filter-row" style="<?php echo in_array($outcome['outcome_type'], ['resources', 'hybrid']) ? '' : 'display:none;'; ?>">
                                            <th><label>Resource Filter</label></th>
                                            <td>
                                                <p style="margin-top: 0;">
                                                    <label>
                                                        <input type="radio" name="resource_filter_type" value="service_type" <?php checked($outcome['resource_filter_type'], 'service_type'); ?>>
                                                        Filter by Service Type
                                                    </label>
                                                </p>
                                                <p>
                                                    <label>
                                                        <input type="radio" name="resource_filter_type" value="specific_resources" <?php checked($outcome['resource_filter_type'], 'specific_resources'); ?>>
                                                        Link to Specific Resources
                                                    </label>
                                                </p>
                                                <p>
                                                    <label>
                                                        <input type="radio" name="resource_filter_type" value="none" <?php checked($outcome['resource_filter_type'], 'none'); ?>>
                                                        No Resources (Guidance Only)
                                                    </label>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>

                                    <p class="submit">
                                        <button type="submit" class="button button-primary">Save Outcome</button>
                                        <button type="button" class="button cancel-edit-outcome-btn">Cancel</button>
                                    </p>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- PREVIEW TAB -->
    <div id="tab-preview" class="tab-content" style="display: none;">
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-top: 20px;">
            <h2>Questionnaire Flow Preview</h2>
            <p class="description">Visual representation of your questionnaire's branching logic.</p>
            <p style="background: #fffbcc; padding: 15px; border-left: 4px solid #ffb900;">
                <strong>Coming Soon:</strong> Flow diagram showing how questions connect to outcomes.
            </p>
        </div>
    </div>
</div>

<!-- Hidden template for new answer option row (populated by JS) -->
<script type="text/template" id="answer-option-template">
    <div class="answer-option-row" style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border: 1px solid #ddd;">
        <input type="hidden" name="new_answer_option_ids[]" value="NEW_PLACEHOLDER_ID">

        <div style="display: grid; grid-template-columns: 2fr 2fr 1fr; gap: 10px; align-items: start;">
            <div>
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">Answer Text</label>
                <input type="text" name="new_answer_texts[]" class="regular-text" required>
            </div>

            <div>
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">Then Go To</label>
                <select name="new_next_action_type[]" class="next-action-type-select">
                    <option value="question">Next Question</option>
                    <option value="outcome">Outcome/Result</option>
                </select>

                <select name="new_next_question_id[]" class="next-question-select" style="margin-top: 5px;">
                    <option value="">-- Select Question --</option>
                    <!-- Populated by JS -->
                </select>

                <select name="new_outcome_id[]" class="outcome-select" style="margin-top: 5px; display: none;">
                    <option value="">-- Select Outcome --</option>
                    <!-- Populated by JS -->
                </select>
            </div>

            <div style="text-align: right;">
                <button type="button" class="button button-small remove-new-answer-option-btn">Remove</button>
            </div>
        </div>
    </div>
</script>
