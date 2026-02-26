<?php
/**
 * Template: Questionnaire Location Selection
 *
 * Variables available:
 * - $questionnaire: Array of questionnaire data
 * - $atts: Shortcode attributes
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get all available conferences
$all_conferences = Location_Service::get_all_conferences();

// Filter by questionnaire geography if specified
if (!empty($questionnaire['geography'])) {
    $allowed_geographies = array_map('trim', explode(',', $questionnaire['geography']));
    $all_conferences = array_intersect($all_conferences, $allowed_geographies);
    // Re-index array to avoid gaps in array keys
    $all_conferences = array_values($all_conferences);
}

$wpgmaps_available = Location_Service::is_wpgmaps_available();
?>

<div class="questionnaire-container questionnaire-location-step" data-questionnaire-id="<?php echo esc_attr($questionnaire['id']); ?>" data-mode="<?php echo esc_attr($atts['mode']); ?>">

    <!-- Header -->
    <div class="questionnaire-header">
        <h2 class="questionnaire-title"><?php echo esc_html($questionnaire['name']); ?></h2>

        <?php if (!empty($questionnaire['description'])): ?>
            <p class="questionnaire-description"><?php echo esc_html($questionnaire['description']); ?></p>
        <?php endif; ?>

        <?php if ($atts['mode'] === 'volunteer'): ?>
            <div class="volunteer-mode-notice">
                <span class="dashicons dashicons-groups"></span>
                <strong>Volunteer Mode:</strong> You are guiding someone through this questionnaire.
            </div>
        <?php endif; ?>
    </div>

    <!-- Volunteer Helper (if in volunteer mode) -->
    <?php if ($atts['mode'] === 'volunteer'): ?>
        <?php
        $context = 'location';
        include MONDAY_RESOURCES_PLUGIN_DIR . 'templates/volunteer-helper.php';
        ?>
    <?php endif; ?>

    <!-- Welcome Message -->
    <div class="questionnaire-welcome">
        <h3>Welcome! Let's get started.</h3>
        <p>To provide you with resources in your area, we need to know which community you're in.</p>
    </div>

    <!-- Location Selection Form -->
    <div class="location-selection-form">

        <?php if ($wpgmaps_available): ?>
            <!-- Address Lookup (if WP Go Maps available) -->
            <div class="location-method address-lookup-method">
                <h4>
                    <span class="dashicons dashicons-location"></span>
                    Enter Your Address
                </h4>
                <p class="method-description">We'll find your local St. Vincent de Paul Conference.</p>

                <div class="address-input-group">
                    <label for="address-input" class="screen-reader-text">Street Address or ZIP Code</label>
                    <input type="text"
                           id="address-input"
                           class="address-input"
                           placeholder="Enter your street address or ZIP code"
                           aria-describedby="address-help">
                    <p id="address-help" class="help-text">
                        Example: 123 Main Street, Fort Wayne, IN or just your ZIP code
                    </p>

                    <button type="button" class="btn btn-primary btn-lookup-address" aria-label="Look up your Conference by address">
                        <span class="btn-text">Find My Conference</span>
                        <span class="btn-loading" style="display: none;">
                            <span class="spinner"></span> Looking up...
                        </span>
                    </button>

                    <div class="address-result" style="display: none;"></div>
                    <div class="address-error" style="display: none;"></div>
                </div>

                <div class="or-divider">
                    <span>or</span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Conference Dropdown (fallback or only method) -->
        <div class="location-method dropdown-method">
            <h4>
                <span class="dashicons dashicons-admin-site-alt3"></span>
                Select Your Conference
            </h4>
            <?php if ($wpgmaps_available): ?>
                <p class="method-description">If you know your Conference, select it from the list below.</p>
            <?php else: ?>
                <p class="method-description">Please select your local St. Vincent de Paul Conference.</p>
            <?php endif; ?>

            <div class="conference-select-group">
                <label for="conference-select" class="screen-reader-text">Select Your Conference</label>
                <select id="conference-select" class="conference-select" aria-describedby="conference-help">
                    <option value="">-- Choose Your Conference --</option>
                    <?php foreach ($all_conferences as $conference): ?>
                        <option value="<?php echo esc_attr($conference); ?>">
                            <?php echo esc_html($conference); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p id="conference-help" class="help-text">
                    Not sure which Conference serves your area? Call 211 for assistance.
                </p>
            </div>
        </div>

        <!-- Begin Button -->
        <div class="begin-button-container">
            <button type="button"
                    class="btn btn-primary btn-large btn-begin-questionnaire"
                    disabled
                    aria-label="Begin questionnaire">
                <span class="btn-text">Begin</span>
                <span class="btn-loading" style="display: none;">
                    <span class="spinner"></span> Starting...
                </span>
            </button>

            <p class="privacy-notice">
                Your answers are private and will only be used to provide you with resource recommendations.
                No personal information is required.
            </p>
        </div>
    </div>

    <!-- Error Messages -->
    <div class="questionnaire-error-container" style="display: none;" role="alert" aria-live="polite"></div>
</div>

<style>
/* Trauma-informed design: warm, welcoming colors and spacing */
.questionnaire-container {
    max-width: 700px;
    margin: 0 auto;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

.questionnaire-header {
    text-align: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e0e0e0;
}

.questionnaire-title {
    font-size: 2em;
    color: #2c3e50;
    margin: 0 0 15px 0;
}

.questionnaire-description {
    font-size: 1.1em;
    color: #555;
    line-height: 1.6;
}

.volunteer-mode-notice {
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
    padding: 12px 15px;
    margin-top: 15px;
    font-size: 0.95em;
    color: #1565c0;
}

.volunteer-mode-notice .dashicons {
    vertical-align: middle;
    margin-right: 5px;
}

.questionnaire-welcome {
    background: #f8f9fa;
    padding: 25px;
    border-radius: 8px;
    margin-bottom: 30px;
    text-align: center;
}

.questionnaire-welcome h3 {
    color: #2c3e50;
    margin-top: 0;
    font-size: 1.5em;
}

.questionnaire-welcome p {
    font-size: 1.1em;
    color: #666;
    margin-bottom: 0;
    line-height: 1.6;
}

.location-selection-form {
    background: #ffffff;
    padding: 30px;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.location-method {
    margin-bottom: 30px;
}

.location-method h4 {
    font-size: 1.3em;
    color: #2c3e50;
    margin-top: 0;
    margin-bottom: 10px;
}

.location-method h4 .dashicons {
    color: #4CAF50;
    vertical-align: middle;
    margin-right: 8px;
}

.method-description {
    color: #666;
    margin-bottom: 20px;
    font-size: 1.05em;
}

.address-input,
.conference-select {
    width: 100%;
    padding: 15px;
    font-size: 1.1em;
    border: 2px solid #ddd;
    border-radius: 6px;
    transition: border-color 0.3s ease;
}

.address-input:focus,
.conference-select:focus {
    outline: none;
    border-color: #4CAF50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
}

.help-text {
    font-size: 0.9em;
    color: #888;
    margin-top: 8px;
    font-style: italic;
}

.btn {
    padding: 15px 30px;
    font-size: 1.1em;
    font-weight: 600;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-block;
    text-align: center;
}

.btn-primary {
    background: #4CAF50;
    color: white;
}

.btn-primary:hover:not(:disabled) {
    background: #45a049;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.btn-primary:disabled {
    background: #ccc;
    cursor: not-allowed;
    opacity: 0.6;
}

.btn-large {
    width: 100%;
    padding: 18px;
    font-size: 1.3em;
}

.btn-lookup-address {
    margin-top: 15px;
    width: 100%;
}

.btn-loading {
    display: none;
}

.btn.loading .btn-text {
    display: none;
}

.btn.loading .btn-loading {
    display: inline-block;
}

.spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.or-divider {
    text-align: center;
    margin: 30px 0;
    position: relative;
}

.or-divider::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 1px;
    background: #ddd;
}

.or-divider span {
    background: white;
    padding: 0 15px;
    position: relative;
    color: #999;
    font-weight: 600;
}

.begin-button-container {
    text-align: center;
    margin-top: 30px;
}

.privacy-notice {
    margin-top: 15px;
    font-size: 0.9em;
    color: #777;
    font-style: italic;
}

.address-result,
.address-error {
    margin-top: 15px;
    padding: 12px;
    border-radius: 6px;
}

.address-result {
    background: #e8f5e9;
    border: 1px solid #4CAF50;
    color: #2e7d32;
}

.address-error {
    background: #ffebee;
    border: 1px solid #f44336;
    color: #c62828;
}

.questionnaire-error-container {
    background: #ffebee;
    border: 1px solid #f44336;
    color: #c62828;
    padding: 15px;
    border-radius: 6px;
    margin-top: 20px;
}

.screen-reader-text {
    position: absolute;
    left: -10000px;
    width: 1px;
    height: 1px;
    overflow: hidden;
}

/* High contrast for accessibility */
@media (prefers-contrast: high) {
    .address-input,
    .conference-select {
        border-width: 3px;
    }

    .btn-primary {
        border: 2px solid #2e7d32;
    }
}

/* Mobile responsive */
@media (max-width: 600px) {
    .questionnaire-container {
        padding: 15px;
    }

    .questionnaire-title {
        font-size: 1.6em;
    }

    .location-selection-form {
        padding: 20px;
    }

    .btn {
        font-size: 1em;
    }

    .btn-large {
        font-size: 1.2em;
    }
}
</style>
