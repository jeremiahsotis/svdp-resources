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
        // ACCORDION BEHAVIOR FOR QUESTIONS AND OUTCOMES
        // ========================================

        // Toggle QUESTION details on Edit button click
        $(document).on('click', '.edit-question-btn', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent header click from interfering

            var $btn = $(this);
            var $questionItem = $btn.closest('.question-item');
            var $details = $questionItem.find('.question-details');
            var isExpanded = $btn.attr('data-expanded') === 'true';

            if (isExpanded) {
                // Collapse
                $details.slideUp(200);
                $btn.text('Edit').attr('data-expanded', 'false');
                $questionItem.find('.toggle-question-details')
                    .removeClass('dashicons-arrow-up')
                    .addClass('dashicons-arrow-down');
            } else {
                // Expand
                $details.slideDown(200);
                $btn.text('Collapse').attr('data-expanded', 'true');
                $questionItem.find('.toggle-question-details')
                    .removeClass('dashicons-arrow-down')
                    .addClass('dashicons-arrow-up');
            }
        });

        // Toggle OUTCOME details on Edit button click
        $(document).on('click', '.edit-outcome-btn', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent header click from interfering

            var $btn = $(this);
            var $outcomeItem = $btn.closest('.outcome-item');
            var $details = $outcomeItem.find('.outcome-details');
            var isExpanded = $btn.attr('data-expanded') === 'true';

            if (isExpanded) {
                // Collapse
                $details.slideUp(200);
                $btn.text('Edit').attr('data-expanded', 'false');
                $outcomeItem.find('.toggle-outcome-details')
                    .removeClass('dashicons-arrow-up')
                    .addClass('dashicons-arrow-down');
            } else {
                // Expand
                $details.slideDown(200);
                $btn.text('Collapse').attr('data-expanded', 'true');
                $outcomeItem.find('.toggle-outcome-details')
                    .removeClass('dashicons-arrow-down')
                    .addClass('dashicons-arrow-up');
            }
        });

        // Cancel button collapses form (Questions)
        $(document).on('click', '.cancel-edit-btn', function(e) {
            e.preventDefault();
            $(this).closest('.question-item').find('.edit-question-btn').click();
        });

        // Cancel button collapses form (Outcomes)
        $(document).on('click', '.cancel-edit-outcome-btn', function(e) {
            e.preventDefault();
            $(this).closest('.outcome-item').find('.edit-outcome-btn').click();
        });

        // Arrow icon toggle for questions
        $(document).on('click', '.toggle-question-details', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).closest('.question-header').find('.edit-question-btn').click();
        });

        // Arrow icon toggle for outcomes
        $(document).on('click', '.toggle-outcome-details', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).closest('.outcome-header').find('.edit-outcome-btn').click();
        });

        // Also allow clicking header area (but not buttons) to toggle
        $(document).on('click', '.question-header', function(e) {
            // Only toggle if clicking the header itself, not buttons
            if (!$(e.target).is('button') && !$(e.target).closest('button').length && !$(e.target).hasClass('dashicons')) {
                $(this).find('.edit-question-btn').click();
            }
        });

        $(document).on('click', '.outcome-header', function(e) {
            // Only toggle if clicking the header itself, not buttons
            if (!$(e.target).is('button') && !$(e.target).closest('button').length && !$(e.target).hasClass('dashicons')) {
                $(this).find('.edit-outcome-btn').click();
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
                // Collect selected resource IDs from hidden inputs
                var resourceIds = [];
                $form.find('input[name="specific_resource_ids[]"]').each(function() {
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
        // SEARCHABLE RESOURCE SELECTION
        // ========================================
        // AJAX RESOURCE SEARCH FOR OUTCOMES
        // ========================================

        var searchTimeout = null;
        var currentSearchRequest = null;

        // Helper function to escape HTML
        function escapeHtml(text) {
            var map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
            return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Debounced AJAX search
        $(document).on('input', '.resource-ajax-search-input', function() {
            var $input = $(this);
            var searchTerm = $input.val().trim();
            var $container = $input.closest('.specific-resources-selection');
            var $results = $container.find('.resource-search-results');
            var $list = $results.find('.resource-search-list');
            var $loading = $results.find('.resource-search-loading');
            var $empty = $results.find('.resource-search-empty');

            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            // Hide results if empty search
            if (searchTerm.length === 0) {
                $results.hide();
                // Cancel pending request
                if (currentSearchRequest) {
                    currentSearchRequest.abort();
                    currentSearchRequest = null;
                }
                return;
            }

            // Show results container
            $results.show();
            $loading.show();
            $list.empty();
            $empty.hide();

            // Debounce: wait 300ms after user stops typing
            searchTimeout = setTimeout(function() {
                // Cancel previous request
                if (currentSearchRequest) {
                    currentSearchRequest.abort();
                }

                // Perform AJAX search
                currentSearchRequest = $.ajax({
                    url: questionnaireAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'search_resources_for_outcome',
                        nonce: questionnaireAdmin.nonce,
                        search: searchTerm,
                        limit: 50,
                        offset: 0
                    },
                    success: function(response) {
                        $loading.hide();

                        if (response.success && response.data.resources.length > 0) {
                            renderSearchResults($list, response.data.resources, $container);
                            $empty.hide();
                        } else {
                            $list.empty();
                            $empty.show();
                        }
                        currentSearchRequest = null;
                    },
                    error: function(xhr) {
                        if (xhr.statusText !== 'abort') {
                            $loading.hide();
                            $empty.html('<span style="color: #d63638;">Error loading results. Please try again.</span>').show();
                        }
                        currentSearchRequest = null;
                    }
                });
            }, 300); // 300ms debounce
        });

        // Render search results
        function renderSearchResults($list, resources, $container) {
            $list.empty();

            // Get already selected resource IDs
            var selectedIds = [];
            $container.find('.selected-resources-list input[name="specific_resource_ids[]"]').each(function() {
                selectedIds.push(parseInt($(this).val()));
            });

            resources.forEach(function(resource) {
                var isSelected = selectedIds.indexOf(resource.id) !== -1;
                var $item = $('<div>')
                    .addClass('resource-search-result-item')
                    .attr('data-resource-id', resource.id)
                    .css({
                        padding: '12px',
                        cursor: isSelected ? 'default' : 'pointer',
                        borderBottom: '1px solid #e0e0e0',
                        background: isSelected ? '#f0f0f0' : '#fff',
                        opacity: isSelected ? '0.6' : '1',
                        transition: 'background-color 0.2s'
                    })
                    .html(
                        '<div style="font-weight: 600; color: ' + (isSelected ? '#666' : '#0073aa') + '; margin-bottom: 4px;">' +
                            escapeHtml(resource.resource_name) +
                            (isSelected ? ' <span style="color: #00a32a; font-size: 0.9em;">(Selected)</span>' : '') +
                        '</div>' +
                        (resource.organization ? '<div style="font-size: 0.9em; color: #555; margin-bottom: 3px;">' + escapeHtml(resource.organization) + '</div>' : '') +
                        '<div style="font-size: 0.85em; color: #666;">' + escapeHtml(resource.primary_service_type || '') + '</div>'
                    );

                if (!isSelected) {
                    $item.hover(
                        function() { $(this).css('background', '#f5f5f5'); },
                        function() { $(this).css('background', '#fff'); }
                    );

                    $item.on('click', function() {
                        addSelectedResource(resource, $container);
                        $(this).css({background: '#f0f0f0', opacity: '0.6', cursor: 'default'})
                              .find('div:first')
                              .append(' <span style="color: #00a32a; font-size: 0.9em;">(Selected)</span>');
                    });
                }

                $list.append($item);
            });
        }

        // Add resource to selected list
        function addSelectedResource(resource, $container) {
            var $selectedList = $container.find('.selected-resources-list');
            var $count = $container.find('.selected-count');

            // Remove "no resources" message if exists
            $selectedList.find('p').remove();

            // Create selected item
            var $item = $('<div>')
                .addClass('selected-resource-item')
                .attr('data-resource-id', resource.id)
                .css({
                    padding: '10px',
                    marginBottom: '8px',
                    border: '1px solid #e0e0e0',
                    borderRadius: '3px',
                    background: '#f9f9f9',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center'
                })
                .html(
                    '<div style="flex: 1;">' +
                        '<div style="font-weight: 600; color: #0073aa;">' + escapeHtml(resource.resource_name) + '</div>' +
                        (resource.organization ? '<div style="font-size: 0.9em; color: #555;">' + escapeHtml(resource.organization) + '</div>' : '') +
                        '<div style="font-size: 0.85em; color: #666;">' + escapeHtml(resource.primary_service_type || '') + '</div>' +
                    '</div>' +
                    '<button type="button" class="button button-small remove-resource-btn" data-resource-id="' + resource.id + '" style="color: #b32d2e;">Remove</button>' +
                    '<input type="hidden" name="specific_resource_ids[]" value="' + resource.id + '">'
                );

            $selectedList.append($item);

            // Update count
            var newCount = parseInt($count.text()) + 1;
            $count.text(newCount);

            // Show animation
            $item.hide().slideDown(200);
        }

        // Remove resource from selected list
        $(document).on('click', '.remove-resource-btn', function() {
            var $item = $(this).closest('.selected-resource-item');
            var $container = $(this).closest('.specific-resources-selection');
            var $count = $container.find('.selected-count');

            $item.slideUp(200, function() {
                $(this).remove();

                // Update count
                var newCount = parseInt($count.text()) - 1;
                $count.text(newCount);

                // Show "no resources" message if list is empty
                var $selectedList = $container.find('.selected-resources-list');
                if ($selectedList.find('.selected-resource-item').length === 0) {
                    $selectedList.html(
                        '<p style="color: #666; margin: 40px 0; text-align: center;">' +
                        'No resources selected. Use the search above to add resources.' +
                        '</p>'
                    );
                }
            });
        });

        // Clear search when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.resource-ajax-search-input, .resource-search-results').length) {
                $('.resource-search-results').hide();
                $('.resource-ajax-search-input').val('');
            }
        });
    });

})(jQuery);
