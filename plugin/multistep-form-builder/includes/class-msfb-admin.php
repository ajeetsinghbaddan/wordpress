<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSFB_Admin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_msfb_save_form', [$this, 'handle_save_form']);
        add_action('admin_post_msfb_delete_form', [$this, 'handle_delete_form']);
        add_action('admin_post_msfb_delete_entry', [$this, 'handle_delete_entry']);
    }

    public function register_menu()
    {
        add_menu_page('Multistep Forms', 'Multistep Forms', 'manage_options', 'msfb-forms', [$this, 'render_forms_page'], 'dashicons-feedback', 26);
        add_submenu_page('msfb-forms', 'All Forms', 'All Forms', 'manage_options', 'msfb-forms', [$this, 'render_forms_page']);
        add_submenu_page('msfb-forms', 'Add New Form', 'Add New', 'manage_options', 'msfb-builder', [$this, 'render_builder_page']);
        add_submenu_page('msfb-forms', 'Entries', 'Entries', 'manage_options', 'msfb-entries', [$this, 'render_entries_page']);
    }

    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'msfb') === false) {
            return;
        }
        wp_enqueue_style('msfb-admin', MSFB_URL . 'assets/admin.css', [], MSFB_VERSION);
        wp_enqueue_script('msfb-admin', MSFB_URL . 'assets/admin.js', ['jquery'], MSFB_VERSION, true);
    }

    public function render_forms_page()
    {
        $forms = MSFB_DB::get_forms();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Multistep Forms</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=msfb-builder')); ?>" class="page-title-action">Add New</a>
            <hr class="wp-header-end">

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Shortcode</th>
                        <th>Steps</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($forms)) : ?>
                    <tr><td colspan="6">No forms yet. Create your first form to get started.</td></tr>
                <?php else : ?>
                    <?php foreach ($forms as $form) :
                        $structure = json_decode($form->structure, true);
                        $delete_url = wp_nonce_url(
                            admin_url('admin-post.php?action=msfb_delete_form&form_id=' . $form->id),
                            'msfb_delete_form_' . $form->id
                        );
                        ?>
                        <tr>
                            <td><?php echo esc_html($form->id); ?></td>
                            <td><strong><?php echo esc_html($form->title); ?></strong></td>
                            <td><code>[msfb_form id="<?php echo esc_attr($form->id); ?>"]</code></td>
                            <td><?php echo count($structure); ?></td>
                            <td><?php echo esc_html($form->created_at); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=msfb-builder&form_id=' . $form->id)); ?>">Edit</a> |
                                <a href="<?php echo esc_url(admin_url('admin.php?page=msfb-entries&form_id=' . $form->id)); ?>">Entries</a> |
                                <a href="<?php echo esc_url($delete_url); ?>" class="msfb-delete" style="color:#b32d2e;">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_builder_page()
    {
        $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
        $form = $form_id ? MSFB_DB::get_form($form_id) : null;
        $title = $form ? $form->title : '';
        $structure = $form ? $form->structure : [];
        ?>
        <div class="wrap">
            <h1><?php echo $form ? 'Edit Form' : 'Add New Form'; ?></h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="msfb-builder-form">
                <?php wp_nonce_field('msfb_save_form', 'msfb_nonce'); ?>
                <input type="hidden" name="action" value="msfb_save_form">
                <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
                <input type="hidden" name="structure" id="msfb-structure" value="">

                <table class="form-table">
                    <tr>
                        <th><label for="msfb-title">Form Title</label></th>
                        <td><input type="text" id="msfb-title" name="title" class="regular-text" value="<?php echo esc_attr($title); ?>" required></td>
                    </tr>
                </table>

                <div id="msfb-steps"></div>

                <p>
                    <button type="button" class="button button-secondary" id="msfb-add-step">+ Add Step</button>
                    <button type="submit" class="button button-primary">Save Form</button>
                </p>
            </form>

            <script type="text/javascript">
                var msfbExistingStructure = <?php echo wp_json_encode($structure); ?>;
            </script>
        </div>
        <?php
    }

    public function render_entries_page()
    {
        $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
        $forms = MSFB_DB::get_forms();
        $entries = MSFB_DB::get_entries($form_id);
        ?>
        <div class="wrap">
            <h1>Form Entries</h1>

            <form method="get" style="margin-bottom:15px;">
                <input type="hidden" name="page" value="msfb-entries">
                <select name="form_id" onchange="this.form.submit()">
                    <option value="0">All forms</option>
                    <?php foreach ($forms as $form) : ?>
                        <option value="<?php echo esc_attr($form->id); ?>" <?php selected($form_id, $form->id); ?>>
                            <?php echo esc_html($form->title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th style="width:120px;">Form</th>
                        <th>Submitted Data</th>
                        <th style="width:160px;">Date</th>
                        <th style="width:80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($entries)) : ?>
                    <tr><td colspan="5">No entries found.</td></tr>
                <?php else : ?>
                    <?php
                    $form_titles = [];
                    foreach ($forms as $form) {
                        $form_titles[$form->id] = $form->title;
                    }
                    foreach ($entries as $entry) :
                        $delete_url = wp_nonce_url(
                            admin_url('admin-post.php?action=msfb_delete_entry&entry_id=' . $entry->id . '&form_id=' . $form_id),
                            'msfb_delete_entry_' . $entry->id
                        );
                        ?>
                        <tr>
                            <td><?php echo esc_html($entry->id); ?></td>
                            <td><?php echo esc_html($form_titles[$entry->form_id] ?? 'Deleted form'); ?></td>
                            <td>
                                <table class="msfb-entry-data">
                                    <?php foreach ((array) $entry->entry_data as $label => $value) : ?>
                                        <tr>
                                            <th><?php echo esc_html($label); ?></th>
                                            <td><?php echo esc_html(is_array($value) ? implode(', ', $value) : $value); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </td>
                            <td><?php echo esc_html($entry->created_at); ?></td>
                            <td><a href="<?php echo esc_url($delete_url); ?>" class="msfb-delete" style="color:#b32d2e;">Delete</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_save_form()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('msfb_save_form', 'msfb_nonce');

        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $raw = wp_unslash($_POST['structure'] ?? '[]');
        $structure = json_decode($raw, true);

        if (!is_array($structure)) {
            $structure = [];
        }

        $clean = [];
        foreach ($structure as $step) {
            $clean_step = [
                'step_title' => sanitize_text_field($step['step_title'] ?? ''),
                'fields'     => [],
            ];
            foreach ((array) ($step['fields'] ?? []) as $field) {
                $clean_step['fields'][] = [
                    'label'    => sanitize_text_field($field['label'] ?? ''),
                    'type'     => sanitize_key($field['type'] ?? 'text'),
                    'required' => !empty($field['required']),
                    'options'  => sanitize_text_field($field['options'] ?? ''),
                ];
            }
            $clean[] = $clean_step;
        }

        $saved_id = MSFB_DB::save_form($title, $clean, $form_id);

        wp_safe_redirect(admin_url('admin.php?page=msfb-builder&form_id=' . $saved_id . '&saved=1'));
        exit;
    }

    public function handle_delete_form()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
        check_admin_referer('msfb_delete_form_' . $form_id);

        MSFB_DB::delete_form($form_id);

        wp_safe_redirect(admin_url('admin.php?page=msfb-forms'));
        exit;
    }

    public function handle_delete_entry()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $entry_id = isset($_GET['entry_id']) ? absint($_GET['entry_id']) : 0;
        $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
        check_admin_referer('msfb_delete_entry_' . $entry_id);

        MSFB_DB::delete_entry($entry_id);

        wp_safe_redirect(admin_url('admin.php?page=msfb-entries&form_id=' . $form_id));
        exit;
    }
}
