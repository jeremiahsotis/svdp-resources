<?php
/**
 * Admin Settings and Dashboard Class
 */

class Monday_Resources_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Resource management actions
        add_action('admin_post_delete_resource', array($this, 'delete_resource'));
        add_action('admin_post_save_resource', array($this, 'save_resource'));
        add_action('admin_post_bulk_action_resources', array($this, 'bulk_action_resources'));

        // Issue and submission actions
        add_action('admin_post_delete_issue', array($this, 'delete_issue'));
        add_action('admin_post_delete_submission', array($this, 'delete_submission'));
        add_action('admin_post_update_issue_status', array($this, 'update_issue_status'));
        add_action('admin_post_update_submission_status', array($this, 'update_submission_status'));
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
        add_menu_page(
            'Community Resources',
            'Resources',
            'manage_options',
            'monday-resources-manage',
            array($this, 'manage_resources_page'),
            'dashicons-list-view',
            30
        );

        add_submenu_page(
            'monday-resources-manage',
            'All Resources',
            'All Resources',
            'manage_options',
            'monday-resources-manage',
            array($this, 'manage_resources_page')
        );

        add_submenu_page(
            'monday-resources-manage',
            'Add New Resource',
            'Add New',
            'manage_options',
            'monday-resources-add',
            array($this, 'add_resource_page')
        );

        add_submenu_page(
            null, // Hidden from menu
            'Edit Resource',
            'Edit Resource',
            'manage_options',
            'monday-resources-edit',
            array($this, 'edit_resource_page')
        );

        add_submenu_page(
            'monday-resources-manage',
            'Issue Reports',
            'Issue Reports',
            'manage_options',
            'monday-resources-issues',
            array($this, 'issues_page')
        );

        add_submenu_page(
            'monday-resources-manage',
            'Resource Submissions',
            'Submissions',
            'manage_options',
            'monday-resources-submissions',
            array($this, 'submissions_page')
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
        $service_filter = isset($_GET['service']) ? sanitize_text_field($_GET['service']) : '';

        // Get all resources
        $filters = array();
        if ($status_filter) {
            $filters['verification_status'] = $status_filter;
        }
        if ($service_filter) {
            $filters['service_type'] = $service_filter;
        }

        $resources = Resources_Manager::get_all_resources($filters);

        // Apply search if provided
        if ($search) {
            $resources = array_filter($resources, function($resource) use ($search) {
                $searchable = strtolower(implode(' ', $resource));
                return strpos($searchable, strtolower($search)) !== false;
            });
        }

        // Get service types from centralized options
        $service_types = get_option('resource_service_types', array());

        // Get verification stats
        $stats = Resources_Manager::get_verification_stats();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Community Resources</h1>
            <a href="<?php echo admin_url('admin.php?page=monday-resources-add'); ?>" class="page-title-action">Add New</a>
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

                    <label style="margin-left: 15px;">Service Type: </label>
                    <select name="service" style="width: 350px; font-size: 14px;">
                        <option value="">All Service Types</option>
                        <?php foreach ($service_types as $type): ?>
                            <option value="<?php echo esc_attr($type); ?>" <?php selected($service_filter, $type); ?>>
                                <?php echo esc_html($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="submit" class="button" value="Filter">
                    <?php if ($search || $status_filter || $service_filter): ?>
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
                                <th>Service Type</th>
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
                                    <td><?php echo esc_html($resource['primary_service_type']); ?></td>
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

            <script>
                // Select all checkbox functionality
                document.getElementById('cb-select-all').addEventListener('change', function() {
                    var checkboxes = document.querySelectorAll('input[name="resource_ids[]"]');
                    checkboxes.forEach(function(checkbox) {
                        checkbox.checked = this.checked;
                    }, this);
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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

                    <tr>
                        <th scope="row"><label for="primary_service_type">Primary Service Type *</label></th>
                        <td>
                            <?php
                            $service_types = get_option('resource_service_types', array());
                            $selected_primary = $has_data ? $resource['primary_service_type'] : '';
                            ?>
                            <select name="primary_service_type" id="primary_service_type" style="width: 500px; font-size: 15px;" required>
                                <option value="">Select a service type...</option>
                                <?php foreach ($service_types as $service_type): ?>
                                    <option value="<?php echo esc_attr($service_type); ?>"
                                            <?php selected($selected_primary, $service_type); ?>>
                                        <?php echo esc_html($service_type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select the main category for this resource (single selection)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label>Secondary Service Types</label></th>
                        <td>
                            <?php
                            $selected_secondary = array();
                            if ($has_data && !empty($resource['secondary_service_type'])) {
                                $selected_secondary = array_map('trim', explode(',', $resource['secondary_service_type']));
                            }
                            ?>
                            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                                <?php if (empty($service_types)): ?>
                                    <p>No service types available. Please add service types in Settings.</p>
                                <?php else: ?>
                                    <?php foreach ($service_types as $service_type): ?>
                                        <label style="display: block; margin: 5px 0; font-size: 15px;">
                                            <input type="checkbox" name="secondary_service_type[]"
                                                   value="<?php echo esc_attr($service_type); ?>"
                                                   <?php checked(in_array($service_type, $selected_secondary)); ?>>
                                            <?php echo esc_html($service_type); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <p class="description">Select additional service categories (multiple selection allowed)</p>
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

                            <div class="hours-of-operation-section" style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">
                                <!-- Special Situations -->
                                <div style="margin-bottom: 20px;">
                                    <h4 style="margin-top: 0;">Special Situations:</h4>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="hours_24_7" id="hours_24_7" value="1"
                                               <?php checked($hours_data['flags']['is_24_7']); ?>>
                                        Open 24/7
                                    </label>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="hours_by_appointment" id="hours_by_appointment" value="1"
                                               <?php checked($hours_data['flags']['is_by_appointment']); ?>>
                                        By Appointment Only
                                    </label>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="hours_call_for_availability" id="hours_call_for_availability" value="1"
                                               <?php checked($hours_data['flags']['is_call_for_availability']); ?>>
                                        Call for Availability
                                    </label>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="hours_currently_closed" id="hours_currently_closed" value="1"
                                               <?php checked($hours_data['flags']['is_currently_closed']); ?>>
                                        Currently Closed
                                    </label>
                                </div>

                                <!-- Office Hours -->
                                <div id="office_hours_section" style="margin-bottom: 20px;">
                                    <h4 style="margin-bottom: 10px;">Office Hours</h4>
                                    <p class="description" style="margin-bottom: 10px;">When the admin office is open for calls/inquiries</p>

                                    <table class="widefat" style="max-width: 600px;">
                                        <thead>
                                            <tr>
                                                <th style="width: 25%;">Day</th>
                                                <th style="width: 15%;">Closed</th>
                                                <th style="width: 30%;">Opens</th>
                                                <th style="width: 30%;">Closes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $days = Resource_Hours_Manager::DAY_NAMES;
                                            for ($day = 0; $day <= 6; $day++):
                                                $day_hours = isset($hours_data['office_hours'][$day]) ? $hours_data['office_hours'][$day] : null;
                                                $is_closed = $day_hours ? $day_hours['is_closed'] : ($day == 0 || $day == 6); // Default Sat/Sun closed
                                                $open_time = $day_hours && !$is_closed ? substr($day_hours['open_time'], 0, 5) : '09:00';
                                                $close_time = $day_hours && !$is_closed ? substr($day_hours['close_time'], 0, 5) : '17:00';
                                            ?>
                                            <tr>
                                                <td><strong><?php echo $days[$day]; ?></strong></td>
                                                <td>
                                                    <input type="checkbox" name="office_hours[<?php echo $day; ?>][is_closed]"
                                                           class="office-closed-checkbox" data-day="<?php echo $day; ?>"
                                                           value="1" <?php checked($is_closed); ?>>
                                                </td>
                                                <td>
                                                    <input type="time" name="office_hours[<?php echo $day; ?>][open_time]"
                                                           class="office-time-input" data-day="<?php echo $day; ?>"
                                                           value="<?php echo esc_attr($open_time); ?>"
                                                           <?php if ($is_closed) echo 'disabled'; ?>>
                                                </td>
                                                <td>
                                                    <input type="time" name="office_hours[<?php echo $day; ?>][close_time]"
                                                           class="office-time-input" data-day="<?php echo $day; ?>"
                                                           value="<?php echo esc_attr($close_time); ?>"
                                                           <?php if ($is_closed) echo 'disabled'; ?>>
                                                </td>
                                            </tr>
                                            <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Service/Program Hours -->
                                <div id="service_hours_section" style="margin-bottom: 20px;">
                                    <h4 style="margin-bottom: 10px;">Service/Program Hours</h4>
                                    <p class="description" style="margin-bottom: 10px;">When services/programs are actually available</p>

                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="checkbox" id="service_same_as_office" name="service_same_as_office" value="1"
                                               <?php if (!empty($hours_data['service_same_as_office'])) echo 'checked'; ?>>
                                        Same as Office Hours
                                    </label>

                                    <table class="widefat" id="service_hours_table" style="max-width: 600px;">
                                        <thead>
                                            <tr>
                                                <th style="width: 25%;">Day</th>
                                                <th style="width: 15%;">Closed</th>
                                                <th style="width: 30%;">Opens</th>
                                                <th style="width: 30%;">Closes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            for ($day = 0; $day <= 6; $day++):
                                                $day_hours = isset($hours_data['service_hours'][$day]) ? $hours_data['service_hours'][$day] : null;
                                                $is_closed = $day_hours ? $day_hours['is_closed'] : ($day == 0 || $day == 6);
                                                $open_time = $day_hours && !$is_closed ? substr($day_hours['open_time'], 0, 5) : '09:00';
                                                $close_time = $day_hours && !$is_closed ? substr($day_hours['close_time'], 0, 5) : '17:00';
                                            ?>
                                            <tr>
                                                <td><strong><?php echo $days[$day]; ?></strong></td>
                                                <td>
                                                    <input type="checkbox" name="service_hours[<?php echo $day; ?>][is_closed]"
                                                           class="service-closed-checkbox" data-day="<?php echo $day; ?>"
                                                           value="1" <?php checked($is_closed); ?>>
                                                </td>
                                                <td>
                                                    <input type="time" name="service_hours[<?php echo $day; ?>][open_time]"
                                                           class="service-time-input" data-day="<?php echo $day; ?>"
                                                           value="<?php echo esc_attr($open_time); ?>"
                                                           <?php if ($is_closed) echo 'disabled'; ?>>
                                                </td>
                                                <td>
                                                    <input type="time" name="service_hours[<?php echo $day; ?>][close_time]"
                                                           class="service-time-input" data-day="<?php echo $day; ?>"
                                                           value="<?php echo esc_attr($close_time); ?>"
                                                           <?php if ($is_closed) echo 'disabled'; ?>>
                                                </td>
                                            </tr>
                                            <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Special Notes -->
                                <div style="margin-bottom: 10px;">
                                    <h4>Special Notes (Optional):</h4>
                                    <textarea name="hours_special_notes" id="hours_special_notes"
                                              class="large-text" rows="2"
                                              placeholder="Additional hours info, holiday closures, etc."><?php echo esc_textarea($hours_data['special_notes']); ?></textarea>
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
        </div>
        <?php
    }

    /**
     * Save resource (create or update)
     */
    public function save_resource() {
        if (!current_user_can('manage_options')) {
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
            $geography = implode(', ', array_map('sanitize_text_field', $_POST['geography']));
        }

        // Handle counties_served array (checkboxes)
        $counties_served = '';
        if (isset($_POST['counties_served']) && is_array($_POST['counties_served'])) {
            $counties_served = implode(', ', array_map('sanitize_text_field', $_POST['counties_served']));
        }

        // Handle secondary_service_type array (checkboxes)
        $secondary_service_type = '';
        if (isset($_POST['secondary_service_type']) && is_array($_POST['secondary_service_type'])) {
            $secondary_service_type = implode(', ', array_map('sanitize_text_field', $_POST['secondary_service_type']));
        }

        // Handle target_population array (checkboxes)
        $target_population = '';
        if (isset($_POST['target_population']) && is_array($_POST['target_population'])) {
            $target_population = implode(', ', array_map('sanitize_text_field', $_POST['target_population']));
        }

        // Use wp_unslash() to remove WordPress's automatic slashing before sanitizing
        $data = array(
            'resource_name' => sanitize_text_field(wp_unslash($_POST['resource_name'])),
            'organization' => sanitize_text_field(wp_unslash($_POST['organization'])),
            'is_svdp' => isset($_POST['is_svdp']) ? 1 : 0,
            'primary_service_type' => sanitize_text_field(wp_unslash($_POST['primary_service_type'])),
            'secondary_service_type' => $secondary_service_type,
            'phone' => sanitize_text_field(wp_unslash($_POST['phone'])),
            'phone_extension' => sanitize_text_field(wp_unslash($_POST['phone_extension'])),
            'alternate_phone' => sanitize_text_field(wp_unslash($_POST['alternate_phone'])),
            'email' => sanitize_email($_POST['email']),
            'website' => esc_url_raw($_POST['website']),
            'physical_address' => sanitize_textarea_field(wp_unslash($_POST['physical_address'])),
            'what_they_provide' => sanitize_textarea_field(wp_unslash($_POST['what_they_provide'])),
            'how_to_apply' => sanitize_textarea_field(wp_unslash($_POST['how_to_apply'])),
            'documents_required' => sanitize_textarea_field(wp_unslash($_POST['documents_required'])),
            'hours_of_operation' => sanitize_text_field(wp_unslash($_POST['hours_of_operation'])),
            'target_population' => $target_population,
            'income_requirements' => sanitize_text_field(wp_unslash($_POST['income_requirements'])),
            'residency_requirements' => sanitize_textarea_field(wp_unslash($_POST['residency_requirements'])),
            'other_eligibility' => sanitize_textarea_field(wp_unslash($_POST['other_eligibility'])),
            'eligibility_notes' => sanitize_textarea_field(wp_unslash($_POST['eligibility_notes'])),
            'geography' => $geography,
            'counties_served' => $counties_served,
            'wait_time' => sanitize_text_field(wp_unslash($_POST['wait_time'])),
            'notes_and_tips' => sanitize_textarea_field(wp_unslash($_POST['notes_and_tips']))
        );

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

            // Build hours data structure
            $hours_data = array(
                'flags' => array(
                    'is_24_7' => isset($_POST['hours_24_7']) ? 1 : 0,
                    'is_by_appointment' => isset($_POST['hours_by_appointment']) ? 1 : 0,
                    'is_call_for_availability' => isset($_POST['hours_call_for_availability']) ? 1 : 0,
                    'is_currently_closed' => isset($_POST['hours_currently_closed']) ? 1 : 0
                ),
                'special_notes' => isset($_POST['hours_special_notes']) ? sanitize_textarea_field(wp_unslash($_POST['hours_special_notes'])) : '',
                'service_same_as_office' => $service_same_as_office,
                'office_hours' => array(),
                'service_hours' => array()
            );

            // Process office hours
            if (isset($_POST['office_hours']) && is_array($_POST['office_hours'])) {
                foreach ($_POST['office_hours'] as $day => $day_hours) {
                    $hours_data['office_hours'][$day] = array(
                        'is_closed' => isset($day_hours['is_closed']) ? 1 : 0,
                        'open_time' => isset($day_hours['open_time']) ? sanitize_text_field($day_hours['open_time']) . ':00' : null,
                        'close_time' => isset($day_hours['close_time']) ? sanitize_text_field($day_hours['close_time']) . ':00' : null
                    );
                }
            }

            // Process service hours - if "Same as Office Hours" is checked, copy office hours
            if ($service_same_as_office) {
                // Copy office hours to service hours
                $hours_data['service_hours'] = $hours_data['office_hours'];
            } else {
                // Process service hours independently
                if (isset($_POST['service_hours']) && is_array($_POST['service_hours'])) {
                    foreach ($_POST['service_hours'] as $day => $day_hours) {
                        $hours_data['service_hours'][$day] = array(
                            'is_closed' => isset($day_hours['is_closed']) ? 1 : 0,
                            'open_time' => isset($day_hours['open_time']) ? sanitize_text_field($day_hours['open_time']) . ':00' : null,
                            'close_time' => isset($day_hours['close_time']) ? sanitize_text_field($day_hours['close_time']) . ':00' : null
                        );
                    }
                }
            }

            // Save hours to database
            Resource_Hours_Manager::save_hours($saved_resource_id, $hours_data);
        }

        wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }
}
