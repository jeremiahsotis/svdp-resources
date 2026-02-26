<?php
/**
 * Template: Questionnaire Outcome (Results)
 *
 * Variables available:
 * - $outcome: Array of outcome data
 * - $resources: Array of filtered resources
 * - $session: Session data
 * - $questionnaire: Questionnaire data
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$has_guidance = !empty($outcome['guidance_text']);
$has_resources = !empty($resources);
?>

<div class="questionnaire-outcome">

    <!-- Success Header -->
    <div class="outcome-header">
        <div class="outcome-success-icon">
            <span class="dashicons dashicons-yes-alt"></span>
        </div>
        <h2 class="outcome-title">Thank you for completing the questionnaire!</h2>
        <p class="outcome-subtitle">
            Based on your answers, here's what we recommend for you
            <?php if (!empty($session['conference'])): ?>
                in the <strong><?php echo esc_html($session['conference']); ?></strong> area
            <?php endif; ?>:
        </p>
    </div>

    <!-- Guidance Text (if provided) -->
    <?php if ($has_guidance): ?>
        <div class="outcome-guidance">
            <h3 class="guidance-title">
                <span class="dashicons dashicons-info"></span>
                Important Information
            </h3>
            <div class="guidance-content">
                <?php echo wp_kses_post($outcome['guidance_text']); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Resources (if provided) -->
    <?php if ($has_resources): ?>
        <div class="outcome-resources">
            <h3 class="resources-title">
                <span class="dashicons dashicons-location"></span>
                Recommended Resources in Your Area
                <span class="resource-count">(<?php echo count($resources); ?> found)</span>
            </h3>

            <p class="resources-intro">
                These resources are available in your area and can help with your situation.
                Click on any resource to see full details.
            </p>

            <div class="resources-list">
                <?php foreach ($resources as $resource): ?>
                    <div class="resource-card" data-resource-id="<?php echo esc_attr($resource['id']); ?>">
                        <div class="resource-card-header">
                            <h4 class="resource-name"><?php echo esc_html($resource['resource_name']); ?></h4>
                            <?php if (!empty($resource['organization'])): ?>
                                <p class="resource-organization"><?php echo esc_html($resource['organization']); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="resource-card-body">
                            <?php
                            $resource_service_area = '';
                            $resource_services_offered = array();
                            $resource_provider_type = '';

                            if (class_exists('Resource_Taxonomy')) {
                                $resource_service_area = Resource_Taxonomy::get_service_area_label(isset($resource['service_area']) ? $resource['service_area'] : '');
                                $resource_services_offered = Resource_Taxonomy::get_services_offered_labels_from_pipe(isset($resource['services_offered']) ? $resource['services_offered'] : '');
                                $resource_provider_type = Resource_Taxonomy::get_provider_type_label(isset($resource['provider_type']) ? $resource['provider_type'] : '');
                            }

                            if ($resource_service_area === '' && !empty($resource['primary_service_type'])) {
                                $resource_service_area = $resource['primary_service_type'];
                            }
                            ?>

                            <?php if ($resource_service_area !== ''): ?>
                                <div class="resource-meta">
                                    <strong>Service Area:</strong> <?php echo esc_html($resource_service_area); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($resource_services_offered)): ?>
                                <div class="resource-meta">
                                    <strong>Services Offered:</strong> <?php echo esc_html(implode(', ', $resource_services_offered)); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($resource_provider_type !== ''): ?>
                                <div class="resource-meta">
                                    <strong>System Type:</strong> <?php echo esc_html($resource_provider_type); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($resource['what_they_provide'])): ?>
                                <div class="resource-description">
                                    <?php echo esc_html(wp_trim_words($resource['what_they_provide'], 30)); ?>
                                </div>
                            <?php endif; ?>

                            <!-- Contact Information -->
                            <div class="resource-contact">
                                <?php if (!empty($resource['phone'])): ?>
                                    <div class="contact-item">
                                        <span class="dashicons dashicons-phone"></span>
                                        <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $resource['phone'])); ?>">
                                            <?php echo esc_html($resource['phone']); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($resource['website'])): ?>
                                    <div class="contact-item">
                                        <span class="dashicons dashicons-admin-site"></span>
                                        <a href="<?php echo esc_url($resource['website']); ?>" target="_blank" rel="noopener">
                                            Visit Website
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($resource['physical_address'])): ?>
                                    <div class="contact-item">
                                        <span class="dashicons dashicons-location-alt"></span>
                                        <?php echo esc_html($resource['physical_address']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- View Details Toggle -->
                            <button type="button" class="btn-view-details" aria-expanded="false">
                                <span class="view-more">View Full Details</span>
                                <span class="view-less" style="display: none;">Hide Details</span>
                            </button>

                            <!-- Full Details (hidden initially) -->
                            <div class="resource-full-details" style="display: none;">
                                <?php if (!empty($resource['how_to_apply'])): ?>
                                    <div class="detail-section">
                                        <h5>How to Apply</h5>
                                        <p><?php echo esc_html($resource['how_to_apply']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($resource['eligibility_notes'])): ?>
                                    <div class="detail-section">
                                        <h5>Eligibility</h5>
                                        <p><?php echo esc_html($resource['eligibility_notes']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($resource['hours_of_operation'])): ?>
                                    <div class="detail-section">
                                        <h5>Hours of Operation</h5>
                                        <p><?php echo esc_html($resource['hours_of_operation']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- No Resources Message -->
    <?php if (!$has_guidance && !$has_resources): ?>
        <div class="outcome-no-results">
            <p>Thank you for completing the questionnaire. Please contact us directly for personalized assistance.</p>
        </div>
    <?php endif; ?>

    <!-- Volunteer-Specific Next Steps -->
    <?php if (isset($session['is_volunteer_assisted']) && $session['is_volunteer_assisted']): ?>
        <div class="outcome-actions volunteer-next-steps">
            <h3>Next Steps for You and the Person You're Helping:</h3>

            <div class="volunteer-action-checklist">
                <h4>Before They Leave:</h4>
                <ul class="checklist">
                    <li>
                        <input type="checkbox" id="check-resources">
                        <label for="check-resources">Review the resources together and identify 1-2 to start with</label>
                    </li>
                    <li>
                        <input type="checkbox" id="check-write">
                        <label for="check-write">Write down or print the contact information for chosen resources</label>
                    </li>
                    <li>
                        <input type="checkbox" id="check-questions">
                        <label for="check-questions">Ask if they have questions about any resources</label>
                    </li>
                    <li>
                        <input type="checkbox" id="check-call">
                        <label for="check-call">Offer to help them make the first call if they're nervous</label>
                    </li>
                    <li>
                        <input type="checkbox" id="check-followup">
                        <label for="check-followup">Discuss if/when they'd like to check in again</label>
                    </li>
                </ul>

                <button type="button" class="btn btn-secondary" onclick="window.print();">
                    <span class="dashicons dashicons-printer"></span>
                    Print Results for Them
                </button>
            </div>

            <div class="action-buttons">
                <a href="<?php echo esc_url(add_query_arg('clear_session', '1', remove_query_arg('outcome'))); ?>" class="btn btn-primary btn-large">
                    <span class="dashicons dashicons-update"></span>
                    Help Another Person
                </a>
            </div>
        </div>

    <?php else: ?>
        <!-- Standard Next Steps (Non-Volunteer) -->
        <div class="outcome-actions">
            <h3>What's Next?</h3>

            <div class="action-buttons">
                <a href="<?php echo esc_url(add_query_arg('clear_session', '1', remove_query_arg('outcome'))); ?>" class="btn btn-primary btn-large btn-start-over">
                    <span class="dashicons dashicons-update"></span>
                    Start a New Questionnaire
                </a>

                <a href="<?php echo esc_url(home_url('/resources')); ?>" class="btn btn-secondary btn-large">
                    <span class="dashicons dashicons-search"></span>
                    Browse All Resources
                </a>
            </div>

            <div class="help-contact">
                <p>
                    <strong>Need additional help?</strong><br>
                    Call 211 for free, confidential assistance 24/7.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Session Summary (optional) -->
    <?php if (isset($session['conference'])): ?>
        <div class="outcome-session-info">
            <p class="session-meta">
                <small>
                    Completed on <?php echo date('F j, Y'); ?>
                    <?php if ($session['is_volunteer_assisted']): ?>
                        • Volunteer-Assisted Session
                    <?php endif; ?>
                </small>
            </p>
        </div>
    <?php endif; ?>
</div>

<style>
/* Outcome Styles */
.questionnaire-outcome {
    max-width: 800px;
    margin: 0 auto;
}

.outcome-header {
    text-align: center;
    padding: 40px 20px;
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border-radius: 8px;
    margin-bottom: 30px;
}

.outcome-success-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    background: #4CAF50;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.outcome-success-icon .dashicons {
    font-size: 50px;
    width: 50px;
    height: 50px;
    color: white;
}

.outcome-title {
    font-size: 2em;
    color: #2c3e50;
    margin: 0 0 15px 0;
    font-weight: 700;
}

.outcome-subtitle {
    font-size: 1.2em;
    color: #555;
    margin: 0;
}

/* Guidance Section */
.outcome-guidance {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 25px;
    margin-bottom: 30px;
    border-radius: 4px;
}

.guidance-title {
    font-size: 1.4em;
    color: #856404;
    margin-top: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.guidance-title .dashicons {
    color: #ffc107;
}

.guidance-content {
    color: #333;
    line-height: 1.8;
    font-size: 1.05em;
}

.guidance-content p {
    margin-bottom: 15px;
}

.guidance-content ul, .guidance-content ol {
    margin-left: 20px;
}

/* Resources Section */
.outcome-resources {
    margin-bottom: 30px;
}

.resources-title {
    font-size: 1.6em;
    color: #2c3e50;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.resources-title .dashicons {
    color: #4CAF50;
}

.resource-count {
    font-size: 0.7em;
    color: #666;
    font-weight: normal;
}

.resources-intro {
    font-size: 1.05em;
    color: #666;
    margin-bottom: 25px;
}

/* Resource Cards */
.resources-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.resource-card {
    background: #ffffff;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 25px;
    transition: all 0.3s ease;
}

.resource-card:hover {
    border-color: #4CAF50;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.resource-card-header {
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e0e0e0;
}

.resource-name {
    font-size: 1.4em;
    color: #2c3e50;
    margin: 0 0 5px 0;
    font-weight: 600;
}

.resource-organization {
    color: #666;
    margin: 0;
    font-size: 1.05em;
}

.resource-meta {
    margin-bottom: 12px;
    color: #555;
}

.resource-description {
    color: #666;
    line-height: 1.6;
    margin-bottom: 15px;
}

/* Contact Information */
.resource-contact {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 15px;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.05em;
}

.contact-item .dashicons {
    color: #4CAF50;
    flex-shrink: 0;
}

.contact-item a {
    color: #0073aa;
    text-decoration: none;
}

.contact-item a:hover {
    text-decoration: underline;
}

/* View Details Button */
.btn-view-details {
    background: #f8f9fa;
    border: 1px solid #ddd;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    width: 100%;
    font-size: 1em;
    color: #0073aa;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-view-details:hover {
    background: #e3f2fd;
    border-color: #0073aa;
}

.resource-full-details {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e0e0e0;
}

.detail-section {
    margin-bottom: 20px;
}

.detail-section h5 {
    font-size: 1.1em;
    color: #2c3e50;
    margin: 0 0 8px 0;
    font-weight: 600;
}

.detail-section p {
    margin: 0;
    color: #555;
    line-height: 1.6;
}

/* No Results */
.outcome-no-results {
    text-align: center;
    padding: 40px 20px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 30px;
}

.outcome-no-results p {
    font-size: 1.2em;
    color: #666;
    margin: 0;
}

/* Actions Section */
.outcome-actions {
    background: #f8f9fa;
    padding: 30px;
    border-radius: 8px;
    margin-top: 40px;
    text-align: center;
}

.outcome-actions h3 {
    font-size: 1.5em;
    color: #2c3e50;
    margin-top: 0;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 15px;
    margin-bottom: 25px;
}

.btn-start-over .dashicons,
.btn-secondary .dashicons {
    vertical-align: middle;
    margin-right: 8px;
}

.help-contact {
    margin-top: 25px;
    padding-top: 25px;
    border-top: 1px solid #ddd;
}

.help-contact p {
    margin: 0;
    color: #555;
    font-size: 1.05em;
}

/* Session Info */
.outcome-session-info {
    text-align: center;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e0e0e0;
}

.session-meta {
    margin: 0;
    color: #999;
    font-size: 0.9em;
}

/* Mobile Responsive */
@media (max-width: 600px) {
    .outcome-header {
        padding: 30px 15px;
    }

    .outcome-title {
        font-size: 1.6em;
    }

    .outcome-subtitle {
        font-size: 1.05em;
    }

    .resource-card {
        padding: 20px;
    }

    .resource-name {
        font-size: 1.2em;
    }

    .action-buttons {
        flex-direction: column;
    }

    .resources-title {
        font-size: 1.3em;
        flex-wrap: wrap;
    }
}

/* Volunteer-Specific Styles */
.volunteer-next-steps {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border: 2px solid #2196f3;
}

.volunteer-action-checklist {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.volunteer-action-checklist h4 {
    color: #1565c0;
    margin-top: 0;
    margin-bottom: 15px;
}

.checklist {
    list-style: none;
    margin: 0 0 20px 0;
    padding: 0;
}

.checklist li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 6px;
    transition: background 0.3s ease;
}

.checklist li:hover {
    background: #e8f5e9;
}

.checklist input[type="checkbox"] {
    width: 20px;
    height: 20px;
    margin-top: 2px;
    cursor: pointer;
    flex-shrink: 0;
}

.checklist label {
    cursor: pointer;
    line-height: 1.6;
    color: #2c3e50;
}

.checklist input[type="checkbox"]:checked + label {
    text-decoration: line-through;
    color: #999;
}

/* Print Styles */
@media print {
    .outcome-actions .action-buttons,
    .btn-view-details,
    .volunteer-helper-box,
    .volunteer-action-checklist {
        display: none !important;
    }

    .resource-full-details {
        display: block !important;
    }

    .volunteer-next-steps h3 {
        page-break-before: always;
    }

    /* Show conference prominently */
    .outcome-subtitle {
        font-size: 1.4em;
        font-weight: bold;
    }
}
</style>

<script>
// Toggle resource details
jQuery(document).ready(function($) {
    $('.btn-view-details').on('click', function() {
        var $details = $(this).siblings('.resource-full-details');
        var $viewMore = $(this).find('.view-more');
        var $viewLess = $(this).find('.view-less');

        $details.slideToggle(300);
        $viewMore.toggle();
        $viewLess.toggle();

        var expanded = $(this).attr('aria-expanded') === 'true';
        $(this).attr('aria-expanded', !expanded);

        // Track resource view
        if (!expanded) {
            var resourceId = $(this).closest('.resource-card').data('resource-id');
            trackResourceView(resourceId);
        }
    });

    function trackResourceView(resourceId) {
        var sessionId = $('.questionnaire-container, .questionnaire-outcome').closest('[data-session-id]').data('session-id');

        if (!sessionId || !resourceId) return;

        $.ajax({
            url: questionnaireFrontend.ajaxUrl,
            type: 'POST',
            data: {
                action: 'questionnaire_track_resource_view',
                nonce: questionnaireFrontend.nonce,
                session_id: sessionId,
                resource_id: resourceId
            }
        });
    }
});
</script>
