<?php
/**
 * Shortcode Display Class
 */

class Monday_Resources_Shortcode {

    const DEFAULT_PER_PAGE = 25;
    private static $snapshot_actions_rendered = false;
    private static $resource_modals_rendered = false;

    public function __construct() {
        add_shortcode('monday_resources', array($this, 'display_resources'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_filter_resources', array($this, 'filter_resources_ajax'));
        add_action('wp_ajax_nopriv_filter_resources', array($this, 'filter_resources_ajax'));
    }

    /**
     * Enqueue frontend scripts and styles.
     */
    public function enqueue_scripts() {
        $post = get_post();
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'monday_resources')) {
            return;
        }

        wp_enqueue_style(
            'monday-resources-modal',
            MONDAY_RESOURCES_PLUGIN_URL . 'assets/css/modal.css',
            array(),
            MONDAY_RESOURCES_VERSION
        );

        wp_enqueue_script(
            'monday-resources-frontend',
            MONDAY_RESOURCES_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            MONDAY_RESOURCES_VERSION,
            true
        );

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $share_cap = class_exists('Resource_Snapshot_Manager')
            ? Resource_Snapshot_Manager::issue_share_cap_for_request($request_uri)
            : '';
        $snapshot_cap = function_exists('monday_resources_get_snapshot_capability')
            ? monday_resources_get_snapshot_capability()
            : 'edit_view_resources';
        $manage_cap = function_exists('monday_resources_get_manage_capability')
            ? monday_resources_get_manage_capability()
            : 'manage_options';
        $can_snapshot = current_user_can($snapshot_cap) || current_user_can($manage_cap) || !empty($share_cap);
        $can_inline_edit = self::current_user_can_inline_edit();
        $twilio_enabled = class_exists('Resource_Snapshot_Manager') && Resource_Snapshot_Manager::is_twilio_configured();

        wp_localize_script('monday-resources-frontend', 'mondayResources', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('monday_resources_nonce'),
            'serviceAreas' => Resource_Taxonomy::get_service_area_terms(),
            'servicesOffered' => Resource_Taxonomy::get_services_offered_terms(),
            'providerTypes' => Resource_Taxonomy::get_provider_type_terms(),
            'populations' => get_option('resource_target_population_options', array()),
            'perPage' => self::DEFAULT_PER_PAGE,
            'shareCap' => $share_cap,
            'sharedRouteBase' => home_url('/resources/shared/'),
            'canSnapshot' => $can_snapshot,
            'canInlineEdit' => $can_inline_edit,
            'twilioEnabled' => $twilio_enabled
        ));
    }

    /**
     * Display resources shortcode.
     *
     * @param array $atts
     * @return string
     */
    public function display_resources($atts) {
        $atts = shortcode_atts(array(
            'geography' => '',
            'service_type' => ''
        ), $atts);

        $prefilters = $this->build_prefilters_from_shortcode($atts);

        $filters = array_merge($prefilters, array(
            'page' => 1,
            'per_page' => self::DEFAULT_PER_PAGE
        ));

        $result = Resources_Manager::get_resources_paginated($filters);
        $items = isset($result['items']) ? $result['items'] : array();
        $total_count = isset($result['total_count']) ? (int) $result['total_count'] : 0;
        $has_more = (self::DEFAULT_PER_PAGE < $total_count);
        $allow_inline_edit = self::current_user_can_inline_edit();

        $service_area_terms = Resource_Taxonomy::get_service_area_terms();
        $services_offered_terms = Resource_Taxonomy::get_services_offered_terms();
        $provider_type_terms = Resource_Taxonomy::get_provider_type_terms();
        $population_terms = get_option('resource_target_population_options', array());
        $show_snapshot_actions = !self::$snapshot_actions_rendered;
        if ($show_snapshot_actions) {
            self::$snapshot_actions_rendered = true;
        }
        $show_resource_modals = !self::$resource_modals_rendered;
        if ($show_resource_modals) {
            self::$resource_modals_rendered = true;
        }

        ob_start();
        ?>
        <style>
            body {
                overflow-x: hidden;
            }
            .resources-container {
                max-width: 1200px;
                width: 100%;
                margin: 0 auto;
                padding: 0 20px;
                box-sizing: border-box;
                overflow-x: hidden;
                position: relative;
                font-size: 16px;
            }
            .resources-container * {
                box-sizing: border-box;
                max-width: 100%;
            }
            .directory-title {
                margin: 0 0 16px;
                font-size: 1.9em;
                color: #1f2933;
            }
            .submit-resource-btn {
                display: inline-block;
                padding: 12px 24px;
                margin-bottom: 20px;
                background-color: #0073aa;
                color: #fff;
                text-decoration: none;
                border-radius: 4px;
                font-weight: 600;
                border: none;
                cursor: pointer;
                font-size: 16px;
                min-height: 44px;
            }
            .submit-resource-btn:hover {
                background-color: #005177;
                color: #fff;
            }
            .snapshot-actions-panel {
                border: 1px solid #dbe1ea;
                border-radius: 10px;
                padding: 14px;
                margin: 4px 0 16px;
                background: #f8fbff;
            }
            .snapshot-actions-panel h3 {
                margin: 0 0 8px;
                font-size: 1.12rem;
                color: #0f172a;
            }
            .snapshot-actions-help {
                margin: 0 0 10px;
                color: #4b5563;
                font-size: 0.96rem;
            }
            .snapshot-actions-grid {
                display: grid;
                gap: 10px;
                grid-template-columns: 1fr;
            }
            .snapshot-actions-grid label {
                display: block;
                font-weight: 600;
                margin-bottom: 4px;
                color: #1f2933;
            }
            .snapshot-actions-grid input {
                width: 100%;
                min-height: 44px;
                border: 2px solid #d2d6dc;
                border-radius: 6px;
                padding: 10px 12px;
                font-size: 16px;
            }
            .snapshot-action-buttons {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
            }
            .snapshot-action-btn {
                min-height: 44px;
                border-radius: 6px;
                border: 2px solid #0073aa;
                background: #0073aa;
                color: #fff;
                font-weight: 600;
                cursor: pointer;
                font-size: 0.95rem;
            }
            .snapshot-action-btn.secondary {
                background: #fff;
                color: #005177;
            }
            .snapshot-action-btn.is-disabled,
            .snapshot-action-btn[disabled].is-disabled {
                background: #f3f4f6;
                color: #6b7280;
                border-color: #d1d5db;
                cursor: not-allowed;
            }
            .snapshot-action-message {
                margin-top: 8px;
                font-size: 0.95rem;
                color: #1f2933;
                min-height: 1.2em;
            }
            .snapshot-action-message.error {
                color: #b91c1c;
            }
            .snapshot-action-message.success {
                color: #166534;
            }
            .service-area-tiles {
                display: grid;
                gap: 10px;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                margin-bottom: 18px;
            }
            .service-area-tile {
                border: 2px solid #d2d6dc;
                border-radius: 10px;
                background: #fff;
                color: #111827;
                text-align: left;
                padding: 12px;
                min-height: 44px;
                font-size: 1rem;
                cursor: pointer;
            }
            .service-area-tile.is-selected {
                border-color: #0073aa;
                background: #e8f3fa;
                color: #005177;
                font-weight: 700;
            }
            .service-area-tile:focus,
            .resources-search input:focus,
            .narrow-results-btn:focus,
            .load-more-btn:focus,
            .resource-toggle-button:focus,
            .resource-report-btn:focus {
                outline: 3px solid rgba(0, 115, 170, 0.35);
                outline-offset: 1px;
            }
            .resources-search {
                margin-bottom: 14px;
            }
            .resources-search label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #333;
            }
            .resources-search input {
                width: 100%;
                max-width: 640px;
                padding: 12px;
                font-size: 16px;
                border: 2px solid #d2d6dc;
                border-radius: 6px;
                min-height: 44px;
            }
            .narrow-results-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 44px;
                padding: 10px 16px;
                border-radius: 6px;
                border: 2px solid #0073aa;
                background: #fff;
                color: #005177;
                font-weight: 600;
                cursor: pointer;
                margin-bottom: 16px;
            }
            .results-count {
                margin: 10px 0;
                color: #4b5563;
                font-size: 1rem;
            }
            .filter-loading {
                display: none;
                margin: 0 0 12px;
                color: #1f2933;
                font-weight: 600;
            }
            .filter-loading.is-visible {
                display: block;
            }
            .resources-grid {
                display: grid;
                grid-template-columns: 1fr;
                gap: 25px;
                margin-top: 20px;
                justify-content: center;
            }
            @media (min-width: 900px) {
                .resources-grid {
                    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                }
                .service-area-tiles {
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                }
            }
            .svdp-badge {
                display: inline-block;
                background-color: #0073aa;
                color: #fff;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 0.75em;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 10px;
            }
            .partner-divider {
                grid-column: 1 / -1;
                margin: 30px 0;
                padding: 15px 10px;
                border-top: 3px solid #0073aa;
                border-bottom: 3px solid #0073aa;
                text-align: center;
                font-size: 1.2em;
                font-weight: 600;
                color: #0073aa;
                background-color: #f8f9fa;
            }
            .resource-card {
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 28px;
                background: #fff;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                transition: box-shadow 0.3s ease;
                width: 100%;
                max-width: 100%;
                overflow-wrap: break-word;
                word-wrap: break-word;
            }
            .resource-card:hover {
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            }
            .resource-card.is-unavailable {
                opacity: 0.68;
                background: #f8fafc;
                border-color: #d7dde6;
            }
            .resource-unavailable-badge {
                display: inline-block;
                background: #f3f4f6;
                color: #374151;
                border-radius: 999px;
                padding: 4px 10px;
                font-size: 0.75rem;
                font-weight: 700;
                margin-bottom: 10px;
                text-transform: uppercase;
                letter-spacing: 0.4px;
            }
            .resource-card h3 {
                margin: 0 0 5px 0;
                font-size: 1.3em;
                color: #333;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
            }
            .resource-organization {
                margin: 0 0 15px 0;
                padding-bottom: 10px;
                font-size: 0.95em;
                color: #555;
                font-style: italic;
                border-bottom: 1px solid #eee;
            }
            .resource-field {
                margin-bottom: 20px;
            }
            .resource-field-label {
                font-weight: bold;
                color: #666;
                font-size: 0.9em;
                display: block;
                margin-bottom: 6px;
                line-height: 1.5;
            }
            .resource-field-value {
                color: #333;
                font-size: 1em;
                line-height: 1.6;
                word-wrap: break-word;
                overflow-wrap: break-word;
                word-break: break-word;
            }
            .resource-field-value a {
                color: #0073aa;
                text-decoration: none;
                word-break: break-all;
                overflow-wrap: break-word;
            }
            .resource-field-value a:hover {
                text-decoration: underline;
            }
            .resource-section {
                margin-bottom: 25px;
                padding-bottom: 20px;
                border-bottom: 1px solid #eee;
            }
            .resource-section:last-child {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }
            .resource-section-heading {
                font-weight: 700;
                font-size: 1.05em;
                color: #0073aa;
                margin-bottom: 15px;
                padding-bottom: 8px;
                border-bottom: 2px solid #e0e0e0;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .resource-hours {
                margin: 15px 0;
            }
            .hours-special-flag {
                display: inline-block;
                padding: 8px 12px;
                background-color: #f0f0f0;
                border-radius: 4px;
                font-weight: 600;
                margin: 5px 0;
                font-size: 0.95em;
            }
            .hours-24-7 {
                background-color: #d4edda;
                color: #155724;
            }
            .hours-closed {
                background-color: #f8d7da;
                color: #721c24;
            }
            .hours-breakdown {
                line-height: 1.8;
            }
            .hours-section {
                margin: 10px 0;
            }
            .hours-section strong {
                display: block;
                margin-bottom: 4px;
                color: #444;
            }
            .hours-notes {
                margin-top: 10px;
                padding-top: 8px;
                border-top: 1px dashed #ddd;
                font-size: 0.9em;
                color: #666;
            }
            .resource-details-hidden {
                display: none;
            }
            .resource-toggle {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #eee;
            }
            .resource-toggle-button {
                background: none;
                border: none;
                color: #0073aa;
                cursor: pointer;
                font-size: 0.95em;
                padding: 0;
                text-decoration: underline;
                min-height: 44px;
            }
            .resource-inline-edit-toggle {
                background: none;
                border: none;
                color: #0073aa;
                text-decoration: underline;
                min-height: 44px;
                cursor: pointer;
                font-size: 0.95em;
                padding: 0;
                margin-top: 4px;
            }
            .resource-inline-edit-panel {
                display: none;
                margin-top: 10px;
                padding: 12px;
                border: 1px solid #dbe1ea;
                border-radius: 8px;
                background: #f9fbff;
            }
            .resource-inline-edit-panel.is-open {
                display: block;
            }
            .inline-edit-row {
                margin-bottom: 10px;
            }
            .inline-edit-row label {
                display: block;
                font-weight: 600;
                margin-bottom: 4px;
                color: #1f2933;
            }
            .inline-edit-row input,
            .inline-edit-row select,
            .inline-edit-row textarea {
                width: 100%;
                min-height: 40px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                padding: 8px 10px;
                font-size: 15px;
            }
            .inline-edit-row textarea {
                min-height: 88px;
                resize: vertical;
            }
            .inline-edit-actions {
                display: flex;
                gap: 8px;
                margin-top: 6px;
            }
            .inline-edit-actions button {
                min-height: 40px;
                border-radius: 6px;
                border: none;
                cursor: pointer;
                padding: 8px 12px;
                font-weight: 600;
            }
            .inline-edit-save {
                background: #0073aa;
                color: #fff;
            }
            .inline-edit-cancel {
                background: #e5e7eb;
                color: #111827;
            }
            .inline-edit-message {
                margin-top: 8px;
                font-size: 0.92rem;
                min-height: 1.2em;
                color: #1f2933;
            }
            .inline-edit-message.error {
                color: #b91c1c;
            }
            .inline-edit-message.success {
                color: #166534;
            }
            .resource-report-btn {
                background-color: #dc3232;
                color: #fff;
                border: none;
                padding: 8px 16px;
                margin-top: 10px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 0.9em;
                transition: background-color 0.3s ease;
                min-height: 44px;
            }
            .resource-report-btn:hover {
                background-color: #a02222;
            }
            .resource-verification-status {
                margin: 10px 0;
            }
            .verification-badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 0.85em;
                font-weight: 500;
            }
            .verification-badge.fresh {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .verification-badge.aging {
                background-color: #fff3cd;
                color: #856404;
                border: 1px solid #ffeaa7;
            }
            .verification-badge.stale {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            .verification-badge.unverified {
                background-color: #e2e3e5;
                color: #383d41;
                border: 1px solid #d6d8db;
            }
            .no-results {
                grid-column: 1 / -1;
                text-align: center;
                padding: 40px;
                color: #666;
                font-size: 1.1em;
            }
            .load-more-wrap {
                margin-top: 20px;
                text-align: center;
            }
            .load-more-btn {
                min-height: 44px;
                border: 2px solid #0073aa;
                background: #0073aa;
                color: #fff;
                border-radius: 6px;
                padding: 10px 16px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
            }
            .load-more-btn[disabled] {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .narrow-sheet {
                display: none;
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 100001;
                background: rgba(15, 23, 42, 0.5);
                height: 100%;
                align-items: flex-end;
            }
            .narrow-sheet.is-open {
                display: flex;
            }
            .narrow-sheet-panel {
                background: #fff;
                border-radius: 12px 12px 0 0;
                width: 100%;
                max-height: 90vh;
                overflow-y: auto;
                padding: 16px;
            }
            .narrow-sheet-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }
            .narrow-sheet-title {
                margin: 0;
                font-size: 1.25rem;
                color: #111827;
            }
            .sheet-close-btn {
                min-height: 44px;
                border: none;
                background: transparent;
                color: #374151;
                font-size: 1rem;
                cursor: pointer;
            }
            .sheet-section {
                margin: 12px 0;
                border-top: 1px solid #e5e7eb;
                padding-top: 12px;
            }
            .sheet-section h4 {
                margin: 0 0 8px;
                color: #111827;
            }
            .sheet-search {
                width: 100%;
                min-height: 44px;
                padding: 10px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                margin-bottom: 8px;
                font-size: 16px;
            }
            .sheet-option-list {
                display: grid;
                gap: 6px;
                max-height: 220px;
                overflow-y: auto;
                padding-right: 6px;
            }
            .sheet-option {
                display: flex;
                align-items: center;
                gap: 8px;
                min-height: 44px;
                padding: 6px 8px;
                border-radius: 6px;
                border: 1px solid #e5e7eb;
            }
            .sheet-option input {
                width: 20px;
                height: 20px;
                margin: 0;
            }
            .sheet-provider-toggle {
                width: 100%;
                min-height: 44px;
                border: none;
                text-align: left;
                background: #f3f4f6;
                color: #111827;
                border-radius: 6px;
                padding: 10px;
                font-weight: 600;
                cursor: pointer;
            }
            .sheet-provider-content {
                margin-top: 8px;
                display: none;
            }
            .sheet-provider-content.is-open {
                display: block;
            }
            .sheet-actions {
                display: flex;
                gap: 8px;
                margin-top: 14px;
            }
            .sheet-actions button {
                flex: 1;
                min-height: 44px;
                border-radius: 6px;
                border: none;
                font-size: 1rem;
                cursor: pointer;
            }
            .sheet-clear-btn {
                background: #e5e7eb;
                color: #111827;
            }
            .sheet-apply-btn {
                background: #0073aa;
                color: #fff;
            }
            .selection-warning {
                margin-top: 6px;
                color: #b45309;
                font-size: 0.95rem;
                display: none;
            }
            .selection-warning.is-visible {
                display: block;
            }
            @media (max-width: 768px) {
                .resources-container {
                    padding: 0 12px;
                }
                .resources-grid {
                    gap: 10px;
                }
                .resource-card {
                    padding: 20px;
                }
                .snapshot-action-buttons {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <div class="resources-container" id="svdp-resources-directory" data-prefilters="<?php echo esc_attr(wp_json_encode($prefilters)); ?>">
            <h2 class="directory-title">Find Help For Someone</h2>

            <button class="submit-resource-btn" onclick="openSubmitResourceModal()">Submit a New Resource</button>

            <?php if ($show_snapshot_actions): ?>
                <div class="snapshot-actions-panel" id="snapshot-actions-panel">
                    <h3>Share This Resource List</h3>
                    <p class="snapshot-actions-help">Create a shareable snapshot of the currently visible resources, then print, email, or text it.</p>
                    <div class="snapshot-actions-grid">
                        <div>
                            <label for="snapshot-neighbor-name">Neighbor Name</label>
                            <input type="text" id="snapshot-neighbor-name" placeholder="Neighbor name">
                        </div>
                        <div>
                            <label for="snapshot-contact-value">Email or Mobile (for Email/Text)</label>
                            <input type="text" id="snapshot-contact-value" placeholder="name@example.com or (260) 555-1234">
                        </div>
                        <div class="snapshot-action-buttons">
                            <button type="button" class="snapshot-action-btn secondary" id="snapshot-print-btn">Print</button>
                            <button type="button" class="snapshot-action-btn" id="snapshot-email-btn">Email</button>
                            <button type="button" class="snapshot-action-btn" id="snapshot-text-btn">Text This List</button>
                        </div>
                    </div>
                    <div id="snapshot-action-message" class="snapshot-action-message" aria-live="polite"></div>
                </div>
            <?php endif; ?>

            <div class="service-area-tiles" id="service-area-tiles">
                <?php foreach ($service_area_terms as $slug => $label): ?>
                    <button type="button" class="service-area-tile" data-service-area="<?php echo esc_attr($slug); ?>">
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="resources-search">
                <label for="resource-search">Search (Optional)</label>
                <input type="text" id="resource-search" placeholder="Type what you're looking for (e.g., trustee, rent help, food)..." autocomplete="off">
            </div>

            <button type="button" class="narrow-results-btn" id="narrow-results-btn">Narrow Results</button>

            <div class="filter-loading" id="resources-filter-loading" aria-live="polite">Updating results...</div>

            <div class="results-count" id="results-count">
                Showing <span id="visible-count"><?php echo esc_html((string) min(self::DEFAULT_PER_PAGE, $total_count)); ?></span> of <span id="total-count"><?php echo esc_html((string) $total_count); ?></span> resources
            </div>

            <div class="resources-grid" id="resources-grid">
                <?php echo self::render_resources_grid_html($items, 0, array('allow_inline_edit' => $allow_inline_edit)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>

            <div class="load-more-wrap">
                <button
                    type="button"
                    class="load-more-btn"
                    id="resources-load-more"
                    <?php echo !$has_more ? 'style="display:none"' : ''; ?>>
                    Load More
                </button>
            </div>

            <div class="narrow-sheet" id="narrow-sheet" aria-hidden="true">
                <div class="narrow-sheet-panel" role="dialog" aria-label="Narrow results">
                    <div class="narrow-sheet-header">
                        <h3 class="narrow-sheet-title">Narrow Results</h3>
                        <button type="button" class="sheet-close-btn" id="narrow-sheet-close">Close</button>
                    </div>

                    <div class="sheet-section">
                        <h4>Services Offered</h4>
                        <input type="text" class="sheet-search" id="services-offered-search" placeholder="Search services offered...">
                        <div class="sheet-option-list" id="services-offered-options">
                            <?php foreach ($services_offered_terms as $slug => $label): ?>
                                <label class="sheet-option" data-filter-text="<?php echo esc_attr(strtolower($label)); ?>">
                                    <input type="checkbox" value="<?php echo esc_attr($slug); ?>" class="services-offered-input">
                                    <span><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="selection-warning" id="services-warning">Heads up: selecting more than 5 services may broaden results significantly.</div>
                    </div>

                    <div class="sheet-section">
                        <h4>Target Population</h4>
                        <input type="text" class="sheet-search" id="population-search" placeholder="Search population...">
                        <div class="sheet-option-list" id="population-options">
                            <?php foreach ($population_terms as $population): ?>
                                <label class="sheet-option" data-filter-text="<?php echo esc_attr(strtolower($population)); ?>">
                                    <input type="checkbox" value="<?php echo esc_attr($population); ?>" class="population-input">
                                    <span><?php echo esc_html($population); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="sheet-section">
                        <button type="button" class="sheet-provider-toggle" id="provider-type-toggle">System Type (rare)</button>
                        <div class="sheet-provider-content" id="provider-type-content">
                            <div class="sheet-option-list">
                                <label class="sheet-option">
                                    <input type="radio" name="provider_type" value="" class="provider-type-input" checked>
                                    <span>Any System Type</span>
                                </label>
                                <?php foreach ($provider_type_terms as $slug => $label): ?>
                                    <label class="sheet-option">
                                        <input type="radio" name="provider_type" value="<?php echo esc_attr($slug); ?>" class="provider-type-input">
                                        <span><?php echo esc_html($label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="sheet-actions">
                        <button type="button" class="sheet-clear-btn" id="narrow-clear-btn">Clear</button>
                        <button type="button" class="sheet-apply-btn" id="narrow-apply-btn">Apply</button>
                    </div>
                </div>
            </div>
        </div>

        <?php
        if ($show_resource_modals) {
            include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/report-issue-modal.php';
            include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/submit-resource-modal.php';
        }
        ?>

        <script>
            function toggleDetails(index) {
                const details = document.getElementById('details-' + index);
                const button = document.getElementById('toggle-' + index);

                if (!details || !button) {
                    return;
                }

                if (details.style.display === 'none' || details.style.display === '') {
                    details.style.display = 'block';
                    details.setAttribute('aria-hidden', 'false');
                    button.setAttribute('aria-expanded', 'true');
                    button.textContent = 'Hide Details';
                } else {
                    details.style.display = 'none';
                    details.setAttribute('aria-hidden', 'true');
                    button.setAttribute('aria-expanded', 'false');
                    button.textContent = 'Show Full Details';
                }
            }
        </script>
        <?php

        return ob_get_clean();
    }

    /**
     * Shared renderer for resource card grid HTML.
     *
     * @param array $items
     * @param int $render_offset
     * @param array $options
     * @return string
     */
    public static function render_resources_grid_html($items, $render_offset = 0, $options = array()) {
        $always_visible = self::get_always_visible_fields();
        $hidden_details = self::get_hidden_details_fields();
        $options = wp_parse_args($options, array(
            'shared_snapshot' => false,
            'allow_inline_edit' => false
        ));
        $is_shared_snapshot = !empty($options['shared_snapshot']);
        $allow_inline_edit = !$is_shared_snapshot && !empty($options['allow_inline_edit']);
        $inline_service_area_terms = $allow_inline_edit ? Resource_Taxonomy::get_service_area_terms() : array();
        $inline_provider_type_terms = $allow_inline_edit ? Resource_Taxonomy::get_provider_type_terms() : array();

        if (empty($items)) {
            return '<div class="no-results">No resources found for the selected filters.</div>';
        }

        ob_start();

        $previous_was_svdp = true;
        foreach ($items as $index => $item):
            $render_index = $render_offset + $index;
            $is_svdp = !empty($item['is_svdp']) && (int) $item['is_svdp'] === 1;

            if ($previous_was_svdp && !$is_svdp): ?>
                <div class="partner-divider">Partner Resources</div>
            <?php
            endif;
            $previous_was_svdp = $is_svdp;

            $searchable_text = strtolower((string) $item['resource_name']);
            foreach ($item as $key => $value) {
                if (!is_numeric($key) && !in_array($key, array('id', 'created_at', 'updated_at', 'status'), true)) {
                    $searchable_text .= ' ' . strtolower((string) $value);
                }
            }

            $service_area_label = Resource_Taxonomy::get_service_area_label(isset($item['service_area']) ? $item['service_area'] : '');
            $services_offered_labels = Resource_Taxonomy::get_services_offered_labels_from_pipe(isset($item['services_offered']) ? $item['services_offered'] : '');
            $services_offered_input = implode(', ', $services_offered_labels);
            $combined_services = trim(strtolower($service_area_label . ' ' . implode(' ', $services_offered_labels)));
            $target_population = !empty($item['target_population']) ? strtolower((string) $item['target_population']) : '';
            $resource_id = isset($item['id']) ? (int) $item['id'] : 0;
            $service_area_slug = isset($item['service_area']) ? Resource_Taxonomy::normalize_service_area_slug($item['service_area']) : '';
            $provider_type_slug = isset($item['provider_type']) ? Resource_Taxonomy::normalize_provider_type_slug($item['provider_type']) : '';
            $is_unavailable = $is_shared_snapshot && (
                !empty($item['_snapshot_unavailable']) ||
                (isset($item['status']) && (string) $item['status'] !== 'active')
            );
            $card_classes = 'resource-card' . ($is_unavailable ? ' is-unavailable' : '');
            ?>
            <div class="<?php echo esc_attr($card_classes); ?>" data-resource-id="<?php echo esc_attr((string) $resource_id); ?>" data-search="<?php echo esc_attr($searchable_text); ?>" data-category="<?php echo esc_attr($combined_services); ?>" data-audience="<?php echo esc_attr($target_population); ?>" data-is-svdp="<?php echo $is_svdp ? '1' : '0'; ?>">
                <?php if ($is_unavailable): ?>
                    <span class="resource-unavailable-badge">No longer available</span>
                <?php endif; ?>
                <?php if ($is_svdp): ?>
                    <span class="svdp-badge">SVdP Resource</span>
                <?php endif; ?>

                <h3><?php echo esc_html($item['resource_name']); ?></h3>

                <?php if (!empty($item['organization'])): ?>
                    <?php $org_value = $is_unavailable ? esc_html((string) $item['organization']) : Resources_Manager::format_column_value($item['organization']); ?>
                    <div class="resource-organization"><?php echo $org_value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                <?php endif; ?>

                <?php
                $status = !empty($item['verification_status']) ? $item['verification_status'] : 'unverified';
                $verified_date = !empty($item['last_verified_date']) ? $item['last_verified_date'] : null;
                $relative_time = $verified_date ? human_time_diff(strtotime($verified_date), current_time('timestamp')) : '';
                ?>
                <div class="resource-verification-status">
                    <?php if ($status === 'fresh' && $verified_date): ?>
                        <span class="verification-badge fresh">&#10003; Verified <?php echo esc_html($relative_time); ?> ago</span>
                    <?php elseif ($status === 'aging' && $verified_date): ?>
                        <span class="verification-badge aging">&#9888; Last verified <?php echo esc_html($relative_time); ?> ago</span>
                    <?php elseif ($status === 'stale' && $verified_date): ?>
                        <span class="verification-badge stale">&#9888; Information may be outdated (verified <?php echo esc_html($relative_time); ?> ago)</span>
                    <?php else: ?>
                        <span class="verification-badge unverified">Not yet verified</span>
                    <?php endif; ?>
                </div>

                <?php foreach ($always_visible as $field_name => $label): ?>
                    <?php
                    $field_value = self::get_field_value_for_display($item, $field_name);
                    if ($field_value === '') {
                        continue;
                    }

                    if ($field_name === 'phone' && !$is_unavailable) {
                        $formatted_value = Resources_Manager::format_column_value($field_value);
                    } else {
                        $formatted_value = esc_html($field_value);
                    }
                    ?>
                    <div class="resource-field">
                        <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                        <span class="resource-field-value">
                            <?php echo $formatted_value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php if ($field_name === 'phone' && !empty($item['phone_extension'])): ?>
                                <span style="font-style: italic; font-size: 0.8em; white-space: nowrap;">ext <?php echo esc_html($item['phone_extension']); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>

                <div class="resource-details-hidden" id="details-<?php echo esc_attr((string) $render_index); ?>" aria-hidden="true">
                    <?php foreach ($hidden_details as $section): ?>
                        <?php
                        $has_content = false;
                        foreach ($section['fields'] as $field_name => $label) {
                            if (self::get_field_value_for_display($item, $field_name) !== '') {
                                $has_content = true;
                                break;
                            }
                        }

                        if (!$has_content) {
                            continue;
                        }
                        ?>

                        <div class="resource-section">
                            <h4 class="resource-section-heading"><?php echo esc_html($section['label']); ?></h4>

                            <?php foreach ($section['fields'] as $field_name => $label): ?>
                                <?php
                                if ($field_name === 'organization') {
                                    continue;
                                }

                                if ($field_name === 'hours_of_operation') {
                                    $hours_data = Resource_Hours_Manager::get_hours($item['id']);
                                    if ($hours_data) {
                                        ?>
                                        <div class="resource-field resource-hours">
                                            <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                            <div class="resource-field-value">
                                                <?php if (!empty($hours_data['flags']['is_24_7'])): ?>
                                                    <div class="hours-special-flag hours-24-7">Open 24/7</div>
                                                <?php elseif (!empty($hours_data['flags']['is_by_appointment'])): ?>
                                                    <div class="hours-special-flag">By Appointment Only</div>
                                                <?php elseif (!empty($hours_data['flags']['is_call_for_availability'])): ?>
                                                    <div class="hours-special-flag">Call for Availability</div>
                                                <?php elseif (!empty($hours_data['flags']['is_currently_closed'])): ?>
                                                    <div class="hours-special-flag hours-closed">Currently Closed</div>
                                                <?php else: ?>
                                                    <div class="hours-breakdown">
                                                        <?php if (!empty($hours_data['office_hours'])): ?>
                                                            <div class="hours-section">
                                                                <strong>Office Hours:</strong>
                                                                <?php echo Resource_Hours_Manager::format_hours_display($hours_data['office_hours'], 'compact'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($hours_data['service_hours'])): ?>
                                                            <div class="hours-section">
                                                                <strong>Service Hours:</strong>
                                                                <?php echo Resource_Hours_Manager::format_hours_display($hours_data['service_hours'], 'compact'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php
                                    } elseif (!empty($item[$field_name])) {
                                        ?>
                                        <div class="resource-field">
                                            <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                            <span class="resource-field-value"><?php echo esc_html($item[$field_name]); ?></span>
                                        </div>
                                        <?php
                                    }
                                    continue;
                                }

                                $display_value = self::get_field_value_for_display($item, $field_name);
                                if ($display_value === '') {
                                    continue;
                                }

                                if (in_array($field_name, array('documents_required', 'other_eligibility'), true)) {
                                    $formatted_value = Resources_Manager::format_as_list($display_value);
                                } else {
                                    $formatted_value = Resources_Manager::format_column_value($display_value);
                                }
                                if ($is_unavailable) {
                                    $formatted_value = self::strip_links($formatted_value);
                                }
                                ?>
                                <div class="resource-field">
                                    <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                    <div class="resource-field-value"><?php echo $formatted_value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="resource-toggle">
                    <button
                        class="resource-toggle-button"
                        onclick="toggleDetails(<?php echo esc_attr((string) $render_index); ?>)"
                        id="toggle-<?php echo esc_attr((string) $render_index); ?>"
                        aria-expanded="false"
                        aria-controls="details-<?php echo esc_attr((string) $render_index); ?>">
                        Show Full Details
                    </button>
                    <?php if ($allow_inline_edit): ?>
                        <br>
                        <button
                            type="button"
                            class="resource-inline-edit-toggle"
                            data-target-id="inline-edit-panel-<?php echo esc_attr((string) $render_index); ?>"
                            aria-expanded="false"
                            aria-controls="inline-edit-panel-<?php echo esc_attr((string) $render_index); ?>">
                            Inline Edit
                        </button>
                        <div
                            class="resource-inline-edit-panel"
                            id="inline-edit-panel-<?php echo esc_attr((string) $render_index); ?>"
                            data-resource-id="<?php echo esc_attr((string) $resource_id); ?>"
                            aria-hidden="true">
                            <div class="inline-edit-row">
                                <label>Resource Name</label>
                                <input type="text" class="inline-edit-resource-name" value="<?php echo esc_attr(isset($item['resource_name']) ? (string) $item['resource_name'] : ''); ?>">
                            </div>
                            <div class="inline-edit-row">
                                <label>Organization</label>
                                <input type="text" class="inline-edit-organization" value="<?php echo esc_attr(isset($item['organization']) ? (string) $item['organization'] : ''); ?>">
                            </div>
                            <div class="inline-edit-row">
                                <label>Service Area</label>
                                <select class="inline-edit-service-area">
                                    <option value="">Select service area</option>
                                    <?php foreach ($inline_service_area_terms as $slug => $label): ?>
                                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $service_area_slug); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="inline-edit-row">
                                <label>Services Offered (comma-separated labels)</label>
                                <input type="text" class="inline-edit-services-offered" value="<?php echo esc_attr($services_offered_input); ?>">
                            </div>
                            <div class="inline-edit-row">
                                <label>System Type</label>
                                <select class="inline-edit-provider-type">
                                    <option value="">Any System Type</option>
                                    <?php foreach ($inline_provider_type_terms as $slug => $label): ?>
                                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $provider_type_slug); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="inline-edit-row">
                                <label>Phone</label>
                                <input type="text" class="inline-edit-phone" value="<?php echo esc_attr(isset($item['phone']) ? (string) $item['phone'] : ''); ?>">
                            </div>
                            <div class="inline-edit-row">
                                <label>Email</label>
                                <input type="text" class="inline-edit-email" value="<?php echo esc_attr(isset($item['email']) ? (string) $item['email'] : ''); ?>">
                            </div>
                            <div class="inline-edit-row">
                                <label>Website</label>
                                <input type="text" class="inline-edit-website" value="<?php echo esc_attr(isset($item['website']) ? (string) $item['website'] : ''); ?>">
                            </div>
                            <div class="inline-edit-row">
                                <label>Notes & Tips</label>
                                <textarea class="inline-edit-notes-and-tips"><?php echo esc_textarea(isset($item['notes_and_tips']) ? (string) $item['notes_and_tips'] : ''); ?></textarea>
                            </div>
                            <div class="inline-edit-actions">
                                <button type="button" class="inline-edit-save">Save Changes</button>
                                <button type="button" class="inline-edit-cancel">Cancel</button>
                            </div>
                            <div class="inline-edit-message" aria-live="polite"></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$is_shared_snapshot): ?>
                        <br>
                        <button class="resource-report-btn" onclick="openReportModal('<?php echo esc_js($item['resource_name']); ?>', <?php echo esc_attr((string) $render_index); ?>)">
                            Report an Issue
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach;

        return ob_get_clean();
    }

    /**
     * AJAX resource filtering endpoint.
     *
     * @return void
     */
    public function filter_resources_ajax() {
        check_ajax_referer('monday_resources_nonce', 'nonce');

        $service_area = isset($_POST['service_area']) ? sanitize_text_field(wp_unslash($_POST['service_area'])) : '';
        $provider_type = isset($_POST['provider_type']) ? sanitize_text_field(wp_unslash($_POST['provider_type'])) : '';
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';

        $services_offered = array();
        if (isset($_POST['services_offered'])) {
            $raw_services = wp_unslash($_POST['services_offered']);
            $services_offered = is_array($raw_services) ? $raw_services : array($raw_services);
            $services_offered = array_values(array_filter(array_map('sanitize_text_field', $services_offered)));
        }

        $population = array();
        if (isset($_POST['population'])) {
            $raw_population = wp_unslash($_POST['population']);
            $population = is_array($raw_population) ? $raw_population : array($raw_population);
            $population = array_values(array_filter(array_map('sanitize_text_field', $population)));
        }

        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? max(1, min(100, (int) $_POST['per_page'])) : self::DEFAULT_PER_PAGE;

        $filters = array(
            'service_area' => $service_area,
            'services_offered' => $services_offered,
            'provider_type' => $provider_type,
            'population' => $population,
            'q' => $q,
            'page' => $page,
            'per_page' => $per_page
        );

        if (isset($_POST['geography_prefilter'])) {
            $raw_geo = wp_unslash($_POST['geography_prefilter']);
            $geo_prefilters = is_array($raw_geo) ? $raw_geo : array($raw_geo);
            $geo_prefilters = array_values(array_filter(array_map('sanitize_text_field', $geo_prefilters)));
            if (!empty($geo_prefilters)) {
                $filters['geography'] = $geo_prefilters;
            }
        }

        if (isset($_POST['service_type_prefilter'])) {
            $raw_service_type = wp_unslash($_POST['service_type_prefilter']);
            $service_type_prefilters = is_array($raw_service_type) ? $raw_service_type : array($raw_service_type);
            $service_type_prefilters = array_values(array_filter(array_map('sanitize_text_field', $service_type_prefilters)));
            if (!empty($service_type_prefilters)) {
                $filters['service_type'] = $service_type_prefilters;
            }
        }

        $result = Resources_Manager::get_resources_paginated($filters);
        $items = isset($result['items']) ? $result['items'] : array();
        $total_count = isset($result['total_count']) ? (int) $result['total_count'] : 0;
        $visible_count = min($page * $per_page, $total_count);
        $has_more = $visible_count < $total_count;
        $render_offset = ($page - 1) * $per_page;
        $allow_inline_edit = self::current_user_can_inline_edit();

        wp_send_json_success(array(
            'html' => self::render_resources_grid_html($items, $render_offset, array('allow_inline_edit' => $allow_inline_edit)),
            'page' => $page,
            'per_page' => $per_page,
            'visible_count' => $visible_count,
            'total_count' => $total_count,
            'has_more' => $has_more
        ));
    }

    /**
     * Build backward-compatible shortcode prefilters.
     *
     * @param array $atts
     * @return array
     */
    private function build_prefilters_from_shortcode($atts) {
        $filters = array();

        if (!empty($atts['geography'])) {
            $geographies = array_map('trim', explode(',', $atts['geography']));
            $geographies = array_values(array_filter($geographies));
            if (!empty($geographies)) {
                $filters['geography'] = $geographies;
            }
        }

        if (!empty($atts['service_type'])) {
            $service_types = array_map('trim', explode(',', $atts['service_type']));
            $service_types = array_values(array_filter($service_types));
            if (!empty($service_types)) {
                $filters['service_type'] = $service_types;
            }
        }

        return $filters;
    }

    /**
     * Check whether current user can use inline edit controls.
     *
     * @return bool
     */
    private static function current_user_can_inline_edit() {
        $manage_cap = function_exists('monday_resources_get_manage_capability')
            ? monday_resources_get_manage_capability()
            : 'manage_options';

        return current_user_can($manage_cap) || current_user_can('manage_options');
    }

    /**
     * Always-visible fields in card body.
     *
     * @return array
     */
    private static function get_always_visible_fields() {
        return array(
            'service_area' => 'Service Area',
            'phone' => 'Phone',
            'target_population' => 'Target Population'
        );
    }

    /**
     * Hidden detail sections in card body.
     *
     * @return array
     */
    private static function get_hidden_details_fields() {
        return array(
            'contact_info' => array(
                'label' => 'Contact Information',
                'fields' => array(
                    'alternate_phone' => 'Alternate Phone',
                    'email' => 'Email',
                    'website' => 'Website'
                )
            ),
            'location_hours' => array(
                'label' => 'Location & Hours',
                'fields' => array(
                    'physical_address' => 'Physical Address',
                    'counties_served' => 'Counties Served',
                    'hours_of_operation' => 'Hours of Operation'
                )
            ),
            'eligibility' => array(
                'label' => 'Eligibility Requirements',
                'fields' => array(
                    'income_requirements' => 'Income Requirements',
                    'residency_requirements' => 'Residency Requirements',
                    'other_eligibility' => 'Other Eligibility Requirements',
                    'eligibility_notes' => 'Eligibility Notes'
                )
            ),
            'how_to_apply' => array(
                'label' => 'How to Apply',
                'fields' => array(
                    'how_to_apply' => 'Application Process',
                    'documents_required' => 'Documents Required',
                    'wait_time' => 'Typical Wait Time'
                )
            ),
            'additional_info' => array(
                'label' => 'Additional Information',
                'fields' => array(
                    'organization' => 'Organization/Agency',
                    'services_offered' => 'Services Offered',
                    'provider_type' => 'Provider Type',
                    'what_they_provide' => 'What They Provide',
                    'notes_and_tips' => 'Notes & Tips'
                )
            )
        );
    }

    /**
     * Remove outbound links from already-formatted HTML while preserving text.
     *
     * @param string $html
     * @return string
     */
    private static function strip_links($html) {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        $stripped = preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $html);
        return is_string($stripped) ? $stripped : $html;
    }

    /**
     * Get transformed field value for card display.
     *
     * @param array $item
     * @param string $field_name
     * @return string
     */
    private static function get_field_value_for_display($item, $field_name) {
        switch ($field_name) {
            case 'service_area':
                return Resource_Taxonomy::get_service_area_label(isset($item['service_area']) ? $item['service_area'] : '');
            case 'services_offered':
                $labels = Resource_Taxonomy::get_services_offered_labels_from_pipe(isset($item['services_offered']) ? $item['services_offered'] : '');
                return implode(', ', $labels);
            case 'provider_type':
                return Resource_Taxonomy::get_provider_type_label(isset($item['provider_type']) ? $item['provider_type'] : '');
            default:
                return isset($item[$field_name]) ? trim((string) $item[$field_name]) : '';
        }
    }
}
