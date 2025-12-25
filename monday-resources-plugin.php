<?php
/**
 * Plugin Name: Monday.com Resources Integration
 * Plugin URI: https://example.com
 * Description: Integrates Monday.com board data as searchable resource cards with filtering, issue reporting, and submission features
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: monday-resources
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MONDAY_RESOURCES_VERSION', '1.0.0');
define('MONDAY_RESOURCES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MONDAY_RESOURCES_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-resources-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-resource-hours-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-verification-system.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-verification-cron.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-shortcode.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-admin.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-submissions.php';

// Include questionnaire system classes (Phases 1-5)
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-questionnaire-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-question-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-outcome-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-session-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-location-service.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-questionnaire-admin.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-questionnaire-shortcode.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-questionnaire-ajax.php';

// Activation hook
register_activation_hook(__FILE__, 'monday_resources_activate');

function monday_resources_activate() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Create main resources table (replaces transient cache)
    $resources_table = $wpdb->prefix . 'resources';
    $sql_resources = "CREATE TABLE IF NOT EXISTS $resources_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        resource_name varchar(255) NOT NULL,
        organization varchar(255) DEFAULT NULL,
        is_svdp tinyint(1) DEFAULT 0,
        primary_service_type varchar(255) DEFAULT NULL,
        secondary_service_type varchar(255) DEFAULT NULL,
        phone varchar(50) DEFAULT NULL,
        phone_extension varchar(20) DEFAULT NULL,
        alternate_phone varchar(50) DEFAULT NULL,
        email varchar(255) DEFAULT NULL,
        website varchar(500) DEFAULT NULL,
        physical_address text DEFAULT NULL,
        what_they_provide text DEFAULT NULL,
        how_to_apply text DEFAULT NULL,
        documents_required text DEFAULT NULL,
        hours_of_operation text DEFAULT NULL,
        target_population text DEFAULT NULL,
        income_requirements varchar(255) DEFAULT NULL,
        residency_requirements text DEFAULT NULL,
        other_eligibility text DEFAULT NULL,
        eligibility_notes text DEFAULT NULL,
        geography varchar(255) DEFAULT NULL,
        counties_served text DEFAULT NULL,
        wait_time varchar(100) DEFAULT NULL,
        notes_and_tips text DEFAULT NULL,
        last_verified_date datetime DEFAULT NULL,
        last_verified_by bigint(20) DEFAULT NULL,
        verification_status varchar(50) DEFAULT 'unverified',
        verification_notes text DEFAULT NULL,
        verification_checklist text DEFAULT NULL,
        verification_attempt_date datetime DEFAULT NULL,
        verification_attempt_notes text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by bigint(20) DEFAULT NULL,
        updated_by bigint(20) DEFAULT NULL,
        status varchar(50) DEFAULT 'active',
        PRIMARY KEY (id),
        KEY last_verified_date (last_verified_date),
        KEY verification_status (verification_status),
        KEY geography (geography),
        KEY primary_service_type (primary_service_type),
        KEY is_svdp (is_svdp)
    ) $charset_collate;";

    // Create verification history table
    $verification_table = $wpdb->prefix . 'resource_verification_history';
    $sql_verification = "CREATE TABLE IF NOT EXISTS $verification_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        resource_id bigint(20) NOT NULL,
        verified_date datetime NOT NULL,
        verified_by bigint(20) NOT NULL,
        checklist_data text DEFAULT NULL,
        notes text DEFAULT NULL,
        verification_type varchar(50) DEFAULT 'full',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY resource_id (resource_id),
        KEY verified_date (verified_date)
    ) $charset_collate;";

    // Create table for issue reports
    $issues_table = $wpdb->prefix . 'monday_resource_issues';
    $sql_issues = "CREATE TABLE IF NOT EXISTS $issues_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        resource_name varchar(255) NOT NULL,
        resource_index int(11) NOT NULL,
        issue_type varchar(100) NOT NULL,
        issue_description text NOT NULL,
        reporter_name varchar(255) DEFAULT NULL,
        reporter_email varchar(255) DEFAULT NULL,
        status varchar(50) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Create table for new resource submissions
    $submissions_table = $wpdb->prefix . 'monday_resource_submissions';
    $sql_submissions = "CREATE TABLE IF NOT EXISTS $submissions_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        organization_name varchar(255) NOT NULL,
        contact_name varchar(255) DEFAULT NULL,
        contact_email varchar(255) DEFAULT NULL,
        contact_phone varchar(50) DEFAULT NULL,
        website varchar(255) DEFAULT NULL,
        service_type varchar(255) DEFAULT NULL,
        description text DEFAULT NULL,
        address text DEFAULT NULL,
        counties_served text DEFAULT NULL,
        status varchar(50) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Create table for structured hours of operation
    $hours_table = $wpdb->prefix . 'resource_hours';
    $sql_hours = "CREATE TABLE IF NOT EXISTS $hours_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        resource_id bigint(20) NOT NULL,
        hour_type enum('office', 'service') NOT NULL DEFAULT 'service',
        day_of_week tinyint(1) NOT NULL,
        is_closed tinyint(1) DEFAULT 0,
        open_time time DEFAULT NULL,
        close_time time DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY resource_id (resource_id),
        KEY hour_type (hour_type),
        KEY day_of_week (day_of_week),
        CONSTRAINT fk_resource_hours_resource FOREIGN KEY (resource_id)
            REFERENCES $resources_table(id) ON DELETE CASCADE
    ) $charset_collate;";

    // Create questionnaire tables
    $questionnaires_table = $wpdb->prefix . 'questionnaires';
    $sql_questionnaires = "CREATE TABLE IF NOT EXISTS $questionnaires_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        slug varchar(255) NOT NULL,
        description text DEFAULT NULL,
        geography text DEFAULT NULL,
        status varchar(50) DEFAULT 'active',
        start_question_id bigint(20) DEFAULT NULL,
        sort_order int DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by bigint(20) DEFAULT NULL,
        updated_by bigint(20) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY status (status)
    ) $charset_collate;";

    $questions_table = $wpdb->prefix . 'questionnaire_questions';
    $sql_questions = "CREATE TABLE IF NOT EXISTS $questions_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        questionnaire_id bigint(20) NOT NULL,
        question_text text NOT NULL,
        question_type varchar(50) NOT NULL,
        help_text text DEFAULT NULL,
        required tinyint(1) DEFAULT 1,
        sort_order int DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY questionnaire_id (questionnaire_id),
        CONSTRAINT fk_questions_questionnaire FOREIGN KEY (questionnaire_id)
            REFERENCES $questionnaires_table(id) ON DELETE CASCADE
    ) $charset_collate;";

    $answer_options_table = $wpdb->prefix . 'questionnaire_answer_options';
    $sql_answer_options = "CREATE TABLE IF NOT EXISTS $answer_options_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        question_id bigint(20) NOT NULL,
        answer_text varchar(500) NOT NULL,
        next_question_id bigint(20) DEFAULT NULL,
        outcome_id bigint(20) DEFAULT NULL,
        sort_order int DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY question_id (question_id),
        CONSTRAINT fk_answers_question FOREIGN KEY (question_id)
            REFERENCES $questions_table(id) ON DELETE CASCADE
    ) $charset_collate;";

    $outcomes_table = $wpdb->prefix . 'questionnaire_outcomes';
    $sql_outcomes = "CREATE TABLE IF NOT EXISTS $outcomes_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        questionnaire_id bigint(20) NOT NULL,
        name varchar(255) NOT NULL,
        outcome_type varchar(50) NOT NULL,
        guidance_text text DEFAULT NULL,
        resource_filter_type varchar(50) DEFAULT NULL,
        resource_filter_data text DEFAULT NULL,
        sort_order int DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY questionnaire_id (questionnaire_id),
        CONSTRAINT fk_outcomes_questionnaire FOREIGN KEY (questionnaire_id)
            REFERENCES $questionnaires_table(id) ON DELETE CASCADE
    ) $charset_collate;";

    $sessions_table = $wpdb->prefix . 'questionnaire_sessions';
    $sql_sessions = "CREATE TABLE IF NOT EXISTS $sessions_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id varchar(255) NOT NULL,
        questionnaire_id bigint(20) NOT NULL,
        conference varchar(255) DEFAULT NULL,
        user_id bigint(20) DEFAULT NULL,
        is_volunteer_assisted tinyint(1) DEFAULT 0,
        status varchar(50) DEFAULT 'in_progress',
        started_at datetime DEFAULT CURRENT_TIMESTAMP,
        completed_at datetime DEFAULT NULL,
        last_activity_at datetime DEFAULT CURRENT_TIMESTAMP,
        outcome_id bigint(20) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY session_id (session_id),
        KEY questionnaire_id (questionnaire_id),
        KEY conference (conference),
        KEY started_at (started_at),
        KEY status (status)
    ) $charset_collate;";

    $responses_table = $wpdb->prefix . 'questionnaire_responses';
    $sql_responses = "CREATE TABLE IF NOT EXISTS $responses_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id bigint(20) NOT NULL,
        question_id bigint(20) NOT NULL,
        answer_option_id bigint(20) DEFAULT NULL,
        answer_text text DEFAULT NULL,
        answered_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY question_id (question_id),
        CONSTRAINT fk_responses_session FOREIGN KEY (session_id)
            REFERENCES $sessions_table(id) ON DELETE CASCADE
    ) $charset_collate;";

    $resource_views_table = $wpdb->prefix . 'questionnaire_resource_views';
    $sql_resource_views = "CREATE TABLE IF NOT EXISTS $resource_views_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id bigint(20) NOT NULL,
        resource_id bigint(20) NOT NULL,
        viewed_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY resource_id (resource_id),
        CONSTRAINT fk_views_session FOREIGN KEY (session_id)
            REFERENCES $sessions_table(id) ON DELETE CASCADE
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_resources);
    dbDelta($sql_verification);
    dbDelta($sql_issues);
    dbDelta($sql_submissions);
    dbDelta($sql_hours);
    dbDelta($sql_questionnaires);
    dbDelta($sql_questions);
    dbDelta($sql_answer_options);
    dbDelta($sql_outcomes);
    dbDelta($sql_sessions);
    dbDelta($sql_responses);
    dbDelta($sql_resource_views);

    // Add hours-related columns to resources table if they don't exist
    // Check and add each column individually for MySQL compatibility
    $columns_to_add = array(
        'hours_24_7' => "ALTER TABLE $resources_table ADD hours_24_7 tinyint(1) DEFAULT 0 AFTER hours_of_operation",
        'hours_by_appointment' => "ALTER TABLE $resources_table ADD hours_by_appointment tinyint(1) DEFAULT 0 AFTER hours_24_7",
        'hours_call_for_availability' => "ALTER TABLE $resources_table ADD hours_call_for_availability tinyint(1) DEFAULT 0 AFTER hours_by_appointment",
        'hours_currently_closed' => "ALTER TABLE $resources_table ADD hours_currently_closed tinyint(1) DEFAULT 0 AFTER hours_call_for_availability",
        'hours_special_notes' => "ALTER TABLE $resources_table ADD hours_special_notes text DEFAULT NULL AFTER hours_currently_closed",
        'service_same_as_office' => "ALTER TABLE $resources_table ADD service_same_as_office tinyint(1) DEFAULT 0 AFTER hours_special_notes"
    );

    foreach ($columns_to_add as $column => $query) {
        // Check if column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $resources_table LIKE '$column'");
        if (empty($column_exists)) {
            $wpdb->query($query);
        }
    }

    // Run migration from Monday.com transient cache to new database
    monday_resources_migrate_data();
}

// Migration function to import Monday.com data
function monday_resources_migrate_data() {
    global $wpdb;

    // Check if migration already run
    $migration_done = get_option('monday_resources_migrated', false);
    if ($migration_done) {
        return;
    }

    // Get data from transient cache
    $transient_data = get_transient('monday_resources');
    if (!$transient_data || !is_array($transient_data)) {
        // Mark as migrated even if no data to prevent repeated attempts
        update_option('monday_resources_migrated', true);
        return;
    }

    $resources_table = $wpdb->prefix . 'resources';
    $migrated_count = 0;

    foreach ($transient_data as $item) {
        // Build column lookup for easier access
        $columns = array();
        if (isset($item['column_values']) && is_array($item['column_values'])) {
            foreach ($item['column_values'] as $col) {
                $columns[$col['id']] = $col;
            }
        }

        // Helper function to get column text value
        $get_col = function($col_id) use ($columns) {
            return isset($columns[$col_id]) ? $columns[$col_id]['text'] : null;
        };

        // Check if SVdP resource
        $is_svdp = 0;
        if (isset($columns['boolean_mkyhtec3'])) {
            $svdp_text = $columns['boolean_mkyhtec3']['text'];
            $svdp_value_json = $columns['boolean_mkyhtec3']['value'];

            if (!empty($svdp_text) && (strtolower($svdp_text) === 'true' || $svdp_text === '1')) {
                $is_svdp = 1;
            }
            if (!empty($svdp_value_json)) {
                $svdp_value_data = json_decode($svdp_value_json, true);
                if (isset($svdp_value_data['checked']) && $svdp_value_data['checked'] === true) {
                    $is_svdp = 1;
                }
            }
        }

        // Insert into new resources table
        $wpdb->insert(
            $resources_table,
            array(
                'resource_name' => $item['name'],
                'organization' => $get_col('dropdown_mkx1nmep'),
                'is_svdp' => $is_svdp,
                'primary_service_type' => $get_col('dropdown_mkx1c4dt'),
                'secondary_service_type' => $get_col('dropdown_mkxm3bt8'),
                'phone' => $get_col('phone_mkx162rz'),
                'phone_extension' => $get_col('numeric_mkyhh8pj'),
                'alternate_phone' => $get_col('phone_mkyh8tkc'),
                'email' => $get_col('email_mkx1akhs'),
                'website' => $get_col('link_mkx1957p'),
                'physical_address' => $get_col('location_mkx11h7c'),
                'what_they_provide' => $get_col('long_text_mkx17r67'),
                'how_to_apply' => $get_col('long_text_mkx1xxn6'),
                'documents_required' => $get_col('long_text_mkx1wdjn'),
                'hours_of_operation' => $get_col('text_mkx1tkrz'),
                'target_population' => $get_col('dropdown_mkx1ngjf'),
                'income_requirements' => $get_col('color_mkx1nefv'),
                'residency_requirements' => $get_col('text_mkx1knsa'),
                'other_eligibility' => $get_col('long_text_mkx1qaxq'),
                'eligibility_notes' => $get_col('long_text_mkx1qsy5'),
                'geography' => $get_col('dropdown_mkx1c3xe'),
                'counties_served' => $get_col('dropdown_mkx1bndy'),
                'wait_time' => $get_col('color_mkx1kpkb'),
                'notes_and_tips' => $get_col('long_text_mkx18jq8'),
                'last_verified_date' => current_time('mysql'),
                'last_verified_by' => 1, // System/admin user
                'verification_status' => 'fresh',
                'verification_notes' => 'Migrated from Monday.com',
                'created_at' => current_time('mysql'),
                'created_by' => 1,
                'status' => 'active'
            ),
            array(
                '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s'
            )
        );

        if ($wpdb->insert_id) {
            $migrated_count++;
        }
    }

    // Mark migration as complete
    update_option('monday_resources_migrated', true);
    update_option('monday_resources_migration_count', $migrated_count);

    // Log success
    error_log("Monday Resources: Successfully migrated $migrated_count resources to database");
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'monday_resources_deactivate');

function monday_resources_deactivate() {
    // Clear scheduled cron (legacy Monday.com sync)
    $timestamp = wp_next_scheduled('fetch_monday_resources');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'fetch_monday_resources');
    }

    // Clear verification cron jobs
    Verification_Cron::clear_scheduled_jobs();
}

// Initialize the plugin
add_action('plugins_loaded', 'monday_resources_init');

function monday_resources_init() {
    new Monday_Resources_Shortcode();
    new Monday_Resources_Admin();
    new Monday_Resources_Submissions();
    new Verification_System();
    new Verification_Cron();
}
