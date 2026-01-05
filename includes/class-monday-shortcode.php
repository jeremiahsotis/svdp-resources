<?php
/**
 * Shortcode Display Class
 */

class Monday_Resources_Shortcode {

    public function __construct() {
        add_shortcode('monday_resources', array($this, 'display_resources'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        if (has_shortcode(get_post()->post_content ?? '', 'monday_resources')) {
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

            wp_localize_script('monday-resources-frontend', 'mondayResources', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('monday_resources_nonce')
            ));
        }
    }

    /**
     * Display resources shortcode
     */
    public function display_resources($atts) {
        // Extract shortcode attributes
        $atts = shortcode_atts(array(
            'geography' => '',
            'service_type' => ''
        ), $atts);
    
        // Get resources from database with optional filters
        $filters = array();
    
        // Handle geography filter - split comma-separated values into array
        if (!empty($atts['geography'])) {
            // Split by comma and trim whitespace from each value
            $geographies = array_map('trim', explode(',', $atts['geography']));
            // Remove any empty values
            $geographies = array_filter($geographies);
            
            if (!empty($geographies)) {
                $filters['geography'] = $geographies; // Pass as array
            }
        }
    
        // Handle service_type filter - also support multiple values
        if (!empty($atts['service_type'])) {
            $service_types = array_map('trim', explode(',', $atts['service_type']));
            $service_types = array_filter($service_types);
        
            if (!empty($service_types)) {
                $filters['service_type'] = $service_types; // Pass as array
            }
        }

        $items = Resources_Manager::get_all_resources($filters);

        if (!$items) {
            return '<p>Unable to load resources. Please try again later.</p>';
        }

        // Resources are already sorted by Resources_Manager::get_all_resources()
        // (SVdP first, then by service type, then by name)

        // Define which fields are always visible vs hidden
        $always_visible = array(
            'primary_service_type' => 'Resource Type',
            'phone' => 'Phone',
            'target_population' => 'Target Population'
        );

        $hidden_details = array(
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
                    'secondary_service_type' => 'Needs Met',
                    'what_they_provide' => 'What They Provide',
                    'notes_and_tips' => 'Notes & Tips'
                )
            )
        );

        // Collect unique Resource Types for dropdown (Primary only)
        $resource_types = array();
        foreach ($items as $item) {
            if (!empty($item['primary_service_type'])) {
                $type = trim($item['primary_service_type']);
                if (!empty($type) && !in_array($type, $resource_types)) {
                    $resource_types[] = $type;
                }
            }
        }
        sort($resource_types);

        // Collect unique Needs Met for dropdown (Secondary only)
        $needs_met = array();
        foreach ($items as $item) {
            if (!empty($item['secondary_service_type'])) {
                $types = array_map('trim', explode(',', $item['secondary_service_type']));
                foreach ($types as $type) {
                    if (!empty($type) && !in_array($type, $needs_met)) {
                        $needs_met[] = $type;
                    }
                }
            }
        }
        sort($needs_met);

        // Get target audiences from options (managed in Settings)
        $target_audiences = get_option('resource_target_population_options', array());

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
            }
            .resources-container * {
                box-sizing: border-box;
                max-width: 100%;
            }
            .resources-help-section {
                background-color: #f8f9fa;
                border: 2px solid #0073aa;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 25px;
            }
            .resources-help-section h2 {
                margin-top: 0;
                color: #0073aa;
                font-size: 1.4em;
                margin-bottom: 15px;
            }
            .resources-help-section p {
                font-size: 1.1em;
                line-height: 1.6;
                margin-bottom: 12px;
                color: #333;
            }
            .resources-help-section ul {
                font-size: 1.05em;
                line-height: 1.7;
                margin-left: 20px;
                color: #333;
            }
            .resources-help-section li {
                margin-bottom: 8px;
            }
            .resources-help-section strong {
                color: #0073aa;
            }
            .submit-resource-btn {
                display: inline-block;
                padding: 12px 24px;
                margin-bottom: 20px;
                background-color: #0073aa;
                color: white;
                text-decoration: none;
                border-radius: 4px;
                font-weight: 600;
                border: none;
                cursor: pointer;
                transition: background-color 0.3s ease;
                font-size: 16px;
            }
            .submit-resource-btn:hover {
                background-color: #005177;
                color: white;
                text-decoration: none;
            }
            .print-controls {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                align-items: center;
                justify-content: flex-end;
            }
            .print-button {
                display: inline-block;
                padding: 10px 18px;
                background-color: #444;
                color: #fff;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 600;
                font-size: 15px;
            }
            .print-button:hover {
                background-color: #222;
            }
            .print-options {
                display: none;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.12);
            }
            .print-options.active {
                display: inline-flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            .print-option-btn {
                padding: 8px 14px;
                border: 1px solid #ccc;
                border-radius: 4px;
                background: #f5f5f5;
                cursor: pointer;
                font-size: 14px;
            }
            .print-option-btn:hover {
                background: #e7e7e7;
            }
            .print-header {
                display: none;
                margin: 0 0 18px 0;
                padding-bottom: 12px;
                border-bottom: 2px solid #000;
            }
            .print-header-content {
                display: flex;
                align-items: center;
                gap: 14px;
            }
            .print-logo {
                max-height: 48px;
                width: auto;
            }
            .print-header-text {
                flex: 1;
            }
            .print-site-name {
                font-size: 18px;
                font-weight: 700;
            }
            .print-title {
                font-size: 20px;
                font-weight: 700;
                margin-top: 4px;
            }
            .print-date {
                font-size: 14px;
                color: #333;
                margin-top: 2px;
            }
            .resources-filters {
                background-color: #fff;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin-bottom: 20px;
            }
            .filter-group {
                margin-bottom: 15px;
            }
            .filter-group:last-child {
                margin-bottom: 0;
            }
            .filter-group label {
                display: block;
                font-weight: 600;
                font-size: 1.05em;
                margin-bottom: 8px;
                color: #333;
            }
            .resources-search input,
            .category-filter select {
                width: 100%;
                max-width: 500px;
                padding: 12px;
                font-size: 16px;
                border: 2px solid #ddd;
                border-radius: 4px;
            }
            .resources-search input:focus,
            .category-filter select:focus {
                outline: none;
                border-color: #0073aa;
                box-shadow: 0 0 0 2px rgba(0, 115, 170, 0.1);
            }
            .category-filter select {
                cursor: pointer;
            }
            .filter-row {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
                align-items: flex-start;
            }
            .filter-column {
                flex: 1;
                min-width: 250px;
            }
            .target-audience-filter {
                width: 100%;
                margin: 16px 0;
                padding: 12px 0;
                border-top: 1px solid #eee;
                border-bottom: 1px solid #eee;
            }
            .target-audience-checkboxes {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 8px;
                margin-top: 8px;
            }
            .target-audience-checkbox {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .target-audience-checkbox input[type="checkbox"] {
                width: 18px;
                height: 18px;
                cursor: pointer;
            }
            .target-audience-checkbox label {
                font-weight: normal !important;
                font-size: 0.95em;
                margin: 0;
                cursor: pointer;
                color: #333;
            }
            .svdp-badge {
                display: inline-block;
                background-color: #0073aa;
                color: white;
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
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                overflow-wrap: break-word;
                word-wrap: break-word;
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
            }
            .resource-card {
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 28px;
                background: #fff;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                transition: box-shadow 0.3s ease;
                width: 100%;
                max-width: 100%;
                overflow-wrap: break-word;
                word-wrap: break-word;
            }
            .resource-card:hover {
                box-shadow: 0 4px 8px rgba(0,0,0,0.15);
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
            /* Section Styles for Organized Expandable Area */
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
            /* Hours of Operation Styles */
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
            }
            .resource-toggle-button:hover {
                color: #005177;
            }
            .resource-report-btn {
                background-color: #dc3232;
                color: white;
                border: none;
                padding: 8px 16px;
                margin-top: 10px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 0.9em;
                transition: background-color 0.3s ease;
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
            .results-count {
                margin: 10px 0;
                color: #666;
                font-size: 0.95em;
            }
            .resources-meta {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                flex-wrap: wrap;
                margin: 10px 0;
            }
            @media (max-width: 768px) {
                .resources-container {
                    padding: 0 15px;
                }
                .resources-grid {
                    grid-template-columns: 1fr;
                    gap: 10px;
                }
                .resource-card {
                    padding: 20px;
                }
                .resources-help-section {
                    padding: 15px;
                }
                .resources-help-section h2 {
                    font-size: 1.2em;
                }
                .resources-help-section p,
                .resources-help-section ul {
                    font-size: 1em;
                }
                .resources-filters {
                    padding: 15px;
                }
                .submit-resource-btn {
                    width: 100%;
                    text-align: center;
                }
                .partner-divider {
                    font-size: 1.1em;
                    margin: 20px 0;
                    padding: 12px 8px;
                }
            }
            @media (max-width: 480px) {
                .resources-container {
                    padding: 0 10px;
                }
                .resources-grid {
                    gap: 8px;
                }
                .resource-card {
                    padding: 16px;
                }
                .resources-help-section {
                    padding: 12px;
                }
                .resources-help-section h2 {
                    font-size: 1.1em;
                }
                .resources-help-section p,
                .resources-help-section ul {
                    font-size: 0.95em;
                }
                .resources-filters {
                    padding: 12px;
                }
                .partner-divider {
                    font-size: 1em;
                    margin: 15px 0;
                    padding: 10px 5px;
                }
            }
            @media print {
                header,
                footer,
                .site-header,
                .site-footer,
                #masthead,
                #colophon,
                #site-header,
                #site-footer {
                    display: none !important;
                }
                .resources-help-section,
                .resources-filters,
                .resources-search,
                .submit-resource-btn,
                .print-controls,
                .resource-toggle,
                .resource-report-btn,
                .resource-verification-status,
                .svdp-badge,
                .partner-divider,
                .results-count,
                .no-results {
                    display: none !important;
                }
                .print-header {
                    display: block !important;
                }
                .resources-grid {
                    display: block;
                }
                .resource-card {
                    box-shadow: none;
                    border: 1px solid #444;
                    margin: 0 0 16px 0;
                    padding: 16px;
                    page-break-inside: avoid;
                }
                .resource-card h3 {
                    border-bottom: 1px solid #444;
                    padding-bottom: 6px;
                    margin-bottom: 6px;
                }
                .resource-organization {
                    border-bottom: none;
                    margin-bottom: 10px;
                    padding-bottom: 0;
                    font-style: normal;
                }
                .resource-details-hidden {
                    display: block !important;
                }
                .resource-section {
                    border: none;
                    margin: 0 0 10px 0;
                    padding: 0;
                }
                .resource-section-heading {
                    display: none;
                }
                .resource-field {
                    display: none !important;
                    margin-bottom: 10px;
                }
                .resource-field--primary_service_type,
                .resource-field--phone,
                .resource-field--email,
                .resource-field--website,
                .resource-field--physical_address,
                .resource-field--hours_of_operation {
                    display: block !important;
                }
                .resource-field-label {
                    color: #000;
                }
                .resource-field-value,
                .resource-field-value a {
                    color: #000;
                }
            }
            @media print {
                body[data-print-layout="compact"] .resource-card {
                    border: none;
                    margin: 0 0 10px 0;
                    padding: 0 0 10px 0;
                    border-bottom: 1px solid #888;
                }
                body[data-print-layout="compact"] .resource-card h3 {
                    border-bottom: none;
                    margin-bottom: 4px;
                    padding-bottom: 0;
                    font-size: 16px;
                }
                body[data-print-layout="compact"] .resource-organization {
                    margin-bottom: 6px;
                    font-size: 13px;
                }
                body[data-print-layout="compact"] .resource-field {
                    margin-bottom: 4px;
                    font-size: 12px;
                }
            }
        </style>

        <div class="resources-container">
            <?php
            $custom_logo_id = get_theme_mod('custom_logo');
            $custom_logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'full') : '';
            $site_name = get_bloginfo('name');
            $print_date = current_time('F j, Y');
            ?>
            <div class="print-header">
                <div class="print-header-content">
                    <?php if (!empty($custom_logo_url)): ?>
                        <img class="print-logo" src="<?php echo esc_url($custom_logo_url); ?>" alt="<?php echo esc_attr($site_name); ?>">
                    <?php endif; ?>
                    <div class="print-header-text">
                        <div class="print-site-name"><?php echo esc_html($site_name); ?></div>
                        <div class="print-title">Help That Meets You Where You Are</div>
                        <div class="print-date"><?php echo esc_html($print_date); ?></div>
                    </div>
                </div>
            </div>

            <!-- Helpful Instructions Section -->
            <div class="resources-help-section">
                <h2>How to Find Resources</h2>
                <p>Browse by category or search by keyword to find what you need. You can also check boxes to filter by who the resource serves (like seniors, families, or veterans). All filters are optional – use what helps you most. Click "Click for more info..." on any resource for complete details. Use the "Report an Issue" button if you find incorrect information, or the "Submit a New Resource" button below to share resources we're missing.</p>
            </div>

            <button class="submit-resource-btn" onclick="openSubmitResourceModal()">Submit a New Resource</button>

            <!-- Filter Section -->
            <div class="resources-filters">
                <div class="filter-row">
                    <div class="filter-column">
                        <div class="filter-group">
                            <label for="resource-type-filter">Filter by Resource Type (Optional)</label>
                            <select id="resource-type-filter" class="resource-type-filter">
                                <option value="">Show All Resource Types</option>
                                <?php foreach ($resource_types as $type): ?>
                                    <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="filter-column">
                        <div class="filter-group">
                            <label for="need-met-filter">Filter by Need Met (Optional)</label>
                            <select id="need-met-filter" class="need-met-filter">
                                <option value="">Show All Needs Met</option>
                                <?php foreach ($needs_met as $need): ?>
                                    <option value="<?php echo esc_attr($need); ?>"><?php echo esc_html($need); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                </div>

                <div class="filter-group target-audience-filter">
                    <label>Filter by Population Served (Optional)</label>
                    <div class="target-audience-checkboxes">
                        <?php foreach ($target_audiences as $index => $audience): ?>
                            <div class="target-audience-checkbox">
                                <input
                                    type="checkbox"
                                    id="audience-<?php echo $index; ?>"
                                    class="audience-checkbox"
                                    value="<?php echo esc_attr($audience); ?>"
                                />
                                <label for="audience-<?php echo $index; ?>"><?php echo esc_html($audience); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="filter-group resources-search">
                    <label for="resource-search">Search by Keyword (Optional)</label>
                    <input type="text" id="resource-search" placeholder="Type what you're looking for (e.g., food, rent help, medical care)..." />
                </div>
            </div>

            <div class="resources-meta">
                <div class="results-count">
                    Showing <span id="visible-count"><?php echo count($items); ?></span> of <?php echo count($items); ?> resources
                </div>
                <div class="print-controls">
                    <button class="print-button" type="button" onclick="openPrintOptions()">Print this List</button>
                    <div class="print-options" id="print-options">
                        <button class="print-option-btn" type="button" onclick="printResources('detailed')">Print Detailed</button>
                        <button class="print-option-btn" type="button" onclick="printResources('compact')">Print Compact</button>
                        <button class="print-option-btn" type="button" onclick="closePrintOptions()">Cancel</button>
                    </div>
                </div>
            </div>
            <div class="resources-grid" id="resources-grid">
                <?php
                $previous_was_svdp = true; // Assume first items are SVdP
                foreach ($items as $index => $item):
                    // Check if this is an SVdP resource
                    $is_svdp = !empty($item['is_svdp']) && $item['is_svdp'] == 1;

                    // Insert divider when transitioning from SVdP to partner resources
                    if ($previous_was_svdp && !$is_svdp): ?>
                        <div class="partner-divider">Partner Resources</div>
                    <?php
                    endif;
                    $previous_was_svdp = $is_svdp;

                    // Build searchable text from all fields
                    $searchable_text = strtolower($item['resource_name']);
                    foreach ($item as $key => $value) {
                        if (!is_numeric($key) && !in_array($key, array('id', 'created_at', 'updated_at', 'status'))) {
                            $searchable_text .= ' ' . strtolower($value);
                        }
                    }

                    // Get Resource Type and Need Met for filtering (separated)
                    $resource_type = !empty($item['primary_service_type']) ? strtolower($item['primary_service_type']) : '';
                    $needs_met = !empty($item['secondary_service_type']) ? strtolower($item['secondary_service_type']) : '';

                    // Get target audience for population filtering
                    $target_population = !empty($item['target_population']) ? strtolower($item['target_population']) : '';
                    ?>
                    <div class="resource-card" data-search="<?php echo esc_attr($searchable_text); ?>" data-resource-type="<?php echo esc_attr($resource_type); ?>" data-need-met="<?php echo esc_attr($needs_met); ?>" data-audience="<?php echo esc_attr($target_population); ?>" data-is-svdp="<?php echo $is_svdp ? '1' : '0'; ?>">
                        <?php if ($is_svdp): ?>
                            <span class="svdp-badge">SVdP Resource</span>
                        <?php endif; ?>
                        <h3><?php echo esc_html($item['resource_name']); ?></h3>

                        <!-- Organization name right below resource name -->
                        <?php if (!empty($item['organization'])): ?>
                            <?php $org_value = Resources_Manager::format_column_value($item['organization']); ?>
                            <div class="resource-organization"><?php echo $org_value; ?></div>
                        <?php endif; ?>

                        <!-- Verification status badge -->
                        <?php
                        $status = !empty($item['verification_status']) ? $item['verification_status'] : 'unverified';
                        $verified_date = $item['last_verified_date'];
                        if ($verified_date) {
                            $relative_time = human_time_diff(strtotime($verified_date), current_time('timestamp'));
                        }
                        ?>
                        <div class="resource-verification-status">
                            <?php if ($status === 'fresh' && $verified_date): ?>
                                <span class="verification-badge fresh">✓ Verified <?php echo esc_html($relative_time); ?> ago</span>
                            <?php elseif ($status === 'aging' && $verified_date): ?>
                                <span class="verification-badge aging">⚠ Last verified <?php echo esc_html($relative_time); ?> ago</span>
                            <?php elseif ($status === 'stale' && $verified_date): ?>
                                <span class="verification-badge stale">⚠ Information may be outdated (verified <?php echo esc_html($relative_time); ?> ago)</span>
                            <?php else: ?>
                                <span class="verification-badge unverified">Not yet verified</span>
                            <?php endif; ?>
                        </div>

                        <!-- Always visible fields -->
                        <?php foreach ($always_visible as $field_name => $label): ?>
                            <?php
                            if (!empty($item[$field_name])) {
                                $formatted_value = Resources_Manager::format_column_value($item[$field_name]);
                            ?>
                                <div class="resource-field resource-field--<?php echo esc_attr($field_name); ?>">
                                    <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                    <span class="resource-field-value">
                                        <?php echo $formatted_value; ?>
                                        <?php
                                        // Add extension inline with phone number
                                        if ($field_name === 'phone' && !empty($item['phone_extension'])) {
                                            echo ' <span style="font-style: italic; font-size: 0.8em; white-space: nowrap;">ext ' . esc_html($item['phone_extension']) . '</span>';
                                        }
                                        ?>
                                    </span>
                                </div>
                            <?php
                            }
                            ?>
                        <?php endforeach; ?>

                        <!-- Hidden details -->
                        <div class="resource-details-hidden" id="details-<?php echo $index; ?>" aria-hidden="true">
                            <?php foreach ($hidden_details as $section_key => $section): ?>
                                <?php
                                // Check if section has any non-empty fields before rendering
                                $has_content = false;
                                foreach ($section['fields'] as $field_name => $label) {
                                    if (!empty($item[$field_name])) {
                                        $has_content = true;
                                        break;
                                    }
                                }

                                if (!$has_content) continue;
                                ?>

                                <div class="resource-section">
                                    <h4 class="resource-section-heading"><?php echo esc_html($section['label']); ?></h4>

                                    <?php foreach ($section['fields'] as $field_name => $label): ?>
                                        <?php
                                        // Skip organization (already shown above resource name)
                                        if ($field_name === 'organization') {
                                            continue;
                                        }
                                    
                                        // Special handling for hours_of_operation - show structured hours
                                        if ($field_name === 'hours_of_operation') {
                                            $hours_data = Resource_Hours_Manager::get_hours($item['id']);

                                            if ($hours_data) {
                                                ?>
                                                <div class="resource-field resource-field--hours_of_operation resource-hours">
                                                    <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                                    <div class="resource-field-value">
                                                        <?php if ($hours_data['flags']['is_24_7']): ?>
                                                            <div class="hours-special-flag hours-24-7">⏰ Open 24/7</div>
                                                        <?php elseif ($hours_data['flags']['is_by_appointment']): ?>
                                                            <div class="hours-special-flag">📅 By Appointment Only</div>
                                                        <?php elseif ($hours_data['flags']['is_call_for_availability']): ?>
                                                            <div class="hours-special-flag">📞 Call for Availability</div>
                                                        <?php elseif ($hours_data['flags']['is_currently_closed']): ?>
                                                            <div class="hours-special-flag hours-closed">🚫 Currently Closed</div>
                                                        <?php else: ?>
                                                            <div class="hours-breakdown">
                                                                <?php if (!empty($hours_data['office_hours'])): ?>
                                                                    <div class="hours-section">
                                                                        <strong>Office Hours:</strong><br>
                                                                        <?php echo Resource_Hours_Manager::format_hours_display($hours_data['office_hours'], 'compact'); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                    
                                                                <?php
                                                                // Only show service hours if they're different from office hours
                                                                $show_service_hours = false;
                                                                if (!empty($hours_data['service_hours'])) {
                                                                    $office_formatted = Resource_Hours_Manager::format_hours_display($hours_data['office_hours'], 'compact');
                                                                    $service_formatted = Resource_Hours_Manager::format_hours_display($hours_data['service_hours'], 'compact');
                                                                    $show_service_hours = ($office_formatted !== $service_formatted);
                                                                }

                                                                if ($show_service_hours):
                                                                ?>
                                                                    <div class="hours-section">
                                                                        <strong>Service Hours:</strong><br>
                                                                        <?php echo Resource_Hours_Manager::format_hours_display($hours_data['service_hours'], 'compact'); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($hours_data['special_notes'])): ?>
                                                            <div class="hours-notes">
                                                                <em><?php echo esc_html($hours_data['special_notes']); ?></em>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php
                                            } elseif (!empty($item[$field_name])) {
                                                // Fallback to legacy text format if no structured hours
                                                ?>
                                                <div class="resource-field resource-field--hours_of_operation">
                                                    <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                                    <span class="resource-field-value"><?php echo esc_html($item[$field_name]); ?></span>
                                                </div>
                                                <?php
                                            }
                                        } elseif (!empty($item[$field_name])) {
                                            // Special formatting for list-style fields (Documents Required, Other Eligibility, How to Apply)
                                            if (in_array($field_name, array('documents_required', 'other_eligibility', 'how_to_apply'))) {
                                                $formatted_value = Resources_Manager::format_as_list($item[$field_name]);
                                            } else {
                                                $formatted_value = Resources_Manager::format_column_value($item[$field_name]);
                                            }
                                            ?>
                                            <div class="resource-field resource-field--<?php echo esc_attr($field_name); ?>">
                                                <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                                <div class="resource-field-value"><?php echo $formatted_value; ?></div>
                                            </div>
                                        <?php
                                        }
                                        ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Toggle button -->
                        <div class="resource-toggle">
                            <button class="resource-toggle-button"
                                    onclick="toggleDetails(<?php echo $index; ?>)"
                                    id="toggle-<?php echo $index; ?>"
                                    aria-expanded="false"
                                    aria-controls="details-<?php echo $index; ?>">
                                Show Full Details
                            </button>
                            <br>
                            <button class="resource-report-btn" onclick="openReportModal('<?php echo esc_js($item['resource_name']); ?>', <?php echo $index; ?>)">
                                Report an Issue
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php
        // Include modal templates
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/report-issue-modal.php';
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/submit-resource-modal.php';
        ?>

        <script>
            // Tighter synonym mapping - only closely related terms
            const synonymMap = {
                'rent': ['rent', 'eviction', 'housing assistance', 'lease'],
                'eviction': ['eviction', 'rent', 'housing assistance', 'lease'],
                'housing': ['housing', 'shelter', 'apartment'],
                'shelter': ['shelter', 'housing', 'homeless', 'emergency housing'],
                'food': ['food', 'pantry', 'meals', 'hunger', 'groceries'],
                'pantry': ['pantry', 'food bank', 'meals'],
                'utility': ['utility', 'utilities', 'electric bill', 'gas bill', 'water bill', 'energy assistance'],
                'electric': ['electric', 'electricity', 'utility', 'energy assistance'],
                'gas': ['gas', 'utility', 'heating', 'energy assistance'],
                'water': ['water', 'utility', 'sewer'],
                'medical': ['medical', 'health care', 'healthcare', 'doctor', 'clinic'],
                'health': ['health', 'medical', 'healthcare', 'doctor', 'clinic'],
                'mental': ['mental health', 'counseling', 'therapy', 'behavioral health'],
                'addiction': ['addiction', 'substance abuse', 'recovery', 'rehabilitation'],
                'job': ['job', 'employment', 'work', 'career'],
                'employment': ['employment', 'job', 'work', 'career'],
                'legal': ['legal', 'lawyer', 'attorney', 'court'],
                'transportation': ['transportation', 'bus', 'transit', 'rides'],
                'clothes': ['clothes', 'clothing', 'apparel'],
                'furniture': ['furniture', 'household items', 'furnishings'],
                'childcare': ['childcare', 'daycare', 'child care'],
                'senior': ['senior', 'elderly', 'older adult'],
                'veteran': ['veteran', 'veterans', 'military'],
                'disability': ['disability', 'disabled', 'accessible']
            };

            function getExpandedSearchTerms(word) {
                const expandedWords = new Set();
                expandedWords.add(word);

                // Check if this word has synonyms
                if (synonymMap[word]) {
                    synonymMap[word].forEach(function(synonym) {
                        expandedWords.add(synonym);
                    });
                }

                return Array.from(expandedWords);
            }

            function toggleDetails(index) {
                const details = document.getElementById('details-' + index);
                const button = document.getElementById('toggle-' + index);

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

            (function() {
                function openPrintOptions() {
                    const options = document.getElementById('print-options');
                    if (options) {
                        options.classList.add('active');
                    }
                }

                function closePrintOptions() {
                    const options = document.getElementById('print-options');
                    if (options) {
                        options.classList.remove('active');
                    }
                }

                function printResources(layout) {
                    document.body.setAttribute('data-print-layout', layout);
                    closePrintOptions();
                    window.print();
                }

                window.openPrintOptions = openPrintOptions;
                window.closePrintOptions = closePrintOptions;
                window.printResources = printResources;

                const searchInput = document.getElementById('resource-search');
                const resourceTypeFilter = document.getElementById('resource-type-filter');
                const needMetFilter = document.getElementById('need-met-filter');
                const audienceCheckboxes = document.querySelectorAll('.audience-checkbox');
                const cards = document.querySelectorAll('.resource-card');
                const visibleCount = document.getElementById('visible-count');

                function normalizeAudienceValue(value) {
                    return value
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, ' ')
                        .trim();
                }

                function normalizeAudienceTokens(value) {
                    var normalized = normalizeAudienceValue(value);
                    if (!normalized) {
                        return [];
                    }
                    return normalized.split(/\s+/).filter(Boolean);
                }

                // Combined filter function that handles search, resource type, need met, and target audience
                function filterResources() {
                    const searchTerm = searchInput.value.toLowerCase().trim();
                    const selectedResourceType = resourceTypeFilter.value.toLowerCase().trim();
                    const selectedNeedMet = needMetFilter.value.toLowerCase().trim();

                    // Get all selected audiences
                    const selectedAudiences = Array.from(audienceCheckboxes)
                        .filter(cb => cb.checked)
                        .map(cb => cb.value.toLowerCase());
                    const normalizedSelectedAudiences = selectedAudiences
                        .map(normalizeAudienceTokens)
                        .filter(tokens => tokens.length > 0);

                    let visible = 0;

                    // Remove any existing "no results" message
                    const existingNoResults = document.querySelector('.no-results');
                    if (existingNoResults) {
                        existingNoResults.remove();
                    }

                    // Create arrays to hold SVdP and partner cards that match filters
                    const svdpCards = [];
                    const partnerCards = [];
                    let hasSvdpResults = false;
                    let hasPartnerResults = false;

                    cards.forEach(function(card) {
                        const searchableText = card.getAttribute('data-search');
                        const cardResourceType = card.getAttribute('data-resource-type');
                        const cardNeedMet = card.getAttribute('data-need-met');
                        const cardAudience = card.getAttribute('data-audience') || '';
                        const cardAudienceList = cardAudience
                            .split(/[,;\n]+/)
                            .map(item => normalizeAudienceTokens(item))
                            .filter(tokens => tokens.length > 0);
                        const normalizedCardAudience = normalizeAudienceTokens(cardAudience);
                        if (normalizedCardAudience.length > 0) {
                            cardAudienceList.push(normalizedCardAudience);
                        }
                        const isSvdp = card.getAttribute('data-is-svdp') === '1';
                        let showCard = true;

                        // Check Resource Type filter
                        if (selectedResourceType !== '') {
                            showCard = cardResourceType.indexOf(selectedResourceType) !== -1;
                        }

                        // Check Need Met filter (AND logic - both must match if both are selected)
                        if (showCard && selectedNeedMet !== '') {
                            showCard = cardNeedMet.indexOf(selectedNeedMet) !== -1;
                        }

                        // Check target audience filter (if any checkboxes are selected)
                        if (showCard && normalizedSelectedAudiences.length > 0) {
                            showCard = normalizedSelectedAudiences.some(function(audienceTokens) {
                                return cardAudienceList.some(function(cardTokens) {
                                    return audienceTokens.every(function(token) {
                                        return cardTokens.indexOf(token) !== -1;
                                    });
                                });
                            });
                        }

                        // If card passes category and audience filters, check search term
                        if (showCard && searchTerm !== '') {
                            const originalWords = searchTerm.split(/\s+/);

                            // ALL original search words must match (via themselves or their synonyms)
                            showCard = originalWords.every(function(originalWord) {
                                // Get this word plus its synonyms
                                const expandedTerms = getExpandedSearchTerms(originalWord);

                                // Check if ANY of the expanded terms match
                                return expandedTerms.some(function(term) {
                                    const regex = new RegExp('\\b' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
                                    return regex.test(searchableText);
                                });
                            });
                        }

                        // Categorize visible cards by SVdP status
                        if (showCard) {
                            if (isSvdp) {
                                svdpCards.push(card);
                                hasSvdpResults = true;
                            } else {
                                partnerCards.push(card);
                                hasPartnerResults = true;
                            }
                            visible++;
                        }
                    });

                    // Hide all cards first
                    cards.forEach(function(card) {
                        card.style.display = 'none';
                    });

                    // Show SVdP cards first, then partner cards (maintains sort order)
                    svdpCards.forEach(function(card) {
                        card.style.display = 'block';
                    });
                    partnerCards.forEach(function(card) {
                        card.style.display = 'block';
                    });

                    // Show/hide the divider based on whether we have both types
                    const divider = document.querySelector('.partner-divider');
                    if (divider) {
                        divider.style.display = (hasSvdpResults && hasPartnerResults) ? 'block' : 'none';
                    }

                    // Update visible count
                    visibleCount.textContent = visible;

                    // Show "no results" message if needed
                    if (visible === 0) {
                        const grid = document.getElementById('resources-grid');
                        const noResults = document.createElement('div');
                        noResults.className = 'no-results';

                        let message = 'No resources found';
                        const filters = [];
                        if (selectedResourceType !== '') {
                            filters.push('Resource Type "' + resourceTypeFilter.options[resourceTypeFilter.selectedIndex].text + '"');
                        }
                        if (selectedNeedMet !== '') {
                            filters.push('Need Met "' + needMetFilter.options[needMetFilter.selectedIndex].text + '"');
                        }
                        if (searchTerm !== '') {
                            filters.push('search "' + searchTerm + '"');
                        }
                        if (selectedAudiences.length > 0) {
                            filters.push('selected population(s)');
                        }
                        if (filters.length > 0) {
                            message += ' matching ' + filters.join(' and ');
                        }

                        noResults.textContent = message;
                        grid.appendChild(noResults);
                    }
                }

                // Add event listeners for all filters
                searchInput.addEventListener('input', filterResources);
                resourceTypeFilter.addEventListener('change', filterResources);
                needMetFilter.addEventListener('change', filterResources);
                audienceCheckboxes.forEach(function(checkbox) {
                    checkbox.addEventListener('change', filterResources);
                });
            })();
        </script>
        <?php
        return ob_get_clean();
    }
}
