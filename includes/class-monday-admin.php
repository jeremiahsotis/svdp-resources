<?php
/**
 * Admin Settings and Dashboard Class
 */

class Monday_Resources_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_delete_issue', array($this, 'delete_issue'));
        add_action('admin_post_delete_submission', array($this, 'delete_submission'));
        add_action('admin_post_update_issue_status', array($this, 'update_issue_status'));
        add_action('admin_post_update_submission_status', array($this, 'update_submission_status'));
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_menu_page(
            'Monday Resources',
            'Monday Resources',
            'manage_options',
            'monday-resources',
            array($this, 'settings_page'),
            'dashicons-list-view',
            30
        );

        add_submenu_page(
            'monday-resources',
            'Settings',
            'Settings',
            'manage_options',
            'monday-resources',
            array($this, 'settings_page')
        );

        add_submenu_page(
            'monday-resources',
            'Issue Reports',
            'Issue Reports',
            'manage_options',
            'monday-resources-issues',
            array($this, 'issues_page')
        );

        add_submenu_page(
            'monday-resources',
            'Resource Submissions',
            'Resource Submissions',
            'manage_options',
            'monday-resources-submissions',
            array($this, 'submissions_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('monday_resources_settings', 'monday_api_token');
        register_setting('monday_resources_settings', 'monday_board_id');

        add_settings_section(
            'monday_resources_api_section',
            'Monday.com API Configuration',
            array($this, 'api_section_callback'),
            'monday_resources_settings'
        );

        add_settings_field(
            'monday_api_token',
            'API Token',
            array($this, 'api_token_field'),
            'monday_resources_settings',
            'monday_resources_api_section'
        );

        add_settings_field(
            'monday_board_id',
            'Board ID',
            array($this, 'board_id_field'),
            'monday_resources_settings',
            'monday_resources_api_section'
        );
    }

    public function api_section_callback() {
        echo '<p>Enter your Monday.com API credentials to sync resources.</p>';
    }

    public function api_token_field() {
        $value = get_option('monday_api_token', '');
        echo '<input type="text" name="monday_api_token" value="' . esc_attr($value) . '" size="80" />';
        echo '<p class="description">Your Monday.com API token</p>';
    }

    public function board_id_field() {
        $value = get_option('monday_board_id', '');
        echo '<input type="text" name="monday_board_id" value="' . esc_attr($value) . '" />';
        echo '<p class="description">The Monday.com board ID to sync from</p>';
    }

    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Monday Resources Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('monday_resources_settings');
                do_settings_sections('monday_resources_settings');
                submit_button();
                ?>
            </form>

            <hr>

            <h2>Manual Sync</h2>
            <p>Click the button below to manually sync resources from Monday.com.</p>
            <form method="post" action="">
                <?php wp_nonce_field('manual_sync', 'manual_sync_nonce'); ?>
                <button type="submit" name="manual_sync" class="button button-primary">Sync Now</button>
            </form>

            <?php
            if (isset($_POST['manual_sync']) && check_admin_referer('manual_sync', 'manual_sync_nonce')) {
                $api = new Monday_Resources_API();
                $api->fetch_monday_data();
                echo '<div class="notice notice-success"><p>Resources synced successfully!</p></div>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Issue reports page
     */
    public function issues_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_issues';

        // Get all issues ordered by newest first
        $issues = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

        ?>
        <div class="wrap">
            <h1>Issue Reports</h1>
            <p>Review issues reported by users about resource listings.</p>

            <?php if (empty($issues)): ?>
                <p>No issues reported yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Resource Name</th>
                            <th>Issue Type</th>
                            <th>Description</th>
                            <th>Reporter</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issues as $issue): ?>
                            <tr>
                                <td><?php echo esc_html(date('M j, Y g:i a', strtotime($issue->created_at))); ?></td>
                                <td><strong><?php echo esc_html($issue->resource_name); ?></strong></td>
                                <td><?php echo esc_html($issue->issue_type); ?></td>
                                <td><?php echo esc_html($issue->issue_description); ?></td>
                                <td>
                                    <?php if (!empty($issue->reporter_name)): ?>
                                        <?php echo esc_html($issue->reporter_name); ?>
                                        <?php if (!empty($issue->reporter_email)): ?>
                                            <br><a href="mailto:<?php echo esc_attr($issue->reporter_email); ?>"><?php echo esc_html($issue->reporter_email); ?></a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Anonymous
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="update_issue_status">
                                        <input type="hidden" name="issue_id" value="<?php echo esc_attr($issue->id); ?>">
                                        <?php wp_nonce_field('update_issue_status_' . $issue->id); ?>
                                        <select name="status" onchange="this.form.submit()">
                                            <option value="pending" <?php selected($issue->status, 'pending'); ?>>Pending</option>
                                            <option value="in_progress" <?php selected($issue->status, 'in_progress'); ?>>In Progress</option>
                                            <option value="resolved" <?php selected($issue->status, 'resolved'); ?>>Resolved</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_issue">
                                        <input type="hidden" name="issue_id" value="<?php echo esc_attr($issue->id); ?>">
                                        <?php wp_nonce_field('delete_issue_' . $issue->id); ?>
                                        <button type="submit" class="button button-small" onclick="return confirm('Are you sure you want to delete this issue report?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Resource submissions page
     */
    public function submissions_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_submissions';

        // Get all submissions ordered by newest first
        $submissions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

        ?>
        <div class="wrap">
            <h1>Resource Submissions</h1>
            <p>Review new resources submitted by users.</p>

            <?php if (empty($submissions)): ?>
                <p>No submissions yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Organization</th>
                            <th>Contact</th>
                            <th>Service Type</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td><?php echo esc_html(date('M j, Y g:i a', strtotime($submission->created_at))); ?></td>
                                <td><strong><?php echo esc_html($submission->organization_name); ?></strong></td>
                                <td>
                                    <?php if (!empty($submission->contact_name)): ?>
                                        <?php echo esc_html($submission->contact_name); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->contact_email)): ?>
                                        <a href="mailto:<?php echo esc_attr($submission->contact_email); ?>"><?php echo esc_html($submission->contact_email); ?></a><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->contact_phone)): ?>
                                        <a href="tel:<?php echo esc_attr($submission->contact_phone); ?>"><?php echo esc_html($submission->contact_phone); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($submission->service_type); ?></td>
                                <td>
                                    <?php if (!empty($submission->website)): ?>
                                        <strong>Website:</strong> <a href="<?php echo esc_url($submission->website); ?>" target="_blank"><?php echo esc_html($submission->website); ?></a><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->description)): ?>
                                        <strong>Description:</strong> <?php echo esc_html($submission->description); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->address)): ?>
                                        <strong>Address:</strong> <?php echo esc_html($submission->address); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->counties_served)): ?>
                                        <strong>Counties:</strong> <?php echo esc_html($submission->counties_served); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="update_submission_status">
                                        <input type="hidden" name="submission_id" value="<?php echo esc_attr($submission->id); ?>">
                                        <?php wp_nonce_field('update_submission_status_' . $submission->id); ?>
                                        <select name="status" onchange="this.form.submit()">
                                            <option value="pending" <?php selected($submission->status, 'pending'); ?>>Pending</option>
                                            <option value="approved" <?php selected($submission->status, 'approved'); ?>>Approved</option>
                                            <option value="rejected" <?php selected($submission->status, 'rejected'); ?>>Rejected</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_submission">
                                        <input type="hidden" name="submission_id" value="<?php echo esc_attr($submission->id); ?>">
                                        <?php wp_nonce_field('delete_submission_' . $submission->id); ?>
                                        <button type="submit" class="button button-small" onclick="return confirm('Are you sure you want to delete this submission?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Delete issue
     */
    public function delete_issue() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $issue_id = isset($_POST['issue_id']) ? intval($_POST['issue_id']) : 0;
        check_admin_referer('delete_issue_' . $issue_id);

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_issues';
        $wpdb->delete($table_name, array('id' => $issue_id), array('%d'));

        wp_redirect(admin_url('admin.php?page=monday-resources-issues'));
        exit;
    }

    /**
     * Delete submission
     */
    public function delete_submission() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        check_admin_referer('delete_submission_' . $submission_id);

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_submissions';
        $wpdb->delete($table_name, array('id' => $submission_id), array('%d'));

        wp_redirect(admin_url('admin.php?page=monday-resources-submissions'));
        exit;
    }

    /**
     * Update issue status
     */
    public function update_issue_status() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $issue_id = isset($_POST['issue_id']) ? intval($_POST['issue_id']) : 0;
        check_admin_referer('update_issue_status_' . $issue_id);

        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_issues';
        $wpdb->update(
            $table_name,
            array('status' => $status),
            array('id' => $issue_id),
            array('%s'),
            array('%d')
        );

        wp_redirect(admin_url('admin.php?page=monday-resources-issues'));
        exit;
    }

    /**
     * Update submission status
     */
    public function update_submission_status() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        check_admin_referer('update_submission_status_' . $submission_id);

        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_submissions';
        $wpdb->update(
            $table_name,
            array('status' => $status),
            array('id' => $submission_id),
            array('%s'),
            array('%d')
        );

        wp_redirect(admin_url('admin.php?page=monday-resources-submissions'));
        exit;
    }
}
