<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSFB_Elementor
{
    public function __construct()
    {
        add_action('elementor/widgets/register', [$this, 'register_widget']);
    }

    public function register_widget($widgets_manager)
    {
        if (!class_exists('\Elementor\Widget_Base')) {
            return;
        }

        require_once MSFB_PATH . 'includes/class-msfb-elementor-widget.php';
        $widgets_manager->register(new MSFB_Elementor_Widget());
    }
}
