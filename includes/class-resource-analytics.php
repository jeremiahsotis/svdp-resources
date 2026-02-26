<?php
/**
 * Analytics event capture, rollups, retention, and dashboard data queries.
 */

class Resource_Analytics {

    const ROLLUP_CRON_HOOK = 'svdp_resources_analytics_rollup_daily';
    const RETENTION_CRON_HOOK = 'svdp_resources_analytics_retention_daily';
    const SEGMENT_RULE_VERSION = '2026-02-26';

    /**
     * Wire analytics hooks.
     */
    public function __construct() {
        add_action('wp_ajax_svdp_resource_analytics_event', array($this, 'ajax_track_event'));
        add_action('wp_ajax_nopriv_svdp_resource_analytics_event', array($this, 'ajax_track_event'));

        add_action('svdp_resource_snapshot_event', array($this, 'handle_snapshot_event'), 10, 2);

        add_action('wp', array($this, 'maybe_schedule_cron_jobs'));
        add_action(self::ROLLUP_CRON_HOOK, array(__CLASS__, 'run_daily_rollup_job'));
        add_action(self::RETENTION_CRON_HOOK, array(__CLASS__, 'run_retention_job'));
    }

    /**
     * Analytics events table.
     *
     * @return string
     */
    public static function get_events_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'svdpr_analytics_events';
    }

    /**
     * Analytics event geographies table.
     *
     * @return string
     */
    public static function get_event_geographies_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'svdpr_analytics_event_geographies';
    }

    /**
     * Snapshot-resource mapping table.
     *
     * @return string
     */
    public static function get_snapshot_resources_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'svdpr_analytics_snapshot_resources';
    }

    /**
     * Daily rollup table.
     *
     * @return string
     */
    public static function get_rollup_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'svdpr_analytics_rollup_daily';
    }

    /**
     * Ensure analytics schema exists.
     *
     * @return void
     */
    public static function ensure_schema() {
        global $wpdb;

        $events_table = self::get_events_table_name();
        $event_geo_table = self::get_event_geographies_table_name();
        $snapshot_resources_table = self::get_snapshot_resources_table_name();
        $rollup_table = self::get_rollup_table_name();
        $registry_table = Resource_Geography_Registry::get_registry_table_name();
        $shortcode_map_table = Resource_Geography_Registry::get_map_table_name();

        $charset_collate = $wpdb->get_charset_collate();

        $sql_events = "CREATE TABLE $events_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_name varchar(64) NOT NULL,
            event_ts_utc datetime NOT NULL,
            event_date_local date NOT NULL,
            segment varchar(32) NOT NULL,
            segment_rule_version varchar(16) NOT NULL,
            source_path varchar(255) NOT NULL,
            source_url text DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            is_authenticated tinyint(1) NOT NULL DEFAULT 0,
            session_id_hash char(64) NOT NULL,
            resource_id bigint(20) unsigned DEFAULT NULL,
            snapshot_id bigint(20) unsigned DEFAULT NULL,
            channel varchar(16) DEFAULT NULL,
            query_text_sanitized text DEFAULT NULL,
            query_hash char(64) DEFAULT NULL,
            result_count int(11) DEFAULT NULL,
            status varchar(16) NOT NULL DEFAULT 'success',
            error_code varchar(64) DEFAULT NULL,
            meta_json longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_event_name (event_name),
            KEY idx_event_ts_utc (event_ts_utc),
            KEY idx_event_date_local (event_date_local),
            KEY idx_segment (segment),
            KEY idx_source_path (source_path),
            KEY idx_user_id (user_id),
            KEY idx_session_id_hash (session_id_hash),
            KEY idx_resource_id (resource_id),
            KEY idx_snapshot_id (snapshot_id),
            KEY idx_channel (channel),
            KEY idx_query_hash (query_hash),
            KEY idx_status (status),
            KEY idx_date_segment_event (event_date_local, segment, event_name),
            KEY idx_event_geography_hint (event_name, event_date_local)
        ) $charset_collate;";

        $sql_event_geo = "CREATE TABLE $event_geo_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            geography_slug varchar(191) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_event_geo (event_id, geography_slug),
            KEY idx_event_id (event_id),
            KEY idx_geography_slug (geography_slug)
        ) $charset_collate;";

        $sql_snapshot_resources = "CREATE TABLE $snapshot_resources_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            snapshot_id bigint(20) unsigned NOT NULL,
            snapshot_token varchar(80) NOT NULL,
            resource_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_snapshot_resource (snapshot_id, resource_id),
            KEY idx_snapshot_id (snapshot_id),
            KEY idx_snapshot_token (snapshot_token),
            KEY idx_resource_id (resource_id),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

        $sql_registry = "CREATE TABLE $registry_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(191) NOT NULL,
            label varchar(255) NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            display_order int(11) NOT NULL DEFAULT 0,
            source_type varchar(32) NOT NULL DEFAULT 'manual',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_slug (slug),
            KEY idx_active_order (is_active, display_order),
            KEY idx_source_type (source_type)
        ) $charset_collate;";

        $sql_shortcode_map = "CREATE TABLE $shortcode_map_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            path varchar(255) NOT NULL,
            shortcode_hash char(64) NOT NULL,
            geography_slug varchar(191) NOT NULL,
            last_seen_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_shortcode_geo (post_id, shortcode_hash, geography_slug),
            KEY idx_post_id (post_id),
            KEY idx_path (path),
            KEY idx_shortcode_hash (shortcode_hash),
            KEY idx_geography_slug (geography_slug),
            KEY idx_last_seen (last_seen_at)
        ) $charset_collate;";

        $sql_rollup = "CREATE TABLE $rollup_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rollup_date date NOT NULL,
            segment varchar(32) NOT NULL,
            geography_slug varchar(191) NOT NULL DEFAULT '',
            channel varchar(16) NOT NULL DEFAULT '',
            search_count int(11) NOT NULL DEFAULT 0,
            unique_query_count int(11) NOT NULL DEFAULT 0,
            zero_result_count int(11) NOT NULL DEFAULT 0,
            low_result_count int(11) NOT NULL DEFAULT 0,
            detail_open_count int(11) NOT NULL DEFAULT 0,
            contact_click_count int(11) NOT NULL DEFAULT 0,
            snapshot_create_count int(11) NOT NULL DEFAULT 0,
            snapshot_send_attempt_count int(11) NOT NULL DEFAULT 0,
            snapshot_sent_count int(11) NOT NULL DEFAULT 0,
            snapshot_send_fail_count int(11) NOT NULL DEFAULT 0,
            snapshot_view_count int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_rollup_slice (rollup_date, segment, geography_slug, channel),
            KEY idx_rollup_date (rollup_date),
            KEY idx_rollup_geo (geography_slug),
            KEY idx_rollup_channel (channel)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_events);
        dbDelta($sql_event_geo);
        dbDelta($sql_snapshot_resources);
        dbDelta($sql_registry);
        dbDelta($sql_shortcode_map);
        dbDelta($sql_rollup);

        Resource_Geography_Registry::ensure_seed_data();
    }

    /**
     * Schedule rollup and retention jobs.
     *
     * @return void
     */
    public function maybe_schedule_cron_jobs() {
        if (!wp_next_scheduled(self::ROLLUP_CRON_HOOK)) {
            wp_schedule_event(time() + (2 * HOUR_IN_SECONDS), 'daily', self::ROLLUP_CRON_HOOK);
        }

        if (!wp_next_scheduled(self::RETENTION_CRON_HOOK)) {
            wp_schedule_event(time() + (3 * HOUR_IN_SECONDS), 'daily', self::RETENTION_CRON_HOOK);
        }
    }

    /**
     * Daily rollup job.
     *
     * @return void
     */
    public static function run_daily_rollup_job() {
        $today_local_ts = current_time('timestamp');
        $rollup_date = gmdate('Y-m-d', $today_local_ts - DAY_IN_SECONDS);
        self::rebuild_rollups_for_range($rollup_date, $rollup_date);
    }

    /**
     * Retention cleanup job.
     *
     * @return void
     */
    public static function run_retention_job() {
        global $wpdb;

        $months = max(1, (int) get_option('monday_resources_analytics_raw_retention_months', 13));
        $cutoff_ts = strtotime('-' . $months . ' months', current_time('timestamp', true));
        if ($cutoff_ts === false) {
            return;
        }

        $cutoff_utc = gmdate('Y-m-d H:i:s', $cutoff_ts);
        $events_table = self::get_events_table_name();
        $event_geo_table = self::get_event_geographies_table_name();

        do {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id
                    FROM $events_table
                    WHERE event_ts_utc < %s
                    ORDER BY id ASC
                    LIMIT 2000",
                    $cutoff_utc
                )
            );

            if (!is_array($ids) || empty($ids)) {
                break;
            }

            $ids = array_map('intval', $ids);
            $ids = array_values(array_filter($ids, function($id) {
                return $id > 0;
            }));
            if (empty($ids)) {
                break;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM $event_geo_table WHERE event_id IN ($placeholders)", $ids));
            $wpdb->query($wpdb->prepare("DELETE FROM $events_table WHERE id IN ($placeholders)", $ids));
        } while (true);
    }

    /**
     * Rollup rebuild for date range.
     *
     * @param string $start_date
     * @param string $end_date
     * @return void
     */
    public static function rebuild_rollups_for_range($start_date, $end_date) {
        $start_ts = strtotime((string) $start_date . ' 00:00:00');
        $end_ts = strtotime((string) $end_date . ' 00:00:00');
        if ($start_ts === false || $end_ts === false) {
            return;
        }

        if ($end_ts < $start_ts) {
            $tmp = $start_ts;
            $start_ts = $end_ts;
            $end_ts = $tmp;
        }

        $current = $start_ts;
        while ($current <= $end_ts) {
            self::rebuild_rollup_for_date(gmdate('Y-m-d', $current));
            $current += DAY_IN_SECONDS;
        }
    }

    /**
     * Rebuild rollups for a single local date.
     *
     * @param string $date
     * @return void
     */
    public static function rebuild_rollup_for_date($date) {
        global $wpdb;

        $rollup_table = self::get_rollup_table_name();
        $events_table = self::get_events_table_name();
        $event_geo_table = self::get_event_geographies_table_name();

        $date = gmdate('Y-m-d', strtotime((string) $date));
        if ($date === '1970-01-01') {
            return;
        }

        $wpdb->delete($rollup_table, array('rollup_date' => $date), array('%s'));

        $base_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    event_date_local AS rollup_date,
                    segment,
                    COALESCE(channel, '') AS channel,
                    SUM(CASE WHEN event_name = 'search_executed' THEN 1 ELSE 0 END) AS search_count,
                    COUNT(DISTINCT CASE WHEN event_name = 'search_executed' AND query_hash IS NOT NULL AND query_hash <> '' THEN query_hash ELSE NULL END) AS unique_query_count,
                    SUM(CASE WHEN event_name = 'search_zero_results' THEN 1 ELSE 0 END) AS zero_result_count,
                    SUM(CASE WHEN event_name = 'search_low_results' THEN 1 ELSE 0 END) AS low_result_count,
                    SUM(CASE WHEN event_name = 'resource_detail_opened' THEN 1 ELSE 0 END) AS detail_open_count,
                    SUM(CASE WHEN event_name = 'resource_contact_clicked' THEN 1 ELSE 0 END) AS contact_click_count,
                    SUM(CASE WHEN event_name = 'snapshot_created' THEN 1 ELSE 0 END) AS snapshot_create_count,
                    SUM(CASE WHEN event_name = 'snapshot_send_attempted' THEN 1 ELSE 0 END) AS snapshot_send_attempt_count,
                    SUM(CASE WHEN event_name = 'snapshot_sent' THEN 1 ELSE 0 END) AS snapshot_sent_count,
                    SUM(CASE WHEN event_name = 'snapshot_send_failed' THEN 1 ELSE 0 END) AS snapshot_send_fail_count,
                    SUM(CASE WHEN event_name = 'snapshot_viewed' THEN 1 ELSE 0 END) AS snapshot_view_count
                FROM $events_table
                WHERE event_date_local = %s
                GROUP BY event_date_local, segment, COALESCE(channel, '')",
                $date
            ),
            ARRAY_A
        );

        if (is_array($base_rows)) {
            foreach ($base_rows as $row) {
                self::upsert_rollup_row($row, '');
            }
        }

        $geo_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    e.event_date_local AS rollup_date,
                    e.segment,
                    eg.geography_slug,
                    COALESCE(e.channel, '') AS channel,
                    SUM(CASE WHEN e.event_name = 'search_executed' THEN 1 ELSE 0 END) AS search_count,
                    COUNT(DISTINCT CASE WHEN e.event_name = 'search_executed' AND e.query_hash IS NOT NULL AND e.query_hash <> '' THEN e.query_hash ELSE NULL END) AS unique_query_count,
                    SUM(CASE WHEN e.event_name = 'search_zero_results' THEN 1 ELSE 0 END) AS zero_result_count,
                    SUM(CASE WHEN e.event_name = 'search_low_results' THEN 1 ELSE 0 END) AS low_result_count,
                    SUM(CASE WHEN e.event_name = 'resource_detail_opened' THEN 1 ELSE 0 END) AS detail_open_count,
                    SUM(CASE WHEN e.event_name = 'resource_contact_clicked' THEN 1 ELSE 0 END) AS contact_click_count,
                    SUM(CASE WHEN e.event_name = 'snapshot_created' THEN 1 ELSE 0 END) AS snapshot_create_count,
                    SUM(CASE WHEN e.event_name = 'snapshot_send_attempted' THEN 1 ELSE 0 END) AS snapshot_send_attempt_count,
                    SUM(CASE WHEN e.event_name = 'snapshot_sent' THEN 1 ELSE 0 END) AS snapshot_sent_count,
                    SUM(CASE WHEN e.event_name = 'snapshot_send_failed' THEN 1 ELSE 0 END) AS snapshot_send_fail_count,
                    SUM(CASE WHEN e.event_name = 'snapshot_viewed' THEN 1 ELSE 0 END) AS snapshot_view_count
                FROM $events_table e
                INNER JOIN $event_geo_table eg ON eg.event_id = e.id
                WHERE e.event_date_local = %s
                GROUP BY e.event_date_local, e.segment, eg.geography_slug, COALESCE(e.channel, '')",
                $date
            ),
            ARRAY_A
        );

        if (is_array($geo_rows)) {
            foreach ($geo_rows as $row) {
                $geo = isset($row['geography_slug']) ? sanitize_key((string) $row['geography_slug']) : '';
                self::upsert_rollup_row($row, $geo);
            }
        }
    }

    /**
     * Insert/update a rollup row.
     *
     * @param array $row
     * @param string $geography_slug
     * @return void
     */
    private static function upsert_rollup_row($row, $geography_slug) {
        global $wpdb;

        $table = self::get_rollup_table_name();
        $rollup_date = isset($row['rollup_date']) ? sanitize_text_field((string) $row['rollup_date']) : '';
        $segment = isset($row['segment']) ? sanitize_key((string) $row['segment']) : 'unknown';
        $channel = isset($row['channel']) ? sanitize_key((string) $row['channel']) : '';
        $geography_slug = sanitize_key((string) $geography_slug);

        if ($rollup_date === '') {
            return;
        }

        $wpdb->replace(
            $table,
            array(
                'rollup_date' => $rollup_date,
                'segment' => $segment,
                'geography_slug' => $geography_slug,
                'channel' => $channel,
                'search_count' => (int) $row['search_count'],
                'unique_query_count' => (int) $row['unique_query_count'],
                'zero_result_count' => (int) $row['zero_result_count'],
                'low_result_count' => (int) $row['low_result_count'],
                'detail_open_count' => (int) $row['detail_open_count'],
                'contact_click_count' => (int) $row['contact_click_count'],
                'snapshot_create_count' => (int) $row['snapshot_create_count'],
                'snapshot_send_attempt_count' => (int) $row['snapshot_send_attempt_count'],
                'snapshot_sent_count' => (int) $row['snapshot_sent_count'],
                'snapshot_send_fail_count' => (int) $row['snapshot_send_fail_count'],
                'snapshot_view_count' => (int) $row['snapshot_view_count']
            ),
            array('%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d')
        );
    }

    /**
     * Track standardized search events.
     *
     * @param array $payload
     * @return array
     */
    public static function track_search_request($payload) {
        if (!monday_resources_is_analytics_capture_enabled()) {
            return array('search_event_id' => 0, 'extra_event_ids' => array());
        }

        $payload = is_array($payload) ? $payload : array();

        $query = isset($payload['q']) ? (string) $payload['q'] : '';
        $result_count = isset($payload['result_count']) ? (int) $payload['result_count'] : null;
        $source_url = isset($payload['source_url']) ? esc_url_raw((string) $payload['source_url']) : '';

        $meta = array(
            'service_area' => isset($payload['service_area']) ? (array) $payload['service_area'] : array(),
            'services_offered' => isset($payload['services_offered']) ? (array) $payload['services_offered'] : array(),
            'provider_type' => isset($payload['provider_type']) ? (string) $payload['provider_type'] : '',
            'population' => isset($payload['population']) ? (array) $payload['population'] : array(),
            'geography_prefilter' => isset($payload['geography_prefilter']) ? (array) $payload['geography_prefilter'] : array(),
            'service_type_prefilter' => isset($payload['service_type_prefilter']) ? (array) $payload['service_type_prefilter'] : array(),
            'free_text_query' => $query,
            'page' => isset($payload['page']) ? (int) $payload['page'] : 1,
            'per_page' => isset($payload['per_page']) ? (int) $payload['per_page'] : 25
        );

        $geography_slugs = Resource_Geography_Registry::normalize_input_to_slugs(
            isset($payload['geography_prefilter']) ? (array) $payload['geography_prefilter'] : array()
        );

        $search_event_id = self::write_event(array(
            'event_name' => 'search_executed',
            'source_url' => $source_url,
            'query_text' => $query,
            'result_count' => $result_count,
            'status' => 'success',
            'meta' => $meta,
            'geography_slugs' => $geography_slugs
        ));

        $extra_event_ids = array();
        if ($result_count !== null && $result_count === 0) {
            $extra_event_ids[] = self::write_event(array(
                'event_name' => 'search_zero_results',
                'source_url' => $source_url,
                'query_text' => $query,
                'result_count' => $result_count,
                'status' => 'success',
                'meta' => $meta,
                'geography_slugs' => $geography_slugs
            ));
        }

        if ($result_count !== null && $result_count <= self::get_low_result_threshold()) {
            $extra_event_ids[] = self::write_event(array(
                'event_name' => 'search_low_results',
                'source_url' => $source_url,
                'query_text' => $query,
                'result_count' => $result_count,
                'status' => 'success',
                'meta' => $meta,
                'geography_slugs' => $geography_slugs
            ));
        }

        return array(
            'search_event_id' => (int) $search_event_id,
            'extra_event_ids' => array_values(array_filter(array_map('intval', $extra_event_ids)))
        );
    }

    /**
     * AJAX tracking endpoint for frontend interactions.
     *
     * @return void
     */
    public function ajax_track_event() {
        check_ajax_referer('monday_resources_nonce', 'nonce');

        if (!monday_resources_is_analytics_capture_enabled()) {
            wp_send_json_success(array('captured' => false));
        }

        $event_name = isset($_POST['event_name']) ? sanitize_key(wp_unslash($_POST['event_name'])) : '';
        $allowed_events = array(
            'resource_detail_opened',
            'resource_contact_clicked',
            'load_more_clicked',
            'filter_sheet_opened',
            'filter_applied'
        );

        if (!in_array($event_name, $allowed_events, true)) {
            wp_send_json_error(array('message' => 'Unsupported event.'), 400);
        }

        $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';
        $resource_id = isset($_POST['resource_id']) ? (int) $_POST['resource_id'] : null;
        if ($resource_id !== null && $resource_id <= 0) {
            $resource_id = null;
        }

        $meta = array();
        if (isset($_POST['meta'])) {
            $raw_meta = wp_unslash($_POST['meta']);
            if (is_string($raw_meta)) {
                $decoded = json_decode($raw_meta, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            } elseif (is_array($raw_meta)) {
                $meta = $raw_meta;
            }
        }

        $geography_slugs = array();
        if (isset($_POST['geography_prefilter'])) {
            $geo = wp_unslash($_POST['geography_prefilter']);
            $geo = is_array($geo) ? $geo : array($geo);
            $geography_slugs = Resource_Geography_Registry::normalize_input_to_slugs($geo);
        }

        $event_id = self::write_event(array(
            'event_name' => $event_name,
            'source_url' => $source_url,
            'resource_id' => $resource_id,
            'channel' => isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : '',
            'meta' => $meta,
            'geography_slugs' => $geography_slugs,
            'status' => 'success'
        ));

        wp_send_json_success(array('captured' => $event_id > 0, 'event_id' => (int) $event_id));
    }

    /**
     * Snapshot event bridge.
     *
     * @param string $event
     * @param array $context
     * @return void
     */
    public function handle_snapshot_event($event, $context) {
        if (!monday_resources_is_analytics_capture_enabled()) {
            return;
        }

        $context = is_array($context) ? $context : array();
        $source_url = isset($context['source_url']) ? esc_url_raw((string) $context['source_url']) : '';
        $snapshot_id = isset($context['snapshot_id']) ? (int) $context['snapshot_id'] : null;
        $snapshot_token = isset($context['snapshot_token']) ? sanitize_text_field((string) $context['snapshot_token']) : '';
        $channel = isset($context['channel']) ? sanitize_key((string) $context['channel']) : '';

        $resource_ids = isset($context['resource_ids']) ? self::sanitize_int_list($context['resource_ids']) : array();

        switch ((string) $event) {
            case 'snapshot_created':
                self::write_event(array(
                    'event_name' => 'snapshot_created',
                    'source_url' => $source_url,
                    'snapshot_id' => $snapshot_id,
                    'channel' => $channel,
                    'meta' => array(
                        'resource_count' => count($resource_ids),
                        'channel_intent' => $channel
                    )
                ));
                if ($snapshot_id !== null && $snapshot_id > 0 && !empty($resource_ids)) {
                    self::register_snapshot_resources($snapshot_id, $snapshot_token, $resource_ids);
                }
                break;

            case 'snapshot_send_attempted':
                self::write_event(array(
                    'event_name' => 'snapshot_send_attempted',
                    'source_url' => $source_url,
                    'snapshot_id' => $snapshot_id,
                    'channel' => $channel,
                    'meta' => array('attempt' => true)
                ));
                break;

            case 'snapshot_sent':
                self::write_event(array(
                    'event_name' => 'snapshot_sent',
                    'source_url' => $source_url,
                    'snapshot_id' => $snapshot_id,
                    'channel' => $channel,
                    'meta' => array('delivery' => 'success')
                ));
                break;

            case 'snapshot_send_failed':
                self::write_event(array(
                    'event_name' => 'snapshot_send_failed',
                    'source_url' => $source_url,
                    'snapshot_id' => $snapshot_id,
                    'channel' => $channel,
                    'status' => 'error',
                    'error_code' => isset($context['error_code']) ? sanitize_key((string) $context['error_code']) : 'send_failed',
                    'meta' => array('delivery' => 'failed')
                ));
                break;

            case 'snapshot_viewed':
                self::write_event(array(
                    'event_name' => 'snapshot_viewed',
                    'source_url' => $source_url,
                    'snapshot_id' => $snapshot_id,
                    'meta' => array('viewed' => true)
                ));
                break;
        }
    }

    /**
     * Persist snapshot/resource mapping rows.
     *
     * @param int $snapshot_id
     * @param string $snapshot_token
     * @param array $resource_ids
     * @return void
     */
    public static function register_snapshot_resources($snapshot_id, $snapshot_token, $resource_ids) {
        global $wpdb;

        $snapshot_id = (int) $snapshot_id;
        if ($snapshot_id <= 0) {
            return;
        }

        $snapshot_token = sanitize_text_field((string) $snapshot_token);
        $resource_ids = self::sanitize_int_list($resource_ids);
        if (empty($resource_ids)) {
            return;
        }

        $table = self::get_snapshot_resources_table_name();
        foreach ($resource_ids as $resource_id) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO $table (snapshot_id, snapshot_token, resource_id, created_at)
                    VALUES (%d, %s, %d, %s)
                    ON DUPLICATE KEY UPDATE snapshot_token = VALUES(snapshot_token)",
                    $snapshot_id,
                    $snapshot_token,
                    $resource_id,
                    current_time('mysql', true)
                )
            );
        }
    }

    /**
     * Write an analytics event.
     *
     * @param array $args
     * @return int
     */
    public static function write_event($args) {
        global $wpdb;

        if (!is_array($args) || empty($args['event_name'])) {
            return 0;
        }

        $event_name = sanitize_key((string) $args['event_name']);
        if ($event_name === '') {
            return 0;
        }

        $source_url = isset($args['source_url']) ? esc_url_raw((string) $args['source_url']) : '';
        $source_path = isset($args['source_path'])
            ? Resource_Geography_Registry::canonicalize_path((string) $args['source_path'])
            : self::resolve_source_path($source_url);

        $segment = isset($args['segment']) && $args['segment'] !== ''
            ? sanitize_key((string) $args['segment'])
            : self::resolve_segment($source_path);

        $query_text = isset($args['query_text']) ? self::sanitize_query_text((string) $args['query_text']) : '';
        $query_hash = $query_text !== '' ? hash('sha256', strtolower($query_text)) : null;

        $meta = isset($args['meta']) ? self::sanitize_meta($args['meta']) : array();
        $meta_json = !empty($meta) ? wp_json_encode($meta) : null;

        $session_raw = self::get_or_create_session_id();
        $session_hash = hash_hmac('sha256', $session_raw, wp_salt('auth'));

        $resource_id = isset($args['resource_id']) ? (int) $args['resource_id'] : null;
        if ($resource_id !== null && $resource_id <= 0) {
            $resource_id = null;
        }

        $snapshot_id = isset($args['snapshot_id']) ? (int) $args['snapshot_id'] : null;
        if ($snapshot_id !== null && $snapshot_id <= 0) {
            $snapshot_id = null;
        }

        $channel = isset($args['channel']) ? sanitize_key((string) $args['channel']) : '';
        if (!in_array($channel, array('', 'print', 'email', 'text'), true)) {
            $channel = '';
        }

        $status = isset($args['status']) ? sanitize_key((string) $args['status']) : 'success';
        if (!in_array($status, array('success', 'error'), true)) {
            $status = 'success';
        }

        $event_ts_utc = gmdate('Y-m-d H:i:s');
        $event_date_local = current_time('Y-m-d');

        $events_table = self::get_events_table_name();

        $inserted = $wpdb->insert(
            $events_table,
            array(
                'event_name' => $event_name,
                'event_ts_utc' => $event_ts_utc,
                'event_date_local' => $event_date_local,
                'segment' => $segment,
                'segment_rule_version' => self::SEGMENT_RULE_VERSION,
                'source_path' => $source_path,
                'source_url' => $source_url,
                'user_id' => is_user_logged_in() ? (int) get_current_user_id() : null,
                'is_authenticated' => is_user_logged_in() ? 1 : 0,
                'session_id_hash' => $session_hash,
                'resource_id' => $resource_id,
                'snapshot_id' => $snapshot_id,
                'channel' => $channel === '' ? null : $channel,
                'query_text_sanitized' => $query_text === '' ? null : $query_text,
                'query_hash' => $query_hash,
                'result_count' => array_key_exists('result_count', $args) ? (int) $args['result_count'] : null,
                'status' => $status,
                'error_code' => isset($args['error_code']) ? sanitize_key((string) $args['error_code']) : null,
                'meta_json' => $meta_json
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            return 0;
        }

        $event_id = (int) $wpdb->insert_id;

        $geo_from_args = isset($args['geography_slugs']) ? Resource_Geography_Registry::normalize_input_to_slugs((array) $args['geography_slugs']) : array();
        $geo_from_path = Resource_Geography_Registry::get_geographies_for_path($source_path);
        $geo_slugs = array_values(array_unique(array_merge($geo_from_args, $geo_from_path)));

        if (!empty($geo_slugs)) {
            self::link_event_geographies($event_id, $geo_slugs);
        }

        return $event_id;
    }

    /**
     * Attach geographies to an event.
     *
     * @param int $event_id
     * @param array $geo_slugs
     * @return void
     */
    private static function link_event_geographies($event_id, $geo_slugs) {
        global $wpdb;

        $event_id = (int) $event_id;
        if ($event_id <= 0 || !is_array($geo_slugs) || empty($geo_slugs)) {
            return;
        }

        $table = self::get_event_geographies_table_name();
        foreach ($geo_slugs as $geo_slug) {
            $geo_slug = sanitize_key((string) $geo_slug);
            if ($geo_slug === '') {
                continue;
            }

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO $table (event_id, geography_slug)
                    VALUES (%d, %s)
                    ON DUPLICATE KEY UPDATE geography_slug = VALUES(geography_slug)",
                    $event_id,
                    $geo_slug
                )
            );
        }
    }

    /**
     * Resolve path from source URL, referer, or request URI.
     *
     * @param string $source_url
     * @return string
     */
    public static function resolve_source_path($source_url = '') {
        if ($source_url !== '') {
            $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
            if ($path !== '') {
                return Resource_Geography_Registry::canonicalize_path($path);
            }
        }

        if (!empty($_SERVER['HTTP_REFERER'])) {
            $path = (string) wp_parse_url(wp_unslash($_SERVER['HTTP_REFERER']), PHP_URL_PATH);
            if ($path !== '') {
                return Resource_Geography_Registry::canonicalize_path($path);
            }
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            $path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
            if ($path !== '') {
                return Resource_Geography_Registry::canonicalize_path($path);
            }
        }

        return '/';
    }

    /**
     * Segment resolver based on source path.
     *
     * @param string $source_path
     * @return string
     */
    public static function resolve_segment($source_path) {
        $path = strtolower(Resource_Geography_Registry::canonicalize_path((string) $source_path));

        if (strpos($path, 'master-resource-list') !== false) {
            return 'staff';
        }

        if ($path === '/district-resources' || strpos($path, '/district-resources/') === 0) {
            return 'vincentian_volunteer';
        }

        $partner_prefix = self::get_partner_prefix();
        if ($partner_prefix !== '' && $partner_prefix !== '/') {
            if ($path === $partner_prefix || strpos($path, $partner_prefix . '/') === 0) {
                return 'partner';
            }
        }

        return 'unknown';
    }

    /**
     * Partner prefix configuration.
     *
     * @return string
     */
    public static function get_partner_prefix() {
        $default = '/partner-resources/';
        $prefix = get_option('monday_resources_partner_path_prefix', $default);
        if (!is_string($prefix) || trim($prefix) === '') {
            $prefix = $default;
        }

        $prefix = Resource_Geography_Registry::canonicalize_path($prefix);
        return $prefix;
    }

    /**
     * Sanitizes search query text for analytics persistence.
     *
     * @param string $query
     * @return string
     */
    public static function sanitize_query_text($query) {
        $query = (string) $query;
        if ($query === '') {
            return '';
        }

        $query = wp_strip_all_tags($query);

        $query = preg_replace(
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
            '[redacted-email]',
            $query
        );

        $query = preg_replace(
            '/(?:\+?1[\s.\-]?)?(?:\(?\d{3}\)?[\s.\-]?)\d{3}[\s.\-]?\d{4}/',
            '[redacted-phone]',
            $query
        );

        $query = preg_replace('/\s+/', ' ', $query);
        $query = trim((string) $query);

        if (strlen($query) > 500) {
            $query = substr($query, 0, 500);
        }

        return sanitize_text_field($query);
    }

    /**
     * Session ID for analytics partitioning.
     *
     * @return string
     */
    private static function get_or_create_session_id() {
        $cookie_name = 'svdp_resources_analytics_session';
        $existing = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])) : '';
        if ($existing !== '') {
            return $existing;
        }

        $session_id = wp_generate_password(40, false, false);
        if (!headers_sent()) {
            setcookie(
                $cookie_name,
                $session_id,
                time() + (30 * DAY_IN_SECONDS),
                COOKIEPATH ? COOKIEPATH : '/',
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
        }

        $_COOKIE[$cookie_name] = $session_id;
        return $session_id;
    }

    /**
     * Low-result threshold config.
     *
     * @return int
     */
    public static function get_low_result_threshold() {
        return max(1, (int) get_option('monday_resources_analytics_low_result_threshold', 3));
    }

    /**
     * Dashboard/export filter sanitizer.
     *
     * @param array $raw
     * @return array
     */
    public static function sanitize_filters($raw) {
        $raw = is_array($raw) ? $raw : array();

        $today = current_time('Y-m-d');
        $default_start = gmdate('Y-m-d', strtotime($today . ' -29 days'));

        $preset = isset($raw['preset']) ? sanitize_key((string) $raw['preset']) : '30';
        if (!in_array($preset, array('7', '30', '90', 'custom'), true)) {
            $preset = '30';
        }

        if ($preset !== 'custom') {
            $days = (int) $preset;
            $start_date = gmdate('Y-m-d', strtotime($today . ' -' . max(0, $days - 1) . ' days'));
            $end_date = $today;
        } else {
            $start_date = isset($raw['start_date']) ? sanitize_text_field((string) $raw['start_date']) : $default_start;
            $end_date = isset($raw['end_date']) ? sanitize_text_field((string) $raw['end_date']) : $today;
        }

        $segment = isset($raw['segment']) ? sanitize_key((string) $raw['segment']) : 'all';
        if (!in_array($segment, array('all', 'staff', 'vincentian_volunteer', 'partner', 'unknown'), true)) {
            $segment = 'all';
        }

        $channel = isset($raw['channel']) ? sanitize_key((string) $raw['channel']) : 'all';
        if (!in_array($channel, array('all', 'print', 'email', 'text'), true)) {
            $channel = 'all';
        }

        $geography = isset($raw['geography']) ? sanitize_key((string) $raw['geography']) : 'all';
        if ($geography === '') {
            $geography = 'all';
        }

        return array(
            'preset' => $preset,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'segment' => $segment,
            'channel' => $channel,
            'geography' => $geography
        );
    }

    /**
     * Load dashboard dataset for current filters.
     *
     * @param array $filters
     * @return array
     */
    public static function get_dashboard_data($filters) {
        $filters = self::sanitize_filters($filters);

        $data = array(
            'filters' => $filters,
            'kpis' => self::get_kpis($filters),
            'trend' => self::get_trend_rows($filters),
            'top_queries' => self::get_top_queries($filters),
            'top_filters' => self::get_top_filter_terms($filters),
            'channel_mix' => self::get_channel_mix($filters),
            'top_shared_resources' => self::get_top_shared_resources($filters),
            'geography_summary' => self::get_geography_summary($filters)
        );

        return $data;
    }

    /**
     * Export payload helper (same data contract as dashboard).
     *
     * @param array $filters
     * @return array
     */
    public static function get_export_payload($filters) {
        return self::get_dashboard_data($filters);
    }

    /**
     * KPI block from rollups.
     *
     * @param array $filters
     * @return array
     */
    private static function get_kpis($filters) {
        global $wpdb;

        $table = self::get_rollup_table_name();
        $where = array('rollup_date BETWEEN %s AND %s');
        $params = array($filters['start_date'], $filters['end_date']);

        if ($filters['segment'] !== 'all') {
            $where[] = 'segment = %s';
            $params[] = $filters['segment'];
        }

        $where[] = 'geography_slug = %s';
        $params[] = $filters['geography'] === 'all' ? '' : $filters['geography'];

        if ($filters['channel'] !== 'all') {
            $where[] = 'channel = %s';
            $params[] = $filters['channel'];
        }

        $sql = "SELECT
            COALESCE(SUM(search_count), 0) AS searches,
            COALESCE(SUM(zero_result_count), 0) AS zero_results,
            COALESCE(SUM(snapshot_send_attempt_count), 0) AS snapshot_send_attempts,
            COALESCE(SUM(snapshot_sent_count), 0) AS snapshot_sent,
            COALESCE(SUM(snapshot_send_fail_count), 0) AS snapshot_send_fail
            FROM $table
            WHERE " . implode(' AND ', $where);

        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        $searches = isset($row['searches']) ? (int) $row['searches'] : 0;
        $zero_results = isset($row['zero_results']) ? (int) $row['zero_results'] : 0;
        $attempts = isset($row['snapshot_send_attempts']) ? (int) $row['snapshot_send_attempts'] : 0;
        $sent = isset($row['snapshot_sent']) ? (int) $row['snapshot_sent'] : 0;

        return array(
            'searches' => $searches,
            'zero_result_rate' => $searches > 0 ? round(($zero_results / $searches) * 100, 1) : 0,
            'snapshot_sends' => $sent,
            'send_success_rate' => $attempts > 0 ? round(($sent / $attempts) * 100, 1) : 0,
            'snapshot_send_attempts' => $attempts,
            'snapshot_send_fail' => isset($row['snapshot_send_fail']) ? (int) $row['snapshot_send_fail'] : 0
        );
    }

    /**
     * Daily trend rows.
     *
     * @param array $filters
     * @return array
     */
    private static function get_trend_rows($filters) {
        global $wpdb;

        $table = self::get_rollup_table_name();
        $where = array('rollup_date BETWEEN %s AND %s');
        $params = array($filters['start_date'], $filters['end_date']);

        if ($filters['segment'] !== 'all') {
            $where[] = 'segment = %s';
            $params[] = $filters['segment'];
        }

        $where[] = 'geography_slug = %s';
        $params[] = $filters['geography'] === 'all' ? '' : $filters['geography'];

        if ($filters['channel'] !== 'all') {
            $where[] = 'channel = %s';
            $params[] = $filters['channel'];
        }

        $sql = "SELECT
            rollup_date,
            COALESCE(SUM(search_count), 0) AS searches,
            COALESCE(SUM(zero_result_count), 0) AS zero_results,
            COALESCE(SUM(snapshot_sent_count), 0) AS snapshot_sent
            FROM $table
            WHERE " . implode(' AND ', $where) . "
            GROUP BY rollup_date
            ORDER BY rollup_date ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Top search query rows.
     *
     * @param array $filters
     * @return array
     */
    private static function get_top_queries($filters) {
        global $wpdb;

        $events_table = self::get_events_table_name();
        $event_geo_table = self::get_event_geographies_table_name();

        $joins = '';
        $where = array('e.event_name = %s', 'e.event_date_local BETWEEN %s AND %s', "e.query_text_sanitized IS NOT NULL", "e.query_text_sanitized <> ''");
        $params = array('search_executed', $filters['start_date'], $filters['end_date']);

        if ($filters['segment'] !== 'all') {
            $where[] = 'e.segment = %s';
            $params[] = $filters['segment'];
        }

        if ($filters['channel'] !== 'all') {
            $where[] = 'COALESCE(e.channel, \'\') = %s';
            $params[] = $filters['channel'];
        }

        if ($filters['geography'] !== 'all') {
            $joins .= " INNER JOIN $event_geo_table eg ON eg.event_id = e.id";
            $where[] = 'eg.geography_slug = %s';
            $params[] = $filters['geography'];
        }

        $sql = "SELECT e.query_text_sanitized AS query_text, COUNT(*) AS total
            FROM $events_table e
            $joins
            WHERE " . implode(' AND ', $where) . "
            GROUP BY e.query_text_sanitized
            ORDER BY total DESC
            LIMIT 15";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Top filter term aggregation from search meta.
     *
     * @param array $filters
     * @return array
     */
    private static function get_top_filter_terms($filters) {
        global $wpdb;

        $events_table = self::get_events_table_name();
        $event_geo_table = self::get_event_geographies_table_name();

        $joins = '';
        $where = array('e.event_name = %s', 'e.event_date_local BETWEEN %s AND %s', "e.meta_json IS NOT NULL", "e.meta_json <> ''");
        $params = array('search_executed', $filters['start_date'], $filters['end_date']);

        if ($filters['segment'] !== 'all') {
            $where[] = 'e.segment = %s';
            $params[] = $filters['segment'];
        }

        if ($filters['channel'] !== 'all') {
            $where[] = 'COALESCE(e.channel, \'\') = %s';
            $params[] = $filters['channel'];
        }

        if ($filters['geography'] !== 'all') {
            $joins .= " INNER JOIN $event_geo_table eg ON eg.event_id = e.id";
            $where[] = 'eg.geography_slug = %s';
            $params[] = $filters['geography'];
        }

        $sql = "SELECT e.meta_json
            FROM $events_table e
            $joins
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.id DESC
            LIMIT 4000";

        $rows = $wpdb->get_col($wpdb->prepare($sql, $params));
        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $counts = array();
        foreach ($rows as $meta_json) {
            $meta = json_decode((string) $meta_json, true);
            if (!is_array($meta)) {
                continue;
            }

            foreach (array('service_area', 'services_offered', 'population') as $key) {
                $values = isset($meta[$key]) ? (array) $meta[$key] : array();
                foreach ($values as $value) {
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }

                    $bucket = $key . ':' . $value;
                    if (!isset($counts[$bucket])) {
                        $counts[$bucket] = array(
                            'dimension' => $key,
                            'label' => $value,
                            'total' => 0
                        );
                    }
                    $counts[$bucket]['total']++;
                }
            }
        }

        if (empty($counts)) {
            return array();
        }

        usort($counts, function($a, $b) {
            return (int) $b['total'] <=> (int) $a['total'];
        });

        return array_slice($counts, 0, 20);
    }

    /**
     * Channel mix rows.
     *
     * @param array $filters
     * @return array
     */
    private static function get_channel_mix($filters) {
        global $wpdb;

        $events_table = self::get_events_table_name();
        $event_geo_table = self::get_event_geographies_table_name();

        $joins = '';
        $where = array('e.event_name = %s', 'e.event_date_local BETWEEN %s AND %s', "e.channel IN ('print', 'email', 'text')");
        $params = array('snapshot_sent', $filters['start_date'], $filters['end_date']);

        if ($filters['segment'] !== 'all') {
            $where[] = 'e.segment = %s';
            $params[] = $filters['segment'];
        }

        if ($filters['channel'] !== 'all') {
            $where[] = 'e.channel = %s';
            $params[] = $filters['channel'];
        }

        if ($filters['geography'] !== 'all') {
            $joins .= " INNER JOIN $event_geo_table eg ON eg.event_id = e.id";
            $where[] = 'eg.geography_slug = %s';
            $params[] = $filters['geography'];
        }

        $sql = "SELECT e.channel, COUNT(*) AS total
            FROM $events_table e
            $joins
            WHERE " . implode(' AND ', $where) . "
            GROUP BY e.channel
            ORDER BY total DESC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Top shared resource rows.
     *
     * @param array $filters
     * @return array
     */
    private static function get_top_shared_resources($filters) {
        global $wpdb;

        $events_table = self::get_events_table_name();
        $event_geo_table = self::get_event_geographies_table_name();
        $snapshot_resources_table = self::get_snapshot_resources_table_name();
        $resources_table = $wpdb->prefix . 'resources';

        $joins = " INNER JOIN $snapshot_resources_table sr ON sr.snapshot_id = e.snapshot_id
            LEFT JOIN $resources_table r ON r.id = sr.resource_id";

        $where = array('e.event_name = %s', 'e.event_date_local BETWEEN %s AND %s');
        $params = array('snapshot_sent', $filters['start_date'], $filters['end_date']);

        if ($filters['segment'] !== 'all') {
            $where[] = 'e.segment = %s';
            $params[] = $filters['segment'];
        }

        if ($filters['channel'] !== 'all') {
            $where[] = 'COALESCE(e.channel, \'\') = %s';
            $params[] = $filters['channel'];
        }

        if ($filters['geography'] !== 'all') {
            $joins .= " INNER JOIN $event_geo_table eg ON eg.event_id = e.id";
            $where[] = 'eg.geography_slug = %s';
            $params[] = $filters['geography'];
        }

        $sql = "SELECT
            sr.resource_id,
            COALESCE(r.resource_name, CONCAT('Resource #', sr.resource_id)) AS resource_name,
            COUNT(*) AS sent_count
            FROM $events_table e
            $joins
            WHERE " . implode(' AND ', $where) . "
            GROUP BY sr.resource_id, resource_name
            ORDER BY sent_count DESC
            LIMIT 15";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Geography summary with prior-period delta.
     *
     * @param array $filters
     * @return array
     */
    private static function get_geography_summary($filters) {
        global $wpdb;

        $table = self::get_rollup_table_name();
        $options = Resource_Geography_Registry::get_active_geography_options();
        if (empty($options)) {
            return array();
        }

        $where = array('rollup_date BETWEEN %s AND %s', "geography_slug <> ''");
        $params = array($filters['start_date'], $filters['end_date']);

        if ($filters['segment'] !== 'all') {
            $where[] = 'segment = %s';
            $params[] = $filters['segment'];
        }

        if ($filters['channel'] !== 'all') {
            $where[] = 'channel = %s';
            $params[] = $filters['channel'];
        }

        if ($filters['geography'] !== 'all') {
            $where[] = 'geography_slug = %s';
            $params[] = $filters['geography'];
        }

        $sql = "SELECT
            geography_slug,
            COALESCE(SUM(search_count), 0) AS searches,
            COALESCE(SUM(zero_result_count), 0) AS zero_results,
            COALESCE(SUM(snapshot_sent_count), 0) AS snapshot_sent
            FROM $table
            WHERE " . implode(' AND ', $where) . "
            GROUP BY geography_slug
            ORDER BY searches DESC";

        $current_rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $current_rows = is_array($current_rows) ? $current_rows : array();

        $start_ts = strtotime($filters['start_date'] . ' 00:00:00');
        $end_ts = strtotime($filters['end_date'] . ' 00:00:00');
        $days = 30;
        if ($start_ts !== false && $end_ts !== false && $end_ts >= $start_ts) {
            $days = (int) floor(($end_ts - $start_ts) / DAY_IN_SECONDS) + 1;
        }

        $prior_end = gmdate('Y-m-d', strtotime($filters['start_date'] . ' -1 day'));
        $prior_start = gmdate('Y-m-d', strtotime($prior_end . ' -' . max(0, $days - 1) . ' days'));

        $prior_where = array('rollup_date BETWEEN %s AND %s', "geography_slug <> ''");
        $prior_params = array($prior_start, $prior_end);

        if ($filters['segment'] !== 'all') {
            $prior_where[] = 'segment = %s';
            $prior_params[] = $filters['segment'];
        }

        if ($filters['channel'] !== 'all') {
            $prior_where[] = 'channel = %s';
            $prior_params[] = $filters['channel'];
        }

        if ($filters['geography'] !== 'all') {
            $prior_where[] = 'geography_slug = %s';
            $prior_params[] = $filters['geography'];
        }

        $prior_sql = "SELECT geography_slug, COALESCE(SUM(search_count), 0) AS searches
            FROM $table
            WHERE " . implode(' AND ', $prior_where) . "
            GROUP BY geography_slug";

        $prior_rows = $wpdb->get_results($wpdb->prepare($prior_sql, $prior_params), ARRAY_A);
        $prior_map = array();
        if (is_array($prior_rows)) {
            foreach ($prior_rows as $row) {
                $slug = isset($row['geography_slug']) ? sanitize_key((string) $row['geography_slug']) : '';
                if ($slug === '') {
                    continue;
                }
                $prior_map[$slug] = (int) $row['searches'];
            }
        }

        $formatted = array();
        foreach ($current_rows as $row) {
            $slug = isset($row['geography_slug']) ? sanitize_key((string) $row['geography_slug']) : '';
            if ($slug === '') {
                continue;
            }

            $searches = (int) $row['searches'];
            $prior = isset($prior_map[$slug]) ? (int) $prior_map[$slug] : 0;
            $delta_pct = null;
            if ($prior > 0) {
                $delta_pct = round((($searches - $prior) / $prior) * 100, 1);
            }

            $formatted[] = array(
                'geography_slug' => $slug,
                'geography_label' => isset($options[$slug]) ? $options[$slug] : $slug,
                'searches' => $searches,
                'zero_results' => (int) $row['zero_results'],
                'snapshot_sent' => (int) $row['snapshot_sent'],
                'trend_delta_pct' => $delta_pct
            );
        }

        return $formatted;
    }

    /**
     * Recursive sanitization for analytics meta payloads.
     *
     * @param mixed $value
     * @param string $key
     * @return mixed
     */
    private static function sanitize_meta($value, $key = '') {
        $restricted_key_patterns = array('email', 'phone', 'neighbor_name', 'neighbor', 'contact_value');
        $key = sanitize_key((string) $key);

        foreach ($restricted_key_patterns as $pattern) {
            if ($key !== '' && strpos($key, $pattern) !== false) {
                return null;
            }
        }

        if (is_array($value)) {
            $clean = array();
            foreach ($value as $child_key => $child_value) {
                $sanitized_child = self::sanitize_meta($child_value, (string) $child_key);
                if ($sanitized_child === null || $sanitized_child === '') {
                    continue;
                }
                $clean[sanitize_key((string) $child_key)] = $sanitized_child;
            }
            return $clean;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = self::sanitize_query_text($value);
            return $value;
        }

        return null;
    }

    /**
     * Normalize arbitrary integer list.
     *
     * @param mixed $values
     * @return array
     */
    private static function sanitize_int_list($values) {
        $values = is_array($values) ? $values : array($values);
        $values = array_map('intval', $values);
        $values = array_values(array_filter($values, function($id) {
            return $id > 0;
        }));
        return array_values(array_unique($values));
    }
}
