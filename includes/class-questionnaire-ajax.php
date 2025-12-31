<?php
/**
 * Questionnaire AJAX Class
 *
 * Handles AJAX endpoints for questionnaire navigation
 * Phase 5: Questionnaire Engine & Navigation
 */

class Questionnaire_Ajax {

    /**
     * Write debug log entry (with error_log fallback)
     */
    private function write_debug_log($entry_data) {
        $log_dir = MONDAY_RESOURCES_PLUGIN_DIR . '.cursor';
        $log_file = $log_dir . '/debug.log';
        
        // Ensure directory exists
        if (!file_exists($log_dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($log_dir);
            } else {
                @mkdir($log_dir, 0755, true);
            }
            @chmod($log_dir, 0755);
        }
        
        // Create file if it doesn't exist
        if (!file_exists($log_file)) {
            @touch($log_file);
            @chmod($log_file, 0644);
        }
        
        // Write to file
        $log_entry = json_encode($entry_data) . "\n";
        $write_result = @file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        // Also log to PHP error log as backup (this always works if PHP logging is enabled)
        error_log('QUESTIONNAIRE_DEBUG: ' . $entry_data['message'] . ' | Location: ' . $entry_data['location'] . ' | Data: ' . json_encode($entry_data['data']));
        
        // If file write failed, log that too
        if ($write_result === false) {
            $error_details = array(
                'error' => 'file_put_contents failed',
                'log_file' => $log_file,
                'file_exists' => file_exists($log_file),
                'is_writable' => is_writable($log_file),
                'dir_exists' => file_exists($log_dir),
                'dir_writable' => is_writable($log_dir),
                'permissions' => file_exists($log_file) ? substr(sprintf('%o', fileperms($log_file)), -4) : 'N/A'
            );
            error_log('QUESTIONNAIRE_DEBUG_ERROR: ' . json_encode($error_details));
        }
        
        return $log_file;
    }

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

        add_action('wp_ajax_questionnaire_track_resource_view', array($this, 'ajax_track_resource_view'));
        add_action('wp_ajax_nopriv_questionnaire_track_resource_view', array($this, 'ajax_track_resource_view'));

        // Admin-only AJAX handlers
        add_action('wp_ajax_get_resources_for_selection', array($this, 'ajax_get_resources_for_selection'));
        
        // #region agent log - Verify AJAX hook registration
        $this->log_ajax_registration();
        // #endregion
    }
    
    /**
     * Log AJAX hook registration for debugging
     */
    private function log_ajax_registration() {
        $log_dir = MONDAY_RESOURCES_PLUGIN_DIR . '.cursor';
        $log_file = $log_dir . '/debug.log';
        if (!file_exists($log_dir)) {
            @wp_mkdir_p($log_dir);
        }
        $entry = json_encode(array(
            'sessionId' => 'debug-session',
            'runId' => 'registration',
            'hypothesisId' => 'A',
            'location' => 'class-questionnaire-ajax.php:56',
            'message' => 'AJAX hook registered',
            'data' => array(
                'action' => 'get_resources_for_selection',
                'hook_exists' => has_action('wp_ajax_get_resources_for_selection'),
                'plugin_dir' => MONDAY_RESOURCES_PLUGIN_DIR
            ),
            'timestamp' => round(microtime(true) * 1000)
        )) . "\n";
        @file_put_contents($log_file, $entry, FILE_APPEND);
        error_log('QUESTIONNAIRE_DEBUG_REG: AJAX hook registered for get_resources_for_selection');
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

    /**
     * AJAX: Track resource view
     */
    public function ajax_track_resource_view() {
        check_ajax_referer('questionnaire_frontend_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $resource_id = isset($_POST['resource_id']) ? intval($_POST['resource_id']) : 0;

        if (!$session_id || !$resource_id) {
            wp_send_json_error(array('message' => 'Missing required parameters.'));
        }

        // Track the view
        $tracked = Session_Manager::track_resource_view($session_id, $resource_id);

        if ($tracked) {
            wp_send_json_success(array('message' => 'Resource view tracked.'));
        } else {
            wp_send_json_error(array('message' => 'Failed to track resource view.'));
        }
    }

    /**
     * AJAX: Get resources for admin selection (lazy loading with search and pagination)
     * Admin-only endpoint for questionnaire outcome resource selection
     */
     public function ajax_get_resources_for_selection() {
         // 1. Security Check
         // This looks for 'nonce' in the $_POST request
         check_ajax_referer('questionnaire_admin_nonce', 'nonce');

         if (!current_user_can('manage_options')) {
             wp_send_json_error(array('message' => 'Unauthorized access.'));
         }

         global $wpdb;
         $table_name = $wpdb->prefix . 'resources';

         // 2. Get Parameters (Page & Search)
         $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
         $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
         $per_page = 20; // Load 20 at a time to keep it fast
         $offset = ($page - 1) * $per_page;

         // 3. Build Query
         $where_sql = "WHERE status = 'active'";
         $args = array();

         if (!empty($search)) {
             $where_sql .= " AND (resource_name LIKE %s OR description LIKE %s)";
             $wildcard = '%' . $wpdb->esc_like($search) . '%';
             $args[] = $wildcard;
             $args[] = $wildcard;
         }

         // 4. Get Total Count (for "Load More" logic)
         if (empty($args)) {
             $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where_sql");
         } else {
             $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name $where_sql", $args));
         }

         // 5. Fetch Actual Resources
         $sql = "SELECT id, resource_name, resource_type, geography 
                 FROM $table_name 
                 $where_sql 
                 ORDER BY resource_name ASC 
                 LIMIT %d OFFSET %d";
            
         $args[] = $per_page;
         $args[] = $offset;

         $resources = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

         // 6. Return JSON Success
         wp_send_json_success(array(
             'resources' => $resources,
             'pagination' => array(
                 'current_page' => $page,
                 'total_pages' => ceil($total_items / $per_page),
                 'total_items' => $total_items,
                 'has_more' => ($page * $per_page) < $total_items
             )
         ));
     }

// Initialize
new Questionnaire_Ajax();
