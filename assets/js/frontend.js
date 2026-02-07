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
// Open Email List Modal
function openEmailModal() {
    const modal = document.getElementById('emailListModal');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close Email List Modal
function closeEmailModal() {
    const modal = document.getElementById('emailListModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';

    // Reset form
    document.getElementById('emailListForm').reset();
    document.getElementById('emailFormMessage').innerHTML = '';
}

// Send Email List
function sendEmailList(format) {
    const emailInput = document.getElementById('recipient_email');
    const email = emailInput.value.trim();
    const messageDiv = document.getElementById('emailFormMessage');

    if (!email) {
        // Simple validation
        messageDiv.html = '<div class="error-message">Please enter a valid email address.</div>'; // Wait, this is raw JS usually, but let's stick to jQuery for consistency if possible, or just raw JS.
        // Actually the file mixes jQuery and Vanilla. Let's stick to jQuery inside the function for consistency with other parts or raw JS.
        // The existing file uses `document.getElementById` a lot. Let's use vanilla JS for DOM manipulation where easy.
        messageDiv.innerHTML = '<div class="error-message" style="color: #dc3232; margin-bottom: 10px;">Please enter a valid email address.</div>';
        emailInput.focus();
        return;
    }

    if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        messageDiv.innerHTML = '<div class="error-message" style="color: #dc3232; margin-bottom: 10px;">Please enter a valid email format.</div>';
        emailInput.focus();
        return;
    }

    // lock buttons
    const buttons = document.querySelectorAll('#emailListForm button');
    buttons.forEach(btn => {
        btn.disabled = true;
        if(btn.textContent.includes('Send')) {
            btn.dataset.originalText = btn.textContent;
            btn.textContent = 'Sending...';
        }
    });

    messageDiv.innerHTML = '';

    // Collect visible resource IDs
    const resourceIds = [];
    const visibleCards = document.querySelectorAll('.resource-card'); // All cards
    
    visibleCards.forEach(card => {
        // We only want cards that are currently displayed (not hidden by filters)
        if (card.style.display !== 'none') {
            const id = card.getAttribute('data-resource-id');
            if (id) {
                resourceIds.push(id);
            }
        }
    });

    if (resourceIds.length === 0) {
        messageDiv.innerHTML = '<div class="error-message" style="color: #dc3232;">No resources are currently visible to email. Please adjust your filters.</div>';
        buttons.forEach(btn => {
            btn.disabled = false;
            if(btn.dataset.originalText) btn.textContent = btn.dataset.originalText;
        });
        return;
    }

    // Use jQuery for the AJAX call to be consistent with the rest of the file
    jQuery.ajax({
        url: mondayResources.ajaxurl,
        type: 'POST',
        data: {
            action: 'monday_resources_email_list',
            nonce: mondayResources.email_nonce,
            email: email,
            format: format,
            resource_ids: resourceIds
        },
        success: function(response) {
            if (response.success) {
                messageDiv.innerHTML = '<div class="success-message" style="color: #155724; background-color: #d4edda; border-color: #c3e6cb; padding: 10px; margin-bottom: 10px; border-radius: 4px;">' + response.data.message + '</div>';
                document.getElementById('emailListForm').reset();
                
                // Close modal after success
                setTimeout(function() {
                    closeEmailModal();
                    // Reset buttons after closing
                    buttons.forEach(btn => {
                        btn.disabled = false;
                        if(btn.dataset.originalText) btn.textContent = btn.dataset.originalText;
                    });
                }, 2000);
            } else {
                messageDiv.innerHTML = '<div class="error-message" style="color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; padding: 10px; margin-bottom: 10px; border-radius: 4px;">' + (response.data.message || 'Error sending email.') + '</div>';
                buttons.forEach(btn => {
                    btn.disabled = false;
                    if(btn.dataset.originalText) btn.textContent = btn.dataset.originalText;
                });
            }
        },
        error: function() {
            messageDiv.innerHTML = '<div class="error-message" style="color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; padding: 10px; margin-bottom: 10px; border-radius: 4px;">Server error. Please try again later.</div>';
            buttons.forEach(btn => {
                btn.disabled = false;
                if(btn.dataset.originalText) btn.textContent = btn.dataset.originalText;
            });
        }
    });
}

// Close modal when clicking outside
window.onclick = function(event) {
    const reportModal = document.getElementById('reportIssueModal');
    const submitModal = document.getElementById('submitResourceModal');
    const emailModal = document.getElementById('emailListModal');

    if (event.target === reportModal) {
        closeReportModal();
    }
    if (event.target === submitModal) {
        closeSubmitResourceModal();
    }
    if (event.target === emailModal) {
        closeEmailModal();
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
