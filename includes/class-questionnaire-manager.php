<?php
/**
 * Questionnaire Manager Class
 *
 * Handles CRUD operations for questionnaires
 */

class Questionnaire_Manager {

    /**
     * Get questionnaire by ID
     */
    public static function get_questionnaire($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaires';

        $questionnaire = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d AND status != 'deleted'", $id),
            ARRAY_A
        );

        return $questionnaire;
    }

    /**
     * Get questionnaire by slug
     */
    public static function get_questionnaire_by_slug($slug) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaires';

        $questionnaire = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE slug = %s AND status != 'deleted'", $slug),
            ARRAY_A
        );

        return $questionnaire;
    }

    /**
     * Get all questionnaires with optional filters
     */
    public static function get_all_questionnaires($filters = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaires';

        $where = array("status != 'deleted'");
        $where_values = array();

        if (isset($filters['status'])) {
            $where[] = "status = %s";
            $where_values[] = $filters['status'];
        }

        if (isset($filters['geography'])) {
            $where[] = "geography LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($filters['geography']) . '%';
        }

        $where_sql = implode(' AND ', $where);

        if (!empty($where_values)) {
            $sql = $wpdb->prepare("SELECT * FROM $table WHERE $where_sql ORDER BY sort_order ASC, name ASC", $where_values);
        } else {
            $sql = "SELECT * FROM $table WHERE $where_sql ORDER BY sort_order ASC, name ASC";
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Create new questionnaire
     */
    public static function create_questionnaire($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaires';

        $defaults = array(
            'name' => '',
            'slug' => '',
            'description' => '',
            'geography' => '',
            'status' => 'active',
            'start_question_id' => null,
            'sort_order' => 0,
            'created_by' => get_current_user_id(),
        );

        $data = wp_parse_args($data, $defaults);

        // Generate slug if not provided
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = sanitize_title($data['name']);
        }

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update questionnaire
     */
    public static function update_questionnaire($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaires';

        $data['updated_by'] = get_current_user_id();

        $result = $wpdb->update(
            $table,
            $data,
            array('id' => $id)
        );

        return $result !== false;
    }

    /**
     * Delete questionnaire (soft delete)
     */
    public static function delete_questionnaire($id) {
        return self::update_questionnaire($id, array('status' => 'deleted'));
    }
}
