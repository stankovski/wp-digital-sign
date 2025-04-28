<?php
// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all options created by the plugin
delete_option('digsign_category_name');
delete_option('digsign_image_width');
delete_option('digsign_image_height');
delete_option('digsign_refresh_interval');
delete_option('digsign_slide_delay');

// Flush rewrite rules after uninstallation
flush_rewrite_rules();
