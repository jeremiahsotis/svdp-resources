<?php
/**
 * Resources Manager Class
 * Handles CRUD operations and verification logic for resources
 */

class Resources_Manager {

    /**
     * Get a single resource by ID
     */
    public static function get_resource($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        $resource = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND status = 'active'",
            $id
        ), ARRAY_A);

        return $resource;
    }

    /**
     * Get a single resource with hours data
     */
    public static function get_resource_with_hours($id) {
        $resource = self::get_resource($id);

        if ($resource) {
            $resource['hours'] = Resource_Hours_Manager::get_hours($id);
        }

        return $resource;
    }

    /**
     * Get resources by explicit IDs in the same order.
     *
     * @param array $resource_ids
     * @param bool $include_inactive
     * @return array
     */
    public static function get_resources_by_ids($resource_ids, $include_inactive = false) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        $resource_ids = array_values(array_unique(array_filter(array_map('intval', (array) $resource_ids))));
        if (empty($resource_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($resource_ids), '%d'));
        $order_sql = implode(',', $resource_ids);
        $where_status = $include_inactive ? '' : "AND status = 'active'";

        $sql = "SELECT * FROM $table_name WHERE id IN ($placeholders) $where_status ORDER BY FIELD(id, $order_sql)";
        $query = $wpdb->prepare($sql, $resource_ids);
        $rows = $wpdb->get_results($query, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Get all resources with optional filtering
     */
    public static function get_all_resources($filters = array()) {
        $result = self::get_resources_paginated(
            array_merge(
                $filters,
                array(
                    'page' => 1,
                    'per_page' => 5000
                )
            )
        );

        return isset($result['items']) ? $result['items'] : array();
    }

    /**
     * Get paginated resources with total count.
     *
     * @param array $filters
     * @return array{items: array, total_count: int}
     */
    public static function get_resources_paginated($filters = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $per_page = isset($filters['per_page']) ? max(1, min(100, (int) $filters['per_page'])) : 25;
        $offset = ($page - 1) * $per_page;

        $cache_key = self::build_paged_cache_key($filters, $page, $per_page);
        if ($page === 1) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && isset($cached['items']) && isset($cached['total_count'])) {
                return $cached;
            }
        }

        $where_values = array();
        $where_clause = self::build_where_clause($filters, $where_values);

        $count_sql = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
        $count_query = !empty($where_values) ? $wpdb->prepare($count_sql, $where_values) : $count_sql;
        $total_count = (int) $wpdb->get_var($count_query);

        if ($total_count === 0) {
            return array('items' => array(), 'total_count' => 0);
        }

        $data_sql = "SELECT * FROM $table_name WHERE $where_clause ORDER BY is_svdp DESC, resource_name ASC LIMIT %d OFFSET %d";
        $data_values = array_merge($where_values, array($per_page, $offset));
        $data_query = $wpdb->prepare($data_sql, $data_values);
        $items = $wpdb->get_results($data_query, ARRAY_A);

        $result = array(
            'items' => $items ? $items : array(),
            'total_count' => $total_count
        );

        if ($page === 1) {
            set_transient($cache_key, $result, MINUTE_IN_SECONDS);
        }

        return $result;
    }

    /**
     * Build SQL where clause for resource queries.
     *
     * @param array $filters
     * @param array $where_values
     * @return string
     */
    private static function build_where_clause($filters, &$where_values) {
        global $wpdb;
        $where = array("status = 'active'");
        $where_values = array();

        if (!empty($filters['geography'])) {
            $geographies = is_array($filters['geography']) ? $filters['geography'] : array($filters['geography']);
            $geography_conditions = array();
            foreach ($geographies as $geo) {
                $geo = trim((string) $geo);
                if ($geo === '') {
                    continue;
                }
                $geography_conditions[] = "geography LIKE %s";
                $where_values[] = '%' . $wpdb->esc_like($geo) . '%';
            }
            if (!empty($geography_conditions)) {
                $where[] = '(' . implode(' OR ', $geography_conditions) . ')';
            }
        }

        if (!empty($filters['verification_status'])) {
            $where[] = "verification_status = %s";
            $where_values[] = sanitize_text_field($filters['verification_status']);
        }

        if (!empty($filters['service_area'])) {
            $service_area_values = is_array($filters['service_area']) ? $filters['service_area'] : array($filters['service_area']);
            $service_area_slugs = array();
            foreach ($service_area_values as $service_area_value) {
                $service_area_slug = Resource_Taxonomy::normalize_service_area_slug($service_area_value);
                if ($service_area_slug !== '') {
                    $service_area_slugs[$service_area_slug] = $service_area_slug;
                }
            }

            if (!empty($service_area_slugs)) {
                $service_area_conditions = array();
                foreach (array_values($service_area_slugs) as $service_area_slug) {
                    $service_area_conditions[] = "service_area = %s";
                    $where_values[] = $service_area_slug;
                }
                $where[] = '(' . implode(' OR ', $service_area_conditions) . ')';
            }
        }

        if (!empty($filters['provider_type'])) {
            $provider_values = is_array($filters['provider_type']) ? $filters['provider_type'] : array($filters['provider_type']);
            $provider_slugs = array();
            foreach ($provider_values as $provider_value) {
                $provider_slug = Resource_Taxonomy::normalize_provider_type_slug($provider_value);
                if ($provider_slug !== '') {
                    $provider_slugs[$provider_slug] = $provider_slug;
                }
            }

            if (!empty($provider_slugs)) {
                $provider_conditions = array();
                foreach (array_values($provider_slugs) as $provider_slug) {
                    $provider_conditions[] = "provider_type = %s";
                    $where_values[] = $provider_slug;
                }
                $where[] = '(' . implode(' OR ', $provider_conditions) . ')';
            }
        }

        if (!empty($filters['services_offered'])) {
            $services_slugs = Resource_Taxonomy::normalize_services_offered_slugs($filters['services_offered']);
            if (!empty($services_slugs)) {
                $services_conditions = array();
                foreach ($services_slugs as $slug) {
                    $services_conditions[] = "services_offered LIKE %s";
                    $where_values[] = '%|' . $wpdb->esc_like($slug) . '|%';
                }
                $where[] = '(' . implode(' OR ', $services_conditions) . ')';
            }
        }

        if (!empty($filters['population'])) {
            $population_filters = Resource_Taxonomy::normalize_population_filters($filters['population']);
            if (!empty($population_filters)) {
                $population_conditions = array();
                foreach ($population_filters as $population) {
                    $population_conditions[] = 'LOWER(target_population) LIKE %s';
                    $where_values[] = '%' . $wpdb->esc_like($population) . '%';
                }
                $where[] = '(' . implode(' OR ', $population_conditions) . ')';
            }
        }

        // Backward-compatible shortcode param behavior.
        if (!empty($filters['service_type'])) {
            $service_types = is_array($filters['service_type']) ? $filters['service_type'] : array($filters['service_type']);
            $service_conditions = array();

            foreach ($service_types as $service) {
                $service = trim((string) $service);
                if ($service === '') {
                    continue;
                }
                $service_slug = Resource_Taxonomy::normalize_slug($service);
                $service_conditions[] = "(service_area = %s OR services_offered LIKE %s OR primary_service_type LIKE %s OR secondary_service_type LIKE %s)";
                $where_values[] = $service_slug;
                $where_values[] = '%|' . $wpdb->esc_like($service_slug) . '|%';
                $where_values[] = '%' . $wpdb->esc_like($service) . '%';
                $where_values[] = '%' . $wpdb->esc_like($service) . '%';
            }

            if (!empty($service_conditions)) {
                $where[] = '(' . implode(' OR ', $service_conditions) . ')';
            }
        }

        if (!empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            if ($q !== '') {
                $like = '%' . $wpdb->esc_like($q) . '%';
                $search_fields = array(
                    'resource_name',
                    'organization',
                    'service_area',
                    'services_offered',
                    'provider_type',
                    'primary_service_type',
                    'secondary_service_type',
                    'target_population',
                    'what_they_provide',
                    'how_to_apply',
                    'notes_and_tips'
                );
                $search_conditions = array();
                foreach ($search_fields as $field) {
                    $search_conditions[] = "$field LIKE %s";
                    $where_values[] = $like;
                }
                $where[] = '(' . implode(' OR ', $search_conditions) . ')';
            }
        }

        if (!empty($filters['open_at'])) {
            $day = isset($filters['open_at']['day']) ? intval($filters['open_at']['day']) : null;
            $time = isset($filters['open_at']['time']) ? $filters['open_at']['time'] : null;
            $hour_type = isset($filters['open_at']['type']) ? $filters['open_at']['type'] : 'service';

            if ($day !== null && $time !== null) {
                $open_resource_ids = Resource_Hours_Manager::find_open_resources($day, $time, $hour_type, $filters);
                if (empty($open_resource_ids)) {
                    $where[] = '1 = 0';
                } else {
                    $ids_placeholder = implode(',', array_fill(0, count($open_resource_ids), '%d'));
                    $where[] = "id IN ($ids_placeholder)";
                    $where_values = array_merge($where_values, array_map('intval', $open_resource_ids));
                }
            }
        }

        return implode(' AND ', $where);
    }

    /**
     * Build cache key for page-1 pagination result.
     *
     * @param array $filters
     * @param int $page
     * @param int $per_page
     * @return string
     */
    private static function build_paged_cache_key($filters, $page, $per_page) {
        $services_offered = isset($filters['services_offered']) ? (array) $filters['services_offered'] : array();
        $services_offered = array_values(array_unique(array_map('strval', $services_offered)));
        sort($services_offered);

        $population = isset($filters['population']) ? (array) $filters['population'] : array();
        $population = array_values(array_unique(array_map('strval', $population)));
        sort($population);

        $service_area = isset($filters['service_area']) ? (array) $filters['service_area'] : array();
        $service_area = array_values(array_unique(array_map('strval', $service_area)));
        sort($service_area);

        $provider_type = isset($filters['provider_type']) ? (array) $filters['provider_type'] : array();
        $provider_type = array_values(array_unique(array_map('strval', $provider_type)));
        sort($provider_type);

        $service_type = isset($filters['service_type']) ? (array) $filters['service_type'] : array();
        $service_type = array_values(array_unique(array_map('strval', $service_type)));
        sort($service_type);

        $geography = isset($filters['geography']) ? (array) $filters['geography'] : array();
        $geography = array_values(array_unique(array_map('strval', $geography)));
        sort($geography);

        $cache_filters = array(
            'service_area' => $service_area,
            'services_offered' => $services_offered,
            'provider_type' => $provider_type,
            'population' => $population,
            'q' => isset($filters['q']) ? $filters['q'] : '',
            'geography' => $geography,
            'service_type' => $service_type,
            'verification_status' => isset($filters['verification_status']) ? (string) $filters['verification_status'] : '',
            'open_at' => isset($filters['open_at']) ? $filters['open_at'] : array()
        );

        return 'svdp_res_paged_' . md5(wp_json_encode(array($cache_filters, (int) $page, (int) $per_page)));
    }

    /**
     * Create a new resource
     */
    public static function create_resource($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        // Set default values
        $defaults = array(
            'last_verified_date' => current_time('mysql'),
            'last_verified_by' => get_current_user_id(),
            'verification_status' => 'fresh',
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id(),
            'status' => 'active'
        );

        $data = wp_parse_args($data, $defaults);
        $data = self::normalize_taxonomy_fields_in_data($data);

        // Insert the resource
        $result = $wpdb->insert($table_name, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update an existing resource
     */
    public static function update_resource($id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        // Add update metadata
        $data['updated_at'] = current_time('mysql');
        $data['updated_by'] = get_current_user_id();
        $data = self::normalize_taxonomy_fields_in_data($data);

        $result = $wpdb->update(
            $table_name,
            $data,
            array('id' => $id),
            null,
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Delete a resource (soft delete by changing status)
     */
    public static function delete_resource($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        $result = $wpdb->update(
            $table_name,
            array(
                'status' => 'deleted',
                'updated_at' => current_time('mysql'),
                'updated_by' => get_current_user_id()
            ),
            array('id' => $id),
            array('%s', '%s', '%d'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Calculate verification status based on last verified date
     * Fresh: < 12 months
     * Aging: 12-18 months
     * Stale: > 18 months
     */
    public static function calculate_verification_status($last_verified_date) {
        if (empty($last_verified_date)) {
            return 'unverified';
        }

        $verified_timestamp = strtotime($last_verified_date);
        $current_timestamp = current_time('timestamp');
        $months_elapsed = ($current_timestamp - $verified_timestamp) / (30 * 24 * 60 * 60);

        if ($months_elapsed < 12) {
            return 'fresh';
        } elseif ($months_elapsed < 18) {
            return 'aging';
        } else {
            return 'stale';
        }
    }

    /**
     * Get resources needing verification (older than X days)
     */
    public static function get_resources_needing_verification($days_threshold = 365) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        $threshold_date = date('Y-m-d H:i:s', strtotime("-$days_threshold days"));

        $query = $wpdb->prepare(
            "SELECT * FROM $table_name
            WHERE status = 'active'
            AND (last_verified_date IS NULL OR last_verified_date < %s)
            ORDER BY last_verified_date ASC",
            $threshold_date
        );

        $resources = $wpdb->get_results($query, ARRAY_A);

        return $resources ? $resources : array();
    }

    /**
     * Verify a resource (full verification with checklist)
     */
    public static function verify_resource($id, $user_id, $checklist, $notes) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';
        $history_table = $wpdb->prefix . 'resource_verification_history';

        // Update resource verification info
        $result = $wpdb->update(
            $table_name,
            array(
                'last_verified_date' => current_time('mysql'),
                'last_verified_by' => $user_id,
                'verification_status' => 'fresh',
                'verification_notes' => $notes,
                'verification_checklist' => json_encode($checklist),
                'updated_at' => current_time('mysql'),
                'updated_by' => $user_id
            ),
            array('id' => $id),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%d'),
            array('%d')
        );

        // Add to verification history
        if ($result !== false) {
            $wpdb->insert(
                $history_table,
                array(
                    'resource_id' => $id,
                    'verified_date' => current_time('mysql'),
                    'verified_by' => $user_id,
                    'checklist_data' => json_encode($checklist),
                    'notes' => $notes,
                    'verification_type' => 'full',
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%s', '%d', '%s', '%s', '%s', '%s')
            );
        }

        return $result !== false;
    }

    /**
     * Record a verification attempt (tried to verify but couldn't reach)
     */
    public static function record_verification_attempt($id, $user_id, $notes) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';
        $history_table = $wpdb->prefix . 'resource_verification_history';

        // Update attempt info (but don't change last_verified_date)
        $result = $wpdb->update(
            $table_name,
            array(
                'verification_attempt_date' => current_time('mysql'),
                'verification_attempt_notes' => $notes,
                'updated_at' => current_time('mysql'),
                'updated_by' => $user_id
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%d'),
            array('%d')
        );

        // Add to verification history as attempt
        if ($result !== false) {
            $wpdb->insert(
                $history_table,
                array(
                    'resource_id' => $id,
                    'verified_date' => current_time('mysql'),
                    'verified_by' => $user_id,
                    'checklist_data' => null,
                    'notes' => $notes,
                    'verification_type' => 'attempt',
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%s', '%d', '%s', '%s', '%s', '%s')
            );
        }

        return $result !== false;
    }

    /**
     * Get verification history for a resource
     */
    public static function get_verification_history($resource_id, $limit = 10) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'resource_verification_history';

        $query = $wpdb->prepare(
            "SELECT h.*, u.display_name as verified_by_name
            FROM $history_table h
            LEFT JOIN {$wpdb->users} u ON h.verified_by = u.ID
            WHERE h.resource_id = %d
            ORDER BY h.verified_date DESC
            LIMIT %d",
            $resource_id,
            $limit
        );

        $history = $wpdb->get_results($query, ARRAY_A);

        return $history ? $history : array();
    }

    /**
     * Get verification statistics
     */
    public static function get_verification_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        $stats = array(
            'fresh' => 0,
            'aging' => 0,
            'stale' => 0,
            'unverified' => 0,
            'total' => 0
        );

        $query = "SELECT verification_status, COUNT(*) as count
                  FROM $table_name
                  WHERE status = 'active'
                  GROUP BY verification_status";

        $results = $wpdb->get_results($query, ARRAY_A);

        foreach ($results as $row) {
            $status = $row['verification_status'];
            $count = intval($row['count']);
            if (isset($stats[$status])) {
                $stats[$status] = $count;
                $stats['total'] += $count;
            }
        }

        return $stats;
    }

    /**
     * Update all verification statuses (run daily via cron)
     */
    public static function update_all_verification_statuses() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        // Get all active resources
        $resources = $wpdb->get_results(
            "SELECT id, last_verified_date, verification_status
            FROM $table_name
            WHERE status = 'active'",
            ARRAY_A
        );

        $updated_count = 0;

        foreach ($resources as $resource) {
            $new_status = self::calculate_verification_status($resource['last_verified_date']);

            // Only update if status changed
            if ($new_status !== $resource['verification_status']) {
                $wpdb->update(
                    $table_name,
                    array('verification_status' => $new_status),
                    array('id' => $resource['id']),
                    array('%s'),
                    array('%d')
                );
                $updated_count++;
            }
        }

        return $updated_count;
    }

    /**
     * Format column value (moved from Monday_Resources_API)
     * Still needed for formatting phone numbers, emails, URLs
     */
    public static function format_column_value($value) {
        if (empty($value)) {
            return '';
        }

        // Convert phone numbers to clickable links and format them
        // Handles both 10-digit (XXX-XXX-XXXX) and 11-digit (1-XXX-XXX-XXXX) formats
        $value = preg_replace_callback(
            '/\b1?[.-]?(\d{3})[.-]?(\d{3})[.-]?(\d{4})\b|\b\(?1?\)?[.-]?\s?\((\d{3})\)\s*(\d{3})[.-]?(\d{4})\b/',
            function($matches) {
                // Extract digits
                if (!empty($matches[1])) {
                    $area = $matches[1];
                    $prefix = $matches[2];
                    $line = $matches[3];
                } else {
                    $area = $matches[4];
                    $prefix = $matches[5];
                    $line = $matches[6];
                }

                $clean = $area . $prefix . $line;
                $formatted = '(' . $area . ') ' . $prefix . '-' . $line;

                return '<a href="tel:+1' . esc_attr($clean) . '">' . esc_html($formatted) . '</a>';
            },
            $value
        );

        // Convert email addresses to clickable links
        $value = preg_replace_callback(
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            function($matches) {
                $email = $matches[0];
                return '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
            },
            $value
        );

        // Convert URLs to clickable links
        $value = preg_replace_callback(
            '/\b(?:https?:\/\/|www\.)[^\s<]+/i',
            function($matches) {
                $url = $matches[0];
                $href = (strpos($url, 'www.') === 0) ? 'http://' . $url : $url;
                $href = rtrim($href, '.,;:!?)');

                return '<a href="' . esc_url($href) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>';
            },
            $value
        );

        return $value;
    }

    /**
     * Format text field as bulleted list
     * Converts line-separated items into HTML list
     * 
     * @param string $value The text value with items on separate lines
     * @return string Formatted HTML list or original text
     */
    public static function format_as_list($value) {
        if (empty($value)) {
            return '';
        }
    
        // Split by newlines and filter empty lines
        $lines = array_filter(
            array_map('trim', explode("\n", $value)),
            function($line) {
                return !empty($line);
            }
        );
    
        // If only one line, return as plain text (no list needed)
        if (count($lines) === 1) {
            return esc_html($lines[0]);
        }
    
        // If multiple lines, create bulleted list
        if (count($lines) > 1) {
            $html = '<ul style="margin: 8px 0 0 0; padding-left: 20px; line-height: 1.6;">';
            foreach ($lines as $line) {
                // Check if line starts with a bullet point, dash, or asterisk
                $cleaned_line = preg_replace('/^[\-\*•]\s*/', '', $line);
                $html .= '<li style="margin: 4px 0;">' . esc_html($cleaned_line) . '</li>';
            }
            $html .= '</ul>';
            return $html;
        }
    
        return esc_html($value);
    }

    /**
     * Normalize taxonomy fields before write.
     *
     * @param array $data
     * @return array
     */
    private static function normalize_taxonomy_fields_in_data($data) {
        if (!is_array($data)) {
            return $data;
        }

        if (array_key_exists('service_area', $data)) {
            $data['service_area'] = Resource_Taxonomy::normalize_service_area_slug($data['service_area']);
        }

        if (array_key_exists('provider_type', $data)) {
            $data['provider_type'] = Resource_Taxonomy::normalize_provider_type_slug($data['provider_type']);
        }

        if (array_key_exists('services_offered', $data)) {
            if (is_array($data['services_offered'])) {
                $slugs = Resource_Taxonomy::normalize_services_offered_slugs($data['services_offered']);
                $data['services_offered'] = Resource_Taxonomy::to_pipe_slug_string($slugs);
            } else {
                $value = trim((string) $data['services_offered']);
                if (strpos($value, '|') !== false) {
                    $slugs = Resource_Taxonomy::normalize_services_offered_slugs(Resource_Taxonomy::parse_pipe_slugs($value));
                } else {
                    $slugs = Resource_Taxonomy::normalize_services_offered_slugs(array_filter(array_map('trim', preg_split('/\s*,\s*|\s*;\s*/', $value))));
                }
                $data['services_offered'] = Resource_Taxonomy::to_pipe_slug_string($slugs);
            }
        }

        return $data;
    }
}
