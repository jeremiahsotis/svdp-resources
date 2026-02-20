<?php
/**
 * Admin Settings and Dashboard Class
 */

class Monday_Resources_Admin {

    const IMPORT_NONCE_ACTION = 'svdp_resource_taxonomy_import';
    const ROLLBACK_NONCE_ACTION = 'svdp_resource_taxonomy_rollback';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Resource management actions
        add_action('admin_post_delete_resource', array($this, 'delete_resource'));
        add_action('admin_post_save_resource', array($this, 'save_resource'));
        add_action('admin_post_bulk_action_resources', array($this, 'bulk_action_resources'));
        add_action('admin_post_rollback_taxonomy_import', array($this, 'rollback_taxonomy_import'));

        // Issue and submission actions
        add_action('admin_post_delete_issue', array($this, 'delete_issue'));
        add_action('admin_post_delete_submission', array($this, 'delete_submission'));
        add_action('admin_post_update_issue_status', array($this, 'update_issue_status'));
        add_action('admin_post_update_submission_status', array($this, 'update_submission_status'));

        // Export AJAX endpoint
        add_action('wp_ajax_export_resources', array($this, 'export_resources'));
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on resource add/edit pages
        // WordPress hook format: {parent_slug}_page_{submenu_slug}
        // For hidden pages (null parent): admin_page_{slug}
        $add_page_hook = 'resources_page_monday-resources-add';
        $edit_page_hook = 'admin_page_monday-resources-edit';

        // Also check by URL parameter as fallback
        $page = isset($_GET['page']) ? $_GET['page'] : '';
        $is_resource_page = ($page === 'monday-resources-add' || $page === 'monday-resources-edit');

        if ($hook === $add_page_hook || $hook === $edit_page_hook || $is_resource_page) {
            wp_enqueue_script(
                'monday-resources-admin-hours',
                MONDAY_RESOURCES_PLUGIN_URL . 'assets/js/admin-hours.js',
                array('jquery'),
                MONDAY_RESOURCES_VERSION,
                true
            );
        }
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        $resource_capability = $this->get_resource_capability();

        add_menu_page(
            'Community Resources',
            'Resources',
            $resource_capability,
            'monday-resources-manage',
            array($this, 'manage_resources_page'),
            'dashicons-list-view',
            30
        );

        add_submenu_page(
            'monday-resources-manage',
            'All Resources',
            'All Resources',
            $resource_capability,
            'monday-resources-manage',
            array($this, 'manage_resources_page')
        );

        add_submenu_page(
            'monday-resources-manage',
            'Add New Resource',
            'Add New',
            $resource_capability,
            'monday-resources-add',
            array($this, 'add_resource_page')
        );

        add_submenu_page(
            null, // Hidden from menu
            'Edit Resource',
            'Edit Resource',
            $resource_capability,
            'monday-resources-edit',
            array($this, 'edit_resource_page')
        );

        add_submenu_page(
            'monday-resources-manage',
            'Issue Reports',
            'Issue Reports',
            $resource_capability,
            'monday-resources-issues',
            array($this, 'issues_page')
        );

        add_submenu_page(
            'monday-resources-manage',
            'Resource Submissions',
            'Submissions',
            $resource_capability,
            'monday-resources-submissions',
            array($this, 'submissions_page')
        );

        add_submenu_page(
            'monday-resources-manage',
            'Taxonomy Import',
            'Taxonomy Import',
            'manage_options',
            'monday-resources-taxonomy-import',
            array($this, 'taxonomy_import_page')
        );

        add_submenu_page(
            'monday-resources-manage',
            'Settings',
            'Settings',
            'manage_options',
            'monday-resources-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Resolve capability required to manage resources.
     *
     * @return string
     */
    private function get_resource_capability() {
        if (function_exists('monday_resources_get_manage_capability')) {
            return monday_resources_get_manage_capability();
        }
        return 'manage_options';
    }

    /**
     * Canonical Service Area terms.
     *
     * @return array
     */
    private function get_service_area_terms() {
        if (!class_exists('Resource_Taxonomy')) {
            return array();
        }
        return Resource_Taxonomy::get_service_area_terms();
    }

    /**
     * Canonical Services Offered terms.
     *
     * @return array
     */
    private function get_services_offered_terms() {
        if (!class_exists('Resource_Taxonomy')) {
            return array();
        }
        return Resource_Taxonomy::get_services_offered_terms();
    }

    /**
     * Canonical Provider Type terms.
     *
     * @return array
     */
    private function get_provider_type_terms() {
        if (!class_exists('Resource_Taxonomy')) {
            return array();
        }
        return Resource_Taxonomy::get_provider_type_terms();
    }

    /**
     * Get Service Area label from stored slug.
     *
     * @param string $service_area_slug
     * @return string
     */
    private function get_service_area_label($service_area_slug) {
        if (!class_exists('Resource_Taxonomy')) {
            return '';
        }
        return Resource_Taxonomy::get_service_area_label($service_area_slug);
    }

    /**
     * Resolve selected Service Area slug for admin form defaults.
     *
     * @param array $resource
     * @return string
     */
    private function resolve_selected_service_area($resource) {
        if (!class_exists('Resource_Taxonomy') || !is_array($resource)) {
            return '';
        }

        if (!empty($resource['service_area'])) {
            $slug = Resource_Taxonomy::normalize_service_area_slug($resource['service_area']);
            if ($slug !== '') {
                return $slug;
            }
        }

        if (!empty($resource['primary_service_type'])) {
            $slug = Resource_Taxonomy::normalize_service_area_slug($resource['primary_service_type']);
            if ($slug !== '') {
                return $slug;
            }
        }

        return '';
    }

    /**
     * Resolve selected Services Offered slugs for admin form defaults.
     *
     * @param array $resource
     * @return array
     */
    private function resolve_selected_services_offered($resource) {
        if (!class_exists('Resource_Taxonomy') || !is_array($resource)) {
            return array();
        }

        if (!empty($resource['services_offered'])) {
            $parsed = Resource_Taxonomy::parse_pipe_slugs($resource['services_offered']);
            return Resource_Taxonomy::normalize_services_offered_slugs($parsed);
        }

        if (!empty($resource['secondary_service_type'])) {
            $legacy_tokens = array_filter(array_map('trim', explode(',', $resource['secondary_service_type'])));
            return Resource_Taxonomy::normalize_services_offered_slugs($legacy_tokens);
        }

        return array();
    }

    /**
     * Resolve selected Provider Type slug for admin form defaults.
     *
     * @param array $resource
     * @return string
     */
    private function resolve_selected_provider_type($resource) {
        if (!class_exists('Resource_Taxonomy') || !is_array($resource)) {
            return '';
        }

        if (!empty($resource['provider_type'])) {
            return Resource_Taxonomy::normalize_provider_type_slug($resource['provider_type']);
        }

        return '';
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('monday_resources_settings', 'resource_conference_options');
        register_setting('monday_resources_settings', 'resource_counties_options');
        register_setting('monday_resources_settings', 'resource_service_types');
        register_setting('monday_resources_settings', 'resource_target_population_options');
        register_setting('monday_resources_settings', 'resource_income_requirements_options');
        register_setting('monday_resources_settings', 'resource_wait_time_options');

        // Initialize default options if not set
        if (!get_option('resource_conference_options')) {
            $default_conferences = array(
                'All Fort Wayne Conferences',
                'Cathedral',
                'Entire Fort Wayne District',
                'Huntington',
                'Our Lady',
                'Queen of Angels',
                'Sacred Heart – Warsaw',
                'St Charles',
                'St Elizabeth',
                'St Francis',
                'St Gaspar',
                'St Henry',
                'St John the Baptist – New Haven',
                'St Joseph',
                'St Jude',
                'St Louis',
                'St Martin',
                'St Mary – Avilla',
                'St Mary – Decatur',
                'St Mary – Fort Wayne',
                'St Patrick',
                'St Paul',
                'St Peter',
                'St Therese',
                'St Vincent'
            );
            update_option('resource_conference_options', $default_conferences);
        }

        if (!get_option('resource_counties_options')) {
            $default_counties = array(
                'Adams County',
                'Allen County',
                'DeKalb County',
                'Huntington County',
                'Kosciusko County',
                'LaGrange County',
                'National',
                'Noble County',
                'Statewide (Indiana)',
                'Steuben County',
                'Wells County',
                'Whitley County'
            );
            update_option('resource_counties_options', $default_counties);
        }

        // Migrate or initialize service types
        $service_types = get_option('resource_service_types');
        $default_service_types = array(
            '💰 Financial Assistance – Emergency',
            'ℹ️ Other Services',
            '♿️ Disability Support',
            '⚖️ Legal Services – Eviction & Housing',
            '🌱 Substance Abuse Treatment',
            '🍽️ Food Assistance',
            '🏘️ Permanent Housing',
            '🏥 Medical/Healthcare',
            '👕 Clothing',
            '👵 Senior Services',
            '👶 Childcare',
            '💊 Prescription Assistance',
            '💡 Financial Assistance – Utilities',
            '🏡 Financial Assistance – Rent/Mortgage',
            '💼 Employment/Job Training',
            '📊 Financial Counseling & Education',
            '📋 Legal Services – (Family – Identity – Other)',
            '📚 Education',
            '🔨 Home Repairs',
            '🚗 Transportation',
            '🚨 Crisis Services',
            '🚿 Basic Needs',
            '🛂 Legal Services – Immigration',
            '🛋️ Furniture',
            '🛏️ Shelter/Emergency Housing',
            '🤝 Support Groups',
            '🧑‍🧑‍🧒‍🧒 Family Services',
            '🧠 Mental Health Services'
        );

        if (!$service_types) {
            // No service types exist, create defaults
            update_option('resource_service_types', $default_service_types);
        } elseif (is_array($service_types) && !empty($service_types)) {
            // Check if it's old format (objects) or old data (with 📌 pin emoji)
            $has_old_data = false;

            if (is_array($service_types[0])) {
                // Old format detected (array of objects), migrate to new format (simple strings)
                $migrated_types = array();
                foreach ($service_types as $type_obj) {
                    if (isset($type_obj['icon']) && isset($type_obj['name'])) {
                        $migrated_types[] = $type_obj['icon'] . ' ' . $type_obj['name'];
                    } elseif (isset($type_obj['name'])) {
                        $migrated_types[] = $type_obj['name'];
                    }
                }
                sort($migrated_types);
                update_option('resource_service_types', $migrated_types);
            } else {
                // Check if list contains old default items with 📌 emoji or old service type names
                foreach ($service_types as $type) {
                    if (strpos($type, '📌') === 0 ||
                        in_array($type, array('Food Assistance', 'Housing/Shelter', 'Financial Assistance', 'Healthcare', 'Mental Health',
                                             'Substance Abuse', 'Legal Assistance', 'Employment', 'Education', 'Transportation',
                                             'Clothing', 'Utilities Assistance', 'Childcare', 'Senior Services', 'Veterans Services',
                                             'Crisis Intervention'))) {
                        $has_old_data = true;
                        break;
                    }
                }

                // If old data detected, replace with new defaults
                if ($has_old_data) {
                    update_option('resource_service_types', $default_service_types);
                }
            }
        }

        if (!get_option('resource_target_population_options')) {
            $default_target_populations = array(
                'Adults (18 – 64)',
                'Cancer Patients',
                'Children (0 – 17)',
                'Families with Children',
                'Food Service Workers',
                'General Population',
                'Health Conditions',
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
                'Youth/Young Adults (16 – 24)'
            );
            update_option('resource_target_population_options', $default_target_populations);
        }

        if (!get_option('resource_income_requirements_options')) {
            $default_income_requirements = array(
                '✅ No Income Limit (anyone qualifies)',
                '300% Federal Poverty Level or Below',
                '250% Federal Poverty Level or Below',
                '200% Federal Poverty Level or Below',
                '185% Federal Poverty Level or Below',
                '165% Federal Poverty Level or Below',
                '150% Federal Poverty Level or Below',
                '141% Federal Poverty Level or Below',
                '138% Federal Poverty Level or Below',
                '130% Federal Poverty Level or Below',
                '125% Federal Poverty Guidelines or Below',
                '100% Federal Poverty Level or Below',
                '80% Federal Poverty Level or Below',
                '50% Federal Poverty Level or Below',
                'Must Be Below Poverty Line',
                'Case-by-Case Determination',
                'Income Not Considered',
                'Other (See Eligibility Notes)',
                '❓ Unknown'
            );
            update_option('resource_income_requirements_options', $default_income_requirements);
        }

        if (!get_option('resource_wait_time_options')) {
            $default_wait_times = array(
                '⚡ Immediate (Same Day)',
                '📅 1 – 3 Days',
                '📆 1 Week',
                '🗓️ 2 – 4 Weeks',
                '📋 1+ Months',
                '🚫 Currently Not Accepting (Waitlist)',
                '❓ Varies/Unknown'
            );
            update_option('resource_wait_time_options', $default_wait_times);
        }
    }

    /**
     * Manage Resources page
     */
    public function manage_resources_page() {
        // Handle search and filters
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $service_area_filter = isset($_GET['service_area']) ? sanitize_text_field($_GET['service_area']) : '';
        if ($service_area_filter === '' && isset($_GET['service'])) {
            $service_area_filter = sanitize_text_field($_GET['service']);
        }

        // Get all resources
        $filters = array();
        if ($status_filter) {
            $filters['verification_status'] = $status_filter;
        }
        if ($service_area_filter) {
            $filters['service_area'] = $service_area_filter;
        }

        $resources = Resources_Manager::get_all_resources($filters);

        // Apply search if provided
        if ($search) {
            $resources = array_filter($resources, function($resource) use ($search) {
                $searchable = strtolower(implode(' ', $resource));
                return strpos($searchable, strtolower($search)) !== false;
            });
        }

        // Get canonical Service Area terms.
        $service_area_terms = $this->get_service_area_terms();

        // Get verification stats
        $stats = Resources_Manager::get_verification_stats();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Community Resources</h1>
            <a href="<?php echo admin_url('admin.php?page=monday-resources-add'); ?>" class="page-title-action">Add New</a>
            <button type="button" class="page-title-action" id="export-resources-btn" style="margin-left: 5px;">Export Resources</button>
            <hr class="wp-header-end">

            <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Resource deleted successfully.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Resource saved successfully.</p>
                </div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div style="background: #fff; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0;">Quick Stats</h3>
                <p style="margin: 5px 0;">
                    <strong>Total Resources:</strong> <?php echo $stats['total']; ?> |
                    <span style="color: #46b450;">Fresh: <?php echo $stats['fresh']; ?></span> |
                    <span style="color: #ffb900;">Aging: <?php echo $stats['aging']; ?></span> |
                    <span style="color: #dc3232;">Stale: <?php echo $stats['stale']; ?></span>
                    <?php if ($stats['unverified'] > 0): ?>
                        | <span style="color: #999;">Unverified: <?php echo $stats['unverified']; ?></span>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Search and Filters -->
            <form method="get" class="search-box">
                <input type="hidden" name="page" value="monday-resources-manage">
                <p class="search-box" style="margin: 15px 0;">
                    <label>Search: </label>
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" style="width: 300px;">

                    <label style="margin-left: 15px;">Status: </label>
                    <select name="status" style="width: 150px;">
                        <option value="">All Statuses</option>
                        <option value="fresh" <?php selected($status_filter, 'fresh'); ?>>Fresh</option>
                        <option value="aging" <?php selected($status_filter, 'aging'); ?>>Aging</option>
                        <option value="stale" <?php selected($status_filter, 'stale'); ?>>Stale</option>
                        <option value="unverified" <?php selected($status_filter, 'unverified'); ?>>Unverified</option>
                    </select>

                    <label style="margin-left: 15px;">Service Area: </label>
                    <select name="service_area" style="width: 350px; font-size: 14px;">
                        <option value="">All Service Areas</option>
                        <?php foreach ($service_area_terms as $service_area_slug => $service_area_label): ?>
                            <option value="<?php echo esc_attr($service_area_slug); ?>" <?php selected($service_area_filter, $service_area_slug); ?>>
                                <?php echo esc_html($service_area_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="submit" class="button" value="Filter">
                    <?php if ($search || $status_filter || $service_area_filter): ?>
                        <a href="<?php echo admin_url('admin.php?page=monday-resources-manage'); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </p>
            </form>

            <!-- Bulk Actions -->
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="bulk_action_resources">
                <?php wp_nonce_field('bulk_action_resources'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="delete">Delete</option>
                        </select>
                        <input type="submit" class="button action" value="Apply">
                    </div>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo count($resources); ?> items</span>
                    </div>
                </div>

                <!-- Resources Table -->
                <?php if (empty($resources)): ?>
                    <p>No resources found.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column">
                                    <input type="checkbox" id="cb-select-all">
                                </td>
                                <th>Resource Name</th>
                                <th>Organization</th>
                                <th>Service Area</th>
                                <th>Conferences</th>
                                <th>Status</th>
                                <th>Last Verified</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resources as $resource): ?>
                                <?php
                                $status_class = '';
                                $status_label = ucfirst($resource['verification_status']);
                                switch ($resource['verification_status']) {
                                    case 'fresh':
                                        $status_class = 'background: #d4edda; color: #155724; padding: 3px 8px; border-radius: 3px;';
                                        break;
                                    case 'aging':
                                        $status_class = 'background: #fff3cd; color: #856404; padding: 3px 8px; border-radius: 3px;';
                                        break;
                                    case 'stale':
                                        $status_class = 'background: #f8d7da; color: #721c24; padding: 3px 8px; border-radius: 3px;';
                                        break;
                                    case 'unverified':
                                        $status_class = 'background: #e2e3e5; color: #383d41; padding: 3px 8px; border-radius: 3px;';
                                        break;
                                }

                                $verified_time = '';
                                if ($resource['last_verified_date']) {
                                    $verified_time = human_time_diff(strtotime($resource['last_verified_date']), current_time('timestamp')) . ' ago';
                                } else {
                                    $verified_time = 'Never';
                                }
                                ?>
                                <tr>
                                    <?php
                                    $service_area_label = '';
                                    if (!empty($resource['service_area'])) {
                                        $service_area_label = $this->get_service_area_label($resource['service_area']);
                                    }
                                    if ($service_area_label === '' && !empty($resource['primary_service_type'])) {
                                        $service_area_label = $resource['primary_service_type'];
                                    }
                                    ?>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="resource_ids[]" value="<?php echo $resource['id']; ?>">
                                    </th>
                                    <td>
                                        <strong>
                                            <a href="<?php echo admin_url('admin.php?page=monday-resources-edit&id=' . $resource['id']); ?>">
                                                <?php echo esc_html($resource['resource_name']); ?>
                                            </a>
                                        </strong>
                                        <?php if ($resource['is_svdp']): ?>
                                            <span style="background: #0073aa; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">SVdP</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($resource['organization']); ?></td>
                                    <td><?php echo esc_html($service_area_label); ?></td>
                                    <td><?php echo esc_html($resource['geography']); ?></td>
                                    <td><span style="<?php echo $status_class; ?>"><?php echo $status_label; ?></span></td>
                                    <td><?php echo $verified_time; ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=monday-resources-edit&id=' . $resource['id']); ?>">Edit</a> |
                                        <a href="<?php echo admin_url('admin.php?page=monday-resources-add&duplicate=' . $resource['id']); ?>">Duplicate</a> |
                                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=delete_resource&id=' . $resource['id']), 'delete_resource_' . $resource['id']); ?>"
                                           onclick="return confirm('Are you sure you want to delete this resource?');"
                                           style="color: #a00;">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </form>

            <!-- Export Modal -->
            <div id="export-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4); overflow-y: auto;">
                <div style="background-color: #fff; margin: 5% auto; padding: 30px; border: 1px solid #888; width: 700px; border-radius: 5px; max-height: 90vh; overflow-y: auto;">
                    <span class="close-export-modal" style="float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
                    <h2>Export Resources</h2>

                    <!-- Step 1: Choose What to Export -->
                    <div style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #0073aa;">
                        <h3 style="margin-top: 0;">1. Choose Resources to Export</h3>
                        <label style="display: block; margin: 10px 0;">
                            <input type="radio" name="export_scope" value="all" checked>
                            <strong>Export All Resources</strong> (<?php echo count($resources); ?> total)
                        </label>
                        <label style="display: block; margin: 10px 0;">
                            <input type="radio" name="export_scope" value="selected">
                            <strong>Export Selected Only</strong> <span class="selected-count">(0 selected)</span>
                        </label>
                        <label style="display: block; margin: 10px 0;">
                            <input type="radio" name="export_scope" value="filtered">
                            <strong>Export Current Filter Results</strong>
                            <?php if ($search || $status_filter || $service_area_filter): ?>
                                (Active filters applied)
                            <?php else: ?>
                                (No filters active - same as Export All)
                            <?php endif; ?>
                        </label>
                    </div>

                    <!-- Step 2: Choose Fields -->
                    <div style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #00a32a;">
                        <h3 style="margin-top: 0;">2. Choose Fields to Include</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px;">
                            <label><input type="checkbox" name="export_fields[]" value="id" checked> ID</label>
                            <label><input type="checkbox" name="export_fields[]" value="resource_name" checked> Resource Name</label>
                            <label><input type="checkbox" name="export_fields[]" value="organization"> Organization</label>
                            <label><input type="checkbox" name="export_fields[]" value="service_area" checked> Service Area</label>
                            <label><input type="checkbox" name="export_fields[]" value="services_offered" checked> Services Offered</label>
                            <label><input type="checkbox" name="export_fields[]" value="provider_type"> Provider Type</label>
                            <label><input type="checkbox" name="export_fields[]" value="phone" checked> Phone</label>
                            <label><input type="checkbox" name="export_fields[]" value="email"> Email</label>
                            <label><input type="checkbox" name="export_fields[]" value="website"> Website</label>
                            <label><input type="checkbox" name="export_fields[]" value="physical_address" checked> Physical Address</label>
                            <label><input type="checkbox" name="export_fields[]" value="what_they_provide"> What They Provide</label>
                            <label><input type="checkbox" name="export_fields[]" value="how_to_apply"> How to Apply</label>
                            <label><input type="checkbox" name="export_fields[]" value="documents_required"> Documents Required</label>
                            <label><input type="checkbox" name="export_fields[]" value="target_population"> Target Population</label>
                            <label><input type="checkbox" name="export_fields[]" value="income_requirements"> Income Requirements</label>
                            <label><input type="checkbox" name="export_fields[]" value="geography"> Geography</label>
                            <label><input type="checkbox" name="export_fields[]" value="office_hours" checked> Office Hours</label>
                            <label><input type="checkbox" name="export_fields[]" value="service_hours" checked> Service Hours</label>
                            <label><input type="checkbox" name="export_fields[]" value="last_verified_date"> Last Verified</label>
                            <label><input type="checkbox" name="export_fields[]" value="verification_status"> Verification Status</label>
                        </div>
                        <div style="margin-top: 10px;">
                            <button type="button" class="button" id="select-all-fields">Select All</button>
                            <button type="button" class="button" id="select-none-fields">Select None</button>
                        </div>
                    </div>

                    <!-- Step 3: Choose Format -->
                    <div style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #d63638;">
                        <h3 style="margin-top: 0;">3. Choose Export Format</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <button type="button" class="button button-primary button-large export-format-btn" data-format="csv" style="padding: 15px;">
                                <span class="dashicons dashicons-media-spreadsheet" style="margin-top: 3px;"></span> CSV
                            </button>
                            <button type="button" class="button button-primary button-large export-format-btn" data-format="excel" style="padding: 15px;">
                                <span class="dashicons dashicons-media-spreadsheet" style="margin-top: 3px;"></span> Excel (XLSX)
                            </button>
                            <button type="button" class="button button-primary button-large export-format-btn" data-format="json" style="padding: 15px;">
                                <span class="dashicons dashicons-media-code" style="margin-top: 3px;"></span> JSON
                            </button>
                            <button type="button" class="button button-primary button-large export-format-btn" data-format="pdf" style="padding: 15px;">
                                <span class="dashicons dashicons-media-document" style="margin-top: 3px;"></span> PDF
                            </button>
                        </div>
                    </div>

                    <p class="description" style="margin-top: 20px;">
                        <strong>Note:</strong> Excel and PDF formats require Composer dependencies.
                        Run <code>composer install</code> in the plugin directory if not installed.
                    </p>
                </div>
            </div>

            <script>
                // Select all checkbox functionality
                document.getElementById('cb-select-all').addEventListener('change', function() {
                    var checkboxes = document.querySelectorAll('input[name="resource_ids[]"]');
                    checkboxes.forEach(function(checkbox) {
                        checkbox.checked = this.checked;
                    }, this);
                });

                // Export modal functionality
                jQuery(document).ready(function($) {
                    // Update selected count when checkboxes change
                    function updateSelectedCount() {
                        var count = $('input[name="resource_ids[]"]:checked').length;
                        $('.selected-count').text('(' + count + ' selected)');
                    }

                    $('input[name="resource_ids[]"]').on('change', updateSelectedCount);

                    // Open export modal
                    $('#export-resources-btn').on('click', function() {
                        updateSelectedCount();
                        $('#export-modal').fadeIn();
                    });

                    // Close modal
                    $('.close-export-modal').on('click', function() {
                        $('#export-modal').fadeOut();
                    });

                    $(window).on('click', function(e) {
                        if (e.target.id === 'export-modal') {
                            $('#export-modal').fadeOut();
                        }
                    });

                    // Field selection helpers
                    $('#select-all-fields').on('click', function() {
                        $('input[name="export_fields[]"]').prop('checked', true);
                    });

                    $('#select-none-fields').on('click', function() {
                        $('input[name="export_fields[]"]').prop('checked', false);
                    });

                    // Export button click
                    $('.export-format-btn').on('click', function() {
                        var format = $(this).data('format');
                        var $btn = $(this);
                        var originalText = $btn.html();

                        // Get export scope
                        var scope = $('input[name="export_scope"]:checked').val();

                        // Get selected resource IDs (if scope is 'selected')
                        var resourceIds = [];
                        if (scope === 'selected') {
                            $('input[name="resource_ids[]"]:checked').each(function() {
                                resourceIds.push($(this).val());
                            });

                            if (resourceIds.length === 0) {
                                alert('Please select at least one resource to export.');
                                return;
                            }
                        }

                        // Get selected fields
                        var fields = [];
                        $('input[name="export_fields[]"]:checked').each(function() {
                            fields.push($(this).val());
                        });

                        if (fields.length === 0) {
                            alert('Please select at least one field to export.');
                            return;
                        }

                        // Build URL with parameters
                        var params = new URLSearchParams({
                            action: 'export_resources',
                            format: format,
                            scope: scope,
                            _wpnonce: '<?php echo wp_create_nonce('export_resources'); ?>'
                        });

                        // Add fields
                        fields.forEach(function(field) {
                            params.append('fields[]', field);
                        });

                        // Add resource IDs if selected scope
                        if (scope === 'selected') {
                            resourceIds.forEach(function(id) {
                                params.append('ids[]', id);
                            });
                        }

                        // Add current filters if filtered scope
                        if (scope === 'filtered') {
                            <?php if ($search): ?>
                            params.append('search', '<?php echo esc_js($search); ?>');
                            <?php endif; ?>
                            <?php if ($status_filter): ?>
                            params.append('status', '<?php echo esc_js($status_filter); ?>');
                            <?php endif; ?>
                            <?php if ($service_area_filter): ?>
                            params.append('service_area', '<?php echo esc_js($service_area_filter); ?>');
                            <?php endif; ?>
                        }

                        $btn.prop('disabled', true).html('Exporting...');

                        // Trigger download
                        window.location.href = ajaxurl + '?' + params.toString();

                        // Re-enable button after delay
                        setTimeout(function() {
                            $btn.prop('disabled', false).html(originalText);
                            $('#export-modal').fadeOut();
                        }, 2000);
                    });
                });
            </script>
        </div>
        <?php
    }

    /**
     * Settings page
     */
    public function settings_page() {
        $stats = Resources_Manager::get_verification_stats();
        $migration_count = get_option('monday_resources_migration_count', 0);

        // Handle add/remove actions
        if (isset($_POST['add_conference']) && check_admin_referer('manage_dropdowns')) {
            $new_conference = sanitize_text_field(wp_unslash($_POST['new_conference']));
            if (!empty($new_conference)) {
                $conferences = get_option('resource_conference_options', array());
                if (!in_array($new_conference, $conferences)) {
                    $conferences[] = $new_conference;
                    sort($conferences);
                    update_option('resource_conference_options', $conferences);
                    echo '<div class="notice notice-success is-dismissible"><p>Conference added successfully.</p></div>';
                }
            }
        }

        if (isset($_POST['remove_conference']) && check_admin_referer('manage_dropdowns')) {
            $remove_conference = sanitize_text_field(wp_unslash($_POST['conference_to_remove']));
            $conferences = get_option('resource_conference_options', array());
            $key = array_search($remove_conference, $conferences);
            if ($key !== false) {
                unset($conferences[$key]);
                $conferences = array_values($conferences);
                update_option('resource_conference_options', $conferences);
                echo '<div class="notice notice-success is-dismissible"><p>Conference removed successfully.</p></div>';
            }
        }

        if (isset($_POST['add_county']) && check_admin_referer('manage_dropdowns')) {
            $new_county = sanitize_text_field(wp_unslash($_POST['new_county']));
            if (!empty($new_county)) {
                $counties = get_option('resource_counties_options', array());
                if (!in_array($new_county, $counties)) {
                    $counties[] = $new_county;
                    sort($counties);
                    update_option('resource_counties_options', $counties);
                    echo '<div class="notice notice-success is-dismissible"><p>County added successfully.</p></div>';
                }
            }
        }

        if (isset($_POST['remove_county']) && check_admin_referer('manage_dropdowns')) {
            $remove_county = sanitize_text_field(wp_unslash($_POST['county_to_remove']));
            $counties = get_option('resource_counties_options', array());
            $key = array_search($remove_county, $counties);
            if ($key !== false) {
                unset($counties[$key]);
                $counties = array_values($counties);
                update_option('resource_counties_options', $counties);
                echo '<div class="notice notice-success is-dismissible"><p>County removed successfully.</p></div>';
            }
        }

        if (isset($_POST['add_service_type']) && check_admin_referer('manage_dropdowns')) {
            $new_service_type = sanitize_text_field(wp_unslash($_POST['new_service_type']));
            if (!empty($new_service_type)) {
                $service_types = get_option('resource_service_types', array());
                if (!in_array($new_service_type, $service_types)) {
                    $service_types[] = $new_service_type;
                    sort($service_types);
                    update_option('resource_service_types', $service_types);
                    echo '<div class="notice notice-success is-dismissible"><p>Service Type added successfully.</p></div>';
                }
            }
        }

        if (isset($_POST['remove_service_type']) && check_admin_referer('manage_dropdowns')) {
            $remove_service = sanitize_text_field(wp_unslash($_POST['service_type_to_remove']));
            $service_types = get_option('resource_service_types', array());
            $key = array_search($remove_service, $service_types);
            if ($key !== false) {
                unset($service_types[$key]);
                $service_types = array_values($service_types);
                update_option('resource_service_types', $service_types);
                echo '<div class="notice notice-success is-dismissible"><p>Service Type removed successfully.</p></div>';
            }
        }

        if (isset($_POST['add_target_population']) && check_admin_referer('manage_dropdowns')) {
            $new_target_population = sanitize_text_field(wp_unslash($_POST['new_target_population']));
            if (!empty($new_target_population)) {
                $target_populations = get_option('resource_target_population_options', array());
                if (!in_array($new_target_population, $target_populations)) {
                    $target_populations[] = $new_target_population;
                    sort($target_populations);
                    update_option('resource_target_population_options', $target_populations);
                    echo '<div class="notice notice-success is-dismissible"><p>Target Population added successfully.</p></div>';
                }
            }
        }

        if (isset($_POST['remove_target_population']) && check_admin_referer('manage_dropdowns')) {
            $remove_target_population = sanitize_text_field(wp_unslash($_POST['target_population_to_remove']));
            $target_populations = get_option('resource_target_population_options', array());
            $key = array_search($remove_target_population, $target_populations);
            if ($key !== false) {
                unset($target_populations[$key]);
                $target_populations = array_values($target_populations);
                update_option('resource_target_population_options', $target_populations);
                echo '<div class="notice notice-success is-dismissible"><p>Target Population removed successfully.</p></div>';
            }
        }

        if (isset($_POST['add_income_requirement']) && check_admin_referer('manage_dropdowns')) {
            $new_income_requirement = sanitize_text_field(wp_unslash($_POST['new_income_requirement']));
            if (!empty($new_income_requirement)) {
                $income_requirements = get_option('resource_income_requirements_options', array());
                if (!in_array($new_income_requirement, $income_requirements)) {
                    $income_requirements[] = $new_income_requirement;
                    update_option('resource_income_requirements_options', $income_requirements);
                    echo '<div class="notice notice-success is-dismissible"><p>Income Requirement added successfully.</p></div>';
                }
            }
        }

        if (isset($_POST['remove_income_requirement']) && check_admin_referer('manage_dropdowns')) {
            $remove_income_requirement = sanitize_text_field(wp_unslash($_POST['income_requirement_to_remove']));
            $income_requirements = get_option('resource_income_requirements_options', array());
            $key = array_search($remove_income_requirement, $income_requirements);
            if ($key !== false) {
                unset($income_requirements[$key]);
                $income_requirements = array_values($income_requirements);
                update_option('resource_income_requirements_options', $income_requirements);
                echo '<div class="notice notice-success is-dismissible"><p>Income Requirement removed successfully.</p></div>';
            }
        }

        if (isset($_POST['add_wait_time']) && check_admin_referer('manage_dropdowns')) {
            $new_wait_time = sanitize_text_field(wp_unslash($_POST['new_wait_time']));
            if (!empty($new_wait_time)) {
                $wait_times = get_option('resource_wait_time_options', array());
                if (!in_array($new_wait_time, $wait_times)) {
                    $wait_times[] = $new_wait_time;
                    update_option('resource_wait_time_options', $wait_times);
                    echo '<div class="notice notice-success is-dismissible"><p>Wait Time option added successfully.</p></div>';
                }
            }
        }

        if (isset($_POST['remove_wait_time']) && check_admin_referer('manage_dropdowns')) {
            $remove_wait_time = sanitize_text_field(wp_unslash($_POST['wait_time_to_remove']));
            $wait_times = get_option('resource_wait_time_options', array());
            $key = array_search($remove_wait_time, $wait_times);
            if ($key !== false) {
                unset($wait_times[$key]);
                $wait_times = array_values($wait_times);
                update_option('resource_wait_time_options', $wait_times);
                echo '<div class="notice notice-success is-dismissible"><p>Wait Time option removed successfully.</p></div>';
            }
        }

        $conferences = get_option('resource_conference_options', array());
        $counties = get_option('resource_counties_options', array());
        $service_types = get_option('resource_service_types', array());
        $target_populations = get_option('resource_target_population_options', array());
        $income_requirements = get_option('resource_income_requirements_options', array());
        $wait_times = get_option('resource_wait_time_options', array());
        ?>
        <div class="wrap">
            <h1>Community Resources Settings</h1>

            <!-- Dropdown Options Management -->
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0;">Dropdown Options</h2>

                <h3>Conference Options</h3>
                <p>Manage the dropdown options for the Conference field (single selection).</p>

                <form method="post" style="margin-bottom: 20px;">
                    <?php wp_nonce_field('manage_dropdowns'); ?>
                    <input type="text" name="new_conference" placeholder="Enter new conference name" style="width: 300px;">
                    <button type="submit" name="add_conference" class="button button-primary">Add Conference</button>
                </form>

                <table class="wp-list-table widefat" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th>Conference Name</th>
                            <th style="width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conferences as $conference): ?>
                            <tr>
                                <td><?php echo esc_html($conference); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('manage_dropdowns'); ?>
                                        <input type="hidden" name="conference_to_remove" value="<?php echo esc_attr($conference); ?>">
                                        <button type="submit" name="remove_conference" class="button button-small"
                                                onclick="return confirm('Remove this conference option?')">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <hr style="margin: 30px 0;">

                <h3>Counties Served Options</h3>
                <p>Manage the options for the Counties Served field (multiple selection).</p>

                <form method="post" style="margin-bottom: 20px;">
                    <?php wp_nonce_field('manage_dropdowns'); ?>
                    <input type="text" name="new_county" placeholder="Enter new county name" style="width: 300px;">
                    <button type="submit" name="add_county" class="button button-primary">Add County</button>
                </form>

                <table class="wp-list-table widefat" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th>County Name</th>
                            <th style="width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($counties as $county): ?>
                            <tr>
                                <td><?php echo esc_html($county); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('manage_dropdowns'); ?>
                                        <input type="hidden" name="county_to_remove" value="<?php echo esc_attr($county); ?>">
                                        <button type="submit" name="remove_county" class="button button-small"
                                                onclick="return confirm('Remove this county option?')">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <hr style="margin: 30px 0;">

                <h3>Service Type Options</h3>
                <p>Manage the Service Types used for both Primary Service Type (single selection) and Secondary Service Type (multiple selection). Include emoji at the beginning (e.g., 🍽️ Food Assistance).</p>

                <form method="post" style="margin-bottom: 20px;">
                    <?php wp_nonce_field('manage_dropdowns'); ?>
                    <input type="text" name="new_service_type" placeholder="Enter service type with emoji (e.g., 🍽️ Food Assistance)" style="width: 500px; font-size: 15px;">
                    <button type="submit" name="add_service_type" class="button button-primary">Add Service Type</button>
                    <p class="description">💡 Tip: Copy emojis from <a href="https://emojipedia.org/" target="_blank">Emojipedia</a> or use your keyboard's emoji picker</p>
                </form>

                <table class="wp-list-table widefat" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th>Service Type</th>
                            <th style="width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($service_types)): ?>
                            <tr>
                                <td colspan="2">No service types defined.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($service_types as $service_type): ?>
                                <tr>
                                    <td style="font-size: 15px;"><?php echo esc_html($service_type); ?></td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('manage_dropdowns'); ?>
                                            <input type="hidden" name="service_type_to_remove" value="<?php echo esc_attr($service_type); ?>">
                                            <button type="submit" name="remove_service_type" class="button button-small"
                                                    onclick="return confirm('Remove this service type?')">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <hr style="margin: 30px 0;">

                <h3>Target Population Options</h3>
                <p>Manage the Target Population options used for resource eligibility (multiple selection).</p>

                <form method="post" style="margin-bottom: 20px;">
                    <?php wp_nonce_field('manage_dropdowns'); ?>
                    <input type="text" name="new_target_population" placeholder="Enter target population" style="width: 400px;">
                    <button type="submit" name="add_target_population" class="button button-primary">Add Target Population</button>
                </form>

                <table class="wp-list-table widefat" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th>Target Population</th>
                            <th style="width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($target_populations as $target_population): ?>
                            <tr>
                                <td><?php echo esc_html($target_population); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('manage_dropdowns'); ?>
                                        <input type="hidden" name="target_population_to_remove" value="<?php echo esc_attr($target_population); ?>">
                                        <button type="submit" name="remove_target_population" class="button button-small"
                                                onclick="return confirm('Remove this target population option?')">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <hr style="margin: 30px 0;">

                <h3>Income Requirements Options</h3>
                <p>Manage the Income Requirements options for resource eligibility (single selection dropdown).</p>

                <form method="post" style="margin-bottom: 20px;">
                    <?php wp_nonce_field('manage_dropdowns'); ?>
                    <input type="text" name="new_income_requirement" placeholder="Enter income requirement (e.g., ✅ No Income Limit)" style="width: 450px;">
                    <button type="submit" name="add_income_requirement" class="button button-primary">Add Income Requirement</button>
                </form>

                <table class="wp-list-table widefat" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th>Income Requirement</th>
                            <th style="width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($income_requirements as $income_requirement): ?>
                            <tr>
                                <td><?php echo esc_html($income_requirement); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('manage_dropdowns'); ?>
                                        <input type="hidden" name="income_requirement_to_remove" value="<?php echo esc_attr($income_requirement); ?>">
                                        <button type="submit" name="remove_income_requirement" class="button button-small"
                                                onclick="return confirm('Remove this income requirement option?')">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <hr style="margin: 30px 0;">

                <h3>Wait Time Options</h3>
                <p>Manage the Wait Time options for resources (single selection dropdown).</p>

                <form method="post" style="margin-bottom: 20px;">
                    <?php wp_nonce_field('manage_dropdowns'); ?>
                    <input type="text" name="new_wait_time" placeholder="Enter wait time option (e.g., ⚡ Immediate)" style="width: 400px;">
                    <button type="submit" name="add_wait_time" class="button button-primary">Add Wait Time</button>
                </form>

                <table class="wp-list-table widefat" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th>Wait Time</th>
                            <th style="width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($wait_times as $wait_time): ?>
                            <tr>
                                <td><?php echo esc_html($wait_time); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('manage_dropdowns'); ?>
                                        <input type="hidden" name="wait_time_to_remove" value="<?php echo esc_attr($wait_time); ?>">
                                        <button type="submit" name="remove_wait_time" class="button button-small"
                                                onclick="return confirm('Remove this wait time option?')">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- System Information -->
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0;">System Information</h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">Total Resources</th>
                        <td><strong><?php echo $stats['total']; ?></strong> active resources</td>
                    </tr>
                    <tr>
                        <th scope="row">Verification Status</th>
                        <td>
                            <span style="color: #46b450;">Fresh: <?php echo $stats['fresh']; ?></span> |
                            <span style="color: #ffb900;">Aging: <?php echo $stats['aging']; ?></span> |
                            <span style="color: #dc3232;">Stale: <?php echo $stats['stale']; ?></span>
                            <?php if ($stats['unverified'] > 0): ?>
                                | <span style="color: #999;">Unverified: <?php echo $stats['unverified']; ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($migration_count > 0): ?>
                    <tr>
                        <th scope="row">Migration Status</th>
                        <td>
                            ✓ Successfully migrated <?php echo $migration_count; ?> resources from Monday.com
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">Verification Reminders</th>
                        <td>
                            Daily status updates and weekly email reminders are enabled<br>
                            <span class="description">Emails sent to all administrator accounts</span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Taxonomy import page (dry run + apply).
     *
     * @return void
     */
    public function taxonomy_import_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $import_result = null;
        $errors = array();
        $rollback_status = isset($_GET['rollback_status']) ? sanitize_key(wp_unslash($_GET['rollback_status'])) : '';
        $rollback_message = isset($_GET['rollback_message']) ? sanitize_text_field(wp_unslash($_GET['rollback_message'])) : '';
        $recent_runs = class_exists('Resource_Taxonomy_Import') ? Resource_Taxonomy_Import::get_recent_apply_runs(10) : array();

        if (isset($_POST['run_taxonomy_import'])) {
            check_admin_referer(self::IMPORT_NONCE_ACTION);

            if (empty($_FILES['taxonomy_file']) || empty($_FILES['taxonomy_file']['tmp_name'])) {
                $errors[] = 'Please select a CSV/XLSX/XLS file before running import.';
            } else {
                require_once ABSPATH . 'wp-admin/includes/file.php';

                $upload = wp_handle_upload(
                    $_FILES['taxonomy_file'],
                    array(
                        'test_form' => false,
                        'test_type' => false,
                        'mimes' => array(
                            'csv' => 'text/csv',
                            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'xls' => 'application/vnd.ms-excel'
                        )
                    )
                );

                if (!empty($upload['error'])) {
                    $errors[] = $upload['error'];
                } else {
                    $apply_changes = isset($_POST['import_mode']) && $_POST['import_mode'] === 'apply';
                    $original_name = isset($_FILES['taxonomy_file']['name']) ? sanitize_file_name($_FILES['taxonomy_file']['name']) : basename($upload['file']);

                    $import_result = Resource_Taxonomy_Import::process_file(
                        $upload['file'],
                        $original_name,
                        $apply_changes
                    );

                    if (isset($upload['file']) && file_exists($upload['file'])) {
                        @unlink($upload['file']);
                    }

                    if (empty($import_result['success'])) {
                        $errors[] = !empty($import_result['message']) ? $import_result['message'] : 'Import failed.';
                    }
                }
            }
        }

        ?>
        <div class="wrap">
            <h1>Taxonomy Import</h1>
            <p>Import taxonomy-only mappings for existing resources: <code>Service Area</code>, <code>Services Offered</code>, and <code>Provider Type</code>.</p>
            <p><strong>Mode guidance:</strong> Run Dry Run first. Use Apply only after reviewing duplicates and review queue items.</p>

            <?php foreach ($errors as $error): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($error); ?></p>
                </div>
            <?php endforeach; ?>

            <?php if (is_array($import_result) && !empty($import_result['success'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($import_result['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($rollback_status === 'success' && $rollback_message !== ''): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($rollback_message); ?></p>
                </div>
            <?php elseif ($rollback_status === 'error' && $rollback_message !== ''): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($rollback_message); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" style="background:#fff; border:1px solid #ccd0d4; padding:20px; max-width:920px;">
                <?php wp_nonce_field(self::IMPORT_NONCE_ACTION); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="taxonomy_file">Spreadsheet File</label></th>
                        <td>
                            <input type="file" id="taxonomy_file" name="taxonomy_file" accept=".csv,.xlsx,.xls" required>
                            <p class="description">Accepted formats: CSV, XLSX, XLS.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Import Mode</th>
                        <td>
                            <label style="display:block; margin-bottom:6px;">
                                <input type="radio" name="import_mode" value="dry_run" checked>
                                Dry Run (no DB writes)
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="import_mode" value="apply">
                                Apply Changes (writes taxonomy fields + audit records)
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top:10px;">
                    <button type="submit" name="run_taxonomy_import" class="button button-primary">Run Import</button>
                </p>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-top:20px; max-width:920px;">
                <h2 style="margin-top:0;">Rollback Import Run</h2>
                <p>Revert taxonomy fields for a prior <strong>Apply</strong> run using its <code>import_run_id</code>. This updates <code>service_area</code>, <code>services_offered</code>, and <code>provider_type</code> only.</p>
                <input type="hidden" name="action" value="rollback_taxonomy_import">
                <?php wp_nonce_field(self::ROLLBACK_NONCE_ACTION); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="rollback_import_run_id">Import Run ID</label></th>
                        <td>
                            <input type="text" id="rollback_import_run_id" name="import_run_id" class="regular-text" required placeholder="e.g. 96b8fbe0-62f5-4e8c-8d8a-3c78f8e95ec7">
                        </td>
                    </tr>
                </table>
                <p class="submit" style="margin-top:10px;">
                    <button type="submit" class="button">Run Rollback</button>
                </p>
            </form>

            <?php if (!empty($recent_runs)): ?>
                <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-top:20px; max-width:920px;">
                    <h2 style="margin-top:0;">Recent Apply Runs</h2>
                    <table class="widefat striped" style="max-width:920px;">
                        <thead>
                            <tr>
                                <th>Import Run ID</th>
                                <th>Last Activity</th>
                                <th>Update Rows</th>
                                <th>Resources Touched</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_runs as $run): ?>
                                <tr>
                                    <td><code><?php echo esc_html(isset($run['import_run_id']) ? $run['import_run_id'] : ''); ?></code></td>
                                    <td><?php echo esc_html(isset($run['last_activity_at']) ? $run['last_activity_at'] : ''); ?></td>
                                    <td><?php echo isset($run['update_rows']) ? intval($run['update_rows']) : 0; ?></td>
                                    <td><?php echo isset($run['resources_touched']) ? intval($run['resources_touched']) : 0; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (is_array($import_result) && !empty($import_result['success'])): ?>
                <?php $stats = isset($import_result['stats']) && is_array($import_result['stats']) ? $import_result['stats'] : array(); ?>
                <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-top:20px; max-width:920px;">
                    <h2 style="margin-top:0;">Import Summary</h2>
                    <p><strong>Run ID:</strong> <?php echo esc_html(isset($import_result['import_run_id']) ? $import_result['import_run_id'] : ''); ?></p>
                    <table class="widefat striped" style="max-width:720px;">
                        <tbody>
                            <tr><td>Rows total</td><td><?php echo isset($stats['rows_total']) ? intval($stats['rows_total']) : 0; ?></td></tr>
                            <tr><td>Rows with ID</td><td><?php echo isset($stats['rows_with_id']) ? intval($stats['rows_with_id']) : 0; ?></td></tr>
                            <tr><td>Rows valid</td><td><?php echo isset($stats['rows_valid']) ? intval($stats['rows_valid']) : 0; ?></td></tr>
                            <tr><td>Rows updated</td><td><?php echo isset($stats['rows_updated']) ? intval($stats['rows_updated']) : 0; ?></td></tr>
                            <tr><td>Rows unchanged</td><td><?php echo isset($stats['rows_unchanged']) ? intval($stats['rows_unchanged']) : 0; ?></td></tr>
                            <tr><td>Duplicates ignored</td><td><?php echo isset($stats['duplicates_ignored']) ? intval($stats['duplicates_ignored']) : 0; ?></td></tr>
                            <tr><td>Rows failed validation</td><td><?php echo isset($stats['rows_failed_validation']) ? intval($stats['rows_failed_validation']) : 0; ?></td></tr>
                            <tr><td>Rows not found</td><td><?php echo isset($stats['rows_not_found']) ? intval($stats['rows_not_found']) : 0; ?></td></tr>
                            <tr><td>Review queue count</td><td><?php echo isset($stats['review_queue_count']) ? intval($stats['review_queue_count']) : 0; ?></td></tr>
                        </tbody>
                    </table>

                    <?php $duplicates_ignored = isset($import_result['duplicates_ignored']) && is_array($import_result['duplicates_ignored']) ? $import_result['duplicates_ignored'] : array(); ?>
                    <?php if (!empty($duplicates_ignored)): ?>
                        <h3>Duplicates Ignored</h3>
                        <table class="widefat striped" style="max-width:920px;">
                            <thead>
                                <tr>
                                    <th>Resource ID</th>
                                    <th>Row Number</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($duplicates_ignored as $duplicate_item): ?>
                                    <tr>
                                        <td><?php echo isset($duplicate_item['resource_id']) ? intval($duplicate_item['resource_id']) : 0; ?></td>
                                        <td><?php echo isset($duplicate_item['row_number']) ? intval($duplicate_item['row_number']) : 0; ?></td>
                                        <td><?php echo esc_html(isset($duplicate_item['reason']) ? $duplicate_item['reason'] : 'duplicate'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php $review_queue = isset($import_result['review_queue']) && is_array($import_result['review_queue']) ? $import_result['review_queue'] : array(); ?>
                    <?php if (!empty($review_queue)): ?>
                        <h3>Review Queue</h3>
                        <table class="widefat striped" style="max-width:920px;">
                            <thead>
                                <tr>
                                    <th>Resource ID</th>
                                    <th>Row</th>
                                    <th>Reason</th>
                                    <th>Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($review_queue as $review_item): ?>
                                    <tr>
                                        <td><?php echo isset($review_item['resource_id']) ? intval($review_item['resource_id']) : 0; ?></td>
                                        <td><?php echo isset($review_item['row_number']) ? intval($review_item['row_number']) : 0; ?></td>
                                        <td><?php echo esc_html(isset($review_item['reason']) ? $review_item['reason'] : 'review'); ?></td>
                                        <td><?php echo esc_html(isset($review_item['message']) ? $review_item['message'] : ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Roll back taxonomy changes for a specific import run ID.
     *
     * @return void
     */
    public function rollback_taxonomy_import() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer(self::ROLLBACK_NONCE_ACTION);

        $import_run_id = isset($_POST['import_run_id']) ? sanitize_text_field(wp_unslash($_POST['import_run_id'])) : '';
        $redirect_base = admin_url('admin.php');

        if ($import_run_id === '') {
            wp_safe_redirect(add_query_arg(array(
                'page' => 'monday-resources-taxonomy-import',
                'rollback_status' => 'error',
                'rollback_message' => 'Import Run ID is required.'
            ), $redirect_base));
            exit;
        }

        if (!class_exists('Resource_Taxonomy_Import')) {
            wp_safe_redirect(add_query_arg(array(
                'page' => 'monday-resources-taxonomy-import',
                'rollback_status' => 'error',
                'rollback_message' => 'Rollback service is unavailable.'
            ), $redirect_base));
            exit;
        }

        $rollback = Resource_Taxonomy_Import::rollback_import_run($import_run_id, get_current_user_id());
        $status = !empty($rollback['success']) ? 'success' : 'error';
        $message = !empty($rollback['message']) ? $rollback['message'] : 'Rollback finished.';

        wp_safe_redirect(add_query_arg(array(
            'page' => 'monday-resources-taxonomy-import',
            'rollback_status' => $status,
            'rollback_message' => $message
        ), $redirect_base));
        exit;
    }

    /**
     * Issue reports page
     */
    public function issues_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_issues';

        // Get all issues ordered by newest first
        $issues = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

        ?>
        <div class="wrap">
            <h1>Issue Reports</h1>
            <p>Review issues reported by users about resource listings.</p>

            <?php if (empty($issues)): ?>
                <p>No issues reported yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Resource Name</th>
                            <th>Issue Type</th>
                            <th>Description</th>
                            <th>Reporter</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issues as $issue): ?>
                            <tr>
                                <td><?php echo esc_html(date('M j, Y g:i a', strtotime($issue->created_at))); ?></td>
                                <td><strong><?php echo esc_html($issue->resource_name); ?></strong></td>
                                <td><?php echo esc_html($issue->issue_type); ?></td>
                                <td><?php echo esc_html($issue->issue_description); ?></td>
                                <td>
                                    <?php if (!empty($issue->reporter_name)): ?>
                                        <?php echo esc_html($issue->reporter_name); ?>
                                        <?php if (!empty($issue->reporter_email)): ?>
                                            <br><a href="mailto:<?php echo esc_attr($issue->reporter_email); ?>"><?php echo esc_html($issue->reporter_email); ?></a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Anonymous
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="update_issue_status">
                                        <input type="hidden" name="issue_id" value="<?php echo esc_attr($issue->id); ?>">
                                        <?php wp_nonce_field('update_issue_status_' . $issue->id); ?>
                                        <select name="status" onchange="this.form.submit()">
                                            <option value="pending" <?php selected($issue->status, 'pending'); ?>>Pending</option>
                                            <option value="in_progress" <?php selected($issue->status, 'in_progress'); ?>>In Progress</option>
                                            <option value="resolved" <?php selected($issue->status, 'resolved'); ?>>Resolved</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_issue">
                                        <input type="hidden" name="issue_id" value="<?php echo esc_attr($issue->id); ?>">
                                        <?php wp_nonce_field('delete_issue_' . $issue->id); ?>
                                        <button type="submit" class="button button-small" onclick="return confirm('Are you sure you want to delete this issue report?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Resource submissions page
     */
    public function submissions_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_submissions';

        // Get all submissions ordered by newest first
        $submissions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

        ?>
        <div class="wrap">
            <h1>Resource Submissions</h1>
            <p>Review new resources submitted by users.</p>

            <?php if (empty($submissions)): ?>
                <p>No submissions yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Organization</th>
                            <th>Contact</th>
                            <th>Service Type</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td><?php echo esc_html(date('M j, Y g:i a', strtotime($submission->created_at))); ?></td>
                                <td><strong><?php echo esc_html($submission->organization_name); ?></strong></td>
                                <td>
                                    <?php if (!empty($submission->contact_name)): ?>
                                        <?php echo esc_html($submission->contact_name); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->contact_email)): ?>
                                        <a href="mailto:<?php echo esc_attr($submission->contact_email); ?>"><?php echo esc_html($submission->contact_email); ?></a><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->contact_phone)): ?>
                                        <a href="tel:<?php echo esc_attr($submission->contact_phone); ?>"><?php echo esc_html($submission->contact_phone); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($submission->service_type); ?></td>
                                <td>
                                    <?php if (!empty($submission->website)): ?>
                                        <strong>Website:</strong> <a href="<?php echo esc_url($submission->website); ?>" target="_blank"><?php echo esc_html($submission->website); ?></a><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->description)): ?>
                                        <strong>Description:</strong> <?php echo esc_html($submission->description); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->address)): ?>
                                        <strong>Address:</strong> <?php echo esc_html($submission->address); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($submission->counties_served)): ?>
                                        <strong>Counties:</strong> <?php echo esc_html($submission->counties_served); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="update_submission_status">
                                        <input type="hidden" name="submission_id" value="<?php echo esc_attr($submission->id); ?>">
                                        <?php wp_nonce_field('update_submission_status_' . $submission->id); ?>
                                        <select name="status" onchange="this.form.submit()">
                                            <option value="pending" <?php selected($submission->status, 'pending'); ?>>Pending</option>
                                            <option value="approved" <?php selected($submission->status, 'approved'); ?>>Approved</option>
                                            <option value="rejected" <?php selected($submission->status, 'rejected'); ?>>Rejected</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($submission->status === 'pending'): ?>
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline; margin-right: 5px;">
                                            <input type="hidden" name="action" value="approve_and_publish_submission">
                                            <input type="hidden" name="submission_id" value="<?php echo esc_attr($submission->id); ?>">
                                            <?php wp_nonce_field('approve_submission_' . $submission->id); ?>
                                            <button type="submit" class="button button-primary button-small">Approve & Publish</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_submission">
                                        <input type="hidden" name="submission_id" value="<?php echo esc_attr($submission->id); ?>">
                                        <?php wp_nonce_field('delete_submission_' . $submission->id); ?>
                                        <button type="submit" class="button button-small" onclick="return confirm('Are you sure you want to delete this submission?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Delete issue
     */
    public function delete_issue() {
        if (!current_user_can($this->get_resource_capability())) {
            wp_die('Unauthorized');
        }

        $issue_id = isset($_POST['issue_id']) ? intval($_POST['issue_id']) : 0;
        check_admin_referer('delete_issue_' . $issue_id);

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_issues';
        $wpdb->delete($table_name, array('id' => $issue_id), array('%d'));

        wp_redirect(admin_url('admin.php?page=monday-resources-issues'));
        exit;
    }

    /**
     * Delete submission
     */
    public function delete_submission() {
        if (!current_user_can($this->get_resource_capability())) {
            wp_die('Unauthorized');
        }

        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        check_admin_referer('delete_submission_' . $submission_id);

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_submissions';
        $wpdb->delete($table_name, array('id' => $submission_id), array('%d'));

        wp_redirect(admin_url('admin.php?page=monday-resources-submissions'));
        exit;
    }

    /**
     * Update issue status
     */
    public function update_issue_status() {
        if (!current_user_can($this->get_resource_capability())) {
            wp_die('Unauthorized');
        }

        $issue_id = isset($_POST['issue_id']) ? intval($_POST['issue_id']) : 0;
        check_admin_referer('update_issue_status_' . $issue_id);

        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_issues';
        $wpdb->update(
            $table_name,
            array('status' => $status),
            array('id' => $issue_id),
            array('%s'),
            array('%d')
        );

        wp_redirect(admin_url('admin.php?page=monday-resources-issues'));
        exit;
    }

    /**
     * Update submission status
     */
    public function update_submission_status() {
        if (!current_user_can($this->get_resource_capability())) {
            wp_die('Unauthorized');
        }

        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        check_admin_referer('update_submission_status_' . $submission_id);

        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';

        global $wpdb;
        $table_name = $wpdb->prefix . 'monday_resource_submissions';
        $wpdb->update(
            $table_name,
            array('status' => $status),
            array('id' => $submission_id),
            array('%s'),
            array('%d')
        );

        wp_redirect(admin_url('admin.php?page=monday-resources-submissions'));
        exit;
    }

    /**
     * Delete a resource
     */
    public function delete_resource() {
        if (!current_user_can($this->get_resource_capability())) {
            wp_die('Unauthorized');
        }

        $resource_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        check_admin_referer('delete_resource_' . $resource_id);

        $success = Resources_Manager::delete_resource($resource_id);

        wp_redirect(add_query_arg('deleted', $success ? '1' : '0', admin_url('admin.php?page=monday-resources-manage')));
        exit;
    }

    /**
     * Bulk action handler
     */
    public function bulk_action_resources() {
        if (!current_user_can($this->get_resource_capability())) {
            wp_die('Unauthorized');
        }

        check_admin_referer('bulk_action_resources');

        $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $resource_ids = isset($_POST['resource_ids']) ? array_map('intval', $_POST['resource_ids']) : array();

        if (empty($bulk_action) || empty($resource_ids)) {
            wp_redirect(admin_url('admin.php?page=monday-resources-manage'));
            exit;
        }

        if ($bulk_action === 'delete') {
            foreach ($resource_ids as $id) {
                Resources_Manager::delete_resource($id);
            }
        }

        wp_redirect(add_query_arg('deleted', count($resource_ids), admin_url('admin.php?page=monday-resources-manage')));
        exit;
    }

    /**
     * Add New Resource page
     */
    public function add_resource_page() {
        $resource = null;

        // Check if duplicating existing resource
        if (isset($_GET['duplicate']) && !empty($_GET['duplicate'])) {
            $duplicate_id = intval($_GET['duplicate']);

            // Get resource with hours data
            $original = Resources_Manager::get_resource_with_hours($duplicate_id);

            if ($original) {
                $resource = $original;

                // Clear ID to force new creation
                unset($resource['id']);

                // Append "(Copy)" to resource name
                $resource['resource_name'] = $resource['resource_name'] . ' (Copy)';

                // Keep verification data from original (last_verified_date, verification_status)

                // Clear creation/update metadata
                unset($resource['created_at']);
                unset($resource['updated_at']);
                unset($resource['created_by']);
                unset($resource['updated_by']);

                // Mark as duplicate operation
                $resource['_is_duplicate'] = true;
            }
        }

        $this->resource_form_page($resource);
    }

    /**
     * Edit Resource page
     */
    public function edit_resource_page() {
        $resource_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $resource = Resources_Manager::get_resource($resource_id);

        if (!$resource) {
            wp_die('Resource not found');
        }

        $this->resource_form_page($resource);
    }

    /**
     * Resource form page (used for both add and edit)
     */
    private function resource_form_page($resource = null) {
        $is_duplicate = !empty($resource) && isset($resource['_is_duplicate']);
        $is_edit = !empty($resource) && !$is_duplicate;

        // For form population: TRUE if we have data (edit OR duplicate)
        $has_data = !empty($resource);
        $resource_id = $is_edit ? intval($resource['id']) : 0;

        if ($is_duplicate) {
            $page_title = 'Add New Resource (Duplicating: ' . esc_html($resource['resource_name']) . ')';
            unset($resource['_is_duplicate']); // Clean up flag
        } else {
            $page_title = $has_data ? 'Edit Resource' : 'Add New Resource';
        }
        ?>
        <div class="wrap">
            <h1><?php echo $page_title; ?></h1>

            <?php if (isset($_GET['verified']) && $_GET['verified'] == '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo isset($_GET['message']) ? esc_html(urldecode($_GET['message'])) : 'Resource verified!'; ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['added']) && $_GET['added'] == '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Resource saved successfully! You can now add another resource.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'missing_service_area'): ?>
                <div class="notice notice-error is-dismissible">
                    <p>Service Area is required. Please choose one Service Area before saving.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="max-width: 800px;">
                <input type="hidden" name="action" value="save_resource">
                <?php if ($is_edit): ?>
                    <input type="hidden" name="resource_id" value="<?php echo $resource['id']; ?>">
                    <?php wp_nonce_field('save_resource_' . $resource['id']); ?>
                <?php else: ?>
                    <?php wp_nonce_field('save_resource_new'); ?>
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="resource_name">Resource Name *</label></th>
                        <td>
                            <input type="text" name="resource_name" id="resource_name" class="regular-text"
                                   value="<?php echo $has_data ? esc_attr($resource['resource_name']) : ''; ?>" required>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="organization">Organization/Agency</label></th>
                        <td>
                            <input type="text" name="organization" id="organization" class="regular-text"
                                   value="<?php echo $has_data ? esc_attr($resource['organization']) : ''; ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="is_svdp">SVdP Resource?</label></th>
                        <td>
                            <input type="checkbox" name="is_svdp" id="is_svdp" value="1"
                                   <?php echo ($is_edit && $resource['is_svdp']) ? 'checked' : ''; ?>>
                            <span class="description">Check if this is an SVdP-operated resource</span>
                        </td>
                    </tr>

                    <?php
                    $service_area_terms = $this->get_service_area_terms();
                    $services_offered_terms = $this->get_services_offered_terms();
                    $provider_type_terms = $this->get_provider_type_terms();
                    $selected_service_area = $has_data ? $this->resolve_selected_service_area($resource) : '';
                    $selected_services_offered = $has_data ? $this->resolve_selected_services_offered($resource) : array();
                    $selected_provider_type = $has_data ? $this->resolve_selected_provider_type($resource) : '';
                    ?>

                    <tr>
                        <th scope="row"><label>Service Area *</label></th>
                        <td>
                            <?php if (empty($service_area_terms)): ?>
                                <p style="margin: 0; color: #a00;">No Service Area terms are configured.</p>
                            <?php else: ?>
                                <fieldset id="service-area-fieldset" style="border: 1px solid #ddd; padding: 10px; background: #f9f9f9; max-width: 640px;">
                                    <?php $service_area_index = 0; ?>
                                    <?php foreach ($service_area_terms as $service_area_slug => $service_area_label): ?>
                                        <label style="display: block; margin: 5px 0;">
                                            <input
                                                type="radio"
                                                name="service_area"
                                                value="<?php echo esc_attr($service_area_slug); ?>"
                                                <?php checked($selected_service_area, $service_area_slug); ?>
                                                <?php echo $service_area_index === 0 ? 'required' : ''; ?>>
                                            <?php echo esc_html($service_area_label); ?>
                                        </label>
                                        <?php $service_area_index++; ?>
                                    <?php endforeach; ?>
                                </fieldset>
                                <p class="description">Required to save. Service Area is controlled by admin-defined canonical terms.</p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="services_offered_filter">Services Offered</label></th>
                        <td>
                            <?php if (empty($services_offered_terms)): ?>
                                <p style="margin: 0; color: #a00;">No Services Offered terms are configured.</p>
                            <?php else: ?>
                                <input
                                    type="text"
                                    id="services_offered_filter"
                                    placeholder="Filter services offered..."
                                    style="width: 100%; max-width: 520px; margin-bottom: 8px;">
                                <div id="services_offered_list" style="max-height: 260px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; max-width: 640px;">
                                    <?php foreach ($services_offered_terms as $service_offered_slug => $service_offered_label): ?>
                                        <label class="services-offered-option" data-filter-text="<?php echo esc_attr(strtolower($service_offered_label)); ?>" style="display: block; margin: 5px 0;">
                                            <input
                                                type="checkbox"
                                                name="services_offered[]"
                                                value="<?php echo esc_attr($service_offered_slug); ?>"
                                                <?php checked(in_array($service_offered_slug, $selected_services_offered, true)); ?>>
                                            <?php echo esc_html($service_offered_label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p id="services-offered-warning" class="description" style="display: none; color: #b45309; font-weight: 600;">
                                    Heads up: selecting more than 5 services may broaden results significantly.
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="provider_type_toggle">System Type (rare)</label></th>
                        <td>
                            <?php if (empty($provider_type_terms)): ?>
                                <p style="margin: 0; color: #a00;">No Provider Type terms are configured.</p>
                            <?php else: ?>
                                <button type="button" id="provider_type_toggle" class="button">System Type (rare)</button>
                                <div id="provider_type_panel" style="display: none; margin-top: 10px; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; max-width: 640px;">
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="radio" name="provider_type" value="" <?php checked($selected_provider_type, ''); ?>>
                                        Not Set
                                    </label>
                                    <?php foreach ($provider_type_terms as $provider_type_slug => $provider_type_label): ?>
                                        <label style="display: block; margin: 5px 0;">
                                            <input type="radio" name="provider_type" value="<?php echo esc_attr($provider_type_slug); ?>" <?php checked($selected_provider_type, $provider_type_slug); ?>>
                                            <?php echo esc_html($provider_type_label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description">Optional. Intended for infrequent system-level classification.</p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="phone">Phone</label></th>
                        <td>
                            <input type="tel" name="phone" id="phone" class="regular-text"
                                   value="<?php echo $has_data ? esc_attr($resource['phone']) : ''; ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="phone_extension">Phone Extension</label></th>
                        <td>
                            <input type="text" name="phone_extension" id="phone_extension" class="small-text"
                                   value="<?php echo $has_data ? esc_attr($resource['phone_extension']) : ''; ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="alternate_phone">Alternate Phone</label></th>
                        <td>
                            <input type="tel" name="alternate_phone" id="alternate_phone" class="regular-text"
                                   value="<?php echo $has_data ? esc_attr($resource['alternate_phone']) : ''; ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="email">Email</label></th>
                        <td>
                            <input type="email" name="email" id="email" class="regular-text"
                                   value="<?php echo $has_data ? esc_attr($resource['email']) : ''; ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="website">Website</label></th>
                        <td>
                            <input type="url" name="website" id="website" class="regular-text"
                                   value="<?php echo $has_data ? esc_attr($resource['website']) : ''; ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="physical_address">Physical Address</label></th>
                        <td>
                            <textarea name="physical_address" id="physical_address" class="large-text" rows="3"><?php echo $has_data ? esc_textarea($resource['physical_address']) : ''; ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="what_they_provide">What They Provide</label></th>
                        <td>
                            <textarea name="what_they_provide" id="what_they_provide" class="large-text" rows="4"><?php echo $has_data ? esc_textarea($resource['what_they_provide']) : ''; ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="how_to_apply">How to Apply</label></th>
                        <td>
                            <textarea name="how_to_apply" id="how_to_apply" class="large-text" rows="3"><?php echo $has_data ? esc_textarea($resource['how_to_apply']) : ''; ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="documents_required">Documents Required</label></th>
                        <td>
                            <textarea name="documents_required" id="documents_required" class="large-text" rows="5"
                                      placeholder="Driver's License or State ID&#10;Proof of Income (pay stubs, tax return)&#10;Utility bill showing current address&#10;Social Security cards for all household members"><?php echo $has_data ? esc_textarea($resource['documents_required']) : ''; ?></textarea>
                            <p class="description">
                                <strong>Enter one document per line</strong> - Each line will display as a bullet point. 
                                You can also include fee information here (e.g., "$25 application fee").
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row" style="vertical-align: top; padding-top: 15px;"><label>Hours of Operation</label></th>
                        <td>
                            <?php
                            // Get existing hours data if editing or duplicating
                            $hours_data = null;
                            if ($is_edit) {
                                $hours_data = Resource_Hours_Manager::get_hours($resource_id);
                            } elseif (!empty($resource) && isset($resource['hours'])) {
                                // Duplicating - hours loaded from original
                                $hours_data = $resource['hours'];
                            }

                            // Default empty hours structure
                            if (!$hours_data) {
                                $hours_data = array(
                                    'flags' => array(
                                        'is_24_7' => false,
                                        'is_by_appointment' => false,
                                        'is_call_for_availability' => false,
                                        'is_currently_closed' => false
                                    ),
                                    'special_notes' => '',
                                    'office_hours' => array(),
                                    'service_hours' => array()
                                );
                            }
                            ?>

                            <?php
                            // Ensure we have separate flags for office and service
                            if (!isset($hours_data['office_flags'])) {
                                $hours_data['office_flags'] = isset($hours_data['flags']) ? $hours_data['flags'] : array(
                                    'is_24_7' => false,
                                    'is_by_appointment' => false,
                                    'is_call_for_availability' => false,
                                    'is_currently_closed' => false,
                                    'special_notes' => ''
                                );
                            }
                            if (!isset($hours_data['service_flags'])) {
                                $hours_data['service_flags'] = array(
                                    'is_24_7' => false,
                                    'is_by_appointment' => false,
                                    'is_call_for_availability' => false,
                                    'is_currently_closed' => false,
                                    'special_notes' => ''
                                );
                            }
                            ?>

                            <div class="hours-of-operation-section" style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">

                                <!-- Office Hours Special Situations -->
                                <div style="margin-bottom: 20px; padding: 10px; background: #fff; border-left: 3px solid #0073aa;">
                                    <h4 style="margin-top: 0;">Office Hours Special Situations</h4>
                                    <p class="description">These apply to when your office is reachable by phone/email</p>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="office_flags[is_24_7]" value="1"
                                               <?php checked(!empty($hours_data['office_flags']['is_24_7'])); ?>>
                                        Office Open 24/7
                                    </label>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="office_flags[is_by_appointment]" value="1"
                                               <?php checked(!empty($hours_data['office_flags']['is_by_appointment'])); ?>>
                                        Office By Appointment Only
                                    </label>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="office_flags[is_call_for_availability]" value="1"
                                               <?php checked(!empty($hours_data['office_flags']['is_call_for_availability'])); ?>>
                                        Call for Office Availability
                                    </label>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="office_flags[is_currently_closed]" value="1"
                                               <?php checked(!empty($hours_data['office_flags']['is_currently_closed'])); ?>>
                                        Office Currently Closed
                                    </label>
                                    <div style="margin-top: 10px;">
                                        <label><strong>Office Special Notes:</strong></label>
                                        <textarea name="office_flags[special_notes]" class="large-text" rows="2"
                                                  placeholder="Holiday closures, special office hours, etc."><?php
                                            echo esc_textarea(!empty($hours_data['office_flags']['special_notes']) ? $hours_data['office_flags']['special_notes'] : '');
                                        ?></textarea>
                                    </div>
                                </div>

                                <!-- Office Hours (Full Implementation) -->
                                <div id="office_hours_section" style="margin-bottom: 20px;">
                                    <h4 style="margin-bottom: 10px;">Office Hours</h4>
                                    <p class="description" style="margin-bottom: 10px;">When the admin office is open for calls/inquiries</p>

                                    <?php
                                    $days = Resource_Hours_Manager::DAY_NAMES;
                                    for ($day = 0; $day <= 6; $day++):
                                        $day_hours = isset($hours_data['office_hours'][$day]) ? $hours_data['office_hours'][$day] : null;

                                        // Detect mode from existing data
                                        $current_mode = 'simple';
                                        $is_closed = false;
                                        $simple_open = '09:00';
                                        $simple_close = '17:00';
                                        $blocks = array();
                                        $recurring = array('pattern' => 'weekly', 'week' => 2, 'open' => '14:00', 'close' => '17:00');

                                        if ($day_hours) {
                                            if (isset($day_hours['mode'])) {
                                                $current_mode = $day_hours['mode'];
                                            }
                                            $is_closed = isset($day_hours['is_closed']) ? $day_hours['is_closed'] : false;

                                            if ($is_closed) {
                                                $current_mode = 'closed';
                                            } elseif (isset($day_hours['simple'])) {
                                                $simple_open = substr($day_hours['simple']['open'], 0, 5);
                                                $simple_close = substr($day_hours['simple']['close'], 0, 5);
                                            } elseif (isset($day_hours['blocks'])) {
                                                $blocks = $day_hours['blocks'];
                                            } elseif (isset($day_hours['recurring'])) {
                                                $recurring = $day_hours['recurring'];
                                            } elseif (isset($day_hours['open_time'])) {
                                                // Old format
                                                $simple_open = substr($day_hours['open_time'], 0, 5);
                                                $simple_close = substr($day_hours['close_time'], 0, 5);
                                            }
                                        } else {
                                            // Default: weekends closed
                                            if ($day == 0 || $day == 6) {
                                                $is_closed = true;
                                                $current_mode = 'closed';
                                            }
                                        }
                                    ?>
                                        <div class="hours-day-container" style="border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; background: #fff;" data-day="<?php echo $day; ?>" data-type="office">
                                            <h5 style="margin-top: 0;"><?php echo $days[$day]; ?></h5>

                                            <!-- Mode Selector -->
                                            <div class="hours-mode-selector" style="margin-bottom: 10px;">
                                                <label style="margin-right: 15px;">
                                                    <input type="radio" name="office_hours[<?php echo $day; ?>][mode]" value="closed"
                                                           class="hours-mode-radio" data-day="<?php echo $day; ?>" data-type="office"
                                                           <?php checked($current_mode, 'closed'); ?>>
                                                    Closed
                                                </label>
                                                <label style="margin-right: 15px;">
                                                    <input type="radio" name="office_hours[<?php echo $day; ?>][mode]" value="simple"
                                                           class="hours-mode-radio" data-day="<?php echo $day; ?>" data-type="office"
                                                           <?php checked($current_mode, 'simple'); ?>>
                                                    Regular Hours
                                                </label>
                                                <label style="margin-right: 15px;">
                                                    <input type="radio" name="office_hours[<?php echo $day; ?>][mode]" value="multiple"
                                                           class="hours-mode-radio" data-day="<?php echo $day; ?>" data-type="office"
                                                           <?php checked($current_mode, 'multiple'); ?>>
                                                    Multiple Blocks
                                                </label>
                                                <label>
                                                    <input type="radio" name="office_hours[<?php echo $day; ?>][mode]" value="recurring"
                                                           class="hours-mode-radio" data-day="<?php echo $day; ?>" data-type="office"
                                                           <?php checked($current_mode, 'recurring'); ?>>
                                                    Recurring Pattern
                                                </label>
                                            </div>

                                            <!-- Simple Hours -->
                                            <div class="hours-simple-container" style="<?php echo $current_mode !== 'simple' ? 'display:none;' : ''; ?>">
                                                <input type="time" name="office_hours[<?php echo $day; ?>][simple][open]"
                                                       value="<?php echo esc_attr($simple_open); ?>" style="margin-right: 5px;">
                                                to
                                                <input type="time" name="office_hours[<?php echo $day; ?>][simple][close]"
                                                       value="<?php echo esc_attr($simple_close); ?>" style="margin-left: 5px;">
                                            </div>

                                            <!-- Multiple Blocks -->
                                            <div class="hours-multiple-container" style="<?php echo $current_mode !== 'multiple' ? 'display:none;' : ''; ?>">
                                                <div class="hours-blocks-list" data-day="<?php echo $day; ?>" data-type="office">
                                                    <?php if (!empty($blocks)): ?>
                                                        <?php foreach ($blocks as $index => $block): ?>
                                                            <div class="hours-block-row" style="margin-bottom: 8px;">
                                                                <span style="margin-right: 5px;">Block <?php echo $index + 1; ?>:</span>
                                                                <input type="time" name="office_hours[<?php echo $day; ?>][blocks][<?php echo $index; ?>][open]"
                                                                       value="<?php echo esc_attr(substr($block['open'], 0, 5)); ?>" style="margin-right: 5px;">
                                                                to
                                                                <input type="time" name="office_hours[<?php echo $day; ?>][blocks][<?php echo $index; ?>][close]"
                                                                       value="<?php echo esc_attr(substr($block['close'], 0, 5)); ?>" style="margin: 0 5px;">
                                                                <input type="text" name="office_hours[<?php echo $day; ?>][blocks][<?php echo $index; ?>][label]"
                                                                       value="<?php echo esc_attr($block['label'] ?? ''); ?>"
                                                                       placeholder="Label (optional)" style="width: 120px; margin-right: 5px;">
                                                                <button type="button" class="button remove-block-btn" style="color: #a00;">Remove</button>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                                <button type="button" class="button add-block-btn" data-day="<?php echo $day; ?>" data-type="office">+ Add Time Block</button>
                                            </div>

                                            <!-- Recurring Pattern -->
                                            <div class="hours-recurring-container" style="<?php echo $current_mode !== 'recurring' ? 'display:none;' : ''; ?>">
                                                <select name="office_hours[<?php echo $day; ?>][recurring][pattern]"
                                                        class="recurring-pattern-select" data-day="<?php echo $day; ?>" data-type="office"
                                                        style="margin-right: 10px;">
                                                    <option value="weekly" <?php selected($recurring['pattern'] ?? 'weekly', 'weekly'); ?>>Weekly</option>
                                                    <option value="biweekly" <?php selected($recurring['pattern'] ?? '', 'biweekly'); ?>>Every Other Week</option>
                                                    <option value="monthly_week" <?php selected($recurring['pattern'] ?? '', 'monthly_week'); ?>>Monthly (Specific Week)</option>
                                                    <option value="monthly_date" <?php selected($recurring['pattern'] ?? '', 'monthly_date'); ?>>Monthly (Specific Date)</option>
                                                </select>

                                                <div class="recurring-monthly-week-fields" style="display: <?php echo ($recurring['pattern'] ?? '') === 'monthly_week' ? 'inline-block' : 'none'; ?>; margin-right: 10px;">
                                                    <select name="office_hours[<?php echo $day; ?>][recurring][week]">
                                                        <option value="1" <?php selected($recurring['week'] ?? 1, 1); ?>>1st</option>
                                                        <option value="2" <?php selected($recurring['week'] ?? 1, 2); ?>>2nd</option>
                                                        <option value="3" <?php selected($recurring['week'] ?? 1, 3); ?>>3rd</option>
                                                        <option value="4" <?php selected($recurring['week'] ?? 1, 4); ?>>4th</option>
                                                        <option value="5" <?php selected($recurring['week'] ?? 1, 5); ?>>Last</option>
                                                    </select>
                                                    <?php echo $days[$day]; ?> of the month
                                                </div>

                                                <div class="recurring-monthly-date-fields" style="display: <?php echo ($recurring['pattern'] ?? '') === 'monthly_date' ? 'inline-block' : 'none'; ?>; margin-right: 10px;">
                                                    Day <input type="number" name="office_hours[<?php echo $day; ?>][recurring][day_of_month]"
                                                               value="<?php echo esc_attr($recurring['day_of_month'] ?? 15); ?>"
                                                               min="1" max="31" style="width: 60px;"> of the month
                                                </div>

                                                <div style="margin-top: 10px;">
                                                    <input type="time" name="office_hours[<?php echo $day; ?>][recurring][open]"
                                                           value="<?php echo esc_attr(substr($recurring['open'] ?? '14:00', 0, 5)); ?>" style="margin-right: 5px;">
                                                    to
                                                    <input type="time" name="office_hours[<?php echo $day; ?>][recurring][close]"
                                                           value="<?php echo esc_attr(substr($recurring['close'] ?? '17:00', 0, 5)); ?>" style="margin-left: 5px;">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>

                                <!-- Service Hours Special Situations -->
                                <div style="margin-bottom: 20px; padding: 10px; background: #fff; border-left: 3px solid #00a32a;">
                                    <h4 style="margin-top: 0;">Service/Program Hours Special Situations</h4>
                                    <p class="description">These apply to when services/programs are actually available</p>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="service_flags[is_24_7]" value="1"
                                               <?php checked(!empty($hours_data['service_flags']['is_24_7'])); ?>>
                                        Service Available 24/7
                                    </label>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="service_flags[is_by_appointment]" value="1"
                                               <?php checked(!empty($hours_data['service_flags']['is_by_appointment'])); ?>>
                                        Service By Appointment Only
                                    </label>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="service_flags[is_call_for_availability]" value="1"
                                               <?php checked(!empty($hours_data['service_flags']['is_call_for_availability'])); ?>>
                                        Call for Service Availability
                                    </label>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="service_flags[is_currently_closed]" value="1"
                                               <?php checked(!empty($hours_data['service_flags']['is_currently_closed'])); ?>>
                                        Service Currently Unavailable
                                    </label>
                                    <div style="margin-top: 10px;">
                                        <label><strong>Service Special Notes:</strong></label>
                                        <textarea name="service_flags[special_notes]" class="large-text" rows="2"
                                                  placeholder="Service-specific notes, availability details, etc."><?php
                                            echo esc_textarea(!empty($hours_data['service_flags']['special_notes']) ? $hours_data['service_flags']['special_notes'] : '');
                                        ?></textarea>
                                    </div>
                                </div>

                                <!-- Service/Program Hours (Full Implementation) -->
                                <div id="service_hours_section" style="margin-bottom: 20px;">
                                    <h4 style="margin-bottom: 10px;">Service/Program Hours</h4>
                                    <p class="description" style="margin-bottom: 10px;">When services/programs are actually available</p>

                                    <label style="display: block; margin-bottom: 15px; padding: 10px; background: #fffbcc; border-left: 3px solid #ffb900;">
                                        <input type="checkbox" id="service_same_as_office" name="service_same_as_office" value="1"
                                               <?php if (!empty($hours_data['service_same_as_office'])) echo 'checked'; ?>>
                                        <strong>Same as Office Hours</strong> - Automatically copies all office hours settings and flags
                                    </label>

                                    <?php
                                    for ($day = 0; $day <= 6; $day++):
                                        $day_hours = isset($hours_data['service_hours'][$day]) ? $hours_data['service_hours'][$day] : null;

                                        // Detect mode from existing data
                                        $current_mode = 'simple';
                                        $is_closed = false;
                                        $simple_open = '09:00';
                                        $simple_close = '17:00';
                                        $blocks = array();
                                        $recurring = array('pattern' => 'weekly', 'week' => 2, 'open' => '14:00', 'close' => '17:00');

                                        if ($day_hours) {
                                            if (isset($day_hours['mode'])) {
                                                $current_mode = $day_hours['mode'];
                                            }
                                            $is_closed = isset($day_hours['is_closed']) ? $day_hours['is_closed'] : false;

                                            if ($is_closed) {
                                                $current_mode = 'closed';
                                            } elseif (isset($day_hours['simple'])) {
                                                $simple_open = substr($day_hours['simple']['open'], 0, 5);
                                                $simple_close = substr($day_hours['simple']['close'], 0, 5);
                                            } elseif (isset($day_hours['blocks'])) {
                                                $blocks = $day_hours['blocks'];
                                            } elseif (isset($day_hours['recurring'])) {
                                                $recurring = $day_hours['recurring'];
                                            } elseif (isset($day_hours['open_time'])) {
                                                // Old format
                                                $simple_open = substr($day_hours['open_time'], 0, 5);
                                                $simple_close = substr($day_hours['close_time'], 0, 5);
                                            }
                                        } else {
                                            // Default: weekends closed
                                            if ($day == 0 || $day == 6) {
                                                $is_closed = true;
                                                $current_mode = 'closed';
                                            }
                                        }
                                    ?>
                                        <div class="hours-day-container" style="border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; background: #fff;" data-day="<?php echo $day; ?>" data-type="service">
                                            <h5 style="margin-top: 0;"><?php echo $days[$day]; ?></h5>

                                            <!-- Mode Selector -->
                                            <div class="hours-mode-selector" style="margin-bottom: 10px;">
                                                <label style="margin-right: 15px;">
                                                    <input type="radio" name="service_hours[<?php echo $day; ?>][mode]" value="closed"
                                                           class="hours-mode-radio" data-day="<?php echo $day; ?>" data-type="service"
                                                           <?php checked($current_mode, 'closed'); ?>>
                                                    Closed
                                                </label>
                                                <label style="margin-right: 15px;">
                                                    <input type="radio" name="service_hours[<?php echo $day; ?>][mode]" value="simple"
                                                           class="hours-mode-radio" data-day="<?php echo $day; ?>" data-type="service"
                                                           <?php checked($current_mode, 'simple'); ?>>
                                                    Regular Hours
                                                </label>
                                                <label style="margin-right: 15px;">
                                                    <input type="radio" name="service_hours[<?php echo $day; ?>][mode]" value="multiple"
                                                           class="hours-mode-radio" data-day="<?php echo $day; ?>" data-type="service"
                                                           <?php checked($current_mode, 'multiple'); ?>>
                                                    Multiple Blocks
                                                </label>
                                                <label>
                                                    <input type="radio" name="service_hours[<?php echo $day; ?>][mode]" value="recurring"
                                                           class="hours-mode-radio" data-day="<?php echo $day; ?>" data-type="service"
                                                           <?php checked($current_mode, 'recurring'); ?>>
                                                    Recurring Pattern
                                                </label>
                                            </div>

                                            <!-- Simple Hours -->
                                            <div class="hours-simple-container" style="<?php echo $current_mode !== 'simple' ? 'display:none;' : ''; ?>">
                                                <input type="time" name="service_hours[<?php echo $day; ?>][simple][open]"
                                                       value="<?php echo esc_attr($simple_open); ?>" style="margin-right: 5px;">
                                                to
                                                <input type="time" name="service_hours[<?php echo $day; ?>][simple][close]"
                                                       value="<?php echo esc_attr($simple_close); ?>" style="margin-left: 5px;">
                                            </div>

                                            <!-- Multiple Blocks -->
                                            <div class="hours-multiple-container" style="<?php echo $current_mode !== 'multiple' ? 'display:none;' : ''; ?>">
                                                <div class="hours-blocks-list" data-day="<?php echo $day; ?>" data-type="service">
                                                    <?php if (!empty($blocks)): ?>
                                                        <?php foreach ($blocks as $index => $block): ?>
                                                            <div class="hours-block-row" style="margin-bottom: 8px;">
                                                                <span style="margin-right: 5px;">Block <?php echo $index + 1; ?>:</span>
                                                                <input type="time" name="service_hours[<?php echo $day; ?>][blocks][<?php echo $index; ?>][open]"
                                                                       value="<?php echo esc_attr(substr($block['open'], 0, 5)); ?>" style="margin-right: 5px;">
                                                                to
                                                                <input type="time" name="service_hours[<?php echo $day; ?>][blocks][<?php echo $index; ?>][close]"
                                                                       value="<?php echo esc_attr(substr($block['close'], 0, 5)); ?>" style="margin: 0 5px;">
                                                                <input type="text" name="service_hours[<?php echo $day; ?>][blocks][<?php echo $index; ?>][label]"
                                                                       value="<?php echo esc_attr($block['label'] ?? ''); ?>"
                                                                       placeholder="Label (optional)" style="width: 120px; margin-right: 5px;">
                                                                <button type="button" class="button remove-block-btn" style="color: #a00;">Remove</button>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                                <button type="button" class="button add-block-btn" data-day="<?php echo $day; ?>" data-type="service">+ Add Time Block</button>
                                            </div>

                                            <!-- Recurring Pattern -->
                                            <div class="hours-recurring-container" style="<?php echo $current_mode !== 'recurring' ? 'display:none;' : ''; ?>">
                                                <select name="service_hours[<?php echo $day; ?>][recurring][pattern]"
                                                        class="recurring-pattern-select" data-day="<?php echo $day; ?>" data-type="service"
                                                        style="margin-right: 10px;">
                                                    <option value="weekly" <?php selected($recurring['pattern'] ?? 'weekly', 'weekly'); ?>>Weekly</option>
                                                    <option value="biweekly" <?php selected($recurring['pattern'] ?? '', 'biweekly'); ?>>Every Other Week</option>
                                                    <option value="monthly_week" <?php selected($recurring['pattern'] ?? '', 'monthly_week'); ?>>Monthly (Specific Week)</option>
                                                    <option value="monthly_date" <?php selected($recurring['pattern'] ?? '', 'monthly_date'); ?>>Monthly (Specific Date)</option>
                                                </select>

                                                <div class="recurring-monthly-week-fields" style="display: <?php echo ($recurring['pattern'] ?? '') === 'monthly_week' ? 'inline-block' : 'none'; ?>; margin-right: 10px;">
                                                    <select name="service_hours[<?php echo $day; ?>][recurring][week]">
                                                        <option value="1" <?php selected($recurring['week'] ?? 1, 1); ?>>1st</option>
                                                        <option value="2" <?php selected($recurring['week'] ?? 1, 2); ?>>2nd</option>
                                                        <option value="3" <?php selected($recurring['week'] ?? 1, 3); ?>>3rd</option>
                                                        <option value="4" <?php selected($recurring['week'] ?? 1, 4); ?>>4th</option>
                                                        <option value="5" <?php selected($recurring['week'] ?? 1, 5); ?>>Last</option>
                                                    </select>
                                                    <?php echo $days[$day]; ?> of the month
                                                </div>

                                                <div class="recurring-monthly-date-fields" style="display: <?php echo ($recurring['pattern'] ?? '') === 'monthly_date' ? 'inline-block' : 'none'; ?>; margin-right: 10px;">
                                                    Day <input type="number" name="service_hours[<?php echo $day; ?>][recurring][day_of_month]"
                                                               value="<?php echo esc_attr($recurring['day_of_month'] ?? 15); ?>"
                                                               min="1" max="31" style="width: 60px;"> of the month
                                                </div>

                                                <div style="margin-top: 10px;">
                                                    <input type="time" name="service_hours[<?php echo $day; ?>][recurring][open]"
                                                           value="<?php echo esc_attr(substr($recurring['open'] ?? '14:00', 0, 5)); ?>" style="margin-right: 5px;">
                                                    to
                                                    <input type="time" name="service_hours[<?php echo $day; ?>][recurring][close]"
                                                           value="<?php echo esc_attr(substr($recurring['close'] ?? '17:00', 0, 5)); ?>" style="margin-left: 5px;">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>

                                <!-- Legacy text field (hidden, for backward compatibility) -->
                                <input type="hidden" name="hours_of_operation" value="<?php echo $has_data ? esc_attr($resource['hours_of_operation']) : ''; ?>">
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label>Target Population</label></th>
                        <td>
                            <?php
                            $target_population_options = get_option('resource_target_population_options', array());
                            $selected_populations = array();
                            if ($has_data && !empty($resource['target_population'])) {
                                $selected_populations = array_map('trim', explode(',', $resource['target_population']));
                            }
                            ?>
                            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                                <?php if (empty($target_population_options)): ?>
                                    <p>No target population options available. Please add options in Settings.</p>
                                <?php else: ?>
                                    <?php foreach ($target_population_options as $population): ?>
                                        <label style="display: block; margin: 5px 0;">
                                            <input type="checkbox" name="target_population[]"
                                                   value="<?php echo esc_attr($population); ?>"
                                                   <?php checked(in_array($population, $selected_populations)); ?>>
                                            <?php echo esc_html($population); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <p class="description">Select all populations this resource serves (multiple selection allowed)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="income_requirements">Income Requirements</label></th>
                        <td>
                            <?php
                            $income_requirements_options = get_option('resource_income_requirements_options', array());
                            $selected_income = $has_data ? $resource['income_requirements'] : '';
                            ?>
                            <select name="income_requirements" id="income_requirements" style="width: 500px; font-size: 15px;">
                                <option value="">Select income requirement...</option>
                                <?php foreach ($income_requirements_options as $income_option): ?>
                                    <option value="<?php echo esc_attr($income_option); ?>"
                                            <?php selected($selected_income, $income_option); ?>>
                                        <?php echo esc_html($income_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select the income eligibility requirement for this resource</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="residency_requirements">Residency Requirements</label></th>
                        <td>
                            <textarea name="residency_requirements" id="residency_requirements" class="large-text" rows="2"><?php echo $has_data ? esc_textarea($resource['residency_requirements']) : ''; ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="other_eligibility">Other Eligibility Requirements</label></th>
                        <td>
                            <textarea name="other_eligibility" id="other_eligibility" class="large-text" rows="5"
                  placeholder="Must be 18 years or older&#10;Must have valid photo ID&#10;First-time applicants only&#10;Must attend orientation session"><?php echo $has_data ? esc_textarea($resource['other_eligibility']) : ''; ?></textarea>
                            <p class="description">
                                <strong>Enter one requirement per line</strong> - Each line will display as a bullet point.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="eligibility_notes">Eligibility Notes</label></th>
                        <td>
                            <textarea name="eligibility_notes" id="eligibility_notes" class="large-text" rows="2"><?php echo $has_data ? esc_textarea($resource['eligibility_notes']) : ''; ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label>Conferences</label></th>
                        <td>
                            <?php
                            $conferences = get_option('resource_conference_options', array());
                            $selected_conferences = array();
                            if ($has_data && !empty($resource['geography'])) {
                                $selected_conferences = array_map('trim', explode(',', $resource['geography']));
                            }
                            ?>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                                <?php foreach ($conferences as $conference): ?>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="geography[]"
                                               value="<?php echo esc_attr($conference); ?>"
                                               <?php checked(in_array($conference, $selected_conferences)); ?>>
                                        <?php echo esc_html($conference); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description">Select all conferences this resource serves</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label>Counties Served</label></th>
                        <td>
                            <?php
                            $counties = get_option('resource_counties_options', array());
                            $selected_counties = array();
                            if ($has_data && !empty($resource['counties_served'])) {
                                $selected_counties = array_map('trim', explode(',', $resource['counties_served']));
                            }
                            ?>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                                <?php foreach ($counties as $county): ?>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="counties_served[]"
                                               value="<?php echo esc_attr($county); ?>"
                                               <?php checked(in_array($county, $selected_counties)); ?>>
                                        <?php echo esc_html($county); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description">Select all counties this resource serves</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wait_time">Wait Time</label></th>
                        <td>
                            <?php
                            $wait_time_options = get_option('resource_wait_time_options', array());
                            $selected_wait_time = $has_data ? $resource['wait_time'] : '';
                            ?>
                            <select name="wait_time" id="wait_time" style="width: 400px; font-size: 15px;">
                                <option value="">Select wait time...</option>
                                <?php foreach ($wait_time_options as $wait_option): ?>
                                    <option value="<?php echo esc_attr($wait_option); ?>"
                                            <?php selected($selected_wait_time, $wait_option); ?>>
                                        <?php echo esc_html($wait_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select the typical wait time for this resource</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="notes_and_tips">Notes & Tips</label></th>
                        <td>
                            <textarea name="notes_and_tips" id="notes_and_tips" class="large-text" rows="3"><?php echo $has_data ? esc_textarea($resource['notes_and_tips']) : ''; ?></textarea>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo $has_data ? 'Update Resource' : 'Add Resource'; ?>">
                    <input type="submit" name="save_and_new" id="save_and_new" class="button button-secondary"
                           value="<?php echo $has_data ? 'Save & Add Another' : 'Add & Create Another'; ?>"
                           style="margin-left: 10px;">
                    <a href="<?php echo admin_url('admin.php?page=monday-resources-manage'); ?>" class="button">Cancel</a>
                </p>
            </form>

            <?php if ($is_edit): ?>
                <hr style="margin: 40px 0;">
                <?php Verification_System::render_verification_checklist_ui($resource['id']); ?>
            <?php endif; ?>

            <script>
                (function() {
                    var filterInput = document.getElementById('services_offered_filter');
                    var options = document.querySelectorAll('.services-offered-option');
                    var warning = document.getElementById('services-offered-warning');
                    var providerToggle = document.getElementById('provider_type_toggle');
                    var providerPanel = document.getElementById('provider_type_panel');

                    function updateServicesWarning() {
                        if (!warning) {
                            return;
                        }
                        var selectedCount = document.querySelectorAll('input[name="services_offered[]"]:checked').length;
                        warning.style.display = selectedCount > 5 ? 'block' : 'none';
                    }

                    function filterServicesOffered() {
                        if (!filterInput || !options.length) {
                            return;
                        }

                        var query = filterInput.value.toLowerCase().trim();
                        options.forEach(function(option) {
                            var text = (option.getAttribute('data-filter-text') || '').toLowerCase();
                            option.style.display = query === '' || text.indexOf(query) !== -1 ? 'block' : 'none';
                        });
                    }

                    if (filterInput) {
                        filterInput.addEventListener('input', filterServicesOffered);
                    }

                    document.querySelectorAll('input[name="services_offered[]"]').forEach(function(input) {
                        input.addEventListener('change', updateServicesWarning);
                    });

                    if (providerToggle && providerPanel) {
                        providerToggle.addEventListener('click', function() {
                            providerPanel.style.display = providerPanel.style.display === 'none' || providerPanel.style.display === '' ? 'block' : 'none';
                        });
                    }

                    updateServicesWarning();
                })();
            </script>
        </div>
        <?php
    }

    /**
     * Save resource (create or update)
     */
    public function save_resource() {
        if (!current_user_can($this->get_resource_capability())) {
            wp_die('Unauthorized');
        }

        $resource_id = isset($_POST['resource_id']) ? intval($_POST['resource_id']) : 0;

        if ($resource_id) {
            check_admin_referer('save_resource_' . $resource_id);
        } else {
            check_admin_referer('save_resource_new');
        }

        // Collect form data
        // Handle geography array (checkboxes - conferences)
        $geography = '';
        if (isset($_POST['geography']) && is_array($_POST['geography'])) {
            $geography = implode(', ', array_map('sanitize_text_field', wp_unslash($_POST['geography'])));
        }

        // Handle counties_served array (checkboxes)
        $counties_served = '';
        if (isset($_POST['counties_served']) && is_array($_POST['counties_served'])) {
            $counties_served = implode(', ', array_map('sanitize_text_field', wp_unslash($_POST['counties_served'])));
        }

        // Handle target_population array (checkboxes)
        $target_population = '';
        if (isset($_POST['target_population']) && is_array($_POST['target_population'])) {
            $target_population = implode(', ', array_map('sanitize_text_field', wp_unslash($_POST['target_population'])));
        }

        $service_area = '';
        if (isset($_POST['service_area']) && class_exists('Resource_Taxonomy')) {
            $service_area = Resource_Taxonomy::normalize_service_area_slug(wp_unslash($_POST['service_area']));
        }

        if ($service_area === '') {
            $redirect_args = array('page' => $resource_id ? 'monday-resources-edit' : 'monday-resources-add', 'error' => 'missing_service_area');
            if ($resource_id) {
                $redirect_args['id'] = $resource_id;
            }
            wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        $services_offered_slugs = array();
        if (isset($_POST['services_offered']) && class_exists('Resource_Taxonomy')) {
            $raw_services_offered = is_array($_POST['services_offered']) ? wp_unslash($_POST['services_offered']) : array();
            $services_offered_slugs = Resource_Taxonomy::normalize_services_offered_slugs($raw_services_offered);
        }

        $services_offered_pipe = class_exists('Resource_Taxonomy')
            ? Resource_Taxonomy::to_pipe_slug_string($services_offered_slugs)
            : '';

        $provider_type = '';
        if (isset($_POST['provider_type']) && class_exists('Resource_Taxonomy')) {
            $provider_type = Resource_Taxonomy::normalize_provider_type_slug(wp_unslash($_POST['provider_type']));
        }

        // Keep legacy columns synchronized during rollback window.
        $service_area_terms = $this->get_service_area_terms();
        $services_offered_terms = $this->get_services_offered_terms();
        $legacy_primary_service_type = isset($service_area_terms[$service_area]) ? $service_area_terms[$service_area] : '';

        $legacy_secondary_labels = array();
        foreach ($services_offered_slugs as $service_slug) {
            if (isset($services_offered_terms[$service_slug])) {
                $legacy_secondary_labels[] = $services_offered_terms[$service_slug];
            }
        }
        $legacy_secondary_service_type = implode(', ', $legacy_secondary_labels);

        $data = array(
            'resource_name' => isset($_POST['resource_name']) ? sanitize_text_field(wp_unslash($_POST['resource_name'])) : '',
            'organization' => isset($_POST['organization']) ? sanitize_text_field(wp_unslash($_POST['organization'])) : '',
            'is_svdp' => isset($_POST['is_svdp']) ? 1 : 0,
            'primary_service_type' => $legacy_primary_service_type,
            'secondary_service_type' => $legacy_secondary_service_type,
            'service_area' => $service_area,
            'services_offered' => $services_offered_pipe,
            'provider_type' => $provider_type,
            'phone' => isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '',
            'phone_extension' => isset($_POST['phone_extension']) ? sanitize_text_field(wp_unslash($_POST['phone_extension'])) : '',
            'alternate_phone' => isset($_POST['alternate_phone']) ? sanitize_text_field(wp_unslash($_POST['alternate_phone'])) : '',
            'email' => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
            'website' => isset($_POST['website']) ? esc_url_raw(wp_unslash($_POST['website'])) : '',
            'physical_address' => isset($_POST['physical_address']) ? sanitize_textarea_field(wp_unslash($_POST['physical_address'])) : '',
            'what_they_provide' => isset($_POST['what_they_provide']) ? sanitize_textarea_field(wp_unslash($_POST['what_they_provide'])) : '',
            'how_to_apply' => isset($_POST['how_to_apply']) ? sanitize_textarea_field(wp_unslash($_POST['how_to_apply'])) : '',
            'documents_required' => isset($_POST['documents_required']) ? sanitize_textarea_field(wp_unslash($_POST['documents_required'])) : '',
            'hours_of_operation' => isset($_POST['hours_of_operation']) ? sanitize_text_field(wp_unslash($_POST['hours_of_operation'])) : '',
            'target_population' => $target_population,
            'income_requirements' => isset($_POST['income_requirements']) ? sanitize_text_field(wp_unslash($_POST['income_requirements'])) : '',
            'residency_requirements' => isset($_POST['residency_requirements']) ? sanitize_textarea_field(wp_unslash($_POST['residency_requirements'])) : '',
            'other_eligibility' => isset($_POST['other_eligibility']) ? sanitize_textarea_field(wp_unslash($_POST['other_eligibility'])) : '',
            'eligibility_notes' => isset($_POST['eligibility_notes']) ? sanitize_textarea_field(wp_unslash($_POST['eligibility_notes'])) : '',
            'geography' => $geography,
            'counties_served' => $counties_served,
            'wait_time' => isset($_POST['wait_time']) ? sanitize_text_field(wp_unslash($_POST['wait_time'])) : '',
            'notes_and_tips' => isset($_POST['notes_and_tips']) ? sanitize_textarea_field(wp_unslash($_POST['notes_and_tips'])) : ''
        );

        if (class_exists('Resource_Organization_Manager')) {
            $organization_name = trim((string) $data['organization']);
            if ($organization_name === '') {
                $data['organization_id'] = null;
            } else {
                $data['organization_id'] = Resource_Organization_Manager::upsert_organization($organization_name);
            }
        }

        // Detect which button was clicked
        $save_and_new = isset($_POST['save_and_new']);

        if ($resource_id) {
            // Update existing resource
            $success = Resources_Manager::update_resource($resource_id, $data);
            $saved_resource_id = $resource_id;

            if ($save_and_new) {
                $redirect_page = 'monday-resources-add';
                $redirect_args = array('page' => $redirect_page, 'added' => '1');
            } else {
                $redirect_page = 'monday-resources-edit';
                $redirect_args = array('page' => $redirect_page, 'id' => $resource_id, 'saved' => $success ? '1' : '0');
            }
        } else {
            // Create new resource (auto-marked as verified)
            $new_id = Resources_Manager::create_resource($data);
            $success = $new_id !== false;
            $saved_resource_id = $new_id;

            if ($save_and_new && $success) {
                $redirect_page = 'monday-resources-add';
                $redirect_args = array('page' => $redirect_page, 'added' => '1');
            } else {
                $redirect_page = $success ? 'monday-resources-edit' : 'monday-resources-add';
                $redirect_args = $success
                    ? array('page' => $redirect_page, 'id' => $new_id, 'saved' => '1')
                    : array('page' => $redirect_page, 'error' => '1');
            }
        }

        // Save structured hours data if resource was saved successfully
        if ($success && $saved_resource_id) {
            // Check if "Same as Office Hours" is checked
            $service_same_as_office = isset($_POST['service_same_as_office']) ? 1 : 0;

            // Build hours data structure with new separate flags
            $hours_data = array(
                'office_flags' => array(
                    'is_24_7' => isset($_POST['office_flags']['is_24_7']) ? 1 : 0,
                    'is_by_appointment' => isset($_POST['office_flags']['is_by_appointment']) ? 1 : 0,
                    'is_call_for_availability' => isset($_POST['office_flags']['is_call_for_availability']) ? 1 : 0,
                    'is_currently_closed' => isset($_POST['office_flags']['is_currently_closed']) ? 1 : 0,
                    'special_notes' => isset($_POST['office_flags']['special_notes']) ? sanitize_textarea_field(wp_unslash($_POST['office_flags']['special_notes'])) : ''
                ),
                'service_flags' => array(
                    'is_24_7' => isset($_POST['service_flags']['is_24_7']) ? 1 : 0,
                    'is_by_appointment' => isset($_POST['service_flags']['is_by_appointment']) ? 1 : 0,
                    'is_call_for_availability' => isset($_POST['service_flags']['is_call_for_availability']) ? 1 : 0,
                    'is_currently_closed' => isset($_POST['service_flags']['is_currently_closed']) ? 1 : 0,
                    'special_notes' => isset($_POST['service_flags']['special_notes']) ? sanitize_textarea_field(wp_unslash($_POST['service_flags']['special_notes'])) : ''
                ),
                'service_same_as_office' => $service_same_as_office,
                'office_hours' => array(),
                'service_hours' => array()
            );

            // Process office hours with new structure (mode-based)
            if (isset($_POST['office_hours']) && is_array($_POST['office_hours'])) {
                $hours_data['office_hours'] = $this->process_hours_data($_POST['office_hours']);
            }

            // Process service hours
            if ($service_same_as_office) {
                // Copy office hours to service hours
                $hours_data['service_hours'] = $hours_data['office_hours'];
            } else {
                if (isset($_POST['service_hours']) && is_array($_POST['service_hours'])) {
                    $hours_data['service_hours'] = $this->process_hours_data($_POST['service_hours']);
                }
            }

            // Save hours to database
            Resource_Hours_Manager::save_hours($saved_resource_id, $hours_data);
        }

        wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /**
     * Process hours data from POST into structured format
     *
     * @param array $post_hours Raw POST hours data
     * @return array Structured hours data
     */
    private function process_hours_data($post_hours) {
        $processed = array();

        foreach ($post_hours as $day => $day_data) {
            if (!is_array($day_data)) {
                continue;
            }

            $mode = isset($day_data['mode']) ? sanitize_text_field($day_data['mode']) : 'simple';

            if ($mode === 'closed') {
                $processed[$day] = array(
                    'mode' => 'closed',
                    'is_closed' => true
                );

            } elseif ($mode === 'simple') {
                if (isset($day_data['simple']) && is_array($day_data['simple'])) {
                    $processed[$day] = array(
                        'mode' => 'simple',
                        'is_closed' => false,
                        'simple' => array(
                            'open' => isset($day_data['simple']['open']) ? sanitize_text_field($day_data['simple']['open']) . ':00' : '09:00:00',
                            'close' => isset($day_data['simple']['close']) ? sanitize_text_field($day_data['simple']['close']) . ':00' : '17:00:00'
                        )
                    );
                }

            } elseif ($mode === 'multiple') {
                if (isset($day_data['blocks']) && is_array($day_data['blocks'])) {
                    $blocks = array();
                    foreach ($day_data['blocks'] as $block) {
                        if (isset($block['open']) && isset($block['close'])) {
                            $blocks[] = array(
                                'open' => sanitize_text_field($block['open']) . ':00',
                                'close' => sanitize_text_field($block['close']) . ':00',
                                'label' => isset($block['label']) ? sanitize_text_field($block['label']) : ''
                            );
                        }
                    }
                    if (!empty($blocks)) {
                        $processed[$day] = array(
                            'mode' => 'multiple',
                            'is_closed' => false,
                            'blocks' => $blocks
                        );
                    }
                }

            } elseif ($mode === 'recurring') {
                if (isset($day_data['recurring']) && is_array($day_data['recurring'])) {
                    $recurring = $day_data['recurring'];
                    $processed[$day] = array(
                        'mode' => 'recurring',
                        'is_closed' => false,
                        'recurring' => array(
                            'pattern' => isset($recurring['pattern']) ? sanitize_text_field($recurring['pattern']) : 'weekly',
                            'interval' => isset($recurring['interval']) ? (int)$recurring['interval'] : 1,
                            'week' => isset($recurring['week']) ? (int)$recurring['week'] : null,
                            'day_of_month' => isset($recurring['day_of_month']) ? (int)$recurring['day_of_month'] : null,
                            'open' => isset($recurring['open']) ? sanitize_text_field($recurring['open']) . ':00' : '09:00:00',
                            'close' => isset($recurring['close']) ? sanitize_text_field($recurring['close']) . ':00' : '17:00:00'
                        )
                    );
                }
            }
        }

        return $processed;
    }

    /**
     * Export resources AJAX handler with full filtering and field selection
     */
    public function export_resources() {
        // Verify nonce
        check_ajax_referer('export_resources');

        // Check permissions
        if (!current_user_can($this->get_resource_capability())) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $resources_table = $wpdb->prefix . 'resources';

        // Get format
        $format = isset($_GET['format']) ? sanitize_text_field($_GET['format']) : 'csv';

        // Get export scope
        $scope = isset($_GET['scope']) ? sanitize_text_field($_GET['scope']) : 'all';

        // Get selected fields
        $selected_fields = isset($_GET['fields']) && is_array($_GET['fields']) ? array_map('sanitize_text_field', $_GET['fields']) : null;

        // Build field map based on selection
        $field_labels = array(
            'id' => 'ID',
            'resource_name' => 'Resource Name',
            'organization' => 'Organization',
            'service_area' => 'Service Area',
            'services_offered' => 'Services Offered',
            'provider_type' => 'Provider Type',
            'phone' => 'Phone',
            'email' => 'Email',
            'website' => 'Website',
            'physical_address' => 'Physical Address',
            'what_they_provide' => 'What They Provide',
            'how_to_apply' => 'How to Apply',
            'documents_required' => 'Documents Required',
            'target_population' => 'Target Population',
            'income_requirements' => 'Income Requirements',
            'geography' => 'Geography',
            'office_hours' => 'Office Hours',
            'service_hours' => 'Service Hours',
            'last_verified_date' => 'Last Verified',
            'verification_status' => 'Verification Status'
        );

        // Filter to selected fields only
        if ($selected_fields) {
            $fields = array();
            foreach ($selected_fields as $field) {
                if (isset($field_labels[$field])) {
                    $fields[$field] = $field_labels[$field];
                }
            }
        } else {
            $fields = null; // Use defaults
        }

        // Get resources based on scope
        $resources = array();

        if ($scope === 'selected') {
            // Export only selected IDs
            $ids = isset($_GET['ids']) && is_array($_GET['ids']) ? array_map('intval', $_GET['ids']) : array();
            if (empty($ids)) {
                wp_die('No resources selected');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $resources = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $resources_table WHERE id IN ($placeholders) ORDER BY resource_name ASC",
                    $ids
                ),
                ARRAY_A
            );

        } elseif ($scope === 'filtered') {
            // Export based on current filters
            $where = array('1=1');
            $query_params = array();

            if (isset($_GET['search']) && !empty($_GET['search'])) {
                $search = '%' . $wpdb->esc_like(sanitize_text_field($_GET['search'])) . '%';
                $where[] = '(resource_name LIKE %s OR organization LIKE %s OR service_area LIKE %s OR services_offered LIKE %s OR provider_type LIKE %s)';
                $query_params[] = $search;
                $query_params[] = $search;
                $query_params[] = $search;
                $query_params[] = $search;
                $query_params[] = $search;
            }

            if (isset($_GET['status']) && !empty($_GET['status'])) {
                $where[] = 'verification_status = %s';
                $query_params[] = sanitize_text_field($_GET['status']);
            }

            $service_area_request = isset($_GET['service_area']) ? sanitize_text_field($_GET['service_area']) : '';
            if ($service_area_request === '' && isset($_GET['service'])) {
                $service_area_request = sanitize_text_field($_GET['service']);
            }

            if ($service_area_request !== '') {
                $service_area_slug = $service_area_request;
                if (class_exists('Resource_Taxonomy')) {
                    $service_area_slug = Resource_Taxonomy::normalize_service_area_slug($service_area_slug);
                }

                if ($service_area_slug !== '') {
                    $where[] = 'service_area = %s';
                    $query_params[] = $service_area_slug;
                }
            }

            $sql = "SELECT * FROM $resources_table WHERE " . implode(' AND ', $where) . " ORDER BY resource_name ASC";

            if (!empty($query_params)) {
                $resources = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);
            } else {
                $resources = $wpdb->get_results($sql, ARRAY_A);
            }

        } else {
            // Export all resources
            $resources = $wpdb->get_results(
                "SELECT * FROM $resources_table ORDER BY resource_name ASC",
                ARRAY_A
            );
        }

        if (empty($resources)) {
            wp_die('No resources to export');
        }

        // Enhance with hours data if hours fields are selected
        if (!$selected_fields || in_array('office_hours', $selected_fields) || in_array('service_hours', $selected_fields)) {
            foreach ($resources as &$resource) {
                if (class_exists('Resource_Hours_Manager')) {
                    $hours_data = Resource_Hours_Manager::get_hours($resource['id']);
                    if ($hours_data) {
                        $resource['office_hours'] = $hours_data['office_hours'];
                        $resource['service_hours'] = $hours_data['service_hours'];
                    }
                }
            }
        }

        // Generate export based on format
        $content = null;
        $filename = 'resources-' . date('Y-m-d') . '.';
        $mime_type = 'text/plain';

        switch ($format) {
            case 'csv':
                $content = Resource_Exporter::export_csv($resources, $fields);
                $filename .= 'csv';
                $mime_type = 'text/csv';
                break;

            case 'excel':
                $content = Resource_Exporter::export_excel($resources, $fields);
                if (is_wp_error($content)) {
                    wp_die('Error: ' . $content->get_error_message());
                }
                $filename .= 'xlsx';
                $mime_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                break;

            case 'json':
                $content = Resource_Exporter::export_json($resources);
                $filename .= 'json';
                $mime_type = 'application/json';
                break;

            case 'pdf':
                $content = Resource_Exporter::export_pdf($resources, $fields);
                if (is_wp_error($content)) {
                    wp_die('Error: ' . $content->get_error_message());
                }
                $filename .= 'pdf';
                $mime_type = 'application/pdf';
                break;

            default:
                wp_die('Invalid format');
        }

        // Send file to browser
        Resource_Exporter::send_download($content, $filename, $mime_type);
    }
}
