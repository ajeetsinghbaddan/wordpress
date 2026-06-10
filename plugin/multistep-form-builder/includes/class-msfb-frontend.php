<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSFB_Frontend
{
    public function __construct()
    {
        add_shortcode('msfb_form', [$this, 'render_form']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_ajax_msfb_submit', [$this, 'handle_submit']);
        add_action('wp_ajax_nopriv_msfb_submit', [$this, 'handle_submit']);
    }

    public function register_assets()
    {
        wp_register_style('msfb-frontend', MSFB_URL . 'assets/frontend.css', [], MSFB_VERSION);
        wp_register_script('msfb-frontend', MSFB_URL . 'assets/frontend.js', [], MSFB_VERSION, [
            'strategy'  => 'defer',
            'in_footer' => true,
        ]);
        wp_localize_script('msfb-frontend', 'msfbAjax', [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('msfb_submit'),
        ]);
    }

    public function render_form($atts)
    {
        $atts = shortcode_atts(['id' => 0], $atts, 'msfb_form');
        $form = MSFB_DB::get_form(absint($atts['id']));

        if (!$form || empty($form->structure)) {
            return current_user_can('manage_options') ? '<p>Multistep Form: form not found or empty.</p>' : '';
        }

        if (apply_filters('msfb_load_styles', true)) {
            wp_enqueue_style('msfb-frontend');
        }
        wp_enqueue_script('msfb-frontend');

        $total_steps = count($form->structure);

        ob_start();
        ?>
        <div class="msfb-form-wrap" data-form-id="<?php echo esc_attr($form->id); ?>">
            <?php if (apply_filters('msfb_show_title', true, $form)) : ?>
                <h3 class="msfb-form-title"><?php echo esc_html($form->title); ?></h3>
            <?php endif; ?>

            <?php if ($total_steps > 1) : ?>
                <div class="msfb-progress" role="list" aria-label="Form steps">
                    <?php foreach ($form->structure as $i => $step) : ?>
                        <span class="msfb-progress-dot <?php echo $i === 0 ? 'active' : ''; ?>" role="listitem">
                            <?php echo esc_html($i + 1); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="msfb-form" novalidate>
                <?php foreach ($form->structure as $i => $step) : ?>
                    <fieldset class="msfb-step <?php echo $i === 0 ? 'active' : ''; ?>" data-step="<?php echo esc_attr($i); ?>">
                        <?php if (!empty($step['step_title'])) : ?>
                            <legend><?php echo esc_html($step['step_title']); ?></legend>
                        <?php endif; ?>

                        <?php foreach ($step['fields'] as $j => $field) :
                            $name = 'field_' . $i . '_' . $j;
                            $required = !empty($field['required']);
                            ?>
                            <div class="msfb-field">
                                <label for="<?php echo esc_attr($name); ?>">
                                    <?php echo esc_html($field['label']); ?>
                                    <?php if ($required) : ?><span class="msfb-required" aria-hidden="true">*</span><?php endif; ?>
                                </label>
                                <?php $this->render_input($field, $name, $required); ?>
                                <span class="msfb-error" role="alert"></span>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>

                <p class="msfb-hp" aria-hidden="true">
                    <label>Leave this field empty
                        <input type="text" name="msfb_hp" value="" tabindex="-1" autocomplete="off">
                    </label>
                </p>

                <div class="msfb-nav">
                    <button type="button" class="msfb-prev wp-element-button" style="display:none;">Previous</button>
                    <button type="button" class="msfb-next wp-element-button" <?php echo $total_steps === 1 ? 'style="display:none;"' : ''; ?>>Next</button>
                    <button type="submit" class="msfb-submit wp-element-button" <?php echo $total_steps > 1 ? 'style="display:none;"' : ''; ?>>Submit</button>
                </div>

                <div class="msfb-message" role="status"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_input($field, $name, $required)
    {
        $req = $required ? 'data-required="1"' : '';
        $type = $field['type'];
        $options = array_filter(array_map('trim', explode(',', $field['options'] ?? '')));

        switch ($type) {
            case 'textarea':
                printf('<textarea id="%1$s" name="%1$s" rows="4" %2$s></textarea>', esc_attr($name), $req);
                break;

            case 'select':
                printf('<select id="%1$s" name="%1$s" %2$s><option value="">— Select —</option>', esc_attr($name), $req);
                foreach ($options as $opt) {
                    printf('<option value="%s">%s</option>', esc_attr($opt), esc_html($opt));
                }
                echo '</select>';
                break;

            case 'radio':
            case 'checkbox':
                $input_type = $type === 'radio' ? 'radio' : 'checkbox';
                echo '<div class="msfb-choice-group" ' . $req . '>';
                foreach ($options as $opt) {
                    printf(
                        '<label class="msfb-choice"><input type="%s" name="%s" value="%s"> %s</label>',
                        esc_attr($input_type),
                        esc_attr($name),
                        esc_attr($opt),
                        esc_html($opt)
                    );
                }
                echo '</div>';
                break;

            default:
                printf(
                    '<input type="%s" id="%s" name="%s" %s>',
                    esc_attr(in_array($type, ['text', 'email', 'number', 'date', 'tel'], true) ? $type : 'text'),
                    esc_attr($name),
                    esc_attr($name),
                    $req
                );
        }
    }

    public function handle_submit()
    {
        check_ajax_referer('msfb_submit', 'nonce');

        if (!empty($_POST['msfb_hp'])) {
            wp_send_json_error(['message' => 'Submission rejected.'], 400);
        }

        if (!$this->check_rate_limit()) {
            wp_send_json_error(['message' => 'Too many submissions. Please wait a minute and try again.'], 429);
        }

        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        $form = MSFB_DB::get_form($form_id);

        if (!$form || empty($form->structure)) {
            wp_send_json_error(['message' => 'Invalid form.'], 400);
        }

        $raw = json_decode(wp_unslash($_POST['data'] ?? '{}'), true);
        if (!is_array($raw)) {
            wp_send_json_error(['message' => 'Invalid data.'], 400);
        }

        $entry = [];
        $errors = [];

        foreach ($form->structure as $i => $step) {
            foreach ((array) ($step['fields'] ?? []) as $j => $field) {
                $name = 'field_' . $i . '_' . $j;
                $label = $field['label'];
                $required = !empty($field['required']);
                $options = array_filter(array_map('trim', explode(',', $field['options'] ?? '')));
                $value = $raw[$name] ?? '';

                switch ($field['type']) {
                    case 'checkbox':
                        $value = array_map('sanitize_text_field', array_filter((array) $value, 'is_string'));
                        $value = array_values(array_intersect($value, $options));
                        if ($required && empty($value)) {
                            $errors[$name] = 'This field is required.';
                        }
                        break;

                    case 'radio':
                    case 'select':
                        $value = is_string($value) ? sanitize_text_field($value) : '';
                        if ($value !== '' && !in_array($value, $options, true)) {
                            $errors[$name] = 'Invalid choice.';
                            $value = '';
                        } elseif ($required && $value === '') {
                            $errors[$name] = 'This field is required.';
                        }
                        break;

                    case 'email':
                        $value = is_string($value) ? sanitize_email($value) : '';
                        if ($required && $value === '') {
                            $errors[$name] = 'This field is required.';
                        } elseif ($value !== '' && !is_email($value)) {
                            $errors[$name] = 'Please enter a valid email address.';
                        }
                        break;

                    case 'number':
                        $value = is_scalar($value) ? trim((string) $value) : '';
                        if ($required && $value === '') {
                            $errors[$name] = 'This field is required.';
                        } elseif ($value !== '' && !is_numeric($value)) {
                            $errors[$name] = 'Please enter a valid number.';
                        }
                        break;

                    case 'date':
                        $value = is_string($value) ? trim($value) : '';
                        if ($required && $value === '') {
                            $errors[$name] = 'This field is required.';
                        } elseif ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            $errors[$name] = 'Please enter a valid date.';
                        }
                        break;

                    case 'textarea':
                        $value = is_string($value) ? sanitize_textarea_field($value) : '';
                        $value = mb_substr($value, 0, 5000);
                        if ($required && trim($value) === '') {
                            $errors[$name] = 'This field is required.';
                        }
                        break;

                    default:
                        $value = is_string($value) ? sanitize_text_field($value) : '';
                        $value = mb_substr($value, 0, 1000);
                        if ($required && trim($value) === '') {
                            $errors[$name] = 'This field is required.';
                        }
                }

                $entry[$label] = $value;
            }
        }

        if (!empty($errors)) {
            wp_send_json_error([
                'message' => 'Please correct the highlighted fields.',
                'fields'  => $errors,
            ], 422);
        }

        MSFB_DB::save_entry($form_id, $entry);

        do_action('msfb_entry_saved', $form_id, $entry);

        wp_send_json_success(['message' => apply_filters('msfb_success_message', 'Thank you! Your form has been submitted.', $form_id)]);
    }

    private function check_rate_limit()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'msfb_rl_' . md5($ip . wp_salt('nonce'));
        $count = (int) get_transient($key);

        $limit = (int) apply_filters('msfb_rate_limit', 5);
        if ($count >= $limit) {
            return false;
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }
}
