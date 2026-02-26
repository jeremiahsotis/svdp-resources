/**
 * Admin Hours UI JavaScript - Full Implementation
 * Handles all interactive functionality for the enhanced hours system
 * Supports: multiple blocks, recurring patterns, separate office/service flags
 */

jQuery(document).ready(function($) {

    // ===== MODE SWITCHING =====

    $('.hours-mode-radio').on('change', function() {
        var $container = $(this).closest('.hours-day-container');
        var mode = $(this).val();

        // Hide all mode containers
        $container.find('.hours-simple-container, .hours-multiple-container, .hours-recurring-container').hide();

        // Show selected mode container
        if (mode === 'simple') {
            $container.find('.hours-simple-container').show();
        } else if (mode === 'multiple') {
            $container.find('.hours-multiple-container').show();
        } else if (mode === 'recurring') {
            $container.find('.hours-recurring-container').show();
        }
        // 'closed' mode shows nothing
    });

    // ===== MULTIPLE BLOCKS: ADD/REMOVE =====

    var blockCounter = {}; // Track block indices per day/type

    $('.add-block-btn').on('click', function() {
        var day = $(this).data('day');
        var type = $(this).data('type');
        var $blocksList = $(this).siblings('.hours-blocks-list');
        var key = type + '_' + day;

        // Initialize counter for this day/type
        if (!blockCounter[key]) {
            blockCounter[key] = $blocksList.find('.hours-block-row').length;
        }

        var index = blockCounter[key]++;
        var blockNum = $blocksList.find('.hours-block-row').length + 1;

        var blockHtml = '<div class="hours-block-row" style="margin-bottom: 8px;">' +
            '<span style="margin-right: 5px;">Block ' + blockNum + ':</span>' +
            '<input type="time" name="' + type + '_hours[' + day + '][blocks][' + index + '][open]" style="margin-right: 5px;">' +
            ' to ' +
            '<input type="time" name="' + type + '_hours[' + day + '][blocks][' + index + '][close]" style="margin: 0 5px;">' +
            '<input type="text" name="' + type + '_hours[' + day + '][blocks][' + index + '][label]" ' +
            'placeholder="Label (optional)" style="width: 120px; margin-right: 5px;">' +
            '<button type="button" class="button remove-block-btn" style="color: #a00;">Remove</button>' +
            '</div>';

        $blocksList.append(blockHtml);
    });

    // Delegate remove button handler
    $(document).on('click', '.remove-block-btn', function() {
        var $blockRow = $(this).closest('.hours-block-row');
        $blockRow.remove();

        // Renumber remaining blocks
        var $blocksList = $blockRow.closest('.hours-blocks-list');
        $blocksList.find('.hours-block-row').each(function(index) {
            $(this).find('span').first().text('Block ' + (index + 1) + ':');
        });
    });

    // ===== RECURRING PATTERN: SHOW/HIDE FIELDS =====

    $('.recurring-pattern-select').on('change', function() {
        var pattern = $(this).val();
        var $container = $(this).closest('.hours-recurring-container');

        $container.find('.recurring-monthly-week-fields, .recurring-monthly-date-fields').hide();

        if (pattern === 'monthly_week') {
            $container.find('.recurring-monthly-week-fields').show();
        } else if (pattern === 'monthly_date') {
            $container.find('.recurring-monthly-date-fields').show();
        }
    });

    // ===== SPECIAL FLAGS: DISABLE/ENABLE HOURS SECTIONS =====

    var officeSpecialFlags = [
        'input[name="office_flags[is_24_7]"]',
        'input[name="office_flags[is_by_appointment]"]',
        'input[name="office_flags[is_call_for_availability]"]',
        'input[name="office_flags[is_currently_closed]"]'
    ];

    var serviceSpecialFlags = [
        'input[name="service_flags[is_24_7]"]',
        'input[name="service_flags[is_by_appointment]"]',
        'input[name="service_flags[is_call_for_availability]"]',
        'input[name="service_flags[is_currently_closed]"]'
    ];

    function updateOfficeHoursFieldsState() {
        var anyOfficeSpecialChecked = officeSpecialFlags.some(function(flag) {
            return $(flag).is(':checked');
        });

        if (anyOfficeSpecialChecked) {
            $('#office_hours_section .hours-day-container').css('opacity', '0.5');
            $('#office_hours_section input, #office_hours_section select, #office_hours_section button').prop('disabled', true);
        } else {
            $('#office_hours_section .hours-day-container').css('opacity', '1');
            $('#office_hours_section input, #office_hours_section select, #office_hours_section button').prop('disabled', false);
        }
    }

    function updateServiceHoursFieldsState() {
        var anyServiceSpecialChecked = serviceSpecialFlags.some(function(flag) {
            return $(flag).is(':checked');
        });

        if (anyServiceSpecialChecked) {
            $('#service_hours_section .hours-day-container').css('opacity', '0.5');
            $('#service_hours_section .hours-day-container input, #service_hours_section .hours-day-container select, #service_hours_section .hours-day-container button').prop('disabled', true);
        } else {
            $('#service_hours_section .hours-day-container').css('opacity', '1');
            $('#service_hours_section .hours-day-container input, #service_hours_section .hours-day-container select, #service_hours_section .hours-day-container button').prop('disabled', false);
        }
    }

    officeSpecialFlags.forEach(function(flag) {
        $(flag).on('change', updateOfficeHoursFieldsState);
    });

    serviceSpecialFlags.forEach(function(flag) {
        $(flag).on('change', updateServiceHoursFieldsState);
    });

    // Initialize on load
    updateOfficeHoursFieldsState();
    updateServiceHoursFieldsState();

    // ===== "SAME AS OFFICE HOURS" SYNC =====

    function syncOfficeToService() {
        // 1. Sync special flags
        officeSpecialFlags.forEach(function(officeFlag, index) {
            var isChecked = $(officeFlag).is(':checked');
            $(serviceSpecialFlags[index]).prop('checked', isChecked);
        });

        // 2. Sync special notes
        var officeNotes = $('textarea[name="office_flags[special_notes]"]').val();
        $('textarea[name="service_flags[special_notes]"]').val(officeNotes);

        // 3. Sync hours for each day
        for (var day = 0; day <= 6; day++) {
            var $officeContainer = $('.hours-day-container[data-day="' + day + '"][data-type="office"]');
            var $serviceContainer = $('.hours-day-container[data-day="' + day + '"][data-type="service"]');

            // Get office mode
            var officeMode = $officeContainer.find('input[name="office_hours[' + day + '][mode]"]:checked').val();

            // Set service mode
            $serviceContainer.find('input[name="service_hours[' + day + '][mode]"][value="' + officeMode + '"]')
                .prop('checked', true).trigger('change');

            // Copy mode-specific data
            if (officeMode === 'simple') {
                var officeOpen = $officeContainer.find('input[name="office_hours[' + day + '][simple][open]"]').val();
                var officeClose = $officeContainer.find('input[name="office_hours[' + day + '][simple][close]"]').val();

                $serviceContainer.find('input[name="service_hours[' + day + '][simple][open]"]').val(officeOpen);
                $serviceContainer.find('input[name="service_hours[' + day + '][simple][close]"]').val(officeClose);

            } else if (officeMode === 'multiple') {
                // Clone all blocks
                var $officeBlocks = $officeContainer.find('.hours-blocks-list');
                var $serviceBlocks = $serviceContainer.find('.hours-blocks-list');

                // Clear existing service blocks
                $serviceBlocks.empty();

                // Copy each office block
                $officeBlocks.find('.hours-block-row').each(function(index) {
                    var open = $(this).find('input[type="time"]').first().val();
                    var close = $(this).find('input[type="time"]').last().val();
                    var label = $(this).find('input[type="text"]').val();

                    var blockHtml = '<div class="hours-block-row" style="margin-bottom: 8px;">' +
                        '<span style="margin-right: 5px;">Block ' + (index + 1) + ':</span>' +
                        '<input type="time" name="service_hours[' + day + '][blocks][' + index + '][open]" value="' + open + '" style="margin-right: 5px;">' +
                        ' to ' +
                        '<input type="time" name="service_hours[' + day + '][blocks][' + index + '][close]" value="' + close + '" style="margin: 0 5px;">' +
                        '<input type="text" name="service_hours[' + day + '][blocks][' + index + '][label]" value="' + label + '" ' +
                        'placeholder="Label (optional)" style="width: 120px; margin-right: 5px;">' +
                        '<button type="button" class="button remove-block-btn" style="color: #a00;">Remove</button>' +
                        '</div>';

                    $serviceBlocks.append(blockHtml);
                });

            } else if (officeMode === 'recurring') {
                // Copy recurring pattern settings
                var officePattern = $officeContainer.find('select[name="office_hours[' + day + '][recurring][pattern]"]').val();
                var officeWeek = $officeContainer.find('select[name="office_hours[' + day + '][recurring][week]"]').val();
                var officeDay = $officeContainer.find('input[name="office_hours[' + day + '][recurring][day_of_month]"]').val();
                var officeOpen = $officeContainer.find('input[name="office_hours[' + day + '][recurring][open]"]').val();
                var officeClose = $officeContainer.find('input[name="office_hours[' + day + '][recurring][close]"]').val();

                $serviceContainer.find('select[name="service_hours[' + day + '][recurring][pattern]"]').val(officePattern).trigger('change');
                $serviceContainer.find('select[name="service_hours[' + day + '][recurring][week]"]').val(officeWeek);
                $serviceContainer.find('input[name="service_hours[' + day + '][recurring][day_of_month]"]').val(officeDay);
                $serviceContainer.find('input[name="service_hours[' + day + '][recurring][open]"]').val(officeOpen);
                $serviceContainer.find('input[name="service_hours[' + day + '][recurring][close]"]').val(officeClose);
            }
        }

        // Update service hours field state
        updateServiceHoursFieldsState();
    }

    // Handle sync checkbox change
    $('#service_same_as_office').on('change', function() {
        if ($(this).is(':checked')) {
            syncOfficeToService();
        }
    });

    // Continuous sync when office hours change
    $('#office_hours_section').on('change input', 'input, select', function() {
        if ($('#service_same_as_office').is(':checked')) {
            syncOfficeToService();
        }
    });

    // Sync when office flags or notes change
    officeSpecialFlags.forEach(function(flag) {
        $(flag).on('change', function() {
            if ($('#service_same_as_office').is(':checked')) {
                syncOfficeToService();
            }
        });
    });

    $('textarea[name="office_flags[special_notes]"]').on('input', function() {
        if ($('#service_same_as_office').is(':checked')) {
            syncOfficeToService();
        }
    });

    // Initialize sync on page load
    if ($('#service_same_as_office').is(':checked')) {
        syncOfficeToService();
    }

    // ===== VALIDATION =====

    // Validate time ranges (open must be before close)
    $(document).on('blur', 'input[type="time"]', function() {
        var $input = $(this);
        var name = $input.attr('name');

        // Check if this is a close time
        if (name && (name.indexOf('[close]') !== -1 || name.indexOf('][close]') !== -1)) {
            var openName = name.replace('[close]', '[open]').replace('][close]', '][open]');
            var $openInput = $('input[name="' + openName + '"]');

            if ($openInput.length) {
                var openTime = $openInput.val();
                var closeTime = $input.val();

                if (openTime && closeTime && openTime >= closeTime) {
                    alert('Close time must be after open time!');
                    $input.val('');
                }
            }
        }
    });

    // Add visual indicator for changed fields
    $('input[type="time"], input[type="text"], input[type="number"]').on('change', function() {
        $(this).css('background-color', '#ffffcc');
        setTimeout(function(el) {
            $(el).css('background-color', '');
        }, 500, this);
    });
});
