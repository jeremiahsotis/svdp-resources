/**
 * Frontend JavaScript for Monday Resources Plugin
 */

// Open Report Issue Modal
function openReportModal(resourceName, resourceIndex) {
    const modal = document.getElementById('reportIssueModal');
    document.getElementById('report_resource_name').value = resourceName;
    document.getElementById('report_resource_index').value = resourceIndex;

    // Update modal title to show resource name
    const modalTitle = modal.querySelector('h2');
    modalTitle.textContent = 'Report an Issue: ' + resourceName;

    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close Report Issue Modal
function closeReportModal() {
    const modal = document.getElementById('reportIssueModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';

    // Reset form
    document.getElementById('reportIssueForm').reset();
    document.getElementById('reportFormMessage').innerHTML = '';
}

// Open Submit Resource Modal
function openSubmitResourceModal() {
    const modal = document.getElementById('submitResourceModal');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close Submit Resource Modal
function closeSubmitResourceModal() {
    const modal = document.getElementById('submitResourceModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';

    // Reset form
    document.getElementById('submitResourceForm').reset();
    document.getElementById('submitFormMessage').innerHTML = '';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const reportModal = document.getElementById('reportIssueModal');
    const submitModal = document.getElementById('submitResourceModal');

    if (event.target === reportModal) {
        closeReportModal();
    }
    if (event.target === submitModal) {
        closeSubmitResourceModal();
    }
}

// Handle Report Issue Form Submission
jQuery(document).ready(function($) {
    $('#reportIssueForm').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const messageDiv = $('#reportFormMessage');
        const submitBtn = form.find('button[type="submit"]');

        // Disable submit button
        submitBtn.prop('disabled', true).text('Submitting...');
        messageDiv.html('');

        $.ajax({
            url: mondayResources.ajaxurl,
            type: 'POST',
            data: {
                action: 'submit_resource_issue',
                nonce: mondayResources.nonce,
                resource_name: $('#report_resource_name').val(),
                resource_index: $('#report_resource_index').val(),
                issue_type: $('#issue_type').val(),
                issue_description: $('#issue_description').val(),
                reporter_name: $('#reporter_name').val(),
                reporter_email: $('#reporter_email').val()
            },
            success: function(response) {
                if (response.success) {
                    messageDiv.html('<div class="success-message">' + response.data.message + '</div>');
                    form[0].reset();

                    // Close modal after 2 seconds
                    setTimeout(function() {
                        closeReportModal();
                    }, 2000);
                } else {
                    messageDiv.html('<div class="error-message">' + response.data.message + '</div>');
                }
            },
            error: function() {
                messageDiv.html('<div class="error-message">An error occurred. Please try again.</div>');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text('Submit Report');
            }
        });
    });

    // Handle Submit Resource Form Submission
    $('#submitResourceForm').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const messageDiv = $('#submitFormMessage');
        const submitBtn = form.find('button[type="submit"]');

        // Disable submit button
        submitBtn.prop('disabled', true).text('Submitting...');
        messageDiv.html('');

        $.ajax({
            url: mondayResources.ajaxurl,
            type: 'POST',
            data: {
                action: 'submit_new_resource',
                nonce: mondayResources.nonce,
                organization_name: $('#organization_name').val(),
                contact_name: $('#contact_name').val(),
                contact_email: $('#contact_email').val(),
                contact_phone: $('#contact_phone').val(),
                website: $('#website').val(),
                service_type: $('#service_type').val(),
                description: $('#description').val(),
                address: $('#address').val(),
                counties_served: $('#counties_served').val()
            },
            success: function(response) {
                if (response.success) {
                    messageDiv.html('<div class="success-message">' + response.data.message + '</div>');
                    form[0].reset();

                    // Close modal after 2 seconds
                    setTimeout(function() {
                        closeSubmitResourceModal();
                    }, 2000);
                } else {
                    messageDiv.html('<div class="error-message">' + response.data.message + '</div>');
                }
            },
            error: function() {
                messageDiv.html('<div class="error-message">An error occurred. Please try again.</div>');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text('Submit Resource');
            }
        });
    });
});
