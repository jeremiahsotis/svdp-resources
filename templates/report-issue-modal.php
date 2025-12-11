<!-- Report Issue Modal -->
<div id="reportIssueModal" class="monday-modal">
    <div class="monday-modal-content">
        <span class="monday-modal-close" onclick="closeReportModal()">&times;</span>
        <h2>Report an Issue</h2>
        <p>Let us know if there's a problem with this resource listing.</p>

        <form id="reportIssueForm">
            <input type="hidden" id="report_resource_name" name="resource_name">
            <input type="hidden" id="report_resource_index" name="resource_index">

            <div class="form-group">
                <label for="issue_type">Type of Issue: <span class="required">*</span></label>
                <select id="issue_type" name="issue_type" required>
                    <option value="">Select an issue type...</option>
                    <option value="Incorrect Information">Incorrect Information</option>
                    <option value="Outdated Information">Outdated Information</option>
                    <option value="Phone Number Wrong">Phone Number Wrong</option>
                    <option value="Website/Link Broken">Website/Link Broken</option>
                    <option value="No Longer Exists">Resource No Longer Exists</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label for="issue_description">Please describe the issue: <span class="required">*</span></label>
                <textarea id="issue_description" name="issue_description" rows="4" required></textarea>
            </div>

            <div class="form-group">
                <label for="reporter_name">Your Name (optional):</label>
                <input type="text" id="reporter_name" name="reporter_name">
            </div>

            <div class="form-group">
                <label for="reporter_email">Your Email (optional):</label>
                <input type="email" id="reporter_email" name="reporter_email">
                <p class="description">We'll only use this to follow up if needed.</p>
            </div>

            <div class="form-actions">
                <button type="button" class="button-secondary" onclick="closeReportModal()">Cancel</button>
                <button type="submit" class="button-primary">Submit Report</button>
            </div>

            <div id="reportFormMessage" class="form-message"></div>
        </form>
    </div>
</div>
