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
        // Increase timeout for large datasets
        if (function_exists('wp_set_time_limit')) {
            @wp_set_time_limit(60);
        } elseif (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        check_ajax_referer('questionnaire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
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
            if (!empty($where_values)) {
                $resources = $wpdb->get_results($wpdb->prepare($query, $where_values), ARRAY_A);
            } else {
                $resources = $wpdb->get_results($query, ARRAY_A);
            }

            if ($resources === false) {
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

            wp_send_json_success(array(
                'resources' => $formatted_resources,
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total_count),
                'has_more' => $has_more
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error loading resources: ' . $e->getMessage()));
        }
    }
}

// Initialize
new Questionnaire_Ajax();
