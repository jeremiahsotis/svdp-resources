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
        // #region agent log
        $this->write_debug_log(array(
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A,B,C,D',
            'location' => 'class-questionnaire-ajax.php:228',
            'message' => 'AJAX function entry',
            'data' => array(
                'has_post' => isset($_POST),
                'has_nonce' => isset($_POST['nonce']),
                'has_action' => isset($_POST['action']),
                'action_value' => isset($_POST['action']) ? $_POST['action'] : 'missing',
                'search_term' => isset($_POST['search']) ? substr($_POST['search'], 0, 50) : 'none',
                'page' => isset($_POST['page']) ? $_POST['page'] : 'missing',
                'memory_before' => memory_get_usage(true),
                'plugin_dir' => MONDAY_RESOURCES_PLUGIN_DIR
            ),
            'timestamp' => round(microtime(true) * 1000)
        ));
        // #endregion

        // Increase timeout for large datasets
        if (function_exists('wp_set_time_limit')) {
            @wp_set_time_limit(60);
        } elseif (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        // #region agent log
        $this->write_debug_log(array(
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'D',
            'location' => 'class-questionnaire-ajax.php:304',
            'message' => 'Before nonce check',
            'data' => array('nonce_present' => isset($_POST['nonce'])),
            'timestamp' => round(microtime(true) * 1000)
        ));
        // #endregion

        $nonce_result = check_ajax_referer('questionnaire_admin_nonce', 'nonce', false);

        // #region agent log
        $this->write_debug_log(array(
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'D',
            'location' => 'class-questionnaire-ajax.php:317',
            'message' => 'After nonce check',
            'data' => array('nonce_valid' => $nonce_result),
            'timestamp' => round(microtime(true) * 1000)
        ));
        // #endregion

        if (!$nonce_result) {
            // #region agent log
            $this->write_debug_log(array(
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'D',
                'location' => 'class-questionnaire-ajax.php:332',
                'message' => 'Nonce check failed',
                'data' => array(),
                'timestamp' => round(microtime(true) * 1000)
            ));
            // #endregion
            wp_send_json_error(array('message' => 'Invalid security token. Please refresh the page.'));
        }

        if (!current_user_can('manage_options')) {
            // #region agent log
            $this->write_debug_log(array(
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'class-questionnaire-ajax.php:348',
                'message' => 'User unauthorized',
                'data' => array('user_id' => get_current_user_id()),
                'timestamp' => round(microtime(true) * 1000)
            ));
            // #endregion
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        // Get parameters
        $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 100;
        
        // Validate pagination
        $page = max(1, $page);
        $per_page = min(200, max(10, $per_page)); // Limit between 10 and 200
        $offset = ($page - 1) * $per_page;

        try {
            // #region agent log
            $this->write_debug_log(array(
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'class-questionnaire-ajax.php:377',
                'message' => 'Before database query',
                'data' => array(
                    'table_name' => $table_name,
                    'search_term' => $search_term,
                    'page' => $page,
                    'per_page' => $per_page,
                    'offset' => $offset
                ),
                'timestamp' => round(microtime(true) * 1000)
            ));
            // #endregion

            // Optimized query - only select needed fields and build searchable text in SQL
            $query = "SELECT 
                id, 
                resource_name, 
                organization, 
                primary_service_type, 
                secondary_service_type, 
                target_population,
                LOWER(CONCAT_WS(' ', 
                    COALESCE(resource_name, ''),
                    COALESCE(organization, ''),
                    COALESCE(primary_service_type, ''),
                    COALESCE(secondary_service_type, ''),
                    COALESCE(target_population, '')
                )) as searchable
            FROM $table_name 
            WHERE status = 'active'";

            $where_values = array();

            // Add search filter if provided
            if (!empty($search_term)) {
                $search_like = '%' . $wpdb->esc_like($search_term) . '%';
                $query .= " AND (
                    resource_name LIKE %s OR
                    organization LIKE %s OR
                    primary_service_type LIKE %s OR
                    secondary_service_type LIKE %s OR
                    target_population LIKE %s
                )";
                $where_values[] = $search_like;
                $where_values[] = $search_like;
                $where_values[] = $search_like;
                $where_values[] = $search_like;
                $where_values[] = $search_like;
            }

            // Add pagination
            $query .= " ORDER BY resource_name ASC LIMIT %d OFFSET %d";
            $where_values[] = $per_page;
            $where_values[] = $offset;

            // Execute query
            $query_start = microtime(true);
            if (!empty($where_values)) {
                $prepared_query = $wpdb->prepare($query, $where_values);
                // #region agent log
                $this->write_debug_log(array(
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'C',
                    'location' => 'class-questionnaire-ajax.php:442',
                    'message' => 'Executing prepared query',
                    'data' => array('query_length' => strlen($prepared_query)),
                    'timestamp' => round(microtime(true) * 1000)
                ));
                // #endregion
                $resources = $wpdb->get_results($prepared_query, ARRAY_A);
            } else {
                // #region agent log
                $this->write_debug_log(array(
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'C',
                    'location' => 'class-questionnaire-ajax.php:457',
                    'message' => 'Executing simple query',
                    'data' => array(),
                    'timestamp' => round(microtime(true) * 1000)
                ));
                // #endregion
                $resources = $wpdb->get_results($query, ARRAY_A);
            }
            $query_time = microtime(true) - $query_start;

            // #region agent log
            $this->write_debug_log(array(
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'class-questionnaire-ajax.php:471',
                'message' => 'After database query',
                'data' => array(
                    'query_time_seconds' => round($query_time, 3),
                    'resources_count' => is_array($resources) ? count($resources) : 'not_array',
                    'is_false' => ($resources === false),
                    'last_error' => $wpdb->last_error ? substr($wpdb->last_error, 0, 100) : 'none'
                ),
                'timestamp' => round(microtime(true) * 1000)
            ));
            // #endregion

            if ($resources === false) {
                // #region agent log
                $this->write_debug_log(array(
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'C',
                    'location' => 'class-questionnaire-ajax.php:491',
                    'message' => 'Database query failed',
                    'data' => array('last_error' => $wpdb->last_error ?: 'unknown'),
                    'timestamp' => round(microtime(true) * 1000)
                ));
                // #endregion
                wp_send_json_error(array('message' => 'Database query failed.'));
            }

            // Check if there are more results
            $count_query = "SELECT COUNT(*) as total FROM $table_name WHERE status = 'active'";
            if (!empty($search_term)) {
                $search_like = '%' . $wpdb->esc_like($search_term) . '%';
                $count_query .= $wpdb->prepare(" AND (
                    resource_name LIKE %s OR
                    organization LIKE %s OR
                    primary_service_type LIKE %s OR
                    secondary_service_type LIKE %s OR
                    target_population LIKE %s
                )", $search_like, $search_like, $search_like, $search_like, $search_like);
            }
            $total_count = $wpdb->get_var($count_query);
            $has_more = ($offset + count($resources)) < $total_count;

            // Format resources (minimal processing)
            $formatted_resources = array();
            foreach ($resources as $resource) {
                $formatted_resources[] = array(
                    'id' => intval($resource['id']),
                    'name' => $resource['resource_name'],
                    'organization' => !empty($resource['organization']) ? $resource['organization'] : '',
                    'resource_type' => !empty($resource['primary_service_type']) ? $resource['primary_service_type'] : '',
                    'needs_met' => !empty($resource['secondary_service_type']) ? $resource['secondary_service_type'] : '',
                    'target_population' => !empty($resource['target_population']) ? $resource['target_population'] : '',
                    'searchable' => !empty($resource['searchable']) ? $resource['searchable'] : ''
                );
            }

            // #region agent log
            $this->write_debug_log(array(
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A,B',
                'location' => 'class-questionnaire-ajax.php:527',
                'message' => 'Before sending success response',
                'data' => array(
                    'formatted_count' => count($formatted_resources),
                    'total_count' => intval($total_count),
                    'has_more' => $has_more,
                    'memory_usage' => memory_get_usage(true)
                ),
                'timestamp' => round(microtime(true) * 1000)
            ));
            // #endregion

            wp_send_json_success(array(
                'resources' => $formatted_resources,
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total_count),
                'has_more' => $has_more
            ));

            // #region agent log
            $this->write_debug_log(array(
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A,B',
                'location' => 'class-questionnaire-ajax.php:553',
                'message' => 'After wp_send_json_success (should not reach here)',
                'data' => array(),
                'timestamp' => round(microtime(true) * 1000)
            ));
            // #endregion

        } catch (Exception $e) {
            // #region agent log
            $this->write_debug_log(array(
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'B',
                'location' => 'class-questionnaire-ajax.php:563',
                'message' => 'Exception caught',
                'data' => array(
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine()
                ),
                'timestamp' => round(microtime(true) * 1000)
            ));
            // #endregion
            wp_send_json_error(array('message' => 'Error loading resources: ' . $e->getMessage()));
        }
    }
}

// Initialize
new Questionnaire_Ajax();
