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
            var $categoryFiltersDiv = $container.find('.service-type-selection, .category-filters-selection');
            var $specificResourcesDiv = $container.find('.specific-resources-selection');

            // Hide all selection divs first
            $categoryFiltersDiv.hide();
            $specificResourcesDiv.hide();

            // Show the appropriate selection div
            if (type === 'categories' || type === 'service_type') {
                $categoryFiltersDiv.show();
            } else if (type === 'specific_resources') {
                $specificResourcesDiv.show();
                // Trigger lazy loading when specific resources section is shown
                var $resourceContainer = $specificResourcesDiv.find('.resource-selection-container');
                if ($resourceContainer.length && !$resourceContainer.data('initialized')) {
                    // Load initial page (empty search, page 1, replace existing)
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

            if (filterType === 'categories' || filterType === 'service_type') {
                // Collect Resource Types
                var resourceTypes = [];
                $form.find('input[name="resource_types[]"]:checked').each(function() {
                    resourceTypes.push($(this).val());
                });
                if (resourceTypes.length > 0) {
                    filterData.resource_types = resourceTypes;
                }

                // Collect Needs Met
                var needsMet = [];
                $form.find('input[name="needs_met[]"]:checked').each(function() {
                    needsMet.push($(this).val());
                });
                if (needsMet.length > 0) {
                    filterData.needs_met = needsMet;
                }

                // Collect Target Audiences
                var targetAudiences = [];
                $form.find('input[name="target_audiences[]"]:checked').each(function() {
                    targetAudiences.push($(this).val());
                });
                if (targetAudiences.length > 0) {
                    filterData.target_audiences = targetAudiences;
                }
            } else if (filterType === 'specific_resources') {
                // Collect selected resource IDs from checkboxes
                var resourceIds = [];
                $form.find('input[name="specific_resource_ids[]"]:checked').each(function() {
                    resourceIds.push(parseInt($(this).val()));
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
        // LAZY LOADING RESOURCES FOR OUTCOMES (Search-Based with Pagination)
        // ========================================

        /**
         * Load resources for a specific outcome via AJAX (with search and pagination)
         */
        function loadResourcesForOutcome($specificResourcesDiv, searchTerm, page, append) {
            var $container = $specificResourcesDiv.find('.resource-selection-container');
            var outcomeId = $specificResourcesDiv.data('outcome-id');
            var $loadingState = $container.find('.resource-loading-state');
            var $listContainer = $container.find('.resource-list-container');
            var $errorState = $container.find('.resource-error-state');
            var $checkboxList = $container.find('.resource-checkbox-list');
            var $loadMoreBtn = $container.find('.load-more-resources-btn');
            
            // Default values
            searchTerm = searchTerm || '';
            page = page || 1;
            append = append !== undefined ? append : false;

            // Get selected IDs from data attribute
            var selectedIds = [];
            try {
                var selectedIdsJson = $container.data('selected-ids');
                if (selectedIdsJson) {
                    selectedIds = typeof selectedIdsJson === 'string' ? JSON.parse(selectedIdsJson) : selectedIdsJson;
                }
            } catch (e) {
                console.error('Error parsing selected IDs:', e);
            }

            // Show loading state (only on first load or new search)
            if (!append) {
                $loadingState.show();
                $listContainer.hide();
                $errorState.hide();
                $loadMoreBtn.hide();
            } else {
                $loadMoreBtn.prop('disabled', true).text('Loading...');
            }

            // #region agent log
            var ajaxData = {
                action: 'get_resources_for_selection',
                nonce: questionnaireAdmin.nonce,
                search: searchTerm,
                page: page,
                per_page: 100
            };
            console.log('QUESTIONNAIRE_DEBUG: Starting AJAX request', ajaxData);
            fetch('http://127.0.0.1:7242/ingest/61f7c9cd-11e0-4365-9c79-c34916a8a396',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'admin-questionnaire.js:1093',message:'AJAX request starting',data:{searchTerm:searchTerm,page:page,ajaxUrl:questionnaireAdmin.ajaxUrl,hasNonce:!!questionnaireAdmin.nonce,action:ajaxData.action,nonceLength:questionnaireAdmin.nonce?questionnaireAdmin.nonce.length:0},timestamp:Date.now(),sessionId:'debug-session',runId:'run1',hypothesisId:'A,E'})}).catch(function(){});
            // #endregion

            // Fetch resources via AJAX
            var ajaxStartTime = Date.now();
            $.ajax({
                url: questionnaireAdmin.ajaxUrl,
                type: 'POST',
                data: ajaxData,
                timeout: 60000, // 60 second timeout
                success: function(response) {
                    // #region agent log
                    var ajaxTime = Date.now() - ajaxStartTime;
                    fetch('http://127.0.0.1:7242/ingest/61f7c9cd-11e0-4365-9c79-c34916a8a396',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'admin-questionnaire.js:1105',message:'AJAX success callback',data:{ajaxTimeMs:ajaxTime,hasSuccess:response.success,hasData:!!response.data,hasResources:!!(response.data&&response.data.resources),resourceCount:response.data&&response.data.resources?response.data.resources.length:0},timestamp:Date.now(),sessionId:'debug-session',runId:'run1',hypothesisId:'A,E'})}).catch(function(){});
                    // #endregion
                    if (response.success && response.data && response.data.resources) {
                        // Hide loading, show list
                        $loadingState.hide();
                        $listContainer.show();
                        $errorState.hide();

                        // Render resources (append or replace)
                        if (append) {
                            appendResourcesToList($checkboxList, response.data.resources, selectedIds);
                        } else {
                            renderResourcesList($container, response.data.resources, selectedIds, searchTerm);
                        }

                        // Show/hide "Load more" button
                        if (response.data.has_more) {
                            $loadMoreBtn.show().prop('disabled', false).text('Load More Resources');
                            $loadMoreBtn.data('next-page', page + 1);
                            $loadMoreBtn.data('search-term', searchTerm);
                        } else {
                            $loadMoreBtn.hide();
                        }

                        // Update count
                        var $countSpan = $container.find('.resource-count');
                        if (response.data.total !== undefined) {
                            $countSpan.text(response.data.total);
                        }
                    } else {
                        showResourceError($container);
                    }
                },
                error: function(xhr, status, error) {
                    // #region agent log
                    var ajaxTime = Date.now() - ajaxStartTime;
                    console.error('QUESTIONNAIRE_DEBUG: AJAX error', {status: status, error: error, xhrStatus: xhr.status, xhrStatusText: xhr.statusText, responseText: xhr.responseText ? xhr.responseText.substring(0, 500) : 'none', ajaxTime: ajaxTime});
                    fetch('http://127.0.0.1:7242/ingest/61f7c9cd-11e0-4365-9c79-c34916a8a396',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'admin-questionnaire.js:1136',message:'AJAX error callback',data:{ajaxTimeMs:ajaxTime,status:status,error:error,xhrStatus:xhr.status,xhrStatusText:xhr.statusText,xhrResponseText:xhr.responseText?xhr.responseText.substring(0,500):'none'},timestamp:Date.now(),sessionId:'debug-session',runId:'run1',hypothesisId:'A'})}).catch(function(){});
                    // #endregion
                    if (status === 'timeout') {
                        showResourceError($container, 'Request timed out. Please try a more specific search term.');
                    } else {
                        showResourceError($container);
                    }
                    $loadMoreBtn.prop('disabled', false).text('Load More Resources');
                },
                beforeSend: function(xhr, settings) {
                    // #region agent log
                    console.log('QUESTIONNAIRE_DEBUG: AJAX beforeSend', {url: settings.url, data: settings.data, type: settings.type});
                    // #endregion
                }
            });
        }

        /**
         * Render resources list in the container (replaces existing list)
         */
        function renderResourcesList($container, resources, selectedIds, searchTerm) {
            var $checkboxList = $container.find('.resource-checkbox-list');
            var $searchInput = $container.find('.resource-search-input');
            var $countSpan = $container.find('.resource-count');

            // Clear existing list
            $checkboxList.empty();

            // Append resources
            appendResourcesToList($checkboxList, resources, selectedIds);

            // Update count (will be updated by AJAX response)
            if (resources.length > 0) {
                $countSpan.text('Loading...');
            }

            // Mark as initialized
            $container.data('initialized', true);
            $container.data('current-search', searchTerm || '');
            $container.data('current-page', 1);

            // Initialize search input handler (debounced AJAX search)
            initializeResourceSearch($container, searchTerm);
        }

        /**
         * Append resources to the list (for pagination)
         */
        function appendResourcesToList($checkboxList, resources, selectedIds) {
            // Use document fragment for efficient DOM updates
            var fragment = document.createDocumentFragment();

            // Build resource checkboxes
            resources.forEach(function(resource) {
                var isSelected = selectedIds.indexOf(resource.id) !== -1;
                var label = document.createElement('label');
                label.className = 'resource-checkbox-item';
                label.style.cssText = 'display: block; margin: 8px 0; padding: 10px; border: 1px solid #e0e0e0; border-radius: 3px; cursor: pointer; background: #fff; transition: background-color 0.2s;';
                label.setAttribute('data-resource-id', resource.id);
                label.setAttribute('data-searchable', resource.searchable || '');

                // Checkbox
                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'specific_resource_ids[]';
                checkbox.value = resource.id;
                checkbox.checked = isSelected;
                checkbox.style.cssText = 'margin-right: 8px; vertical-align: top; margin-top: 3px;';
                label.appendChild(checkbox);

                // Resource details container
                var detailsDiv = document.createElement('div');
                detailsDiv.style.cssText = 'display: inline-block; width: calc(100% - 30px);';

                // Resource name
                var nameDiv = document.createElement('div');
                nameDiv.style.cssText = 'font-weight: 600; color: #0073aa; margin-bottom: 4px;';
                nameDiv.textContent = resource.name;
                detailsDiv.appendChild(nameDiv);

                // Organization
                if (resource.organization) {
                    var orgDiv = document.createElement('div');
                    orgDiv.style.cssText = 'font-size: 0.9em; color: #555; margin-bottom: 3px;';
                    orgDiv.innerHTML = '<strong>Organization:</strong> ' + escapeHtml(resource.organization);
                    detailsDiv.appendChild(orgDiv);
                }

                // Type and Needs Met
                var typeDiv = document.createElement('div');
                typeDiv.style.cssText = 'font-size: 0.9em; color: #666; margin-bottom: 3px;';
                var typeParts = [];
                if (resource.resource_type) {
                    typeParts.push('<strong>Type:</strong> ' + escapeHtml(resource.resource_type));
                }
                if (resource.needs_met) {
                    typeParts.push('<strong>Needs Met:</strong> ' + escapeHtml(resource.needs_met));
                }
                if (typeParts.length > 0) {
                    typeDiv.innerHTML = typeParts.join(' | ');
                    detailsDiv.appendChild(typeDiv);
                }

                // Target Population
                if (resource.target_population) {
                    var popDiv = document.createElement('div');
                    popDiv.style.cssText = 'font-size: 0.85em; color: #777; margin-top: 3px;';
                    popDiv.innerHTML = '<strong>Target Population:</strong> ' + escapeHtml(resource.target_population);
                    detailsDiv.appendChild(popDiv);
                }

                label.appendChild(detailsDiv);
                fragment.appendChild(label);
            });

            // Append fragment to list
            $checkboxList[0].appendChild(fragment);
        }

        /**
         * Show error state for resource loading
         */
        function showResourceError($container, errorMessage) {
            var $loadingState = $container.find('.resource-loading-state');
            var $listContainer = $container.find('.resource-list-container');
            var $errorState = $container.find('.resource-error-state');
            var $loadMoreBtn = $container.find('.load-more-resources-btn');

            $loadingState.hide();
            $listContainer.hide();
            $errorState.show();
            $loadMoreBtn.hide();
            
            if (errorMessage) {
                $errorState.find('p').text(errorMessage);
            }
        }

        /**
         * Initialize search with debouncing for a specific resource container
         * Triggers AJAX search instead of client-side filtering
         */
        function initializeResourceSearch($container, initialSearchTerm) {
            var $searchInput = $container.find('.resource-search-input');
            var $specificResourcesDiv = $container.closest('.specific-resources-selection');
            var searchTimeout = null;

            // Set initial search term if provided
            if (initialSearchTerm) {
                $searchInput.val(initialSearchTerm);
            }

            // Remove any existing handlers
            $searchInput.off('input.resource-search');

            // Add debounced search handler
            $searchInput.on('input.resource-search', function() {
                var $input = $(this);
                var searchTerm = $input.val().trim();

                // Clear previous timeout
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }

                // Debounce search (300ms delay)
                searchTimeout = setTimeout(function() {
                    // Load resources with new search term (page 1, replace existing)
                    loadResourcesForOutcome($specificResourcesDiv, searchTerm, 1, false);
                }, 300);
            });
        }

        /**
         * Debounce helper function
         */
        function debounce(func, wait) {
            var timeout;
            return function() {
                var context = this;
                var args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    func.apply(context, args);
                }, wait);
            };
        }

        // Also load resources when outcome is expanded and has specific_resources selected
        $(document).on('click', '.edit-outcome-btn', function() {
            var $outcome = $(this).closest('.outcome-item');
            var $specificResourcesDiv = $outcome.find('.specific-resources-selection');
            
            // Check if this outcome has specific_resources selected and is visible
            if ($specificResourcesDiv.length && $specificResourcesDiv.is(':visible')) {
                var $resourceContainer = $specificResourcesDiv.find('.resource-selection-container');
                if ($resourceContainer.length && !$resourceContainer.data('initialized')) {
                    // Load initial page (empty search, page 1)
                    loadResourcesForOutcome($specificResourcesDiv, '', 1, false);
                }
            }
        });

        // Handle "Load More" button click
        $(document).on('click', '.load-more-resources-btn', function() {
            var $btn = $(this);
            var $container = $btn.closest('.resource-selection-container');
            var $specificResourcesDiv = $container.closest('.specific-resources-selection');
            var nextPage = $btn.data('next-page') || 2;
            var searchTerm = $btn.data('search-term') || '';

            // Load next page (append to existing list)
            loadResourcesForOutcome($specificResourcesDiv, searchTerm, nextPage, true);
        });
    });

})(jQuery);
