<?php
/**
 * Handle Issue Reports and Resource Submissions
 */

class Monday_Resources_Submissions {

    public function __construct() {
        add_action('wp_ajax_submit_resource_issue', array($this, 'handle_issue_submission'));
        add_action('wp_ajax_nopriv_submit_resource_issue', array($this, 'handle_issue_submission'));

        add_action('wp_ajax_submit_new_resource', array($this, 'handle_resource_submission'));
        add_action('wp_ajax_nopriv_submit_new_resource', array($this, 'handle_resource_submission'));
    }

    /**
     * Handle issue report submission
     */
    public function handle_issue_submission() {
        check_ajax_referer('monday_resources_nonce', 'nonce');

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_issues';

        $resource_name = isset($_POST['resource_name']) ? sanitize_text_field($_POST['resource_name']) : '';
        $resource_index = isset($_POST['resource_index']) ? intval($_POST['resource_index']) : 0;
        $issue_type = isset($_POST['issue_type']) ? sanitize_text_field($_POST['issue_type']) : '';
        $issue_description = isset($_POST['issue_description']) ? sanitize_textarea_field($_POST['issue_description']) : '';
        $reporter_name = isset($_POST['reporter_name']) ? sanitize_text_field($_POST['reporter_name']) : '';
        $reporter_email = isset($_POST['reporter_email']) ? sanitize_email($_POST['reporter_email']) : '';

        // Validate required fields
        if (empty($resource_name) || empty($issue_type) || empty($issue_description)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
            return;
        }

        // Insert into database
        $result = $wpdb->insert(
            $table_name,
            array(
                'resource_name' => $resource_name,
                'resource_index' => $resource_index,
                'issue_type' => $issue_type,
                'issue_description' => $issue_description,
                'reporter_name' => $reporter_name,
                'reporter_email' => $reporter_email,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to submit report. Please try again.'));
        } else {
            wp_send_json_success(array('message' => 'Thank you! Your report has been submitted.'));
        }
    }

    /**
     * Handle new resource submission
     */
    public function handle_resource_submission() {
        check_ajax_referer('monday_resources_nonce', 'nonce');

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_submissions';

        $organization_name = isset($_POST['organization_name']) ? sanitize_text_field($_POST['organization_name']) : '';
        $contact_name = isset($_POST['contact_name']) ? sanitize_text_field($_POST['contact_name']) : '';
        $contact_email = isset($_POST['contact_email']) ? sanitize_email($_POST['contact_email']) : '';
        $contact_phone = isset($_POST['contact_phone']) ? sanitize_text_field($_POST['contact_phone']) : '';
        $website = isset($_POST['website']) ? esc_url_raw($_POST['website']) : '';
        $service_type = isset($_POST['service_type']) ? sanitize_text_field($_POST['service_type']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $address = isset($_POST['address']) ? sanitize_textarea_field($_POST['address']) : '';
        $counties_served = isset($_POST['counties_served']) ? sanitize_text_field($_POST['counties_served']) : '';

        // Validate required fields
        if (empty($organization_name) || empty($service_type) || empty($description)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
            return;
        }

        // Insert into database
        $result = $wpdb->insert(
            $table_name,
            array(
                'organization_name' => $organization_name,
                'contact_name' => $contact_name,
                'contact_email' => $contact_email,
                'contact_phone' => $contact_phone,
                'website' => $website,
                'service_type' => $service_type,
                'description' => $description,
                'address' => $address,
                'counties_served' => $counties_served,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to submit resource. Please try again.'));
        } else {
            wp_send_json_success(array('message' => 'Thank you! Your resource has been submitted for review.'));
        }
    }
}
