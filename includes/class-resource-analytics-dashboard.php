<?php
/**
 * Analytics dashboard admin page and geography management actions.
 */

class Resource_Analytics_Dashboard {

    const PAGE_SLUG = 'monday-resources-analytics';

    /**
     * Wire menu/actions.
     */
    public function __construct() {
        // Register after the core Resources menu so the submenu attaches correctly.
        add_action('admin_menu', array($this, 'register_menu'), 99);

        add_action('admin_post_svdp_analytics_add_geography', array($this, 'handle_add_geography'));
        add_action('admin_post_svdp_analytics_toggle_geography', array($this, 'handle_toggle_geography'));
        add_action('admin_post_svdp_analytics_remove_geography', array($this, 'handle_remove_geography'));
        add_action('admin_post_svdp_analytics_update_geography_order', array($this, 'handle_update_geography_order'));
        add_action('admin_post_svdp_analytics_run_discovery', array($this, 'handle_run_discovery'));
        add_action('admin_post_svdp_analytics_rebuild_rollups', array($this, 'handle_rebuild_rollups'));
        add_action('admin_post_svdp_analytics_save_discovery_schedule', array($this, 'handle_save_discovery_schedule'));
    }

    /**
     * Add analytics submenu.
     *
     * @return void
     */
    public function register_menu() {
        add_submenu_page(
            'monday-resources-manage',
            'Resources Analytics',
            'Analytics',
            monday_resources_get_analytics_view_capability(),
            self::PAGE_SLUG,
            array($this, 'render_page')
        );
    }

    /**
     * Render analytics page.
     *
     * @return void
     */
    public function render_page() {
        if (!$this->current_user_can_view()) {
            wp_die('Unauthorized', 403);
        }

        $filters = Resource_Analytics::sanitize_filters($_GET);
        $data = Resource_Analytics::get_dashboard_data($filters);
        $geography_options = Resource_Geography_Registry::get_active_geography_options();
        $registry_rows = Resource_Geography_Registry::get_registry_rows();

        $exports_enabled = monday_resources_is_analytics_exports_enabled();
        $dashboard_enabled = monday_resources_is_analytics_dashboard_enabled();

        ?>
        <div class="wrap">
            <h1>Resources Analytics</h1>

            <?php if (!$dashboard_enabled): ?>
                <div class="notice notice-warning"><p>Analytics dashboard is currently disabled by feature flag.</p></div>
            <?php endif; ?>

            <?php $this->render_notice(); ?>

            <form method="get" style="margin: 12px 0 18px; padding: 12px; background: #fff; border: 1px solid #ccd0d4; border-radius: 6px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="svdp-analytics-preset">Date Range</label></th>
                            <td>
                                <select id="svdp-analytics-preset" name="preset">
                                    <option value="7" <?php selected($filters['preset'], '7'); ?>>Last 7 days</option>
                                    <option value="30" <?php selected($filters['preset'], '30'); ?>>Last 30 days</option>
                                    <option value="90" <?php selected($filters['preset'], '90'); ?>>Last 90 days</option>
                                    <option value="custom" <?php selected($filters['preset'], 'custom'); ?>>Custom</option>
                                </select>
                                <input type="date" name="start_date" value="<?php echo esc_attr($filters['start_date']); ?>">
                                <input type="date" name="end_date" value="<?php echo esc_attr($filters['end_date']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="svdp-analytics-segment">Segment</label></th>
                            <td>
                                <select id="svdp-analytics-segment" name="segment">
                                    <option value="all" <?php selected($filters['segment'], 'all'); ?>>All segments</option>
                                    <option value="staff" <?php selected($filters['segment'], 'staff'); ?>>Staff</option>
                                    <option value="vincentian_volunteer" <?php selected($filters['segment'], 'vincentian_volunteer'); ?>>Vincentian Volunteer</option>
                                    <option value="partner" <?php selected($filters['segment'], 'partner'); ?>>Partner</option>
                                    <option value="unknown" <?php selected($filters['segment'], 'unknown'); ?>>Unknown</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="svdp-analytics-geography">Geography</label></th>
                            <td>
                                <select id="svdp-analytics-geography" name="geography">
                                    <option value="all" <?php selected($filters['geography'], 'all'); ?>>All geographies</option>
                                    <?php foreach ($geography_options as $slug => $label): ?>
                                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['geography'], $slug); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="svdp-analytics-channel">Channel</label></th>
                            <td>
                                <select id="svdp-analytics-channel" name="channel">
                                    <option value="all" <?php selected($filters['channel'], 'all'); ?>>All channels</option>
                                    <option value="print" <?php selected($filters['channel'], 'print'); ?>>Print</option>
                                    <option value="email" <?php selected($filters['channel'], 'email'); ?>>Email</option>
                                    <option value="text" <?php selected($filters['channel'], 'text'); ?>>Text</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p>
                    <button type="submit" class="button button-primary">Apply Filters</button>
                </p>
            </form>

            <?php if ($exports_enabled && $dashboard_enabled): ?>
                <p>
                    <?php echo $this->build_export_link('csv', 'Export CSV', $filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo $this->build_export_link('xlsx', 'Export XLSX', $filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo $this->build_export_link('pdf', 'Export PDF', $filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </p>
            <?php endif; ?>

            <?php if ($dashboard_enabled): ?>
                <?php $this->render_kpis($data['kpis']); ?>
                <?php $this->render_trend_table($data['trend']); ?>
                <?php $this->render_simple_table('Top Queries', $data['top_queries'], array('query_text' => 'Query', 'total' => 'Count')); ?>
                <?php $this->render_simple_table('Top Filters / Needs', $data['top_filters'], array('dimension' => 'Dimension', 'label' => 'Label', 'total' => 'Count')); ?>
                <?php $this->render_simple_table('Channel Mix', $data['channel_mix'], array('channel' => 'Channel', 'total' => 'Sends')); ?>
                <?php $this->render_simple_table('Top Shared Resources', $data['top_shared_resources'], array('resource_id' => 'Resource ID', 'resource_name' => 'Resource', 'sent_count' => 'Sends')); ?>
                <?php $this->render_simple_table('Geography Summary', $data['geography_summary'], array('geography_label' => 'Geography', 'searches' => 'Searches', 'zero_results' => 'Zero Results', 'snapshot_sent' => 'Snapshot Sent', 'trend_delta_pct' => 'Delta %')); ?>
            <?php endif; ?>

            <?php if (current_user_can('manage_options')): ?>
                <hr>
                <h2>Geography Registry</h2>

                <?php $auto_discovery_enabled = (int) get_option('monday_resources_analytics_auto_discovery_enabled', 0) === 1; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 12px; padding: 10px; background: #fff; border: 1px solid #ccd0d4; border-radius: 6px;">
                    <?php wp_nonce_field('svdp_analytics_save_discovery_schedule'); ?>
                    <input type="hidden" name="action" value="svdp_analytics_save_discovery_schedule">
                    <label>
                        <input type="checkbox" name="auto_discovery_enabled" value="1" <?php checked($auto_discovery_enabled); ?>>
                        Enable daily shortcode geography auto-discovery
                    </label>
                    <button type="submit" class="button">Save Discovery Setting</button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 12px; padding: 10px; background: #fff; border: 1px solid #ccd0d4; border-radius: 6px;">
                    <?php wp_nonce_field('svdp_analytics_add_geography'); ?>
                    <input type="hidden" name="action" value="svdp_analytics_add_geography">
                    <label for="svdp-new-geography"><strong>Add geography</strong></label>
                    <input id="svdp-new-geography" type="text" name="label" required placeholder="e.g. St Anne" style="min-width: 260px;">
                    <button type="submit" class="button">Add</button>
                </form>

                <p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right: 8px;">
                        <?php wp_nonce_field('svdp_analytics_run_discovery'); ?>
                        <input type="hidden" name="action" value="svdp_analytics_run_discovery">
                        <button type="submit" class="button">Run Discovery Now</button>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                        <?php wp_nonce_field('svdp_analytics_rebuild_rollups'); ?>
                        <input type="hidden" name="action" value="svdp_analytics_rebuild_rollups">
                        <input type="date" name="start_date" value="<?php echo esc_attr($filters['start_date']); ?>">
                        <input type="date" name="end_date" value="<?php echo esc_attr($filters['end_date']); ?>">
                        <button type="submit" class="button">Rebuild Rollups</button>
                    </form>
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Slug</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Order</th>
                            <th>Last Seen Paths</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registry_rows)): ?>
                            <tr><td colspan="7">No geographies found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($registry_rows as $row): ?>
                                <?php
                                $slug = isset($row['slug']) ? sanitize_key((string) $row['slug']) : '';
                                $is_active = isset($row['is_active']) && (int) $row['is_active'] === 1;
                                $paths = Resource_Geography_Registry::get_source_paths_for_slug($slug, 3);
                                ?>
                                <tr>
                                    <td><?php echo esc_html(isset($row['label']) ? (string) $row['label'] : ''); ?></td>
                                    <td><code><?php echo esc_html($slug); ?></code></td>
                                    <td><?php echo $is_active ? 'Active' : 'Disabled'; ?></td>
                                    <td><?php echo esc_html(isset($row['source_type']) ? (string) $row['source_type'] : ''); ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:6px; align-items:center;">
                                            <?php wp_nonce_field('svdp_analytics_update_geography_order_' . $slug); ?>
                                            <input type="hidden" name="action" value="svdp_analytics_update_geography_order">
                                            <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                            <input type="number" name="display_order" value="<?php echo (int) ($row['display_order'] ?? 0); ?>" style="width:78px;">
                                            <button type="submit" class="button button-small">Save</button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if (empty($paths)): ?>
                                            <em>None</em>
                                        <?php else: ?>
                                            <?php echo esc_html(implode(' | ', $paths)); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:6px;">
                                            <?php wp_nonce_field('svdp_analytics_toggle_geography_' . $slug); ?>
                                            <input type="hidden" name="action" value="svdp_analytics_toggle_geography">
                                            <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                            <input type="hidden" name="is_active" value="<?php echo $is_active ? '0' : '1'; ?>">
                                            <button type="submit" class="button button-small"><?php echo $is_active ? 'Disable' : 'Enable'; ?></button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                            <?php wp_nonce_field('svdp_analytics_remove_geography_' . $slug); ?>
                                            <input type="hidden" name="action" value="svdp_analytics_remove_geography">
                                            <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                            <button type="submit" class="button button-small" onclick="return confirm('Remove this geography from active lists?');">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Add geography handler.
     *
     * @return void
     */
    public function handle_add_geography() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('svdp_analytics_add_geography');

        $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
        if ($label !== '') {
            Resource_Geography_Registry::upsert_geography($label, array('source_type' => 'manual', 'is_active' => 1));
            $this->redirect_with_notice('geography_added');
            return;
        }

        $this->redirect_with_notice('geography_missing');
    }

    /**
     * Enable/disable geography handler.
     *
     * @return void
     */
    public function handle_toggle_geography() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : '';
        check_admin_referer('svdp_analytics_toggle_geography_' . $slug);

        $is_active = isset($_POST['is_active']) && (int) $_POST['is_active'] === 1;
        if ($slug !== '') {
            Resource_Geography_Registry::set_active($slug, $is_active);
            $this->redirect_with_notice('geography_toggled');
            return;
        }

        $this->redirect_with_notice('geography_missing');
    }

    /**
     * Soft-remove geography from active lists.
     *
     * @return void
     */
    public function handle_remove_geography() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : '';
        check_admin_referer('svdp_analytics_remove_geography_' . $slug);

        if ($slug !== '') {
            Resource_Geography_Registry::soft_remove($slug);
            $this->redirect_with_notice('geography_removed');
            return;
        }

        $this->redirect_with_notice('geography_missing');
    }

    /**
     * Update explicit geography display order.
     *
     * @return void
     */
    public function handle_update_geography_order() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : '';
        check_admin_referer('svdp_analytics_update_geography_order_' . $slug);

        $display_order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;
        if ($slug !== '') {
            Resource_Geography_Registry::set_display_order($slug, $display_order);
            $this->redirect_with_notice('geography_reordered');
            return;
        }

        $this->redirect_with_notice('geography_missing');
    }

    /**
     * Manual discovery handler.
     *
     * @return void
     */
    public function handle_run_discovery() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('svdp_analytics_run_discovery');
        $result = Resource_Geography_Registry::run_discovery();

        $message = sprintf(
            'discovery_done:%d:%d',
            isset($result['posts_scanned']) ? (int) $result['posts_scanned'] : 0,
            isset($result['mappings_upserted']) ? (int) $result['mappings_upserted'] : 0
        );
        $this->redirect_with_notice($message);
    }

    /**
     * Rollup rebuild handler.
     *
     * @return void
     */
    public function handle_rebuild_rollups() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('svdp_analytics_rebuild_rollups');

        $start = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : current_time('Y-m-d');
        $end = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : $start;

        Resource_Analytics::rebuild_rollups_for_range($start, $end);
        $this->redirect_with_notice('rollups_rebuilt');
    }

    /**
     * Save auto-discovery schedule setting.
     *
     * @return void
     */
    public function handle_save_discovery_schedule() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('svdp_analytics_save_discovery_schedule');

        $enabled = isset($_POST['auto_discovery_enabled']) && (int) $_POST['auto_discovery_enabled'] === 1 ? 1 : 0;
        update_option('monday_resources_analytics_auto_discovery_enabled', $enabled, false);
        Resource_Geography_Registry::sync_discovery_schedule();

        $this->redirect_with_notice('discovery_schedule_saved');
    }

    /**
     * Render status notice from query arg.
     *
     * @return void
     */
    private function render_notice() {
        $code = isset($_GET['svdp_analytics_notice']) ? sanitize_text_field((string) $_GET['svdp_analytics_notice']) : '';
        if ($code === '') {
            return;
        }

        $class = 'notice notice-info';
        $text = '';

        if ($code === 'geography_added') {
            $class = 'notice notice-success';
            $text = 'Geography added.';
        } elseif ($code === 'geography_toggled') {
            $class = 'notice notice-success';
            $text = 'Geography status updated.';
        } elseif ($code === 'geography_removed') {
            $class = 'notice notice-success';
            $text = 'Geography removed from active lists.';
        } elseif ($code === 'geography_reordered') {
            $class = 'notice notice-success';
            $text = 'Geography display order updated.';
        } elseif ($code === 'discovery_schedule_saved') {
            $class = 'notice notice-success';
            $text = 'Discovery schedule setting saved.';
        } elseif ($code === 'rollups_rebuilt') {
            $class = 'notice notice-success';
            $text = 'Rollups rebuilt for selected range.';
        } elseif ($code === 'geography_missing') {
            $class = 'notice notice-error';
            $text = 'Missing geography value.';
        } elseif (strpos($code, 'discovery_done:') === 0) {
            $parts = explode(':', $code);
            $posts = isset($parts[1]) ? (int) $parts[1] : 0;
            $maps = isset($parts[2]) ? (int) $parts[2] : 0;
            $class = 'notice notice-success';
            $text = sprintf('Discovery complete. Scanned %d posts and upserted %d mappings.', $posts, $maps);
        }

        if ($text === '') {
            return;
        }

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($text) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Build export action link.
     *
     * @param string $format
     * @param string $label
     * @param array $filters
     * @return string
     */
    private function build_export_link($format, $label, $filters) {
        $args = array_merge(
            array(
                'action' => 'svdp_export_analytics',
                'format' => $format
            ),
            $filters
        );

        $url = add_query_arg($args, admin_url('admin-ajax.php'));
        $url = wp_nonce_url($url, 'svdp_export_analytics');

        return '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    /**
     * KPI cards.
     *
     * @param array $kpis
     * @return void
     */
    private function render_kpis($kpis) {
        $kpis = is_array($kpis) ? $kpis : array();

        ?>
        <h2>KPI Summary</h2>
        <div style="display:grid; grid-template-columns: repeat(4, minmax(160px, 1fr)); gap: 10px; margin-bottom: 16px;">
            <?php $this->render_kpi_card('Searches', (int) ($kpis['searches'] ?? 0)); ?>
            <?php $this->render_kpi_card('Zero Result Rate', (float) ($kpis['zero_result_rate'] ?? 0) . '%'); ?>
            <?php $this->render_kpi_card('Snapshot Sends', (int) ($kpis['snapshot_sends'] ?? 0)); ?>
            <?php $this->render_kpi_card('Send Success Rate', (float) ($kpis['send_success_rate'] ?? 0) . '%'); ?>
        </div>
        <?php
    }

    /**
     * Single KPI card.
     *
     * @param string $label
     * @param string|int|float $value
     * @return void
     */
    private function render_kpi_card($label, $value) {
        ?>
        <div style="background:#fff; border:1px solid #ccd0d4; border-radius:6px; padding:10px;">
            <div style="font-size:12px; color:#6b7280;"><?php echo esc_html($label); ?></div>
            <div style="font-size:24px; font-weight:700; line-height:1.2;"><?php echo esc_html((string) $value); ?></div>
        </div>
        <?php
    }

    /**
     * Trend table renderer.
     *
     * @param array $rows
     * @return void
     */
    private function render_trend_table($rows) {
        $rows = is_array($rows) ? $rows : array();
        ?>
        <h2>Daily Trends</h2>
        <?php if (empty($rows)): ?>
            <p><em>No trend data for this filter slice.</em></p>
        <?php else: ?>
            <table class="widefat striped" style="margin-bottom:16px;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Searches</th>
                        <th>Zero Results</th>
                        <th>Snapshot Sent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo esc_html((string) ($row['rollup_date'] ?? '')); ?></td>
                            <td><?php echo (int) ($row['searches'] ?? 0); ?></td>
                            <td><?php echo (int) ($row['zero_results'] ?? 0); ?></td>
                            <td><?php echo (int) ($row['snapshot_sent'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Generic table renderer.
     *
     * @param string $heading
     * @param array $rows
     * @param array $columns
     * @return void
     */
    private function render_simple_table($heading, $rows, $columns) {
        $rows = is_array($rows) ? $rows : array();
        $columns = is_array($columns) ? $columns : array();

        ?>
        <h2><?php echo esc_html($heading); ?></h2>
        <?php if (empty($rows)): ?>
            <p><em>No data.</em></p>
        <?php else: ?>
            <table class="widefat striped" style="margin-bottom:16px;">
                <thead>
                    <tr>
                        <?php foreach ($columns as $label): ?>
                            <th><?php echo esc_html($label); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $key => $label): ?>
                                <?php $value = isset($row[$key]) ? $row[$key] : ''; ?>
                                <td><?php echo esc_html((string) $value); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Redirect helper with notice code.
     *
     * @param string $notice
     * @return void
     */
    private function redirect_with_notice($notice) {
        $url = add_query_arg(
            array(
                'page' => self::PAGE_SLUG,
                'svdp_analytics_notice' => (string) $notice
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    /**
     * View capability check.
     *
     * @return bool
     */
    private function current_user_can_view() {
        $cap = monday_resources_get_analytics_view_capability();
        return current_user_can($cap) || current_user_can('manage_options');
    }
}
