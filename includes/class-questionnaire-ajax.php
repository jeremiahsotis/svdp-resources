<?php
/**
 * Questionnaire AJAX Class
 *
 * Handles AJAX endpoints for questionnaire navigation
 * Phase 5: Questionnaire Engine & Navigation
 */

class Questionnaire_Ajax {

    /**
     * Constructor
     */
    public function __construct() {
        // AJAX handlers for questionnaire navigation
        add_action('wp_ajax_questionnaire_get_question', array($this, 'ajax_get_question'));
        add_action('wp_ajax_nopriv_questionnaire_get_question', array($this, 'ajax_get_question'));

        add_action('wp_ajax_questionnaire_submit_answer', array($this, 'ajax_submit_answer'));
        add_action('wp_ajax_nopriv_questionnaire_submit_answer', array($this, 'ajax_submit_answer'));

        add_action('wp_ajax_questionnaire_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_nopriv_questionnaire_get_progress', array($this, 'ajax_get_progress'));
    }

    /**
     * AJAX: Get question by ID
     */
    public function ajax_get_question() {
        check_ajax_referer('questionnaire_frontend_nonce', 'nonce');

        $question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        if (!$question_id || !$session_id) {
            wp_send_json_error(array('message' => 'Missing required parameters.'));
        }

        // Verify session exists
        $session = Session_Manager::get_session($session_id);
        if (!$session) {
            wp_send_json_error(array('message' => 'Invalid session.'));
        }

        // Get question
        $question = Question_Manager::get_question($question_id);
        if (!$question) {
            wp_send_json_error(array('message' => 'Question not found.'));
        }

        // Get answer options if applicable
        $answer_options = array();
        if (in_array($question['question_type'], array('multiple_choice', 'yes_no'))) {
            $answer_options = Question_Manager::get_answer_options($question_id);
        }

        // Render question HTML
        ob_start();
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/questionnaire-question.php';
        $question_html = ob_get_clean();

        wp_send_json_success(array(
            'question_html' => $question_html,
            'question_type' => $question['question_type'],
            'question_id' => $question_id
        ));
    }

    /**
     * AJAX: Submit answer and get next step
     */
    public function ajax_submit_answer() {
        check_ajax_referer('questionnaire_frontend_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;
        $answer_option_id = isset($_POST['answer_option_id']) ? intval($_POST['answer_option_id']) : null;
        $answer_text = isset($_POST['answer_text']) ? sanitize_textarea_field($_POST['answer_text']) : null;

        // Validate inputs
        if (!$session_id || !$question_id) {
            wp_send_json_error(array('message' => 'Missing required parameters.'));
        }

        // Verify session
        $session = Session_Manager::get_session($session_id);
        if (!$session || $session['status'] !== 'in_progress') {
            wp_send_json_error(array('message' => 'Invalid or completed session.'));
        }

        // Get question to validate answer
        $question = Question_Manager::get_question($question_id);
        if (!$question) {
            wp_send_json_error(array('message' => 'Question not found.'));
        }

        // Validate required field
        if ($question['required']) {
            if (in_array($question['question_type'], array('multiple_choice', 'yes_no')) && !$answer_option_id) {
                wp_send_json_error(array('message' => 'Please select an answer.'));
            }
            if ($question['question_type'] === 'text' && empty($answer_text)) {
                wp_send_json_error(array('message' => 'Please provide an answer.'));
            }
        }

        // Record response
        $answer_data = array(
            'answer_option_id' => $answer_option_id,
            'answer_text' => $answer_text
        );

        $recorded = Session_Manager::record_response($session_id, $question_id, $answer_data);

        if (!$recorded) {
            wp_send_json_error(array('message' => 'Failed to record answer.'));
        }

        // Determine next step (question or outcome)
        $next_step = Question_Manager::get_next_step($question_id, $answer_option_id);

        if (!$next_step) {
            wp_send_json_error(array('message' => 'Unable to determine next step.'));
        }

        if ($next_step['type'] === 'question') {
            // Get next question
            $next_question = Question_Manager::get_question($next_step['id']);

            if (!$next_question) {
                wp_send_json_error(array('message' => 'Next question not found.'));
            }

            // Get answer options if applicable
            $answer_options = array();
            if (in_array($next_question['question_type'], array('multiple_choice', 'yes_no'))) {
                $answer_options = Question_Manager::get_answer_options($next_step['id']);
            }

            // Render next question
            $question = $next_question;
            $question_id = $next_step['id'];

            ob_start();
            include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/questionnaire-question.php';
            $question_html = ob_get_clean();

            wp_send_json_success(array(
                'next_type' => 'question',
                'question_html' => $question_html,
                'question_id' => $next_step['id']
            ));

        } else if ($next_step['type'] === 'outcome') {
            // Mark session as complete
            Session_Manager::complete_session($session_id, $next_step['id']);

            wp_send_json_success(array(
                'next_type' => 'outcome',
                'outcome_id' => $next_step['id']
            ));
        } else {
            wp_send_json_error(array('message' => 'Invalid next step type.'));
        }
    }

    /**
     * AJAX: Get session progress
     */
    public function ajax_get_progress() {
        check_ajax_referer('questionnaire_frontend_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        if (!$session_id) {
            wp_send_json_error(array('message' => 'Missing session ID.'));
        }

        // Get session
        $session = Session_Manager::get_session($session_id);
        if (!$session) {
            wp_send_json_error(array('message' => 'Session not found.'));
        }

        // Get session path (all answered questions)
        $path = Session_Manager::get_session_path($session_id);

        wp_send_json_success(array(
            'questions_answered' => count($path),
            'status' => $session['status'],
            'session_path' => $path
        ));
    }
}

// Initialize
new Questionnaire_Ajax();
