<?php
/*
Plugin Name: Digital Signage
Description: Adds a page that displays a digital signage gallery of images from your WordPress posts.
Version: 1.0.1
Author: stankovski
Author URI: https://github.com/stankovski/
Text Domain: digital-signage
Domain Path: /languages
License: MIT
License URI: https://opensource.org/licenses/MIT
*/

if (!defined('ABSPATH')) exit;

// Include QR code generator
require_once plugin_dir_path(__FILE__) . 'qrcode.php';

// Define QR code directory
define('DIGSIGN_QRCODE_DIR', WP_CONTENT_DIR . '/uploads/digsign-qrcodes/');
define('DIGSIGN_QRCODE_URL', content_url('/uploads/digsign-qrcodes/'));

// Create QR code directory if it doesn't exist
function digsign_create_qrcode_dir() {
    if (!file_exists(DIGSIGN_QRCODE_DIR)) {
        wp_mkdir_p(DIGSIGN_QRCODE_DIR);
        // Create .htaccess to allow direct access to QR code images
        $htaccess = "# Allow direct access to QR code images\n";
        $htaccess .= "<IfModule mod_rewrite.c>\n";
        $htaccess .= "RewriteEngine Off\n";
        $htaccess .= "</IfModule>\n";
        file_put_contents(DIGSIGN_QRCODE_DIR . '.htaccess', $htaccess);
    }
}
register_activation_hook(__FILE__, 'digsign_create_qrcode_dir');

// Generate QR code for a post URL
function digsign_generate_qrcode($post_id) {
    $post_url = get_permalink($post_id);
    $filename = 'qr-' . md5($post_url) . '.png';
    $file_path = DIGSIGN_QRCODE_DIR . $filename;
    $file_url = DIGSIGN_QRCODE_URL . $filename;
    
    // Check if QR code exists already
    if (!file_exists($file_path)) {
        // Create directory if it doesn't exist (in case it was deleted)
        digsign_create_qrcode_dir();
        
        // Generate QR code
        $options = [
            'w' => 200,  // Width
            'h' => 200,  // Height
            'bc' => 'FFFFFF', // Background color
            'fc' => '000000'  // Foreground color
        ];
        
        $qr = new DigSign\QRCode($post_url, $options);
        $image = $qr->render_image();
        
        // Add transparency to the QR code background
        imagealphablending($image, false);
        imagesavealpha($image, true);
        
        // Save the QR code
        imagepng($image, $file_path);
        imagedestroy($image);
    }
    
    return $file_url;
}

// Register custom image size (do not hook to after_setup_theme)
function digsign_register_image_sizes() {
    $width = intval(get_option('digsign_image_width', 1260));
    $height = intval(get_option('digsign_image_height', 940));
    
    // Remove previously registered size if it exists
    if (has_image_size('digsign-gallery-thumb')) {
        remove_image_size('digsign-gallery-thumb');
    }
    
    // Register with current settings values
    add_image_size('digsign-gallery-thumb', $width, $height, false);
}

// Call directly during plugin initialization
digsign_register_image_sizes(); 

// Hook to option updates to regenerate image sizes when settings change
add_action('update_option_digsign_image_width', 'digsign_register_image_sizes');
add_action('update_option_digsign_image_height', 'digsign_register_image_sizes');

// Register custom rewrite endpoint
function digsign_add_gallery_rewrite() {
    add_rewrite_rule('^digital-signage/?$', 'index.php?digsign_gallery=1', 'top');
}
add_action('init', 'digsign_add_gallery_rewrite');

// Flush rewrite rules on plugin activation
function digsign_activate_plugin() {
    digsign_add_gallery_rewrite();
    flush_rewrite_rules();
    digsign_create_qrcode_dir();
}
register_activation_hook(__FILE__, 'digsign_activate_plugin');

// Optional: Flush rewrite rules on plugin deactivation
function digsign_deactivate_plugin() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'digsign_deactivate_plugin');

// Register query var
function digsign_add_query_vars($vars) {
    $vars[] = 'digsign_gallery';
    return $vars;
}
add_filter('query_vars', 'digsign_add_query_vars');

// Register and enqueue scripts and styles for the digital signage page
function digsign_register_scripts() {
    // Register styles
    wp_register_style(
        'digsign-gallery-style',
        plugins_url('assets/css/digital-signage.css', __FILE__),
        array(),
        '1.0.0'
    );
    
    // Register scripts
    wp_register_script(
        'digsign-gallery-script',
        plugins_url('assets/js/digital-signage.js', __FILE__),
        array(), 
        '1.0.0',
        true
    );
}
add_action('init', 'digsign_register_scripts');

// Template redirect for gallery page
function digsign_template_redirect() {
    if (get_query_var('digsign_gallery')) {
        digsign_render_gallery_page();
        exit;
    }
}
add_action('template_redirect', 'digsign_template_redirect');

// --- Settings Page ---
require_once plugin_dir_path(__FILE__) . 'settings.php';

// REST API endpoint for gallery images
add_action('rest_api_init', function () {
    register_rest_route('digsign/v1', '/slides', [
        'methods' => 'GET',
        'callback' => function () {
            $category_name = get_option('digsign_category_name', 'news');
            $width = intval(get_option('digsign_image_width', 1260));
            $height = intval(get_option('digsign_image_height', 940));
            $image_size = 'digsign-gallery-thumb';
            $refresh_interval = intval(get_option('digsign_refresh_interval', 10));
            $slide_delay = intval(get_option('digsign_slide_delay', 5));
            $enable_qrcodes = (bool)get_option('digsign_enable_qrcodes', true);
            $layout_type = get_option('digsign_layout_type', 'fullscreen');
            
            $args = [
                'category_name' => $category_name,
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ];
            $query = new WP_Query($args);
            $slides = [];
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    $thumb_id = get_post_thumbnail_id($post_id);
                    
                    // Generate QR code only if enabled
                    $qr_code_url = $enable_qrcodes ? digsign_generate_qrcode($post_id) : '';
                    
                    if ($thumb_id) {
                        // Check if the custom size exists, generate if not
                        $meta = wp_get_attachment_metadata($thumb_id);
                        if (!isset($meta['sizes'][$image_size])) {
                            // Generate the image size on demand
                            require_once ABSPATH . 'wp-admin/includes/image.php';
                            $fullsizepath = get_attached_file($thumb_id);
                            if ($fullsizepath && file_exists($fullsizepath)) {
                                $metadata = wp_generate_attachment_metadata($thumb_id, $fullsizepath);
                                if ($metadata && !is_wp_error($metadata)) {
                                    wp_update_attachment_metadata($thumb_id, $metadata);
                                }
                            }
                        }
                        $img_url = get_the_post_thumbnail_url($post_id, $image_size);
                        if ($img_url) {
                            $slides[] = [
                                'type' => 'image',
                                'content' => $img_url,
                                'qrcode' => $qr_code_url,
                                'post_title' => get_the_title(),
                                'post_url' => get_permalink($post_id)
                            ];
                        }
                    } else {
                        // No featured image, use post content instead
                        $content = apply_filters('the_content', get_the_content());
                        if (!empty($content)) {
                            $slides[] = [
                                'type' => 'html',
                                'content' => $content,
                                'title' => get_the_title(),
                                'qrcode' => $qr_code_url,
                                'post_url' => get_permalink($post_id)
                            ];
                        }
                    }
                }
                wp_reset_postdata();
            }
            $layout_type = get_option('digsign_layout_type', 'fullscreen');
            
            return rest_ensure_response([
                'slides' => $slides,
                'settings' => [
                    'refresh_interval' => $refresh_interval,
                    'slide_delay' => $slide_delay,
                    'enable_qrcodes' => $enable_qrcodes,
                    'layout_type' => $layout_type
                ]
            ]);
        },
        'permission_callback' => '__return_true'
    ]);
});

// Legacy REST API endpoint for gallery images
add_action('rest_api_init', function () {
    register_rest_route('dsp/v1', '/images', [
        'methods' => 'GET',
        'callback' => function () {
            $category_name = get_option('dsp_category_name', 'news');
            $width = intval(get_option('dsp_image_width', 1260));
            $height = intval(get_option('dsp_image_height', 940));
            $image_size = 'dsp-gallery-thumb';
            $args = [
                'category_name' => $category_name,
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ];
            $query = new WP_Query($args);
            $images = [];
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    $thumb_id = get_post_thumbnail_id($post_id);
                    if ($thumb_id) {
                        // Check if the custom size exists, generate if not
                        $meta = wp_get_attachment_metadata($thumb_id);
                        if (!isset($meta['sizes'][$image_size])) {
                            // Generate the image size on demand
                            require_once ABSPATH . 'wp-admin/includes/image.php';
                            $fullsizepath = get_attached_file($thumb_id);
                            if ($fullsizepath && file_exists($fullsizepath)) {
                                $metadata = wp_generate_attachment_metadata($thumb_id, $fullsizepath);
                                if ($metadata && !is_wp_error($metadata)) {
                                    wp_update_attachment_metadata($thumb_id, $metadata);
                                }
                            }
                        }
                        $img_url = get_the_post_thumbnail_url($post_id, $image_size);
                        if ($img_url) {
                            $images[] = $img_url;
                        }
                    }
                }
                wp_reset_postdata();
            }
            return rest_ensure_response($images);
        },
        'permission_callback' => '__return_true'
    ]);
});

// Render gallery HTML
function digsign_render_gallery_page() {
    $width = intval(get_option('digsign_image_width', 1260));
    $height = intval(get_option('digsign_image_height', 940));
    $category_name = esc_html(get_option('digsign_category_name', 'news'));
    $refresh_interval = intval(get_option('digsign_refresh_interval', 10));
    $slide_delay = intval(get_option('digsign_slide_delay', 5));
    $enable_qrcodes = (bool)get_option('digsign_enable_qrcodes', true);
    $layout_type = get_option('digsign_layout_type', 'fullscreen');
    $header_content = get_option('digsign_header_content', '');
    $right_panel_content = get_option('digsign_right_panel_content', '');
    
    // Enqueue required styles and scripts
    wp_enqueue_style('digsign-gallery-style');
    wp_enqueue_script('digsign-gallery-script');
    
    // Add inline style for dynamic values
    wp_add_inline_style('digsign-gallery-style', sprintf('
        .gallery .slide {
            width: %dpx;
            height: %dpx;
        }
        .gallery .html-content {
            max-height: %dpx;
        }
        .digsign-layout-main {
            width: %dpx;
            max-width: %dpx;
        }
    ', $width, $height, $height - 40, $width, $width));
    
    // Add inline script for dynamic values
    wp_add_inline_script('digsign-gallery-script', sprintf('
        var digsignConfig = {
            ajaxUrl: %s,
            refreshInterval: %d,
            slideDelay: %d,
            enableQrCodes: %s,
            categoryName: %s,
            layoutType: %s,
            i18n: {
                noContent: %s,
                failedToLoad: %s
            }
        };
    ', 
        wp_json_encode(esc_url_raw(rest_url('digsign/v1/slides'))),
        max(1, $refresh_interval) * 1000,
        max(1, $slide_delay) * 1000,
        $enable_qrcodes ? 'true' : 'false',
        wp_json_encode($category_name),
        wp_json_encode($layout_type),
        wp_json_encode(sprintf(__('No content found for category "%s".', 'digital-signage'), $category_name)),
        wp_json_encode(__('Failed to load content.', 'digital-signage'))
    ));
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Digital Signage</title>
        <?php wp_head(); ?>
    </head>
    <body class="digsign-layout-<?php echo esc_attr($layout_type); ?>">
        <?php if ($layout_type === 'header-panels' && !empty($header_content)): ?>
        <div class="digsign-layout-header">
            <?php echo wp_kses_post(apply_filters('the_content', $header_content)); ?>
        </div>
        <?php endif; ?>
        
        <div class="digsign-layout-container">
            <div class="digsign-layout-main">
                <div class="gallery" id="digsign-carousel">
                    <p id="digsign-loading">Loading content...</p>
                </div>
            </div>
            
            <?php if (($layout_type === 'header-panels' || $layout_type === 'two-panels') && !empty($right_panel_content)): ?>
            <div class="digsign-layout-sidebar">
                <?php echo wp_kses_post(apply_filters('the_content', $right_panel_content)); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}
