<?php
/**
 * Questionnaire Admin Class
 *
 * Handles admin interface for questionnaire management
 */

class Questionnaire_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Form handlers
        add_action('admin_post_save_questionnaire', array($this, 'save_questionnaire'));
        add_action('admin_post_delete_questionnaire', array($this, 'delete_questionnaire'));
        add_action('admin_post_bulk_action_questionnaires', array($this, 'bulk_action_questionnaires'));

        // Question builder form handlers (Phase 3)
        add_action('admin_post_save_question', array($this, 'save_question'));
        add_action('admin_post_save_outcome', array($this, 'save_outcome'));
        add_action('admin_post_set_start_question', array($this, 'set_start_question'));

        // AJAX handlers for question builder (Phase 3)
        add_action('wp_ajax_add_new_question', array($this, 'ajax_add_new_question'));
        add_action('wp_ajax_delete_question', array($this, 'ajax_delete_question'));
        add_action('wp_ajax_add_answer_option', array($this, 'ajax_add_answer_option'));
        add_action('wp_ajax_delete_answer_option', array($this, 'ajax_delete_answer_option'));
        add_action('wp_ajax_add_new_outcome', array($this, 'ajax_add_new_outcome'));
        add_action('wp_ajax_delete_outcome', array($this, 'ajax_delete_outcome'));
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main menu page (also serves as "All Questionnaires")
        add_menu_page(
            'Questionnaires',
            'Questionnaires',
            'manage_options',
            'questionnaires',
            array($this, 'all_questionnaires_page'),
            'dashicons-format-chat',
            31
        );

        // All Questionnaires submenu (rename main page)
        add_submenu_page(
            'questionnaires',
            'All Questionnaires',
            'All Questionnaires',
            'manage_options',
            'questionnaires',
            array($this, 'all_questionnaires_page')
        );

        // Add New submenu
        add_submenu_page(
            'questionnaires',
            'Add New Questionnaire',
            'Add New',
            'manage_options',
            'questionnaires-add',
            array($this, 'add_questionnaire_page')
        );

        // Analytics submenu
        add_submenu_page(
            'questionnaires',
            'Questionnaire Analytics',
            'Analytics',
            'manage_options',
            'questionnaires-analytics',
            array($this, 'analytics_page')
        );

        // Edit page (hidden from menu)
        add_submenu_page(
            null,
            'Edit Questionnaire',
            'Edit Questionnaire',
            'manage_options',
            'questionnaires-edit',
            array($this, 'edit_questionnaire_page')
        );

        // Question Builder page (hidden from menu)
        add_submenu_page(
            null,
            'Manage Questions & Outcomes',
            'Manage Questions & Outcomes',
            'manage_options',
            'questionnaires-builder',
            array($this, 'question_builder_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Placeholder for future settings
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on questionnaire pages
        $questionnaire_pages = array(
            'toplevel_page_questionnaires',
            'questionnaires_page_questionnaires-add',
            'questionnaires_page_questionnaires-analytics',
            'admin_page_questionnaires-edit',
            'admin_page_questionnaires-builder',
        );

        // Also check URL parameter as fallback
        $page = isset($_GET['page']) ? $_GET['page'] : '';
        $is_questionnaire_page = strpos($page, 'questionnaires') !== false;

        if (in_array($hook, $questionnaire_pages) || $is_questionnaire_page) {
            wp_enqueue_style(
                'questionnaire-admin',
                MONDAY_RESOURCES_PLUGIN_URL . 'assets/css/admin-questionnaire.css',
                array(),
                MONDAY_RESOURCES_VERSION
            );

            wp_enqueue_script(
                'questionnaire-admin',
                MONDAY_RESOURCES_PLUGIN_URL . 'assets/js/admin-questionnaire.js',
                array('jquery'),
                MONDAY_RESOURCES_VERSION,
                true
            );

            wp_localize_script('questionnaire-admin', 'questionnaireAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('questionnaire_admin_nonce'),
            ));
        }
    }

    /**
     * All Questionnaires list page
     */
    public function all_questionnaires_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get all questionnaires
        $questionnaires = Questionnaire_Manager::get_all_questionnaires();

        // Show success/error messages
        if (isset($_GET['saved']) && $_GET['saved'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Questionnaire saved successfully.</p></div>';
        }
        if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Questionnaire deleted successfully.</p></div>';
        }
        if (isset($_GET['added']) && $_GET['added'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Questionnaire created successfully.</p></div>';
        }

        // Include template
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/admin-questionnaire-list.php';
    }

    /**
     * Add New Questionnaire page
     */
    public function add_questionnaire_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $questionnaire = null;
        $is_duplicate = false;

        // Check if duplicating
        if (isset($_GET['duplicate']) && !empty($_GET['duplicate'])) {
            $duplicate_id = intval($_GET['duplicate']);
            $original = Questionnaire_Manager::get_questionnaire($duplicate_id);

            if ($original) {
                $questionnaire = $original;
                unset($questionnaire['id']);
                $questionnaire['name'] .= ' (Copy)';
                $questionnaire['slug'] = '';
                unset($questionnaire['created_at']);
                unset($questionnaire['updated_at']);
                $is_duplicate = true;
            }
        }

        // Include template
        $is_edit = false;
        $has_data = !empty($questionnaire);
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/admin-questionnaire-form.php';
    }

    /**
     * Edit Questionnaire page
     */
    public function edit_questionnaire_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get questionnaire ID
        $questionnaire_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$questionnaire_id) {
            wp_die(__('Invalid questionnaire ID.'));
        }

        // Get questionnaire
        $questionnaire = Questionnaire_Manager::get_questionnaire($questionnaire_id);
        if (!$questionnaire) {
            wp_die(__('Questionnaire not found.'));
        }

        // Include template
        $is_edit = true;
        $has_data = true;
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/admin-questionnaire-form.php';
    }

    /**
     * Analytics page (placeholder)
     */
    public function analytics_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        echo '<div class="wrap">';
        echo '<h1>Questionnaire Analytics</h1>';
        echo '<p>Analytics dashboard coming in Phase 8...</p>';
        echo '</div>';
    }

    /**
     * Question Builder page
     */
    public function question_builder_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get questionnaire ID
        $questionnaire_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$questionnaire_id) {
            wp_die(__('Invalid questionnaire ID.'));
        }

        // Get questionnaire
        $questionnaire = Questionnaire_Manager::get_questionnaire($questionnaire_id);
        if (!$questionnaire) {
            wp_die(__('Questionnaire not found.'));
        }

        // Get all questions for this questionnaire
        $questions = Question_Manager::get_questions_for_questionnaire($questionnaire_id);

        // Get all outcomes for this questionnaire
        $outcomes = Outcome_Manager::get_outcomes_for_questionnaire($questionnaire_id);

        // Include template
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/admin-question-builder.php';
    }

    /**
     * Save questionnaire handler
     */
    public function save_questionnaire() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Check nonce
        $questionnaire_id = isset($_POST['questionnaire_id']) ? intval($_POST['questionnaire_id']) : 0;
        if ($questionnaire_id) {
            check_admin_referer('save_questionnaire_' . $questionnaire_id);
        } else {
            check_admin_referer('save_questionnaire_new');
        }

        // Sanitize and validate inputs
        $data = array(
            'name' => sanitize_text_field(wp_unslash($_POST['name'])),
            'slug' => sanitize_title(wp_unslash($_POST['slug'])),
            'description' => sanitize_textarea_field(wp_unslash($_POST['description'])),
            'status' => sanitize_text_field(wp_unslash($_POST['status'])),
            'sort_order' => intval($_POST['sort_order']),
        );

        // Handle geography checkboxes
        if (isset($_POST['geography']) && is_array($_POST['geography'])) {
            $data['geography'] = implode(', ', array_map('sanitize_text_field', $_POST['geography']));
        } else {
            $data['geography'] = '';
        }

        // Detect which button was clicked
        $save_and_new = isset($_POST['save_and_new']);

        // Save or update
        if ($questionnaire_id) {
            // Update existing
            $success = Questionnaire_Manager::update_questionnaire($questionnaire_id, $data);

            if ($success) {
                if ($save_and_new) {
                    $redirect_url = admin_url('admin.php?page=questionnaires-add&saved=1');
                } else {
                    $redirect_url = admin_url('admin.php?page=questionnaires-edit&id=' . $questionnaire_id . '&saved=1');
                }
            } else {
                $redirect_url = admin_url('admin.php?page=questionnaires-edit&id=' . $questionnaire_id . '&error=1');
            }
        } else {
            // Create new
            $new_id = Questionnaire_Manager::create_questionnaire($data);

            if ($new_id) {
                if ($save_and_new) {
                    $redirect_url = admin_url('admin.php?page=questionnaires-add&added=1');
                } else {
                    $redirect_url = admin_url('admin.php?page=questionnaires-edit&id=' . $new_id . '&saved=1');
                }
            } else {
                $redirect_url = admin_url('admin.php?page=questionnaires-add&error=1');
            }
        }

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Delete questionnaire handler
     */
    public function delete_questionnaire() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Get questionnaire ID
        $questionnaire_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$questionnaire_id) {
            wp_die('Invalid questionnaire ID');
        }

        // Check nonce
        check_admin_referer('delete_questionnaire_' . $questionnaire_id);

        // Soft delete
        $success = Questionnaire_Manager::delete_questionnaire($questionnaire_id);

        if ($success) {
            wp_redirect(admin_url('admin.php?page=questionnaires&deleted=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=questionnaires&error=1'));
        }
        exit;
    }

    /**
     * Bulk actions handler
     */
    public function bulk_action_questionnaires() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Check nonce
        check_admin_referer('bulk_action_questionnaires');

        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $questionnaire_ids = isset($_POST['questionnaire_ids']) ? array_map('intval', $_POST['questionnaire_ids']) : array();

        if (empty($action) || empty($questionnaire_ids)) {
            wp_redirect(admin_url('admin.php?page=questionnaires'));
            exit;
        }

        $count = 0;
        foreach ($questionnaire_ids as $id) {
            if ($action === 'delete') {
                if (Questionnaire_Manager::delete_questionnaire($id)) {
                    $count++;
                }
            }
        }

        wp_redirect(admin_url('admin.php?page=questionnaires&deleted=' . $count));
        exit;
    }

    // ========================================
    // PHASE 3: QUESTION BUILDER HANDLERS
    // ========================================

    /**
     * Save question handler
     */
    public function save_question() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;
        $questionnaire_id = isset($_POST['questionnaire_id']) ? intval($_POST['questionnaire_id']) : 0;

        // Check nonce
        check_admin_referer('save_question_' . $question_id);

        // Sanitize question data
        $data = array(
            'question_text' => sanitize_textarea_field(wp_unslash($_POST['question_text'])),
            'question_type' => sanitize_text_field($_POST['question_type']),
            'help_text' => isset($_POST['help_text']) ? sanitize_textarea_field(wp_unslash($_POST['help_text'])) : '',
            'required' => isset($_POST['required']) ? 1 : 0,
        );

        // Update question
        $success = Question_Manager::update_question($question_id, $data);

        // Handle answer options
        if (in_array($data['question_type'], array('multiple_choice', 'yes_no'))) {
            // Update existing answer options
            if (isset($_POST['answer_option_ids']) && is_array($_POST['answer_option_ids'])) {
                foreach ($_POST['answer_option_ids'] as $option_id) {
                    $option_data = array(
                        'answer_text' => sanitize_text_field($_POST['answer_texts'][$option_id]),
                    );

                    // Determine next action
                    $next_action = $_POST['next_action_type'][$option_id];
                    if ($next_action === 'question') {
                        $option_data['next_question_id'] = isset($_POST['next_question_id'][$option_id]) ? intval($_POST['next_question_id'][$option_id]) : null;
                        $option_data['outcome_id'] = null;
                    } else {
                        $option_data['next_question_id'] = null;
                        $option_data['outcome_id'] = isset($_POST['outcome_id'][$option_id]) ? intval($_POST['outcome_id'][$option_id]) : null;
                    }

                    Question_Manager::update_answer_option($option_id, $option_data);
                }
            }
        }

        // Redirect back to builder
        if ($success) {
            wp_redirect(admin_url('admin.php?page=questionnaires-builder&id=' . $questionnaire_id . '&saved=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=questionnaires-builder&id=' . $questionnaire_id . '&error=Failed to save question'));
        }
        exit;
    }

    /**
     * Save outcome handler
     */
    public function save_outcome() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $outcome_id = isset($_POST['outcome_id']) ? intval($_POST['outcome_id']) : 0;
        $questionnaire_id = isset($_POST['questionnaire_id']) ? intval($_POST['questionnaire_id']) : 0;

        // Check nonce
        check_admin_referer('save_outcome_' . $outcome_id);

        // Sanitize outcome data
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'outcome_type' => sanitize_text_field($_POST['outcome_type']),
            'guidance_text' => isset($_POST['guidance_text']) ? wp_kses_post($_POST['guidance_text']) : '',
            'resource_filter_type' => isset($_POST['resource_filter_type']) ? sanitize_text_field($_POST['resource_filter_type']) : 'none',
            'resource_filter_data' => '', // Placeholder for now
        );

        // Update outcome
        $success = Outcome_Manager::update_outcome($outcome_id, $data);

        // Redirect back to builder
        if ($success) {
            wp_redirect(admin_url('admin.php?page=questionnaires-builder&id=' . $questionnaire_id . '&saved=1#outcomes'));
        } else {
            wp_redirect(admin_url('admin.php?page=questionnaires-builder&id=' . $questionnaire_id . '&error=Failed to save outcome'));
        }
        exit;
    }

    /**
     * Set start question handler
     */
    public function set_start_question() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $questionnaire_id = isset($_POST['questionnaire_id']) ? intval($_POST['questionnaire_id']) : 0;

        // Check nonce
        check_admin_referer('set_start_question_' . $questionnaire_id);

        $start_question_id = isset($_POST['start_question_id']) ? intval($_POST['start_question_id']) : null;

        // Update questionnaire
        $success = Questionnaire_Manager::update_questionnaire($questionnaire_id, array(
            'start_question_id' => $start_question_id
        ));

        if ($success) {
            wp_redirect(admin_url('admin.php?page=questionnaires-builder&id=' . $questionnaire_id . '&saved=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=questionnaires-builder&id=' . $questionnaire_id . '&error=Failed to set start question'));
        }
        exit;
    }

    /**
     * AJAX: Add new question
     */
    public function ajax_add_new_question() {
        check_ajax_referer('questionnaire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $questionnaire_id = isset($_POST['questionnaire_id']) ? intval($_POST['questionnaire_id']) : 0;

        if (!$questionnaire_id) {
            wp_send_json_error('Invalid questionnaire ID');
        }

        // Create new question with placeholder text
        $question_id = Question_Manager::create_question(array(
            'questionnaire_id' => $questionnaire_id,
            'question_text' => 'New Question - Click Edit to customize',
            'question_type' => 'multiple_choice',
            'help_text' => '',
            'required' => 1,
            'sort_order' => 0,
        ));

        if ($question_id) {
            wp_send_json_success(array('question_id' => $question_id));
        } else {
            wp_send_json_error('Failed to create question');
        }
    }

    /**
     * AJAX: Delete question
     */
    public function ajax_delete_question() {
        check_ajax_referer('questionnaire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;

        if (!$question_id) {
            wp_send_json_error('Invalid question ID');
        }

        $success = Question_Manager::delete_question($question_id);

        if ($success) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete question');
        }
    }

    /**
     * AJAX: Add answer option
     */
    public function ajax_add_answer_option() {
        check_ajax_referer('questionnaire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $question_id = isset($_POST['question_id']) ? intval($_POST['question_id']) : 0;

        if (!$question_id) {
            wp_send_json_error('Invalid question ID');
        }

        // Create new answer option
        $option_id = Question_Manager::create_answer_option(array(
            'question_id' => $question_id,
            'answer_text' => 'New Answer Option',
            'next_question_id' => null,
            'outcome_id' => null,
            'sort_order' => 0,
        ));

        if ($option_id) {
            wp_send_json_success(array('option_id' => $option_id));
        } else {
            wp_send_json_error('Failed to create answer option');
        }
    }

    /**
     * AJAX: Delete answer option
     */
    public function ajax_delete_answer_option() {
        check_ajax_referer('questionnaire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $option_id = isset($_POST['option_id']) ? intval($_POST['option_id']) : 0;

        if (!$option_id) {
            wp_send_json_error('Invalid option ID');
        }

        $success = Question_Manager::delete_answer_option($option_id);

        if ($success) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete answer option');
        }
    }

    /**
     * AJAX: Add new outcome
     */
    public function ajax_add_new_outcome() {
        check_ajax_referer('questionnaire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $questionnaire_id = isset($_POST['questionnaire_id']) ? intval($_POST['questionnaire_id']) : 0;

        if (!$questionnaire_id) {
            wp_send_json_error('Invalid questionnaire ID');
        }

        // Create new outcome
        $outcome_id = Outcome_Manager::create_outcome(array(
            'questionnaire_id' => $questionnaire_id,
            'name' => 'New Outcome - Click Edit to customize',
            'outcome_type' => 'hybrid',
            'guidance_text' => '',
            'resource_filter_type' => 'none',
            'resource_filter_data' => '',
            'sort_order' => 0,
        ));

        if ($outcome_id) {
            wp_send_json_success(array('outcome_id' => $outcome_id));
        } else {
            wp_send_json_error('Failed to create outcome');
        }
    }

    /**
     * AJAX: Delete outcome
     */
    public function ajax_delete_outcome() {
        check_ajax_referer('questionnaire_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $outcome_id = isset($_POST['outcome_id']) ? intval($_POST['outcome_id']) : 0;

        if (!$outcome_id) {
            wp_send_json_error('Invalid outcome ID');
        }

        $success = Outcome_Manager::delete_outcome($outcome_id);

        if ($success) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete outcome');
        }
    }
}

// Initialize
new Questionnaire_Admin();
