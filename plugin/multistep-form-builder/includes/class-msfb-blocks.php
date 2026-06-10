<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSFB_Blocks
{
    public function __construct()
    {
        add_action('init', [$this, 'register_block']);
        add_action('enqueue_block_editor_assets', [$this, 'editor_data']);
    }

    public function register_block()
    {
        wp_register_script(
            'msfb-block',
            MSFB_URL . 'assets/block.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'],
            MSFB_VERSION,
            true
        );

        register_block_type('msfb/form', [
            'api_version'     => 2,
            'editor_script'   => 'msfb-block',
            'attributes'      => [
                'formId' => [
                    'type'    => 'number',
                    'default' => 0,
                ],
            ],
            'render_callback' => [$this, 'render_block'],
        ]);
    }

    public function editor_data()
    {
        $forms = MSFB_DB::get_forms();
        $data = [];
        foreach ($forms as $form) {
            $data[] = [
                'id'    => (int) $form->id,
                'title' => $form->title,
            ];
        }
        wp_localize_script('msfb-block', 'msfbBlockData', ['forms' => $data]);
    }

    public function render_block($attributes)
    {
        $form_id = isset($attributes['formId']) ? absint($attributes['formId']) : 0;
        if (!$form_id) {
            return '';
        }
        return do_shortcode('[msfb_form id="' . $form_id . '"]');
    }
}
