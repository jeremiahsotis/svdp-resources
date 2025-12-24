<?php
/**
 * Resource Hours Manager Class
 * Handles all operations related to resource hours of operation
 */

class Resource_Hours_Manager {

    // Day constants
    const DAY_SUNDAY = 0;
    const DAY_MONDAY = 1;
    const DAY_TUESDAY = 2;
    const DAY_WEDNESDAY = 3;
    const DAY_THURSDAY = 4;
    const DAY_FRIDAY = 5;
    const DAY_SATURDAY = 6;

    // Hour type constants
    const HOUR_TYPE_OFFICE = 'office';
    const HOUR_TYPE_SERVICE = 'service';

    // Day names
    const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday'
    ];

    const DAY_ABBREV = [
        0 => 'Sun',
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat'
    ];

    /**
     * Save hours for a resource
     *
     * @param int $resource_id The resource ID
     * @param array $hours_data Array containing flags, office_hours, service_hours
     * @return bool Success status
     */
    public static function save_hours($resource_id, $hours_data) {
        global $wpdb;

        // Validate hours data
        $errors = self::validate_hours($hours_data);
        if (!empty($errors)) {
            return false;
        }

        $resources_table = $wpdb->prefix . 'resources';
        $hours_table = $wpdb->prefix . 'resource_hours';

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Update special flags in resources table
            $wpdb->update(
                $resources_table,
                array(
                    'hours_24_7' => isset($hours_data['flags']['is_24_7']) ? (int)$hours_data['flags']['is_24_7'] : 0,
                    'hours_by_appointment' => isset($hours_data['flags']['is_by_appointment']) ? (int)$hours_data['flags']['is_by_appointment'] : 0,
                    'hours_call_for_availability' => isset($hours_data['flags']['is_call_for_availability']) ? (int)$hours_data['flags']['is_call_for_availability'] : 0,
                    'hours_currently_closed' => isset($hours_data['flags']['is_currently_closed']) ? (int)$hours_data['flags']['is_currently_closed'] : 0,
                    'hours_special_notes' => isset($hours_data['special_notes']) ? sanitize_textarea_field($hours_data['special_notes']) : null,
                    'service_same_as_office' => isset($hours_data['service_same_as_office']) ? (int)$hours_data['service_same_as_office'] : 0
                ),
                array('id' => $resource_id),
                array('%d', '%d', '%d', '%d', '%s', '%d'),
                array('%d')
            );

            // Delete existing hours for this resource
            $wpdb->delete($hours_table, array('resource_id' => $resource_id), array('%d'));

            // Insert office hours
            if (!empty($hours_data['office_hours'])) {
                foreach ($hours_data['office_hours'] as $day => $hours) {
                    if (!isset($hours['is_closed']) || !$hours['is_closed']) {
                        if (!empty($hours['open_time']) && !empty($hours['close_time'])) {
                            $wpdb->insert(
                                $hours_table,
                                array(
                                    'resource_id' => $resource_id,
                                    'hour_type' => self::HOUR_TYPE_OFFICE,
                                    'day_of_week' => $day,
                                    'is_closed' => 0,
                                    'open_time' => $hours['open_time'],
                                    'close_time' => $hours['close_time']
                                ),
                                array('%d', '%s', '%d', '%d', '%s', '%s')
                            );
                        }
                    } else {
                        // Mark day as closed
                        $wpdb->insert(
                            $hours_table,
                            array(
                                'resource_id' => $resource_id,
                                'hour_type' => self::HOUR_TYPE_OFFICE,
                                'day_of_week' => $day,
                                'is_closed' => 1,
                                'open_time' => null,
                                'close_time' => null
                            ),
                            array('%d', '%s', '%d', '%d', '%s', '%s')
                        );
                    }
                }
            }

            // Insert service hours
            if (!empty($hours_data['service_hours'])) {
                foreach ($hours_data['service_hours'] as $day => $hours) {
                    if (!isset($hours['is_closed']) || !$hours['is_closed']) {
                        if (!empty($hours['open_time']) && !empty($hours['close_time'])) {
                            $wpdb->insert(
                                $hours_table,
                                array(
                                    'resource_id' => $resource_id,
                                    'hour_type' => self::HOUR_TYPE_SERVICE,
                                    'day_of_week' => $day,
                                    'is_closed' => 0,
                                    'open_time' => $hours['open_time'],
                                    'close_time' => $hours['close_time']
                                ),
                                array('%d', '%s', '%d', '%d', '%s', '%s')
                            );
                        }
                    } else {
                        // Mark day as closed
                        $wpdb->insert(
                            $hours_table,
                            array(
                                'resource_id' => $resource_id,
                                'hour_type' => self::HOUR_TYPE_SERVICE,
                                'day_of_week' => $day,
                                'is_closed' => 1,
                                'open_time' => null,
                                'close_time' => null
                            ),
                            array('%d', '%s', '%d', '%d', '%s', '%s')
                        );
                    }
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');
            return true;

        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * Get hours for a resource
     *
     * @param int $resource_id The resource ID
     * @return array Structured hours data with office and service hours
     */
    public static function get_hours($resource_id) {
        global $wpdb;

        $resources_table = $wpdb->prefix . 'resources';
        $hours_table = $wpdb->prefix . 'resource_hours';

        // Get special flags
        $resource = $wpdb->get_row($wpdb->prepare(
            "SELECT hours_24_7, hours_by_appointment, hours_call_for_availability,
                    hours_currently_closed, hours_special_notes, service_same_as_office
             FROM $resources_table WHERE id = %d",
            $resource_id
        ), ARRAY_A);

        if (!$resource) {
            return null;
        }

        // Get hours records
        $hours_records = $wpdb->get_results($wpdb->prepare(
            "SELECT hour_type, day_of_week, is_closed, open_time, close_time
             FROM $hours_table WHERE resource_id = %d
             ORDER BY hour_type, day_of_week",
            $resource_id
        ), ARRAY_A);

        // Build structured response
        $hours_data = array(
            'flags' => array(
                'is_24_7' => (bool)$resource['hours_24_7'],
                'is_by_appointment' => (bool)$resource['hours_by_appointment'],
                'is_call_for_availability' => (bool)$resource['hours_call_for_availability'],
                'is_currently_closed' => (bool)$resource['hours_currently_closed']
            ),
            'special_notes' => $resource['hours_special_notes'],
            'service_same_as_office' => (bool)$resource['service_same_as_office'],
            'office_hours' => array(),
            'service_hours' => array()
        );

        // Organize hours by type and day
        foreach ($hours_records as $record) {
            $day = (int)$record['day_of_week'];
            $is_closed = (bool)$record['is_closed'];

            $day_data = array(
                'is_closed' => $is_closed,
                'open_time' => $is_closed ? null : $record['open_time'],
                'close_time' => $is_closed ? null : $record['close_time']
            );

            if ($record['hour_type'] === self::HOUR_TYPE_OFFICE) {
                $hours_data['office_hours'][$day] = $day_data;
            } else {
                $hours_data['service_hours'][$day] = $day_data;
            }
        }

        return $hours_data;
    }

    /**
     * Check if a resource is open at specific day/time
     *
     * @param int $resource_id The resource ID
     * @param int $day_of_week 0-6 (Sunday-Saturday)
     * @param string $time Time in HH:MM format (24-hour)
     * @param string $hour_type 'office' or 'service'
     * @return bool True if open, false if closed
     */
    public static function is_open_at($resource_id, $day_of_week, $time, $hour_type = 'service') {
        $hours_data = self::get_hours($resource_id);

        if (!$hours_data) {
            return false;
        }

        // Check special flags
        if ($hours_data['flags']['is_24_7']) {
            return true;
        }

        if ($hours_data['flags']['is_currently_closed'] ||
            $hours_data['flags']['is_by_appointment'] ||
            $hours_data['flags']['is_call_for_availability']) {
            return false;
        }

        // Check specific day/time
        $hours_key = $hour_type === self::HOUR_TYPE_OFFICE ? 'office_hours' : 'service_hours';

        if (empty($hours_data[$hours_key][$day_of_week])) {
            return false;
        }

        $day_hours = $hours_data[$hours_key][$day_of_week];

        if ($day_hours['is_closed']) {
            return false;
        }

        // Convert times to comparable format
        $check_time = strtotime($time);
        $open_time = strtotime($day_hours['open_time']);
        $close_time = strtotime($day_hours['close_time']);

        return $check_time >= $open_time && $check_time <= $close_time;
    }

    /**
     * Find all resources open at specific day/time
     *
     * @param int $day_of_week 0-6 (Sunday-Saturday)
     * @param string $time Time in HH:MM format (24-hour)
     * @param string $hour_type 'office' or 'service'
     * @param array $filters Optional filters (e.g., ['service_type' => 'Food Assistance'])
     * @return array Array of resource IDs that are open
     */
    public static function find_open_resources($day_of_week, $time, $hour_type = 'service', $filters = array()) {
        global $wpdb;

        $resources_table = $wpdb->prefix . 'resources';
        $hours_table = $wpdb->prefix . 'resource_hours';

        // Build query
        $where_clauses = array("r.status = 'active'");

        // Add service type filter if provided
        if (!empty($filters['service_type'])) {
            $where_clauses[] = $wpdb->prepare(
                "(r.primary_service_type = %s OR r.secondary_service_type LIKE %s)",
                $filters['service_type'],
                '%' . $wpdb->esc_like($filters['service_type']) . '%'
            );
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Find resources that are 24/7
        $query_24_7 = "SELECT r.id FROM $resources_table r WHERE $where_sql AND r.hours_24_7 = 1";

        // Find resources with specific hours
        $query_hours = $wpdb->prepare(
            "SELECT DISTINCT r.id FROM $resources_table r
             INNER JOIN $hours_table h ON r.id = h.resource_id
             WHERE $where_sql
             AND h.hour_type = %s
             AND h.day_of_week = %d
             AND h.is_closed = 0
             AND h.open_time <= %s
             AND h.close_time >= %s",
            $hour_type,
            $day_of_week,
            $time,
            $time
        );

        // Combine results
        $query = "($query_24_7) UNION ($query_hours)";

        $results = $wpdb->get_col($query);

        return array_map('intval', $results);
    }

    /**
     * Get current day/time open status for a resource
     *
     * @param int $resource_id The resource ID
     * @param string $hour_type 'office' or 'service'
     * @return array ['is_open' => true/false, 'next_open' => 'Mon 9:00 AM', 'closes_at' => '5:00 PM']
     */
    public static function get_current_status($resource_id, $hour_type = 'service') {
        $current_day = (int)date('w'); // 0 = Sunday, 6 = Saturday
        $current_time = date('H:i:s');

        $is_open = self::is_open_at($resource_id, $current_day, $current_time, $hour_type);

        $status = array(
            'is_open' => $is_open,
            'next_open' => null,
            'closes_at' => null
        );

        $hours_data = self::get_hours($resource_id);

        if (!$hours_data) {
            return $status;
        }

        // If 24/7, always open
        if ($hours_data['flags']['is_24_7']) {
            $status['is_open'] = true;
            $status['closes_at'] = 'Never (24/7)';
            return $status;
        }

        $hours_key = $hour_type === self::HOUR_TYPE_OFFICE ? 'office_hours' : 'service_hours';

        if ($is_open && !empty($hours_data[$hours_key][$current_day])) {
            $close_time = $hours_data[$hours_key][$current_day]['close_time'];
            $status['closes_at'] = date('g:i A', strtotime($close_time));
        } else {
            // Find next opening
            for ($i = 1; $i <= 7; $i++) {
                $next_day = ($current_day + $i) % 7;
                if (!empty($hours_data[$hours_key][$next_day]) && !$hours_data[$hours_key][$next_day]['is_closed']) {
                    $day_name = self::DAY_NAMES[$next_day];
                    $open_time = $hours_data[$hours_key][$next_day]['open_time'];
                    $status['next_open'] = $day_name . ' ' . date('g:i A', strtotime($open_time));
                    break;
                }
            }
        }

        return $status;
    }

    /**
     * Format hours for display
     *
     * @param array $hours_array Array of day => hours data
     * @param string $format 'full', 'compact', or 'list'
     * @return string Formatted HTML string
     */
    public static function format_hours_display($hours_array, $format = 'compact') {
        if (empty($hours_array)) {
            return '';
        }

        if ($format === 'compact') {
            return self::format_compact($hours_array);
        } elseif ($format === 'list') {
            return self::format_list($hours_array);
        } else {
            return self::format_full($hours_array);
        }
    }

    /**
     * Format hours in compact format (Mon-Fri: 9:00 AM - 5:00 PM)
     */
    private static function format_compact($hours_array) {
        $grouped = array();
        $current_hours = null;
        $current_days = array();

        for ($day = 0; $day <= 6; $day++) {
            $day_hours = isset($hours_array[$day]) ? $hours_array[$day] : null;

            if ($day_hours && !$day_hours['is_closed']) {
                $hours_key = $day_hours['open_time'] . '-' . $day_hours['close_time'];

                if ($hours_key === $current_hours) {
                    $current_days[] = $day;
                } else {
                    if ($current_hours !== null) {
                        $grouped[] = array('days' => $current_days, 'hours' => $current_hours);
                    }
                    $current_hours = $hours_key;
                    $current_days = array($day);
                }
            } else {
                if ($current_hours !== null) {
                    $grouped[] = array('days' => $current_days, 'hours' => $current_hours);
                    $current_hours = null;
                    $current_days = array();
                }
            }
        }

        if ($current_hours !== null) {
            $grouped[] = array('days' => $current_days, 'hours' => $current_hours);
        }

        $output = array();
        foreach ($grouped as $group) {
            $days = $group['days'];
            list($open, $close) = explode('-', $group['hours']);

            if (count($days) === 1) {
                $day_str = self::DAY_ABBREV[$days[0]];
            } elseif (self::are_consecutive($days)) {
                $day_str = self::DAY_ABBREV[$days[0]] . '-' . self::DAY_ABBREV[end($days)];
            } else {
                $day_str = implode(', ', array_map(function($d) {
                    return self::DAY_ABBREV[$d];
                }, $days));
            }

            $open_formatted = date('g:i A', strtotime($open));
            $close_formatted = date('g:i A', strtotime($close));

            $output[] = "$day_str: $open_formatted - $close_formatted";
        }

        return implode('<br>', $output);
    }

    /**
     * Format hours in full format
     */
    private static function format_full($hours_array) {
        $output = array();

        for ($day = 0; $day <= 6; $day++) {
            $day_name = self::DAY_NAMES[$day];
            $day_hours = isset($hours_array[$day]) ? $hours_array[$day] : null;

            if ($day_hours && !$day_hours['is_closed']) {
                $open = date('g:i A', strtotime($day_hours['open_time']));
                $close = date('g:i A', strtotime($day_hours['close_time']));
                $output[] = "<strong>$day_name:</strong> $open - $close";
            } else {
                $output[] = "<strong>$day_name:</strong> Closed";
            }
        }

        return implode('<br>', $output);
    }

    /**
     * Format hours as list
     */
    private static function format_list($hours_array) {
        return '<ul><li>' . str_replace('<br>', '</li><li>', self::format_full($hours_array)) . '</li></ul>';
    }

    /**
     * Check if array of days is consecutive
     */
    private static function are_consecutive($days) {
        if (count($days) <= 1) {
            return false;
        }

        for ($i = 1; $i < count($days); $i++) {
            if ($days[$i] !== $days[$i-1] + 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate hours data structure
     *
     * @param array $hours_data Hours data to validate
     * @return array Array of validation errors (empty if valid)
     */
    public static function validate_hours($hours_data) {
        $errors = array();

        // Validate office hours
        if (!empty($hours_data['office_hours'])) {
            foreach ($hours_data['office_hours'] as $day => $hours) {
                if ($day < 0 || $day > 6) {
                    $errors[] = "Invalid day of week: $day";
                }

                if (!isset($hours['is_closed']) || !$hours['is_closed']) {
                    if (!empty($hours['open_time']) && !empty($hours['close_time'])) {
                        // Validate time format
                        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $hours['open_time'])) {
                            $errors[] = "Invalid open time format for " . self::DAY_NAMES[$day];
                        }
                        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $hours['close_time'])) {
                            $errors[] = "Invalid close time format for " . self::DAY_NAMES[$day];
                        }

                        // Check that open time is before close time
                        if (strtotime($hours['open_time']) >= strtotime($hours['close_time'])) {
                            $errors[] = "Open time must be before close time for " . self::DAY_NAMES[$day];
                        }
                    }
                }
            }
        }

        // Validate service hours (same logic)
        if (!empty($hours_data['service_hours'])) {
            foreach ($hours_data['service_hours'] as $day => $hours) {
                if ($day < 0 || $day > 6) {
                    $errors[] = "Invalid day of week: $day";
                }

                if (!isset($hours['is_closed']) || !$hours['is_closed']) {
                    if (!empty($hours['open_time']) && !empty($hours['close_time'])) {
                        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $hours['open_time'])) {
                            $errors[] = "Invalid service open time format for " . self::DAY_NAMES[$day];
                        }
                        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $hours['close_time'])) {
                            $errors[] = "Invalid service close time format for " . self::DAY_NAMES[$day];
                        }

                        if (strtotime($hours['open_time']) >= strtotime($hours['close_time'])) {
                            $errors[] = "Service open time must be before close time for " . self::DAY_NAMES[$day];
                        }
                    }
                }
            }
        }

        return $errors;
    }
}
