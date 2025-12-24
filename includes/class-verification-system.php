<?php
/**
 * Verification System Class
 * Handles verification UI, dashboard widgets, and verification processing
 */

class Verification_System {

    public function __construct() {
        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // Add verification processing handler
        add_action('admin_post_verify_resource', array($this, 'process_verification'));
    }

    /**
     * Add dashboard widget showing verification status
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'resources_verification_widget',
            'Resource Verification Status',
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Render the dashboard widget
     */
    public function render_dashboard_widget() {
        $stats = Resources_Manager::get_verification_stats();

        ?>
        <style>
            .verification-stats {
                margin: 15px 0;
            }
            .verification-stat-row {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f0;
            }
            .verification-stat-row:last-child {
                border-bottom: none;
                font-weight: bold;
                margin-top: 8px;
                padding-top: 15px;
                border-top: 2px solid #ddd;
            }
            .verification-stat-label {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .verification-indicator {
                display: inline-block;
                width: 12px;
                height: 12px;
                border-radius: 50%;
            }
            .indicator-stale {
                background-color: #dc3232;
            }
            .indicator-aging {
                background-color: #ffb900;
            }
            .indicator-fresh {
                background-color: #46b450;
            }
            .indicator-unverified {
                background-color: #999;
            }
            .verification-stat-count {
                font-weight: 600;
                font-size: 1.1em;
            }
            .widget-action-button {
                margin-top: 15px;
            }
        </style>

        <div class="verification-stats">
            <div class="verification-stat-row">
                <span class="verification-stat-label">
                    <span class="verification-indicator indicator-stale"></span>
                    Stale (>18 months)
                </span>
                <span class="verification-stat-count"><?php echo esc_html($stats['stale']); ?></span>
            </div>

            <div class="verification-stat-row">
                <span class="verification-stat-label">
                    <span class="verification-indicator indicator-aging"></span>
                    Aging (12-18 months)
                </span>
                <span class="verification-stat-count"><?php echo esc_html($stats['aging']); ?></span>
            </div>

            <div class="verification-stat-row">
                <span class="verification-stat-label">
                    <span class="verification-indicator indicator-fresh"></span>
                    Fresh (<12 months)
                </span>
                <span class="verification-stat-count"><?php echo esc_html($stats['fresh']); ?></span>
            </div>

            <?php if ($stats['unverified'] > 0): ?>
            <div class="verification-stat-row">
                <span class="verification-stat-label">
                    <span class="verification-indicator indicator-unverified"></span>
                    Never Verified
                </span>
                <span class="verification-stat-count"><?php echo esc_html($stats['unverified']); ?></span>
            </div>
            <?php endif; ?>

            <div class="verification-stat-row">
                <span class="verification-stat-label">
                    Total Resources
                </span>
                <span class="verification-stat-count"><?php echo esc_html($stats['total']); ?></span>
            </div>
        </div>

        <div class="widget-action-button">
            <a href="<?php echo admin_url('admin.php?page=monday-resources-manage&filter=needs_verification'); ?>" class="button button-primary">
                View Resources Needing Verification
            </a>
        </div>
        <?php
    }

    /**
     * Render verification checklist UI (called from edit page)
     */
    public static function render_verification_checklist_ui($resource_id) {
        $resource = Resources_Manager::get_resource($resource_id);
        if (!$resource) {
            return;
        }

        $history = Resources_Manager::get_verification_history($resource_id, 5);

        // Get verified by user name
        $verified_by_name = 'Unknown';
        if ($resource['last_verified_by']) {
            $user = get_userdata($resource['last_verified_by']);
            if ($user) {
                $verified_by_name = $user->display_name;
            }
        }

        // Calculate relative time
        $relative_time = 'Never';
        if ($resource['last_verified_date']) {
            $relative_time = human_time_diff(strtotime($resource['last_verified_date']), current_time('timestamp')) . ' ago';
        }

        // Status badge class
        $status_class = array(
            'fresh' => 'status-fresh',
            'aging' => 'status-aging',
            'stale' => 'status-stale',
            'unverified' => 'status-unverified'
        );
        $badge_class = isset($status_class[$resource['verification_status']]) ? $status_class[$resource['verification_status']] : 'status-unverified';

        ?>
        <style>
            .verification-panel {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                margin-top: 20px;
            }
            .verification-status {
                background: #f0f0f1;
                padding: 12px;
                margin-bottom: 20px;
                border-radius: 4px;
            }
            .status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 3px;
                font-weight: 600;
                font-size: 13px;
                text-transform: uppercase;
            }
            .status-fresh {
                background-color: #d4edda;
                color: #155724;
            }
            .status-aging {
                background-color: #fff3cd;
                color: #856404;
            }
            .status-stale {
                background-color: #f8d7da;
                color: #721c24;
            }
            .status-unverified {
                background-color: #e2e3e5;
                color: #383d41;
            }
            .verification-checklist label {
                display: block;
                margin: 10px 0;
                font-size: 14px;
            }
            .verification-checklist input[type="checkbox"] {
                margin-right: 8px;
            }
            .verification-notes {
                width: 100%;
                margin-top: 10px;
            }
            .button-group {
                margin-top: 15px;
                display: flex;
                gap: 10px;
            }
            .verification-history {
                margin-top: 25px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
            }
            .verification-history ul {
                margin: 10px 0;
                padding-left: 20px;
            }
            .verification-history li {
                margin: 8px 0;
                font-size: 13px;
                line-height: 1.6;
            }
            .verification-type-attempt {
                color: #856404;
                font-style: italic;
            }
        </style>

        <div class="verification-panel">
            <h3>Verify Resource Information</h3>

            <div class="verification-status">
                <strong>Current Status:</strong>
                <span class="status-badge <?php echo esc_attr($badge_class); ?>">
                    <?php echo esc_html(ucfirst($resource['verification_status'])); ?>
                </span>
                <br>
                <strong>Last Verified:</strong> <?php echo esc_html($relative_time); ?>
                <?php if ($resource['last_verified_date']): ?>
                    by <?php echo esc_html($verified_by_name); ?>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="verify_resource">
                <input type="hidden" name="resource_id" value="<?php echo esc_attr($resource_id); ?>">
                <?php wp_nonce_field('verify_resource_' . $resource_id); ?>

                <h4>Verification Checklist</h4>
                <div class="verification-checklist">
                    <label>
                        <input type="checkbox" name="checklist[]" value="phone">
                        Phone number(s) confirmed
                    </label>
                    <label>
                        <input type="checkbox" name="checklist[]" value="email">
                        Email address confirmed
                    </label>
                    <label>
                        <input type="checkbox" name="checklist[]" value="website">
                        Website URL working
                    </label>
                    <label>
                        <input type="checkbox" name="checklist[]" value="address">
                        Physical address confirmed
                    </label>
                    <label>
                        <input type="checkbox" name="checklist[]" value="hours">
                        Service hours confirmed
                    </label>
                    <label>
                        <input type="checkbox" name="checklist[]" value="eligibility">
                        Eligibility requirements confirmed
                    </label>
                    <label>
                        <input type="checkbox" name="checklist[]" value="program_active">
                        Program still active
                    </label>
                    <label>
                        <input type="checkbox" name="checklist[]" value="contact">
                        Contact person still available
                    </label>
                </div>

                <h4>Verification Notes</h4>
                <textarea name="verification_notes" class="verification-notes" rows="4" placeholder="Add notes about what you verified or any changes made..."></textarea>

                <div class="button-group">
                    <button type="submit" name="verification_type" value="successful" class="button button-primary">
                        Mark as Verified
                    </button>
                    <button type="submit" name="verification_type" value="attempted" class="button button-secondary">
                        Log Verification Attempt (Couldn't Reach)
                    </button>
                </div>
            </form>

            <?php if (!empty($history)): ?>
            <div class="verification-history">
                <h4>Verification History</h4>
                <ul>
                    <?php foreach ($history as $entry): ?>
                        <li>
                            <strong><?php echo esc_html(date('M j, Y g:i a', strtotime($entry['verified_date']))); ?></strong>
                            - <?php echo esc_html($entry['verification_type']); ?> by <?php echo esc_html($entry['verified_by_name']); ?>
                            <?php if (!empty($entry['notes'])): ?>
                                <span class="<?php echo $entry['verification_type'] === 'attempt' ? 'verification-type-attempt' : ''; ?>">
                                    - "<?php echo esc_html($entry['notes']); ?>"
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Process verification submission
     */
    public function process_verification() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $resource_id = isset($_POST['resource_id']) ? intval($_POST['resource_id']) : 0;

        if (!$resource_id) {
            wp_die('Invalid resource ID');
        }

        check_admin_referer('verify_resource_' . $resource_id);

        $checklist = isset($_POST['checklist']) ? $_POST['checklist'] : array();
        $notes = isset($_POST['verification_notes']) ? sanitize_textarea_field($_POST['verification_notes']) : '';
        $type = isset($_POST['verification_type']) ? sanitize_text_field($_POST['verification_type']) : 'successful';

        if ($type === 'successful') {
            // Full verification
            $success = Resources_Manager::verify_resource(
                $resource_id,
                get_current_user_id(),
                $checklist,
                $notes
            );

            $message = $success ? 'Resource marked as verified!' : 'Error verifying resource.';
        } else {
            // Attempted verification (couldn't reach)
            $success = Resources_Manager::record_verification_attempt(
                $resource_id,
                get_current_user_id(),
                $notes
            );

            $message = $success ? 'Verification attempt logged.' : 'Error logging verification attempt.';
        }

        // Redirect back to edit page
        $redirect_url = add_query_arg(
            array(
                'page' => 'monday-resources-edit',
                'id' => $resource_id,
                'verified' => $success ? '1' : '0',
                'message' => urlencode($message)
            ),
            admin_url('admin.php')
        );

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Get verification stats for dashboard
     */
    public static function get_verification_stats() {
        return Resources_Manager::get_verification_stats();
    }
}
