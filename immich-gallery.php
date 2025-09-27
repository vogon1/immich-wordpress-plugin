<?php
/*
Plugin Name: Immich Gallery
Description: Show Immich albums and photos in a WordPress site using shortcodes. Requires Immich server with API access.
Version: 0.7
Author: Sietse Visser
Text Domain: immich-gallery
Domain Path: /languages
*/

// Plugin description for translations - WordPress will pick this up
if (!function_exists('immich_gallery_get_plugin_description')) {
    function immich_gallery_get_plugin_description() {
        return __('Show Immich albums and photos in a WordPress site using shortcodes. Requires Immich server with API access.', 'immich-gallery');
    }
}

if (!defined('ABSPATH')) exit;

class Immich_Gallery {
    private $option_name = 'immich_gallery_settings';

    public function __construct() {
        add_action('init', [$this, 'load_textdomain'], 1);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_shortcode('immich_album', [$this, 'render_gallery']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Plugin description translation for plugin list
        add_filter('all_plugins', [$this, 'translate_plugin_description']);
    }

    public function translate_plugin_description($plugins) {
        $plugin_file = plugin_basename(__FILE__);
        
        if (isset($plugins[$plugin_file])) {
            // Translate the description
            $plugins[$plugin_file]['Description'] = __('Show Immich albums and photos in a WordPress site using shortcodes. Requires Immich server with API access.', 'immich-gallery');
        }
        
        return $plugins;
    }

    public function load_textdomain() {
        $loaded = load_plugin_textdomain('immich-gallery', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        return $loaded;
    }

    /* --- Admin settings --- */
    public function add_admin_menu() {
        add_options_page('Immich Gallery', 'Immich Gallery', 'manage_options', 'immich_gallery', [$this, 'options_page']);
    }

    public function settings_init() {
        register_setting('immich_gallery', $this->option_name);

        add_settings_section('immich_gallery_section', __('Settings', 'immich-gallery'), null, 'immich_gallery');

        add_settings_field('server_url', __('Immich server URL', 'immich-gallery'), [$this, 'field_server_url'], 'immich_gallery', 'immich_gallery_section');
        add_settings_field('api_key', __('API Key', 'immich-gallery'), [$this, 'field_api_key'], 'immich_gallery', 'immich_gallery_section');
    }

    public function field_server_url() {
        $options = get_option($this->option_name);
        ?>
        <input type="text" name="<?= $this->option_name ?>[server_url]" value="<?= esc_attr($options['server_url'] ?? '') ?>" style="width:400px;">
        <?php
    }

    public function field_api_key() {
        $options = get_option($this->option_name);
        ?>
        <input type="text" name="<?= $this->option_name ?>[api_key]" value="<?= esc_attr($options['api_key'] ?? '') ?>" style="width:400px;">
        <?php
    }

    public function options_page() {
        ?>
        <div class="wrap">
            <h1>Immich Gallery</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('immich_gallery');
                do_settings_sections('immich_gallery');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* --- Scripts and CSS for Lightbox --- */
    public function enqueue_scripts() {
        wp_enqueue_style('glightbox-css', 'https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css', [], '3.2.0');
        wp_enqueue_script('glightbox-js', 'https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js', ['jquery'], '3.2.0', true);

        // JS config for Lightbox
        wp_add_inline_script('glightbox-js', '
            const lightbox = GLightbox({
                selector: ".immich-lightbox",
                touchNavigation: true,
                loop: false,
                zoomable: true,
                openEffect: "zoom",
                closeEffect: "fade",
                slideEffect: "slide",
		slideZoom: true,
                type: "image"
            });
        ');

        // CSS fix for full scale, centered, maintaining aspect ratio
        wp_add_inline_style('glightbox-css', '
            .glightbox-container .gslide img {
                max-width: 95vw !important;
                max-height: 95vh !important;
                width: auto !important;
                heught: auto !important;
                object-fit: contain !important;
                display: block !important;
                margin 0 auto !important;
            }
        ');
    }

    /* --- API request helper --- */
    private function api_request($endpoint) {
        $options = get_option($this->option_name);
        $url = rtrim($options['server_url'], '/') . '/api/' . ltrim($endpoint, '/');

        $response = wp_remote_get($url, [
            'headers' => [
                'x-api-key' => $options['api_key']
            ]
        ]);

        if (is_wp_error($response)) return [];
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /* --- Shortcode: overview + detail --- */
    public function render_gallery($atts) {
        $options = get_option($this->option_name);
        $album_id = $_GET['immich_album'] ?? ($atts['id'] ?? '');

        if (!$album_id) {
            // Overview page
            $albums = $this->api_request('albums');
            if (!$albums) return '<p>' . __('No albums found or Immich not reachable.', 'immich-gallery') . '</p>';

            $html = '<div class="immich-grid" style="display:flex;flex-wrap:wrap;gap:20px;">';
            foreach ($albums as $album) {
                if (empty($album['albumThumbnailAssetId'])) continue;
                $thumb_url = plugins_url('immich-gallery-thumbnail.php', __FILE__) . '?id=' . $album['albumThumbnailAssetId'];
                $html .= '<div style="width:200px;text-align:center;">
                    <a href="' . get_permalink() . '?immich_album=' . esc_attr($album['id']) . '">
                        <img src="' . esc_url($thumb_url) . '" style="width:100%;border-radius:8px;">
                        <div>' . esc_html($album['albumName']) . '</div>
                    </a>
                </div>';
            }
            $html .= '</div>';
            return $html;

        } else {
            // Detail page
            $album = $this->api_request('albums/' . $album_id);
            if (!$album || empty($album['assets'])) return '<p>Geen foto’s gevonden in dit album.</p>';

            $html = '<h2>' . esc_html($album['albumName']) . '</h2>';
            $html .= '<div class="immich-grid" style="display:flex;flex-wrap:wrap;gap:10px;">';

            foreach ($album['assets'] as $asset) {
                if (empty($asset['id'])) continue;
                $thumb_url = plugins_url('immich-gallery-thumbnail.php', __FILE__) . '?id=' . $asset['id'];
                $full_url  = plugins_url('immich-gallery-original.php', __FILE__) . '?id=' . $asset['id'];
                $html .= '<a href="' . esc_url($full_url) . '" class="immich-lightbox" data-gallery="album-' . esc_attr($album['id']) . '">
                            <img src="' . esc_url($thumb_url) . '" style="width:200px;border-radius:6px;margin-bottom:5px;">
                          </a>';
            }
            $html .= '</div>';
            $html .= '<p><a href="' . get_permalink() . '">&larr; ' . __('Back to overview', 'immich-gallery') . '</a></p>';

            return $html;
        }
    }
}

new Immich_Gallery();
