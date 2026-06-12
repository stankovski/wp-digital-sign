<?php
/*
Plugin Name: Digital Signage
Description: Adds a page that displays a digital signage gallery of images from your WordPress posts.
Version: 1.0.2
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

// Define QR code directory using wp_upload_dir()
function digsign_get_upload_dir() {
    $upload_dir = wp_upload_dir();
    return [
        'dir' => trailingslashit($upload_dir['basedir']) . 'digsign-qrcodes/',
        'url' => trailingslashit($upload_dir['baseurl']) . 'digsign-qrcodes/',
    ];
}

// Get QR code directory paths when needed
define('DIGSIGN_QRCODE_DIR', digsign_get_upload_dir()['dir']);
define('DIGSIGN_QRCODE_URL', digsign_get_upload_dir()['url']);

// Create QR code directory if it doesn't exist
function digsign_create_qrcode_dir() {
    $upload_info = digsign_get_upload_dir();
    if (!file_exists($upload_info['dir'])) {
        wp_mkdir_p($upload_info['dir']);
    }
}
register_activation_hook(__FILE__, 'digsign_create_qrcode_dir');

// Generate QR code for a post URL
function digsign_generate_qrcode($post_id) {
    $post_url = get_permalink($post_id);
    $filename = 'qr-' . md5($post_url) . '.png';
    $upload_info = digsign_get_upload_dir();
    $file_path = $upload_info['dir'] . $filename;
    $file_url = $upload_info['url'] . $filename;
    
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

// Get image URL with modern format support (WebP, AVIF, etc.)
function digsign_get_modern_image_url($post_id, $image_size) {
    $thumb_id = get_post_thumbnail_id($post_id);
    if (!$thumb_id) {
        return false;
    }
    
    // Check if webp-uploads plugin is active and modern formats should be used
    $use_modern_formats = is_plugin_active('webp-uploads/webp-uploads.php') || 
                         is_plugin_active('performance-lab/performance-lab.php') ||
                         function_exists('webp_uploads_get_image_output_format');
    
    if ($use_modern_formats) {
        // Get attachment metadata to check for modern format sources
        $metadata = wp_get_attachment_metadata($thumb_id);
        if (!empty($metadata) && isset($metadata['sizes'][$image_size]['sources'])) {
            $upload_dir = wp_upload_dir();
            $base_url = trailingslashit($upload_dir['baseurl']);
            $file_dir = dirname($metadata['file']);
            
            // Get the preferred modern format
            $preferred_format = 'webp';
            if (function_exists('webp_uploads_get_image_output_format')) {
                $preferred_format = webp_uploads_get_image_output_format();
            }
            
            // Look for modern format in sources
            $sources = $metadata['sizes'][$image_size]['sources'];
            if (isset($sources['image/' . $preferred_format])) {
                $modern_file = $sources['image/' . $preferred_format]['file'];
                return $base_url . trailingslashit($file_dir) . $modern_file;
            }
            
            // Fallback: look for any WebP source if preferred format not found
            if ($preferred_format !== 'webp' && isset($sources['image/webp'])) {
                $webp_file = $sources['image/webp']['file'];
                return $base_url . trailingslashit($file_dir) . $webp_file;
            }
        }
        
        // Check if full-size image has modern format sources
        if (!empty($metadata) && isset($metadata['sources'])) {
            $upload_dir = wp_upload_dir();
            $base_url = trailingslashit($upload_dir['baseurl']);
            $file_dir = dirname($metadata['file']);
            
            $preferred_format = 'webp';
            if (function_exists('webp_uploads_get_image_output_format')) {
                $preferred_format = webp_uploads_get_image_output_format();
            }
            
            // Look for modern format in full-size sources
            $sources = $metadata['sources'];
            if (isset($sources['image/' . $preferred_format])) {
                $modern_file = $sources['image/' . $preferred_format]['file'];
                return $base_url . trailingslashit($file_dir) . $modern_file;
            }
            
            // Fallback: look for any WebP source if preferred format not found
            if ($preferred_format !== 'webp' && isset($sources['image/webp'])) {
                $webp_file = $sources['image/webp']['file'];
                return $base_url . trailingslashit($file_dir) . $webp_file;
            }
        }
    }
    
    // Fallback to standard method
    return get_the_post_thumbnail_url($post_id, $image_size);
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

/**
 * Collect media (slides or image URLs) for a given category.
 * Options (array):
 *  - category_name (string)
 *  - image_size (string) image size name to request
 *  - include_html (bool) whether to fallback to post HTML content when no featured image
 *  - include_qr (bool) whether to generate QR codes for posts
 *  - structure (string) 'slides' for detailed slide objects or 'urls' for simple list of image URLs
 *
 * @return array
 */
function digsign_collect_media($options = []) {
    // Performance timing instrumentation
    $start_time = microtime(true);
    $timings = [];
    
    $defaults = [
        'category_name' => 'news',
        'image_size'    => 'digsign-gallery-thumb',
        'include_html'  => true,
        'include_qr'    => true,
        'structure'     => 'slides', // 'slides' or 'urls'
    ];
    $opts = wp_parse_args($options, $defaults);

    $args = [
        'category_name'  => $opts['category_name'],
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ];
    
    $query_start = microtime(true);
    $query = new WP_Query($args);
    $timings['database_query'] = (microtime(true) - $query_start) * 1000;
    
    $results = [];
    $processed_posts = 0;
    $qr_generation_time = 0;
    $image_processing_time = 0;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id  = get_the_ID();
            $thumb_id = get_post_thumbnail_id($post_id);
            
            // Time QR code generation
            $qr_start = microtime(true);
            $qr_code_url = ($opts['include_qr']) ? digsign_generate_qrcode($post_id) : '';
            $qr_generation_time += (microtime(true) - $qr_start) * 1000;

            if ($thumb_id) {
                $img_start = microtime(true);
                
                // PERFORMANCE FIX: Skip expensive synchronous image regeneration
                // Instead, use existing image sizes or fallback gracefully
                $meta = wp_get_attachment_metadata($thumb_id);
                
                // Log when image size is missing (for debugging)
                if (!isset($meta['sizes'][$opts['image_size']])) {
                    error_log("Digital Signage: Missing image size '{$opts['image_size']}' for attachment {$thumb_id}, using fallback");
                }
                
                // Get image URL with modern format support (WebP if available)
                // If the requested size doesn't exist, try fallback sizes
                $img_url = digsign_get_modern_image_url($post_id, $opts['image_size']);
                
                // Fallback to other sizes if the requested size is not available
                if (!$img_url && !isset($meta['sizes'][$opts['image_size']])) {
                    $fallback_sizes = ['large', 'medium_large', 'medium', 'full'];
                    foreach ($fallback_sizes as $fallback_size) {
                        $img_url = digsign_get_modern_image_url($post_id, $fallback_size);
                        if ($img_url) {
                            error_log("Digital Signage: Used fallback size '{$fallback_size}' for post {$post_id}");
                            break;
                        }
                    }
                }
                if ($img_url) {
                    if ($opts['structure'] === 'urls') {
                        $results[] = $img_url; // simple list
                    } else {
                        $results[] = [
                            'type'       => 'image',
                            'content'    => $img_url,
                            'qrcode'     => $qr_code_url,
                            'post_title' => get_the_title(),
                            'post_url'   => get_permalink($post_id),
                        ];
                    }
                }
            } elseif ($opts['include_html'] && $opts['structure'] !== 'urls') {
                // Fallback to post content (only for slides structure)
                $content = apply_filters('the_content', get_the_content());
                if (!empty($content)) {
                    $results[] = [
                        'type'     => 'html',
                        'content'  => $content,
                        'title'    => get_the_title(),
                        'qrcode'   => $qr_code_url,
                        'post_url' => get_permalink($post_id),
                    ];
                }
                
                $image_processing_time += (microtime(true) - $img_start) * 1000;
            }
            $processed_posts++;
        }
        wp_reset_postdata();
    }
    
    // Performance logging
    $total_time = (microtime(true) - $start_time) * 1000;
    $timings['total_execution'] = $total_time;
    $timings['processed_posts'] = $processed_posts;
    $timings['qr_generation'] = $qr_generation_time;
    $timings['image_processing'] = $image_processing_time;
    $timings['results_count'] = count($results);
    
    // Add debug info to results if WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $results['_debug_timing'] = $timings;
    }
    
    return $results;
}

// REST API endpoint for gallery images
add_action('rest_api_init', function () {
    register_rest_route('digsign/v1', '/slides', [
        'methods' => 'GET',
        'callback' => function () {
            $category_name     = get_option('digsign_category_name', 'news');
            $refresh_interval  = intval(get_option('digsign_refresh_interval', 10));
            $slide_delay       = absint(get_option('digsign_slide_delay', 5));
            $enable_qrcodes    = (bool)get_option('digsign_enable_qrcodes', true);
            $layout_type       = get_option('digsign_layout_type', 'fullscreen');
            $slides = digsign_collect_media([
                'category_name' => $category_name,
                'image_size'    => 'digsign-gallery-thumb',
                'include_html'  => true,
                'include_qr'    => $enable_qrcodes,
                'structure'     => 'slides'
            ]);
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
            $category_name = get_option('digsign_category_name', 'news');
            // Legacy endpoint only returns image URLs (no HTML, no QR codes)
            $images = digsign_collect_media([
                'category_name' => $category_name,
                'image_size'    => 'digsign-gallery-thumb',
                'include_html'  => false,
                'include_qr'    => false,
                'structure'     => 'urls'
            ]);
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
    $slide_delay = absint(get_option('digsign_slide_delay', 5));
    $enable_qrcodes = (bool)get_option('digsign_enable_qrcodes', true);
    $layout_type = get_option('digsign_layout_type', 'fullscreen');
    $header_content = get_option('digsign_header_content', '');
    $right_panel_post_id = intval(get_option('digsign_right_panel_content', 0));
    $right_panel_content = '';
    if ($right_panel_post_id) {
        $post = get_post($right_panel_post_id);
        if ($post && $post->post_status === 'publish') {
            $right_panel_content = apply_filters('the_content', $post->post_content);
        }
    }
    
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
        absint(max(1, $refresh_interval)) * 1000,
        absint(max(1, $slide_delay)) * 1000,
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
                <?php echo wp_kses_post($right_panel_content); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}
