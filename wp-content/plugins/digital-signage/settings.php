<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// Register settings
function digsign_register_settings() {
    register_setting('digsign_settings_group', 'digsign_category_name', [
        'type' => 'string',
        'default' => 'news',
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('digsign_settings_group', 'digsign_image_width', [
        'type' => 'integer',
        'default' => 1260,
        'sanitize_callback' => 'absint'
    ]);
    register_setting('digsign_settings_group', 'digsign_image_height', [
        'type' => 'integer',
        'default' => 940,
        'sanitize_callback' => 'absint'
    ]);
    register_setting('digsign_settings_group', 'digsign_refresh_interval', [
        'type' => 'integer',
        'default' => 10,
        'sanitize_callback' => 'absint'
    ]);
    register_setting('digsign_settings_group', 'digsign_slide_delay', [
        'type' => 'integer',
        'default' => 5,
        'sanitize_callback' => 'absint'
    ]);
    register_setting('digsign_settings_group', 'digsign_enable_qrcodes', [
        'type' => 'boolean',
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean'
    ]);
    
    // Layout settings
    register_setting('digsign_settings_group', 'digsign_layout_type', [
        'type' => 'string',
        'default' => 'fullscreen',
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('digsign_settings_group', 'digsign_right_panel_content', [
        'type' => 'string',
        'default' => '',
        'sanitize_callback' => 'wp_kses_post'
    ]);
    register_setting('digsign_settings_group', 'digsign_header_content', [
        'type' => 'string',
        'default' => '',
        'sanitize_callback' => 'wp_kses_post'
    ]);
}

// Add settings page
function digsign_add_settings_page() {
    add_options_page(
        'Digital Signage Settings',
        'Digital Signage',
        'manage_options',
        'digsign-settings',
        'digsign_render_settings_page'
    );
}

/**
 * Check if permalinks are set to plain
 * 
 * @return boolean True if permalinks are set to plain, false otherwise
 */
function digsign_has_plain_permalinks() {
    $permalink_structure = get_option('permalink_structure');
    return empty($permalink_structure);
}

// Register and enqueue admin scripts
function digsign_admin_scripts($hook) {
    if ('settings_page_digsign-settings' !== $hook) {
        return;
    }
    
    // Enqueue admin styles
    wp_enqueue_style('digsign-admin-style', plugin_dir_url(__FILE__) . 'assets/css/digsign-admin.css', array(), '1.0.0');
    
    // Enqueue WordPress editor components
    // Enqueue Gutenberg block editor assets
    wp_enqueue_script('wp-blocks');
    wp_enqueue_script('wp-element');
    wp_enqueue_script('wp-editor');
    wp_enqueue_script('wp-components');
    wp_enqueue_script('wp-i18n');
    wp_enqueue_script('wp-block-editor');
    wp_enqueue_script('wp-block-library');
    wp_enqueue_script('wp-format-library');
    wp_enqueue_style('wp-edit-blocks');
    wp_enqueue_style('wp-components');
    wp_enqueue_style('wp-block-library');
    wp_enqueue_style('wp-format-library');
    
    // Register custom admin script with path to the actual file
    wp_register_script(
        'digsign-admin-script', 
        plugin_dir_url(__FILE__) . 'assets/js/digsign-admin.js', 
        array(
            'jquery', 
            'wp-editor', 
            'wp-element', 
            'wp-blocks', 
            'wp-components', 
            'wp-block-editor',
            'wp-i18n',
            'wp-block-library'
        ), 
        '1.0.1', 
        true
    );
    wp_enqueue_script('digsign-admin-script');
    
    wp_localize_script('digsign-admin-script', 'digsign_admin', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('digsign_cleanup_nonce'),
        'confirm_message' => __('Are you sure you want to delete old image thumbnails? This cannot be undone.', 'digital-signage'),
        'processing_message' => __('Processing...', 'digital-signage'),
    ));
    
    // Add inline script
    $script = "
        jQuery(document).ready(function($) {
            // Thumbnails cleanup
            $('#digsign-cleanup-button').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm(digsign_admin.confirm_message)) {
                    return;
                }
                
                const resultDiv = $('#digsign-cleanup-result');
                resultDiv.html('<p>' + digsign_admin.processing_message + '</p>').show();
                
                $.ajax({
                    url: digsign_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'digsign_cleanup_thumbnails',
                        nonce: digsign_admin.nonce
                    },
                    success: function(response) {
                        resultDiv.html('<p>' + response.data + '</p>');
                    },
                    error: function() {
                        resultDiv.html('<p class=\"error\">" . esc_js(__('An error occurred during the cleanup process.', 'digital-signage')) . "</p>');
                    }
                });
            });
            
            // Layout accordion toggle
            $('.digsign-layout-accordion-header').on('click', function() {
                $(this).parent().toggleClass('digsign-layout-accordion-open');
            });
            
            // Layout preview update
            $('#digsign_layout_type').on('change', function() {
                var selectedLayout = $(this).val();
                $('.layout-preview-svg').hide();
                $('#layout-preview-' + selectedLayout).show();
                
                // Toggle editor visibility based on layout
                toggleEditors(selectedLayout);
            });
            
            function toggleEditors(layoutType) {
                var rightPanelEditor = $('#digsign-right-panel-editor');
                var headerEditor = $('#digsign-header-editor');
                
                if (layoutType === 'header-panels') {
                    rightPanelEditor.show();
                    headerEditor.show();
                } else if (layoutType === 'two-panels') {
                    rightPanelEditor.show();
                    headerEditor.hide();
                } else {
                    rightPanelEditor.hide();
                    headerEditor.hide();
                }
            }
            
            // Initialize editors visibility
            toggleEditors($('#digsign_layout_type').val());
        });
    ";
    
    wp_add_inline_script('digsign-admin-script', $script);
    
    // Register the block library explicitly
    if (function_exists('register_block_type')) {
        wp_enqueue_script('wp-block-library');
    }
    
    // Localize the script with additional data
    wp_localize_script('digsign-admin-script', 'digsignGutenberg', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('digsign-gutenberg-nonce'),
        'media' => array(
            'title' => __('Select or Upload Media', 'digital-signage'),
            'button' => __('Use this media', 'digital-signage')
        ),
        'blockTypes' => function_exists('get_block_categories') ? get_block_categories(get_current_screen()) : array()
    ));
    
    // Enqueue the admin script
    wp_enqueue_script('digsign-admin-script');
}

// AJAX handler for thumbnail cleanup
function digsign_cleanup_thumbnails_handler() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'digsign_cleanup_nonce')) {
        wp_send_json_error(__('Security check failed.', 'digital-signage'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'digital-signage'));
    }
    
    $result = digsign_cleanup_gallery_thumbnails();
    
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
function digsign_cleanup_gallery_thumbnails() {
    global $wpdb;
    
    try {
        // Get all attachment IDs with digsign-gallery-thumb in their metadata
        $attachments = [];
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_wp_attachment_metadata',
                    'value' => 'digsign-gallery-thumb',
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
            
            if (!isset($meta['sizes']['digsign-gallery-thumb']) || !isset($meta['file'])) {
                continue;
            }
            
            // Get path to the thumbnail
            $file_dir = dirname($meta['file']);
            $thumb_file = $meta['sizes']['digsign-gallery-thumb']['file'];
            $thumb_path = $base_dir . '/' . $file_dir . '/' . $thumb_file;
            
            // Delete the file if it exists
            if (file_exists($thumb_path) && wp_delete_file($thumb_path)) {
                $deleted_count++;
            }
            
            // Remove from metadata
            unset($meta['sizes']['digsign-gallery-thumb']);
            update_post_meta($attachment->post_id, '_wp_attachment_metadata', $meta);
        }
        
        return $deleted_count;
        
    } catch (Exception $e) {
        return new WP_Error('cleanup_failed', $e->getMessage());
    }
}

// Render settings page
function digsign_render_settings_page() {
    // Fetch categories
    $categories = get_categories([
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    $selected_category = get_option('digsign_category_name', 'news');
    $site_url = esc_url(home_url('/digital-signage/'));
    $has_plain_permalinks = digsign_has_plain_permalinks();
    
    // Get saved content
    $header_content = get_option('digsign_header_content', '');
    $right_panel_content = get_option('digsign_right_panel_content', '');
    
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
            <p><strong><?php esc_html_e('Cache Notice:', 'digital-signage'); ?></strong> <?php esc_html_e('If you are using a caching plugin or CDN, you may need to exclude the REST API endpoint from caching:', 'digital-signage'); ?> <code><?php echo esc_html(rest_url('digsign/v1/slides')); ?></code></p>
            <p><?php esc_html_e('This ensures the digital signage displays will always show fresh content according to your refresh settings.', 'digital-signage'); ?></p>
        </div>
        
        <form method="post" action="options.php" id="digsign-settings-form">
            <?php settings_fields('digsign_settings_group'); ?>
            <?php do_settings_sections('digsign_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Category Name</th>
                    <td>
                        <select name="digsign_category_name">
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
                        <input type="number" name="digsign_image_width" value="<?php echo esc_attr(get_option('digsign_image_width', 1260)); ?>" min="1" />
                        <p class="description">Width in pixels for signage images.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Image Height</th>
                    <td>
                        <input type="number" name="digsign_image_height" value="<?php echo esc_attr(get_option('digsign_image_height', 940)); ?>" min="1" />
                        <p class="description">Height in pixels for signage images.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Page Refresh Interval</th>
                    <td>
                        <input type="number" name="digsign_refresh_interval" value="<?php echo esc_attr(get_option('digsign_refresh_interval', 10)); ?>" min="1" />
                        <p class="description">Interval in seconds to refresh the gallery images.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Slide Delay</th>
                    <td>
                        <input type="number" name="digsign_slide_delay" value="<?php echo esc_attr(get_option('digsign_slide_delay', 5)); ?>" min="1" />
                        <p class="description">Time in seconds each image is shown before switching to the next slide.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">QR Codes</th>
                    <td>
                        <label>
                            <input type="checkbox" name="digsign_enable_qrcodes" value="1" <?php checked(get_option('digsign_enable_qrcodes', true)); ?> />
                            Enable QR codes for each slide
                        </label>
                        <p class="description">When enabled, a QR code linking to the post will be displayed on each slide.</p>
                    </td>
                </tr>
            </table>
            <!-- Layout Settings Accordion -->
            <div class="digsign-layout-accordion">
                <div class="digsign-layout-accordion-header">
                    <h3>Layout Settings</h3>
                    <span class="digsign-layout-accordion-icon dashicons dashicons-arrow-down-alt2"></span>
                </div>
                <div class="digsign-layout-accordion-content">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Display Layout</th>
                            <td>
                                <div class="digsign-layout-option">
                                    <select name="digsign_layout_type" id="digsign_layout_type">
                                        <option value="fullscreen" <?php selected(get_option('digsign_layout_type', 'fullscreen'), 'fullscreen'); ?>>Full Screen</option>
                                        <option value="header-panels" <?php selected(get_option('digsign_layout_type', 'fullscreen'), 'header-panels'); ?>>Header and 2 Panels (60/40)</option>
                                        <option value="two-panels" <?php selected(get_option('digsign_layout_type', 'fullscreen'), 'two-panels'); ?>>2 Panels without Header (60/40)</option>
                                    </select>
                                </div>
                                
                                <div class="digsign-layout-preview">
                                    <p>Layout Preview:</p>
                                    <img class="layout-preview-svg" id="layout-preview-fullscreen" 
                                         src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/images/layout-fullscreen.svg'); ?>" 
                                         alt="Full Screen Layout" 
                                         width="100" height="50"
                                         <?php echo (get_option('digsign_layout_type', 'fullscreen') !== 'fullscreen') ? 'style="display: none;"' : ''; ?>>
                                    
                                    <img class="layout-preview-svg" id="layout-preview-header-panels" 
                                         src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/images/layout-header-panels.svg'); ?>" 
                                         alt="Header and 2 Panels Layout" 
                                         width="100" height="50"
                                         <?php echo (get_option('digsign_layout_type', 'fullscreen') !== 'header-panels') ? 'style="display: none;"' : ''; ?>>
                                    
                                    <img class="layout-preview-svg" id="layout-preview-two-panels" 
                                         src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/images/layout-two-panels.svg'); ?>" 
                                         alt="2 Panels Layout" 
                                         width="100" height="50"
                                         <?php echo (get_option('digsign_layout_type', 'fullscreen') !== 'two-panels') ? 'style="display: none;"' : ''; ?>>
                                </div>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Gutenberg Editor for Header Content (Layout 2) -->
                    <div id="digsign-header-editor" style="<?php echo (get_option('digsign_layout_type', 'fullscreen') !== 'header-panels') ? 'display: none;' : ''; ?>">
                        <div class="digsign-editor-container">
                            <div class="digsign-editor-header">Header Content</div>
                            <p class="description"><?php esc_html_e('Use this block editor to create content that will be displayed in the header area of the digital signage. You can add text, images, and other media.', 'digital-signage'); ?></p>
                            <div class="digsign-editor-body">
                                <div id="digsign-header-block-editor" class="block-editor-container"></div>
                                <textarea 
                                    id="digsign_header_content" 
                                    name="digsign_header_content" 
                                    class="digsign-gutenberg-content"
                                    style="display:none;"
                                ><?php echo esc_textarea($header_content); ?></textarea>
                                <script>
                                wp.domReady(function() {
                                    setTimeout(function() {
                                        initBlockEditor('digsign-header-block-editor', 'digsign_header_content');
                                    }, 300);
                                });
                                </script>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Gutenberg Editor for Right Panel Content (Layout 2 and 3) -->
                    <div id="digsign-right-panel-editor" style="<?php echo (get_option('digsign_layout_type', 'fullscreen') === 'fullscreen') ? 'display: none;' : ''; ?>">
                        <div class="digsign-editor-container">
                            <div class="digsign-editor-header">Right Panel Content</div>
                            <p class="description"><?php esc_html_e('Use this block editor to create content that will be displayed in the right panel of the digital signage. You can add text, images, and other media.', 'digital-signage'); ?></p>
                            <div class="digsign-editor-body">
                                <div id="digsign-right-panel-block-editor" class="block-editor-container"></div>
                                <textarea 
                                    id="digsign_right_panel_content" 
                                    name="digsign_right_panel_content" 
                                    class="digsign-gutenberg-content"
                                    style="display:none;"
                                ><?php echo esc_textarea($right_panel_content); ?></textarea>
                                <script>
                                wp.domReady(function() {
                                    setTimeout(function() {
                                        initBlockEditor('digsign-right-panel-block-editor', 'digsign_right_panel_content');
                                    }, 300);
                                });
                                </script>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php submit_button(); ?>
        </form>
        
        <hr>
        
        <h2><?php esc_html_e('Image Management', 'digital-signage'); ?></h2>
        <p><?php esc_html_e('If you\'ve changed image dimensions, you may want to clean up old thumbnails to save disk space.', 'digital-signage'); ?></p>
        <button id="digsign-cleanup-button" class="button button-secondary">
            <?php esc_html_e('Delete Old Thumbnails', 'digital-signage'); ?>
        </button>
        <div id="digsign-cleanup-result" style="margin-top: 10px; display: none;"></div>
    </div>
    <?php
}

// Hook all functions after they've been defined
add_action('admin_init', 'digsign_register_settings');
add_action('admin_menu', 'digsign_add_settings_page');
add_action('admin_enqueue_scripts', 'digsign_admin_scripts');
add_action('wp_ajax_digsign_cleanup_thumbnails', 'digsign_cleanup_thumbnails_handler');
