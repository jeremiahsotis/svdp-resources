<?php
/**
 * Verification Cron Class
 * Handles scheduled tasks for verification reminders and status updates
 */

class Verification_Cron {

    public function __construct() {
        // Schedule cron jobs
        add_action('wp', array($this, 'schedule_cron_jobs'));

        // Hook cron actions
        add_action('resources_daily_verification_update', array($this, 'daily_verification_update'));
        add_action('resources_weekly_verification_reminder', array($this, 'weekly_verification_reminder'));
    }

    /**
     * Schedule cron jobs if not already scheduled
     */
    public function schedule_cron_jobs() {
        // Daily verification status update
        if (!wp_next_scheduled('resources_daily_verification_update')) {
            wp_schedule_event(time(), 'daily', 'resources_daily_verification_update');
        }

        // Weekly verification reminder email
        if (!wp_next_scheduled('resources_weekly_verification_reminder')) {
            // Schedule for Monday at 9 AM
            $next_monday = strtotime('next Monday 9:00');
            wp_schedule_event($next_monday, 'weekly', 'resources_weekly_verification_reminder');
        }
    }

    /**
     * Daily cron job: Update all verification statuses
     */
    public function daily_verification_update() {
        $updated_count = Resources_Manager::update_all_verification_statuses();
        error_log("Resources: Daily verification status update completed. Updated $updated_count resources.");
    }

    /**
     * Weekly cron job: Send verification reminder emails
     */
    public function weekly_verification_reminder() {
        // Get resources needing verification
        $stale_resources = Resources_Manager::get_all_resources(array('verification_status' => 'stale'));
        $aging_resources = Resources_Manager::get_all_resources(array('verification_status' => 'aging'));
        $unverified_resources = Resources_Manager::get_all_resources(array('verification_status' => 'unverified'));

        // Only send if there are resources needing attention
        if (empty($stale_resources) && empty($aging_resources) && empty($unverified_resources)) {
            return;
        }

        // Get admin emails
        $admin_emails = $this->get_admin_emails();

        if (empty($admin_emails)) {
            return;
        }

        // Build email content
        $subject = '[SVdP Resources] Weekly Verification Report';
        $message = $this->build_verification_email($stale_resources, $aging_resources, $unverified_resources);

        // Send email to all admins
        foreach ($admin_emails as $email) {
            wp_mail($email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
        }

        error_log("Resources: Weekly verification reminder sent to " . count($admin_emails) . " admins.");
    }

    /**
     * Get admin user emails
     */
    private function get_admin_emails() {
        $admins = get_users(array(
            'role' => 'administrator',
            'fields' => array('user_email')
        ));

        $emails = array();
        foreach ($admins as $admin) {
            if (!empty($admin->user_email)) {
                $emails[] = $admin->user_email;
            }
        }

        return $emails;
    }

    /**
     * Build verification reminder email HTML
     */
    private function build_verification_email($stale, $aging, $unverified) {
        $admin_url = admin_url('admin.php?page=monday-resources-manage');

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body {
                    font-family: Arial, sans-serif;
                    font-size: 14px;
                    line-height: 1.6;
                    color: #333;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                }
                h2 {
                    color: #0073aa;
                    border-bottom: 2px solid #0073aa;
                    padding-bottom: 10px;
                }
                h3 {
                    color: #555;
                    margin-top: 25px;
                }
                .section {
                    margin: 20px 0;
                }
                .resource-list {
                    list-style: none;
                    padding: 0;
                }
                .resource-list li {
                    padding: 8px 12px;
                    margin: 5px 0;
                    background: #f5f5f5;
                    border-left: 4px solid #ccc;
                }
                .stale {
                    border-left-color: #dc3232;
                    background: #ffe5e5;
                }
                .aging {
                    border-left-color: #ffb900;
                    background: #fff8e5;
                }
                .unverified {
                    border-left-color: #999;
                    background: #f0f0f0;
                }
                .resource-name {
                    font-weight: bold;
                }
                .verified-date {
                    color: #666;
                    font-size: 13px;
                }
                .button {
                    display: inline-block;
                    padding: 12px 24px;
                    background-color: #0073aa;
                    color: #fff;
                    text-decoration: none;
                    border-radius: 4px;
                    margin: 20px 0;
                }
                .footer {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    font-size: 12px;
                    color: #666;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h2>Weekly Resource Verification Report</h2>

                <p>Hello,</p>

                <p>This is your weekly reminder about resources that need verification. Keeping resource information up-to-date ensures the community receives accurate and reliable information.</p>

                <?php if (!empty($stale)): ?>
                <div class="section">
                    <h3>🔴 STALE RESOURCES (>18 months - URGENT)</h3>
                    <p><?php echo count($stale); ?> resource(s) have not been verified in over 18 months:</p>
                    <ul class="resource-list">
                        <?php foreach (array_slice($stale, 0, 10) as $resource): ?>
                            <li class="stale">
                                <div class="resource-name"><?php echo esc_html($resource['resource_name']); ?></div>
                                <div class="verified-date">
                                    Last verified:
                                    <?php
                                    if ($resource['last_verified_date']) {
                                        echo human_time_diff(strtotime($resource['last_verified_date']), current_time('timestamp')) . ' ago';
                                    } else {
                                        echo 'Never';
                                    }
                                    ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($stale) > 10): ?>
                            <li style="background: #fff; border: none; padding: 8px 0;">
                                <em>...and <?php echo count($stale) - 10; ?> more</em>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($aging)): ?>
                <div class="section">
                    <h3>🟡 AGING RESOURCES (12-18 months)</h3>
                    <p><?php echo count($aging); ?> resource(s) are approaching staleness:</p>
                    <ul class="resource-list">
                        <?php foreach (array_slice($aging, 0, 10) as $resource): ?>
                            <li class="aging">
                                <div class="resource-name"><?php echo esc_html($resource['resource_name']); ?></div>
                                <div class="verified-date">
                                    Last verified:
                                    <?php
                                    if ($resource['last_verified_date']) {
                                        echo human_time_diff(strtotime($resource['last_verified_date']), current_time('timestamp')) . ' ago';
                                    } else {
                                        echo 'Never';
                                    }
                                    ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($aging) > 10): ?>
                            <li style="background: #fff; border: none; padding: 8px 0;">
                                <em>...and <?php echo count($aging) - 10; ?> more</em>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($unverified)): ?>
                <div class="section">
                    <h3>⚠️ NEVER VERIFIED</h3>
                    <p><?php echo count($unverified); ?> resource(s) have never been verified:</p>
                    <ul class="resource-list">
                        <?php foreach (array_slice($unverified, 0, 5) as $resource): ?>
                            <li class="unverified">
                                <div class="resource-name"><?php echo esc_html($resource['resource_name']); ?></div>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($unverified) > 5): ?>
                            <li style="background: #fff; border: none; padding: 8px 0;">
                                <em>...and <?php echo count($unverified) - 5; ?> more</em>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo esc_url($admin_url); ?>" class="button">
                        View All Resources Needing Verification
                    </a>
                </div>

                <div class="footer">
                    <p>This is an automated reminder from your Community Resources database.</p>
                    <p>To stop receiving these emails, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Clear scheduled cron jobs (called on deactivation)
     */
    public static function clear_scheduled_jobs() {
        $timestamp = wp_next_scheduled('resources_daily_verification_update');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'resources_daily_verification_update');
        }

        $timestamp = wp_next_scheduled('resources_weekly_verification_reminder');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'resources_weekly_verification_reminder');
        }
    }
}
