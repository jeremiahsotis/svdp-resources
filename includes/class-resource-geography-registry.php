<?php
/**
 * Geography registry and shortcode discovery service for analytics.
 */

class Resource_Geography_Registry {

    const DISCOVERY_CRON_HOOK = 'svdp_resources_analytics_geography_discovery';

    /**
     * Wire cron hooks.
     */
    public function __construct() {
        add_action('wp', array($this, 'maybe_schedule_discovery'));
        add_action(self::DISCOVERY_CRON_HOOK, array(__CLASS__, 'run_discovery'));
    }

    /**
     * Geography registry table name.
     *
     * @return string
     */
    public static function get_registry_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'svdpr_geography_registry';
    }

    /**
     * Shortcode geography map table name.
     *
     * @return string
     */
    public static function get_map_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'svdpr_shortcode_geography_map';
    }

    /**
     * Initial registry seed labels.
     *
     * @return array
     */
    public static function get_seed_labels() {
        return array(
            'Cathedral',
            'Huntington',
            'Our Lady',
            'Queen of Angels',
            'Sacred Heart - Warsaw',
            'St Charles',
            'St Elizabeth',
            'St Francis',
            'St Gaspar',
            'St John the Baptist - New Haven',
            'St Patrick',
            'St Joseph',
            'St Jude',
            'St Louis',
            'St Martin',
            'St Mary - Avilla',
            'St Mary - Decatur',
            'St Mary - Fort Wayne',
            'St Paul',
            'St Peter',
            'St Therese',
            'St Vincent',
            'Entire Fort Wayne District',
            'All Fort Wayne Conferences'
        );
    }

    /**
     * Ensure seeded geographies exist.
     *
     * @return void
     */
    public static function ensure_seed_data() {
        $order = 10;
        foreach (self::get_seed_labels() as $label) {
            self::upsert_geography(
                $label,
                array(
                    'source_type' => 'seed',
                    'display_order' => $order,
                    'is_active' => 1,
                    'preserve_manual_source' => true
                )
            );
            $order += 10;
        }
    }

    /**
     * Enable/disable daily discovery cron.
     *
     * @return void
     */
    public function maybe_schedule_discovery() {
        self::sync_discovery_schedule();
    }

    /**
     * Schedule/unschedule discovery cron from current option state.
     *
     * @return void
     */
    public static function sync_discovery_schedule() {
        $enabled = (int) get_option('monday_resources_analytics_auto_discovery_enabled', 0) === 1;
        $hook = self::DISCOVERY_CRON_HOOK;

        if ($enabled) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $hook);
            }
            return;
        }

        $ts = wp_next_scheduled($hook);
        if ($ts) {
            wp_unschedule_event($ts, $hook);
        }
    }

    /**
     * Parse shortcode geography attributes from published content.
     *
     * @return array
     */
    public static function run_discovery() {
        global $wpdb;

        $posts_table = $wpdb->posts;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content
                FROM $posts_table
                WHERE post_status = %s
                    AND post_content LIKE %s",
                'publish',
                '%[monday_resources%'
            ),
            ARRAY_A
        );

        $result = array(
            'posts_scanned' => 0,
            'shortcodes_found' => 0,
            'geographies_discovered' => 0,
            'mappings_upserted' => 0
        );

        if (!is_array($rows) || empty($rows)) {
            update_option('monday_resources_analytics_last_discovery', array_merge($result, array('run_at' => current_time('mysql'))), false);
            return $result;
        }

        $result['posts_scanned'] = count($rows);
        $pattern = get_shortcode_regex(array('monday_resources'));
        $seen_pairs = array();

        foreach ($rows as $row) {
            $post_id = isset($row['ID']) ? (int) $row['ID'] : 0;
            if ($post_id <= 0) {
                continue;
            }

            $content = isset($row['post_content']) ? (string) $row['post_content'] : '';
            if ($content === '') {
                continue;
            }

            if (!preg_match_all('/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER)) {
                continue;
            }

            $path = self::canonicalize_path((string) wp_parse_url((string) get_permalink($post_id), PHP_URL_PATH));

            foreach ($matches as $match) {
                $result['shortcodes_found']++;
                $attr_text = isset($match[3]) ? (string) $match[3] : '';
                $atts = shortcode_parse_atts($attr_text);
                if (!is_array($atts) || empty($atts['geography'])) {
                    continue;
                }

                $shortcode_hash = hash('sha256', (string) $match[0]);
                $labels = self::split_geography_labels((string) $atts['geography']);
                if (empty($labels)) {
                    continue;
                }

                foreach ($labels as $label) {
                    $slug = self::normalize_slug($label);
                    if ($slug === '') {
                        continue;
                    }

                    $pair_key = $post_id . '|' . $shortcode_hash . '|' . $slug;
                    if (!isset($seen_pairs[$pair_key])) {
                        $seen_pairs[$pair_key] = true;
                    }

                    self::upsert_geography(
                        $label,
                        array(
                            'source_type' => 'shortcode_discovery',
                            'preserve_manual_source' => true
                        )
                    );
                    $result['geographies_discovered']++;

                    if (self::upsert_shortcode_mapping($post_id, $path, $shortcode_hash, $slug)) {
                        $result['mappings_upserted']++;
                    }
                }
            }
        }

        $result['run_at'] = current_time('mysql');
        update_option('monday_resources_analytics_last_discovery', $result, false);

        return $result;
    }

    /**
     * Split comma-separated geography labels.
     *
     * @param string $raw
     * @return array
     */
    public static function split_geography_labels($raw) {
        $parts = array_map('trim', explode(',', (string) $raw));
        $parts = array_values(array_filter($parts, function($value) {
            return $value !== '';
        }));

        return $parts;
    }

    /**
     * Canonical path normalization for mapping keys.
     *
     * @param string $path
     * @return string
     */
    public static function canonicalize_path($path) {
        $path = '/' . ltrim((string) $path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    /**
     * Normalize geography slug.
     *
     * @param string $label
     * @return string
     */
    public static function normalize_slug($label) {
        $slug = sanitize_title((string) $label);
        if ($slug === '') {
            $slug = sanitize_key((string) $label);
        }
        return (string) $slug;
    }

    /**
     * Add or update a registry row.
     *
     * @param string $label
     * @param array $args
     * @return array|null
     */
    public static function upsert_geography($label, $args = array()) {
        global $wpdb;

        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }

        $defaults = array(
            'source_type' => 'manual',
            'display_order' => null,
            'is_active' => 1,
            'preserve_manual_source' => false
        );
        $args = wp_parse_args($args, $defaults);

        $slug = self::normalize_slug($label);
        if ($slug === '') {
            return null;
        }

        $table = self::get_registry_table_name();
        $now = current_time('mysql');

        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE slug = %s LIMIT 1", $slug),
            ARRAY_A
        );

        if ($existing) {
            $source_type = isset($existing['source_type']) ? (string) $existing['source_type'] : 'manual';
            if ($args['preserve_manual_source'] && in_array($source_type, array('manual', 'seed'), true)) {
                $source_type = $source_type;
            } else {
                $source_type = sanitize_key((string) $args['source_type']);
                if ($source_type === '') {
                    $source_type = isset($existing['source_type']) ? (string) $existing['source_type'] : 'manual';
                }
            }

            $display_order = isset($args['display_order']) && $args['display_order'] !== null
                ? (int) $args['display_order']
                : (int) $existing['display_order'];

            $wpdb->update(
                $table,
                array(
                    'label' => $label,
                    'source_type' => $source_type,
                    'is_active' => (int) $args['is_active'] === 1 ? 1 : 0,
                    'display_order' => $display_order,
                    'updated_at' => $now
                ),
                array('id' => (int) $existing['id']),
                array('%s', '%s', '%d', '%d', '%s'),
                array('%d')
            );

            $existing['label'] = $label;
            $existing['source_type'] = $source_type;
            $existing['is_active'] = (int) $args['is_active'] === 1 ? 1 : 0;
            $existing['display_order'] = $display_order;
            $existing['updated_at'] = $now;
            return $existing;
        }

        $display_order = isset($args['display_order']) && $args['display_order'] !== null
            ? (int) $args['display_order']
            : ((int) $wpdb->get_var("SELECT COALESCE(MAX(display_order), 0) FROM $table") + 10);

        $source_type = sanitize_key((string) $args['source_type']);
        if ($source_type === '') {
            $source_type = 'manual';
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'slug' => $slug,
                'label' => $label,
                'is_active' => (int) $args['is_active'] === 1 ? 1 : 0,
                'display_order' => $display_order,
                'source_type' => $source_type,
                'created_at' => $now,
                'updated_at' => $now
            ),
            array('%s', '%s', '%d', '%d', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            return null;
        }

        return array(
            'id' => (int) $wpdb->insert_id,
            'slug' => $slug,
            'label' => $label,
            'is_active' => (int) $args['is_active'] === 1 ? 1 : 0,
            'display_order' => $display_order,
            'source_type' => $source_type,
            'created_at' => $now,
            'updated_at' => $now
        );
    }

    /**
     * Upsert shortcode mapping row.
     *
     * @param int $post_id
     * @param string $path
     * @param string $shortcode_hash
     * @param string $geography_slug
     * @return bool
     */
    public static function upsert_shortcode_mapping($post_id, $path, $shortcode_hash, $geography_slug) {
        global $wpdb;

        $post_id = (int) $post_id;
        if ($post_id <= 0 || $shortcode_hash === '' || $geography_slug === '') {
            return false;
        }

        $path = self::canonicalize_path($path);
        $table = self::get_map_table_name();
        $now = current_time('mysql');

        $sql = "INSERT INTO $table
            (post_id, path, shortcode_hash, geography_slug, last_seen_at)
            VALUES (%d, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                path = VALUES(path),
                last_seen_at = VALUES(last_seen_at)";

        $result = $wpdb->query(
            $wpdb->prepare(
                $sql,
                $post_id,
                $path,
                $shortcode_hash,
                $geography_slug,
                $now
            )
        );

        return $result !== false;
    }

    /**
     * Resolve mapped geography slugs for a source path.
     *
     * @param string $path
     * @return array
     */
    public static function get_geographies_for_path($path) {
        global $wpdb;

        $path = self::canonicalize_path($path);
        if ($path === '') {
            return array();
        }

        $table = self::get_map_table_name();
        $registry_table = self::get_registry_table_name();

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT m.geography_slug
                FROM $table m
                INNER JOIN $registry_table r ON r.slug = m.geography_slug
                WHERE m.path = %s",
                $path
            )
        );

        if (!is_array($rows)) {
            return array();
        }

        return array_values(array_unique(array_map('sanitize_key', $rows)));
    }

    /**
     * Normalize arbitrary labels/slugs to active registry slugs where possible.
     *
     * @param array $values
     * @return array
     */
    public static function normalize_input_to_slugs($values) {
        if (!is_array($values) || empty($values)) {
            return array();
        }

        $slugs = array();
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            if (preg_match('/^[a-z0-9\-]+$/', $value)) {
                $slugs[] = sanitize_key($value);
                continue;
            }

            $slugs[] = self::normalize_slug($value);
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    /**
     * Registry rows for admin table.
     *
     * @return array
     */
    public static function get_registry_rows() {
        global $wpdb;
        $table = self::get_registry_table_name();
        $rows = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY is_active DESC, display_order ASC, label ASC",
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    /**
     * Active geography options as slug => label.
     *
     * @return array
     */
    public static function get_active_geography_options() {
        global $wpdb;
        $table = self::get_registry_table_name();
        $rows = $wpdb->get_results(
            "SELECT slug, label
            FROM $table
            WHERE is_active = 1
            ORDER BY display_order ASC, label ASC",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

        $options = array();
        foreach ($rows as $row) {
            $slug = isset($row['slug']) ? sanitize_key((string) $row['slug']) : '';
            $label = isset($row['label']) ? (string) $row['label'] : '';
            if ($slug === '' || $label === '') {
                continue;
            }
            $options[$slug] = $label;
        }

        return $options;
    }

    /**
     * Toggle active state.
     *
     * @param string $slug
     * @param bool $is_active
     * @return bool
     */
    public static function set_active($slug, $is_active) {
        global $wpdb;
        $table = self::get_registry_table_name();
        $updated = $wpdb->update(
            $table,
            array(
                'is_active' => $is_active ? 1 : 0,
                'updated_at' => current_time('mysql')
            ),
            array('slug' => sanitize_key((string) $slug)),
            array('%d', '%s'),
            array('%s')
        );

        return $updated !== false;
    }

    /**
     * Soft-remove a geography from active use while preserving historical joins.
     *
     * @param string $slug
     * @return bool
     */
    public static function soft_remove($slug) {
        global $wpdb;
        $table = self::get_registry_table_name();
        $updated = $wpdb->update(
            $table,
            array(
                'is_active' => 0,
                'source_type' => 'manual_removed',
                'updated_at' => current_time('mysql')
            ),
            array('slug' => sanitize_key((string) $slug)),
            array('%d', '%s', '%s'),
            array('%s')
        );

        return $updated !== false;
    }

    /**
     * Set explicit display order for a geography slug.
     *
     * @param string $slug
     * @param int $display_order
     * @return bool
     */
    public static function set_display_order($slug, $display_order) {
        global $wpdb;
        $table = self::get_registry_table_name();
        $updated = $wpdb->update(
            $table,
            array(
                'display_order' => max(0, (int) $display_order),
                'updated_at' => current_time('mysql')
            ),
            array('slug' => sanitize_key((string) $slug)),
            array('%d', '%s'),
            array('%s')
        );

        return $updated !== false;
    }

    /**
     * Reorder display order by provided slug sequence.
     *
     * @param array $slugs
     * @return void
     */
    public static function reorder($slugs) {
        global $wpdb;
        if (!is_array($slugs)) {
            return;
        }

        $table = self::get_registry_table_name();
        $order = 10;
        foreach ($slugs as $slug) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '') {
                continue;
            }

            $wpdb->update(
                $table,
                array(
                    'display_order' => $order,
                    'updated_at' => current_time('mysql')
                ),
                array('slug' => $slug),
                array('%d', '%s'),
                array('%s')
            );
            $order += 10;
        }
    }

    /**
     * Return a small source-path sample for a geography slug.
     *
     * @param string $slug
     * @param int $limit
     * @return array
     */
    public static function get_source_paths_for_slug($slug, $limit = 5) {
        global $wpdb;

        $slug = sanitize_key((string) $slug);
        $limit = max(1, min(25, (int) $limit));
        if ($slug === '') {
            return array();
        }

        $table = self::get_map_table_name();
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT path
                FROM $table
                WHERE geography_slug = %s
                ORDER BY last_seen_at DESC
                LIMIT %d",
                $slug,
                $limit
            )
        );

        return is_array($rows) ? $rows : array();
    }
}
