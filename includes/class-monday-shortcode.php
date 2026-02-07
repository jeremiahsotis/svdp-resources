<?php
/**
 * Shortcode Display Class
 */

class Monday_Resources_Shortcode
{

    public function __construct()
    {
        add_shortcode('monday_resources', array($this, 'display_resources'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Register Email AJAX Actions
        add_action('wp_ajax_monday_resources_email_list', array($this, 'email_resource_list'));
        add_action('wp_ajax_nopriv_monday_resources_email_list', array($this, 'email_resource_list'));
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts()
    {
        // Always enqueue styles and scripts if they might be needed for the shortcode,
        // or ensure the has_shortcode check is reliable even for page builders.
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
            'nonce' => wp_create_nonce('monday_resources_nonce'),
            'email_nonce' => wp_create_nonce('monday_resources_email_nonce')
        ));
    }

    /**
     * Handle Email List AJAX Request
     */
    public function email_resource_list()
    {
        check_ajax_referer('monday_resources_email_nonce', 'nonce');

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'detailed';
        $resource_ids = isset($_POST['resource_ids']) ? array_map('intval', $_POST['resource_ids']) : array();

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => 'Invalid email address.'));
        }

        error_log('Monday Resources Email Request: Email=' . $email . ', Format=' . $format . ', IDs=' . (is_array($resource_ids) ? implode(',', $resource_ids) : 'none'));

        if (empty($resource_ids)) {
            wp_send_json_error(array('message' => 'No resources selected.'));
        }

        // Fetch resource details
        global $wpdb;
        $resources_table = $wpdb->prefix . 'resources';
        $ids_placeholder = implode(',', array_fill(0, count($resource_ids), '%d'));

        $query = "SELECT * FROM $resources_table WHERE id IN ($ids_placeholder) ORDER BY resource_name ASC";
        $resources = $wpdb->get_results($wpdb->prepare($query, $resource_ids), ARRAY_A);

        if (empty($resources)) {
            wp_send_json_error(array('message' => 'Could not find the selected resources.'));
        }

        // Build Email Content
        $site_name = wp_strip_all_tags(get_bloginfo('name'));
        $site_name = str_replace(array('"', "'", "\n", "\r", ","), '', $site_name); // Sanitize name
        $admin_email = get_option('admin_email');

        $subject = 'Your Community Resources List from ' . $site_name;

        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Use standard From header format: Name <email>
        if (is_email($admin_email)) {
            $headers[] = 'From: "' . $site_name . '" <' . $admin_email . '>';
            $headers[] = 'Reply-To: ' . $admin_email;
        }

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>

        <head>
            <style>
                body {
                    font-family: Helvetica, Arial, sans-serif;
                    line-height: 1.5;
                    color: #333;
                }

                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                }

                .header {
                    border-bottom: 2px solid #0073aa;
                    padding-bottom: 10px;
                    margin-bottom: 20px;
                }

                .header h1 {
                    margin: 0;
                    color: #0073aa;
                    font-size: 24px;
                }

                .footer {
                    margin-top: 30px;
                    font-size: 12px;
                    color: #666;
                    border-top: 1px solid #eee;
                    padding-top: 10px;
                }

                .resource-item {
                    margin-bottom: 20px;
                    border-bottom: 1px solid #eee;
                    padding-bottom: 15px;
                }

                .resource-item:last-child {
                    border-bottom: none;
                }

                .resource-name {
                    font-size: 18px;
                    font-weight: bold;
                    color: #000;
                    margin-bottom: 5px;
                }

                .organization {
                    font-style: italic;
                    color: #555;
                    margin-bottom: 8px;
                    font-size: 14px;
                }

                .compact-line {
                    font-size: 14px;
                }

                .detail-row {
                    margin-bottom: 5px;
                    font-size: 14px;
                }

                .label {
                    font-weight: bold;
                    color: #555;
                }

                a {
                    color: #0073aa;
                    text-decoration: none;
                }
            </style>
        </head>

        <body>
            <div class="container">
                <div class="header">
                    <h1>Community Resources</h1>
                    <p>Here is the list of resources you requested from <?php echo esc_html($site_name); ?>.</p>
                </div>

                <div class="resource-list">
                    <?php foreach ($resources as $item): ?>
                        <div class="resource-item">
                            <div class="resource-name"><?php echo esc_html($item['resource_name']); ?></div>

                            <?php if (!empty($item['organization'])): ?>
                                <div class="organization"><?php echo esc_html($item['organization']); ?></div>
                            <?php endif; ?>

                            <?php if ($format === 'compact'): ?>
                                <!-- Compact Format: 2-3 lines max -->
                                <div class="compact-line">
                                    <?php
                                    $parts = array();
                                    if (!empty($item['phone'])) {
                                        $parts[] = '<a href="tel:' . esc_attr(preg_replace('/[^0-9]/', '', $item['phone'])) . '">' . esc_html($item['phone']) . '</a>';
                                    }
                                    if (!empty($item['website'])) {
                                        $parts[] = '<a href="' . esc_url($item['website']) . '">Website</a>';
                                    }
                                    echo implode(' | ', $parts);
                                    ?>
                                </div>
                                <?php if (!empty($item['physical_address'])): ?>
                                    <div class="compact-line" style="font-size: 12px; color: #666; margin-top: 4px;">
                                        <?php echo esc_html($item['physical_address']); ?>
                                    </div>
                                <?php endif; ?>

                            <?php else: // Detailed Format ?>

                                <?php if (!empty($item['primary_service_type'])): ?>
                                    <div class="detail-row"><span class="label">Type:</span>
                                        <?php echo esc_html($item['primary_service_type']); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($item['phone'])): ?>
                                    <div class="detail-row"><span class="label">Phone:</span> <a
                                            href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $item['phone'])); ?>"><?php echo esc_html($item['phone']); ?></a>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($item['website'])): ?>
                                    <div class="detail-row"><span class="label">Website:</span> <a
                                            href="<?php echo esc_url($item['website']); ?>"><?php echo esc_html($item['website']); ?></a>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($item['physical_address'])): ?>
                                    <div class="detail-row"><span class="label">Address:</span>
                                        <?php echo esc_html($item['physical_address']); ?></div>
                                <?php endif; ?>

                                <?php
                                // Hours (simplified for email)
                                if (class_exists('Resource_Hours_Manager')) {
                                    $hours_data = Resource_Hours_Manager::get_hours($item['id']);
                                    if ($hours_data && !empty($hours_data['office_hours'])) {
                                        $hours_text = Resource_Hours_Manager::format_hours_display($hours_data['office_hours'], 'compact');
                                        echo '<div class="detail-row"><span class="label">Hours:</span> ' . wp_kses_post($hours_text) . '</div>';
                                    } elseif (!empty($item['hours_of_operation'])) {
                                        echo '<div class="detail-row"><span class="label">Hours:</span> ' . esc_html($item['hours_of_operation']) . '</div>';
                                    }
                                }
                                ?>

                                <?php if (!empty($item['description'])): ?>
                                    <div class="detail-row" style="margin-top: 8px;">
                                        <?php echo wp_kses_post(wpautop($item['description'])); ?>
                                    </div>
                                <?php endif; ?>

                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="footer">
                    <p>Sent from <?php echo esc_html($site_name); ?> on <?php echo date('F j, Y'); ?>.</p>
                </div>
            </div>
        </body>

        </html>
        <?php
        $message = ob_get_clean();

        // Send Email
        $sent = wp_mail($email, $subject, $message, $headers);

        if ($sent) {
            wp_send_json_success(array('message' => 'Email sent successfully to ' . $email));
        } else {
            wp_send_json_error(array('message' => 'Failed to send email. Please check your server settings.'));
        }
    }

    /**
     * Display resources shortcode
     */
    public function display_resources($atts)
    {
        // Extract shortcode attributes
        $atts = shortcode_atts(array(
            'geography' => '',
            'service_type' => ''
        ), $atts);

        // Get resources from database with optional filters
        $filters = array();
        if (!empty($atts['geography'])) {
            $filters['geography'] = $atts['geography'];
        }
        if (!empty($atts['service_type'])) {
            $filters['service_type'] = $atts['service_type'];
        }

        $items = Resources_Manager::get_all_resources($filters);

        if (!$items) {
            return '<p>Unable to load resources. Please try again later.</p>';
        }

        // Resources are already sorted by Resources_Manager::get_all_resources()
        // (SVdP first, then by service type, then by name)

        // Define which fields are always visible vs hidden
        $always_visible = array(
            'primary_service_type' => 'Primary Service Type',
            'phone' => 'Phone',
            'target_population' => 'Target Population',
            'income_requirements' => 'Income Requirements',
            'website' => 'Website'
        );

        $hidden_details = array(
            'organization' => 'Organization/Agency',
            'secondary_service_type' => 'Secondary Service Type',
            'what_they_provide' => 'What They Provide',
            'email' => 'Email',
            'alternate_phone' => 'Alternate Phone',
            'physical_address' => 'Physical Address',
            'how_to_apply' => 'How to Apply',
            'documents_required' => 'Documents Required',
            'hours_of_operation' => 'Hours of Operation',
            'wait_time' => 'Wait Time',
            'residency_requirements' => 'Residency Requirements',
            'other_eligibility' => 'Other Eligibility Requirements',
            'eligibility_notes' => 'Eligibility Notes',
            'counties_served' => 'Counties Served',
            'notes_and_tips' => 'Notes & Tips'
        );

        // Collect unique service types for dropdown (Primary + Secondary)
        $service_types = array();
        foreach ($items as $item) {
            // Include primary service type
            if (!empty($item['primary_service_type'])) {
                $types = array_map('trim', explode(',', $item['primary_service_type']));
                foreach ($types as $type) {
                    if (!empty($type) && !in_array($type, $service_types)) {
                        $service_types[] = $type;
                    }
                }
            }
            // Include secondary service type
            if (!empty($item['secondary_service_type'])) {
                $types = array_map('trim', explode(',', $item['secondary_service_type']));
                foreach ($types as $type) {
                    if (!empty($type) && !in_array($type, $service_types)) {
                        $service_types[] = $type;
                    }
                }
            }
        }
        sort($service_types);

        // Define target audiences for checkboxes
        $target_audiences = array(
            'Adults (18 – 64)',
            'Children (0 – 17)',
            'Families with Children',
            'High School Students',
            'Immigrants/Refugees',
            'LGBTQ+ Individuals',
            'Men',
            'Military Families',
            'People at Risk of Homelessness',
            'People Experiencing Homelessness',
            'People with Disabilities',
            'People with Substance Use Disorder',
            'Pregnant Women',
            'Seniors (55+)',
            'Seniors (60+)',
            'Veterans',
            'Women',
            'Youth/Young Adults ( 16 – 24)'
        );

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

            /* Add email button style */
            .email-list-btn {
                display: inline-block;
                padding: 8px 16px;
                background-color: #f0f0f0;
                color: #333;
                border: 1px solid #ccc;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 600;
                margin-left: 10px;
                font-size: 0.9em;
            }

            .email-list-btn:hover {
                background-color: #e0e0e0;
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
                max-width: 600px;
            }

            .target-audience-checkboxes {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
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
                gap: 20px;
                margin-top: 20px;
                justify-content: center;
            }

            @media (min-width: 1080px) {
                .resources-grid {
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                }
            }

            .resource-card {
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
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
                margin-bottom: 12px;
            }

            .resource-field-label {
                font-weight: bold;
                color: #666;
                font-size: 0.9em;
                display: block;
                margin-bottom: 3px;
            }

            .resource-field-value {
                color: #333;
                font-size: 1em;
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

            @media (max-width: 768px) {
                .resources-container {
                    padding: 0 15px;
                }

                .resources-grid {
                    grid-template-columns: 1fr;
                    gap: 10px;
                }

                .resource-card {
                    padding: 15px;
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
                    padding: 12px;
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

                /* Reset basic layout */
                body {
                    background: white !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }

                /* Hide theme elements */
                header,
                nav,
                footer,
                .sidebar,
                .comments-area,
                #wpadminbar,
                .site-header,
                .site-footer {
                    display: none !important;
                }

                /* Hide plugin UI elements */
                .resources-help-section,
                .resources-filters,
                .submit-resource-btn,
                .results-count,
                .resource-toggle,
                .resource-report-btn,
                .monday-modal,
                .partner-divider {
                    display: none !important;
                }

                /* Force visibility of the main container */
                body * {
                    visibility: hidden !important;
                }

                .resources-container,
                .resources-container * {
                    visibility: visible !important;
                }

                .resources-container {
                    position: absolute !important;
                    left: 0 !important;
                    top: 0 !important;
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 20px !important;
                }

                /* Re-layout the grid for paper */
                .resources-grid {
                    display: block !important;
                    width: 100% !important;
                }

                .resource-card {
                    display: block !important;
                    width: 100% !important;
                    margin-bottom: 25px !important;
                    padding: 20px !important;
                    border: 1px solid #ccc !important;
                    break-inside: avoid !important;
                    page-break-inside: avoid !important;
                    box-shadow: none !important;
                    background: white !important;
                }

                .resource-card h3 {
                    color: #000 !important;
                    border-bottom: 2px solid #0073aa !important;
                    font-size: 1.4em !important;
                }

                .svdp-badge {
                    border: 1px solid #0073aa !important;
                    color: #0073aa !important;
                    background: none !important;
                    font-size: 10pt !important;
                }

                .resource-field-label {
                    color: #555 !important;
                }

                .resource-field-value {
                    color: #000 !important;
                }

                .resource-field-value a {
                    text-decoration: none !important;
                    color: #000 !important;
                }
            }
        </style>

        <div class="resources-container">
            <!-- Helpful Instructions Section -->
            <div class="resources-help-section">
                <h2>How to Find Resources</h2>
                <p>Browse by category or search by keyword to find what you need. You can also check boxes to filter by who the
                    resource serves (like seniors, families, or veterans). All filters are optional – use what helps you most.
                    Click "Click for more info..." on any resource for complete details. Use the "Report an Issue" button if you
                    find incorrect information, or the "Submit a New Resource" button below to share resources we're missing.
                </p>
            </div>

            <button class="submit-resource-btn" onclick="openSubmitResourceModal()">Submit a New Resource</button>

            <!-- Filter Section -->
            <div class="resources-filters">
                <div class="filter-row">
                    <div class="filter-column">
                        <div class="filter-group">
                            <label for="category-filter">Filter by Category (Optional)</label>
                            <select id="category-filter" class="category-filter">
                                <option value="">Show All Categories</option>
                                <?php foreach ($service_types as $type): ?>
                                    <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="filter-column target-audience-filter">
                        <div class="filter-group">
                            <label>Filter by Population Served (Optional)</label>
                            <div class="target-audience-checkboxes">
                                <?php foreach ($target_audiences as $index => $audience): ?>
                                    <div class="target-audience-checkbox">
                                        <input type="checkbox" id="audience-<?php echo $index; ?>" class="audience-checkbox"
                                            value="<?php echo esc_attr($audience); ?>" />
                                        <label for="audience-<?php echo $index; ?>"><?php echo esc_html($audience); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-group resources-search">
                    <label for="resource-search">Search by Keyword (Optional)</label>
                    <input type="text" id="resource-search"
                        placeholder="Type what you're looking for (e.g., food, rent help, medical care)..." />
                </div>
            </div>

            <div class="results-count">
                Showing <span id="visible-count"><?php echo count($items); ?></span> of <?php echo count($items); ?> resources
                <div style="display: inline-block; float: right;">
                    <button type="button" class="email-list-btn" onclick="openEmailModal()" style="margin-right: 10px;">Email
                        this List</button>
                    <button type="button" class="email-list-btn" onclick="showPrintOptions()">Print this List</button>
                    <div id="print-options"
                        style="display:none; position: absolute; right: 20px; background: white; border: 1px solid #ccc; padding: 10px; z-index: 100; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">
                        <button type="button" class="submit-resource-btn"
                            style="padding: 5px 10px; margin-bottom: 5px; width: 100%;"
                            onclick="printResources('detailed')">Print Detailed</button>
                        <button type="button" class="submit-resource-btn"
                            style="padding: 5px 10px; margin-bottom: 5px; width: 100%;"
                            onclick="printResources('compact')">Print Compact</button>
                        <button type="button" class="submit-resource-btn"
                            style="padding: 5px 10px; width: 100%; background-color: #666;"
                            onclick="closePrintOptions()">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- Email Modal -->
            <div id="emailListModal" class="monday-modal">
                <div class="monday-modal-content">
                    <span class="monday-modal-close" onclick="closeEmailModal()">&times;</span>
                    <h2>Email Resource List</h2>
                    <p>Enter your email address to receive a copy of the current list.</p>

                    <div id="emailFormMessage" class="form-message"></div>

                    <form id="emailListForm">
                        <div class="form-group">
                            <label for="recipient_email">Email Address <span class="required">*</span></label>
                            <input type="email" id="recipient_email" name="recipient_email" required
                                placeholder="you@example.com">
                        </div>

                        <div class="form-actions">
                            <button type="button" class="button-secondary" onclick="closeEmailModal()">Cancel</button>
                            <button type="button" class="button-primary" onclick="sendEmailList('compact')">Send Compact
                                List</button>
                            <button type="button" class="button-primary" onclick="sendEmailList('detailed')">Send Detailed
                                List</button>
                        </div>
                        <p style="font-size: 0.85em; color: #666; margin-top: 10px;">
                            <strong>Note:</strong> "Detailed" includes descriptions, hours, and notes. "Compact" is just names
                            and contact info (2-3 lines per item).
                        </p>
                    </form>
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
                    $searchable_text = strtolower((string) ($item['resource_name'] ?? ''));
                    foreach ($item as $key => $value) {
                        if (!is_numeric($key) && !in_array($key, array('id', 'created_at', 'updated_at', 'status')) && !is_null($value)) {
                            $searchable_text .= ' ' . strtolower((string) $value);
                        }
                    }

                    // Get the service types for category filtering (both Primary and Secondary)
                    $primary_service = !empty($item['primary_service_type']) ? strtolower((string) $item['primary_service_type']) : '';
                    $secondary_service = !empty($item['secondary_service_type']) ? strtolower((string) $item['secondary_service_type']) : '';
                    $combined_services = trim($primary_service . ' ' . $secondary_service);

                    // Get target audience for population filtering
                    $target_population = !empty($item['target_population']) ? strtolower((string) $item['target_population']) : '';
                    ?>
                    <div class="resource-card" data-resource-id="<?php echo esc_attr($item['id']); ?>"
                        data-search="<?php echo esc_attr($searchable_text); ?>"
                        data-category="<?php echo esc_attr($combined_services); ?>"
                        data-audience="<?php echo esc_attr($target_population); ?>"
                        data-is-svdp="<?php echo $is_svdp ? '1' : '0'; ?>">
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
                                <span class="verification-badge aging">⚠ Last verified <?php echo esc_html($relative_time); ?>
                                    ago</span>
                            <?php elseif ($status === 'stale' && $verified_date): ?>
                                <span class="verification-badge stale">⚠ Information may be outdated (verified
                                    <?php echo esc_html($relative_time); ?> ago)</span>
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
                                <div class="resource-field">
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
                        <div class="resource-details-hidden" id="details-<?php echo $index; ?>">
                            <?php foreach ($hidden_details as $field_name => $label): ?>
                                <?php
                                // Skip Organization since it's now shown above
                                if ($field_name === 'organization') {
                                    continue;
                                }

                                // Special handling for hours_of_operation - show structured hours
                                if ($field_name === 'hours_of_operation') {
                                    $hours_data = Resource_Hours_Manager::get_hours($item['id']);

                                    if ($hours_data) {
                                        ?>
                                        <div class="resource-field resource-hours">
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
                                                            // Compare the formatted output to see if they're actually different
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
                                        <div class="resource-field">
                                            <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                            <span class="resource-field-value"><?php echo esc_html($item[$field_name]); ?></span>
                                        </div>
                                        <?php
                                    }
                                } elseif (!empty($item[$field_name])) {
                                    $formatted_value = Resources_Manager::format_column_value($item[$field_name]);
                                    ?>
                                    <div class="resource-field">
                                        <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                        <span class="resource-field-value"><?php echo $formatted_value; ?></span>
                                    </div>
                                    <?php
                                }
                                ?>
                            <?php endforeach; ?>
                        </div>

                        <!-- Toggle button -->
                        <div class="resource-toggle">
                            <button class="resource-toggle-button" onclick="toggleDetails(<?php echo $index; ?>)"
                                id="toggle-<?php echo $index; ?>">
                                Click for more info...
                            </button>
                            <br>
                            <button class="resource-report-btn"
                                onclick="openReportModal('<?php echo esc_js($item['resource_name']); ?>', <?php echo $index; ?>)">
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
            // Email and Print Functions
            function openEmailModal() {
                document.getElementById('emailListModal').style.display = 'block';
            }

            function closeEmailModal() {
                document.getElementById('emailListModal').style.display = 'none';
                document.getElementById('emailFormMessage').innerHTML = '';
            }

            function sendEmailList(format) {
                var email = document.getElementById('recipient_email').value;
                if (!email) {
                    alert('Please enter an email address.');
                    return;
                }

                var messageDiv = document.getElementById('emailFormMessage');
                messageDiv.innerHTML = 'Sending...';
                messageDiv.className = 'form-message';

                // Get visible resource IDs
                var visibleIds = [];
                var cards = document.querySelectorAll('.resource-card');
                cards.forEach(function (card) {
                    var style = window.getComputedStyle(card);
                    if (style.display !== 'none' && style.visibility !== 'hidden') {
                        visibleIds.push(card.getAttribute('data-resource-id'));
                    }
                });
                if (visibleIds.length === 0) {
                    messageDiv.innerHTML = 'No resources to send.';
                    messageDiv.className = 'form-message error';
                    return;
                }

                jQuery.ajax({
                    url: mondayResources.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'monday_resources_email_list',
                        nonce: mondayResources.email_nonce,
                        email: email,
                        format: format,
                        resource_ids: visibleIds
                    },
                    success: function (response) {
                        if (response.success) {
                            messageDiv.innerHTML = response.data.message;
                            messageDiv.className = 'form-message success';
                            setTimeout(closeEmailModal, 2000);
                        } else {
                            messageDiv.innerHTML = response.data.message;
                            messageDiv.className = 'form-message error';
                        }
                    },
                    error: function (xhr,                                  status, error) {
                                console.error('Monday Resources Email Error:', error);
                                console.error('Status:', status);
                                console.error('Response:', xhr.responseText);
                                messageDiv.innerHTML = 'An error occurred (Permission or Network). Please try again.';
                                messageDiv.className = 'form-message error';
                            }
                        });
                    }

                    function showPrintOptions() {
                        var options = document.getElementById('print-options');
                        if (options.style.display === 'none') {
                            options.style.display = 'block';
                        } else {
                            options.style.display = 'none';
                        }
                    }

                    function closePrintOptions() {
                        document.getElementById('print-options').style.display = 'none';
                    }

                    function printResources(format) {
                        closePrintOptions();

                        // Expand details if detailed format
                        if (format === 'detailed') {
                            var details = document.querySelectorAll('.resource-details-hidden');
                            details.forEach(function (detail) {
                                detail.style.display = 'block';
                                detail.style.visibility = 'visible';
                            });
                            var toggles = document.querySelectorAll('.resource-toggle');
                            toggles.forEach(function (toggle) {
                                toggle.style.display = 'none';
                            });
                        }

                        // Short delay for DOM to catch up
                        setTimeout(function () {
                            window.print();

                            // Reload to reset state after print dialog is closed
                            // Some browsers need this event, others block JS until dialog closes
                            window.onafterprint = function () {
                                location.reload();
                            };

                            // Fallback for browsers without onafterprint
                            setTimeout(function () {
                                if (confirm('Print finished? Click OK to reset the page.')) {
                                    location.reload();
                                }
                            }, 500);
                        }, 250);
                    }
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
                            synonymMap[word].forEach(function (synonym) {
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
                            button.textContent = 'Show less';
                        } else {
                            details.style.display = 'none';
                            button.textContent = 'Click for more info...';
                        }
                    }

                    (function () {
                        const searchInput = document.getElementById('resource-search');
                        const categoryFilter = document.getElementById('category-filter');
                        const audienceCheckboxes = document.querySelectorAll('.audience-checkbox');
                        const cards = document.querySelectorAll('.resource-card');
                        const visibleCount = document.getElementById('visible-count');

                        // Combined filter function that handles search, category, and target audience
                        function filterResources() {
                            const searchTerm = searchInput.value.toLowerCase().trim();
                            const selectedCategory = categoryFilter.value.toLowerCase().trim();

                            // Get all selected audiences
                            const selectedAudiences = Array.from(audienceCheckboxes)
                                .filter(cb => cb.checked)
                                .map(cb => cb.value.toLowerCase());

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

                            cards.forEach(function (card) {
                                const searchableText = card.getAttribute('data-search');
                                const cardCategory = card.getAttribute('data-category');
                                const cardAudience = card.getAttribute('data-audience');
                                const isSvdp = card.getAttribute('data-is-svdp') === '1';
                                let showCard = true;

                                // Check category filter first
                                if (selectedCategory !== '') {
                                    // Check if card's category contains the selected category
                                    showCard = cardCategory.indexOf(selectedCategory) !== -1;
                                }

                                // Check target audience filter (if any checkboxes are selected)
                                if (showCard && selectedAudiences.length > 0) {
                                    // Card must match at least one selected audience
                                    // Use word boundary matching to avoid "men" matching "women"
                                    showCard = selectedAudiences.some(function (audience) {
                                        // Escape special regex characters and create word boundary pattern
                                        var escapedAudience = audience.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                                        var regex = new RegExp('\\b' + escapedAudience + '\\b', 'i');
                                        return regex.test(cardAudience);
                                    });
                                }

                                // If card passes category and audience filters, check search term
                                if (showCard && searchTerm !== '') {
                                    const originalWords = searchTerm.split(/\s+/);

                                    // ALL original search words must match (via themselves or their synonyms)
                                    showCard = originalWords.every(function (originalWord) {
                                        // Get this word plus its synonyms
                                        const expandedTerms = getExpandedSearchTerms(originalWord);

                                        // Check if ANY of the expanded terms match
                                        return expandedTerms.some(function (term) {
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
                            cards.forEach(function (card) {
                                card.style.display = 'none';
                            });

                            // Show SVdP cards first, then partner cards (maintains sort order)
                            svdpCards.forEach(function (card) {
                                card.style.display = 'block';
                            });
                            partnerCards.forEach(function (card) {
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
                                if (selectedCategory !== '' && searchTerm !== '') {
                                    message += ' matching category "' + categoryFilter.options[categoryFilter.selectedIndex].text + '" and search "' + searchTerm + '"';
                                } else if (selectedCategory !== '') {
                                    message += ' in category "' + categoryFilter.options[categoryFilter.selectedIndex].text + '"';
                                } else if (searchTerm !== '') {
                                    message += ' matching "' + searchTerm + '"';
                                }

                                if (selectedAudiences.length > 0) {
                                    message += ' for selected population(s)';
                                }

                                noResults.textContent = message;
                                grid.appendChild(noResults);
                            }
                        }

                        // Add event listeners for all filters
                        searchInput.addEventListener('input', filterResources);
                        categoryFilter.addEventListener('change', filterResources);
                        audienceCheckboxes.forEach(function (checkbox) {
                            checkbox.addEventListener('change', filterResources);
                        });
                    })();
                </script>
                <?php
                return ob_get_clean();
    }
}
