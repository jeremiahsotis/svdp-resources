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
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-api.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-shortcode.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-admin.php';
require_once MONDAY_RESOURCES_PLUGIN_DIR . 'includes/class-monday-submissions.php';

// Activation hook
register_activation_hook(__FILE__, 'monday_resources_activate');

function monday_resources_activate() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

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

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_issues);
    dbDelta($sql_submissions);
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'monday_resources_deactivate');

function monday_resources_deactivate() {
    // Clear scheduled cron
    $timestamp = wp_next_scheduled('fetch_monday_resources');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'fetch_monday_resources');
    }
}

// Initialize the plugin
add_action('plugins_loaded', 'monday_resources_init');

function monday_resources_init() {
    new Monday_Resources_API();
    new Monday_Resources_Shortcode();
    new Monday_Resources_Admin();
    new Monday_Resources_Submissions();
}
