<!-- Submit Resource Modal -->
<div id="submitResourceModal" class="monday-modal">
    <div class="monday-modal-content monday-modal-large">
        <span class="monday-modal-close" onclick="closeSubmitResourceModal()">&times;</span>
        <h2>Submit a New Resource</h2>
        <p>Help us expand our resource directory by submitting a new resource.</p>

        <form id="submitResourceForm">
            <div class="form-group">
                <label for="organization_name">Organization/Resource Name: <span class="required">*</span></label>
                <input type="text" id="organization_name" name="organization_name" required>
            </div>

            <div class="form-group">
                <label for="service_type">Primary Service Type: <span class="required">*</span></label>
                <select id="service_type" name="service_type" required>
                    <option value="">Select a service type...</option>
                    <option value="Food Assistance">Food Assistance</option>
                    <option value="Housing/Shelter">Housing/Shelter</option>
                    <option value="Utility Assistance">Utility Assistance</option>
                    <option value="Healthcare">Healthcare</option>
                    <option value="Mental Health">Mental Health</option>
                    <option value="Legal Services">Legal Services</option>
                    <option value="Employment">Employment</option>
                    <option value="Education">Education</option>
                    <option value="Transportation">Transportation</option>
                    <option value="Financial Assistance">Financial Assistance</option>
                    <option value="Clothing">Clothing</option>
                    <option value="Childcare">Childcare</option>
                    <option value="Senior Services">Senior Services</option>
                    <option value="Veteran Services">Veteran Services</option>
                    <option value="Disability Services">Disability Services</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label for="description">What services do they provide? <span class="required">*</span></label>
                <textarea id="description" name="description" rows="4" required></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="contact_name">Contact Person:</label>
                    <input type="text" id="contact_name" name="contact_name">
                </div>

                <div class="form-group">
                    <label for="contact_phone">Phone Number:</label>
                    <input type="tel" id="contact_phone" name="contact_phone">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="contact_email">Email:</label>
                    <input type="email" id="contact_email" name="contact_email">
                </div>

                <div class="form-group">
                    <label for="website">Website:</label>
                    <input type="url" id="website" name="website" placeholder="https://">
                </div>
            </div>

            <div class="form-group">
                <label for="address">Physical Address:</label>
                <textarea id="address" name="address" rows="2"></textarea>
            </div>

            <div class="form-group">
                <label for="counties_served">Counties/Areas Served:</label>
                <input type="text" id="counties_served" name="counties_served" placeholder="e.g., Mecklenburg, Union, Cabarrus">
            </div>

            <div class="form-actions">
                <button type="button" class="button-secondary" onclick="closeSubmitResourceModal()">Cancel</button>
                <button type="submit" class="button-primary">Submit Resource</button>
            </div>

            <div id="submitFormMessage" class="form-message"></div>
        </form>
    </div>
</div>
