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

        if ($outcome['resource_filter_type'] === 'service_type') {
            // Apply Resource Type filter
            if (!empty($filter_data['resource_types'])) {
                $filters['primary_type'] = $filter_data['resource_types'];
            }

            // Apply Need Met filter
            if (!empty($filter_data['needs_met'])) {
                $filters['need_met'] = $filter_data['needs_met'];
            }

            // Apply Target Audience filter
            if (!empty($filter_data['target_audiences'])) {
                $filters['target_audience'] = $filter_data['target_audiences'];
            }

            // If no filters selected, return empty (or all resources - decide based on requirements)
            // For now, if no filters are selected, return empty to be safe
            if (empty($filter_data['resource_types']) && empty($filter_data['needs_met']) && empty($filter_data['target_audiences'])) {
                return array();
            }
        } elseif ($outcome['resource_filter_type'] === 'specific_resources' && !empty($filter_data['specific_ids'])) {
            // Get specific resources by ID
            return self::get_specific_resources($filter_data['specific_ids'], $conference);
        } elseif ($outcome['resource_filter_type'] === 'none') {
            return array();
        }

        // Use existing Resources_Manager to filter
        if (class_exists('Resources_Manager')) {
            $resources = Resources_Manager::get_all_resources($filters);

            // Apply Target Audience filter manually if needed (since Resources_Manager doesn't have this filter yet)
            if (!empty($filter_data['target_audiences']) && !empty($resources)) {
                $filtered_resources = array();
                foreach ($resources as $resource) {
                    $resource_target_pop = !empty($resource['target_population']) ? strtolower($resource['target_population']) : '';
                    $matches_audience = false;
                    foreach ($filter_data['target_audiences'] as $audience) {
                        if (stripos($resource_target_pop, strtolower($audience)) !== false) {
                            $matches_audience = true;
                            break;
                        }
                    }
                    if ($matches_audience) {
                        $filtered_resources[] = $resource;
                    }
                }
                return $filtered_resources;
            }

            return $resources;
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
