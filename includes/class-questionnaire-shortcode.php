<?php
/**
 * Questionnaire Shortcode Class
 *
 * Handles frontend rendering of questionnaires via [svdp_questionnaire] shortcode
 * Phase 4: Location Service & Frontend Foundation
 */

class Questionnaire_Shortcode {

    /**
     * Constructor
     */
    public function __construct() {
        add_shortcode('svdp_questionnaire', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        // AJAX handlers for frontend
        add_action('wp_ajax_questionnaire_create_session', array($this, 'ajax_create_session'));
        add_action('wp_ajax_nopriv_questionnaire_create_session', array($this, 'ajax_create_session'));
        add_action('wp_ajax_questionnaire_lookup_address', array($this, 'ajax_lookup_address'));
        add_action('wp_ajax_nopriv_questionnaire_lookup_address', array($this, 'ajax_lookup_address'));
    }

    /**
     * Enqueue frontend CSS and JavaScript
     */
    public function enqueue_frontend_assets() {
        // Only enqueue if shortcode is present on the page
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'svdp_questionnaire')) {
            // Frontend CSS
            wp_enqueue_style(
                'questionnaire-frontend',
                MONDAY_RESOURCES_PLUGIN_URL . 'assets/css/questionnaire.css',
                array(),
                MONDAY_RESOURCES_VERSION
            );

            // Frontend JS
            wp_enqueue_script(
                'questionnaire-frontend',
                MONDAY_RESOURCES_PLUGIN_URL . 'assets/js/questionnaire.js',
                array('jquery'),
                MONDAY_RESOURCES_VERSION,
                true
            );

            // Localize script with AJAX URL and nonce
            wp_localize_script('questionnaire-frontend', 'questionnaireFrontend', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('questionnaire_frontend_nonce'),
            ));
        }
    }

    /**
     * Render the shortcode
     *
     * [svdp_questionnaire slug="eviction-help" geography="Cathedral" mode="public" show_progress="true"]
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_shortcode($atts) {
        // Parse attributes
        $atts = shortcode_atts(array(
            'slug' => '',
            'geography' => '', // Pre-set Conference (skips location selection)
            'mode' => 'public', // 'public' or 'volunteer'
            'show_progress' => 'true',
        ), $atts, 'svdp_questionnaire');

        // Validate slug
        if (empty($atts['slug'])) {
            return '<div class="questionnaire-error">Error: Questionnaire slug is required.</div>';
        }

        // Get questionnaire by slug
        $questionnaire = Questionnaire_Manager::get_questionnaire_by_slug($atts['slug']);

        if (!$questionnaire) {
            return '<div class="questionnaire-error">Error: Questionnaire not found.</div>';
        }

        // Check if questionnaire is active
        if ($questionnaire['status'] !== 'active') {
            return '<div class="questionnaire-error">This questionnaire is not currently available.</div>';
        }

        // Check if displaying outcome
        if (isset($_GET['outcome'])) {
            $outcome_id = intval($_GET['outcome']);
            return $this->render_outcome($outcome_id, $questionnaire, $atts);
        }

        // Check for existing session
        $session_id = $this->get_session_cookie();
        $session = null;

        if ($session_id) {
            $session = Session_Manager::get_session($session_id);

            // If session is completed, show outcome
            if ($session && $session['status'] == 'completed' && !empty($session['outcome_id'])) {
                return $this->render_outcome($session['outcome_id'], $questionnaire, $atts);
            }

            // Only use session if it's for this questionnaire and still in progress
            if ($session && $session['questionnaire_id'] == $questionnaire['id'] && $session['status'] == 'in_progress') {
                // Session exists - render questionnaire flow
                ob_start();
                $this->render_questionnaire_flow($questionnaire, $session_id, $atts);
                return ob_get_clean();
            } else {
                $session = null;
            }
        }

        // Start output buffering
        ob_start();

        // Check if geography is pre-set (skip location selection)
        if (!empty($atts['geography'])) {
            // Validate pre-set geography
            if (Location_Service::validate_conference($atts['geography'])) {
                // Create session immediately with pre-set geography
                $session_id = $this->create_session($questionnaire['id'], $atts['geography'], $atts['mode']);

                if ($session_id) {
                    // Render first question
                    $this->render_questionnaire_flow($questionnaire, $session_id, $atts);
                } else {
                    echo '<div class="questionnaire-error">Error creating session. Please try again.</div>';
                }
            } else {
                echo '<div class="questionnaire-error">Invalid Conference specified.</div>';
            }
        } else {
            // Show location selection
            $this->render_location_selection($questionnaire, $atts);
        }

        return ob_get_clean();
    }

    /**
     * Render location selection step
     *
     * @param array $questionnaire Questionnaire data
     * @param array $atts Shortcode attributes
     */
    private function render_location_selection($questionnaire, $atts) {
        // Include template
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/questionnaire-location.php';
    }

    /**
     * Create a new questionnaire session
     *
     * @param int $questionnaire_id Questionnaire ID
     * @param string $conference Conference name
     * @param string $mode 'public' or 'volunteer'
     * @return string|false Session ID or false on failure
     */
    private function create_session($questionnaire_id, $conference, $mode = 'public') {
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $is_volunteer_assisted = ($mode === 'volunteer') ? 1 : 0;

        // Create session
        $session_id = Session_Manager::create_session(
            $questionnaire_id,
            $conference,
            $user_id,
            $is_volunteer_assisted
        );

        if ($session_id) {
            // Store session ID in cookie (24 hour expiration)
            $this->set_session_cookie($session_id);
        }

        return $session_id;
    }

    /**
     * Get session ID from cookie
     *
     * @return string|null Session ID or null if not set
     */
    private function get_session_cookie() {
        return isset($_COOKIE['svdp_questionnaire_session']) ? sanitize_text_field($_COOKIE['svdp_questionnaire_session']) : null;
    }

    /**
     * Set session ID in cookie
     *
     * @param string $session_id Session ID
     */
    private function set_session_cookie($session_id) {
        $expiration = time() + (24 * 60 * 60); // 24 hours
        setcookie('svdp_questionnaire_session', $session_id, $expiration, COOKIEPATH, COOKIE_DOMAIN);
    }

    /**
     * Clear session cookie
     */
    private function clear_session_cookie() {
        setcookie('svdp_questionnaire_session', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
    }

    /**
     * Render questionnaire flow (first question)
     *
     * @param array $questionnaire Questionnaire data
     * @param string $session_id Session ID
     * @param array $atts Shortcode attributes
     */
    private function render_questionnaire_flow($questionnaire, $session_id, $atts) {
        // Get session
        $session = Session_Manager::get_session($session_id);

        if (!$session) {
            echo '<div class="questionnaire-error">Error: Session not found.</div>';
            return;
        }

        // Check if questionnaire has a start question
        if (empty($questionnaire['start_question_id'])) {
            echo '<div class="questionnaire-error">Error: This questionnaire has no starting question. Please contact the administrator.</div>';
            return;
        }

        // Get first question
        $question = Question_Manager::get_question($questionnaire['start_question_id']);

        if (!$question) {
            echo '<div class="questionnaire-error">Error: Starting question not found.</div>';
            return;
        }

        // Get answer options if applicable
        $answer_options = array();
        if (in_array($question['question_type'], array('multiple_choice', 'yes_no'))) {
            $answer_options = Question_Manager::get_answer_options($question['id']);
        }

        // Get questions answered count
        $session_path = Session_Manager::get_session_path($session_id);
        $questions_answered = count($session_path);

        // Output container
        echo '<div class="questionnaire-container questionnaire-flow" data-questionnaire-id="' . esc_attr($questionnaire['id']) . '" data-session-id="' . esc_attr($session_id) . '" data-mode="' . esc_attr($atts['mode']) . '">';

        // Header
        echo '<div class="questionnaire-header">';
        echo '<h2 class="questionnaire-title">' . esc_html($questionnaire['name']) . '</h2>';
        if ($atts['mode'] === 'volunteer') {
            echo '<div class="volunteer-mode-notice">';
            echo '<span class="dashicons dashicons-groups"></span>';
            echo '<strong>Volunteer Mode:</strong> You are guiding someone through this questionnaire.';
            echo '</div>';
        }
        echo '</div>';

        // Volunteer Helper (if in volunteer mode)
        if ($atts['mode'] === 'volunteer') {
            $context = 'questions';
            include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/volunteer-helper.php';
        }

        // Progress indicator (if enabled)
        if ($atts['show_progress'] === 'true') {
            include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/questionnaire-progress.php';
        }

        // Question container (will be replaced by AJAX)
        echo '<div class="question-container">';
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/questionnaire-question.php';
        echo '</div>';

        echo '</div>'; // .questionnaire-container
    }

    /**
     * Render outcome (results) page
     *
     * @param int $outcome_id Outcome ID
     * @param array $questionnaire Questionnaire data
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    private function render_outcome($outcome_id, $questionnaire, $atts) {
        // Get outcome
        $outcome = Outcome_Manager::get_outcome($outcome_id);

        if (!$outcome) {
            return '<div class="questionnaire-error">Error: Outcome not found.</div>';
        }

        // Get session
        $session_id = $this->get_session_cookie();
        $session = Session_Manager::get_session($session_id);

        if (!$session) {
            return '<div class="questionnaire-error">Error: Session not found.</div>';
        }

        // Get resources if outcome shows resources
        $resources = array();
        if (in_array($outcome['outcome_type'], array('resources', 'hybrid'))) {
            $resources = Outcome_Manager::get_resources_for_outcome($outcome_id, $session['conference']);
        }

        // Start output buffering
        ob_start();

        // Wrapper for consistency
        echo '<div class="questionnaire-container questionnaire-outcome-container" data-session-id="' . esc_attr($session_id) . '">';

        // Volunteer Helper (if in volunteer mode)
        if ($atts['mode'] === 'volunteer') {
            $context = 'outcome';
            include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/volunteer-helper.php';
        }

        // Include outcome template
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/questionnaire-outcome.php';

        echo '</div>';

        return ob_get_clean();
    }

    // ========================================
    // AJAX HANDLERS
    // ========================================

    /**
     * AJAX: Create questionnaire session
     */
    public function ajax_create_session() {
        check_ajax_referer('questionnaire_frontend_nonce', 'nonce');

        $questionnaire_id = isset($_POST['questionnaire_id']) ? intval($_POST['questionnaire_id']) : 0;
        $conference = isset($_POST['conference']) ? sanitize_text_field($_POST['conference']) : '';
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'public';

        // Validate inputs
        if (!$questionnaire_id || empty($conference)) {
            wp_send_json_error(array('message' => 'Missing required information.'));
        }

        // Validate conference
        if (!Location_Service::validate_conference($conference)) {
            wp_send_json_error(array('message' => 'Invalid Conference selected.'));
        }

        // Validate questionnaire exists and is active
        $questionnaire = Questionnaire_Manager::get_questionnaire($questionnaire_id);
        if (!$questionnaire || $questionnaire['status'] !== 'active') {
            wp_send_json_error(array('message' => 'Questionnaire not available.'));
        }

        // Create session
        $session_id = $this->create_session($questionnaire_id, $conference, $mode);

        if ($session_id) {
            wp_send_json_success(array(
                'session_id' => $session_id,
                'message' => 'Session created successfully.'
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to create session. Please try again.'));
        }
    }

    /**
     * AJAX: Lookup Conference from address
     */
    public function ajax_lookup_address() {
        check_ajax_referer('questionnaire_frontend_nonce', 'nonce');

        $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';

        if (empty($address)) {
            wp_send_json_error(array('message' => 'Please enter an address.'));
        }

        // Use Location Service to lookup address
        $result = Location_Service::get_conference_from_address($address);

        if ($result['found']) {
            wp_send_json_success(array(
                'conference_name' => $result['conference_name'],
                'conference_description' => isset($result['conference_description']) ? $result['conference_description'] : '',
                'message' => 'Conference found!'
            ));
        } else {
            wp_send_json_error(array(
                'error_code' => isset($result['error']) ? $result['error'] : 'unknown',
                'message' => $result['message']
            ));
        }
    }
}

// Initialize
new Questionnaire_Shortcode();
