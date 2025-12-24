<?php
/**
 * Template: Add/Edit Questionnaire Form
 *
 * Variables available:
 * - $questionnaire: Array of questionnaire data (if editing)
 * - $is_edit: Boolean - true if editing, false if adding new
 * - $has_data: Boolean - true if form should be pre-filled
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get Conference options
$conference_options = Location_Service::get_all_conferences();
$selected_geographies = array();
if ($has_data && !empty($questionnaire['geography'])) {
    $selected_geographies = array_map('trim', explode(',', $questionnaire['geography']));
}
?>

<div class="wrap">
    <h1><?php echo $is_edit ? 'Edit Questionnaire' : 'Add New Questionnaire'; ?></h1>

    <?php if (isset($_GET['error']) && $_GET['error'] == '1'): ?>
        <div class="notice notice-error is-dismissible">
            <p>Failed to save questionnaire. Please try again.</p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="save_questionnaire">

        <?php if ($is_edit): ?>
            <input type="hidden" name="questionnaire_id" value="<?php echo esc_attr($questionnaire['id']); ?>">
            <?php wp_nonce_field('save_questionnaire_' . $questionnaire['id']); ?>
        <?php else: ?>
            <?php wp_nonce_field('save_questionnaire_new'); ?>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="name">Questionnaire Name <span class="description">(required)</span></label>
                    </th>
                    <td>
                        <input type="text"
                               name="name"
                               id="name"
                               class="regular-text"
                               value="<?php echo $has_data ? esc_attr($questionnaire['name']) : ''; ?>"
                               required>
                        <p class="description">
                            Example: "Eviction Help", "Employment Barriers", "Childcare Assistance"
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="slug">Slug</label>
                    </th>
                    <td>
                        <input type="text"
                               name="slug"
                               id="slug"
                               class="regular-text"
                               value="<?php echo $has_data ? esc_attr($questionnaire['slug']) : ''; ?>"
                               pattern="[a-z0-9-]+"
                               placeholder="auto-generated from name">
                        <p class="description">
                            URL-friendly version of the name. Letters, numbers, and hyphens only. Leave blank to auto-generate.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="description">Description</label>
                    </th>
                    <td>
                        <textarea name="description"
                                  id="description"
                                  class="large-text"
                                  rows="4"><?php echo $has_data ? esc_textarea($questionnaire['description']) : ''; ?></textarea>
                        <p class="description">
                            Optional description for admin reference. Not shown to end users.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label>Conference Geography</label>
                    </th>
                    <td>
                        <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                            <?php foreach ($conference_options as $conference): ?>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox"
                                           name="geography[]"
                                           value="<?php echo esc_attr($conference); ?>"
                                           <?php checked(in_array($conference, $selected_geographies)); ?>>
                                    <?php echo esc_html($conference); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">
                            Select which Conference(s) this questionnaire is available for. Leave all unchecked for "All Conferences".
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="status">Status</label>
                    </th>
                    <td>
                        <select name="status" id="status">
                            <option value="active" <?php selected($has_data ? $questionnaire['status'] : 'active', 'active'); ?>>Active</option>
                            <option value="inactive" <?php selected($has_data ? $questionnaire['status'] : '', 'inactive'); ?>>Inactive</option>
                        </select>
                        <p class="description">
                            Inactive questionnaires are not available for use but are not deleted.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sort_order">Sort Order</label>
                    </th>
                    <td>
                        <input type="number"
                               name="sort_order"
                               id="sort_order"
                               class="small-text"
                               value="<?php echo $has_data ? esc_attr($questionnaire['sort_order']) : '0'; ?>"
                               min="0"
                               step="1">
                        <p class="description">
                            Lower numbers appear first. Use 0 for default ordering.
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php if ($is_edit): ?>
            <div style="background: #f0f0f1; padding: 15px; margin: 20px 0; border-left: 4px solid #2271b1;">
                <p style="margin: 0 0 15px 0;">
                    <strong>Next Step:</strong> Add questions and outcomes to build the questionnaire flow.
                </p>
                <a href="<?php echo admin_url('admin.php?page=questionnaires-builder&id=' . $questionnaire['id']); ?>"
                   class="button button-primary button-large">
                    <span class="dashicons dashicons-format-chat" style="margin-top: 3px;"></span>
                    Manage Questions & Outcomes
                </a>
            </div>
        <?php endif; ?>

        <p class="submit">
            <input type="submit"
                   name="submit"
                   id="submit"
                   class="button button-primary"
                   value="<?php echo $is_edit ? 'Update Questionnaire' : 'Create Questionnaire'; ?>">

            <input type="submit"
                   name="save_and_new"
                   id="save_and_new"
                   class="button button-secondary"
                   value="<?php echo $is_edit ? 'Save & Add Another' : 'Create & Add Another'; ?>"
                   style="margin-left: 10px;">

            <a href="<?php echo admin_url('admin.php?page=questionnaires'); ?>"
               class="button"
               style="margin-left: 10px;">Cancel</a>
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Auto-generate slug from name if slug is empty
    $('#name').on('blur', function() {
        var name = $(this).val();
        var slug = $('#slug').val();

        if (name && !slug) {
            var autoSlug = name
                .toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .trim();
            $('#slug').val(autoSlug);
        }
    });
});
</script>
