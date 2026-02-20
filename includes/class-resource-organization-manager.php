<?php
/**
 * Organization entity manager with normalized uniqueness and close-match detection.
 */

class Resource_Organization_Manager {

    /**
     * Organization table name.
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'svdpr_organizations';
    }

    /**
     * Normalize organization name for uniqueness checks.
     *
     * @param string $name
     * @return string
     */
    public static function normalize_name($name) {
        $name = strtolower(trim((string) $name));
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/&/', ' and ', $name);
        $name = preg_replace('/[^a-z0-9\s]/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', (string) $name);
        return trim((string) $name);
    }

    /**
     * Create or reuse organization row and return ID.
     *
     * @param string $name
     * @return int
     */
    public static function upsert_organization($name) {
        global $wpdb;

        $name = trim((string) $name);
        if ($name === '') {
            return 0;
        }

        $table = self::get_table_name();
        $normalized = self::normalize_name($name);
        if ($normalized === '') {
            return 0;
        }

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE name_normalized = %s LIMIT 1",
                $normalized
            )
        );

        if ($existing_id > 0) {
            $wpdb->update(
                $table,
                array(
                    'name' => $name,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing_id),
                array('%s', '%s'),
                array('%d')
            );
            return $existing_id;
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'name' => $name,
                'name_normalized' => $normalized,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            $fallback_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM $table WHERE name_normalized = %s LIMIT 1",
                    $normalized
                )
            );
            return $fallback_id > 0 ? $fallback_id : 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Find close organization matches by Levenshtein distance.
     *
     * @param string $name
     * @param int $max_distance
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function find_close_matches($name, $max_distance = 3, $limit = 5) {
        global $wpdb;

        $name = trim((string) $name);
        if ($name === '') {
            return array();
        }

        $normalized = self::normalize_name($name);
        if ($normalized === '') {
            return array();
        }

        $table = self::get_table_name();
        $rows = $wpdb->get_results(
            "SELECT id, name, name_normalized FROM $table ORDER BY updated_at DESC LIMIT 1000",
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $matches = array();
        foreach ($rows as $row) {
            $candidate = isset($row['name_normalized']) ? (string) $row['name_normalized'] : '';
            if ($candidate === '' || $candidate === $normalized) {
                continue;
            }

            $distance = levenshtein($normalized, $candidate);
            if ($distance <= $max_distance) {
                $matches[] = array(
                    'id' => (int) $row['id'],
                    'name' => isset($row['name']) ? (string) $row['name'] : '',
                    'distance' => $distance
                );
            }
        }

        usort($matches, function($a, $b) {
            if ($a['distance'] === $b['distance']) {
                return strcmp((string) $a['name'], (string) $b['name']);
            }
            return $a['distance'] <=> $b['distance'];
        });

        return array_slice($matches, 0, max(1, (int) $limit));
    }
}
