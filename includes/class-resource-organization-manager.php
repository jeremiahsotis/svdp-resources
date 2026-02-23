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
     * Search organizations for autocomplete and lookups.
     *
     * Results are sourced from organization entities first, with fallback to
     * distinct legacy resource.organization values when entities are sparse.
     *
     * @param string $query
     * @param int $limit
     * @return array<int, array<string,mixed>>
     */
    public static function search_organizations($query = '', $limit = 15) {
        global $wpdb;

        $query = trim((string) $query);
        $limit = max(1, min(50, (int) $limit));
        $results = array();
        $seen = array();

        $org_table = self::get_table_name();
        $org_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $org_table));
        if ($org_table_exists === $org_table) {
            if ($query === '') {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, name
                        FROM $org_table
                        ORDER BY updated_at DESC, name ASC
                        LIMIT %d",
                        $limit
                    ),
                    ARRAY_A
                );
            } else {
                $like = '%' . $wpdb->esc_like($query) . '%';
                $prefix_like = $wpdb->esc_like($query) . '%';
                $normalized_query = self::normalize_name($query);
                $normalized_like = '%' . $wpdb->esc_like($normalized_query) . '%';

                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, name
                        FROM $org_table
                        WHERE name LIKE %s OR name_normalized LIKE %s
                        ORDER BY
                            CASE WHEN name LIKE %s THEN 0 ELSE 1 END,
                            updated_at DESC,
                            name ASC
                        LIMIT %d",
                        $like,
                        $normalized_like,
                        $prefix_like,
                        $limit
                    ),
                    ARRAY_A
                );
            }

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $name = isset($row['name']) ? trim((string) $row['name']) : '';
                    if ($name === '') {
                        continue;
                    }

                    $seen_key = strtolower($name);
                    if (isset($seen[$seen_key])) {
                        continue;
                    }

                    $seen[$seen_key] = true;
                    $results[] = array(
                        'id' => isset($row['id']) ? (int) $row['id'] : 0,
                        'name' => $name
                    );
                }
            }
        }

        if (count($results) >= $limit) {
            return array_slice($results, 0, $limit);
        }

        $resources_table = $wpdb->prefix . 'resources';
        $resources_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $resources_table));
        if ($resources_table_exists !== $resources_table) {
            return $results;
        }

        $remaining = max(1, $limit - count($results));
        if ($query === '') {
            $resource_rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT organization
                    FROM $resources_table
                    WHERE organization IS NOT NULL
                        AND TRIM(organization) <> ''
                    ORDER BY organization ASC
                    LIMIT %d",
                    $remaining
                )
            );
        } else {
            $resource_like = '%' . $wpdb->esc_like($query) . '%';
            $resource_rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT organization
                    FROM $resources_table
                    WHERE organization IS NOT NULL
                        AND TRIM(organization) <> ''
                        AND organization LIKE %s
                    ORDER BY organization ASC
                    LIMIT %d",
                    $resource_like,
                    $remaining
                )
            );
        }

        if (is_array($resource_rows)) {
            foreach ($resource_rows as $resource_name) {
                $name = trim((string) $resource_name);
                if ($name === '') {
                    continue;
                }

                $seen_key = strtolower($name);
                if (isset($seen[$seen_key])) {
                    continue;
                }

                $seen[$seen_key] = true;
                $results[] = array(
                    'id' => 0,
                    'name' => $name
                );

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
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
