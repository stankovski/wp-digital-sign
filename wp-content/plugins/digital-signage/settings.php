<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// Register settings
function dsp_register_settings() {
    register_setting('dsp_settings_group', 'dsp_category_name', [
        'type' => 'string',
        'default' => 'news',
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('dsp_settings_group', 'dsp_image_width', [
        'type' => 'integer',
        'default' => 1260,
        'sanitize_callback' => 'absint'
    ]);
    register_setting('dsp_settings_group', 'dsp_image_height', [
        'type' => 'integer',
        'default' => 940,
        'sanitize_callback' => 'absint'
    ]);
    register_setting('dsp_settings_group', 'dsp_refresh_interval', [
        'type' => 'integer',
        'default' => 10,
        'sanitize_callback' => 'absint'
    ]);
    register_setting('dsp_settings_group', 'dsp_slide_delay', [
        'type' => 'integer',
        'default' => 5,
        'sanitize_callback' => 'absint'
    ]);
    register_setting('dsp_settings_group', 'dsp_enable_qrcodes', [
        'type' => 'boolean',
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean'
    ]);
}

// Add settings page
function dsp_add_settings_page() {
    add_options_page(
        'Digital Signage Settings',
        'Digital Signage',
        'manage_options',
        'dsp-settings',
        'dsp_render_settings_page'
    );
}

/**
 * Check if permalinks are set to plain
 * 
 * @return boolean True if permalinks are set to plain, false otherwise
 */
function dsp_has_plain_permalinks() {
    $permalink_structure = get_option('permalink_structure');
    return empty($permalink_structure);
}

// Register and enqueue admin scripts
function dsp_admin_scripts($hook) {
    if ('settings_page_dsp-settings' !== $hook) {
        return;
    }
    
    wp_register_script('dsp-admin-script', '', array('jquery'), '1.0', true);
    wp_enqueue_script('dsp-admin-script');
    
    wp_localize_script('dsp-admin-script', 'dsp_admin', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('dsp_cleanup_nonce'),
        'confirm_message' => __('Are you sure you want to delete old image thumbnails? This cannot be undone.', 'digital-signage'),
        'processing_message' => __('Processing...', 'digital-signage'),
    ));
    
    // Add inline script
    $script = "
        jQuery(document).ready(function($) {
            $('#dsp-cleanup-button').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm(dsp_admin.confirm_message)) {
                    return;
                }
                
                const resultDiv = $('#dsp-cleanup-result');
                resultDiv.html('<p>' + dsp_admin.processing_message + '</p>').show();
                
                $.ajax({
                    url: dsp_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dsp_cleanup_thumbnails',
                        nonce: dsp_admin.nonce
                    },
                    success: function(response) {
                        resultDiv.html('<p>' + response.data + '</p>');
                    },
                    error: function() {
                        resultDiv.html('<p class=\"error\">" . esc_js(__('An error occurred during the cleanup process.', 'digital-signage')) . "</p>');
                    }
                });
            });
        });
    ";
    
    wp_add_inline_script('dsp-admin-script', $script);
}

// AJAX handler for thumbnail cleanup
function dsp_cleanup_thumbnails_handler() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'dsp_cleanup_nonce')) {
        wp_send_json_error(__('Security check failed.', 'digital-signage'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'digital-signage'));
    }
    
    $result = dsp_cleanup_gallery_thumbnails();
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        /* translators: %d: number of deleted files */
        $message = sprintf(__('Cleanup complete. %d old gallery thumbnails were deleted.', 'digital-signage'), $result);
        wp_send_json_success($message);
    }
}

/**
 * Cleanup old gallery thumbnails
 * 
 * @return int|WP_Error Number of deleted files or WP_Error
 */
function dsp_cleanup_gallery_thumbnails() {
    global $wpdb;
    
    try {
        // Get all attachment IDs with dsp-gallery-thumb in their metadata
        $attachments = [];
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_wp_attachment_metadata',
                    'value' => 'dsp-gallery-thumb',
                    'compare' => 'LIKE'
                ]
            ],
            'fields' => 'ids'
        ]);
        
        if ($query->have_posts()) {
            foreach ($query->posts as $post_id) {
                $meta_value = get_post_meta($post_id, '_wp_attachment_metadata', true);
                if ($meta_value) {
                    $attachments[] = (object)[
                        'post_id' => $post_id,
                        'meta_value' => $meta_value
                    ];
                }
            }
        }
        
        if (empty($attachments)) {
            return 0;
        }
        
        $deleted_count = 0;
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        foreach ($attachments as $attachment) {
            $meta = maybe_unserialize($attachment->meta_value);
            
            if (!isset($meta['sizes']['dsp-gallery-thumb']) || !isset($meta['file'])) {
                continue;
            }
            
            // Get path to the thumbnail
            $file_dir = dirname($meta['file']);
            $thumb_file = $meta['sizes']['dsp-gallery-thumb']['file'];
            $thumb_path = $base_dir . '/' . $file_dir . '/' . $thumb_file;
            
            // Delete the file if it exists
            if (file_exists($thumb_path) && wp_delete_file($thumb_path)) {
                $deleted_count++;
            }
            
            // Remove from metadata
            unset($meta['sizes']['dsp-gallery-thumb']);
            update_post_meta($attachment->post_id, '_wp_attachment_metadata', $meta);
        }
        
        return $deleted_count;
        
    } catch (Exception $e) {
        return new WP_Error('cleanup_failed', $e->getMessage());
    }
}

// Render settings page
function dsp_render_settings_page() {
    // Fetch categories
    $categories = get_categories([
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    $selected_category = get_option('dsp_category_name', 'news');
    $site_url = esc_url(home_url('/digital-signage/'));
    $has_plain_permalinks = dsp_has_plain_permalinks();
    ?>
    <div class="wrap">
        <h1>Digital Signage Settings</h1>
        
        <?php if ($has_plain_permalinks): ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e('Warning:', 'digital-signage'); ?></strong> <?php esc_html_e('Your site is using "Plain" permalink settings. Digital Signage requires pretty permalinks to function correctly.', 'digital-signage'); ?></p>
            <p><?php esc_html_e('Please go to', 'digital-signage'); ?> <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>"><?php esc_html_e('Settings &gt; Permalinks', 'digital-signage'); ?></a> <?php esc_html_e('and choose a different permalink structure.', 'digital-signage'); ?></p>
        </div>
        <?php endif; ?>
        
        <div style="background: #f8f9fa; border-left: 4px solid #0073aa; padding: 12px 16px; margin-bottom: 20px;">
            <strong>To view your digital signage gallery, navigate to:</strong>
            <br>
            <a href="<?php echo esc_html($site_url); ?>"><?php echo esc_html($site_url); ?></a>
        </div>
        
        <div class="notice notice-warning inline">
            <p><strong><?php esc_html_e('Cache Notice:', 'digital-signage'); ?></strong> <?php esc_html_e('If you are using a caching plugin or CDN, you may need to exclude the REST API endpoint from caching:', 'digital-signage'); ?> <code><?php echo esc_html(rest_url('dsp/v1/slides')); ?></code></p>
            <p><?php esc_html_e('This ensures the digital signage displays will always show fresh content according to your refresh settings.', 'digital-signage'); ?></p>
        </div>
        
        <form method="post" action="options.php">
            <?php settings_fields('dsp_settings_group'); ?>
            <?php do_settings_sections('dsp_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Category Name</th>
                    <td>
                        <select name="dsp_category_name">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat->slug); ?>" <?php selected($selected_category, $cat->slug); ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Posts from this category will be shown in the digital signage gallery.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Image Width</th>
                    <td>
                        <input type="number" name="dsp_image_width" value="<?php echo esc_attr(get_option('dsp_image_width', 1260)); ?>" min="1" />
                        <p class="description">Width in pixels for signage images.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Image Height</th>
                    <td>
                        <input type="number" name="dsp_image_height" value="<?php echo esc_attr(get_option('dsp_image_height', 940)); ?>" min="1" />
                        <p class="description">Height in pixels for signage images.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Page Refresh Interval</th>
                    <td>
                        <input type="number" name="dsp_refresh_interval" value="<?php echo esc_attr(get_option('dsp_refresh_interval', 10)); ?>" min="1" />
                        <p class="description">Interval in seconds to refresh the gallery images.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Slide Delay</th>
                    <td>
                        <input type="number" name="dsp_slide_delay" value="<?php echo esc_attr(get_option('dsp_slide_delay', 5)); ?>" min="1" />
                        <p class="description">Time in seconds each image is shown before switching to the next slide.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">QR Codes</th>
                    <td>
                        <label>
                            <input type="checkbox" name="dsp_enable_qrcodes" value="1" <?php checked(get_option('dsp_enable_qrcodes', true)); ?> />
                            Enable QR codes for each slide
                        </label>
                        <p class="description">When enabled, a QR code linking to the post will be displayed on each slide.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <hr>
        
        <h2><?php esc_html_e('Image Management', 'digital-signage'); ?></h2>
        <p><?php esc_html_e('If you\'ve changed image dimensions, you may want to clean up old thumbnails to save disk space.', 'digital-signage'); ?></p>
        <button id="dsp-cleanup-button" class="button button-secondary">
            <?php esc_html_e('Delete Old Thumbnails', 'digital-signage'); ?>
        </button>
        <div id="dsp-cleanup-result" style="margin-top: 10px; display: none;"></div>
    </div>
    <?php
}

// Hook all functions after they've been defined
add_action('admin_init', 'dsp_register_settings');
add_action('admin_menu', 'dsp_add_settings_page');
add_action('admin_enqueue_scripts', 'dsp_admin_scripts');
add_action('wp_ajax_dsp_cleanup_thumbnails', 'dsp_cleanup_thumbnails_handler');
