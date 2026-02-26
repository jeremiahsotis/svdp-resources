<?php
/**
 * Template: All Questionnaires List Page
 *
 * Variables available:
 * - $questionnaires: Array of questionnaire data
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Questionnaires</h1>
    <a href="<?php echo admin_url('admin.php?page=questionnaires-add'); ?>" class="page-title-action">Add New</a>
    <hr class="wp-header-end">

    <?php if (empty($questionnaires)): ?>
        <div class="notice notice-info">
            <p>No questionnaires found. <a href="<?php echo admin_url('admin.php?page=questionnaires-add'); ?>">Create your first questionnaire</a> to get started!</p>
        </div>
    <?php else: ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="bulk_action_questionnaires">
            <?php wp_nonce_field('bulk_action_questionnaires'); ?>

            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="delete">Delete</option>
                    </select>
                    <input type="submit" class="button action" value="Apply">
                </div>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo count($questionnaires); ?> item<?php echo count($questionnaires) !== 1 ? 's' : ''; ?></span>
                </div>
                <br class="clear">
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" id="cb-select-all">
                        </td>
                        <th class="manage-column">Name</th>
                        <th class="manage-column">Slug</th>
                        <th class="manage-column">Geography</th>
                        <th class="manage-column">Status</th>
                        <th class="manage-column">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($questionnaires as $questionnaire): ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="questionnaire_ids[]" value="<?php echo esc_attr($questionnaire['id']); ?>">
                            </th>
                            <td class="column-primary">
                                <strong>
                                    <a href="<?php echo admin_url('admin.php?page=questionnaires-edit&id=' . $questionnaire['id']); ?>">
                                        <?php echo esc_html($questionnaire['name']); ?>
                                    </a>
                                </strong>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo admin_url('admin.php?page=questionnaires-edit&id=' . $questionnaire['id']); ?>">Edit</a> |
                                    </span>
                                    <span class="duplicate">
                                        <a href="<?php echo admin_url('admin.php?page=questionnaires-add&duplicate=' . $questionnaire['id']); ?>">Duplicate</a> |
                                    </span>
                                    <span class="trash">
                                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=delete_questionnaire&id=' . $questionnaire['id']), 'delete_questionnaire_' . $questionnaire['id']); ?>"
                                           onclick="return confirm('Are you sure you want to delete this questionnaire?');"
                                           style="color: #a00;">Delete</a>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <code><?php echo esc_html($questionnaire['slug']); ?></code>
                            </td>
                            <td>
                                <?php
                                if (!empty($questionnaire['geography'])) {
                                    $geographies = explode(', ', $questionnaire['geography']);
                                    echo esc_html(count($geographies) . ' Conference' . (count($geographies) !== 1 ? 's' : ''));
                                } else {
                                    echo '<span style="color: #999;">All Conferences</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $status_colors = array(
                                    'active' => 'green',
                                    'inactive' => 'orange',
                                );
                                $color = isset($status_colors[$questionnaire['status']]) ? $status_colors[$questionnaire['status']] : 'gray';
                                ?>
                                <span style="color: <?php echo $color; ?>;">●</span>
                                <?php echo esc_html(ucfirst($questionnaire['status'])); ?>
                            </td>
                            <td>
                                <?php
                                if (!empty($questionnaire['created_at'])) {
                                    echo esc_html(date('M j, Y', strtotime($questionnaire['created_at'])));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="tablenav bottom">
                <div class="alignleft actions bulkactions">
                    <select name="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="delete">Delete</option>
                    </select>
                    <input type="submit" class="button action" value="Apply">
                </div>
                <br class="clear">
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // Select all checkbox functionality
    $('#cb-select-all').on('click', function() {
        $('input[name="questionnaire_ids[]"]').prop('checked', this.checked);
    });
});
</script>
