<?php
/**
 * Plugin Name: Monday.com Resources Integration
 * Plugin URI: https://example.com
 * Description: Integrates Monday.com board data as searchable resource cards with filtering, issue reporting, and submission features
 * Version: 1.2.0
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
define('MONDAY_RESOURCES_VERSION', '1.2.0');
define('MONDAY_RESOURCES_DB_SCHEMA_VERSION', '1.2.0');
define('MONDAY_RESOURCES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MONDAY_RESOURCES_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-resource-taxonomy.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-resource-organization-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-resource-snapshot-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-resources-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-resource-hours-manager.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-verification-system.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-verification-cron.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-shortcode.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-admin.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-submissions.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-resource-exporter.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-resource-taxonomy-import.php';

// Include Composer autoloader if available (for Excel/PDF export)
if (file_exists(MONDAY_RESOURCES_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once MONDAY_RESOURCES_PLUGIN_DIR . 'vendor/autoload.php';
}

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

// Run migration checks in all contexts (not admin-only), with admin fallback.
add_action('plugins_loaded', 'monday_resources_maybe_upgrade_db_bootstrap', 5);
add_action('admin_init', 'monday_resources_maybe_upgrade_db_bootstrap', 1);
add_action('init', 'monday_resources_maybe_flush_rewrite_rules', 99);

/**
 * Capability used for managing resources in admin.
 *
 * @return string
 */
function monday_resources_get_manage_capability() {
    return 'svdp_manage_resources';
}

/**
 * Capability used for snapshot creation/sharing actions on the directory.
 *
 * @return string
 */
function monday_resources_get_snapshot_capability() {
    return 'edit_view_resources';
}

/**
 * Ensure role and capability assignments exist for resource managers.
 *
 * @return void
 */
function monday_resources_register_resource_manager_role() {
    $capability = monday_resources_get_manage_capability();
    $snapshot_capability = monday_resources_get_snapshot_capability();
    $resource_caps = array(
        $capability => true,
        $snapshot_capability => true,
        'read' => true,
        'upload_files' => true
    );

    $role = get_role('svdp_resource_manager');
    if (!$role) {
        add_role(
            'svdp_resource_manager',
            'SVdP Resource Manager',
            $resource_caps
        );
    } else {
        foreach ($resource_caps as $cap => $grant) {
            $role->add_cap($cap, $grant);
        }
    }

    $admin_role = get_role('administrator');
    if ($admin_role) {
        $admin_role->add_cap($capability, true);
        $admin_role->add_cap($snapshot_capability, true);
    }
}

/**
 * Ensure snapshot and organization schema exists.
 *
 * @return void
 */
function monday_resources_ensure_snapshot_schema() {
    if (class_exists('Resource_Snapshot_Manager')) {
        Resource_Snapshot_Manager::ensure_snapshot_schema();
    }
}

/**
 * Ensure taxonomy-related columns and indexes exist on resources table.
 *
 * @return void
 */
function monday_resources_ensure_resource_taxonomy_schema() {
    global $wpdb;
    $resources_table = $wpdb->prefix . 'resources';

    $columns = array(
        'service_area' => "ALTER TABLE $resources_table ADD COLUMN service_area VARCHAR(191) NOT NULL DEFAULT '' AFTER secondary_service_type",
        'services_offered' => "ALTER TABLE $resources_table ADD COLUMN services_offered TEXT NULL AFTER service_area",
        'provider_type' => "ALTER TABLE $resources_table ADD COLUMN provider_type VARCHAR(191) DEFAULT NULL AFTER services_offered"
    );

    foreach ($columns as $column => $query) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $resources_table LIKE %s", $column));
        if (!$exists) {
            $wpdb->query($query);
        }
    }

    $indexes = array(
        'idx_resources_service_area' => "ALTER TABLE $resources_table ADD INDEX idx_resources_service_area (service_area)",
        'idx_resources_provider_type' => "ALTER TABLE $resources_table ADD INDEX idx_resources_provider_type (provider_type)",
        'idx_resources_services_offered_prefix' => "ALTER TABLE $resources_table ADD INDEX idx_resources_services_offered_prefix (services_offered(100))"
    );

    foreach ($indexes as $index_name => $query) {
        $index_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM $resources_table WHERE Key_name = %s",
                $index_name
            )
        );
        if (!$index_exists) {
            $wpdb->query($query);
        }
    }
}

/**
 * Ensure import audit table exists for taxonomy migration runs.
 *
 * @return void
 */
function monday_resources_ensure_taxonomy_import_audit_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'resources_taxonomy_import_audit';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        import_run_id varchar(64) NOT NULL,
        resource_id bigint(20) DEFAULT NULL,
        action varchar(50) NOT NULL,
        field_name varchar(100) DEFAULT NULL,
        old_value longtext DEFAULT NULL,
        new_value longtext DEFAULT NULL,
        `row_number` int(11) DEFAULT NULL,
        source_file varchar(255) DEFAULT NULL,
        source_file_hash varchar(64) DEFAULT NULL,
        raw_row_json longtext DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_import_run_id (import_run_id),
        KEY idx_resource_id (resource_id),
        KEY idx_action (action)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Assess migration/schema health.
 *
 * @return array
 */
function monday_resources_get_schema_health() {
    global $wpdb;

    $health = array(
        'ok' => true,
        'details' => array()
    );

    $resources_table = $wpdb->prefix . 'resources';
    $audit_table = $wpdb->prefix . 'resources_taxonomy_import_audit';
    $snapshot_table = $wpdb->prefix . 'svdpr_snapshots';
    $organizations_table = $wpdb->prefix . 'svdpr_organizations';

    $resources_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $resources_table));
    if ($resources_exists !== $resources_table) {
        $health['ok'] = false;
        $health['details'][] = 'missing_table:' . $resources_table;
        return $health;
    }

    $required_columns = array('service_area', 'services_offered', 'provider_type');
    foreach ($required_columns as $column) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $resources_table LIKE %s", $column));
        if (!$exists) {
            $health['ok'] = false;
            $health['details'][] = 'missing_column:' . $column;
        }
    }

    $required_indexes = array(
        'idx_resources_service_area',
        'idx_resources_provider_type',
        'idx_resources_services_offered_prefix',
        'idx_resources_organization_id'
    );
    foreach ($required_indexes as $index_name) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM $resources_table WHERE Key_name = %s",
                $index_name
            )
        );
        if (!$exists) {
            $health['ok'] = false;
            $health['details'][] = 'missing_index:' . $index_name;
        }
    }

    $audit_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $audit_table));
    if ($audit_exists !== $audit_table) {
        $health['ok'] = false;
        $health['details'][] = 'missing_table:' . $audit_table;
    }

    $snapshot_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $snapshot_table));
    if ($snapshot_exists !== $snapshot_table) {
        $health['ok'] = false;
        $health['details'][] = 'missing_table:' . $snapshot_table;
    }

    $organizations_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $organizations_table));
    if ($organizations_exists !== $organizations_table) {
        $health['ok'] = false;
        $health['details'][] = 'missing_table:' . $organizations_table;
    }

    $organization_column_exists = $wpdb->get_var("SHOW COLUMNS FROM $resources_table LIKE 'organization_id'");
    if (!$organization_column_exists) {
        $health['ok'] = false;
        $health['details'][] = 'missing_column:organization_id';
    }

    return $health;
}

/**
 * Persist migration diagnostics when schema is unhealthy.
 *
 * @param array $schema_health
 * @return void
 */
function monday_resources_record_schema_health($schema_health) {
    if (!is_array($schema_health) || empty($schema_health['ok'])) {
        $payload = array(
            'recorded_at' => current_time('mysql'),
            'details' => isset($schema_health['details']) && is_array($schema_health['details']) ? $schema_health['details'] : array('unknown')
        );
        update_option('monday_resources_last_migration_error', $payload, false);
        error_log('Monday Resources migration health check failed: ' . wp_json_encode($payload));
        return;
    }

    delete_option('monday_resources_last_migration_error');
}

/**
 * Admin notice for migration failures.
 *
 * @return void
 */
function monday_resources_migration_admin_notice() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    $error = get_option('monday_resources_last_migration_error', array());
    if (empty($error) || !is_array($error)) {
        return;
    }

    $details = isset($error['details']) && is_array($error['details']) ? implode(', ', $error['details']) : 'unknown';
    ?>
    <div class="notice notice-error">
        <p>
            <strong>SVdP Resources migration check failed.</strong>
            <?php echo esc_html('Details: ' . $details); ?>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'monday_resources_migration_admin_notice');

/**
 * Migration bootstrap wrapper with a short lock to prevent concurrent upgrades.
 *
 * @return void
 */
function monday_resources_maybe_upgrade_db_bootstrap() {
    static $ran = false;
    if ($ran || wp_installing()) {
        return;
    }

    $ran = true;
    $lock_key = 'monday_resources_db_upgrade_lock';
    $has_lock = get_transient($lock_key);
    if ($has_lock) {
        return;
    }

    set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);
    try {
        monday_resources_maybe_upgrade_db();
    } catch (Throwable $e) {
        $payload = array(
            'recorded_at' => current_time('mysql'),
            'details' => array('exception:' . $e->getMessage())
        );
        update_option('monday_resources_last_migration_error', $payload, false);
        error_log('Monday Resources migration bootstrap exception: ' . $e->getMessage());
    }

    delete_transient($lock_key);
}

function monday_resources_maybe_upgrade_db() {
    global $wpdb;
    $db_version = get_option('monday_resources_db_version', '1.0.0');
    $resources_table = $wpdb->prefix . 'resources';
    $resources_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $resources_table));

    // If activation hook was skipped on deploy, create required tables and continue upgrades.
    if ($resources_table_exists !== $resources_table) {
        monday_resources_activate();
        $db_version = get_option('monday_resources_db_version', '1.0.0');
    }

    // Upgrade to 1.0.4 - Questionnaire columns
    if (version_compare($db_version, '1.0.4', '<')) {
        $questions_table = $wpdb->prefix . 'questionnaire_questions';

        // Add next_question_id column if it doesn't exist
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $questions_table LIKE 'next_question_id'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $questions_table ADD COLUMN next_question_id bigint(20) DEFAULT NULL AFTER required");
        }

        // Add outcome_id column if it doesn't exist
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $questions_table LIKE 'outcome_id'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $questions_table ADD COLUMN outcome_id bigint(20) DEFAULT NULL AFTER next_question_id");
        }

        // Update version
        update_option('monday_resources_db_version', '1.0.4');
        $db_version = '1.0.4';
    }

    // Upgrade to 1.0.7 - Enhanced hours system with complex scheduling patterns
    if (version_compare($db_version, '1.0.7', '<')) {
        $hours_table = $wpdb->prefix . 'resource_hours';
        $resources_table = $wpdb->prefix . 'resources';

        // Part A: Add columns to wp_resource_hours table for complex scheduling
        $hours_columns_to_add = array(
            'recurrence_pattern' => "ALTER TABLE $hours_table ADD COLUMN recurrence_pattern varchar(20) DEFAULT 'weekly' AFTER hour_type",
            'recurrence_interval' => "ALTER TABLE $hours_table ADD COLUMN recurrence_interval int DEFAULT 1 AFTER recurrence_pattern",
            'recurrence_week_of_month' => "ALTER TABLE $hours_table ADD COLUMN recurrence_week_of_month tinyint(1) DEFAULT NULL AFTER recurrence_interval",
            'recurrence_day_of_month' => "ALTER TABLE $hours_table ADD COLUMN recurrence_day_of_month tinyint(2) DEFAULT NULL AFTER recurrence_week_of_month",
            'block_label' => "ALTER TABLE $hours_table ADD COLUMN block_label varchar(100) DEFAULT NULL AFTER recurrence_day_of_month",
            'sort_order' => "ALTER TABLE $hours_table ADD COLUMN sort_order tinyint DEFAULT 0 AFTER block_label"
        );

        foreach ($hours_columns_to_add as $column => $query) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $hours_table LIKE '$column'");
            if (empty($column_exists)) {
                $wpdb->query($query);
            }
        }

        // Add indexes for performance
        $wpdb->query("ALTER TABLE $hours_table ADD INDEX idx_recurrence_pattern (recurrence_pattern)");
        $wpdb->query("ALTER TABLE $hours_table ADD INDEX idx_sort_order (resource_id, hour_type, day_of_week, sort_order)");

        // Part B: Add service-specific flag columns to wp_resources table
        $service_flag_columns = array(
            'service_hours_24_7' => "ALTER TABLE $resources_table ADD COLUMN service_hours_24_7 tinyint(1) DEFAULT 0 AFTER service_same_as_office",
            'service_hours_by_appointment' => "ALTER TABLE $resources_table ADD COLUMN service_hours_by_appointment tinyint(1) DEFAULT 0 AFTER service_hours_24_7",
            'service_hours_call_for_availability' => "ALTER TABLE $resources_table ADD COLUMN service_hours_call_for_availability tinyint(1) DEFAULT 0 AFTER service_hours_by_appointment",
            'service_hours_currently_closed' => "ALTER TABLE $resources_table ADD COLUMN service_hours_currently_closed tinyint(1) DEFAULT 0 AFTER service_hours_call_for_availability",
            'service_hours_special_notes' => "ALTER TABLE $resources_table ADD COLUMN service_hours_special_notes text DEFAULT NULL AFTER service_hours_currently_closed"
        );

        foreach ($service_flag_columns as $column => $query) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $resources_table LIKE '$column'");
            if (empty($column_exists)) {
                $wpdb->query($query);
            }
        }

        // Update version
        update_option('monday_resources_db_version', '1.0.7');
        error_log('Monday Resources: Database upgraded to version 1.0.7 - Enhanced hours system');
        $db_version = '1.0.7';
    }

    // Upgrade to 1.1.0 - taxonomy schema + canonical vocab + import audit + role capabilities
    if (version_compare($db_version, '1.1.0', '<')) {
        monday_resources_ensure_resource_taxonomy_schema();
        monday_resources_ensure_taxonomy_import_audit_table();
        monday_resources_register_resource_manager_role();
        Resource_Taxonomy::seed_canonical_options();

        update_option('monday_resources_db_version', '1.1.0');
        $db_version = '1.1.0';
        error_log('Monday Resources: Database upgraded to version 1.1.0 - taxonomy + browse filters');
    }

    // Upgrade to latest schema version.
    if (version_compare($db_version, MONDAY_RESOURCES_DB_SCHEMA_VERSION, '<')) {
        monday_resources_ensure_resource_taxonomy_schema();
        monday_resources_ensure_taxonomy_import_audit_table();
        monday_resources_ensure_snapshot_schema();
        monday_resources_register_resource_manager_role();
        Resource_Taxonomy::seed_canonical_options();
        update_option('monday_resources_flush_rewrite_needed', 1, false);

        update_option('monday_resources_db_version', MONDAY_RESOURCES_DB_SCHEMA_VERSION);
        $db_version = MONDAY_RESOURCES_DB_SCHEMA_VERSION;
        error_log('Monday Resources: Database upgraded to version ' . MONDAY_RESOURCES_DB_SCHEMA_VERSION . ' - snapshots + sharing + inline save support');
    }

    // Periodic self-heal (every 6 hours) to catch drift or interrupted deploys.
    $last_self_heal = (int) get_option('monday_resources_last_schema_self_heal', 0);
    $self_heal_interval = 6 * HOUR_IN_SECONDS;
    $run_self_heal = (defined('WP_CLI') && WP_CLI) || ($last_self_heal < (time() - $self_heal_interval));

    if ($run_self_heal) {
        monday_resources_ensure_resource_taxonomy_schema();
        monday_resources_ensure_taxonomy_import_audit_table();
        monday_resources_ensure_snapshot_schema();
        monday_resources_register_resource_manager_role();
        Resource_Taxonomy::seed_canonical_options();
        update_option('monday_resources_last_schema_self_heal', time());
    }

    monday_resources_record_schema_health(monday_resources_get_schema_health());
}

function monday_resources_activate() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Create main resources table (replaces transient cache)
    $resources_table = $wpdb->prefix . 'resources';
    $sql_resources = "CREATE TABLE IF NOT EXISTS $resources_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        resource_name varchar(255) NOT NULL,
        organization varchar(255) DEFAULT NULL,
        organization_id bigint(20) unsigned DEFAULT NULL,
        is_svdp tinyint(1) DEFAULT 0,
        primary_service_type varchar(255) DEFAULT NULL,
        secondary_service_type varchar(255) DEFAULT NULL,
        service_area varchar(191) NOT NULL DEFAULT '',
        services_offered text DEFAULT NULL,
        provider_type varchar(191) DEFAULT NULL,
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
        KEY service_area (service_area),
        KEY provider_type (provider_type),
        KEY idx_resources_organization_id (organization_id),
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
        next_question_id bigint(20) DEFAULT NULL,
        outcome_id bigint(20) DEFAULT NULL,
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

    // Add next_question_id and outcome_id to questions table (for text and info_only question types)
    $questions_table = $wpdb->prefix . 'questionnaire_questions';
    $questions_columns_to_add = array(
        'next_question_id' => "ALTER TABLE $questions_table ADD next_question_id bigint(20) DEFAULT NULL AFTER required",
        'outcome_id' => "ALTER TABLE $questions_table ADD outcome_id bigint(20) DEFAULT NULL AFTER next_question_id"
    );

    foreach ($questions_columns_to_add as $column => $query) {
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $questions_table LIKE '$column'");
        if (empty($column_exists)) {
            $wpdb->query($query);
        }
    }

    monday_resources_ensure_resource_taxonomy_schema();
    monday_resources_ensure_taxonomy_import_audit_table();
    monday_resources_ensure_snapshot_schema();
    monday_resources_register_resource_manager_role();
    Resource_Taxonomy::seed_canonical_options();
    update_option('monday_resources_db_version', MONDAY_RESOURCES_DB_SCHEMA_VERSION);
    update_option('monday_resources_flush_rewrite_needed', 1, false);

    if (class_exists('Resource_Snapshot_Manager')) {
        Resource_Snapshot_Manager::register_rewrite_rules();
    }
    flush_rewrite_rules(false);

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

/**
 * Flush rewrite rules when flagged by activation/upgrade.
 *
 * @return void
 */
function monday_resources_maybe_flush_rewrite_rules() {
    $needs_flush = (int) get_option('monday_resources_flush_rewrite_needed', 0);
    if ($needs_flush !== 1) {
        return;
    }

    if (class_exists('Resource_Snapshot_Manager')) {
        Resource_Snapshot_Manager::register_rewrite_rules();
    }

    flush_rewrite_rules(false);
    delete_option('monday_resources_flush_rewrite_needed');
}

/**
 * WP-CLI command to force schema migrations.
 *
 * Usage:
 * wp monday-resources migrate-db [--force]
 *
 * @param array $args
 * @param array $assoc_args
 * @return void
 */
function monday_resources_wpcli_migrate_db($args, $assoc_args) {
    if (!empty($assoc_args['force'])) {
        delete_transient('monday_resources_db_upgrade_lock');
    }

    monday_resources_maybe_upgrade_db();
    $db_version = get_option('monday_resources_db_version', 'unknown');
    $schema_health = monday_resources_get_schema_health();
    $last_error = get_option('monday_resources_last_migration_error', array());

    if (!empty($schema_health['ok'])) {
        WP_CLI::success('Monday Resources DB migration complete. Current version: ' . $db_version);
        return;
    }

    $details = isset($schema_health['details']) && is_array($schema_health['details']) ? implode(', ', $schema_health['details']) : 'unknown';
    WP_CLI::warning('Schema is still unhealthy after migration: ' . $details);

    if (!empty($last_error) && is_array($last_error)) {
        WP_CLI::warning('Last migration error payload: ' . wp_json_encode($last_error));
    }
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_command(
        'monday-resources migrate-db',
        'monday_resources_wpcli_migrate_db',
        array(
            'shortdesc' => 'Force-run SVdP Resources DB migrations and print health status.',
            'synopsis' => array(
                array(
                    'type' => 'flag',
                    'name' => 'force',
                    'optional' => true,
                    'description' => 'Clear the migration lock before running.'
                )
            )
        )
    );
}

// Initialize the plugin
add_action('plugins_loaded', 'monday_resources_init');

function monday_resources_init() {
    new Resource_Snapshot_Manager();
    new Monday_Resources_Shortcode();
    new Monday_Resources_Admin();
    new Monday_Resources_Submissions();
    new Verification_System();
    new Verification_Cron();
}
