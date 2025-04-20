<?php
// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all options created by the plugin
delete_option('dsp_category_name');
delete_option('dsp_image_width');
delete_option('dsp_image_height');
delete_option('dsp_refresh_interval');
delete_option('dsp_slide_delay');

// Flush rewrite rules after uninstallation
flush_rewrite_rules();
