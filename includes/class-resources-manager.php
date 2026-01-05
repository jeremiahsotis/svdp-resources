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
     * Get all resources with optional filtering
     */
    public static function get_all_resources($filters = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        $where = array("status = 'active'");
        $where_values = array();

        // Geography filter - now supports multiple values
        if (!empty($filters['geography'])) {
            // Handle both single string and array of strings
            $geographies = is_array($filters['geography']) ? $filters['geography'] : array($filters['geography']);
        
            if (!empty($geographies)) {
                $geography_conditions = array();
                foreach ($geographies as $geo) {
                    $geography_conditions[] = "geography LIKE %s";
                    $where_values[] = '%' . $wpdb->esc_like($geo) . '%';
                }
                // Use OR to match any of the geography values
                $where[] = '(' . implode(' OR ', $geography_conditions) . ')';
            }
        }
    
        // Primary type (Resource Type) filter - supports multiple values
        if (!empty($filters['primary_type'])) {
            // Handle both single string and array of strings
            $primary_types = is_array($filters['primary_type']) ? $filters['primary_type'] : array($filters['primary_type']);
        
            if (!empty($primary_types)) {
                $primary_conditions = array();
                foreach ($primary_types as $type) {
                    $primary_conditions[] = "primary_service_type LIKE %s";
                    $where_values[] = '%' . $wpdb->esc_like($type) . '%';
                }
                // Use OR to match any of the primary types
                $where[] = '(' . implode(' OR ', $primary_conditions) . ')';
            }
        }

        // Need Met filter - supports multiple values
        if (!empty($filters['need_met'])) {
            // Handle both single string and array of strings
            $needs_met = is_array($filters['need_met']) ? $filters['need_met'] : array($filters['need_met']);
        
            if (!empty($needs_met)) {
                $need_conditions = array();
                foreach ($needs_met as $need) {
                    $need_conditions[] = "secondary_service_type LIKE %s";
                    $where_values[] = '%' . $wpdb->esc_like($need) . '%';
                }
                // Use OR to match any of the needs met
                $where[] = '(' . implode(' OR ', $need_conditions) . ')';
            }
        }

        // Service type filter - backward compatibility (searches both primary and secondary)
        if (!empty($filters['service_type'])) {
            // Handle both single string and array of strings
            $service_types = is_array($filters['service_type']) ? $filters['service_type'] : array($filters['service_type']);
        
            if (!empty($service_types)) {
                $service_conditions = array();
                foreach ($service_types as $service) {
                    $service_conditions[] = "(primary_service_type LIKE %s OR secondary_service_type LIKE %s)";
                    $where_values[] = '%' . $wpdb->esc_like($service) . '%';
                    $where_values[] = '%' . $wpdb->esc_like($service) . '%';
                }
                // Use OR to match any of the service types
                $where[] = '(' . implode(' OR ', $service_conditions) . ')';
            }
        }

        // Verification status filter
        if (!empty($filters['verification_status'])) {
            $where[] = "verification_status = %s";
            $where_values[] = $filters['verification_status'];
        }

        // Hours filter - find resources open at specific day/time
        if (!empty($filters['open_at'])) {
            $day = isset($filters['open_at']['day']) ? intval($filters['open_at']['day']) : null;
            $time = isset($filters['open_at']['time']) ? $filters['open_at']['time'] : null;
            $hour_type = isset($filters['open_at']['type']) ? $filters['open_at']['type'] : 'service';

            if ($day !== null && $time !== null) {
                // Get resource IDs that are open at this time
                $open_resource_ids = Resource_Hours_Manager::find_open_resources($day, $time, $hour_type, $filters);

                if (!empty($open_resource_ids)) {
                    $ids_placeholder = implode(',', array_fill(0, count($open_resource_ids), '%d'));
                    $where[] = "id IN ($ids_placeholder)";
                    $where_values = array_merge($where_values, $open_resource_ids);
                } else {
                    // No resources open at this time - return empty
                    return array();
                }
            }
        }

        // Build the query
        $where_clause = implode(' AND ', $where);
        $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY is_svdp DESC, primary_service_type ASC, resource_name ASC";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        $resources = $wpdb->get_results($query, ARRAY_A);

        return $resources ? $resources : array();
    }

    /**
     * Search resources by name, organization, or service type
     * Optimized for AJAX autocomplete - returns only needed columns
     *
     * @param string $search_term Search query
     * @param int $limit Maximum results to return
     * @param int $offset Offset for pagination
     * @return array Array of matching resources
     */
    public static function search_resources($search_term = '', $limit = 20, $offset = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        $where = array("status = 'active'");
        $where_values = array();

        if (!empty($search_term)) {
            // Search across multiple fields
            $search_like = '%' . $wpdb->esc_like($search_term) . '%';
            $where[] = "(
                resource_name LIKE %s OR
                organization LIKE %s OR
                primary_service_type LIKE %s OR
                secondary_service_type LIKE %s OR
                target_population LIKE %s
            )";
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }

        $where_clause = implode(' AND ', $where);

        // Return only columns needed for search results display
        $query = "SELECT id, resource_name, organization, primary_service_type,
                  secondary_service_type, target_population
                  FROM $table_name
                  WHERE $where_clause
                  ORDER BY is_svdp DESC, resource_name ASC
                  LIMIT %d OFFSET %d";

        $where_values[] = $limit;
        $where_values[] = $offset;

        $query = $wpdb->prepare($query, $where_values);
        $resources = $wpdb->get_results($query, ARRAY_A);

        return $resources ? $resources : array();
    }

    /**
     * Count total search results (for pagination)
     *
     * @param string $search_term Search query
     * @return int Total matching resources
     */
    public static function count_search_results($search_term = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';

        $where = array("status = 'active'");
        $where_values = array();

        if (!empty($search_term)) {
            $search_like = '%' . $wpdb->esc_like($search_term) . '%';
            $where[] = "(
                resource_name LIKE %s OR
                organization LIKE %s OR
                primary_service_type LIKE %s OR
                secondary_service_type LIKE %s OR
                target_population LIKE %s
            )";
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }

        $where_clause = implode(' AND ', $where);
        $query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        return (int) $wpdb->get_var($query);
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
}
