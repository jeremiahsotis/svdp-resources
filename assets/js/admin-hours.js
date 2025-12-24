/**
 * Admin Hours UI JavaScript
 * Handles interactive functionality for the hours of operation form
 */

jQuery(document).ready(function($) {

    // Handle office hours closed checkboxes
    $('.office-closed-checkbox').on('change', function() {
        var day = $(this).data('day');
        var isChecked = $(this).is(':checked');

        // Enable/disable time inputs for this day
        $('input.office-time-input[data-day="' + day + '"]').prop('disabled', isChecked);

        if (isChecked) {
            // Clear values when closing
            $('input.office-time-input[data-day="' + day + '"]').val('');
        }
    });

    // Handle service hours closed checkboxes
    $('.service-closed-checkbox').on('change', function() {
        var day = $(this).data('day');
        var isChecked = $(this).is(':checked');

        // Enable/disable time inputs for this day
        $('input.service-time-input[data-day="' + day + '"]').prop('disabled', isChecked);

        if (isChecked) {
            // Clear values when closing
            $('input.service-time-input[data-day="' + day + '"]').val('');
        }
    });

    // Handle special situation flags
    var specialFlags = ['#hours_24_7', '#hours_by_appointment', '#hours_call_for_availability', '#hours_currently_closed'];

    function updateHoursFieldsState() {
        var anySpecialChecked = false;

        specialFlags.forEach(function(flag) {
            if ($(flag).is(':checked')) {
                anySpecialChecked = true;
            }
        });

        if (anySpecialChecked) {
            // Disable all hours sections
            $('#office_hours_section, #service_hours_section').css('opacity', '0.5');
            $('#office_hours_section input, #service_hours_section input').prop('disabled', true);
            $('#service_same_as_office').prop('disabled', true);
        } else {
            // Enable hours sections
            $('#office_hours_section, #service_hours_section').css('opacity', '1');
            $('#service_same_as_office').prop('disabled', false);

            // Re-enable inputs based on closed state
            for (var day = 0; day <= 6; day++) {
                var officeClosedChecked = $('input.office-closed-checkbox[data-day="' + day + '"]').is(':checked');
                $('input.office-time-input[data-day="' + day + '"]').prop('disabled', officeClosedChecked);

                var serviceClosedChecked = $('input.service-closed-checkbox[data-day="' + day + '"]').is(':checked');
                $('input.service-time-input[data-day="' + day + '"]').prop('disabled', serviceClosedChecked);
            }
        }
    }

    specialFlags.forEach(function(flag) {
        $(flag).on('change', updateHoursFieldsState);
    });

    // Initialize state on page load
    updateHoursFieldsState();

    // Sync office hours to service hours
    function syncOfficeToService() {
        for (var day = 0; day <= 6; day++) {
            var officeClosedChecked = $('input.office-closed-checkbox[data-day="' + day + '"]').is(':checked');
            var $serviceClosedCheckbox = $('input.service-closed-checkbox[data-day="' + day + '"]');

            $serviceClosedCheckbox.prop('checked', officeClosedChecked).trigger('change');

            if (!officeClosedChecked) {
                var officeOpen = $('input.office-time-input[data-day="' + day + '"][name*="open_time"]').val();
                var officeClose = $('input.office-time-input[data-day="' + day + '"][name*="close_time"]').val();

                $('input.service-time-input[data-day="' + day + '"][name*="open_time"]').val(officeOpen);
                $('input.service-time-input[data-day="' + day + '"][name*="close_time"]').val(officeClose);
            }
        }
    }

    // Handle checkbox change
    $('#service_same_as_office').on('change', function() {
        if ($(this).is(':checked')) {
            syncOfficeToService();
        }
    });

    // Continuous sync when office hours change
    $('.office-closed-checkbox, .office-time-input').on('change', function() {
        if ($('#service_same_as_office').is(':checked')) {
            syncOfficeToService();
        }
    });

    // Initialize on page load
    if ($('#service_same_as_office').is(':checked')) {
        syncOfficeToService();
    }

    // Add visual indicator for changed fields
    $('.office-time-input, .service-time-input').on('change', function() {
        $(this).css('background-color', '#ffffcc');
        setTimeout(function(el) {
            $(el).css('background-color', '');
        }, 500, this);
    });

    // Validate time ranges (open must be before close)
    $('.office-time-input, .service-time-input').on('blur', function() {
        var $input = $(this);
        var day = $input.data('day');
        var type = $input.hasClass('office-time-input') ? 'office' : 'service';
        var isOpen = $input.attr('name').indexOf('open_time') !== -1;

        if (!isOpen) {
            // This is a close time, validate it's after open time
            var $openInput = $('.' + type + '-time-input[data-day="' + day + '"][name*="open_time"]');
            var openTime = $openInput.val();
            var closeTime = $input.val();

            if (openTime && closeTime && openTime >= closeTime) {
                alert('Close time must be after open time for ' + getDayName(day));
                $input.val('');
            }
        }
    });

    function getDayName(day) {
        var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return days[day] || 'this day';
    }
});
