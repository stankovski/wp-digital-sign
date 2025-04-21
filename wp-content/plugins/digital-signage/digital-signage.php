<?php
/*
Plugin Name: Digital Signage
Description: Adds a page that displays a digital signage gallery of images from your WordPress posts.
Version: 1.0.0
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
define('DSP_QRCODE_DIR', WP_CONTENT_DIR . '/uploads/dsp-qrcodes/');
define('DSP_QRCODE_URL', content_url('/uploads/dsp-qrcodes/'));

// Create QR code directory if it doesn't exist
function dsp_create_qrcode_dir() {
    if (!file_exists(DSP_QRCODE_DIR)) {
        wp_mkdir_p(DSP_QRCODE_DIR);
        // Create .htaccess to allow direct access to QR code images
        $htaccess = "# Allow direct access to QR code images\n";
        $htaccess .= "<IfModule mod_rewrite.c>\n";
        $htaccess .= "RewriteEngine Off\n";
        $htaccess .= "</IfModule>\n";
        file_put_contents(DSP_QRCODE_DIR . '.htaccess', $htaccess);
    }
}
register_activation_hook(__FILE__, 'dsp_create_qrcode_dir');

// Generate QR code for a post URL
function dsp_generate_qrcode($post_id) {
    $post_url = get_permalink($post_id);
    $filename = 'qr-' . md5($post_url) . '.png';
    $file_path = DSP_QRCODE_DIR . $filename;
    $file_url = DSP_QRCODE_URL . $filename;
    
    // Check if QR code exists already
    if (!file_exists($file_path)) {
        // Create directory if it doesn't exist (in case it was deleted)
        dsp_create_qrcode_dir();
        
        // Generate QR code
        $options = [
            'w' => 200,  // Width
            'h' => 200,  // Height
            'bc' => 'FFFFFF', // Background color
            'fc' => '000000'  // Foreground color
        ];
        
        $qr = new QRCode($post_url, $options);
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
function dsp_register_image_sizes() {
    $width = intval(get_option('dsp_image_width', 1260));
    $height = intval(get_option('dsp_image_height', 940));
    
    // Remove previously registered size if it exists
    if (has_image_size('dsp-gallery-thumb')) {
        remove_image_size('dsp-gallery-thumb');
    }
    
    // Register with current settings values
    add_image_size('dsp-gallery-thumb', $width, $height, false);
}

// Call directly during plugin initialization
dsp_register_image_sizes(); 

// Hook to option updates to regenerate image sizes when settings change
add_action('update_option_dsp_image_width', 'dsp_register_image_sizes');
add_action('update_option_dsp_image_height', 'dsp_register_image_sizes');

// Register custom rewrite endpoint
function dsp_add_gallery_rewrite() {
    add_rewrite_rule('^digital-signage/?$', 'index.php?dsp_gallery=1', 'top');
}
add_action('init', 'dsp_add_gallery_rewrite');

// Flush rewrite rules on plugin activation
function dsp_activate_plugin() {
    dsp_add_gallery_rewrite();
    flush_rewrite_rules();
    dsp_create_qrcode_dir();
}
register_activation_hook(__FILE__, 'dsp_activate_plugin');

// Optional: Flush rewrite rules on plugin deactivation
function dsp_deactivate_plugin() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'dsp_deactivate_plugin');

// Register query var
function dsp_add_query_vars($vars) {
    $vars[] = 'dsp_gallery';
    return $vars;
}
add_filter('query_vars', 'dsp_add_query_vars');

// Template redirect for gallery page
function dsp_template_redirect() {
    if (get_query_var('dsp_gallery')) {
        dsp_render_gallery_page();
        exit;
    }
}
add_action('template_redirect', 'dsp_template_redirect');

// --- Settings Page ---
require_once plugin_dir_path(__FILE__) . 'settings.php';

// REST API endpoint for gallery images
add_action('rest_api_init', function () {
    register_rest_route('dsp/v1', '/slides', [
        'methods' => 'GET',
        'callback' => function () {
            $category_name = get_option('dsp_category_name', 'news');
            $width = intval(get_option('dsp_image_width', 1260));
            $height = intval(get_option('dsp_image_height', 940));
            $image_size = 'dsp-gallery-thumb';
            $refresh_interval = intval(get_option('dsp_refresh_interval', 10));
            $slide_delay = intval(get_option('dsp_slide_delay', 5));
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
                    
                    // Generate QR code for this post
                    $qr_code_url = dsp_generate_qrcode($post_id);
                    
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
            return rest_ensure_response([
                'slides' => $slides,
                'settings' => [
                    'refresh_interval' => $refresh_interval,
                    'slide_delay' => $slide_delay
                ]
            ]);
        },
        'permission_callback' => '__return_true'
    ]);
});

// Render gallery HTML
function dsp_render_gallery_page() {
    $width = intval(get_option('dsp_image_width', 1260));
    $height = intval(get_option('dsp_image_height', 940));
    $category_name = esc_html(get_option('dsp_category_name', 'news'));
    $refresh_interval = intval(get_option('dsp_refresh_interval', 10));
    $slide_delay = intval(get_option('dsp_slide_delay', 5));
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Digital Signage</title>
        <style>
            html, body {
                height: 100%;
                margin: 0;
                padding: 0;
                overflow: hidden;
            }
            body { 
                font-family: Arial, sans-serif; 
                margin: 0;
                padding: 0;
            }
            .main-content {
                margin: 0;
                padding: 0;
                height: 100%;
            }
            .gallery {
                display: flex;
                gap: 20px;
                justify-content: center;
                align-items: center;
                min-height: <?php echo esc_attr($height); ?>px;
                margin: 0;
                padding: 0;
            }
            .gallery .slide {
                width: <?php echo esc_attr($width); ?>px;
                height: <?php echo esc_attr($height); ?>px;
                max-width: 100%;
                max-height: 100%;
                border-radius: 0px;
                display: none;
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                overflow: hidden;
                position: relative;
            }
            .gallery img {
                width: 100%;
                height: 100%;
                object-fit: contain;
                max-height: 100%;
            }
            .gallery .slide.active {
                display: flex;
                justify-content: center;
                align-items: center;
            }
            .gallery .html-content {
                overflow: auto;
                background: #fff;
                padding: 20px;
                max-height: <?php echo esc_attr($height - 40); ?>px; /* Accounting for padding */
                width: 100%;
                box-sizing: border-box;
            }
            .gallery .html-content h2 {
                margin-top: 0;
            }
            .qrcode-overlay {
                position: absolute;
                bottom: 20px;
                left: 20px;
                width: 100px;
                height: 100px;
                background-color: rgba(255, 255, 255, 0.7);
                padding: 5px;
                border-radius: 5px;
                z-index: 10;
            }
            .qrcode-overlay img {
                width: 100%;
                height: 100%;
                object-fit: contain;
            }
        </style>
    </head>
    <body>
        <div class="main-content">
            <div class="gallery" id="dsp-carousel">
                <p id="dsp-loading">Loading content...</p>
            </div>
        </div>
        <script>
        // Async load images and run carousel
        (function() {
            var carousel = document.getElementById('dsp-carousel');
            var loading = document.getElementById('dsp-loading');
            var slideEls = [];
            var idx = 0;
            var carouselInterval = null;
            // Initial default values until first API response
            var refreshInterval = <?php echo esc_js(max(1, $refresh_interval) * 1000); ?>;
            var slideDelay = <?php echo esc_js(max(1, $slide_delay) * 1000); ?>;

            function startCarousel() {
                if (carouselInterval) clearInterval(carouselInterval);
                if (slideEls.length < 2) return;
                carouselInterval = setInterval(function() {
                    slideEls[idx].classList.remove('active');
                    idx = (idx + 1) % slideEls.length;
                    slideEls[idx].classList.add('active');
                }, slideDelay);
            }

            function renderSlides(data) {
                // Update intervals if provided in response
                if (data.settings) {
                    if (data.settings.refresh_interval) {
                        refreshInterval = Math.max(1, data.settings.refresh_interval) * 1000;
                    }
                    if (data.settings.slide_delay) {
                        slideDelay = Math.max(1, data.settings.slide_delay) * 1000;
                    }
                }
                
                // Get slides from response
                var slides = data.slides || [];
                
                // Remove old slides
                slideEls.forEach(function(slide) { slide.remove(); });
                slideEls = [];
                if (!slides || !slides.length) {
                    if (!loading) {
                        loading = document.createElement('p');
                        loading.id = 'dsp-loading';
                        carousel.appendChild(loading);
                    }
                    /* translators: %s: category name */
                    loading.textContent = <?php echo wp_json_encode(sprintf(__('No content found for category "%s".', 'digital-signage'), $category_name)); ?>;
                    return;
                }
                if (loading) loading.remove();
                
                slides.forEach(function(slide, i) {
                    var slideEl = document.createElement('div');
                    slideEl.classList.add('slide');
                    
                    if (slide.type === 'image') {
                        var img = document.createElement('img');
                        img.src = slide.content;
                        img.alt = slide.post_title || 'Gallery Image';
                        slideEl.appendChild(img);
                    } else if (slide.type === 'html') {
                        var contentDiv = document.createElement('div');
                        contentDiv.classList.add('html-content');
                        if (slide.title) {
                            var title = document.createElement('h2');
                            title.textContent = slide.title;
                            contentDiv.appendChild(title);
                        }
                        var contentContainer = document.createElement('div');
                        contentContainer.innerHTML = slide.content;
                        contentDiv.appendChild(contentContainer);
                        slideEl.appendChild(contentDiv);
                    }
                    
                    // Add QR code overlay
                    if (slide.qrcode) {
                        var qrDiv = document.createElement('div');
                        qrDiv.classList.add('qrcode-overlay');
                        var qrImg = document.createElement('img');
                        qrImg.src = slide.qrcode;
                        qrImg.alt = 'Scan for more information';
                        qrDiv.appendChild(qrImg);
                        slideEl.appendChild(qrDiv);
                    }
                    
                    carousel.appendChild(slideEl);
                    slideEls.push(slideEl);
                });
                
                // Reset index if out of bounds
                if (idx >= slideEls.length) idx = 0;
                slideEls.forEach(function(slide, i) {
                    slide.className = 'slide' + (i === idx ? ' active' : '');
                });
                startCarousel();
            }

            function fetchContent() {
                fetch(<?php echo wp_json_encode(esc_url_raw(rest_url('dsp/v1/slides'))); ?>)
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        renderSlides(data);
                    })
                    .catch(function() {
                        if (loading) loading.textContent = <?php echo wp_json_encode(__('Failed to load content.', 'digital-signage')); ?>;
                    });
            }

            fetchContent();
            setInterval(fetchContent, refreshInterval);
        })();
        </script>
    </body>
    </html>
    <?php
}
