<?php
/**
 * Resource Migration Class
 *
 * Handles migration of Resource Type, Need Met, and Target Population data from Excel/CSV spreadsheets
 */

class Resource_Migration {

    /**
     * Process uploaded spreadsheet file
     *
     * @param string $file_path Path to uploaded file (temp file)
     * @param string $original_filename Original filename with extension
     * @return array Result with success status, message, and statistics
     */
    public static function process_spreadsheet($file_path, $original_filename = '') {
        $result = array(
            'success' => false,
            'message' => '',
            'stats' => array(
                'resources_updated' => 0,
                'resources_not_found' => 0,
                'resource_types_added' => 0,
                'needs_met_added' => 0,
                'target_populations_added' => 0,
                'errors' => array()
            )
        );

        if (!file_exists($file_path)) {
            $result['message'] = 'File not found.';
            return $result;
        }

        // Get file extension from original filename if provided, otherwise from file path
        if (!empty($original_filename)) {
            $file_extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        } else {
            $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        }
        
        // If still no extension, try to detect from MIME type
        if (empty($file_extension) && function_exists('mime_content_type')) {
            $mime_type = mime_content_type($file_path);
            if ($mime_type === 'text/csv' || $mime_type === 'text/plain') {
                $file_extension = 'csv';
            } elseif ($mime_type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
                $file_extension = 'xlsx';
            } elseif ($mime_type === 'application/vnd.ms-excel') {
                $file_extension = 'xls';
            }
        }

        // Read file based on extension
        if ($file_extension === 'csv') {
            $data = self::read_csv($file_path);
        } elseif (in_array($file_extension, array('xlsx', 'xls'))) {
            $data = self::read_excel($file_path);
        } else {
            $result['message'] = 'Unsupported file format. Detected extension: "' . esc_html($file_extension) . '". Please upload CSV or Excel (.xlsx, .xls) file. Original filename: ' . esc_html($original_filename);
            return $result;
        }

        if (empty($data)) {
            $result['message'] = 'No data found in file or file could not be read.';
            return $result;
        }

        // Validate required columns
        $required_columns = array('Resource ID', 'Resource Name', 'Primary Category', 'Secondary Categories', 'Target Populations (New)');
        $headers = array_keys($data[0]);
        $missing_columns = array_diff($required_columns, $headers);

        if (!empty($missing_columns)) {
            $result['message'] = 'Missing required columns: ' . implode(', ', $missing_columns);
            return $result;
        }

        // Extract unique values for option lists
        $resource_types = array();
        $needs_met = array();
        $target_populations = array();

        foreach ($data as $row) {
            // Collect Resource Types (Primary Category)
            if (!empty($row['Primary Category'])) {
                $type = trim($row['Primary Category']);
                if (!empty($type) && !in_array($type, $resource_types)) {
                    $resource_types[] = $type;
                }
            }

            // Collect Needs Met (Secondary Categories - semicolon-separated)
            if (!empty($row['Secondary Categories'])) {
                $needs = array_map('trim', explode(';', $row['Secondary Categories']));
                foreach ($needs as $need) {
                    if (!empty($need) && !in_array($need, $needs_met)) {
                        $needs_met[] = $need;
                    }
                }
            }

            // Collect Target Populations (semicolon-separated)
            if (!empty($row['Target Populations (New)'])) {
                $populations = array_map('trim', explode(';', $row['Target Populations (New)']));
                foreach ($populations as $population) {
                    if (!empty($population) && !in_array($population, $target_populations)) {
                        $target_populations[] = $population;
                    }
                }
            }
        }

        // Sort all lists alphabetically
        sort($resource_types);
        sort($needs_met);
        sort($target_populations);

        // Update option lists
        $existing_resource_types = get_option('resource_service_types', array());
        $new_resource_types = array_diff($resource_types, $existing_resource_types);
        if (!empty($new_resource_types)) {
            $updated_resource_types = array_merge($existing_resource_types, $new_resource_types);
            sort($updated_resource_types);
            update_option('resource_service_types', $updated_resource_types);
            $result['stats']['resource_types_added'] = count($new_resource_types);
        }

        $existing_needs_met = get_option('resource_need_options', array());
        $new_needs_met = array_diff($needs_met, $existing_needs_met);
        if (!empty($new_needs_met)) {
            $updated_needs_met = array_merge($existing_needs_met, $new_needs_met);
            sort($updated_needs_met);
            update_option('resource_need_options', $updated_needs_met);
            $result['stats']['needs_met_added'] = count($new_needs_met);
        }

        $existing_target_populations = get_option('resource_target_population_options', array());
        $new_target_populations = array_diff($target_populations, $existing_target_populations);
        if (!empty($new_target_populations)) {
            $updated_target_populations = array_merge($existing_target_populations, $new_target_populations);
            sort($updated_target_populations);
            update_option('resource_target_population_options', $updated_target_populations);
            $result['stats']['target_populations_added'] = count($new_target_populations);
        }

        // Update database records
        global $wpdb;
        $table_name = $wpdb->prefix . 'resources';
        $resources_not_found = array();

        foreach ($data as $row) {
            $resource_id = intval($row['Resource ID']);
            if (empty($resource_id)) {
                continue;
            }

            // Check if resource exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE id = %d",
                $resource_id
            ));

            if (!$exists) {
                $resources_not_found[] = $resource_id;
                $result['stats']['resources_not_found']++;
                continue;
            }

            // Prepare update data
            $update_data = array();
            $update_format = array();

            // Update Primary Category (Resource Type)
            if (!empty($row['Primary Category'])) {
                $update_data['primary_service_type'] = trim($row['Primary Category']);
                $update_format[] = '%s';
            }

            // Update Secondary Categories (Need Met) - convert semicolon to comma
            if (!empty($row['Secondary Categories'])) {
                $needs = array_map('trim', explode(';', $row['Secondary Categories']));
                $needs = array_filter($needs); // Remove empty values
                $update_data['secondary_service_type'] = implode(', ', $needs);
                $update_format[] = '%s';
            }

            // Update Target Populations - convert semicolon to comma
            if (!empty($row['Target Populations (New)'])) {
                $populations = array_map('trim', explode(';', $row['Target Populations (New)']));
                $populations = array_filter($populations); // Remove empty values
                $update_data['target_population'] = implode(', ', $populations);
                $update_format[] = '%s';
            }

            // Update the resource
            if (!empty($update_data)) {
                $update_data['updated_at'] = current_time('mysql');
                $update_format[] = '%s';

                $update_result = $wpdb->update(
                    $table_name,
                    $update_data,
                    array('id' => $resource_id),
                    $update_format,
                    array('%d')
                );

                if ($update_result !== false) {
                    $result['stats']['resources_updated']++;
                } else {
                    $result['stats']['errors'][] = 'Failed to update resource ID ' . $resource_id;
                }
            }
        }

        // Build success message
        $messages = array();
        $messages[] = sprintf('%d resources updated', $result['stats']['resources_updated']);
        
        if ($result['stats']['resources_not_found'] > 0) {
            $messages[] = sprintf('%d resources not found by ID', $result['stats']['resources_not_found']);
        }
        
        if ($result['stats']['resource_types_added'] > 0) {
            $messages[] = sprintf('%d new resource types added to options', $result['stats']['resource_types_added']);
        }
        
        if ($result['stats']['needs_met_added'] > 0) {
            $messages[] = sprintf('%d new need met options added', $result['stats']['needs_met_added']);
        }
        
        if ($result['stats']['target_populations_added'] > 0) {
            $messages[] = sprintf('%d new target population options added', $result['stats']['target_populations_added']);
        }

        $result['success'] = true;
        $result['message'] = implode('. ', $messages) . '.';

        if (!empty($result['stats']['errors'])) {
            $result['message'] .= ' Errors: ' . implode(', ', array_slice($result['stats']['errors'], 0, 5));
            if (count($result['stats']['errors']) > 5) {
                $result['message'] .= ' and ' . (count($result['stats']['errors']) - 5) . ' more.';
            }
        }

        return $result;
    }

    /**
     * Read CSV file
     *
     * @param string $file_path Path to CSV file
     * @return array Array of associative arrays with column names as keys
     */
    private static function read_csv($file_path) {
        $data = array();
        $headers = array();

        if (($handle = fopen($file_path, 'r')) !== false) {
            // Read headers
            $headers = fgetcsv($handle);
            if ($headers === false) {
                fclose($handle);
                return array();
            }

            // Clean headers (remove BOM if present)
            $headers = array_map(function($header) {
                return trim(str_replace("\xEF\xBB\xBF", '', $header));
            }, $headers);

            // Read data rows
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === count($headers)) {
                    $data[] = array_combine($headers, $row);
                }
            }

            fclose($handle);
        }

        return $data;
    }

    /**
     * Read Excel file
     *
     * @param string $file_path Path to Excel file
     * @return array Array of associative arrays with column names as keys
     */
    private static function read_excel($file_path) {
        // Check if PhpSpreadsheet is available
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            // Try to use SimpleXLSX if available
            if (function_exists('simplexlsx_read_file')) {
                $xlsx = simplelsx_read_file($file_path);
                if ($xlsx) {
                    return self::convert_xlsx_to_array($xlsx);
                }
            }
            
            return array(); // Cannot read Excel without library
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = array();

            // Get headers from first row
            $headers = array();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellValue = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
                $headers[] = trim($cellValue);
            }

            // Read data rows
            $highestRow = $worksheet->getHighestRow();
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = array();
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $cellValue = $worksheet->getCellByColumnAndRow($col, $row)->getValue();
                    $rowData[] = trim($cellValue);
                }
                if (count($rowData) === count($headers)) {
                    $data[] = array_combine($headers, $rowData);
                }
            }

            return $data;
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Convert SimpleXLSX data to array format
     *
     * @param object $xlsx SimpleXLSX object
     * @return array Array of associative arrays
     */
    private static function convert_xlsx_to_array($xlsx) {
        $rows = $xlsx->rows();
        if (empty($rows)) {
            return array();
        }

        $headers = array_shift($rows);
        $headers = array_map('trim', $headers);

        $data = array();
        foreach ($rows as $row) {
            if (count($row) === count($headers)) {
                $data[] = array_combine($headers, $row);
            }
        }

        return $data;
    }
}

