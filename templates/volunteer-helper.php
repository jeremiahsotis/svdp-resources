<?php
/**
 * Template: Volunteer Helper Tips
 *
 * Displays helpful guidance for volunteers using the questionnaire
 * Variables available:
 * - $context: string - 'location', 'questions', or 'outcome'
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$context = isset($context) ? $context : 'questions';
?>

<div class="volunteer-helper-box">
    <div class="volunteer-helper-header">
        <span class="dashicons dashicons-groups"></span>
        <strong>Volunteer Tips</strong>
    </div>

    <div class="volunteer-helper-content">
        <?php if ($context === 'location'): ?>
            <!-- Location Selection Tips -->
            <h4>Helping Someone Get Started:</h4>
            <ul>
                <li>Ask for their street address or ZIP code to find their local Conference</li>
                <li>If they're unsure, help them select the Conference closest to where they live</li>
                <li>Remind them their information is private and confidential</li>
                <li>Let them know this will take about 5-10 minutes</li>
            </ul>

        <?php elseif ($context === 'questions'): ?>
            <!-- Question Navigation Tips -->
            <h4>Guiding Through Questions:</h4>
            <ul>
                <li><strong>Read each question clearly</strong> - Take your time and ensure they understand</li>
                <li><strong>Let them answer</strong> - Avoid suggesting answers; this is their story</li>
                <li><strong>It's okay to skip</strong> - If they're uncomfortable, optional questions can be skipped</li>
                <li><strong>Be patient</strong> - Some questions may bring up difficult emotions</li>
                <li><strong>Reassure privacy</strong> - Their answers are confidential and used only for recommendations</li>
            </ul>

            <div class="volunteer-reminder">
                <strong>Remember:</strong> You're here to guide, not judge. Use trauma-informed language and be compassionate.
            </div>

        <?php elseif ($context === 'outcome'): ?>
            <!-- Outcome Display Tips -->
            <h4>Reviewing Results Together:</h4>
            <ul>
                <li><strong>Go through each resource</strong> - Click to expand details and explain what each offers</li>
                <li><strong>Help them prioritize</strong> - Which resources seem most helpful for their situation?</li>
                <li><strong>Offer to call</strong> - Some people may be nervous to call alone</li>
                <li><strong>Write down information</strong> - Offer to help them note phone numbers and addresses</li>
                <li><strong>Explain next steps</strong> - What happens when they contact each resource?</li>
            </ul>

            <div class="volunteer-reminder">
                <strong>Follow-up:</strong> Let them know they can come back anytime. Offer to check in later if appropriate.
            </div>

        <?php endif; ?>
    </div>

    <!-- Collapse/Expand Toggle -->
    <button type="button" class="volunteer-helper-toggle" aria-label="Toggle volunteer tips">
        <span class="toggle-show">Show Tips</span>
        <span class="toggle-hide" style="display: none;">Hide Tips</span>
    </button>
</div>

<style>
/* Volunteer Helper Box Styles */
.volunteer-helper-box {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border: 2px solid #2196f3;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 25px;
    position: relative;
}

.volunteer-helper-header {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #1565c0;
    font-size: 1.2em;
    margin-bottom: 15px;
}

.volunteer-helper-header .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
}

.volunteer-helper-content h4 {
    color: #1565c0;
    margin: 0 0 12px 0;
    font-size: 1.1em;
}

.volunteer-helper-content ul {
    margin: 0 0 15px 20px;
    padding: 0;
    color: #0d47a1;
}

.volunteer-helper-content li {
    margin-bottom: 8px;
    line-height: 1.6;
}

.volunteer-helper-content li strong {
    color: #1565c0;
}

.volunteer-reminder {
    background: rgba(255, 255, 255, 0.7);
    padding: 12px 15px;
    border-left: 4px solid #ff9800;
    border-radius: 4px;
    margin-top: 15px;
    color: #e65100;
}

.volunteer-reminder strong {
    display: block;
    margin-bottom: 5px;
}

.volunteer-helper-toggle {
    background: #2196f3;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
    margin-top: 10px;
    transition: background 0.3s ease;
}

.volunteer-helper-toggle:hover {
    background: #1976d2;
}

/* Collapsed State */
.volunteer-helper-box.collapsed .volunteer-helper-content {
    display: none;
}

.volunteer-helper-box.collapsed .volunteer-helper-toggle .toggle-show {
    display: inline;
}

.volunteer-helper-box.collapsed .volunteer-helper-toggle .toggle-hide {
    display: none;
}

.volunteer-helper-box:not(.collapsed) .volunteer-helper-toggle .toggle-show {
    display: none;
}

.volunteer-helper-box:not(.collapsed) .volunteer-helper-toggle .toggle-hide {
    display: inline;
}

/* Mobile Responsive */
@media (max-width: 600px) {
    .volunteer-helper-box {
        padding: 15px;
    }

    .volunteer-helper-header {
        font-size: 1.1em;
    }

    .volunteer-helper-content h4 {
        font-size: 1em;
    }

    .volunteer-helper-content ul {
        margin-left: 15px;
    }
}

/* Print */
@media print {
    .volunteer-helper-box {
        page-break-inside: avoid;
    }

    .volunteer-helper-toggle {
        display: none;
    }

    .volunteer-helper-content {
        display: block !important;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Toggle volunteer helper box
    $('.volunteer-helper-toggle').on('click', function() {
        var $box = $(this).closest('.volunteer-helper-box');
        var isCollapsed = $box.hasClass('collapsed');

        // Toggle the collapsed class
        $box.toggleClass('collapsed');

        // Explicitly control button text visibility to prevent blank state
        $(this).find('.toggle-show').toggle(!isCollapsed);
        $(this).find('.toggle-hide').toggle(isCollapsed);
    });

    // Start collapsed on mobile
    if ($(window).width() < 768) {
        $('.volunteer-helper-box').addClass('collapsed');
    }
});
</script>
