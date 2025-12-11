<?php
/**
 * Shortcode Display Class
 */

class Monday_Resources_Shortcode {

    private $api;

    public function __construct() {
        $this->api = new Monday_Resources_API();
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

        $items = $this->api->get_resources();

        if (!$items) {
            return '<p>Unable to load resources. Please try again later.</p>';
        }

        // Filter items based on shortcode attributes
        if (!empty($atts['geography']) || !empty($atts['service_type'])) {
            $items = array_filter($items, function($item) use ($atts) {
                $columns = array();
                foreach ($item['column_values'] as $col) {
                    $columns[$col['id']] = $col;
                }

                if (!empty($atts['geography'])) {
                    $geography_values = array_map('trim', explode(',', $atts['geography']));
                    $match_found = false;

                    foreach ($geography_values as $geo) {
                        if (isset($columns['dropdown_mkx1c3xe']) && stripos($columns['dropdown_mkx1c3xe']['text'], $geo) !== false) {
                            $match_found = true;
                            break;
                        }
                    }

                    if (!$match_found) {
                        return false;
                    }
                }

                if (!empty($atts['service_type'])) {
                    $service_values = array_map('trim', explode(',', $atts['service_type']));
                    $match_found = false;

                    foreach ($service_values as $service) {
                        if (isset($columns['dropdown_mkx1c4dt']) && stripos($columns['dropdown_mkx1c4dt']['text'], $service) !== false) {
                            $match_found = true;
                            break;
                        }
                    }

                    if (!$match_found) {
                        return false;
                    }
                }

                return true;
            });
        }

        // Define which fields are always visible vs hidden
        $always_visible = array(
            'dropdown_mkx1c4dt' => 'Primary Service Type',
            'phone_mkx162rz' => 'Phone',
            'dropdown_mkx1ngjf' => 'Target Population',
            'color_mkx1nefv' => 'Income Requirements',
            'link_mkx1957p' => 'Website'
        );

        $hidden_details = array(
            'dropdown_mkx1nmep' => 'Organization/Agency',
            'dropdown_mkxm3bt8' => 'Secondary Service Type',
            'long_text_mkx17r67' => 'What They Provide',
            'email_mkx1akhs' => 'Email',
            'location_mkx11h7c' => 'Physical Address',
            'long_text_mkx1xxn6' => 'How to Apply',
            'long_text_mkx1wdjn' => 'Documents Required',
            'text_mkx1tkrz' => 'Hours of Operation',
            'color_mkx1kpkb' => 'Wait Time',
            'text_mkx1knsa' => 'Residency Requirements',
            'long_text_mkx1qaxq' => 'Other Eligibility Requirements',
            'long_text_mkx1qsy5' => 'Eligibility Notes',
            'dropdown_mkx1bndy' => 'Counties Served',
            'long_text_mkx18jq8' => 'Notes & Tips',
            'long_text_mkx1w8qc' => 'Last Verified'
        );

        // Collect unique service types for dropdown
        $service_types = array();
        foreach ($items as $item) {
            foreach ($item['column_values'] as $col) {
                if ($col['id'] === 'dropdown_mkx1c4dt' && !empty($col['text'])) {
                    $types = array_map('trim', explode(',', $col['text']));
                    foreach ($types as $type) {
                        if (!empty($type) && !in_array($type, $service_types)) {
                            $service_types[] = $type;
                        }
                    }
                }
            }
        }
        sort($service_types);

        ob_start();
        ?>
        <style>
            .resources-container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 20px;
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
            .resources-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-top: 20px;
                justify-content: center;
            }
            @media (min-width: 1080px) {
                .resources-grid {
                    grid-template-columns: repeat(auto-fit, minmax(250px, calc(25% - 15px)));
                }
            }
            .resource-card {
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                background: #fff;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                transition: box-shadow 0.3s ease;
            }
            .resource-card:hover {
                box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            }
            .resource-card h3 {
                margin: 0 0 15px 0;
                font-size: 1.3em;
                color: #333;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
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
            }
            .resource-field-value a {
                color: #0073aa;
                text-decoration: none;
            }
            .resource-field-value a:hover {
                text-decoration: underline;
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
                .resources-grid {
                    grid-template-columns: 1fr;
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
            }
            @media (max-width: 480px) {
                .resources-help-section h2 {
                    font-size: 1.1em;
                }
                .resources-help-section p,
                .resources-help-section ul {
                    font-size: 0.95em;
                }
            }
        </style>

        <div class="resources-container">
            <!-- Helpful Instructions Section -->
            <div class="resources-help-section">
                <h2>How to Find Resources</h2>
                <p>Browse by category or search by keyword to find what you need. Click "Click for more info..." on any resource for complete details. Use the "Report an Issue" button if you find incorrect information, or the "Submit a New Resource" button below to share resources we're missing.</p>
            </div>

            <button class="submit-resource-btn" onclick="openSubmitResourceModal()">Submit a New Resource</button>

            <!-- Filter Section -->
            <div class="resources-filters">
                <div class="filter-group">
                    <label for="category-filter">Filter by Category (Optional)</label>
                    <select id="category-filter" class="category-filter">
                        <option value="">Show All Categories</option>
                        <?php foreach ($service_types as $type): ?>
                            <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group resources-search">
                    <label for="resource-search">Search by Keyword (Optional)</label>
                    <input type="text" id="resource-search" placeholder="Type what you're looking for (e.g., food, rent help, medical care)..." />
                </div>
            </div>

            <div class="results-count">
                Showing <span id="visible-count"><?php echo count($items); ?></span> of <?php echo count($items); ?> resources
            </div>
            <div class="resources-grid" id="resources-grid">
                <?php foreach ($items as $index => $item): ?>
                    <?php
                    $columns = array();
                    foreach ($item['column_values'] as $col) {
                        $columns[$col['id']] = $col;
                    }

                    $searchable_text = strtolower($item['name']);
                    foreach ($columns as $col) {
                        $searchable_text .= ' ' . strtolower($col['text']);
                    }

                    // Get the service type for category filtering
                    $service_type = isset($columns['dropdown_mkx1c4dt']) ? strtolower($columns['dropdown_mkx1c4dt']['text']) : '';
                    ?>
                    <div class="resource-card" data-search="<?php echo esc_attr($searchable_text); ?>" data-category="<?php echo esc_attr($service_type); ?>">
                        <h3><?php echo esc_html($item['name']); ?></h3>

                        <!-- Always visible fields -->
                        <?php foreach ($always_visible as $col_id => $label): ?>
                            <?php
                            if (isset($columns[$col_id])) {
                                $formatted_value = Monday_Resources_API::format_column_value($columns[$col_id]);
                                if (!empty($formatted_value)):
                            ?>
                                <div class="resource-field">
                                    <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                    <span class="resource-field-value"><?php echo $formatted_value; ?></span>
                                </div>
                            <?php
                                endif;
                            }
                            ?>
                        <?php endforeach; ?>

                        <!-- Hidden details -->
                        <div class="resource-details-hidden" id="details-<?php echo $index; ?>">
                            <?php foreach ($hidden_details as $col_id => $label): ?>
                                <?php
                                if (isset($columns[$col_id])) {
                                    $formatted_value = Monday_Resources_API::format_column_value($columns[$col_id]);
                                    if (!empty($formatted_value)):
                                ?>
                                    <div class="resource-field">
                                        <span class="resource-field-label"><?php echo esc_html($label); ?>:</span>
                                        <span class="resource-field-value"><?php echo $formatted_value; ?></span>
                                    </div>
                                <?php
                                    endif;
                                }
                                ?>
                            <?php endforeach; ?>
                        </div>

                        <!-- Toggle button -->
                        <div class="resource-toggle">
                            <button class="resource-toggle-button" onclick="toggleDetails(<?php echo $index; ?>)" id="toggle-<?php echo $index; ?>">
                                Click for more info...
                            </button>
                            <br>
                            <button class="resource-report-btn" onclick="openReportModal('<?php echo esc_js($item['name']); ?>', <?php echo $index; ?>)">
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
                    button.textContent = 'Show less';
                } else {
                    details.style.display = 'none';
                    button.textContent = 'Click for more info...';
                }
            }

            (function() {
                const searchInput = document.getElementById('resource-search');
                const categoryFilter = document.getElementById('category-filter');
                const cards = document.querySelectorAll('.resource-card');
                const visibleCount = document.getElementById('visible-count');

                // Combined filter function that handles both search and category
                function filterResources() {
                    const searchTerm = searchInput.value.toLowerCase().trim();
                    const selectedCategory = categoryFilter.value.toLowerCase().trim();
                    let visible = 0;

                    // Remove any existing "no results" message
                    const existingNoResults = document.querySelector('.no-results');
                    if (existingNoResults) {
                        existingNoResults.remove();
                    }

                    cards.forEach(function(card) {
                        const searchableText = card.getAttribute('data-search');
                        const cardCategory = card.getAttribute('data-category');
                        let showCard = true;

                        // Check category filter first
                        if (selectedCategory !== '') {
                            // Check if card's category contains the selected category
                            showCard = cardCategory.indexOf(selectedCategory) !== -1;
                        }

                        // If card passes category filter, check search term
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

                        // Show or hide the card based on filters
                        if (showCard) {
                            card.style.display = 'block';
                            visible++;
                        } else {
                            card.style.display = 'none';
                        }
                    });

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

                        noResults.textContent = message;
                        grid.appendChild(noResults);
                    }
                }

                // Add event listeners for both filters
                searchInput.addEventListener('input', filterResources);
                categoryFilter.addEventListener('change', filterResources);
            })();
        </script>
        <?php
        return ob_get_clean();
    }
}
