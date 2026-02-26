<?php
/**
 * Snapshot sharing, shared-route rendering, and inline-save handlers.
 */

class Resource_Snapshot_Manager {

    const SHARE_ROUTE_BASE = '/resources/shared/';
    const LEGACY_SHARE_ROUTE_BASE = '/district-resources/shared/';
    const SHARE_CAP_TTL_SECONDS = 1800;
    const DEFAULT_EXPIRATION_DAYS = 30;

    /**
     * Wire hooks.
     */
    public function __construct() {
        add_action('init', array(__CLASS__, 'register_rewrite_rules'));
        add_filter('query_vars', array(__CLASS__, 'register_query_vars'));
        add_action('template_redirect', array($this, 'maybe_render_shared_snapshot_route'));

        add_action('wp_ajax_svdp_snapshot_create', array($this, 'ajax_snapshot_create'));
        add_action('wp_ajax_nopriv_svdp_snapshot_create', array($this, 'ajax_snapshot_create'));

        add_action('wp_ajax_svdp_snapshot_send', array($this, 'ajax_snapshot_send'));
        add_action('wp_ajax_nopriv_svdp_snapshot_send', array($this, 'ajax_snapshot_send'));

        add_action('wp_ajax_svdp_resource_inline_save', array($this, 'ajax_resource_inline_save'));
    }

    /**
     * Snapshot table name.
     *
     * @return string
     */
    public static function get_snapshot_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'svdpr_snapshots';
    }

    /**
     * Organization table name.
     *
     * @return string
     */
    public static function get_organizations_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'svdpr_organizations';
    }

    /**
     * Ensure schema required by snapshots and organizations.
     *
     * @return void
     */
    public static function ensure_snapshot_schema() {
        global $wpdb;

        $snapshot_table = self::get_snapshot_table_name();
        $organizations_table = self::get_organizations_table_name();
        $resources_table = $wpdb->prefix . 'resources';
        $charset_collate = $wpdb->get_charset_collate();

        $sql_snapshots = "CREATE TABLE $snapshot_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(80) NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_by_user_id bigint(20) unsigned DEFAULT NULL,
            created_from_url text DEFAULT NULL,
            neighbor_name varchar(191) NOT NULL,
            primary_contact_type varchar(10) NOT NULL,
            primary_contact_value varchar(191) NOT NULL,
            resource_ids_json longtext NOT NULL,
            resource_count int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY idx_status (status),
            KEY idx_expires_at (expires_at)
        ) $charset_collate;";

        $sql_organizations = "CREATE TABLE $organizations_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            name_normalized varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name_normalized (name_normalized),
            KEY idx_name (name)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_snapshots);
        dbDelta($sql_organizations);

        $resources_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $resources_table));
        if ($resources_table_exists !== $resources_table) {
            return;
        }

        $organization_column = $wpdb->get_var("SHOW COLUMNS FROM $resources_table LIKE 'organization_id'");
        if (!$organization_column) {
            $wpdb->query("ALTER TABLE $resources_table ADD COLUMN organization_id bigint(20) unsigned DEFAULT NULL AFTER organization");
        }

        $organization_index = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM $resources_table WHERE Key_name = %s",
                'idx_resources_organization_id'
            )
        );
        if (!$organization_index) {
            $wpdb->query("ALTER TABLE $resources_table ADD INDEX idx_resources_organization_id (organization_id)");
        }
    }

    /**
     * Register snapshot route query vars.
     *
     * @param array $vars
     * @return array
     */
    public static function register_query_vars($vars) {
        $vars[] = 'svdp_snapshot_token';
        $vars[] = 'svdp_snapshot_legacy';
        return $vars;
    }

    /**
     * Register shared snapshot rewrite rules.
     *
     * @return void
     */
    public static function register_rewrite_rules() {
        add_rewrite_rule(
            '^resources/shared/([^/]+)/?$',
            'index.php?svdp_snapshot_token=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^district-resources/shared/([^/]+)/?$',
            'index.php?svdp_snapshot_token=$matches[1]&svdp_snapshot_legacy=1',
            'top'
        );
    }

    /**
     * Build canonical shared snapshot URL.
     *
     * @param string $token
     * @return string
     */
    public static function get_shared_snapshot_url($token) {
        $token = rawurlencode(trim((string) $token));
        return home_url('/resources/shared/' . $token . '/');
    }

    /**
     * Issue a short-lived share capability for district path usage.
     *
     * @param string $request_uri
     * @return string
     */
    public static function issue_share_cap_for_request($request_uri = '') {
        if ($request_uri === '' && isset($_SERVER['REQUEST_URI'])) {
            $request_uri = wp_unslash($_SERVER['REQUEST_URI']);
        }

        $path = (string) wp_parse_url((string) $request_uri, PHP_URL_PATH);
        if (!self::is_district_path($path)) {
            return '';
        }

        $payload = array(
            'exp' => time() + self::SHARE_CAP_TTL_SECONDS,
            'path_prefix' => '/district-resources/'
        );

        $encoded = self::base64url_encode(wp_json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, wp_salt('nonce'));

        return $encoded . '.' . $signature;
    }

    /**
     * Verify share capability and path constraints.
     *
     * @param string $share_cap
     * @param string $source_url
     * @return bool
     */
    public static function verify_share_cap($share_cap, $source_url = '') {
        $share_cap = trim((string) $share_cap);
        if ($share_cap === '' || strpos($share_cap, '.') === false) {
            return false;
        }

        list($encoded, $signature) = explode('.', $share_cap, 2);
        $expected = hash_hmac('sha256', $encoded, wp_salt('nonce'));
        if (!hash_equals($expected, (string) $signature)) {
            return false;
        }

        $decoded_json = self::base64url_decode($encoded);
        if ($decoded_json === '') {
            return false;
        }

        $payload = json_decode($decoded_json, true);
        if (!is_array($payload) || empty($payload['exp']) || empty($payload['path_prefix'])) {
            return false;
        }

        if (time() > (int) $payload['exp']) {
            return false;
        }

        $source_path = self::resolve_source_path($source_url);
        if ($source_path === '') {
            return false;
        }

        $prefix = rtrim((string) $payload['path_prefix'], '/');
        return $source_path === $prefix || strpos($source_path, $prefix . '/') === 0;
    }

    /**
     * Handle /resources/shared/{token}/ route rendering.
     *
     * @return void
     */
    public function maybe_render_shared_snapshot_route() {
        $token = get_query_var('svdp_snapshot_token');
        if ($token === '' || $token === null) {
            return;
        }

        $token = sanitize_text_field((string) $token);
        $legacy = (int) get_query_var('svdp_snapshot_legacy');
        if ($legacy === 1) {
            wp_safe_redirect(self::get_shared_snapshot_url($token), 302);
            exit;
        }

        $snapshot = self::get_snapshot_by_token($token);
        if (!$snapshot) {
            status_header(404);
            nocache_headers();
            echo self::render_route_shell(
                'Resource Link Not Found',
                '<p>This shared link is unavailable.</p>',
                false
            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }

        if (self::is_snapshot_expired($snapshot)) {
            status_header(410);
            nocache_headers();
            $body = '<p>This link has expired.</p><p>Please call <strong>(260) 456-3561</strong> for help.</p>';
            echo self::render_route_shell('This Link Has Expired', $body, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }

        $is_print = isset($_GET['print']) && sanitize_text_field(wp_unslash($_GET['print'])) === '1';
        $shared_url = self::get_shared_snapshot_url($token);
        self::log_event('snapshot_viewed', array(
            'snapshot_id' => isset($snapshot['id']) ? (int) $snapshot['id'] : 0,
            'snapshot_token' => isset($snapshot['token']) ? (string) $snapshot['token'] : '',
            'source_url' => isset($snapshot['created_from_url']) ? (string) $snapshot['created_from_url'] : ''
        ));

        $items = self::load_snapshot_resources($snapshot);
        $grid_html = Monday_Resources_Shortcode::render_resources_grid_html(
            $items,
            0,
            array('shared_snapshot' => true)
        );

        $name = trim((string) $snapshot['neighbor_name']);
        if ($name === '') {
            $name = 'Neighbor';
        }

        $actions_html = '';
        if (!$is_print) {
            $actions_html = '<div class="snapshot-actions">'
                . '<a class="snapshot-action-btn" href="' . esc_url(add_query_arg('print', '1', $shared_url)) . '" target="_blank" rel="noopener">Print</a>'
                . '<button type="button" class="snapshot-action-btn secondary" onclick="window.navigator.clipboard && window.navigator.clipboard.writeText(\'' . esc_js($shared_url) . '\')">Copy Link</button>'
                . '</div>';
        }

        $qr_html = '';
        if ($is_print) {
            $qr_src = 'https://api.qrserver.com/v1/create-qr-code/?size=170x170&data=' . rawurlencode($shared_url);
            $qr_html = '<div class="snapshot-print-qr"><p>Scan to view online</p><img src="' . esc_url($qr_src) . '" alt="QR code for shared resources link"></div>';
        }

        $body = '<div class="snapshot-wrap">'
            . '<h1>Resources for ' . esc_html($name) . '</h1>'
            . '<p class="snapshot-subhead">Shared support resources</p>'
            . $actions_html
            . '<div class="resources-grid">' . $grid_html . '</div>'
            . $qr_html
            . '</div>';

        echo self::render_route_shell('Shared Resources', $body, $is_print); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Create snapshot from fixed resource IDs.
     *
     * @return void
     */
    public function ajax_snapshot_create() {
        check_ajax_referer('monday_resources_nonce', 'nonce');

        $limit_error = self::enforce_rate_limit('snapshot_create');
        if (is_wp_error($limit_error)) {
            wp_send_json_error(
                array(
                    'message' => $limit_error->get_error_message(),
                    'retry_after' => $limit_error->get_error_data('retry_after')
                ),
                429
            );
        }

        $share_cap = isset($_POST['share_cap']) ? sanitize_text_field(wp_unslash($_POST['share_cap'])) : '';
        $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';
        if (!self::can_create_snapshot_request($share_cap, $source_url)) {
            wp_send_json_error(array('message' => 'Not authorized to create shared snapshots.'), 403);
        }

        $resource_ids = isset($_POST['resource_ids']) ? self::sanitize_resource_ids(wp_unslash($_POST['resource_ids'])) : array();
        if (empty($resource_ids)) {
            wp_send_json_error(array('message' => 'Select at least one resource before sharing.'), 400);
        }

        $resources = Resources_Manager::get_resources_by_ids($resource_ids, true);
        $found_ids = array_map(
            'intval',
            wp_list_pluck(is_array($resources) ? $resources : array(), 'id')
        );
        $resource_ids = array_values(array_filter($resource_ids, function($id) use ($found_ids) {
            return in_array((int) $id, $found_ids, true);
        }));
        if (empty($resource_ids)) {
            wp_send_json_error(array('message' => 'No valid resources were selected.'), 400);
        }

        $contact_type = isset($_POST['primary_contact_type']) ? sanitize_text_field(wp_unslash($_POST['primary_contact_type'])) : 'print';
        if (!in_array($contact_type, array('print', 'email', 'text'), true)) {
            $contact_type = 'print';
        }

        $channel_limit_error = self::enforce_rate_limit($contact_type);
        if (is_wp_error($channel_limit_error)) {
            wp_send_json_error(
                array(
                    'message' => $channel_limit_error->get_error_message(),
                    'retry_after' => $channel_limit_error->get_error_data('retry_after')
                ),
                429
            );
        }

        $contact_value = isset($_POST['primary_contact_value']) ? sanitize_text_field(wp_unslash($_POST['primary_contact_value'])) : '';
        if ($contact_type === 'email' && !is_email($contact_value)) {
            wp_send_json_error(array('message' => 'Enter a valid email address.'), 400);
        }

        if ($contact_type === 'text') {
            if (!self::is_twilio_configured()) {
                wp_send_json_error(array('message' => 'Text sharing is unavailable until Twilio settings are configured.'), 400);
            }

            $normalized_phone = self::normalize_phone_to_e164($contact_value);
            if ($normalized_phone === '') {
                wp_send_json_error(array('message' => 'Enter a valid mobile number.'), 400);
            }
            $contact_value = $normalized_phone;
        }

        if ($contact_type === 'print' && $contact_value === '') {
            $contact_value = 'print';
        }

        $neighbor_name = isset($_POST['neighbor_name']) ? sanitize_text_field(wp_unslash($_POST['neighbor_name'])) : '';
        if ($neighbor_name === '') {
            $neighbor_name = 'Neighbor';
        }

        $snapshot = self::create_snapshot(array(
            'resource_ids' => $resource_ids,
            'neighbor_name' => $neighbor_name,
            'primary_contact_type' => $contact_type,
            'primary_contact_value' => $contact_value,
            'created_from_url' => $source_url,
            'created_by_user_id' => get_current_user_id()
        ));

        if (!$snapshot) {
            wp_send_json_error(array('message' => 'Could not create a snapshot. Please try again.'), 500);
        }

        $shared_url = self::get_shared_snapshot_url($snapshot['token']);
        self::log_event('snapshot_created', array(
            'snapshot_id' => isset($snapshot['id']) ? (int) $snapshot['id'] : 0,
            'snapshot_token' => isset($snapshot['token']) ? (string) $snapshot['token'] : '',
            'resource_ids' => $resource_ids,
            'channel' => $contact_type,
            'source_url' => $source_url
        ));

        wp_send_json_success(array(
            'token' => $snapshot['token'],
            'shared_url' => $shared_url,
            'print_url' => add_query_arg('print', '1', $shared_url),
            'expires_at' => $snapshot['expires_at'],
            'resource_count' => (int) $snapshot['resource_count']
        ));
    }

    /**
     * Send snapshot link by email or text.
     *
     * @return void
     */
    public function ajax_snapshot_send() {
        check_ajax_referer('monday_resources_nonce', 'nonce');

        $share_cap = isset($_POST['share_cap']) ? sanitize_text_field(wp_unslash($_POST['share_cap'])) : '';
        $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';
        if (!self::can_create_snapshot_request($share_cap, $source_url)) {
            wp_send_json_error(array('message' => 'Not authorized to send this snapshot.'), 403);
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if ($token === '') {
            wp_send_json_error(array('message' => 'Missing snapshot token.'), 400);
        }

        $snapshot = self::get_snapshot_by_token($token);
        if (!$snapshot || self::is_snapshot_expired($snapshot)) {
            wp_send_json_error(array('message' => 'This snapshot is expired or unavailable.'), 410);
        }

        $channel = isset($_POST['channel']) ? sanitize_text_field(wp_unslash($_POST['channel'])) : '';
        if (!in_array($channel, array('print', 'email', 'text'), true)) {
            $channel = 'print';
        }

        self::log_event('snapshot_send_attempted', array(
            'snapshot_id' => isset($snapshot['id']) ? (int) $snapshot['id'] : 0,
            'snapshot_token' => isset($snapshot['token']) ? (string) $snapshot['token'] : '',
            'channel' => $channel,
            'source_url' => $source_url
        ));

        $limit_error = self::enforce_rate_limit($channel);
        if (is_wp_error($limit_error)) {
            wp_send_json_error(
                array(
                    'message' => $limit_error->get_error_message(),
                    'retry_after' => $limit_error->get_error_data('retry_after')
                ),
                429
            );
        }

        $shared_url = self::get_shared_snapshot_url($snapshot['token']);
        if ($channel === 'print') {
            self::log_event('snapshot_sent', array(
                'snapshot_id' => isset($snapshot['id']) ? (int) $snapshot['id'] : 0,
                'snapshot_token' => isset($snapshot['token']) ? (string) $snapshot['token'] : '',
                'channel' => 'print',
                'source_url' => $source_url
            ));
            wp_send_json_success(array(
                'message' => 'Print view ready.',
                'print_url' => add_query_arg('print', '1', $shared_url),
                'shared_url' => $shared_url
            ));
        }

        $neighbor_name = trim((string) $snapshot['neighbor_name']) !== '' ? trim((string) $snapshot['neighbor_name']) : 'Neighbor';
        $contact_value = isset($_POST['contact_value']) ? sanitize_text_field(wp_unslash($_POST['contact_value'])) : '';
        if ($contact_value === '') {
            $contact_value = (string) $snapshot['primary_contact_value'];
        }

        if ($channel === 'email') {
            $email = sanitize_email($contact_value);
            if (!is_email($email)) {
                wp_send_json_error(array('message' => 'Enter a valid email address.'), 400);
            }

            $sent = self::send_snapshot_email($email, $neighbor_name, $shared_url, $snapshot);
            if (!$sent) {
                self::log_event('snapshot_send_failed', array(
                    'snapshot_id' => isset($snapshot['id']) ? (int) $snapshot['id'] : 0,
                    'snapshot_token' => isset($snapshot['token']) ? (string) $snapshot['token'] : '',
                    'channel' => 'email',
                    'source_url' => $source_url,
                    'error_code' => 'email_send_failed'
                ));
                wp_send_json_error(array('message' => 'Unable to send email right now.'), 500);
            }

            self::log_event('snapshot_sent', array(
                'snapshot_id' => isset($snapshot['id']) ? (int) $snapshot['id'] : 0,
                'snapshot_token' => isset($snapshot['token']) ? (string) $snapshot['token'] : '',
                'channel' => 'email',
                'source_url' => $source_url
            ));
            wp_send_json_success(array(
                'message' => 'Email sent.',
                'shared_url' => $shared_url
            ));
        }

        if ($channel === 'text') {
            if (!self::is_twilio_configured()) {
                wp_send_json_error(array('message' => 'Text sharing is unavailable until Twilio settings are configured.'), 400);
            }

            $to = self::normalize_phone_to_e164($contact_value);
            if ($to === '') {
                wp_send_json_error(array('message' => 'Enter a valid mobile number.'), 400);
            }

            $message = self::build_sms_copy($neighbor_name, $shared_url);
            $sent = self::send_snapshot_text($to, $message);
            if (is_wp_error($sent)) {
                self::log_event('snapshot_send_failed', array(
                    'snapshot_id' => isset($snapshot['id']) ? (int) $snapshot['id'] : 0,
                    'snapshot_token' => isset($snapshot['token']) ? (string) $snapshot['token'] : '',
                    'channel' => 'text',
                    'source_url' => $source_url,
                    'error_code' => sanitize_key((string) $sent->get_error_code())
                ));
                wp_send_json_error(array('message' => $sent->get_error_message()), 500);
            }

            self::log_event('snapshot_sent', array(
                'snapshot_id' => isset($snapshot['id']) ? (int) $snapshot['id'] : 0,
                'snapshot_token' => isset($snapshot['token']) ? (string) $snapshot['token'] : '',
                'channel' => 'text',
                'source_url' => $source_url
            ));
            wp_send_json_success(array(
                'message' => 'Text message sent.',
                'shared_url' => $shared_url
            ));
        }

        wp_send_json_error(array('message' => 'Unsupported channel.'), 400);
    }

    /**
     * Partial inline save endpoint.
     *
     * @return void
     */
    public function ajax_resource_inline_save() {
        check_ajax_referer('monday_resources_nonce', 'nonce');

        $manage_cap = function_exists('monday_resources_get_manage_capability')
            ? monday_resources_get_manage_capability()
            : 'manage_options';
        if (!current_user_can($manage_cap) && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized.'), 403);
        }

        $limit_error = self::enforce_rate_limit('inline_save');
        if (is_wp_error($limit_error)) {
            wp_send_json_error(array('message' => $limit_error->get_error_message()), 429);
        }

        $resource_id = isset($_POST['resource_id']) ? (int) $_POST['resource_id'] : 0;
        if ($resource_id <= 0) {
            wp_send_json_error(array('message' => 'Missing resource ID.'), 400);
        }

        $existing = Resources_Manager::get_resource($resource_id);
        if (!$existing) {
            wp_send_json_error(array('message' => 'Resource not found.'), 404);
        }

        $warnings = array();
        $data = array();

        $field_map = array(
            'resource_name' => 'text',
            'organization' => 'text',
            'is_svdp' => 'bool',
            'service_area' => 'service_area',
            'services_offered' => 'services_offered',
            'provider_type' => 'provider_type',
            'phone' => 'text',
            'phone_extension' => 'text',
            'alternate_phone' => 'text',
            'email' => 'email',
            'website' => 'url',
            'physical_address' => 'textarea',
            'what_they_provide' => 'textarea',
            'how_to_apply' => 'textarea',
            'documents_required' => 'textarea',
            'hours_of_operation' => 'text',
            'income_requirements' => 'text',
            'residency_requirements' => 'textarea',
            'other_eligibility' => 'textarea',
            'eligibility_notes' => 'textarea',
            'wait_time' => 'text',
            'notes_and_tips' => 'textarea'
        );

        foreach ($field_map as $field => $type) {
            if (!isset($_POST[$field])) {
                continue;
            }

            $raw_value = wp_unslash($_POST[$field]);
            switch ($type) {
                case 'bool':
                    $data[$field] = !empty($raw_value) ? 1 : 0;
                    break;
                case 'email':
                    $data[$field] = sanitize_email($raw_value);
                    break;
                case 'url':
                    $data[$field] = esc_url_raw($raw_value);
                    break;
                case 'textarea':
                    $data[$field] = sanitize_textarea_field($raw_value);
                    break;
                case 'service_area':
                    $raw_service_areas = is_array($raw_value) ? $raw_value : Resource_Taxonomy::parse_pipe_slugs((string) $raw_value);
                    if (!is_array($raw_service_areas) || empty($raw_service_areas)) {
                        $raw_service_areas = preg_split('/\s*,\s*|\s*;\s*/', (string) $raw_value);
                    }
                    $slugs = Resource_Taxonomy::normalize_service_area_slugs((array) $raw_service_areas);
                    if (empty($slugs)) {
                        wp_send_json_error(array('message' => 'At least one Service Area must be a canonical option.'), 400);
                    }
                    $data[$field] = Resource_Taxonomy::to_pipe_slug_string($slugs);
                    $service_area_terms = Resource_Taxonomy::get_service_area_terms();
                    $first_slug = $slugs[0];
                    $data['primary_service_type'] = isset($service_area_terms[$first_slug]) ? $service_area_terms[$first_slug] : '';
                    break;
                case 'services_offered':
                    $raw_services = is_array($raw_value) ? $raw_value : Resource_Taxonomy::parse_pipe_slugs((string) $raw_value);
                    if (!is_array($raw_services) || empty($raw_services)) {
                        $raw_services = is_array($raw_value)
                            ? $raw_value
                            : preg_split('/\s*,\s*|\s*;\s*/', (string) $raw_value);
                    }
                    $slugs = Resource_Taxonomy::normalize_services_offered_slugs((array) $raw_services);
                    $data[$field] = Resource_Taxonomy::to_pipe_slug_string($slugs);

                    $service_terms = Resource_Taxonomy::get_services_offered_terms();
                    $labels = array();
                    foreach ($slugs as $slug_item) {
                        if (isset($service_terms[$slug_item])) {
                            $labels[] = $service_terms[$slug_item];
                        }
                    }
                    $data['secondary_service_type'] = implode(', ', $labels);
                    break;
                case 'provider_type':
                    $data[$field] = Resource_Taxonomy::normalize_provider_type_slug($raw_value);
                    break;
                default:
                    $data[$field] = sanitize_text_field($raw_value);
                    break;
            }
        }

        if (isset($_POST['target_population'])) {
            $raw = wp_unslash($_POST['target_population']);
            $values = is_array($raw) ? $raw : array_map('trim', explode(',', (string) $raw));
            $values = array_values(array_filter(array_map('sanitize_text_field', $values)));
            $data['target_population'] = implode(', ', $values);
        }

        if (isset($_POST['geography'])) {
            $raw = wp_unslash($_POST['geography']);
            $values = is_array($raw) ? $raw : array_map('trim', explode(',', (string) $raw));
            $values = array_values(array_filter(array_map('sanitize_text_field', $values)));
            $data['geography'] = implode(', ', $values);
        }

        if (isset($_POST['counties_served'])) {
            $raw = wp_unslash($_POST['counties_served']);
            $values = is_array($raw) ? $raw : array_map('trim', explode(',', (string) $raw));
            $values = array_values(array_filter(array_map('sanitize_text_field', $values)));
            $data['counties_served'] = implode(', ', $values);
        }

        if (array_key_exists('organization', $data)) {
            $organization_name = trim((string) $data['organization']);
            if ($organization_name !== '' && class_exists('Resource_Organization_Manager')) {
                $confirm_close_match = !empty($_POST['confirm_org_close_match']);
                $threshold = (int) apply_filters('svdp_resource_org_match_threshold', 3);
                $close_matches = Resource_Organization_Manager::find_close_matches($organization_name, $threshold, 5);

                if (!$confirm_close_match && !empty($close_matches)) {
                    wp_send_json_error(
                        array(
                            'code' => 'organization_close_match_confirmation_required',
                            'message' => 'Potential duplicate organizations found. Confirm to continue.',
                            'matches' => $close_matches
                        ),
                        409
                    );
                }

                $data['organization_id'] = Resource_Organization_Manager::upsert_organization($organization_name);
                if ((int) $data['organization_id'] <= 0) {
                    $warnings[] = 'Organization entity could not be linked.';
                }
            } elseif ($organization_name === '') {
                $data['organization_id'] = null;
            }
        }

        $update_success = true;
        if (!empty($data)) {
            $update_success = Resources_Manager::update_resource($resource_id, $data);
        }

        if (!$update_success) {
            wp_send_json_error(array('message' => 'Unable to save resource fields.'), 500);
        }

        if (isset($_POST['hours_data'])) {
            $hours_payload = wp_unslash($_POST['hours_data']);
            if (is_string($hours_payload)) {
                $decoded = json_decode($hours_payload, true);
                $hours_payload = is_array($decoded) ? $decoded : null;
            }

            if (is_array($hours_payload)) {
                $hours_saved = Resource_Hours_Manager::save_hours($resource_id, $hours_payload);
                if (!$hours_saved) {
                    $warnings[] = 'Hours were not saved because the hours format was invalid.';
                }
            } else {
                $warnings[] = 'Hours payload was ignored because it was invalid.';
            }
        }

        wp_send_json_success(array(
            'resource_id' => $resource_id,
            'warnings' => $warnings
        ));
    }

    /**
     * Render basic HTML shell for shared route.
     *
     * @param string $title
     * @param string $body_html
     * @param bool $is_print
     * @return string
     */
    private static function render_route_shell($title, $body_html, $is_print) {
        $title = esc_html($title);
        $print_class = $is_print ? 'is-print' : '';

        $style = '
            <style>
                body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5f7fb; color: #1f2933; }
                .snapshot-wrap { max-width: 1080px; margin: 0 auto; padding: 24px 16px 40px; font-size: 16px; }
                .snapshot-wrap h1 { margin: 0 0 8px; font-size: 2rem; color: #0f172a; }
                .snapshot-subhead { margin: 0 0 12px; color: #4b5563; font-size: 1rem; }
                .snapshot-actions { display: flex; gap: 8px; margin: 10px 0 18px; }
                .snapshot-action-btn { min-height: 44px; border-radius: 6px; border: 2px solid #0073aa; background: #0073aa; color: #fff; padding: 10px 14px; font-weight: 600; cursor: pointer; text-decoration: none; }
                .snapshot-action-btn.secondary { background: #fff; color: #005177; }
                .snapshot-print-qr { margin: 16px 0; text-align: center; }
                .snapshot-print-qr p { margin-bottom: 8px; font-weight: 600; }
                .snapshot-print-qr img { width: 170px; height: 170px; }
                .resources-grid { display: grid; grid-template-columns: 1fr; gap: 22px; margin-top: 16px; }
                .resource-card { border: 1px solid #ddd; border-radius: 8px; padding: 24px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
                .resource-card h3 { margin: 0 0 8px; border-bottom: 2px solid #0073aa; padding-bottom: 8px; }
                .resource-organization { margin: 0 0 12px; font-style: italic; border-bottom: 1px solid #eee; padding-bottom: 8px; }
                .resource-field { margin-bottom: 14px; }
                .resource-field-label { display: block; font-weight: 700; margin-bottom: 4px; color: #4b5563; }
                .resource-field-value { line-height: 1.5; }
                .resource-details-hidden { display: none; }
                .resource-toggle-button { background: none; border: none; color: #0073aa; text-decoration: underline; cursor: pointer; min-height: 44px; padding: 0; font-size: 0.95rem; }
                .resource-card.is-unavailable { opacity: 0.65; border-color: #d1d5db; background: #f9fafb; }
                .resource-unavailable-badge { display: inline-block; margin-bottom: 10px; padding: 4px 9px; border-radius: 999px; background: #f3f4f6; color: #374151; font-weight: 700; font-size: 0.78rem; text-transform: uppercase; }
                .partner-divider { grid-column: 1 / -1; margin: 12px 0; padding: 10px; text-align: center; border-top: 2px solid #0073aa; border-bottom: 2px solid #0073aa; color: #005177; font-weight: 600; }
                .verification-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; margin-bottom: 10px; }
                .verification-badge.fresh { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
                .verification-badge.aging { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
                .verification-badge.stale { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
                .verification-badge.unverified { background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db; }
                .resource-section { margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #eee; }
                .resource-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
                .resource-section-heading { font-size: 1.02rem; margin-bottom: 10px; color: #0073aa; text-transform: uppercase; letter-spacing: 0.4px; }
                body.is-print .snapshot-actions { display: none; }
                body.is-print { background: #fff; }
                @media (min-width: 900px) { .resources-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
            </style>';

        $script = '<script>
            function toggleDetails(index) {
                var details = document.getElementById("details-" + index);
                var button = document.getElementById("toggle-" + index);
                if (!details || !button) { return; }
                if (details.style.display === "none" || details.style.display === "") {
                    details.style.display = "block";
                    details.setAttribute("aria-hidden", "false");
                    button.setAttribute("aria-expanded", "true");
                    button.textContent = "Hide Details";
                } else {
                    details.style.display = "none";
                    details.setAttribute("aria-hidden", "true");
                    button.setAttribute("aria-expanded", "false");
                    button.textContent = "Show Full Details";
                }
            }
            if (document.body.classList.contains("is-print")) {
                setTimeout(function() { window.print(); }, 250);
            }
        </script>';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $title . '</title>'
            . $style
            . '</head><body class="' . esc_attr($print_class) . '">'
            . $body_html
            . $script
            . '</body></html>';
    }

    /**
     * Load resources in snapshot order and mark unavailable rows.
     *
     * @param array $snapshot
     * @return array
     */
    private static function load_snapshot_resources($snapshot) {
        $resource_ids = isset($snapshot['resource_ids']) && is_array($snapshot['resource_ids'])
            ? $snapshot['resource_ids']
            : array();

        if (empty($resource_ids)) {
            return array();
        }

        $rows = Resources_Manager::get_resources_by_ids($resource_ids, true);
        $by_id = array();
        foreach ((array) $rows as $row) {
            $row_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($row_id > 0) {
                $by_id[$row_id] = $row;
            }
        }

        $ordered_items = array();
        foreach ($resource_ids as $resource_id) {
            $resource_id = (int) $resource_id;
            if ($resource_id <= 0) {
                continue;
            }

            if (!isset($by_id[$resource_id])) {
                $ordered_items[] = array(
                    'id' => $resource_id,
                    'resource_name' => 'Resource Unavailable',
                    'organization' => '',
                    'status' => 'inactive',
                    '_snapshot_unavailable' => 1,
                    'what_they_provide' => 'This resource is no longer available.'
                );
                continue;
            }

            $row = $by_id[$resource_id];
            $row['_snapshot_unavailable'] = (isset($row['status']) && $row['status'] !== 'active') ? 1 : 0;
            $ordered_items[] = $row;
        }

        return $ordered_items;
    }

    /**
     * Insert snapshot row.
     *
     * @param array $args
     * @return array|false
     */
    private static function create_snapshot($args) {
        global $wpdb;
        $table = self::get_snapshot_table_name();

        $resource_ids = isset($args['resource_ids']) ? self::sanitize_resource_ids($args['resource_ids']) : array();
        if (empty($resource_ids)) {
            return false;
        }

        $token = self::generate_snapshot_token();
        if ($token === '') {
            return false;
        }

        $expires_days = (int) apply_filters('svdp_snapshot_expiration_days', self::DEFAULT_EXPIRATION_DAYS);
        if ($expires_days < 1) {
            $expires_days = self::DEFAULT_EXPIRATION_DAYS;
        }

        $created_at_gmt = gmdate('Y-m-d H:i:s');
        $expires_at_gmt = gmdate('Y-m-d H:i:s', time() + ($expires_days * DAY_IN_SECONDS));

        $inserted = $wpdb->insert(
            $table,
            array(
                'token' => $token,
                'created_at' => $created_at_gmt,
                'expires_at' => $expires_at_gmt,
                'status' => 'active',
                'created_by_user_id' => isset($args['created_by_user_id']) ? (int) $args['created_by_user_id'] : null,
                'created_from_url' => isset($args['created_from_url']) ? esc_url_raw((string) $args['created_from_url']) : '',
                'neighbor_name' => isset($args['neighbor_name']) ? sanitize_text_field((string) $args['neighbor_name']) : 'Neighbor',
                'primary_contact_type' => isset($args['primary_contact_type']) ? sanitize_text_field((string) $args['primary_contact_type']) : 'print',
                'primary_contact_value' => isset($args['primary_contact_value']) ? sanitize_text_field((string) $args['primary_contact_value']) : 'print',
                'resource_ids_json' => wp_json_encode($resource_ids),
                'resource_count' => count($resource_ids)
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d')
        );

        if ($inserted === false) {
            self::log_event('snapshot_insert_failed', array('db_error' => $wpdb->last_error));
            return false;
        }

        return array(
            'id' => (int) $wpdb->insert_id,
            'token' => $token,
            'expires_at' => $expires_at_gmt,
            'resource_count' => count($resource_ids)
        );
    }

    /**
     * Get snapshot by token.
     *
     * @param string $token
     * @return array|null
     */
    private static function get_snapshot_by_token($token) {
        global $wpdb;
        $table = self::get_snapshot_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE token = %s LIMIT 1",
                $token
            ),
            ARRAY_A
        );

        if (!$row || !is_array($row)) {
            return null;
        }

        $ids = json_decode((string) $row['resource_ids_json'], true);
        $row['resource_ids'] = self::sanitize_resource_ids(is_array($ids) ? $ids : array());
        return $row;
    }

    /**
     * Check if snapshot is expired/inactive.
     *
     * @param array $snapshot
     * @return bool
     */
    private static function is_snapshot_expired($snapshot) {
        if (!is_array($snapshot)) {
            return true;
        }

        if (isset($snapshot['status']) && $snapshot['status'] !== 'active') {
            return true;
        }

        $expires_at = isset($snapshot['expires_at']) ? strtotime((string) $snapshot['expires_at'] . ' UTC') : 0;
        if ($expires_at <= 0) {
            return true;
        }

        return time() > $expires_at;
    }

    /**
     * Validate permissions for snapshot creation/send.
     *
     * @param string $share_cap
     * @param string $source_url
     * @return bool
     */
    private static function can_create_snapshot_request($share_cap, $source_url) {
        if (self::current_user_can_snapshot_actions()) {
            return true;
        }

        return self::verify_share_cap($share_cap, $source_url);
    }

    /**
     * User capability check for snapshot actions.
     *
     * @return bool
     */
    private static function current_user_can_snapshot_actions() {
        if (!is_user_logged_in()) {
            return false;
        }

        $snapshot_cap = function_exists('monday_resources_get_snapshot_capability')
            ? monday_resources_get_snapshot_capability()
            : 'edit_view_resources';

        $manage_cap = function_exists('monday_resources_get_manage_capability')
            ? monday_resources_get_manage_capability()
            : 'manage_options';

        return current_user_can($snapshot_cap) || current_user_can($manage_cap) || current_user_can('manage_options');
    }

    /**
     * Email sender.
     *
     * @param string $to_email
     * @param string $neighbor_name
     * @param string $shared_url
     * @param array $snapshot
     * @return bool
     */
    private static function send_snapshot_email($to_email, $neighbor_name, $shared_url, $snapshot) {
        $subject = sprintf('Resources for %s', $neighbor_name);
        $body = self::build_snapshot_email_html($neighbor_name, $shared_url, $snapshot);

        $headers = array('Content-Type: text/html; charset=UTF-8');
        return (bool) wp_mail($to_email, $subject, $body, $headers);
    }

    /**
     * Build email body using the same snapshot object contract as print/shared.
     *
     * @param string $neighbor_name
     * @param string $shared_url
     * @param array $snapshot
     * @return string
     */
    private static function build_snapshot_email_html($neighbor_name, $shared_url, $snapshot) {
        $items = self::load_snapshot_resources($snapshot);
        $print_url = add_query_arg('print', '1', $shared_url);
        $resource_count = isset($snapshot['resource_count']) ? (int) $snapshot['resource_count'] : count($items);
        $org_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        if ($org_name === '') {
            $org_name = 'SVdP';
        }

        $cards_html = '';
        foreach ($items as $item) {
            $is_unavailable = !empty($item['_snapshot_unavailable']) || (isset($item['status']) && (string) $item['status'] !== 'active');
            $name = isset($item['resource_name']) ? (string) $item['resource_name'] : 'Resource';
            $organization = isset($item['organization']) ? (string) $item['organization'] : '';
            $service_areas = Resource_Taxonomy::get_service_area_labels_from_pipe(isset($item['service_area']) ? $item['service_area'] : '');
            $services = Resource_Taxonomy::get_services_offered_labels_from_pipe(isset($item['services_offered']) ? $item['services_offered'] : '');
            $provider = Resource_Taxonomy::get_provider_type_label(isset($item['provider_type']) ? $item['provider_type'] : '');
            $phone = isset($item['phone']) ? trim((string) $item['phone']) : '';
            $website = isset($item['website']) ? trim((string) $item['website']) : '';

            $status_badge = '';
            if ($is_unavailable) {
                $status_badge = '<div style="display:inline-block;margin:0 0 8px;padding:4px 9px;border-radius:999px;background:#f3f4f6;color:#374151;font-size:12px;font-weight:700;text-transform:uppercase;">No longer available</div>';
            }

            $contact_lines = '';
            if ($phone !== '') {
                if ($is_unavailable) {
                    $contact_lines .= '<div><strong>Phone:</strong> ' . esc_html($phone) . '</div>';
                } else {
                    $digits = preg_replace('/\D+/', '', $phone);
                    if (is_string($digits) && strlen($digits) >= 10) {
                        $contact_lines .= '<div><strong>Phone:</strong> <a href="tel:' . esc_attr('+' . $digits) . '" style="color:#0073aa;">' . esc_html($phone) . '</a></div>';
                    } else {
                        $contact_lines .= '<div><strong>Phone:</strong> ' . esc_html($phone) . '</div>';
                    }
                }
            }

            if ($website !== '') {
                if ($is_unavailable) {
                    $contact_lines .= '<div><strong>Website:</strong> ' . esc_html($website) . '</div>';
                } else {
                    $contact_lines .= '<div><strong>Website:</strong> <a href="' . esc_url($website) . '" style="color:#0073aa;" target="_blank" rel="noopener">' . esc_html($website) . '</a></div>';
                }
            }

            $service_lines = '';
            if (!empty($service_areas)) {
                $service_lines .= '<div><strong>Service Areas:</strong> ' . esc_html(implode(', ', $service_areas)) . '</div>';
            }
            if (!empty($services)) {
                $service_lines .= '<div><strong>Services Offered:</strong> ' . esc_html(implode(', ', $services)) . '</div>';
            }
            if ($provider !== '') {
                $service_lines .= '<div><strong>System Type:</strong> ' . esc_html($provider) . '</div>';
            }

            $cards_html .= '<div style="border:1px solid #d9dee6;border-radius:8px;padding:14px;margin:0 0 12px;background:' . ($is_unavailable ? '#f8fafc' : '#ffffff') . ';opacity:' . ($is_unavailable ? '0.72' : '1') . ';">'
                . $status_badge
                . '<h3 style="margin:0 0 6px;font-size:18px;color:#0f172a;">' . esc_html($name) . '</h3>'
                . ($organization !== '' ? '<div style="margin:0 0 8px;color:#4b5563;font-style:italic;">' . esc_html($organization) . '</div>' : '')
                . $service_lines
                . $contact_lines
                . '</div>';
        }

        return '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif;color:#1f2933;line-height:1.5;">'
            . '<h2 style="margin:0 0 8px;color:#0f172a;">Resources for ' . esc_html($neighbor_name) . '</h2>'
            . '<p style="margin:0 0 12px;">' . esc_html($org_name) . ' shared this resource snapshot for you.</p>'
            . '<p style="margin:0 0 12px;"><a href="' . esc_url($shared_url) . '" style="color:#0073aa;">View Online</a> | <a href="' . esc_url($print_url) . '" style="color:#0073aa;">Open Print Version</a></p>'
            . '<p style="margin:0 0 14px;color:#4b5563;">This list contains ' . esc_html((string) $resource_count) . ' resources. Print view includes a QR code labeled "Scan to view online".</p>'
            . $cards_html
            . '<p style="margin:14px 0 0;color:#4b5563;">If you need help, call <strong>(260) 456-3561</strong>.</p>'
            . '</div>';
    }

    /**
     * Send text message through Twilio REST API.
     *
     * @param string $to
     * @param string $message
     * @return true|WP_Error
     */
    private static function send_snapshot_text($to, $message) {
        $credentials = self::get_twilio_credentials();
        if (is_wp_error($credentials)) {
            return $credentials;
        }

        $endpoint = sprintf(
            'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
            rawurlencode($credentials['account_sid'])
        );

        $response = wp_remote_post($endpoint, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['account_sid'] . ':' . $credentials['auth_token'])
            ),
            'body' => array(
                'To' => $to,
                'From' => $credentials['from_number'],
                'Body' => $message
            )
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $body = wp_remote_retrieve_body($response);
            self::log_event('twilio_send_failed', array(
                'response_code' => $code,
                'response_body' => $body
            ));
            return new WP_Error('twilio_send_failed', 'Twilio rejected the text message request.');
        }

        return true;
    }

    /**
     * Get Twilio credentials from options or constants.
     *
     * @return array|WP_Error
     */
    private static function get_twilio_credentials() {
        $account_sid = trim((string) get_option('svdp_twilio_account_sid', ''));
        $auth_token = trim((string) get_option('svdp_twilio_auth_token', ''));
        $from_number = trim((string) get_option('svdp_twilio_from_number', ''));

        if ($account_sid === '' && defined('SVDP_TWILIO_ACCOUNT_SID')) {
            $account_sid = trim((string) SVDP_TWILIO_ACCOUNT_SID);
        }
        if ($auth_token === '' && defined('SVDP_TWILIO_AUTH_TOKEN')) {
            $auth_token = trim((string) SVDP_TWILIO_AUTH_TOKEN);
        }
        if ($from_number === '' && defined('SVDP_TWILIO_FROM_NUMBER')) {
            $from_number = trim((string) SVDP_TWILIO_FROM_NUMBER);
        }

        if ($account_sid === '' || $auth_token === '' || $from_number === '') {
            return new WP_Error('missing_twilio_config', 'Twilio is not configured.');
        }

        return array(
            'account_sid' => $account_sid,
            'auth_token' => $auth_token,
            'from_number' => $from_number
        );
    }

    /**
     * Public configuration check used by UI controls.
     *
     * @return bool
     */
    public static function is_twilio_configured() {
        $credentials = self::get_twilio_credentials();
        return !is_wp_error($credentials);
    }

    /**
     * Rate limiting by IP/session.
     *
     * @param string $action
     * @return null|WP_Error
     */
    private static function enforce_rate_limit($action) {
        $config = self::get_rate_limit_config($action);
        if (empty($config['window'])) {
            return null;
        }

        $window = (int) $config['window'];
        $limits = array(
            'ip' => isset($config['ip']) ? (int) $config['ip'] : 0,
            'session' => isset($config['session']) ? (int) $config['session'] : 0
        );

        $subjects = array(
            'ip' => self::get_client_ip(),
            'session' => self::get_rate_limit_session_id()
        );

        $retry_after = max(1, $window - (time() % $window));

        foreach ($limits as $scope => $limit) {
            if ($limit <= 0 || empty($subjects[$scope])) {
                continue;
            }

            $counter_key = 'svdp_rl_' . md5($action . '|' . $scope . '|' . $subjects[$scope] . '|' . floor(time() / $window));
            $count = (int) get_transient($counter_key);
            if ($count >= $limit) {
                self::log_event('rate_limited', array(
                    'action' => $action,
                    'scope' => $scope,
                    'identifier' => $subjects[$scope],
                    'count' => $count,
                    'limit' => $limit
                ));
                return new WP_Error(
                    'rate_limited',
                    'Too many requests. Please try again shortly.',
                    array('retry_after' => $retry_after)
                );
            }

            set_transient($counter_key, $count + 1, $window + 60);
        }

        return null;
    }

    /**
     * Rate-limit configuration.
     *
     * @param string $action
     * @return array
     */
    private static function get_rate_limit_config($action) {
        $defaults = array(
            'snapshot_create' => array('window' => HOUR_IN_SECONDS, 'ip' => 30, 'session' => 20),
            'print' => array('window' => HOUR_IN_SECONDS, 'ip' => 80, 'session' => 60),
            'email' => array('window' => HOUR_IN_SECONDS, 'ip' => 16, 'session' => 10),
            'text' => array('window' => HOUR_IN_SECONDS, 'ip' => 12, 'session' => 8),
            'inline_save' => array('window' => HOUR_IN_SECONDS, 'ip' => 160, 'session' => 100)
        );

        $all = apply_filters('svdp_resource_rate_limits', $defaults);
        return isset($all[$action]) && is_array($all[$action]) ? $all[$action] : array();
    }

    /**
     * Generate token.
     *
     * @return string
     */
    private static function generate_snapshot_token() {
        for ($i = 0; $i < 5; $i++) {
            $token = wp_generate_password(48, false, false);
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    }

    /**
     * Locked SMS copy.
     *
     * @param string $neighbor_name
     * @param string $shared_url
     * @return string
     */
    private static function build_sms_copy($neighbor_name, $shared_url) {
        $org_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        if ($org_name === '') {
            $org_name = 'SVdP';
        }

        $neighbor_name = trim((string) $neighbor_name);
        if ($neighbor_name === '') {
            $neighbor_name = 'Neighbor';
        }

        return sprintf(
            'Hi %1$s. %2$s here. Sharing the resources you asked for: %3$s. Use it anytime to view details or print.',
            $neighbor_name,
            $org_name,
            $shared_url
        );
    }

    /**
     * Normalize input resource IDs.
     *
     * @param mixed $value
     * @return array
     */
    private static function sanitize_resource_ids($value) {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value);
        }

        if (!is_array($value)) {
            return array();
        }

        $ids = array_map('intval', $value);
        $ids = array_values(array_unique(array_filter($ids, function($id) {
            return $id > 0;
        })));

        return $ids;
    }

    /**
     * Build rate-limit session ID (cookie-backed).
     *
     * @return string
     */
    private static function get_rate_limit_session_id() {
        $cookie_name = 'svdp_rl_session';
        $existing = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])) : '';
        if ($existing !== '') {
            return $existing;
        }

        $session_id = wp_generate_password(32, false, false);
        if (!headers_sent()) {
            setcookie($cookie_name, $session_id, time() + (30 * DAY_IN_SECONDS), COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        $_COOKIE[$cookie_name] = $session_id;
        return $session_id;
    }

    /**
     * Resolve client IP (best effort).
     *
     * @return string
     */
    private static function get_client_ip() {
        $keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $raw = sanitize_text_field(wp_unslash($_SERVER[$key]));
            $parts = explode(',', $raw);
            $candidate = trim((string) $parts[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    /**
     * Normalize to E.164 for US numbers.
     *
     * @param string $value
     * @return string
     */
    private static function normalize_phone_to_e164($value) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === null) {
            return '';
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
            return '+' . $digits;
        }

        return '';
    }

    /**
     * Check path is district-resources route.
     *
     * @param string $path
     * @return bool
     */
    private static function is_district_path($path) {
        $path = '/' . ltrim((string) $path, '/');
        return $path === '/district-resources' || strpos($path, '/district-resources/') === 0;
    }

    /**
     * Resolve source path from request context.
     *
     * @param string $source_url
     * @return string
     */
    private static function resolve_source_path($source_url = '') {
        if ($source_url !== '') {
            $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
            if ($path !== '') {
                return $path;
            }
        }

        if (!empty($_SERVER['HTTP_REFERER'])) {
            $path = (string) wp_parse_url(wp_unslash($_SERVER['HTTP_REFERER']), PHP_URL_PATH);
            if ($path !== '') {
                return $path;
            }
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            return (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        }

        return '';
    }

    /**
     * URL-safe base64 encode.
     *
     * @param string $value
     * @return string
     */
    private static function base64url_encode($value) {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    /**
     * URL-safe base64 decode.
     *
     * @param string $value
     * @return string
     */
    private static function base64url_decode($value) {
        $value = strtr((string) $value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($value, true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * Structured logging helper.
     *
     * @param string $event
     * @param array $context
     * @return void
     */
    private static function log_event($event, $context = array()) {
        $payload = array(
            'event' => $event,
            'context' => $context,
            'time' => gmdate('c')
        );
        error_log('SVdP Resources: ' . wp_json_encode($payload));
        do_action('svdp_resource_snapshot_event', $event, $context);
    }
}
