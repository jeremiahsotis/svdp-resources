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
        // HANDLE ACTIVE TAB RESTORATION
        // ========================================

        // Check sessionStorage for active tab
        var activeTab = sessionStorage.getItem('questionnaire_active_tab');
        var expandOutcomeId = sessionStorage.getItem('questionnaire_expand_outcome');

        // Clear BEFORE restoring to prevent race conditions
        sessionStorage.removeItem('questionnaire_active_tab');
        sessionStorage.removeItem('questionnaire_expand_outcome');

        // Also check URL hash as fallback
        if (window.location.hash) {
            var hash = window.location.hash.substring(1); // Remove the #
            if (hash === 'outcomes' || hash.startsWith('outcomes-')) {
                activeTab = 'outcomes';
                if (hash.startsWith('outcomes-')) {
                    expandOutcomeId = hash.replace('outcomes-', '');
                }
            }
        }

        // Restore active tab if we have one
        if (activeTab && activeTab !== 'questions') {
            console.log('Restoring tab:', activeTab);
            // Manually switch tabs without triggering click event to avoid re-saving to sessionStorage
            $('.nav-tab').removeClass('nav-tab-active');
            $('.nav-tab[data-tab="' + activeTab + '"]').addClass('nav-tab-active');
            $('.tab-content').hide();
            $('#tab-' + activeTab).show();
        }

        // Auto-expand and scroll to outcome if specified
        if (expandOutcomeId) {
            setTimeout(function() {
                var $outcome = $('.outcome-item[data-outcome-id="' + expandOutcomeId + '"]');
                if ($outcome.length) {
                    // Scroll to the outcome
                    $('html, body').animate({
                        scrollTop: $outcome.offset().top - 100
                    }, 500);
                    // Expand it
                    $outcome.find('.edit-outcome-btn').click();
                }
            }, 200);
        }

        // Clear the hash from URL (keep it clean)
        if (window.location.hash) {
            history.replaceState(null, null, window.location.pathname + window.location.search);
        }

        // ========================================
        // DRAG AND DROP INITIALIZATION
        // ========================================

        // Initialize sortable for questions list
        $('#questions-list').sortable({
            handle: '.question-header',
            axis: 'y',
            cursor: 'move',
            opacity: 0.7,
            placeholder: 'sortable-placeholder',
            update: function(event, ui) {
                // Get new order
                var questionIds = [];
                $('#questions-list .question-item').each(function() {
                    questionIds.push($(this).data('question-id'));
                });

                // Save new order via AJAX
                $.ajax({
                    url: questionnaireAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'reorder_questions',
                        nonce: questionnaireAdmin.nonce,
                        question_ids: questionIds
                    },
                    success: function(response) {
                        if (!response.success) {
                            alert('Error saving question order: ' + (response.data || 'Unknown error'));
                        }
                    },
                    error: function() {
                        alert('Error saving question order. Please refresh the page.');
                    }
                });
            }
        });

        // Initialize sortable for answer options (delegated)
        $(document).on('mouseenter', '.answer-options-list', function() {
            if (!$(this).hasClass('ui-sortable')) {
                $(this).sortable({
                    axis: 'y',
                    cursor: 'move',
                    opacity: 0.7,
                    placeholder: 'sortable-placeholder',
                    handle: '.answer-option-row',
                    update: function(event, ui) {
                        // Get new order
                        var optionIds = [];
                        $(this).find('.answer-option-row').each(function() {
                            var optionId = $(this).find('input[name="answer_option_ids[]"]').val();
                            if (optionId) {
                                optionIds.push(optionId);
                            }
                        });

                        // Save new order via AJAX
                        $.ajax({
                            url: questionnaireAdmin.ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'reorder_answer_options',
                                nonce: questionnaireAdmin.nonce,
                                option_ids: optionIds
                            },
                            success: function(response) {
                                if (!response.success) {
                                    alert('Error saving answer option order: ' + (response.data || 'Unknown error'));
                                }
                            },
                            error: function() {
                                alert('Error saving answer option order. Please refresh the page.');
                            }
                        });
                    }
                });
            }
        });

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

            // Save current tab to sessionStorage (for page reloads)
            sessionStorage.setItem('questionnaire_active_tab', tab);
        });

        // ========================================
        // FLOW PREVIEW TAB
        // ========================================

        var flowPreviewInitialized = false;
        var flowData = null;

        // Initialize Mermaid config when library loads
        function initMermaid() {
            if (typeof mermaid !== 'undefined') {
                mermaid.initialize({
                    startOnLoad: false,
                    theme: 'default',
                    flowchart: {
                        curve: 'basis',
                        padding: 20,
                        nodeSpacing: 50,
                        rankSpacing: 80,
                        useMaxWidth: true,
                        htmlLabels: true
                    },
                    securityLevel: 'loose' // Allow click handlers
                });
            }
        }

        // Load flow data from embedded JSON
        function loadFlowData() {
            try {
                var dataElement = document.getElementById('questionnaire-flow-data');
                if (dataElement) {
                    flowData = JSON.parse(dataElement.textContent);
                    return true;
                }
            } catch (e) {
                console.error('Error loading flow data:', e);
            }
            return false;
        }

        // Render the flow diagram
        function renderFlowDiagram() {
            if (!flowData) {
                if (!loadFlowData()) {
                    showFlowError();
                    return;
                }
            }

            var $loading = $('#flow-diagram-loading');
            var $content = $('#flow-diagram-content');
            var $error = $('#flow-diagram-error');
            var $empty = $('#flow-diagram-empty');
            var $warnings = $('#flow-validation-warnings');

            // Hide all states
            $loading.hide();
            $content.hide();
            $error.hide();
            $empty.hide();
            $warnings.hide();

            // Check for empty state
            if (flowData.empty) {
                $empty.show();
                return;
            }

            // Show validation warnings
            if (flowData.validation && flowData.validation.length > 0) {
                renderValidationWarnings(flowData.validation);
                $warnings.show();
            }

            // Render mermaid diagram
            try {
                // Show loading
                $loading.show();

                // Initialize Mermaid if needed
                initMermaid();

                // Clear previous diagram
                $('#flow-mermaid-diagram').empty();

                // Render new diagram
                var diagramId = 'mermaid-' + Date.now();
                mermaid.render(diagramId, flowData.mermaid).then(function(result) {
                    $('#flow-mermaid-diagram').html(result.svg);

                    // Add click handlers to nodes
                    addNodeClickHandlers();

                    $loading.hide();
                    $content.show();
                }).catch(function(error) {
                    console.error('Mermaid render error:', error);
                    $loading.hide();
                    $error.show();
                });
            } catch (e) {
                console.error('Error rendering flow diagram:', e);
                $loading.hide();
                $error.show();
            }
        }

        // Render validation warnings as alerts
        function renderValidationWarnings(warnings) {
            var html = '';
            var errors = warnings.filter(function(w) { return w.type === 'error'; });
            var warningsOnly = warnings.filter(function(w) { return w.type === 'warning'; });

            if (errors.length > 0) {
                html += '<div class="notice notice-error" style="margin: 0 0 10px 0;">';
                html += '<p><strong>Errors Found:</strong></p>';
                html += '<ul style="margin: 5px 0; padding-left: 20px;">';
                errors.forEach(function(err) {
                    html += '<li>' + escapeHtml(err.message);
                    if (err.question_id) {
                        html += ' <a href="#" class="jump-to-question" data-question-id="' + err.question_id + '">(Edit Question)</a>';
                    } else if (err.outcome_id) {
                        html += ' <a href="#" class="jump-to-outcome" data-outcome-id="' + err.outcome_id + '">(Edit Outcome)</a>';
                    }
                    html += '</li>';
                });
                html += '</ul></div>';
            }

            if (warningsOnly.length > 0) {
                html += '<div class="notice notice-warning" style="margin: 0;">';
                html += '<p><strong>Warnings:</strong></p>';
                html += '<ul style="margin: 5px 0; padding-left: 20px;">';
                warningsOnly.forEach(function(warn) {
                    html += '<li>' + escapeHtml(warn.message);
                    if (warn.question_id) {
                        html += ' <a href="#" class="jump-to-question" data-question-id="' + warn.question_id + '">(Edit Question)</a>';
                    } else if (warn.outcome_id) {
                        html += ' <a href="#" class="jump-to-outcome" data-outcome-id="' + warn.outcome_id + '">(Edit Outcome)</a>';
                    }
                    html += '</li>';
                });
                html += '</ul></div>';
            }

            $('#flow-validation-warnings').html(html);
        }

        // Add click handlers to Mermaid nodes
        function addNodeClickHandlers() {
            // Question nodes (Q prefix)
            $('#flow-mermaid-diagram [id^="flowchart-Q"]').each(function() {
                var nodeId = $(this).attr('id').replace('flowchart-', '');
                var questionId = nodeId.replace('Q', '');

                $(this).css('cursor', 'pointer');
                $(this).on('click', function(e) {
                    e.preventDefault();
                    jumpToQuestion(questionId);
                });
            });

            // Outcome nodes (O prefix)
            $('#flow-mermaid-diagram [id^="flowchart-O"]').each(function() {
                var nodeId = $(this).attr('id').replace('flowchart-', '');
                var outcomeId = nodeId.replace('O', '');

                $(this).css('cursor', 'pointer');
                $(this).on('click', function(e) {
                    e.preventDefault();
                    jumpToOutcome(outcomeId);
                });
            });
        }

        // Jump to question in Questions tab
        function jumpToQuestion(questionId) {
            // Switch to Questions tab
            $('.nav-tab').removeClass('nav-tab-active');
            $('.nav-tab[data-tab="questions"]').addClass('nav-tab-active');
            $('.tab-content').hide();
            $('#tab-questions').show();

            // Scroll to and expand the question
            setTimeout(function() {
                var $question = $('.question-item[data-question-id="' + questionId + '"]');
                if ($question.length) {
                    $('html, body').animate({
                        scrollTop: $question.offset().top - 100
                    }, 500);

                    // Expand the question details
                    $question.find('.edit-question-btn').click();
                }
            }, 100);
        }

        // Jump to outcome in Outcomes tab
        function jumpToOutcome(outcomeId) {
            // Switch to Outcomes tab
            sessionStorage.setItem('questionnaire_active_tab', 'outcomes');
            sessionStorage.setItem('questionnaire_expand_outcome', outcomeId);

            $('.nav-tab').removeClass('nav-tab-active');
            $('.nav-tab[data-tab="outcomes"]').addClass('nav-tab-active');
            $('.tab-content').hide();
            $('#tab-outcomes').show();

            // Scroll to and expand the outcome
            setTimeout(function() {
                var $outcome = $('.outcome-item[data-outcome-id="' + outcomeId + '"]');
                if ($outcome.length) {
                    $('html, body').animate({
                        scrollTop: $outcome.offset().top - 100
                    }, 500);

                    // Expand the outcome details
                    $outcome.find('.edit-outcome-btn').click();
                }
            }, 100);
        }

        // Show error state
        function showFlowError() {
            $('#flow-diagram-loading').hide();
            $('#flow-diagram-content').hide();
            $('#flow-diagram-empty').hide();
            $('#flow-diagram-error').show();
        }

        // Refresh flow diagram button
        $('#refresh-flow-btn').on('click', function() {
            flowData = null; // Force reload
            renderFlowDiagram();
        });

        // Render diagram when Preview tab is clicked
        $('.nav-tab[data-tab="preview"]').on('click', function() {
            if (!flowPreviewInitialized) {
                // Wait for Mermaid to load
                setTimeout(function() {
                    renderFlowDiagram();
                    flowPreviewInitialized = true;
                }, 100);
            }
        });

        // Handle jump links in validation warnings
        $(document).on('click', '.jump-to-question', function(e) {
            e.preventDefault();
            var questionId = $(this).data('question-id');
            jumpToQuestion(questionId);
        });

        $(document).on('click', '.jump-to-outcome', function(e) {
            e.preventDefault();
            var outcomeId = $(this).data('outcome-id');
            jumpToOutcome(outcomeId);
        });

        // Initialize Mermaid when document is ready
        $(document).ready(function() {
            // Wait for Mermaid library to load
            var checkMermaid = setInterval(function() {
                if (typeof mermaid !== 'undefined') {
                    initMermaid();
                    clearInterval(checkMermaid);
                }
            }, 100);
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

        // Question type change - show/hide answer options or next step section
        $(document).on('change', '.question-type-select', function() {
            var type = $(this).val();
            var $form = $(this).closest('form');
            var $answerOptionsSection = $form.find('.answer-options-section');
            var $nextStepSection = $form.find('.next-step-section');

            if (type === 'multiple_choice' || type === 'yes_no') {
                $answerOptionsSection.slideDown();
                $nextStepSection.slideUp();
            } else if (type === 'text' || type === 'info_only') {
                $answerOptionsSection.slideUp();
                $nextStepSection.slideDown();
            } else {
                $answerOptionsSection.slideUp();
                $nextStepSection.slideUp();
            }
        });

        // Direct next action type change - show/hide question/outcome selects (for text/info_only)
        $(document).on('change', '.direct-next-action-type-select', function() {
            var type = $(this).val();
            var $container = $(this).parent();
            var $questionSelect = $container.find('.direct-next-question-select');
            var $outcomeSelect = $container.find('.direct-outcome-select');

            if (type === 'question') {
                $questionSelect.show();
                $outcomeSelect.hide();
            } else {
                $questionSelect.hide();
                $outcomeSelect.show();
            }
        });

        // ========================================
        // INITIALIZE DROPDOWN VISIBILITY ON PAGE LOAD
        // ========================================

        // Initialize for ALL direct next step sections (text/info_only questions)
        $('.direct-next-action-type-select').each(function() {
            var type = $(this).val();
            var $container = $(this).parent();
            var $questionSelect = $container.find('.direct-next-question-select');
            var $outcomeSelect = $container.find('.direct-outcome-select');

            if (type === 'question') {
                $questionSelect.show();
                $outcomeSelect.hide();
            } else {
                $questionSelect.hide();
                $outcomeSelect.show();
            }
        });

        // Initialize for ALL answer option next action selects (multiple_choice/yes_no questions)
        $('.next-action-type-select').each(function() {
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
        // ANSWER OPTIONS MANAGEMENT
        // ========================================

        // Add answer option button
        $(document).on('click', '.add-answer-option-btn', function() {
            var questionId = $(this).data('question-id');
            var $answerOptionsList = $(this).prev('.answer-options-list');
            var $button = $(this);

            // Disable button to prevent double-clicks
            $button.prop('disabled', true);

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
                        // Instead of reloading, dynamically add the new option to the DOM
                        var newOptionHtml = buildAnswerOptionHtml(
                            response.data.option_id,
                            'New Answer Option',
                            response.data.questions,
                            response.data.outcomes
                        );
                        $answerOptionsList.append(newOptionHtml);

                        // Initialize dropdown visibility for the newly added row
                        var $newRow = $answerOptionsList.children('.answer-option-row').last();
                        var $newActionSelect = $newRow.find('.next-action-type-select');
                        $newActionSelect.trigger('change'); // Trigger change to set initial visibility

                        // Re-enable sortable if it exists
                        if ($answerOptionsList.hasClass('ui-sortable')) {
                            $answerOptionsList.sortable('refresh');
                        }
                    } else {
                        alert('Error adding answer option: ' + (response.data || 'Unknown error'));
                    }
                    $button.prop('disabled', false);
                },
                error: function() {
                    alert('Error adding answer option. Please try again.');
                    $button.prop('disabled', false);
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
            var $button = $(this);

            // Disable button to prevent double-clicks
            $button.prop('disabled', true);

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
                        // Save tab state and outcome to expand in sessionStorage
                        sessionStorage.setItem('questionnaire_active_tab', 'outcomes');
                        sessionStorage.setItem('questionnaire_expand_outcome', response.data.outcome_id);

                        // Reload page - sessionStorage will restore the outcomes tab and expand the new outcome
                        window.location.reload();
                    } else {
                        alert('Error adding outcome: ' + (response.data || 'Unknown error'));
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Error adding outcome. Please try again.');
                    $button.prop('disabled', false);
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

        // Resource filter type change - show/hide filter selection UI
        $(document).on('change', '.resource-filter-type-radio', function() {
            var type = $(this).val();
            var $container = $(this).closest('td');
            var $serviceTypeDiv = $container.find('.service-type-selection');
            var $specificResourcesDiv = $container.find('.specific-resources-selection');

            // Hide all selection divs first
            $serviceTypeDiv.hide();
            $specificResourcesDiv.hide();

            // Show the appropriate selection div
            if (type === 'service_type') {
                $serviceTypeDiv.show();
            } else if (type === 'specific_resources') {
                $specificResourcesDiv.show();
                var $resourceContainer = $specificResourcesDiv.find('.resource-selection-container');
                if ($resourceContainer.length && !$resourceContainer.data('initialized')) {
                    loadResourcesForOutcome($specificResourcesDiv, '', 1, false);
                }
            }
            // 'none' type - both stay hidden
        });

        // Initialize filter selection visibility on page load
        $('.resource-filter-type-radio:checked').each(function() {
            $(this).trigger('change');
        });

        // Before submitting outcome form, serialize filter data to JSON
        $(document).on('submit', '.outcome-edit-form', function(e) {
            var $form = $(this);
            var filterType = $form.find('input[name="resource_filter_type"]:checked').val();

            // Remove any existing hidden input for filter data
            $form.find('input[name="resource_filter_data_json"]').remove();

            var filterData = {};

            if (filterType === 'service_type') {
                var serviceAreas = [];
                $form.find('input[name="service_areas[]"]:checked').each(function() {
                    serviceAreas.push($(this).val());
                });
                if (serviceAreas.length > 0) {
                    filterData.service_areas = serviceAreas;
                }

                var servicesOffered = [];
                $form.find('input[name="services_offered[]"]:checked').each(function() {
                    servicesOffered.push($(this).val());
                });
                if (servicesOffered.length > 0) {
                    filterData.services_offered = servicesOffered;
                }

                var targetPopulations = [];
                $form.find('input[name="target_populations[]"]:checked').each(function() {
                    targetPopulations.push($(this).val());
                });
                if (targetPopulations.length > 0) {
                    filterData.target_populations = targetPopulations;
                }

                var providerType = $form.find('input[name="provider_type"]:checked').val();
                if (providerType) {
                    filterData.provider_type = providerType;
                }
            } else if (filterType === 'specific_resources') {
                // Collect selected resource IDs
                var resourceIds = [];
                $form.find('input[name="specific_resource_ids[]"]:checked').each(function() {
                    var resourceId = parseInt($(this).val(), 10);
                    if (!isNaN(resourceId) && resourceId > 0) {
                        resourceIds.push(resourceId);
                    }
                });
                if (resourceIds.length > 0) {
                    filterData.specific_ids = resourceIds;
                }
            }

            // Add serialized JSON as hidden input
            var jsonData = JSON.stringify(filterData);
            $form.append('<input type="hidden" name="resource_filter_data_json" value="' + escapeHtml(jsonData) + '">');

            // Store active tab before submission
            sessionStorage.setItem('questionnaire_active_tab', 'outcomes');
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

        /**
         * Build HTML for a new answer option row
         */
        function buildAnswerOptionHtml(optionId, answerText, questions, outcomes) {
            var html = '<div class="answer-option-row" style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border: 1px solid #ddd;">';
            html += '<input type="hidden" name="answer_option_ids[]" value="' + optionId + '">';
            html += '<div style="display: grid; grid-template-columns: 2fr 2fr 1fr; gap: 10px; align-items: start;">';

            // Answer Text
            html += '<div>';
            html += '<label style="font-weight: bold; display: block; margin-bottom: 5px;">Answer Text</label>';
            html += '<input type="text" name="answer_texts[' + optionId + ']" value="' + answerText + '" class="regular-text" required>';
            html += '</div>';

            // Then Go To
            html += '<div>';
            html += '<label style="font-weight: bold; display: block; margin-bottom: 5px;">Then Go To</label>';
            html += '<select name="next_action_type[' + optionId + ']" class="next-action-type-select">';
            html += '<option value="question">Next Question</option>';
            html += '<option value="outcome">Outcome/Result</option>';
            html += '</select>';

            // Next Question Select
            html += '<select name="next_question_id[' + optionId + ']" class="next-question-select" style="margin-top: 5px;">';
            html += '<option value="">-- Select Question --</option>';
            if (questions && questions.length) {
                questions.forEach(function(q) {
                    html += '<option value="' + q.id + '">' + escapeHtml(q.question_text) + '</option>';
                });
            }
            html += '</select>';

            // Outcome Select
            html += '<select name="outcome_id[' + optionId + ']" class="outcome-select" style="margin-top: 5px; display: none;">';
            html += '<option value="">-- Select Outcome --</option>';
            if (outcomes && outcomes.length) {
                outcomes.forEach(function(o) {
                    html += '<option value="' + o.id + '">' + escapeHtml(o.name) + '</option>';
                });
            }
            html += '</select>';
            html += '</div>';

            // Delete Button
            html += '<div style="text-align: right;">';
            html += '<button type="button" class="button button-small delete-answer-option-btn" data-option-id="' + optionId + '">Remove</button>';
            html += '</div>';

            html += '</div></div>';
            return html;
        }

        /**
         * Escape HTML for safe insertion
         */
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // ========================================
        // FORM VALIDATION & TAB PERSISTENCE
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

            // If submitting an outcome form, save the outcomes tab to sessionStorage
            // so we return to the outcomes tab after the page reloads
            if ($form.hasClass('outcome-edit-form')) {
                sessionStorage.setItem('questionnaire_active_tab', 'outcomes');
            }
        });

        // ========================================
        // LAZY RESOURCE LOADING FOR OUTCOMES
        // ========================================

        function getSelectedResourceIds($resourceContainer) {
            var raw = $resourceContainer.attr('data-selected-ids') || '[]';

            try {
                var parsed = JSON.parse(raw);
                if (!Array.isArray(parsed)) {
                    return [];
                }

                return parsed
                    .map(function(id) {
                        return parseInt(id, 10);
                    })
                    .filter(function(id) {
                        return !isNaN(id) && id > 0;
                    });
            } catch (e) {
                return [];
            }
        }

        function renderResourceOptions($list, resources, selectedIds, append) {
            if (!append) {
                $list.empty();
            }

            if (!Array.isArray(resources) || resources.length === 0) {
                if (!append) {
                    $list.html('<p style="margin:0;">No resources found.</p>');
                }
                return;
            }

            var html = '';
            resources.forEach(function(resource) {
                var id = parseInt(resource.id, 10);
                if (isNaN(id) || id <= 0) {
                    return;
                }

                var isChecked = selectedIds.indexOf(id) !== -1 ? ' checked' : '';
                var resourceName = escapeHtml(String(resource.resource_name || ('Resource ' + id)));
                var organization = resource.organization
                    ? '<div style="font-size:0.9em;color:#555;margin-top:3px;">Organization: ' + escapeHtml(String(resource.organization)) + '</div>'
                    : '';
                var serviceArea = resource.service_area
                    ? '<div style="font-size:0.85em;color:#666;margin-top:2px;">Service Area: ' + escapeHtml(String(resource.service_area)) + '</div>'
                    : '';

                html += '<label class="resource-checkbox-item" style="display:block;margin:8px 0;padding:10px;border:1px solid #e0e0e0;border-radius:3px;cursor:pointer;background:#fff;">';
                html += '<input type="checkbox" name="specific_resource_ids[]" value="' + id + '"' + isChecked + ' style="margin-right:8px;vertical-align:top;margin-top:3px;">';
                html += '<span style="display:inline-block;width:calc(100% - 30px);">';
                html += '<span style="font-weight:600;color:#0073aa;">' + resourceName + '</span>';
                html += organization;
                html += serviceArea;
                html += '</span>';
                html += '</label>';
            });

            if (append) {
                $list.append(html);
            } else {
                $list.html(html);
            }
        }

        function showResourceError($container, message) {
            var $loadingState = $container.find('.resource-loading-state');
            var $listContainer = $container.find('.resource-list-container');
            var $errorState = $container.find('.resource-error-state');
            var $loadMoreBtn = $container.find('.load-more-resources-btn');

            $loadingState.hide();
            $listContainer.hide();
            $errorState.show();
            $loadMoreBtn.hide();

            if (message) {
                $errorState.find('p').text(message);
            }
        }

        function loadResourcesForOutcome($specificResourcesDiv, search, page, append) {
            var $container = $specificResourcesDiv.find('.resource-selection-container');
            var $loadingState = $container.find('.resource-loading-state');
            var $listContainer = $container.find('.resource-list-container');
            var $errorState = $container.find('.resource-error-state');
            var $list = $container.find('.resource-checkbox-list');
            var $count = $container.find('.resource-count');
            var $loadMoreBtn = $container.find('.load-more-resources-btn');
            var selectedIds = getSelectedResourceIds($container);

            if (!append) {
                $list.empty();
                $loadMoreBtn.hide();
            }

            $errorState.hide();
            if (!append) {
                $loadingState.show();
                $listContainer.hide();
            } else {
                $loadMoreBtn.prop('disabled', true).text('Loading...');
            }

            $.ajax({
                url: questionnaireAdmin.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'get_resources_for_selection',
                    nonce: questionnaireAdmin.nonce,
                    search: search || '',
                    page: page || 1
                }
            })
                .done(function(response) {
                    if (!response || !response.success || !response.data) {
                        showResourceError($container, 'Failed to load resources. Please refresh and try again.');
                        return;
                    }

                    var resources = Array.isArray(response.data.resources) ? response.data.resources : [];
                    var pagination = response.data.pagination || {};

                    renderResourceOptions($list, resources, selectedIds, append);

                    $loadingState.hide();
                    $listContainer.show();
                    $container.data('initialized', true);

                    var totalItems = parseInt(pagination.total_items || resources.length, 10);
                    if (!isNaN(totalItems) && totalItems >= 0) {
                        $count.text(String(totalItems));
                    }

                    if (pagination.has_more) {
                        $loadMoreBtn
                            .data('next-page', (page || 1) + 1)
                            .data('search-term', search || '')
                            .prop('disabled', false)
                            .text('Load More Resources')
                            .show();
                    } else {
                        $loadMoreBtn.hide();
                    }
                })
                .fail(function() {
                    showResourceError($container, 'Failed to load resources. Please refresh and try again.');
                })
                .always(function() {
                    if (append) {
                        $loadMoreBtn.prop('disabled', false).text('Load More Resources');
                    }
                });
        }

        var resourceSearchTimers = {};

        $(document).on('input', '.resource-search-input', function() {
            var $input = $(this);
            var $specificResourcesDiv = $input.closest('.specific-resources-selection');
            var key = String($specificResourcesDiv.data('outcome-id') || 'default');
            var searchTerm = $input.val().trim();

            if (resourceSearchTimers[key]) {
                clearTimeout(resourceSearchTimers[key]);
            }

            resourceSearchTimers[key] = setTimeout(function() {
                loadResourcesForOutcome($specificResourcesDiv, searchTerm, 1, false);
            }, 300);
        });

        $(document).on('click', '.edit-outcome-btn', function() {
            var $outcome = $(this).closest('.outcome-item');
            var $specificResourcesDiv = $outcome.find('.specific-resources-selection');

            if ($specificResourcesDiv.length && $specificResourcesDiv.is(':visible')) {
                var $resourceContainer = $specificResourcesDiv.find('.resource-selection-container');
                if ($resourceContainer.length && !$resourceContainer.data('initialized')) {
                    loadResourcesForOutcome($specificResourcesDiv, '', 1, false);
                }
            }
        });

        $(document).on('click', '.load-more-resources-btn', function() {
            var $button = $(this);
            var $container = $button.closest('.resource-selection-container');
            var $specificResourcesDiv = $container.closest('.specific-resources-selection');
            var nextPage = parseInt($button.data('next-page') || 2, 10);
            var searchTerm = String($button.data('search-term') || '');

            if (isNaN(nextPage) || nextPage < 2) {
                nextPage = 2;
            }

            loadResourcesForOutcome($specificResourcesDiv, searchTerm, nextPage, true);
        });
    });

})(jQuery);
