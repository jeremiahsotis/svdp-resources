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

        // Add approve & publish handler
        add_action('admin_post_approve_and_publish_submission', array($this, 'approve_and_publish_submission'));
    }

    /**
     * Capability for resource-management actions.
     *
     * @return string
     */
    private function get_resource_capability() {
        if (function_exists('monday_resources_get_manage_capability')) {
            return monday_resources_get_manage_capability();
        }
        return 'manage_options';
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

    /**
     * Approve and publish a submission to the main resources database
     */
    public function approve_and_publish_submission() {
        // Check permissions
        if (!current_user_can($this->get_resource_capability())) {
            wp_die('Unauthorized');
        }

        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;

        if (!$submission_id) {
            wp_die('Invalid submission ID');
        }

        check_admin_referer('approve_submission_' . $submission_id);

        global $wpdb;
        $submissions_table = $wpdb->prefix . 'monday_resource_submissions';

        // Get the submission
        $submission = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $submissions_table WHERE id = %d",
            $submission_id
        ));

        if (!$submission) {
            wp_die('Submission not found');
        }

        $service_area_pipe = '';
        $services_offered_pipe = '';
        $legacy_primary_service_type = sanitize_text_field((string) $submission->service_type);
        $legacy_secondary_service_type = '';

        if (class_exists('Resource_Taxonomy')) {
            $service_area_slugs = Resource_Taxonomy::normalize_service_area_slugs(array($submission->service_type));
            $service_area_pipe = Resource_Taxonomy::to_pipe_slug_string($service_area_slugs);
            $services_offered_slugs = Resource_Taxonomy::normalize_services_offered_slugs(array($submission->service_type));
            $services_offered_pipe = Resource_Taxonomy::to_pipe_slug_string($services_offered_slugs);

            $service_area_terms = Resource_Taxonomy::get_service_area_terms();
            if (!empty($service_area_slugs)) {
                $first_service_area_slug = $service_area_slugs[0];
                if (isset($service_area_terms[$first_service_area_slug])) {
                    $legacy_primary_service_type = $service_area_terms[$first_service_area_slug];
                }
            }

            $services_terms = Resource_Taxonomy::get_services_offered_terms();
            $legacy_services = array();
            foreach ($services_offered_slugs as $service_slug) {
                if (isset($services_terms[$service_slug])) {
                    $legacy_services[] = $services_terms[$service_slug];
                }
            }
            $legacy_secondary_service_type = implode(', ', $legacy_services);
        }

        // Create the resource in the main database.
        // Resources are marked as verified at the moment of entry
        $resource_id = Resources_Manager::create_resource(array(
            'resource_name' => $submission->organization_name,
            'primary_service_type' => $legacy_primary_service_type,
            'secondary_service_type' => $legacy_secondary_service_type,
            'service_area' => $service_area_pipe,
            'services_offered' => $services_offered_pipe,
            'website' => $submission->website,
            'phone' => $submission->contact_phone,
            'email' => $submission->contact_email,
            'physical_address' => $submission->address,
            'counties_served' => $submission->counties_served,
            'what_they_provide' => $submission->description,
            'last_verified_date' => current_time('mysql'),
            'last_verified_by' => get_current_user_id(),
            'verification_status' => 'fresh',
            'verification_notes' => 'Resource approved from user submission',
            'created_by' => get_current_user_id()
        ));

        if ($resource_id) {
            // Update submission status to approved
            $wpdb->update(
                $submissions_table,
                array('status' => 'approved'),
                array('id' => $submission_id),
                array('%s'),
                array('%d')
            );

            // Redirect with success message
            $redirect_url = add_query_arg(
                array(
                    'page' => 'monday-resources-submissions',
                    'published' => '1',
                    'resource_id' => $resource_id
                ),
                admin_url('admin.php')
            );
        } else {
            // Redirect with error message
            $redirect_url = add_query_arg(
                array(
                    'page' => 'monday-resources-submissions',
                    'error' => '1'
                ),
                admin_url('admin.php')
            );
        }

        wp_redirect($redirect_url);
        exit;
    }
}
