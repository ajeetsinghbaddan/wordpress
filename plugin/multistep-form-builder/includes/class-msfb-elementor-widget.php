<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSFB_Elementor_Widget extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'msfb_form';
    }

    public function get_title()
    {
        return 'Multistep Form';
    }

    public function get_icon()
    {
        return 'eicon-form-horizontal';
    }

    public function get_categories()
    {
        return ['general'];
    }

    public function get_keywords()
    {
        return ['form', 'multistep', 'msfb'];
    }

    protected function register_controls()
    {
        $options = [0 => '— Select a form —'];
        foreach (MSFB_DB::get_forms() as $form) {
            $options[(int) $form->id] = $form->title . ' (#' . $form->id . ')';
        }

        $this->start_controls_section('msfb_section', [
            'label' => 'Form',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('form_id', [
            'label'   => 'Choose Form',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => $options,
            'default' => 0,
        ]);

        $this->end_controls_section();
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $form_id = absint($settings['form_id'] ?? 0);

        if (!$form_id) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<p>Select a form from the widget settings.</p>';
            }
            return;
        }

        echo do_shortcode('[msfb_form id="' . $form_id . '"]');
    }
}
