/**
 * Frontend Questionnaire JavaScript
 *
 * Handles location selection, session creation, and questionnaire navigation
 * Phase 4: Location Service & Frontend Foundation
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        console.log('Questionnaire Frontend JS loaded - Phase 4');

        // ========================================
        // LOCATION SELECTION
        // ========================================

        var selectedConference = null;
        var $beginButton = $('.btn-begin-questionnaire');
        var $conferenceSelect = $('#conference-select');
        var $addressInput = $('#address-input');
        var $lookupButton = $('.btn-lookup-address');

        // Conference dropdown selection
        $conferenceSelect.on('change', function() {
            var conference = $(this).val();

            if (conference) {
                selectedConference = conference;
                $beginButton.prop('disabled', false);

                // Clear address lookup results
                $('.address-result').hide();
                $('.address-error').hide();
            } else {
                selectedConference = null;
                $beginButton.prop('disabled', true);
            }
        });

        // Address lookup button
        $lookupButton.on('click', function() {
            var address = $addressInput.val().trim();

            if (!address) {
                showAddressError('Please enter an address or ZIP code.');
                return;
            }

            // Show loading state
            $lookupButton.addClass('loading').prop('disabled', true);
            $('.address-result').hide();
            $('.address-error').hide();

            // AJAX lookup
            $.ajax({
                url: questionnaireFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'questionnaire_lookup_address',
                    nonce: questionnaireFrontend.nonce,
                    address: address
                },
                success: function(response) {
                    $lookupButton.removeClass('loading').prop('disabled', false);

                    if (response.success) {
                        // Address found!
                        selectedConference = response.data.conference_name;

                        // Show success message
                        var message = '<strong>Conference Found:</strong> ' + response.data.conference_name;
                        if (response.data.conference_description) {
                            message += '<br><small>' + response.data.conference_description + '</small>';
                        }
                        showAddressResult(message);

                        // Pre-select in dropdown
                        $conferenceSelect.val(selectedConference);

                        // Enable begin button
                        $beginButton.prop('disabled', false);

                    } else {
                        // Address not found
                        showAddressError(response.data.message || 'Could not find a Conference for that address. Please select from the dropdown below.');

                        // Focus on dropdown
                        $conferenceSelect.focus();
                    }
                },
                error: function() {
                    $lookupButton.removeClass('loading').prop('disabled', false);
                    showAddressError('Error looking up address. Please try again or select from the dropdown below.');
                }
            });
        });

        // Allow Enter key to trigger lookup
        $addressInput.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $lookupButton.click();
            }
        });

        // ========================================
        // SESSION CREATION
        // ========================================

        $beginButton.on('click', function() {
            if (!selectedConference) {
                showError('Please select a Conference to continue.');
                return;
            }

            var $container = $('.questionnaire-container');
            var questionnaireId = $container.data('questionnaire-id');
            var mode = $container.data('mode') || 'public';

            // Show loading state
            $beginButton.addClass('loading').prop('disabled', true);

            // Create session via AJAX
            $.ajax({
                url: questionnaireFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'questionnaire_create_session',
                    nonce: questionnaireFrontend.nonce,
                    questionnaire_id: questionnaireId,
                    conference: selectedConference,
                    mode: mode
                },
                success: function(response) {
                    if (response.success) {
                        // Session created successfully
                        var sessionId = response.data.session_id;

                        // Store session ID in data attribute
                        $container.data('session-id', sessionId);

                        // Phase 5: Redirect to first question (placeholder for now)
                        // For Phase 4, just show success message
                        $container.html(
                            '<div class="questionnaire-success">' +
                            '<h3>Session Started!</h3>' +
                            '<p>Session ID: ' + sessionId + '</p>' +
                            '<p><strong>Phase 5 Preview:</strong> The first question will appear here.</p>' +
                            '<p><em>Question rendering will be implemented in Phase 5.</em></p>' +
                            '</div>'
                        );

                        // Scroll to top
                        $('html, body').animate({
                            scrollTop: $container.offset().top - 50
                        }, 500);

                    } else {
                        $beginButton.removeClass('loading').prop('disabled', false);
                        showError(response.data.message || 'Error creating session. Please try again.');
                    }
                },
                error: function() {
                    $beginButton.removeClass('loading').prop('disabled', false);
                    showError('Error starting questionnaire. Please try again.');
                }
            });
        });

        // ========================================
        // HELPER FUNCTIONS
        // ========================================

        function showAddressResult(message) {
            $('.address-result').html(message).fadeIn();
            $('.address-error').hide();
        }

        function showAddressError(message) {
            $('.address-error').html(message).fadeIn();
            $('.address-result').hide();
        }

        function showError(message) {
            var $errorContainer = $('.questionnaire-error-container');
            $errorContainer.html('<strong>Error:</strong> ' + message).fadeIn();

            // Scroll to error
            $('html, body').animate({
                scrollTop: $errorContainer.offset().top - 50
            }, 300);
        }

        // ========================================
        // ACCESSIBILITY ENHANCEMENTS
        // ========================================

        // Add aria-live announcements for screen readers
        var $liveRegion = $('<div>', {
            'class': 'sr-only',
            'role': 'status',
            'aria-live': 'polite',
            'aria-atomic': 'true'
        }).appendTo('body');

        function announceToScreenReader(message) {
            $liveRegion.text(message);
            setTimeout(function() {
                $liveRegion.text('');
            }, 1000);
        }

        // Announce when Conference is selected
        $conferenceSelect.on('change', function() {
            if ($(this).val()) {
                announceToScreenReader('Conference selected: ' + $(this).val() + '. You can now begin the questionnaire.');
            }
        });

        // ========================================
        // MOBILE OPTIMIZATIONS
        // ========================================

        // Ensure inputs are visible when keyboard appears on mobile
        if ('ontouchstart' in window) {
            $('input, select').on('focus', function() {
                var $this = $(this);
                setTimeout(function() {
                    $('html, body').animate({
                        scrollTop: $this.offset().top - 100
                    }, 300);
                }, 300);
            });
        }
    });

})(jQuery);
