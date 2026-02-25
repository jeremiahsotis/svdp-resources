<?php
/**
 * Taxonomy-only import pipeline for resources taxonomy migration.
 */

class Resource_Taxonomy_Import {

    /**
     * Import taxonomy fields from spreadsheet rows.
     *
     * @param string $file_path
     * @param string $source_file_name
     * @param bool $apply_changes
     * @return array
     */
    public static function process_file($file_path, $source_file_name = '', $apply_changes = false) {
        $result = array(
            'success' => false,
            'message' => '',
            'import_run_id' => wp_generate_uuid4(),
            'source_file' => $source_file_name,
            'source_file_hash' => file_exists($file_path) ? hash_file('sha256', $file_path) : '',
            'stats' => array(
                'rows_total' => 0,
                'rows_with_id' => 0,
                'rows_valid' => 0,
                'rows_updated' => 0,
                'rows_unchanged' => 0,
                'duplicates_ignored' => 0,
                'rows_failed_validation' => 0,
                'rows_not_found' => 0,
                'review_queue_count' => 0
            ),
            'duplicates_ignored' => array(),
            'review_queue' => array()
        );

        if (!file_exists($file_path)) {
            $result['message'] = 'Import file not found.';
            return $result;
        }

        $rows = self::read_rows($file_path, $source_file_name);
        if (is_wp_error($rows)) {
            $result['message'] = $rows->get_error_message();
            return $result;
        }

        if (empty($rows)) {
            $result['message'] = 'No rows found in uploaded file.';
            return $result;
        }

        $result['stats']['rows_total'] = count($rows);
        $deduped_rows = self::dedupe_rows_by_id($rows, $result);
        $result['stats']['rows_with_id'] = count($deduped_rows);

        foreach ($deduped_rows as $resource_id => $row) {
            $validation = self::validate_row($row);
            if (!$validation['valid']) {
                $result['stats']['rows_failed_validation']++;
                $result['review_queue'][] = $validation['review_item'];
                continue;
            }

            $result['stats']['rows_valid']++;

            $existing = Resources_Manager::get_resource($resource_id);
            if (!$existing) {
                $result['stats']['rows_not_found']++;
                $result['review_queue'][] = array(
                    'resource_id' => $resource_id,
                    'row_number' => $row['_row_number'],
                    'reason' => 'resource_not_found',
                    'message' => 'Resource ID not found in database.',
                    'raw_row' => $row
                );
                continue;
            }

            $new_values = $validation['normalized'];
            $old_values = array(
                'service_area' => isset($existing['service_area']) ? (string) $existing['service_area'] : '',
                'services_offered' => isset($existing['services_offered']) ? (string) $existing['services_offered'] : '',
                'provider_type' => isset($existing['provider_type']) ? (string) $existing['provider_type'] : ''
            );

            $changed_fields = array();
            foreach ($new_values as $field => $value) {
                if ((string) $old_values[$field] !== (string) $value) {
                    $changed_fields[$field] = array(
                        'old' => $old_values[$field],
                        'new' => $value
                    );
                }
            }

            if (empty($changed_fields)) {
                $result['stats']['rows_unchanged']++;
                continue;
            }

            if ($apply_changes) {
                $update_success = Resources_Manager::update_resource($resource_id, $new_values);
                if (!$update_success) {
                    $result['stats']['rows_failed_validation']++;
                    $result['review_queue'][] = array(
                        'resource_id' => $resource_id,
                        'row_number' => $row['_row_number'],
                        'reason' => 'db_update_failed',
                        'message' => 'Database update failed for row.',
                        'raw_row' => $row
                    );
                    continue;
                }

                foreach ($changed_fields as $field => $change) {
                    self::log_audit(array(
                        'import_run_id' => $result['import_run_id'],
                        'resource_id' => $resource_id,
                        'action' => 'update',
                        'field_name' => $field,
                        'old_value' => $change['old'],
                        'new_value' => $change['new'],
                        'row_number' => $row['_row_number'],
                        'source_file' => $result['source_file'],
                        'source_file_hash' => $result['source_file_hash'],
                        'raw_row_json' => wp_json_encode($row)
                    ));
                }
            }

            $result['stats']['rows_updated']++;
        }

        if ($apply_changes) {
            foreach ($result['duplicates_ignored'] as $duplicate_item) {
                self::log_audit(array(
                    'import_run_id' => $result['import_run_id'],
                    'resource_id' => $duplicate_item['resource_id'],
                    'action' => 'duplicate_ignored',
                    'field_name' => null,
                    'old_value' => null,
                    'new_value' => null,
                    'row_number' => $duplicate_item['row_number'],
                    'source_file' => $result['source_file'],
                    'source_file_hash' => $result['source_file_hash'],
                    'raw_row_json' => wp_json_encode($duplicate_item['raw_row'])
                ));
            }

            foreach ($result['review_queue'] as $review_item) {
                self::log_audit(array(
                    'import_run_id' => $result['import_run_id'],
                    'resource_id' => isset($review_item['resource_id']) ? $review_item['resource_id'] : null,
                    'action' => 'conflict_logged',
                    'field_name' => isset($review_item['reason']) ? $review_item['reason'] : null,
                    'old_value' => null,
                    'new_value' => null,
                    'row_number' => isset($review_item['row_number']) ? $review_item['row_number'] : null,
                    'source_file' => $result['source_file'],
                    'source_file_hash' => $result['source_file_hash'],
                    'raw_row_json' => wp_json_encode(isset($review_item['raw_row']) ? $review_item['raw_row'] : $review_item)
                ));
            }
        }

        $result['stats']['review_queue_count'] = count($result['review_queue']);
        $result['success'] = true;

        $mode_text = $apply_changes ? 'applied' : 'dry run';
        $result['message'] = sprintf(
            'Import %s complete: %d updated, %d unchanged, %d duplicates ignored, %d in review queue.',
            $mode_text,
            $result['stats']['rows_updated'],
            $result['stats']['rows_unchanged'],
            $result['stats']['duplicates_ignored'],
            $result['stats']['review_queue_count']
        );

        return $result;
    }

    /**
     * Read CSV/XLSX rows.
     *
     * @param string $file_path
     * @param string $source_file_name
     * @return array|WP_Error
     */
    private static function read_rows($file_path, $source_file_name = '') {
        $extension = strtolower(pathinfo($source_file_name ? $source_file_name : $file_path, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            return self::read_csv($file_path);
        }

        if (in_array($extension, array('xlsx', 'xls'), true)) {
            return self::read_excel($file_path);
        }

        return new WP_Error('invalid_format', 'Unsupported file format. Please upload CSV, XLSX, or XLS.');
    }

    /**
     * Read rows from CSV.
     *
     * @param string $file_path
     * @return array
     */
    private static function read_csv($file_path) {
        $rows = array();
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return $rows;
        }

        $headers = fgetcsv($handle);
        if (!is_array($headers)) {
            fclose($handle);
            return $rows;
        }

        $headers = array_map(array(__CLASS__, 'normalize_header'), $headers);
        $row_number = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $row_number++;
            $row = array('_row_number' => $row_number);
            foreach ($headers as $index => $header) {
                $row[$header] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Read rows from Excel.
     *
     * @param string $file_path
     * @return array|WP_Error
     */
    private static function read_excel($file_path) {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            return new WP_Error('missing_dependency', 'PhpSpreadsheet dependency is required for Excel imports.');
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
            $sheet = $spreadsheet->getActiveSheet();
            $highest_row = $sheet->getHighestDataRow();
            $highest_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

            $headers = array();
            for ($col = 1; $col <= $highest_col; $col++) {
                $headers[] = self::normalize_header((string) $sheet->getCellByColumnAndRow($col, 1)->getValue());
            }

            $rows = array();
            for ($row_number = 2; $row_number <= $highest_row; $row_number++) {
                $row = array('_row_number' => $row_number);
                $has_value = false;
                for ($col = 1; $col <= $highest_col; $col++) {
                    $value = $sheet->getCellByColumnAndRow($col, $row_number)->getValue();
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $has_value = true;
                    }
                    $row[$headers[$col - 1]] = $value;
                }

                if ($has_value) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } catch (\Throwable $e) {
            return new WP_Error('excel_read_failed', $e->getMessage());
        }
    }

    /**
     * Keep last row per ID and mark earlier duplicates as ignored.
     *
     * @param array $rows
     * @param array $result
     * @return array
     */
    private static function dedupe_rows_by_id($rows, &$result) {
        $deduped = array();

        foreach ($rows as $row) {
            $resource_id = self::get_row_resource_id($row);
            if ($resource_id <= 0) {
                continue;
            }

            if (isset($deduped[$resource_id])) {
                $result['stats']['duplicates_ignored']++;
                $result['duplicates_ignored'][] = array(
                    'resource_id' => $resource_id,
                    'row_number' => $deduped[$resource_id]['_row_number'],
                    'reason' => 'superseded_by_later_row',
                    'raw_row' => $deduped[$resource_id]
                );
            }

            $deduped[$resource_id] = $row;
        }

        return $deduped;
    }

    /**
     * Validate and normalize taxonomy fields from row.
     *
     * @param array $row
     * @return array
     */
    private static function validate_row($row) {
        $resource_id = self::get_row_resource_id($row);
        $service_area_raw = self::get_row_value($row, array('service area', 'service_area'));
        $services_offered_raw = self::get_row_value($row, array('services offered', 'services_offered'));
        $provider_type_raw = self::get_row_value($row, array('provider type', 'provider_type'));

        $service_area_tokens = self::split_services_tokens($service_area_raw);
        $service_area_slugs = Resource_Taxonomy::normalize_service_area_slugs($service_area_tokens);
        $invalid_service_areas = array();

        foreach ($service_area_tokens as $token) {
            $token_slug = Resource_Taxonomy::normalize_slug($token);
            if ($token_slug !== '' && !in_array($token_slug, $service_area_slugs, true)) {
                $invalid_service_areas[] = $token;
            }
        }

        if (!empty($invalid_service_areas)) {
            return array(
                'valid' => false,
                'review_item' => array(
                    'resource_id' => $resource_id,
                    'row_number' => isset($row['_row_number']) ? (int) $row['_row_number'] : 0,
                    'reason' => 'invalid_service_area_term',
                    'message' => 'One or more Service Area terms are not canonical: ' . implode(', ', $invalid_service_areas),
                    'raw_row' => $row
                )
            );
        }

        if (empty($service_area_slugs)) {
            return array(
                'valid' => false,
                'review_item' => array(
                    'resource_id' => $resource_id,
                    'row_number' => isset($row['_row_number']) ? (int) $row['_row_number'] : 0,
                    'reason' => 'missing_or_invalid_service_area',
                    'message' => 'Service Area is required and must match canonical list.',
                    'raw_row' => $row
                )
            );
        }

        $services_tokens = self::split_services_tokens($services_offered_raw);
        $services_slugs = Resource_Taxonomy::normalize_services_offered_slugs($services_tokens);
        $invalid_services = array();

        foreach ($services_tokens as $token) {
            $token_slug = Resource_Taxonomy::normalize_slug($token);
            if ($token_slug !== '' && !in_array($token_slug, $services_slugs, true)) {
                $invalid_services[] = $token;
            }
        }

        if (!empty($invalid_services)) {
            return array(
                'valid' => false,
                'review_item' => array(
                    'resource_id' => $resource_id,
                    'row_number' => isset($row['_row_number']) ? (int) $row['_row_number'] : 0,
                    'reason' => 'invalid_services_offered_term',
                    'message' => 'One or more Services Offered terms are not canonical: ' . implode(', ', $invalid_services),
                    'raw_row' => $row
                )
            );
        }

        $provider_slug = Resource_Taxonomy::normalize_provider_type_slug($provider_type_raw);
        if ($provider_type_raw !== '' && $provider_slug === '') {
            return array(
                'valid' => false,
                'review_item' => array(
                    'resource_id' => $resource_id,
                    'row_number' => isset($row['_row_number']) ? (int) $row['_row_number'] : 0,
                    'reason' => 'invalid_provider_type',
                    'message' => 'Provider Type does not match canonical list.',
                    'raw_row' => $row
                )
            );
        }

        return array(
            'valid' => true,
            'normalized' => array(
                'service_area' => Resource_Taxonomy::to_pipe_slug_string($service_area_slugs),
                'services_offered' => Resource_Taxonomy::to_pipe_slug_string($services_slugs),
                'provider_type' => $provider_slug
            )
        );
    }

    /**
     * Normalize header keys for flexible matching.
     *
     * @param string $header
     * @return string
     */
    private static function normalize_header($header) {
        $header = trim(strtolower($header));
        $header = preg_replace('/\s+/', ' ', $header);
        return str_replace("\xEF\xBB\xBF", '', $header);
    }

    /**
     * Get first matching row value by possible header names.
     *
     * @param array $row
     * @param array $candidates
     * @return string
     */
    private static function get_row_value($row, $candidates) {
        foreach ($candidates as $candidate) {
            if (isset($row[$candidate])) {
                return (string) $row[$candidate];
            }
        }
        return '';
    }

    /**
     * Extract resource ID from row using known header variants.
     *
     * @param array $row
     * @return int
     */
    private static function get_row_resource_id($row) {
        $raw_id = self::get_row_value($row, array('id', 'resource id', 'resource_id'));
        return (int) $raw_id;
    }

    /**
     * Split services-offered string by common delimiters.
     *
     * @param string $value
     * @return string[]
     */
    private static function split_services_tokens($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }

        $parts = preg_split('/\s*\|\s*|\s*;\s*|\s*,\s*/', $value);
        $tokens = array();
        foreach ((array) $parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $tokens[] = $part;
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * Return audit table name.
     *
     * @return string
     */
    private static function get_audit_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'resources_taxonomy_import_audit';
    }

    /**
     * Ensure audit table exists.
     *
     * @return bool
     */
    private static function ensure_audit_table_exists() {
        global $wpdb;
        $table_name = self::get_audit_table_name();

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if ($exists === $table_name) {
            return true;
        }

        if (function_exists('monday_resources_ensure_taxonomy_import_audit_table')) {
            monday_resources_ensure_taxonomy_import_audit_table();
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
            return $exists === $table_name;
        }

        return false;
    }

    /**
     * Get recent import runs that applied taxonomy updates.
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent_apply_runs($limit = 20) {
        global $wpdb;

        if (!self::ensure_audit_table_exists()) {
            return array();
        }

        $table_name = self::get_audit_table_name();
        $limit = max(1, min(100, (int) $limit));

        $query = $wpdb->prepare(
            "SELECT
                import_run_id,
                MAX(created_at) AS last_activity_at,
                COUNT(*) AS update_rows,
                COUNT(DISTINCT resource_id) AS resources_touched
            FROM $table_name
            WHERE action = 'update'
            GROUP BY import_run_id
            ORDER BY MAX(id) DESC
            LIMIT %d",
            $limit
        );

        $rows = $wpdb->get_results($query, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $runs = array();
        foreach ($rows as $row) {
            $import_run_id = isset($row['import_run_id']) ? (string) $row['import_run_id'] : '';
            if ($import_run_id === '') {
                continue;
            }

            $runs[] = array(
                'import_run_id' => $import_run_id,
                'last_activity_at' => isset($row['last_activity_at']) ? (string) $row['last_activity_at'] : '',
                'update_rows' => isset($row['update_rows']) ? (int) $row['update_rows'] : 0,
                'resources_touched' => isset($row['resources_touched']) ? (int) $row['resources_touched'] : 0
            );
        }

        return $runs;
    }

    /**
     * Roll back taxonomy fields for a specific import run.
     *
     * @param string $import_run_id
     * @param int $actor_user_id
     * @return array
     */
    public static function rollback_import_run($import_run_id, $actor_user_id = 0) {
        global $wpdb;

        $import_run_id = sanitize_text_field((string) $import_run_id);
        $actor_user_id = (int) $actor_user_id;

        $result = array(
            'success' => false,
            'message' => '',
            'import_run_id' => $import_run_id,
            'stats' => array(
                'audit_rows_scanned' => 0,
                'resources_targeted' => 0,
                'resources_rolled_back' => 0,
                'resources_unchanged' => 0,
                'resources_not_found' => 0,
                'resources_failed' => 0,
                'fields_rolled_back' => 0
            )
        );

        if ($import_run_id === '') {
            $result['message'] = 'Import Run ID is required.';
            return $result;
        }

        if (!self::ensure_audit_table_exists()) {
            $result['message'] = 'Audit table is not available. Unable to run rollback.';
            return $result;
        }

        $table_name = self::get_audit_table_name();
        $query = $wpdb->prepare(
            "SELECT id, resource_id, field_name, old_value
            FROM $table_name
            WHERE import_run_id = %s
            AND action = 'update'
            AND field_name IN ('service_area', 'services_offered', 'provider_type')
            ORDER BY id ASC",
            $import_run_id
        );

        $audit_rows = $wpdb->get_results($query, ARRAY_A);
        if (empty($audit_rows)) {
            $result['message'] = 'No update audit rows found for that Import Run ID.';
            return $result;
        }

        $result['stats']['audit_rows_scanned'] = count($audit_rows);
        $rollback_map = array();

        foreach ($audit_rows as $audit_row) {
            $resource_id = isset($audit_row['resource_id']) ? (int) $audit_row['resource_id'] : 0;
            $field_name = isset($audit_row['field_name']) ? (string) $audit_row['field_name'] : '';
            if ($resource_id <= 0 || $field_name === '') {
                continue;
            }

            if (!isset($rollback_map[$resource_id])) {
                $rollback_map[$resource_id] = array();
            }

            // Earliest old_value per field recreates pre-import state.
            if (!array_key_exists($field_name, $rollback_map[$resource_id])) {
                $rollback_map[$resource_id][$field_name] = isset($audit_row['old_value']) ? (string) $audit_row['old_value'] : '';
            }
        }

        $result['stats']['resources_targeted'] = count($rollback_map);
        if (empty($rollback_map)) {
            $result['message'] = 'No rollback candidates found for that Import Run ID.';
            return $result;
        }

        foreach ($rollback_map as $resource_id => $target_fields) {
            $existing = Resources_Manager::get_resource($resource_id);
            if (!$existing) {
                $result['stats']['resources_not_found']++;
                continue;
            }

            $update_data = array();
            $field_changes = array();

            foreach ($target_fields as $field_name => $rollback_value) {
                $current_value = isset($existing[$field_name]) ? (string) $existing[$field_name] : '';
                if ($current_value === (string) $rollback_value) {
                    continue;
                }

                $update_data[$field_name] = $rollback_value;
                $field_changes[$field_name] = array(
                    'old' => $current_value,
                    'new' => (string) $rollback_value
                );
            }

            if (empty($update_data)) {
                $result['stats']['resources_unchanged']++;
                continue;
            }

            $update_success = Resources_Manager::update_resource($resource_id, $update_data);
            if (!$update_success) {
                $result['stats']['resources_failed']++;
                continue;
            }

            $result['stats']['resources_rolled_back']++;
            $result['stats']['fields_rolled_back'] += count($update_data);

            foreach ($field_changes as $field_name => $change) {
                self::log_audit(array(
                    'import_run_id' => $import_run_id,
                    'resource_id' => $resource_id,
                    'action' => 'rollback',
                    'field_name' => $field_name,
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                    'row_number' => null,
                    'source_file' => 'rollback',
                    'source_file_hash' => '',
                    'raw_row_json' => wp_json_encode(array(
                        'rollback_from_import_run_id' => $import_run_id,
                        'actor_user_id' => $actor_user_id,
                        'rolled_back_at' => current_time('mysql')
                    ))
                ));
            }
        }

        $result['success'] = true;
        $result['message'] = sprintf(
            'Rollback complete for %s: %d resources rolled back (%d fields), %d unchanged, %d not found, %d failed.',
            $import_run_id,
            $result['stats']['resources_rolled_back'],
            $result['stats']['fields_rolled_back'],
            $result['stats']['resources_unchanged'],
            $result['stats']['resources_not_found'],
            $result['stats']['resources_failed']
        );

        return $result;
    }

    /**
     * Insert audit row.
     *
     * @param array $data
     * @return void
     */
    private static function log_audit($data) {
        global $wpdb;
        if (!self::ensure_audit_table_exists()) {
            return;
        }

        $table_name = self::get_audit_table_name();

        $wpdb->insert(
            $table_name,
            array(
                'import_run_id' => isset($data['import_run_id']) ? $data['import_run_id'] : '',
                'resource_id' => isset($data['resource_id']) ? $data['resource_id'] : null,
                'action' => isset($data['action']) ? $data['action'] : '',
                'field_name' => isset($data['field_name']) ? $data['field_name'] : null,
                'old_value' => isset($data['old_value']) ? $data['old_value'] : null,
                'new_value' => isset($data['new_value']) ? $data['new_value'] : null,
                'row_number' => isset($data['row_number']) ? $data['row_number'] : null,
                'source_file' => isset($data['source_file']) ? $data['source_file'] : null,
                'source_file_hash' => isset($data['source_file_hash']) ? $data['source_file_hash'] : null,
                'raw_row_json' => isset($data['raw_row_json']) ? $data['raw_row_json'] : null,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );
    }
}
