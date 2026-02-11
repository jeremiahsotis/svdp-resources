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

        if ($outcome['resource_filter_type'] === 'specific_resources') {
            if (!empty($filter_data['specific_ids'])) {
                // Get specific resources by ID.
                return self::get_specific_resources($filter_data['specific_ids'], $conference);
            }
            return array();
        } elseif ($outcome['resource_filter_type'] === 'none') {
            return array();
        } elseif ($outcome['resource_filter_type'] !== 'service_type') {
            return array();
        }

        if ($outcome['resource_filter_type'] === 'service_type') {
            $effective_filter_count = 0;

            $service_areas = self::sanitize_service_areas_from_filter_data($filter_data);
            if (!empty($service_areas)) {
                $filters['service_area'] = $service_areas;
                $effective_filter_count++;
            }

            $services_offered = self::sanitize_services_offered_from_filter_data($filter_data);
            if (!empty($services_offered)) {
                $filters['services_offered'] = $services_offered;
                $effective_filter_count++;
            }

            $provider_types = self::sanitize_provider_types_from_filter_data($filter_data);
            if (!empty($provider_types)) {
                $filters['provider_type'] = $provider_types;
                $effective_filter_count++;
            }

            $target_populations = self::sanitize_target_populations_from_filter_data($filter_data);
            if (!empty($target_populations)) {
                $filters['population'] = $target_populations;
                $effective_filter_count++;
            }

            $legacy_service_types = self::sanitize_legacy_service_types_from_filter_data($filter_data);
            if (!empty($legacy_service_types)) {
                $filters['service_type'] = $legacy_service_types;
                $effective_filter_count++;
            }

            // Keep existing safety behavior: service-type outcomes with no selections return nothing.
            if ($effective_filter_count === 0) {
                return array();
            }
        }

        // Use existing Resources_Manager to filter.
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

    /**
     * Normalize outcome filter service areas, with backward compatibility.
     *
     * @param array $filter_data
     * @return array
     */
    private static function sanitize_service_areas_from_filter_data($filter_data) {
        if (!is_array($filter_data)) {
            return array();
        }

        $raw = array();
        if (!empty($filter_data['service_areas']) && is_array($filter_data['service_areas'])) {
            $raw = array_merge($raw, $filter_data['service_areas']);
        }

        // Backward compatibility: old resource_types and service_types can map into service areas.
        if (!empty($filter_data['resource_types']) && is_array($filter_data['resource_types'])) {
            $raw = array_merge($raw, $filter_data['resource_types']);
        }
        if (!empty($filter_data['service_types']) && is_array($filter_data['service_types'])) {
            $raw = array_merge($raw, $filter_data['service_types']);
        }

        $normalized = array();
        foreach ($raw as $value) {
            $slug = Resource_Taxonomy::normalize_service_area_slug($value);
            if ($slug !== '') {
                $normalized[$slug] = $slug;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize outcome filter services offered, with backward compatibility.
     *
     * @param array $filter_data
     * @return array
     */
    private static function sanitize_services_offered_from_filter_data($filter_data) {
        if (!is_array($filter_data)) {
            return array();
        }

        $raw = array();
        if (!empty($filter_data['services_offered']) && is_array($filter_data['services_offered'])) {
            $raw = array_merge($raw, $filter_data['services_offered']);
        }

        // Backward compatibility: old needs_met and service_types may map to services offered.
        if (!empty($filter_data['needs_met']) && is_array($filter_data['needs_met'])) {
            $raw = array_merge($raw, $filter_data['needs_met']);
        }
        if (!empty($filter_data['service_types']) && is_array($filter_data['service_types'])) {
            $raw = array_merge($raw, $filter_data['service_types']);
        }

        return Resource_Taxonomy::normalize_services_offered_slugs($raw);
    }

    /**
     * Normalize outcome filter provider types.
     *
     * @param array $filter_data
     * @return array
     */
    private static function sanitize_provider_types_from_filter_data($filter_data) {
        if (!is_array($filter_data)) {
            return array();
        }

        $raw = array();
        if (!empty($filter_data['provider_type'])) {
            $raw[] = $filter_data['provider_type'];
        }
        if (!empty($filter_data['provider_types']) && is_array($filter_data['provider_types'])) {
            $raw = array_merge($raw, $filter_data['provider_types']);
        }

        $normalized = array();
        foreach ($raw as $value) {
            $slug = Resource_Taxonomy::normalize_provider_type_slug($value);
            if ($slug !== '') {
                $normalized[$slug] = $slug;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize target-population filters, with backward compatibility.
     *
     * @param array $filter_data
     * @return array
     */
    private static function sanitize_target_populations_from_filter_data($filter_data) {
        if (!is_array($filter_data)) {
            return array();
        }

        $raw = array();
        if (!empty($filter_data['target_populations']) && is_array($filter_data['target_populations'])) {
            $raw = array_merge($raw, $filter_data['target_populations']);
        }
        if (!empty($filter_data['target_audiences']) && is_array($filter_data['target_audiences'])) {
            $raw = array_merge($raw, $filter_data['target_audiences']);
        }

        return Resource_Taxonomy::normalize_population_filters($raw);
    }

    /**
     * Preserve legacy service-type matching for older outcomes.
     *
     * @param array $filter_data
     * @return array
     */
    private static function sanitize_legacy_service_types_from_filter_data($filter_data) {
        if (!is_array($filter_data)) {
            return array();
        }

        $legacy = array();

        // Legacy fields that were free-text labels in old schema.
        $legacy_fields = array('service_types', 'resource_types', 'needs_met');
        foreach ($legacy_fields as $field) {
            if (empty($filter_data[$field]) || !is_array($filter_data[$field])) {
                continue;
            }

            foreach ($filter_data[$field] as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $legacy[$value] = $value;
                }
            }
        }

        return array_values($legacy);
    }
}
