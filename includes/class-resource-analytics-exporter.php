<?php
/**
 * Analytics export endpoint (CSV, XLSX, PDF).
 */

class Resource_Analytics_Exporter {

    /**
     * Wire AJAX endpoint.
     */
    public function __construct() {
        add_action('wp_ajax_svdp_export_analytics', array($this, 'export_analytics'));
    }

    /**
     * Export analytics dataset for current filter slice.
     *
     * @return void
     */
    public function export_analytics() {
        check_ajax_referer('svdp_export_analytics');

        if (!monday_resources_is_analytics_exports_enabled()) {
            wp_die('Analytics exports are disabled.', 403);
        }

        if (!self::current_user_can_export()) {
            wp_die('Unauthorized', 403);
        }

        $format = isset($_GET['format']) ? sanitize_key((string) $_GET['format']) : 'csv';
        if (!in_array($format, array('csv', 'xlsx', 'pdf'), true)) {
            $format = 'csv';
        }

        $filters = Resource_Analytics::sanitize_filters($_GET);
        $data = Resource_Analytics::get_export_payload($filters);

        $label_parts = array('analytics', $filters['start_date'] . '_to_' . $filters['end_date']);
        if ($filters['segment'] !== 'all') {
            $label_parts[] = $filters['segment'];
        }
        if ($filters['geography'] !== 'all') {
            $label_parts[] = $filters['geography'];
        }
        $base = implode('_', $label_parts);

        switch ($format) {
            case 'xlsx':
                $content = $this->build_xlsx($data);
                if (is_wp_error($content)) {
                    wp_die(esc_html($content->get_error_message()), 500);
                }
                $this->stream_file($content, $base . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                break;

            case 'pdf':
                $content = $this->build_pdf($data);
                if (is_wp_error($content)) {
                    wp_die(esc_html($content->get_error_message()), 500);
                }
                $this->stream_file($content, $base . '.pdf', 'application/pdf');
                break;

            case 'csv':
            default:
                $content = $this->build_csv($data);
                $this->stream_file($content, $base . '.csv', 'text/csv; charset=utf-8');
                break;
        }
    }

    /**
     * CSV writer.
     *
     * @param array $data
     * @return string
     */
    private function build_csv($data) {
        $output = fopen('php://temp', 'r+');
        if (!$output) {
            return '';
        }

        $filters = isset($data['filters']) ? $data['filters'] : array();
        $kpis = isset($data['kpis']) ? $data['kpis'] : array();

        fputcsv($output, array('Resources Analytics Export'));
        fputcsv($output, array('Date Range', (string) ($filters['start_date'] ?? ''), (string) ($filters['end_date'] ?? '')));
        fputcsv($output, array('Segment', (string) ($filters['segment'] ?? 'all')));
        fputcsv($output, array('Geography', (string) ($filters['geography'] ?? 'all')));
        fputcsv($output, array('Channel', (string) ($filters['channel'] ?? 'all')));
        fputcsv($output, array());

        fputcsv($output, array('KPIs'));
        fputcsv($output, array('Searches', (int) ($kpis['searches'] ?? 0)));
        fputcsv($output, array('Zero Result Rate (%)', (float) ($kpis['zero_result_rate'] ?? 0)));
        fputcsv($output, array('Snapshot Sends', (int) ($kpis['snapshot_sends'] ?? 0)));
        fputcsv($output, array('Send Success Rate (%)', (float) ($kpis['send_success_rate'] ?? 0)));
        fputcsv($output, array());

        fputcsv($output, array('Daily Trends'));
        fputcsv($output, array('Date', 'Searches', 'Zero Results', 'Snapshot Sent'));
        foreach ((array) ($data['trend'] ?? array()) as $row) {
            fputcsv($output, array(
                (string) ($row['rollup_date'] ?? ''),
                (int) ($row['searches'] ?? 0),
                (int) ($row['zero_results'] ?? 0),
                (int) ($row['snapshot_sent'] ?? 0)
            ));
        }
        fputcsv($output, array());

        fputcsv($output, array('Top Queries'));
        fputcsv($output, array('Query', 'Count'));
        foreach ((array) ($data['top_queries'] ?? array()) as $row) {
            fputcsv($output, array((string) ($row['query_text'] ?? ''), (int) ($row['total'] ?? 0)));
        }
        fputcsv($output, array());

        fputcsv($output, array('Top Shared Resources'));
        fputcsv($output, array('Resource ID', 'Resource Name', 'Sends'));
        foreach ((array) ($data['top_shared_resources'] ?? array()) as $row) {
            fputcsv($output, array(
                (int) ($row['resource_id'] ?? 0),
                (string) ($row['resource_name'] ?? ''),
                (int) ($row['sent_count'] ?? 0)
            ));
        }
        fputcsv($output, array());

        fputcsv($output, array('Geography Summary'));
        fputcsv($output, array('Geography', 'Searches', 'Zero Results', 'Snapshot Sent', 'Trend Delta (%)'));
        foreach ((array) ($data['geography_summary'] ?? array()) as $row) {
            fputcsv($output, array(
                (string) ($row['geography_label'] ?? $row['geography_slug'] ?? ''),
                (int) ($row['searches'] ?? 0),
                (int) ($row['zero_results'] ?? 0),
                (int) ($row['snapshot_sent'] ?? 0),
                isset($row['trend_delta_pct']) ? (string) $row['trend_delta_pct'] : ''
            ));
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return (string) $csv;
    }

    /**
     * XLSX writer.
     *
     * @param array $data
     * @return string|WP_Error
     */
    private function build_xlsx($data) {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            return new WP_Error('missing_dependency', 'PhpSpreadsheet is required for XLSX exports.');
        }

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $summary = $spreadsheet->getActiveSheet();
            $summary->setTitle('Summary');

            $filters = isset($data['filters']) ? $data['filters'] : array();
            $kpis = isset($data['kpis']) ? $data['kpis'] : array();

            $summary->setCellValue('A1', 'Resources Analytics Summary');
            $summary->setCellValue('A3', 'Date Range');
            $summary->setCellValue('B3', (string) ($filters['start_date'] ?? '') . ' to ' . (string) ($filters['end_date'] ?? ''));
            $summary->setCellValue('A4', 'Segment');
            $summary->setCellValue('B4', (string) ($filters['segment'] ?? 'all'));
            $summary->setCellValue('A5', 'Geography');
            $summary->setCellValue('B5', (string) ($filters['geography'] ?? 'all'));
            $summary->setCellValue('A6', 'Channel');
            $summary->setCellValue('B6', (string) ($filters['channel'] ?? 'all'));

            $summary->setCellValue('A8', 'Searches');
            $summary->setCellValue('B8', (int) ($kpis['searches'] ?? 0));
            $summary->setCellValue('A9', 'Zero Result Rate (%)');
            $summary->setCellValue('B9', (float) ($kpis['zero_result_rate'] ?? 0));
            $summary->setCellValue('A10', 'Snapshot Sends');
            $summary->setCellValue('B10', (int) ($kpis['snapshot_sends'] ?? 0));
            $summary->setCellValue('A11', 'Send Success Rate (%)');
            $summary->setCellValue('B11', (float) ($kpis['send_success_rate'] ?? 0));

            $trend = $spreadsheet->createSheet();
            $trend->setTitle('Daily Trends');
            $trend->fromArray(array('Date', 'Searches', 'Zero Results', 'Snapshot Sent'), null, 'A1');
            $row_index = 2;
            foreach ((array) ($data['trend'] ?? array()) as $row) {
                $trend->fromArray(
                    array(
                        (string) ($row['rollup_date'] ?? ''),
                        (int) ($row['searches'] ?? 0),
                        (int) ($row['zero_results'] ?? 0),
                        (int) ($row['snapshot_sent'] ?? 0)
                    ),
                    null,
                    'A' . $row_index
                );
                $row_index++;
            }

            $queries = $spreadsheet->createSheet();
            $queries->setTitle('Top Queries');
            $queries->fromArray(array('Query', 'Count'), null, 'A1');
            $row_index = 2;
            foreach ((array) ($data['top_queries'] ?? array()) as $row) {
                $queries->fromArray(
                    array(
                        (string) ($row['query_text'] ?? ''),
                        (int) ($row['total'] ?? 0)
                    ),
                    null,
                    'A' . $row_index
                );
                $row_index++;
            }

            $shared = $spreadsheet->createSheet();
            $shared->setTitle('Top Shared Resources');
            $shared->fromArray(array('Resource ID', 'Resource Name', 'Sends'), null, 'A1');
            $row_index = 2;
            foreach ((array) ($data['top_shared_resources'] ?? array()) as $row) {
                $shared->fromArray(
                    array(
                        (int) ($row['resource_id'] ?? 0),
                        (string) ($row['resource_name'] ?? ''),
                        (int) ($row['sent_count'] ?? 0)
                    ),
                    null,
                    'A' . $row_index
                );
                $row_index++;
            }

            $geo = $spreadsheet->createSheet();
            $geo->setTitle('Geography Summary');
            $geo->fromArray(array('Geography', 'Searches', 'Zero Results', 'Snapshot Sent', 'Trend Delta (%)'), null, 'A1');
            $row_index = 2;
            foreach ((array) ($data['geography_summary'] ?? array()) as $row) {
                $geo->fromArray(
                    array(
                        (string) ($row['geography_label'] ?? $row['geography_slug'] ?? ''),
                        (int) ($row['searches'] ?? 0),
                        (int) ($row['zero_results'] ?? 0),
                        (int) ($row['snapshot_sent'] ?? 0),
                        isset($row['trend_delta_pct']) ? (float) $row['trend_delta_pct'] : ''
                    ),
                    null,
                    'A' . $row_index
                );
                $row_index++;
            }

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                foreach (range('A', 'E') as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $content = ob_get_clean();

            return (string) $content;
        } catch (Exception $e) {
            return new WP_Error('xlsx_export_failed', $e->getMessage());
        }
    }

    /**
     * PDF writer.
     *
     * @param array $data
     * @return string|WP_Error
     */
    private function build_pdf($data) {
        if (!class_exists('TCPDF')) {
            return new WP_Error('missing_dependency', 'TCPDF is required for PDF exports.');
        }

        try {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
            $pdf->SetCreator('SVDP Resources Plugin');
            $pdf->SetAuthor('SVdP Resources');
            $pdf->SetTitle('Resources Analytics');
            $pdf->SetMargins(12, 12, 12);
            $pdf->SetAutoPageBreak(true, 12);
            $pdf->AddPage();

            $filters = isset($data['filters']) ? $data['filters'] : array();
            $kpis = isset($data['kpis']) ? $data['kpis'] : array();

            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'Resources Analytics', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 6, sprintf('Date range: %s to %s', (string) ($filters['start_date'] ?? ''), (string) ($filters['end_date'] ?? '')), 0, 1, 'L');
            $pdf->Cell(0, 6, sprintf('Segment: %s | Geography: %s | Channel: %s', (string) ($filters['segment'] ?? 'all'), (string) ($filters['geography'] ?? 'all'), (string) ($filters['channel'] ?? 'all')), 0, 1, 'L');
            $pdf->Ln(2);

            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 7, 'KPI Summary', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 6, 'Searches: ' . (int) ($kpis['searches'] ?? 0), 0, 1, 'L');
            $pdf->Cell(0, 6, 'Zero Result Rate: ' . (float) ($kpis['zero_result_rate'] ?? 0) . '%', 0, 1, 'L');
            $pdf->Cell(0, 6, 'Snapshot Sends: ' . (int) ($kpis['snapshot_sends'] ?? 0), 0, 1, 'L');
            $pdf->Cell(0, 6, 'Send Success Rate: ' . (float) ($kpis['send_success_rate'] ?? 0) . '%', 0, 1, 'L');
            $pdf->Ln(2);

            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 7, 'Top Queries', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 9);
            foreach (array_slice((array) ($data['top_queries'] ?? array()), 0, 8) as $row) {
                $query = trim((string) ($row['query_text'] ?? ''));
                if ($query === '') {
                    continue;
                }
                $pdf->MultiCell(0, 5, '- ' . $query . ' (' . (int) ($row['total'] ?? 0) . ')', 0, 'L', false, 1);
            }

            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 7, 'Geography Summary', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(62, 6, 'Geography', 1, 0, 'L', true);
            $pdf->Cell(24, 6, 'Searches', 1, 0, 'R', true);
            $pdf->Cell(32, 6, 'Zero Results', 1, 0, 'R', true);
            $pdf->Cell(32, 6, 'Snapshot Sent', 1, 0, 'R', true);
            $pdf->Cell(28, 6, 'Delta %', 1, 1, 'R', true);

            foreach (array_slice((array) ($data['geography_summary'] ?? array()), 0, 15) as $row) {
                $pdf->Cell(62, 6, (string) ($row['geography_label'] ?? $row['geography_slug'] ?? ''), 1, 0, 'L');
                $pdf->Cell(24, 6, (string) (int) ($row['searches'] ?? 0), 1, 0, 'R');
                $pdf->Cell(32, 6, (string) (int) ($row['zero_results'] ?? 0), 1, 0, 'R');
                $pdf->Cell(32, 6, (string) (int) ($row['snapshot_sent'] ?? 0), 1, 0, 'R');
                $delta = isset($row['trend_delta_pct']) ? (string) $row['trend_delta_pct'] . '%' : '-';
                $pdf->Cell(28, 6, $delta, 1, 1, 'R');
            }

            return $pdf->Output('', 'S');
        } catch (Exception $e) {
            return new WP_Error('pdf_export_failed', $e->getMessage());
        }
    }

    /**
     * Stream file content and exit.
     *
     * @param string $content
     * @param string $filename
     * @param string $mime
     * @return void
     */
    private function stream_file($content, $filename, $mime) {
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen((string) $content));

        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Analytics export capability check.
     *
     * @return bool
     */
    private static function current_user_can_export() {
        $cap = monday_resources_get_analytics_export_capability();
        return current_user_can($cap) || current_user_can('manage_options');
    }
}
