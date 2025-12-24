<?php
/**
 * Outcome Manager Class
 *
 * Handles CRUD operations for questionnaire outcomes and resource filtering
 */

class Outcome_Manager {

    /**
     * Get outcome by ID
     */
    public static function get_outcome($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_outcomes';

        $outcome = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id),
            ARRAY_A
        );

        return $outcome;
    }

    /**
     * Get all outcomes for a questionnaire
     */
    public static function get_outcomes_for_questionnaire($questionnaire_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_outcomes';

        $outcomes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE questionnaire_id = %d ORDER BY sort_order ASC",
                $questionnaire_id
            ),
            ARRAY_A
        );

        return $outcomes;
    }

    /**
     * Create new outcome
     */
    public static function create_outcome($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_outcomes';

        $defaults = array(
            'questionnaire_id' => 0,
            'name' => '',
            'outcome_type' => 'resources',
            'guidance_text' => '',
            'resource_filter_type' => 'service_type',
            'resource_filter_data' => '',
            'sort_order' => 0,
        );

        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update outcome
     */
    public static function update_outcome($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_outcomes';

        $result = $wpdb->update(
            $table,
            $data,
            array('id' => $id)
        );

        return $result !== false;
    }

    /**
     * Delete outcome
     */
    public static function delete_outcome($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_outcomes';

        return $wpdb->delete($table, array('id' => $id)) !== false;
    }

    /**
     * Get resources for an outcome based on Conference
     */
    public static function get_resources_for_outcome($outcome_id, $conference) {
        $outcome = self::get_outcome($outcome_id);

        if (!$outcome || empty($outcome['resource_filter_type'])) {
            return array();
        }

        $filter_data = !empty($outcome['resource_filter_data']) ?
            json_decode($outcome['resource_filter_data'], true) : array();

        $filters = array('geography' => $conference);

        if ($outcome['resource_filter_type'] === 'service_type' && !empty($filter_data['service_types'])) {
            $filters['service_type'] = $filter_data['service_types'];
        } elseif ($outcome['resource_filter_type'] === 'specific_resources' && !empty($filter_data['specific_ids'])) {
            // Get specific resources by ID
            return self::get_specific_resources($filter_data['specific_ids'], $conference);
        } elseif ($outcome['resource_filter_type'] === 'none') {
            return array();
        }

        // Use existing Resources_Manager to filter
        if (class_exists('Resources_Manager')) {
            return Resources_Manager::get_all_resources($filters);
        }

        return array();
    }

    /**
     * Get specific resources by IDs, filtered by Conference
     */
    private static function get_specific_resources($resource_ids, $conference) {
        if (empty($resource_ids) || !is_array($resource_ids)) {
            return array();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'resources';

        $placeholders = implode(',', array_fill(0, count($resource_ids), '%d'));

        $sql = $wpdb->prepare(
            "SELECT * FROM $table
            WHERE id IN ($placeholders)
            AND status = 'active'
            AND (geography LIKE %s OR geography IS NULL)
            ORDER BY resource_name ASC",
            array_merge($resource_ids, array('%' . $wpdb->esc_like($conference) . '%'))
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }
}
