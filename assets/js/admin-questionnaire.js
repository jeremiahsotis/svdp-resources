/**
 * Admin Questionnaire JavaScript
 *
 * JavaScript for questionnaire admin interface
 * Phase 3: Question Builder UI
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        console.log('Questionnaire Admin JS loaded - Phase 3');

        // ========================================
        // TAB NAVIGATION
        // ========================================
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();

            var tab = $(this).data('tab');

            // Update active tab
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            // Show corresponding content
            $('.tab-content').hide();
            $('#tab-' + tab).show();
        });

        // ========================================
        // QUESTION MANAGEMENT
        // ========================================

        // Toggle question details (expand/collapse)
        $(document).on('click', '.question-header', function(e) {
            // Don't toggle if clicking buttons
            if ($(e.target).is('button') || $(e.target).closest('button').length) {
                return;
            }

            var $details = $(this).next('.question-details');
            var $icon = $(this).find('.toggle-question-details');

            $details.slideToggle(200);

            if ($icon.hasClass('dashicons-arrow-down')) {
                $icon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
            } else {
                $icon.removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
            }
        });

        // Edit question button
        $(document).on('click', '.edit-question-btn', function(e) {
            e.stopPropagation();
            var $details = $(this).closest('.question-header').next('.question-details');
            $details.slideDown(200);
            $(this).closest('.question-header').find('.toggle-question-details')
                .removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
        });

        // Cancel edit button
        $(document).on('click', '.cancel-edit-btn', function(e) {
            e.preventDefault();
            var $details = $(this).closest('.question-details');
            $details.slideUp(200);
            $details.prev('.question-header').find('.toggle-question-details')
                .removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
        });

        // Delete question
        $(document).on('click', '.delete-question-btn', function(e) {
            e.stopPropagation();

            if (!confirm('Are you sure you want to delete this question? This cannot be undone.')) {
                return;
            }

            var questionId = $(this).data('question-id');
            var $questionItem = $(this).closest('.question-item');

            $.ajax({
                url: questionnaireAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'delete_question',
                    nonce: questionnaireAdmin.nonce,
                    question_id: questionId
                },
                success: function(response) {
                    if (response.success) {
                        $questionItem.fadeOut(300, function() {
                            $(this).remove();
                            // Update question count
                            updateQuestionCount();
                        });
                    } else {
                        alert('Error deleting question: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error deleting question. Please try again.');
                }
            });
        });

        // Add question button
        $('#add-question-btn').on('click', function() {
            var questionnaireId = getQuestionnaireId();

            $.ajax({
                url: questionnaireAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'add_new_question',
                    nonce: questionnaireAdmin.nonce,
                    questionnaire_id: questionnaireId
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to show new question
                        window.location.reload();
                    } else {
                        alert('Error adding question: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error adding question. Please try again.');
                }
            });
        });

        // Question type change - show/hide answer options
        $(document).on('change', '.question-type-select', function() {
            var type = $(this).val();
            var $answerOptionsSection = $(this).closest('form').find('.answer-options-section');

            if (type === 'multiple_choice' || type === 'yes_no') {
                $answerOptionsSection.slideDown();
            } else {
                $answerOptionsSection.slideUp();
            }
        });

        // ========================================
        // ANSWER OPTIONS MANAGEMENT
        // ========================================

        // Add answer option button
        $(document).on('click', '.add-answer-option-btn', function() {
            var questionId = $(this).data('question-id');
            var $answerOptionsList = $(this).prev('.answer-options-list');

            $.ajax({
                url: questionnaireAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'add_answer_option',
                    nonce: questionnaireAdmin.nonce,
                    question_id: questionId
                },
                success: function(response) {
                    if (response.success) {
                        // Reload to show new option
                        window.location.reload();
                    } else {
                        alert('Error adding answer option: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error adding answer option. Please try again.');
                }
            });
        });

        // Delete answer option
        $(document).on('click', '.delete-answer-option-btn', function(e) {
            e.preventDefault();

            if (!confirm('Remove this answer option?')) {
                return;
            }

            var optionId = $(this).data('option-id');
            var $row = $(this).closest('.answer-option-row');

            $.ajax({
                url: questionnaireAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'delete_answer_option',
                    nonce: questionnaireAdmin.nonce,
                    option_id: optionId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Error deleting answer option: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error deleting answer option. Please try again.');
                }
            });
        });

        // Next action type change - show/hide question/outcome selects
        $(document).on('change', '.next-action-type-select', function() {
            var type = $(this).val();
            var $container = $(this).parent();
            var $questionSelect = $container.find('.next-question-select');
            var $outcomeSelect = $container.find('.outcome-select');

            if (type === 'question') {
                $questionSelect.show();
                $outcomeSelect.hide();
            } else {
                $questionSelect.hide();
                $outcomeSelect.show();
            }
        });

        // ========================================
        // OUTCOME MANAGEMENT
        // ========================================

        // Toggle outcome details
        $(document).on('click', '.outcome-header', function(e) {
            // Don't toggle if clicking buttons
            if ($(e.target).is('button') || $(e.target).closest('button').length) {
                return;
            }

            var $details = $(this).next('.outcome-details');
            var $icon = $(this).find('.toggle-outcome-details');

            $details.slideToggle(200);

            if ($icon.hasClass('dashicons-arrow-down')) {
                $icon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
            } else {
                $icon.removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
            }
        });

        // Edit outcome button
        $(document).on('click', '.edit-outcome-btn', function(e) {
            e.stopPropagation();
            var $details = $(this).closest('.outcome-header').next('.outcome-details');
            $details.slideDown(200);
            $(this).closest('.outcome-header').find('.toggle-outcome-details')
                .removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
        });

        // Cancel edit outcome
        $(document).on('click', '.cancel-edit-outcome-btn', function(e) {
            e.preventDefault();
            var $details = $(this).closest('.outcome-details');
            $details.slideUp(200);
            $details.prev('.outcome-header').find('.toggle-outcome-details')
                .removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
        });

        // Delete outcome
        $(document).on('click', '.delete-outcome-btn', function(e) {
            e.stopPropagation();

            if (!confirm('Are you sure you want to delete this outcome? This cannot be undone.')) {
                return;
            }

            var outcomeId = $(this).data('outcome-id');
            var $outcomeItem = $(this).closest('.outcome-item');

            $.ajax({
                url: questionnaireAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'delete_outcome',
                    nonce: questionnaireAdmin.nonce,
                    outcome_id: outcomeId
                },
                success: function(response) {
                    if (response.success) {
                        $outcomeItem.fadeOut(300, function() {
                            $(this).remove();
                            updateOutcomeCount();
                        });
                    } else {
                        alert('Error deleting outcome: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error deleting outcome. Please try again.');
                }
            });
        });

        // Add outcome button
        $('#add-outcome-btn').on('click', function() {
            var questionnaireId = getQuestionnaireId();

            $.ajax({
                url: questionnaireAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'add_new_outcome',
                    nonce: questionnaireAdmin.nonce,
                    questionnaire_id: questionnaireId
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to show new outcome
                        window.location.reload();
                    } else {
                        alert('Error adding outcome: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error adding outcome. Please try again.');
                }
            });
        });

        // Outcome type change - show/hide guidance and resource sections
        $(document).on('change', '.outcome-type-select', function() {
            var type = $(this).val();
            var $form = $(this).closest('form');
            var $guidanceRow = $form.find('.guidance-text-row');
            var $resourceRow = $form.find('.resource-filter-row');

            if (type === 'guidance') {
                $guidanceRow.show();
                $resourceRow.hide();
            } else if (type === 'resources') {
                $guidanceRow.hide();
                $resourceRow.show();
            } else { // hybrid
                $guidanceRow.show();
                $resourceRow.show();
            }
        });

        // ========================================
        // HELPER FUNCTIONS
        // ========================================

        function getQuestionnaireId() {
            var urlParams = new URLSearchParams(window.location.search);
            return urlParams.get('id');
        }

        function updateQuestionCount() {
            var count = $('.question-item').length;
            $('#tab-questions h2').first().text('Questions (' + count + ')');
        }

        function updateOutcomeCount() {
            var count = $('.outcome-item').length;
            $('#tab-outcomes h2').first().text('Outcomes (' + count + ')');
        }

        // ========================================
        // FORM VALIDATION
        // ========================================

        // Prevent form submission if required fields empty
        $('form.question-edit-form, form.outcome-edit-form').on('submit', function(e) {
            var $form = $(this);
            var valid = true;

            $form.find('[required]').each(function() {
                if (!$(this).val()) {
                    valid = false;
                    $(this).css('border-color', 'red');
                } else {
                    $(this).css('border-color', '');
                }
            });

            if (!valid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return false;
            }
        });
    });

})(jQuery);
