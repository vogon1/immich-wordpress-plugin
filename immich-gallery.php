<?php
/**
 * Plugin Name: Immich Gallery
 * Plugin URI: https://github.com/vogon1/immich-wordpress-plugin
 * Description: Show Immich albums and photos in a WordPress site using shortcodes. Requires Immich server with API access.
 * Version: 0.4.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Sietse Visser
 * Author URI: https://github.com/vogon1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: immich-gallery
 * Domain Path: /languages
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
        // Load text domain for translations (required for JavaScript translations)
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_shortcode('immich_gallery', [$this, 'render_gallery']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Gutenberg block support
        add_action('init', [$this, 'register_block']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Plugin description translation for plugin list
        add_filter('all_plugins', [$this, 'translate_plugin_description']);
    }

    public function load_textdomain() {
        // Load plugin translations manually for standalone installations
        // WordPress.org automatically loads translations, but this ensures
        // compatibility when installed via GitHub or direct download
        load_plugin_textdomain(
            'immich-gallery',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    public function translate_plugin_description($plugins) {
        $plugin_file = plugin_basename(__FILE__);
        
        if (isset($plugins[$plugin_file])) {
            // Translate the description
            $plugins[$plugin_file]['Description'] = __('Show Immich albums and photos in a WordPress site using shortcodes. Requires Immich server with API access.', 'immich-gallery');
        }
        
        return $plugins;
    }

    /* --- Admin settings --- */
    public function add_admin_menu() {
        add_options_page('Immich Gallery', 'Immich Gallery', 'manage_options', 'immich_gallery', [$this, 'options_page']);
    }

    public function settings_init() {
        register_setting('immich_gallery', $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section('immich_gallery_section', __('Settings', 'immich-gallery'), null, 'immich_gallery');

        add_settings_field('server_url', __('Immich server URL', 'immich-gallery'), [$this, 'field_server_url'], 'immich_gallery', 'immich_gallery_section');
        add_settings_field('api_key', __('API Key', 'immich-gallery'), [$this, 'field_api_key'], 'immich_gallery', 'immich_gallery_section');
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
                    __('Server URL must use HTTPS (or localhost for development).', 'immich-gallery')
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
                    __('API Key contains invalid characters.', 'immich-gallery')
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
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'immich-gallery'));
        }
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
        wp_enqueue_style('glightbox-css', plugins_url('assets/glightbox/css/glightbox.min.css', __FILE__), [], '3.2.0');
        wp_enqueue_script('glightbox-js', plugins_url('assets/glightbox/js/glightbox.min.js', __FILE__), ['jquery'], '3.2.0', true);

        // JS config for Lightbox
        wp_add_inline_script('glightbox-js', '
            const lightbox = GLightbox({
                selector: ".immich-lightbox",
                touchNavigation: true,
                loop: false,
                zoomable: false,
                openEffect: "zoom",
                closeEffect: "fade",
                slideEffect: "slide",
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

    /* --- Gutenberg block registration --- */
    public function register_block() {
        // Register the block with compiled script
        wp_register_script(
            'immich-gallery-block',
            plugins_url('build/index.js', __FILE__),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch'],
            filemtime(plugin_dir_path(__FILE__) . 'build/index.js'),
            true // Load in footer
        );
        
        // Set translations for the block editor script
        wp_set_script_translations(
            'immich-gallery-block',
            'immich-gallery',
            plugin_dir_path(__FILE__) . 'languages'
        );
        
        register_block_type(__DIR__ . '/block.json', [
            'editor_script' => 'immich-gallery-block',
            'render_callback' => [$this, 'render_block']
        ]);
    }
    
    public function render_block($attributes) {
        // Convert block attributes to shortcode attributes
        $shortcode_atts = [];
        
        if (!empty($attributes['asset'])) {
            $shortcode_atts['asset'] = $attributes['asset'];
        }
        
        if (!empty($attributes['album'])) {
            $shortcode_atts['album'] = $attributes['album'];
        }
        
        if (!empty($attributes['albums']) && is_array($attributes['albums'])) {
            $shortcode_atts['albums'] = implode(',', $attributes['albums']);
        }
        
        if (!empty($attributes['show']) && is_array($attributes['show'])) {
            $shortcode_atts['show'] = implode(',', $attributes['show']);
        }
        
        if (!empty($attributes['order'])) {
            $shortcode_atts['order'] = $attributes['order'];
        }
        
        if (!empty($attributes['size'])) {
            $shortcode_atts['size'] = $attributes['size'];
        }
        
        if (!empty($attributes['title_size'])) {
            $shortcode_atts['title_size'] = $attributes['title_size'];
        }
        
        if (!empty($attributes['description_size'])) {
            $shortcode_atts['description_size'] = $attributes['description_size'];
        }
        
        if (!empty($attributes['date_size'])) {
            $shortcode_atts['date_size'] = $attributes['date_size'];
        }
        
        return $this->render_gallery($shortcode_atts);
    }
    
    /* --- REST API endpoint for block editor --- */
    public function register_rest_routes() {
        register_rest_route('immich-gallery/v1', '/albums', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_albums'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ]);
    }
    
    public function rest_get_albums() {
        $immich_albums = $this->api_request('albums');
        
        if (isset($immich_albums['error']) && $immich_albums['error']) {
            return new WP_Error('api_error', $immich_albums['message'], ['status' => 500]);
        }
        
        if (!$immich_albums) {
            return ['albums' => []];
        }
        
        // Format albums for the block editor
        $formatted_albums = array_map(function($album) {
            return [
                'id' => $album['id'],
                'name' => $album['albumName'] ?? __('Untitled Album', 'immich-gallery')
            ];
        }, $immich_albums);
        
        return ['albums' => $formatted_albums];
    }

    /* --- API request helper --- */
    private function api_request($endpoint) {
        $options = get_option($this->option_name);
        
        // Validate options exist
        if (empty($options['server_url']) || empty($options['api_key'])) {
            return ['error' => true, 'message' => __('Plugin not configured. Please set Server URL and API Key in settings.', 'immich-gallery')];
        }
        
        $url = rtrim($options['server_url'], '/') . '/api/' . ltrim($endpoint, '/');

        $response = wp_remote_get($url, [
            'headers' => [
                'x-api-key' => $options['api_key'],
                'User-Agent' => 'WordPress-Immich-Gallery/' . get_bloginfo('version')
            ],
            'timeout' => 15,
            'sslverify' => true // Enforce SSL verification
        ]);

        if (is_wp_error($response)) {
            return ['error' => true, 'message' => $response->get_error_message()];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            /* translators: %d: HTTP status code number */
            return ['error' => true, 'message' => sprintf(__('API returned status code: %d', 'immich-gallery'), $status_code)];
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public gallery view, no privileged action
        $album = sanitize_text_field(wp_unslash($_GET['immich_gallery'] ?? ($atts['album'] ?? '')));
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
        
        // Sanitize order parameter - only allow specific sort options
        // Default: date_desc for albums (newest first), date_asc for photos (oldest first/chronological)
        $default_order = $album ? 'date_asc' : 'date_desc';
        $order = sanitize_text_field($atts['order'] ?? $default_order);
        if (!in_array($order, ['date_asc', 'date_desc', 'name_asc', 'name_desc', 'description_asc', 'description_desc'])) {
            $order = $default_order;
        }
        
        // Sanitize size parameter - thumbnail size in pixels
        // Default: 200px, allowed range: 100-500
        $size = intval($atts['size'] ?? 200);
        if ($size < 100 || $size > 500) {
            $size = 200;
        }
        
        // Sanitize text size parameters - font sizes in pixels
        // Defaults: title=16, description=14, date=13
        $title_size = intval($atts['title_size'] ?? 16);
        if ($title_size < 10 || $title_size > 30) {
            $title_size = 16;
        }
        
        $description_size = intval($atts['description_size'] ?? 14);
        if ($description_size < 10 || $description_size > 30) {
            $description_size = 14;
        }
        
        $date_size = intval($atts['date_size'] ?? 13);
        if ($date_size < 10 || $date_size > 30) {
            $date_size = 13;
        }
        
        // Enable lazy loading for all images
        $lazy_attr = ' loading="lazy"';

        if ($asset) {
            // Direct link to single asset
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
                $date = wp_date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
                if ($description) {
                    $description .= ' • ' . $date;
                } else {
                    $description = $date;
                }
            }
            
            $html .= '<a href="' . esc_url($full_url) . '" class="immich-lightbox" 
                        data-gallery="asset-' . esc_attr($asset['id']) . '">
                        <img src="' . esc_url($thumb_url) . '" style="max-width:100%;border-radius:6px;margin-bottom:15px;"' . $lazy_attr . '>
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
            $album = $this->api_request('albums/' . $album);
            // error_log(print_r($album, true));

            if (!$album || empty($album['assets'])) return '<p>' . __('No photos found in this album.', 'immich-gallery') . '</p>';

            // Sort album photos based on order parameter
            // date_asc: oldest first (chronological), date_desc: newest first
            // description_asc: A-Z by description, description_desc: Z-A by description
            $assets_to_render = $album['assets'];
            
            usort($assets_to_render, function($a, $b) use ($order) {
                if ($order === 'description_asc' || $order === 'description_desc') {
                    // Sort by description
                    $descA = $a['exifInfo']['description'] ?? '';
                    $descB = $b['exifInfo']['description'] ?? '';
                    
                    if ($descA && $descB) {
                        $comparison = strcasecmp($descA, $descB);
                        return ($order === 'description_asc') ? $comparison : -$comparison;
                    }
                    
                    if ($descA && !$descB) return -1;
                    if (!$descA && $descB) return 1;
                    return 0;
                } else {
                    // Sort by date (date_asc or date_desc)
                    $dateA = $a['exifInfo']['dateTimeOriginal'] ?? '';
                    $dateB = $b['exifInfo']['dateTimeOriginal'] ?? '';
                    
                    if ($dateA && $dateB) {
                        $comparison = strcmp($dateA, $dateB);
                        return ($order === 'date_asc') ? $comparison : -$comparison;
                    }
                    
                    if ($dateA && !$dateB) return -1;
                    if (!$dateA && $dateB) return 1;
                    return 0;
                }
            });

            $html = '';
            if (in_array('gallery_name', $show)) {
                $html .= '<h2 style="font-size:' . $title_size . 'px;">' . esc_html($album['albumName']) . '</h2>';
            }
            if (in_array('gallery_description', $show) && !empty($album['description'])) {
                $html .= '<p style="font-size:' . $description_size . 'px;">' . esc_html($album['description']) . '</p>';
            }
            $html .= '<div class="immich-grid immich-album-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $size . 'px,1fr));gap:15px;">';

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
                            <img src="' . esc_url($thumb_url) . '" style="width:100%;height:' . $size . 'px;object-fit:cover;border-radius:6px;display:block;"' . $lazy_attr . '>
                          </a>';
                if (in_array('asset_date', $show) && !empty($asset['exifInfo']['dateTimeOriginal'])) {
                    $date = wp_date('Y-m-d', strtotime($asset['exifInfo']['dateTimeOriginal']));
                    $html .= '<div style="text-align:center;margin-top:4px;margin-bottom:-2px;line-height:1;font-size:' . $date_size . 'px;">' . esc_html($date) . '</div>';
                }
                if (in_array('asset_description', $show)) {
                    $html .= '<div style="text-align:center;margin-top:0;margin-bottom:0;line-height:1;font-size:' . $description_size . 'px;color:#666;">' . esc_html($asset['exifInfo']['description'] ?? '') . '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
            //$html .= '<p><a href="' . get_permalink() . '">&larr; ' . __('Back to overview', 'immich-gallery') . '</a></p>';

            return $html;
        } else {
            // Overview page
            $immich_albums = $this->api_request('albums');
            // error_log(print_r($immich_albums, true));

            // Check for API error
            if (isset($immich_albums['error']) && $immich_albums['error']) {
                return '<p>' . __('Error from Immich API: ', 'immich-gallery') . esc_html($immich_albums['error']) . ': ' . esc_html($immich_albums['message']) . '</p>';
            }

            if (!$immich_albums) {
                return '<p>' . __('No albums found.', 'immich-gallery') . '</p>';
            }

            $html = '<div class="immich-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $size . 'px,1fr));gap:20px;">';
            
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
                // Show all albums from Immich, sorted based on order parameter
                // date_asc: oldest first, date_desc: newest first
                // name_asc: A-Z, name_desc: Z-A
                $albums_to_render = $immich_albums;
                
                usort($albums_to_render, function($a, $b) use ($order) {
                    if ($order === 'name_asc' || $order === 'name_desc') {
                        // Sort by album name
                        $nameA = $a['albumName'] ?? '';
                        $nameB = $b['albumName'] ?? '';
                        
                        if ($nameA && $nameB) {
                            $comparison = strcasecmp($nameA, $nameB);
                            return ($order === 'name_asc') ? $comparison : -$comparison;
                        }
                        
                        if ($nameA && !$nameB) return -1;
                        if (!$nameA && $nameB) return 1;
                        return 0;
                    } else {
                        // Sort by date (date_asc or date_desc)
                        $endDateA = $a['endDate'] ?? '';
                        $endDateB = $b['endDate'] ?? '';
                        
                        if ($endDateA && $endDateB) {
                            $comparison = strcmp($endDateA, $endDateB);
                            return ($order === 'date_asc') ? $comparison : -$comparison;
                        }
                        
                        if ($endDateA && !$endDateB) return -1;
                        if (!$endDateA && $endDateB) return 1;
                        return 0;
                    }
                });
            }

            // Render the albums
            foreach ($albums_to_render as $album) {
                if (empty($album['albumThumbnailAssetId'])) continue;
                $thumb_url = plugins_url('immich-gallery-thumbnail.php', __FILE__) . '?id=' . $album['albumThumbnailAssetId'];

                $html .= '<div>';
                $html .= '<a href="' . get_permalink() . '?immich_gallery=' . esc_attr($album['id']) . '">
                        <img src="' . esc_url($thumb_url) . '" style="width:100%;height:' . $size . 'px;object-fit:cover;display:block;"' . $lazy_attr . '></a>';;
                
                if (in_array('gallery_name', $show) || in_array('gallery_description', $show)) {
                    $html .= '<div style="text-align:center;">';
                    if (in_array('gallery_name', $show)) {
                        $html .= '<a href="' . get_permalink() . '?immich_gallery=' . esc_attr($album['id']) . '">
                                <div style="font-weight:bold;margin-bottom:5px;font-size:' . $title_size . 'px;">' . esc_html($album['albumName']) . '</div></a>';
                    }
                    if (in_array('gallery_description', $show)) {
                        $html .= '<div style="font-size:' . $description_size . 'px;color:#666;">' . esc_html($album['description']) . '</div>';
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
