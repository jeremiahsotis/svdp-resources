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
                        // Session created successfully - page will reload with first question
                        // The shortcode will handle rendering the question flow
                        window.location.reload();
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
        // QUESTION NAVIGATION (Phase 5)
        // ========================================

        // Multiple choice - enable Continue button when option selected
        $(document).on('change', '.answer-option input[type="radio"]', function() {
            $('.btn-submit-answer').prop('disabled', false);
        });

        // Yes/No buttons - submit immediately
        $(document).on('click', '.btn-answer', function() {
            var answerOptionId = $(this).data('answer-option-id');
            submitAnswer(answerOptionId, null);
        });

        // Continue button (for multiple choice and text)
        $(document).on('click', '.btn-submit-answer', function() {
            var $question = $(this).closest('.questionnaire-question');
            var questionType = $question.data('question-type');

            if (questionType === 'multiple_choice') {
                var answerOptionId = $question.find('input[name="answer"]:checked').val();
                if (!answerOptionId) {
                    showQuestionError('Please select an answer.');
                    return;
                }
                submitAnswer(parseInt(answerOptionId), null);
            } else if (questionType === 'text') {
                var answerText = $question.find('.text-answer-field').val().trim();
                if (!answerText && $question.find('.text-answer-field').prop('required')) {
                    showQuestionError('Please provide an answer.');
                    return;
                }
                submitAnswer(null, answerText);
            }
        });

        // Continue button for info_only questions
        $(document).on('click', '.btn-continue', function() {
            // For info_only, we just record that they saw it and move on
            submitAnswer(null, null);
        });

        // Skip button
        $(document).on('click', '.btn-skip', function() {
            submitAnswer(null, ''); // Empty string indicates skipped
        });

        // Submit answer function
        function submitAnswer(answerOptionId, answerText) {
            var $container = $('.questionnaire-flow');
            var sessionId = $container.data('session-id');
            var $question = $('.questionnaire-question');
            var questionId = $question.data('question-id');

            if (!sessionId || !questionId) {
                showQuestionError('Session error. Please refresh the page.');
                return;
            }

            // Show loading state
            var $button = $('.btn-submit-answer, .btn-answer, .btn-continue').filter(':visible').first();
            $button.addClass('loading').prop('disabled', true);
            $('.question-error').hide();

            // Submit via AJAX
            $.ajax({
                url: questionnaireFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'questionnaire_submit_answer',
                    nonce: questionnaireFrontend.nonce,
                    session_id: sessionId,
                    question_id: questionId,
                    answer_option_id: answerOptionId,
                    answer_text: answerText
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.next_type === 'question') {
                            // Load next question
                            loadNextQuestion(response.data.question_html);
                            announceToScreenReader('Next question loaded.');
                        } else if (response.data.next_type === 'outcome') {
                            // Redirect to outcome (Phase 6)
                            window.location.href = window.location.href.split('?')[0] + '?outcome=' + response.data.outcome_id;
                        }
                    } else {
                        $button.removeClass('loading').prop('disabled', false);
                        showQuestionError(response.data.message || 'Error submitting answer. Please try again.');
                    }
                },
                error: function() {
                    $button.removeClass('loading').prop('disabled', false);
                    showQuestionError('Error submitting answer. Please try again.');
                }
            });
        }

        // Load next question
        function loadNextQuestion(questionHtml) {
            var $questionContainer = $('.question-container');

            // Fade out current question
            $questionContainer.fadeOut(200, function() {
                // Replace with new question
                $questionContainer.html(questionHtml);

                // Update progress
                updateProgress();

                // Fade in new question
                $questionContainer.fadeIn(200);

                // Scroll to question
                $('html, body').animate({
                    scrollTop: $questionContainer.offset().top - 50
                }, 300);
            });
        }

        // Update progress indicator
        function updateProgress() {
            var $container = $('.questionnaire-flow');
            var sessionId = $container.data('session-id');

            if (!sessionId) return;

            $.ajax({
                url: questionnaireFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'questionnaire_get_progress',
                    nonce: questionnaireFrontend.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    if (response.success) {
                        var count = response.data.questions_answered;
                        var text = count === 1 ? 'question' : 'questions';

                        // Update progress display
                        $('.progress-count').html('<strong>' + count + '</strong> ' + text + ' answered');

                        // Update progress bar width (visual feedback)
                        var percentage = Math.min(count * 10, 90); // Max 90% until complete
                        $('.progress-bar-fill').css('width', percentage + '%');
                    }
                }
            });
        }

        // Show question-specific error
        function showQuestionError(message) {
            $('.question-error').html('<strong>Error:</strong> ' + message).fadeIn();
            announceToScreenReader('Error: ' + message);
        }

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
