<?php
/*
Plugin Name: Immich Gallery
Description: Show Immich albums and photos in a WordPress site using shortcodes. Requires Immich server with API access.
Version: 0.1
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
        add_shortcode('immich_gallery', [$this, 'render_gallery']);
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
            
            /* Remove blue focus outline */
            .immich-lightbox {
                outline: none !important;
                text-decoration: none !important;
            }
            
            .immich-lightbox:focus {
                outline: none !important;
                box-shadow: none !important;
            }
            
            /* Album grid hover effects and shadows */
            .immich-grid > div {
                transition: all 0.3s ease;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                border-radius: 8px;
                overflow: hidden;
                background: white;
            }
            
            .immich-grid > div:hover {
                transform: translateY(-8px);
                box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            }
            
            .immich-grid > div img {
                transition: transform 0.3s ease;
            }
            
            .immich-grid > div:hover img {
                transform: scale(1.05);
            }
            
            .immich-grid > div a {
                display: block;
                text-decoration: none;
                color: inherit;
            }
            
            .immich-grid > div > div {
                padding: 10px;
            }
            
            /* Album photo grid specific styles */
            .immich-album-grid > div {
                transition: all 0.3s ease;
                border-radius: 6px;
                overflow: hidden;
            }
            
            .immich-album-grid > div:hover {
                transform: translateY(-4px);
                box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            }
            
            .immich-album-grid > div img {
                transition: transform 0.3s ease;
            }
            
            .immich-album-grid > div:hover img {
                transform: scale(1.03);
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
        $albums = $atts['albums'] ?? [];
        if ($albums) {
            $albums = explode(',', $albums);
        }
        $album = $_GET['immich_gallery'] ?? ($atts['album'] ?? '');
        $asset = $atts['asset'] ?? '';
        $show = $atts['show'] ?? [];
        if ($show) {
            $show = explode(',', $show);
        }

        if ($asset) {
            // Direct link to single asset
            if (!$show) $show = ['asset_description'];

            $asset = $this->api_request('assets/' . $asset);
            // error_log(print_r($asset, true));

            if (!$asset || empty($asset['id'])) return '<p>' . __('Photo not found.', 'immich-gallery') . '</p>';

            $thumb_url = plugins_url('immich-gallery-thumbnail.php', __FILE__) . '?id=' . $asset['id'];
            $full_url  = plugins_url('immich-gallery-original.php', __FILE__) . '?id=' . $asset['id'];

            $html = '<div>';
            
            // Prepare description for lightbox
            $description = '';
            if (!empty($asset['exifInfo']['description'])) {
                $description = esc_attr($asset['exifInfo']['description']);
            }
            if (!empty($asset['exifInfo']['dateTimeOriginal'])) {
                $date = date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
                if ($description) {
                    $description .= ' • ' . $date;
                } else {
                    $description = $date;
                }
            }
            
            $html .= '<a href="' . esc_url($full_url) . '" class="immich-lightbox" 
                        data-gallery="asset-' . esc_attr($asset['id']) . '">
                        <img src="' . esc_url($thumb_url) . '" style="max-width:100%;border-radius:6px;margin-bottom:15px;">
                        </a>';
            if (in_array('asset_date', $show) && !empty($asset['exifInfo']['dateTimeOriginal'])) {
                $date = date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
                $html .= '<div>' . esc_html($date) . '</div>';
            }
            if (in_array('asset_description', $show)) {
                $html .= '<div>' . esc_html($asset['exifInfo']['description'] ?? '') . '</div>';
            }
            $html .= '</div>';

            return $html;
        } elseif ($album) {
            // Detail page
            if (!$show) $show = ['gallery_name', 'gallery_description', 'asset_description'];

            $album = $this->api_request('albums/' . $album);
            // error_log(print_r($album, true));

            if (!$album || empty($album['assets'])) return '<p>' . __('No photos found in this album.', 'immich-gallery') . '</p>';

            // Sort album photos by dateTimeOriginal in ascending order (oldest first)
            $assets_to_render = $album['assets'];
            usort($assets_to_render, function($a, $b) {
                $dateA = $a['exifInfo']['dateTimeOriginal'] ?? '';
                $dateB = $b['exifInfo']['dateTimeOriginal'] ?? '';
                
                // If both have dateTimeOriginal, compare them
                if ($dateA && $dateB) {
                    return strcmp($dateA, $dateB); // Ascending order (oldest first)
                }
                
                // If only one has dateTimeOriginal, prioritize the one with date
                if ($dateA && !$dateB) return -1;
                if (!$dateA && $dateB) return 1;
                
                // If neither has dateTimeOriginal, maintain original order
                return 0;
            });

            if (in_array('gallery_name', $show)) {
                $html = '<h2>' . esc_html($album['albumName']) . '</h2>';
            }
            if (in_array('gallery_description', $show) && !empty($album['description'])) {
                $html .= '<p>' . esc_html($album['description']) . '</p>';
            }
            $html .= '<div class="immich-grid immich-album-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:15px;">';

            foreach ($assets_to_render as $asset) {
                if (empty($asset['id'])) continue;
                $thumb_url = plugins_url('immich-gallery-thumbnail.php', __FILE__) . '?id=' . $asset['id'];
                $full_url  = plugins_url('immich-gallery-original.php', __FILE__) . '?id=' . $asset['id'];
                
                // Prepare description for lightbox
                $description = '';
                if (!empty($asset['exifInfo']['description'])) {
                    $description = esc_attr($asset['exifInfo']['description']);
                }
                if (!empty($asset['exifInfo']['dateTimeOriginal'])) {
                    $date = date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
                    if ($description) {
                        $description .= ' • ' . $date;
                    } else {
                        $description = $date;
                    }
                }
                
                $html .= '<div>';
                $html .= '<a href="' . esc_url($full_url) . '" class="immich-lightbox" 
                            data-gallery="album-' . esc_attr($album['id']) . '">
                            <img src="' . esc_url($thumb_url) . '" style="width:100%;height:200px;object-fit:cover;border-radius:6px;display:block;">
                          </a>';
                if (in_array('asset_date', $show) && !empty($asset['exifInfo']['dateTimeOriginal'])) {
                    $date = date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
                    $html .= '<div style="text-align:center;margin-top:8px;font-size:0.9em;">' . esc_html($date) . '</div>';
                }
                if (in_array('asset_description', $show)) {
                    $html .= '<div style="text-align:center;margin-top:5px;font-size:0.9em;color:#666;">' . esc_html($asset['exifInfo']['description'] ?? '') . '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
            //$html .= '<p><a href="' . get_permalink() . '">&larr; ' . __('Back to overview', 'immich-gallery') . '</a></p>';

            return $html;
        } else {
            // Overview page
            if (!$show) $show = ['gallery_name', 'gallery_description'];

            $immich_albums = $this->api_request('albums');
            // error_log(print_r($immich_albums, true));

            // Check for API error
            if (isset($immich_albums['error']) && $immich_albums['error']) {
                return '<p>' . __('Error from Immich API: ', 'immich-gallery') . esc_html($immich_albums['error']) . ': ' . esc_html($immich_albums['message']) . '</p>';
            }

            if (!$immich_albums) {
                return '<p>' . __('No albums found.', 'immich-gallery') . '</p>';
            }

            $html = '<div class="immich-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px;">';
            
            // Determine which albums to render and in what order
            $albums_to_render = [];
            if (is_array($albums) && count($albums) > 0) {
                // Render albums in the order specified in $albums array
                foreach ($albums as $album_id) {
                    // Find the album in $immich_albums by ID
                    foreach ($immich_albums as $immich_album) {
                        if ($immich_album['id'] === $album_id) {
                            $albums_to_render[] = $immich_album;
                            break;
                        }
                    }
                }
            } else {
                // Show all albums from Immich, sorted by endDate (newest first)
                $albums_to_render = $immich_albums;
                
                // Sort albums by endDate in descending order (newest first)
                usort($albums_to_render, function($a, $b) {
                    $endDateA = $a['endDate'] ?? '';
                    $endDateB = $b['endDate'] ?? '';
                    
                    // If both have endDate, compare them
                    if ($endDateA && $endDateB) {
                        return strcmp($endDateB, $endDateA); // Descending order
                    }
                    
                    // If only one has endDate, prioritize the one with endDate
                    if ($endDateA && !$endDateB) return -1;
                    if (!$endDateA && $endDateB) return 1;
                    
                    // If neither has endDate, maintain original order
                    return 0;
                });
            }

            // Render the albums
            foreach ($albums_to_render as $album) {
                if (empty($album['albumThumbnailAssetId'])) continue;
                $thumb_url = plugins_url('immich-gallery-thumbnail.php', __FILE__) . '?id=' . $album['albumThumbnailAssetId'];

                $html .= '<div>';
                $html .= '<a href="' . get_permalink() . '?immich_gallery=' . esc_attr($album['id']) . '">
                        <img src="' . esc_url($thumb_url) . '" style="width:100%;height:200px;object-fit:cover;display:block;"></a>';
                
                if (in_array('gallery_name', $show) || in_array('gallery_description', $show)) {
                    $html .= '<div style="text-align:center;">';
                    if (in_array('gallery_name', $show)) {
                        $html .= '<a href="' . get_permalink() . '?immich_gallery=' . esc_attr($album['id']) . '">
                                <div style="font-weight:bold;margin-bottom:5px;">' . esc_html($album['albumName']) . '</div></a>';
                    }
                    if (in_array('gallery_description', $show)) {
                        $html .= '<div style="font-size:0.9em;color:#666;">' . esc_html($album['description']) . '</div>';
                    }
                    $html .= '</div>';
                }
                
                $html .= '</div>';

            }
            $html .= '</div>';
            return $html;
        }
    }
}

new Immich_Gallery();
