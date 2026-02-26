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
     * @param array $hours_data Array containing office_flags, service_flags, office_hours, service_hours
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
            // Support legacy 'flags' format for backward compatibility
            $office_flags = isset($hours_data['office_flags']) ? $hours_data['office_flags'] :
                           (isset($hours_data['flags']) ? $hours_data['flags'] : array());
            $service_flags = isset($hours_data['service_flags']) ? $hours_data['service_flags'] : array();

            // Update special flags in resources table
            $wpdb->update(
                $resources_table,
                array(
                    // Office flags (existing columns)
                    'hours_24_7' => isset($office_flags['is_24_7']) ? (int)$office_flags['is_24_7'] : 0,
                    'hours_by_appointment' => isset($office_flags['is_by_appointment']) ? (int)$office_flags['is_by_appointment'] : 0,
                    'hours_call_for_availability' => isset($office_flags['is_call_for_availability']) ? (int)$office_flags['is_call_for_availability'] : 0,
                    'hours_currently_closed' => isset($office_flags['is_currently_closed']) ? (int)$office_flags['is_currently_closed'] : 0,
                    'hours_special_notes' => isset($office_flags['special_notes']) ? sanitize_textarea_field($office_flags['special_notes']) :
                                            (isset($hours_data['special_notes']) ? sanitize_textarea_field($hours_data['special_notes']) : null),

                    // Service flags (new columns)
                    'service_hours_24_7' => isset($service_flags['is_24_7']) ? (int)$service_flags['is_24_7'] : 0,
                    'service_hours_by_appointment' => isset($service_flags['is_by_appointment']) ? (int)$service_flags['is_by_appointment'] : 0,
                    'service_hours_call_for_availability' => isset($service_flags['is_call_for_availability']) ? (int)$service_flags['is_call_for_availability'] : 0,
                    'service_hours_currently_closed' => isset($service_flags['is_currently_closed']) ? (int)$service_flags['is_currently_closed'] : 0,
                    'service_hours_special_notes' => isset($service_flags['special_notes']) ? sanitize_textarea_field($service_flags['special_notes']) : null,

                    // Sync flag
                    'service_same_as_office' => isset($hours_data['service_same_as_office']) ? (int)$hours_data['service_same_as_office'] : 0
                ),
                array('id' => $resource_id),
                array('%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%d'),
                array('%d')
            );

            // Delete existing hours for this resource
            $wpdb->delete($hours_table, array('resource_id' => $resource_id), array('%d'));

            // Insert office hours
            if (!empty($hours_data['office_hours'])) {
                self::insert_hours_blocks($resource_id, self::HOUR_TYPE_OFFICE, $hours_data['office_hours']);
            }

            // Insert service hours
            if (!empty($hours_data['service_hours'])) {
                self::insert_hours_blocks($resource_id, self::HOUR_TYPE_SERVICE, $hours_data['service_hours']);
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
     * Insert hours blocks with support for multiple blocks and recurring patterns
     *
     * @param int $resource_id The resource ID
     * @param string $hour_type 'office' or 'service'
     * @param array $days_data Array of day => day_data
     */
    private static function insert_hours_blocks($resource_id, $hour_type, $days_data) {
        global $wpdb;
        $hours_table = $wpdb->prefix . 'resource_hours';

        foreach ($days_data as $day => $day_data) {
            // Support both old simple format and new mode-based format
            $mode = isset($day_data['mode']) ? $day_data['mode'] : 'simple';

            // Handle backward compatibility - old format is treated as 'simple' mode
            if (!isset($day_data['mode'])) {
                if (isset($day_data['is_closed']) && $day_data['is_closed']) {
                    $mode = 'closed';
                } elseif (isset($day_data['open_time']) && isset($day_data['close_time'])) {
                    $mode = 'simple';
                }
            }

            if ($mode === 'closed') {
                // Mark day as closed
                $wpdb->insert(
                    $hours_table,
                    array(
                        'resource_id' => $resource_id,
                        'hour_type' => $hour_type,
                        'day_of_week' => $day,
                        'is_closed' => 1,
                        'open_time' => null,
                        'close_time' => null,
                        'recurrence_pattern' => 'weekly',
                        'recurrence_interval' => 1,
                        'sort_order' => 0
                    ),
                    array('%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d')
                );

            } elseif ($mode === 'simple') {
                // Single time block (backward compatible)
                $simple_data = isset($day_data['simple']) ? $day_data['simple'] : $day_data;

                if (!empty($simple_data['open']) && !empty($simple_data['close'])) {
                    $wpdb->insert(
                        $hours_table,
                        array(
                            'resource_id' => $resource_id,
                            'hour_type' => $hour_type,
                            'day_of_week' => $day,
                            'is_closed' => 0,
                            'open_time' => $simple_data['open'],
                            'close_time' => $simple_data['close'],
                            'recurrence_pattern' => 'weekly',
                            'recurrence_interval' => 1,
                            'sort_order' => 0
                        ),
                        array('%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d')
                    );
                } elseif (!empty($simple_data['open_time']) && !empty($simple_data['close_time'])) {
                    // Support old format with '_time' suffix
                    $wpdb->insert(
                        $hours_table,
                        array(
                            'resource_id' => $resource_id,
                            'hour_type' => $hour_type,
                            'day_of_week' => $day,
                            'is_closed' => 0,
                            'open_time' => $simple_data['open_time'],
                            'close_time' => $simple_data['close_time'],
                            'recurrence_pattern' => 'weekly',
                            'recurrence_interval' => 1,
                            'sort_order' => 0
                        ),
                        array('%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d')
                    );
                }

            } elseif ($mode === 'multiple') {
                // Multiple time blocks for same day
                $blocks = isset($day_data['blocks']) ? $day_data['blocks'] : array();

                foreach ($blocks as $index => $block) {
                    if (!empty($block['open']) && !empty($block['close'])) {
                        $wpdb->insert(
                            $hours_table,
                            array(
                                'resource_id' => $resource_id,
                                'hour_type' => $hour_type,
                                'day_of_week' => $day,
                                'is_closed' => 0,
                                'open_time' => $block['open'],
                                'close_time' => $block['close'],
                                'recurrence_pattern' => 'weekly',
                                'recurrence_interval' => 1,
                                'block_label' => isset($block['label']) ? sanitize_text_field($block['label']) : null,
                                'sort_order' => $index
                            ),
                            array('%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d')
                        );
                    }
                }

            } elseif ($mode === 'recurring') {
                // Recurring pattern (biweekly, monthly, etc.)
                $recurring = isset($day_data['recurring']) ? $day_data['recurring'] : array();

                if (!empty($recurring['pattern']) && !empty($recurring['open']) && !empty($recurring['close'])) {
                    $data = array(
                        'resource_id' => $resource_id,
                        'hour_type' => $hour_type,
                        'day_of_week' => $recurring['pattern'] === 'monthly_date' ? null : $day,
                        'is_closed' => 0,
                        'open_time' => $recurring['open'],
                        'close_time' => $recurring['close'],
                        'recurrence_pattern' => $recurring['pattern'],
                        'recurrence_interval' => isset($recurring['interval']) ? (int)$recurring['interval'] : 1,
                        'recurrence_week_of_month' => isset($recurring['week']) ? (int)$recurring['week'] : null,
                        'recurrence_day_of_month' => isset($recurring['day_of_month']) ? (int)$recurring['day_of_month'] : null,
                        'sort_order' => 0
                    );

                    $formats = array('%d', '%s', $data['day_of_week'] === null ? '%s' : '%d', '%d', '%s', '%s', '%s', '%d',
                                   $data['recurrence_week_of_month'] === null ? '%s' : '%d',
                                   $data['recurrence_day_of_month'] === null ? '%s' : '%d', '%d');

                    $wpdb->insert($hours_table, $data, $formats);
                }
            }
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

        // Get special flags (both office and service)
        $resource = $wpdb->get_row($wpdb->prepare(
            "SELECT hours_24_7, hours_by_appointment, hours_call_for_availability,
                    hours_currently_closed, hours_special_notes,
                    service_hours_24_7, service_hours_by_appointment,
                    service_hours_call_for_availability, service_hours_currently_closed,
                    service_hours_special_notes, service_same_as_office
             FROM $resources_table WHERE id = %d",
            $resource_id
        ), ARRAY_A);

        if (!$resource) {
            return null;
        }

        // Get hours records with all new columns
        $hours_records = $wpdb->get_results($wpdb->prepare(
            "SELECT hour_type, day_of_week, is_closed, open_time, close_time,
                    recurrence_pattern, recurrence_interval, recurrence_week_of_month,
                    recurrence_day_of_month, block_label, sort_order
             FROM $hours_table WHERE resource_id = %d
             ORDER BY hour_type, day_of_week, sort_order",
            $resource_id
        ), ARRAY_A);

        // Build structured response with separate flags
        $hours_data = array(
            'office_flags' => array(
                'is_24_7' => (bool)$resource['hours_24_7'],
                'is_by_appointment' => (bool)$resource['hours_by_appointment'],
                'is_call_for_availability' => (bool)$resource['hours_call_for_availability'],
                'is_currently_closed' => (bool)$resource['hours_currently_closed'],
                'special_notes' => $resource['hours_special_notes']
            ),
            'service_flags' => array(
                'is_24_7' => (bool)$resource['service_hours_24_7'],
                'is_by_appointment' => (bool)$resource['service_hours_by_appointment'],
                'is_call_for_availability' => (bool)$resource['service_hours_call_for_availability'],
                'is_currently_closed' => (bool)$resource['service_hours_currently_closed'],
                'special_notes' => $resource['service_hours_special_notes']
            ),
            // Legacy 'flags' for backward compatibility (maps to office_flags)
            'flags' => array(
                'is_24_7' => (bool)$resource['hours_24_7'],
                'is_by_appointment' => (bool)$resource['hours_by_appointment'],
                'is_call_for_availability' => (bool)$resource['hours_call_for_availability'],
                'is_currently_closed' => (bool)$resource['hours_currently_closed']
            ),
            'special_notes' => $resource['hours_special_notes'], // Legacy
            'service_same_as_office' => (bool)$resource['service_same_as_office'],
            'office_hours' => array(),
            'service_hours' => array()
        );

        // Organize hours by type and day with mode detection
        foreach ($hours_records as $record) {
            $day = $record['day_of_week'] !== null ? (int)$record['day_of_week'] : null;
            $hour_type = $record['hour_type'];
            $is_closed = (bool)$record['is_closed'];

            // Determine target array
            $hours_key = $hour_type === self::HOUR_TYPE_OFFICE ? 'office_hours' : 'service_hours';

            if ($is_closed) {
                // Closed day
                if ($day !== null) {
                    $hours_data[$hours_key][$day] = array(
                        'mode' => 'closed',
                        'is_closed' => true
                    );
                }
            } else {
                $block_data = array(
                    'open' => $record['open_time'],
                    'close' => $record['close_time'],
                    'label' => $record['block_label']
                );

                // Detect mode based on recurrence pattern and whether there are multiple blocks
                if ($record['recurrence_pattern'] !== 'weekly') {
                    // Recurring pattern
                    if ($day !== null && !isset($hours_data[$hours_key][$day])) {
                        $hours_data[$hours_key][$day] = array(
                            'mode' => 'recurring',
                            'recurring' => array(
                                'pattern' => $record['recurrence_pattern'],
                                'interval' => (int)$record['recurrence_interval'],
                                'week' => $record['recurrence_week_of_month'] ? (int)$record['recurrence_week_of_month'] : null,
                                'day_of_month' => $record['recurrence_day_of_month'] ? (int)$record['recurrence_day_of_month'] : null,
                                'open' => $record['open_time'],
                                'close' => $record['close_time']
                            )
                        );
                    }
                } else {
                    // Weekly pattern - check if multiple blocks
                    if ($day !== null) {
                        if (!isset($hours_data[$hours_key][$day])) {
                            // First block for this day
                            $hours_data[$hours_key][$day] = array(
                                'mode' => 'simple',
                                'is_closed' => false,
                                'simple' => array(
                                    'open' => $record['open_time'],
                                    'close' => $record['close_time']
                                ),
                                // Also store in old format for backward compatibility
                                'open_time' => $record['open_time'],
                                'close_time' => $record['close_time']
                            );
                        } else {
                            // Additional block - convert to multiple mode
                            if ($hours_data[$hours_key][$day]['mode'] === 'simple') {
                                // Convert from simple to multiple
                                $first_block = array(
                                    'open' => $hours_data[$hours_key][$day]['simple']['open'],
                                    'close' => $hours_data[$hours_key][$day]['simple']['close'],
                                    'label' => null
                                );
                                $hours_data[$hours_key][$day] = array(
                                    'mode' => 'multiple',
                                    'is_closed' => false,
                                    'blocks' => array($first_block, $block_data)
                                );
                            } elseif ($hours_data[$hours_key][$day]['mode'] === 'multiple') {
                                // Add to existing blocks
                                $hours_data[$hours_key][$day]['blocks'][] = $block_data;
                            }
                        }
                    }
                }
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
     * @param string|null $date Optional date in Y-m-d format (for recurring pattern checking)
     * @return bool True if open, false if closed
     */
    public static function is_open_at($resource_id, $day_of_week, $time, $hour_type = 'service', $date = null) {
        $hours_data = self::get_hours($resource_id);

        if (!$hours_data) {
            return false;
        }

        // Check appropriate flags based on hour_type
        $flags_key = ($hour_type === self::HOUR_TYPE_OFFICE) ? 'office_flags' : 'service_flags';

        // Check for 24/7
        if ($hours_data[$flags_key]['is_24_7']) {
            return true;
        }

        // Check closure flags
        if ($hours_data[$flags_key]['is_currently_closed'] ||
            $hours_data[$flags_key]['is_by_appointment'] ||
            $hours_data[$flags_key]['is_call_for_availability']) {
            return false;
        }

        // Check specific day/time
        $hours_key = $hour_type === self::HOUR_TYPE_OFFICE ? 'office_hours' : 'service_hours';

        if (empty($hours_data[$hours_key][$day_of_week])) {
            return false;
        }

        $day_hours = $hours_data[$hours_key][$day_of_week];

        if (isset($day_hours['is_closed']) && $day_hours['is_closed']) {
            return false;
        }

        $mode = isset($day_hours['mode']) ? $day_hours['mode'] : 'simple';
        $check_time = strtotime($time);

        if ($mode === 'multiple') {
            // Check if time falls within any block
            foreach ($day_hours['blocks'] as $block) {
                $open_time = strtotime($block['open']);
                $close_time = strtotime($block['close']);
                if ($check_time >= $open_time && $check_time <= $close_time) {
                    return true;
                }
            }
            return false;

        } elseif ($mode === 'recurring') {
            // Check if date matches the recurrence pattern
            if ($date === null) {
                $date = date('Y-m-d');
            }

            $recurring = $day_hours['recurring'];
            if (!self::date_matches_recurrence($date, $day_of_week, $recurring)) {
                return false;
            }

            // Check time
            $open_time = strtotime($recurring['open']);
            $close_time = strtotime($recurring['close']);
            return $check_time >= $open_time && $check_time <= $close_time;

        } else {
            // Simple mode
            $open_time_str = isset($day_hours['simple']) ? $day_hours['simple']['open'] : $day_hours['open_time'];
            $close_time_str = isset($day_hours['simple']) ? $day_hours['simple']['close'] : $day_hours['close_time'];

            $open_time = strtotime($open_time_str);
            $close_time = strtotime($close_time_str);

            return $check_time >= $open_time && $check_time <= $close_time;
        }
    }

    /**
     * Check if a date matches a recurrence pattern
     *
     * @param string $date Date in Y-m-d format
     * @param int $day_of_week Expected day of week (0-6)
     * @param array $recurring Recurring pattern data
     * @return bool True if date matches pattern
     */
    private static function date_matches_recurrence($date, $day_of_week, $recurring) {
        $pattern = $recurring['pattern'];
        $timestamp = strtotime($date);
        $actual_day = (int)date('w', $timestamp);

        switch ($pattern) {
            case 'weekly':
                return $actual_day === $day_of_week;

            case 'biweekly':
                // For biweekly, we'd need a reference date - simplified for now
                // Assumes alternating weeks based on week number
                if ($actual_day !== $day_of_week) {
                    return false;
                }
                $week_number = (int)date('W', $timestamp);
                return ($week_number % 2) === 0;

            case 'monthly_week':
                if ($actual_day !== $day_of_week) {
                    return false;
                }
                $week = isset($recurring['week']) ? (int)$recurring['week'] : 1;
                $actual_week = self::get_week_of_month($date);
                return $actual_week === $week;

            case 'monthly_date':
                $day_of_month = isset($recurring['day_of_month']) ? (int)$recurring['day_of_month'] : 1;
                $actual_day_of_month = (int)date('j', $timestamp);
                return $actual_day_of_month === $day_of_month;

            default:
                return false;
        }
    }

    /**
     * Get the week of the month for a given date (1-5, where 5 = last)
     *
     * @param string $date Date in Y-m-d format
     * @return int Week of month (1-5)
     */
    private static function get_week_of_month($date) {
        $timestamp = strtotime($date);
        $day_of_month = (int)date('j', $timestamp);
        $day_of_week = (int)date('w', $timestamp);

        // Calculate which occurrence of this day of week in the month
        $first_of_month = strtotime(date('Y-m-01', $timestamp));
        $first_day_of_week = (int)date('w', $first_of_month);

        // Days until first occurrence of this day of week
        $days_until_first = ($day_of_week - $first_day_of_week + 7) % 7;
        $first_occurrence_day = 1 + $days_until_first;

        // Calculate which occurrence this is
        $week = floor(($day_of_month - $first_occurrence_day) / 7) + 1;

        // Check if this is the last occurrence
        $next_week_day = $day_of_month + 7;
        $last_day_of_month = (int)date('t', $timestamp);

        if ($next_week_day > $last_day_of_month) {
            return 5; // Last occurrence
        }

        return $week;
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
     * Now supports multiple blocks and recurring patterns
     */
    private static function format_compact($hours_array) {
        $grouped = array();
        $current_hours = null;
        $current_days = array();
        $output = array();

        for ($day = 0; $day <= 6; $day++) {
            $day_hours = isset($hours_array[$day]) ? $hours_array[$day] : null;

            if (!$day_hours || (isset($day_hours['is_closed']) && $day_hours['is_closed'])) {
                // Day is closed - flush current group
                if ($current_hours !== null) {
                    $grouped[] = array('days' => $current_days, 'hours' => $current_hours);
                    $current_hours = null;
                    $current_days = array();
                }
                continue;
            }

            $mode = isset($day_hours['mode']) ? $day_hours['mode'] : 'simple';

            if ($mode === 'multiple') {
                // Multiple blocks - can't group, output individually
                if ($current_hours !== null) {
                    $grouped[] = array('days' => $current_days, 'hours' => $current_hours);
                    $current_hours = null;
                    $current_days = array();
                }

                $blocks_text = array();
                foreach ($day_hours['blocks'] as $block) {
                    $open_formatted = date('g:i A', strtotime($block['open']));
                    $close_formatted = date('g:i A', strtotime($block['close']));
                    $time_str = "$open_formatted-$close_formatted";
                    if (!empty($block['label'])) {
                        $time_str .= " ({$block['label']})";
                    }
                    $blocks_text[] = $time_str;
                }
                $output[] = self::DAY_ABBREV[$day] . ': ' . implode(', ', $blocks_text);

            } elseif ($mode === 'recurring') {
                // Recurring pattern - can't group, output individually
                if ($current_hours !== null) {
                    $grouped[] = array('days' => $current_days, 'hours' => $current_hours);
                    $current_hours = null;
                    $current_days = array();
                }

                $recurring = $day_hours['recurring'];
                $pattern_desc = self::format_recurrence_pattern($recurring['pattern'], $recurring, $day);
                $open_formatted = date('g:i A', strtotime($recurring['open']));
                $close_formatted = date('g:i A', strtotime($recurring['close']));

                $output[] = "$pattern_desc: $open_formatted - $close_formatted";

            } else {
                // Simple mode - can be grouped
                $open_time = isset($day_hours['simple']) ? $day_hours['simple']['open'] : $day_hours['open_time'];
                $close_time = isset($day_hours['simple']) ? $day_hours['simple']['close'] : $day_hours['close_time'];
                $hours_key = $open_time . '-' . $close_time;

                if ($hours_key === $current_hours) {
                    $current_days[] = $day;
                } else {
                    if ($current_hours !== null) {
                        $grouped[] = array('days' => $current_days, 'hours' => $current_hours);
                    }
                    $current_hours = $hours_key;
                    $current_days = array($day);
                }
            }
        }

        // Flush last group
        if ($current_hours !== null) {
            $grouped[] = array('days' => $current_days, 'hours' => $current_hours);
        }

        // Format grouped simple hours
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
     * Now supports multiple blocks and recurring patterns
     */
    private static function format_full($hours_array) {
        $output = array();

        for ($day = 0; $day <= 6; $day++) {
            $day_name = self::DAY_NAMES[$day];
            $day_hours = isset($hours_array[$day]) ? $hours_array[$day] : null;

            if (!$day_hours || (isset($day_hours['is_closed']) && $day_hours['is_closed'])) {
                $output[] = "<strong>$day_name:</strong> Closed";
                continue;
            }

            $mode = isset($day_hours['mode']) ? $day_hours['mode'] : 'simple';

            if ($mode === 'multiple') {
                // Multiple blocks
                $blocks_text = array();
                foreach ($day_hours['blocks'] as $block) {
                    $open = date('g:i A', strtotime($block['open']));
                    $close = date('g:i A', strtotime($block['close']));
                    $time_str = "$open - $close";
                    if (!empty($block['label'])) {
                        $time_str .= " ({$block['label']})";
                    }
                    $blocks_text[] = $time_str;
                }
                $output[] = "<strong>$day_name:</strong> " . implode(', ', $blocks_text);

            } elseif ($mode === 'recurring') {
                // Recurring pattern
                $recurring = $day_hours['recurring'];
                $pattern_desc = self::format_recurrence_pattern($recurring['pattern'], $recurring, $day);
                $open = date('g:i A', strtotime($recurring['open']));
                $close = date('g:i A', strtotime($recurring['close']));
                $output[] = "<strong>$pattern_desc:</strong> $open - $close";

            } else {
                // Simple mode
                $open_time = isset($day_hours['simple']) ? $day_hours['simple']['open'] : $day_hours['open_time'];
                $close_time = isset($day_hours['simple']) ? $day_hours['simple']['close'] : $day_hours['close_time'];
                $open = date('g:i A', strtotime($open_time));
                $close = date('g:i A', strtotime($close_time));
                $output[] = "<strong>$day_name:</strong> $open - $close";
            }
        }

        return implode('<br>', $output);
    }

    /**
     * Format recurrence pattern for display
     *
     * @param string $pattern Pattern type (biweekly, monthly_week, monthly_date)
     * @param array $recurring_data Recurrence data
     * @param int $day Day of week
     * @return string Formatted pattern description
     */
    private static function format_recurrence_pattern($pattern, $recurring_data, $day) {
        switch ($pattern) {
            case 'biweekly':
                return 'Every Other ' . self::DAY_NAMES[$day];

            case 'monthly_week':
                $week = isset($recurring_data['week']) ? (int)$recurring_data['week'] : 1;
                $week_labels = array(1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => 'Last');
                $week_str = isset($week_labels[$week]) ? $week_labels[$week] : $week . 'th';
                return $week_str . ' ' . self::DAY_NAMES[$day];

            case 'monthly_date':
                $day_of_month = isset($recurring_data['day_of_month']) ? (int)$recurring_data['day_of_month'] : 1;
                $suffix = self::get_ordinal_suffix($day_of_month);
                return $day_of_month . $suffix . ' of Month';

            default:
                return 'Weekly';
        }
    }

    /**
     * Get ordinal suffix for a number (st, nd, rd, th)
     */
    private static function get_ordinal_suffix($number) {
        $ends = array('th','st','nd','rd','th','th','th','th','th','th');
        if ((($number % 100) >= 11) && (($number % 100) <= 13)) {
            return 'th';
        }
        return $ends[$number % 10];
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
