<?php
/**
 * Resource Exporter Class
 * Exports resources to multiple formats: CSV, Excel (XLSX), JSON, PDF
 *
 * @package Monday_Resources
 */

class Resource_Exporter {

    /**
     * Export resources to CSV
     *
     * @param array $resources Array of resource data
     * @param array $fields Fields to include (optional, defaults to all)
     * @return string CSV content
     */
    public static function export_csv($resources, $fields = null) {
        if (empty($resources)) {
            return '';
        }

        // Default fields if not specified
        if ($fields === null) {
            $fields = array(
                'id' => 'ID',
                'resource_name' => 'Resource Name',
                'primary_service_type' => 'Primary Service',
                'phone' => 'Phone',
                'email' => 'Email',
                'website' => 'Website',
                'physical_address' => 'Address',
                'geography' => 'Geography',
                'target_population' => 'Target Population',
                'income_requirements' => 'Income Requirements',
                'office_hours' => 'Office Hours',
                'service_hours' => 'Service Hours',
                'last_verified' => 'Last Verified'
            );
        }

        // Create CSV
        $output = fopen('php://temp', 'r+');

        // Write header row
        fputcsv($output, array_values($fields));

        // Write data rows
        foreach ($resources as $resource) {
            $row = array();
            foreach (array_keys($fields) as $field) {
                if (isset($resource[$field])) {
                    // Format complex fields
                    if ($field === 'office_hours' || $field === 'service_hours') {
                        $row[] = self::format_hours_for_export($resource[$field]);
                    } elseif (is_array($resource[$field])) {
                        $row[] = implode(', ', $resource[$field]);
                    } else {
                        $row[] = $resource[$field];
                    }
                } else {
                    $row[] = '';
                }
            }
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export resources to Excel (XLSX)
     * Requires PhpSpreadsheet library
     *
     * @param array $resources Array of resource data
     * @param array $fields Fields to include
     * @return string|WP_Error Excel file content or error
     */
    public static function export_excel($resources, $fields = null) {
        // Check if PhpSpreadsheet is available
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            return new WP_Error('missing_dependency', 'PhpSpreadsheet library not installed. Run: composer require phpoffice/phpspreadsheet');
        }

        if (empty($resources)) {
            return new WP_Error('no_data', 'No resources to export');
        }

        // Default fields if not specified
        if ($fields === null) {
            $fields = array(
                'id' => 'ID',
                'resource_name' => 'Resource Name',
                'primary_service_type' => 'Primary Service',
                'phone' => 'Phone',
                'email' => 'Email',
                'website' => 'Website',
                'physical_address' => 'Address',
                'geography' => 'Geography',
                'target_population' => 'Target Population',
                'income_requirements' => 'Income Requirements',
                'office_hours' => 'Office Hours',
                'service_hours' => 'Service Hours',
                'last_verified' => 'Last Verified'
            );
        }

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Write header row
            $col = 1;
            foreach ($fields as $header) {
                $sheet->setCellValueByColumnAndRow($col, 1, $header);
                $col++;
            }

            // Style header row
            $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($fields)) . '1')
                  ->getFont()->setBold(true);
            $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($fields)) . '1')
                  ->getFill()
                  ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                  ->getStartColor()->setRGB('CCCCCC');

            // Write data rows
            $row = 2;
            foreach ($resources as $resource) {
                $col = 1;
                foreach (array_keys($fields) as $field) {
                    if (isset($resource[$field])) {
                        if ($field === 'office_hours' || $field === 'service_hours') {
                            $value = self::format_hours_for_export($resource[$field]);
                        } elseif (is_array($resource[$field])) {
                            $value = implode(', ', $resource[$field]);
                        } else {
                            $value = $resource[$field];
                        }
                        $sheet->setCellValueByColumnAndRow($col, $row, $value);
                    }
                    $col++;
                }
                $row++;
            }

            // Auto-size columns
            foreach (range(1, count($fields)) as $col) {
                $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }

            // Generate Excel file
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $excel_content = ob_get_clean();

            return $excel_content;

        } catch (Exception $e) {
            return new WP_Error('export_failed', $e->getMessage());
        }
    }

    /**
     * Export resources to JSON
     *
     * @param array $resources Array of resource data
     * @param bool $pretty Pretty print (default true)
     * @return string JSON content
     */
    public static function export_json($resources, $pretty = true) {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($resources, $flags);
    }

    /**
     * Export resources to PDF
     * Requires TCPDF library
     *
     * @param array $resources Array of resource data
     * @param array $fields Fields to include
     * @return string|WP_Error PDF file content or error
     */
    public static function export_pdf($resources, $fields = null) {
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            return new WP_Error('missing_dependency', 'TCPDF library not installed. Run: composer require tecnickcom/tcpdf');
        }

        if (empty($resources)) {
            return new WP_Error('no_data', 'No resources to export');
        }

        // Default fields for PDF (more concise)
        if ($fields === null) {
            $fields = array(
                'resource_name' => 'Resource Name',
                'primary_service_type' => 'Service Type',
                'phone' => 'Phone',
                'address' => 'Address',
                'service_hours' => 'Hours'
            );
        }

        try {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');

            // Set document information
            $pdf->SetCreator('SVDP Resources Plugin');
            $pdf->SetAuthor('St. Vincent de Paul');
            $pdf->SetTitle('Community Resources');

            // Set margins
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(true, 15);

            // Add page
            $pdf->AddPage();

            // Title
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'Community Resources', 0, 1, 'C');
            $pdf->Ln(5);

            // Table header
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(220, 220, 220);

            // Calculate column widths
            $pageWidth = $pdf->getPageWidth() - 30; // 30 = margins
            $widths = array(
                'name' => $pageWidth * 0.3,
                'primary_service_type' => $pageWidth * 0.2,
                'phone' => $pageWidth * 0.15,
                'address' => $pageWidth * 0.2,
                'service_hours' => $pageWidth * 0.15
            );

            // Header row
            foreach ($fields as $key => $header) {
                $pdf->Cell($widths[$key] ?? 30, 7, $header, 1, 0, 'L', true);
            }
            $pdf->Ln();

            // Data rows
            $pdf->SetFont('helvetica', '', 9);
            foreach ($resources as $resource) {
                $maxHeight = 7;
                foreach (array_keys($fields) as $field) {
                    $value = '';
                    if (isset($resource[$field])) {
                        if ($field === 'service_hours' || $field === 'office_hours') {
                            $value = self::format_hours_for_export($resource[$field], true);
                        } elseif (is_array($resource[$field])) {
                            $value = implode(', ', $resource[$field]);
                        } else {
                            $value = $resource[$field];
                        }
                    }

                    $pdf->MultiCell($widths[$field] ?? 30, $maxHeight, $value, 1, 'L', false, 0);
                }
                $pdf->Ln();
            }

            // Output PDF
            return $pdf->Output('resources.pdf', 'S');

        } catch (Exception $e) {
            return new WP_Error('export_failed', $e->getMessage());
        }
    }

    /**
     * Format hours data for export
     *
     * @param mixed $hours_data Hours data array or string
     * @param bool $compact Use compact format (default false)
     * @return string Formatted hours string
     */
    private static function format_hours_for_export($hours_data, $compact = false) {
        if (is_string($hours_data)) {
            return $hours_data;
        }

        if (!is_array($hours_data)) {
            return '';
        }

        // Use Resource_Hours_Manager if available
        if (class_exists('Resource_Hours_Manager')) {
            return Resource_Hours_Manager::format_hours_display($hours_data, $compact ? 'compact' : 'list');
        }

        // Fallback: simple formatting
        $lines = array();
        $days = array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat');

        for ($day = 0; $day <= 6; $day++) {
            if (isset($hours_data[$day])) {
                $day_data = $hours_data[$day];
                if (isset($day_data['is_closed']) && $day_data['is_closed']) {
                    if (!$compact) {
                        $lines[] = $days[$day] . ': Closed';
                    }
                } elseif (isset($day_data['open_time']) && isset($day_data['close_time'])) {
                    $lines[] = $days[$day] . ': ' . $day_data['open_time'] . '-' . $day_data['close_time'];
                }
            }
        }

        return implode('; ', $lines);
    }

    /**
     * Get all resources for export
     *
     * @return array Array of resources with all data
     */
    public static function get_all_resources_for_export() {
        global $wpdb;
        $resources_table = $wpdb->prefix . 'resources';

        $resources = $wpdb->get_results(
            "SELECT * FROM $resources_table ORDER BY resource_name ASC",
            ARRAY_A
        );

        // Enhance each resource with hours data
        foreach ($resources as &$resource) {
            if (class_exists('Resource_Hours_Manager')) {
                $hours_data = Resource_Hours_Manager::get_hours($resource['id']);
                if ($hours_data) {
                    $resource['office_hours'] = $hours_data['office_hours'];
                    $resource['service_hours'] = $hours_data['service_hours'];
                }
            }
        }

        return $resources;
    }

    /**
     * Send export file to browser
     *
     * @param string $content File content
     * @param string $filename Filename
     * @param string $mime_type MIME type
     */
    public static function send_download($content, $filename, $mime_type) {
        // Clear any output
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Pragma: no-cache');
        header('Expires: 0');

        // Output content
        echo $content;
        exit;
    }
}
