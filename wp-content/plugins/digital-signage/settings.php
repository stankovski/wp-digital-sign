<?php
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
}
add_action('admin_init', 'dsp_register_settings');

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
add_action('admin_menu', 'dsp_add_settings_page');

/**
 * Check if permalinks are set to plain
 * 
 * @return boolean True if permalinks are set to plain, false otherwise
 */
function dsp_has_plain_permalinks() {
    $permalink_structure = get_option('permalink_structure');
    return empty($permalink_structure);
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
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
