<?php
/**
 * Plugin Name: Gallery for Immich
 * Plugin URI: https://github.com/vogon1/immich-wordpress-plugin
 * Description: Show Immich albums and photos in a WordPress site using shortcodes. Requires Immich server with API access.
 * Version: 0.3.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Sietse Visser
 * Author URI: https://github.com/vogon1
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: gallery-for-immich
 * Domain Path: /languages
 */

// Plugin description for translations - WordPress will pick this up
if (!function_exists('gallery_for_immich_get_plugin_description')) {
    function gallery_for_immich_get_plugin_description() {
        return __('Show Immich albums and photos in a WordPress site using shortcodes. Requires Immich server with API access.', 'gallery-for-immich');
    }
}

if (!defined('ABSPATH')) exit;

class Gallery_For_Immich {
    private $option_name = 'gallery_for_immich_settings';

    public function __construct() {
        // Note: load_plugin_textdomain() not needed - WordPress.org automatically loads translations since WP 4.6
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_shortcode('gallery_for_immich', [$this, 'render_gallery']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('init', [$this, 'handle_image_proxy']);
        
        // Plugin description translation for plugin list
        add_filter('all_plugins', [$this, 'translate_plugin_description']);
    }

    public function translate_plugin_description($plugins) {
        $plugin_file = plugin_basename(__FILE__);
        
        if (isset($plugins[$plugin_file])) {
            // Translate the name and description
            $plugins[$plugin_file]['Name'] = __('Gallery for Immich', 'gallery-for-immich');
            $plugins[$plugin_file]['Description'] = __('Show Immich albums and photos in a WordPress site using shortcodes. Requires Immich server with API access.', 'gallery-for-immich');
        }
        
        return $plugins;
    }

    /* --- Image proxy handler --- */
    public function handle_image_proxy() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public image proxy endpoint
        if (!isset($_GET['gallery_for_immich_proxy'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated below with strict whitelist
        $type = isset($_GET['gallery_for_immich_proxy']) ? sanitize_text_field(wp_unslash($_GET['gallery_for_immich_proxy'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated with UUID regex below
        $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';

        // Validate type with strict whitelist
        if (!in_array($type, ['thumbnail', 'original'], true)) {
            status_header(400);
            exit('Invalid type');
        }

        // Validate ID: must be UUID format
        if (!$id || !preg_match('/^[a-f0-9\-]{36}$/i', $id)) {
            status_header(400);
            exit('Invalid ID format');
        }

        $options = get_option($this->option_name);
        
        // Validate plugin is configured
        if (empty($options['server_url']) || empty($options['api_key'])) {
            status_header(503);
            exit('Plugin not configured');
        }

        // Build URL based on type
        if ($type === 'thumbnail') {
            $url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/thumbnail?w=300&h=300';
            $timeout = 10;
        } else {
            $url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/original';
            $timeout = 30;
        }

        // Fetch image from Immich
        $resp = wp_remote_get($url, [
            'headers' => ['x-api-key' => $options['api_key']],
            'timeout' => $timeout,
            'sslverify' => true
        ]);

        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) != 200) {
            status_header(404);
            exit('Image not found');
        }

        // Security headers
        header('Content-Type: image/jpeg');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000');
        
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image data
        echo wp_remote_retrieve_body($resp);
        exit;
    }

    /* --- Admin settings --- */
    public function add_admin_menu() {
        add_options_page(__('Gallery for Immich', 'gallery-for-immich'), __('Gallery for Immich', 'gallery-for-immich'), 'manage_options', 'gallery_for_immich', [$this, 'options_page']);
    }

    public function settings_init() {
        register_setting('gallery_for_immich', $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section('gallery_for_immich_section', __('Settings', 'gallery-for-immich'), null, 'gallery_for_immich');

        add_settings_field('server_url', __('Immich server URL', 'gallery-for-immich'), [$this, 'field_server_url'], 'gallery_for_immich', 'gallery_for_immich_section');
        add_settings_field('api_key', __('API Key', 'gallery-for-immich'), [$this, 'field_api_key'], 'gallery_for_immich', 'gallery_for_immich_section');
    }

    public function sanitize_settings($input) {
        $sanitized = [];
        
        // Sanitize and validate server URL
        if (!empty($input['server_url'])) {
            $url = esc_url_raw($input['server_url']);
            // Ensure it's HTTPS in production (or localhost for dev)
            if (strpos($url, 'https://') === 0 || strpos($url, 'http://localhost') === 0 || strpos($url, 'http://127.0.0.1') === 0) {
                $sanitized['server_url'] = rtrim($url, '/');
            } else {
                add_settings_error(
                    $this->option_name,
                    'invalid_url',
                    __('Server URL must use HTTPS (or localhost for development).', 'gallery-for-immich')
                );
            }
        }
        
        // Sanitize API key - only allow alphanumeric and common special chars
        if (!empty($input['api_key'])) {
            $api_key = sanitize_text_field($input['api_key']);
            // Validate format (basic alphanumeric check)
            if (preg_match('/^[a-zA-Z0-9_\-\.]+$/', $api_key)) {
                $sanitized['api_key'] = $api_key;
            } else {
                add_settings_error(
                    $this->option_name,
                    'invalid_api_key',
                    __('API Key contains invalid characters.', 'gallery-for-immich')
                );
            }
        }
        
        return $sanitized;
    }

    public function field_server_url() {
        $options = get_option($this->option_name);
        ?>
        <input type="url" name="<?php echo esc_attr($this->option_name); ?>[server_url]" value="<?php echo esc_attr($options['server_url'] ?? ''); ?>" style="width:400px;" placeholder="https://immich.example.com">
        <?php
    }

    public function field_api_key() {
        $options = get_option($this->option_name);
        ?>
        <input type="password" name="<?php echo esc_attr($this->option_name); ?>[api_key]" value="<?php echo esc_attr($options['api_key'] ?? ''); ?>" style="width:400px;" autocomplete="off">
        <?php
    }

    public function options_page() {
        // Double-check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'gallery-for-immich'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Gallery for Immich', 'gallery-for-immich'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('gallery_for_immich');
                do_settings_sections('gallery_for_immich');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* --- Scripts and CSS for Lightbox --- */
    public function enqueue_scripts() {
        wp_enqueue_style('gallery-for-immich-glightbox-css', plugins_url('assets/glightbox/css/glightbox.min.css', __FILE__), [], '3.2.0');
        wp_enqueue_script('gallery-for-immich-glightbox-js', plugins_url('assets/glightbox/js/glightbox.min.js', __FILE__), ['jquery'], '3.2.0', true);

        // JS config for Lightbox - always create new instance with unique selector
        wp_add_inline_script('gallery-for-immich-glightbox-js', '
            (function() {
                if (typeof GLightbox === "undefined") {
                    console.warn("Gallery for Immich: GLightbox library not loaded");
                    return;
                }
                
                window.galleryForImmichLightbox = GLightbox({
                    selector: ".immich-lightbox",
                    touchNavigation: true,
                    loop: false,
                    zoomable: false,
                    openEffect: "zoom",
                    closeEffect: "fade",
                    slideEffect: "slide",
                    type: "image"
                });
            })();
        ');

        // CSS fix for full scale, centered, maintaining aspect ratio
        wp_add_inline_style('gallery-for-immich-glightbox-css', '
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
        
        // Validate options exist
        if (empty($options['server_url']) || empty($options['api_key'])) {
            return ['error' => true, 'message' => __('Plugin not configured. Please set Server URL and API Key in settings.', 'gallery-for-immich')];
        }
        
        $url = rtrim($options['server_url'], '/') . '/api/' . ltrim($endpoint, '/');

        $response = wp_remote_get($url, [
            'headers' => [
                'x-api-key' => $options['api_key'],
                'User-Agent' => 'WordPress-Gallery-For-Immich/' . get_bloginfo('version')
            ],
            'timeout' => 15,
            'sslverify' => true // Enforce SSL verification
        ]);

        if (is_wp_error($response)) {
            return ['error' => true, 'message' => $response->get_error_message()];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return ['error' => true, 'message' => 'API returned status code: ' . $status_code];
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /* --- Shortcode: overview + detail --- */
    public function render_gallery($atts) {
        // Sanitize and validate all input parameters
        $albums = $atts['albums'] ?? [];
        if ($albums) {
            $albums = array_map('sanitize_text_field', explode(',', $albums));
            // Validate each album ID is UUID format
            $albums = array_filter($albums, function($id) {
                return preg_match('/^[a-f0-9\-]{36}$/i', trim($id));
            });
        }
        
        // Validate album parameter from GET or shortcode
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public gallery navigation doesn't require nonce
        $album = sanitize_text_field(wp_unslash($_GET['gallery_for_immich'] ?? ($atts['album'] ?? '')));
        if ($album && !preg_match('/^[a-f0-9\-]{36}$/i', $album)) {
            $album = ''; // Invalid format, ignore
        }
        
        // Validate asset parameter
        $asset = sanitize_text_field($atts['asset'] ?? '');
        if ($asset && !preg_match('/^[a-f0-9\-]{36}$/i', $asset)) {
            $asset = ''; // Invalid format, ignore
        }
        
        // Sanitize show parameter - only allow specific values
        $show = $atts['show'] ?? [];
        if ($show) {
            $allowed_show = ['gallery_name', 'gallery_description', 'asset_date', 'asset_description'];
            $show = array_map('sanitize_text_field', explode(',', $show));
            $show = array_filter($show, function($item) use ($allowed_show) {
                return in_array(trim($item), $allowed_show);
            });
        }

        if ($asset) {
            // Direct link to single asset
            if (!$show) $show = ['asset_description'];

            $asset = $this->api_request('assets/' . $asset);
            // error_log(print_r($asset, true));

            if (!$asset || empty($asset['id'])) return '<p>' . __('Photo not found.', 'gallery-for-immich') . '</p>';

            $thumb_url = home_url('/?gallery_for_immich_proxy=thumbnail&id=' . $asset['id']);
            $full_url  = home_url('/?gallery_for_immich_proxy=original&id=' . $asset['id']);

            $html = '<div>';
            
            // Prepare description for lightbox
            $description = '';
            if (!empty($asset['exifInfo']['description'])) {
                $description = esc_attr($asset['exifInfo']['description']);
            }
            if (!empty($asset['exifInfo']['dateTimeOriginal'])) {
                $date = wp_date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
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
                $date = wp_date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
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

            if (!$album || empty($album['assets'])) return '<p>' . __('No photos found in this album.', 'gallery-for-immich') . '</p>';

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

            $html = '';
            if (in_array('gallery_name', $show)) {
                $html .= '<h2>' . esc_html($album['albumName']) . '</h2>';
            }
            if (in_array('gallery_description', $show) && !empty($album['description'])) {
                $html .= '<p>' . esc_html($album['description']) . '</p>';
            }
            $html .= '<div class="immich-grid immich-album-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:15px;">';

            foreach ($assets_to_render as $asset) {
                if (empty($asset['id'])) continue;
                $thumb_url = home_url('/?gallery_for_immich_proxy=thumbnail&id=' . $asset['id']);
                $full_url  = home_url('/?gallery_for_immich_proxy=original&id=' . $asset['id']);
                
                // Prepare description for lightbox
                $description = '';
                if (!empty($asset['exifInfo']['description'])) {
                    $description = esc_attr($asset['exifInfo']['description']);
                }
                if (!empty($asset['exifInfo']['dateTimeOriginal'])) {
                    $date = wp_date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
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
                    $date = wp_date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
                    $html .= '<div style="text-align:center;margin-top:8px;font-size:0.9em;">' . esc_html($date) . '</div>';
                }
                if (in_array('asset_description', $show)) {
                    $html .= '<div style="text-align:center;margin-top:5px;font-size:0.9em;color:#666;">' . esc_html($asset['exifInfo']['description'] ?? '') . '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
            //$html .= '<p><a href="' . get_permalink() . '">&larr; ' . __('Back to overview', 'gallery-for-immich') . '</a></p>';

            return $html;
        } else {
            // Overview page
            if (!$show) $show = ['gallery_name', 'gallery_description'];

            $immich_albums = $this->api_request('albums');
            // error_log(print_r($immich_albums, true));

            // Check for API error
            if (isset($immich_albums['error']) && $immich_albums['error']) {
                return '<p>' . __('Error from Immich API: ', 'gallery-for-immich') . esc_html($immich_albums['error']) . ': ' . esc_html($immich_albums['message']) . '</p>';
            }

            if (!$immich_albums) {
                return '<p>' . __('No albums found.', 'gallery-for-immich') . '</p>';
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
                $thumb_url = home_url('/?gallery_for_immich_proxy=thumbnail&id=' . $album['albumThumbnailAssetId']);

                $html .= '<div>';
                $html .= '<a href="' . get_permalink() . '?gallery_for_immich=' . esc_attr($album['id']) . '">
                        <img src="' . esc_url($thumb_url) . '" style="width:100%;height:200px;object-fit:cover;display:block;"></a>';
                
                if (in_array('gallery_name', $show) || in_array('gallery_description', $show)) {
                    $html .= '<div style="text-align:center;">';
                    if (in_array('gallery_name', $show)) {
                        $html .= '<a href="' . get_permalink() . '?gallery_for_immich=' . esc_attr($album['id']) . '">
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

new Gallery_For_Immich();
