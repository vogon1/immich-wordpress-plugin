<?php
/**
 * Plugin Name: Gallery for Immich
 * Plugin URI: https://github.com/vogon1/immich-wordpress-plugin
 * Description: Show Immich albums and photos in a WordPress site. Requires Immich server with API access.
 * Version: 0.10.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Sietse Visser
 * Author URI: https://github.com/vogon1
 * License: GPLv3 or later
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

    /** Default lifetime of cached Immich API responses, in seconds. */
    const DEFAULT_CACHE_TTL = 900;

    public function __construct() {
        // The proxy runs once init is complete, so access filters that other plugins register
        // during init (gallery_for_immich_can_view) are in place.
        add_action('wp_loaded', [$this, 'handle_image_proxy']);
        // Load text domain for translations (required for JavaScript translations)
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_shortcode('gallery_for_immich', [$this, 'render_gallery']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('gallery_for_immich_cleanup_shared_link', [$this, 'cleanup_shared_link']);
        
        // Gutenberg block support
        add_action('init', [$this, 'register_block']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // AJAX handler for API connection test (admin only)
        add_action('wp_ajax_gallery_for_immich_test_connection', [$this, 'ajax_test_connection']);
        add_action('admin_post_gallery_for_immich_clear_cache', [$this, 'handle_clear_cache']);
        
        // Plugin description translation for plugin list
        add_filter('all_plugins', [$this, 'translate_plugin_description']);
    }

    public function load_textdomain() {
        // Load plugin translations with explicit path
        // This ensures translations work correctly for standalone installations
        $mofile = plugin_dir_path(__FILE__) . 'languages/gallery-for-immich-' . determine_locale() . '.mo';
        load_textdomain('gallery-for-immich', $mofile);
    }

    public function translate_plugin_description($plugins) {
        $plugin_file = plugin_basename(__FILE__);
        
        if (isset($plugins[$plugin_file])) {
            // Translate the description
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

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public proxy; every request is authorised by the HMAC signature below instead.
        $type    = sanitize_text_field(wp_unslash($_GET['gallery_for_immich_proxy']));
        $id      = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        $sig     = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Validate type with strict whitelist. Only the renders the plugin itself links to are
        // served: no originals (full resolution with all EXIF data, including GPS location).
        if (!in_array($type, ['thumbnail', 'preview', 'video'], true)) {
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

        // Only assets this site rendered carry a valid signature. It binds the asset to the post it
        // was shown on, so a URL copied from a protected page cannot be turned into a public one.
        if (!preg_match('/^[a-f0-9]{64}$/', $sig) || !hash_equals($this->proxy_signature($id, $post_id), $sig)) {
            nocache_headers();
            status_header(403);
            exit('Invalid signature');
        }

        // The page the asset was shown on decides who may see it: a password-protected page needs
        // its password cookie, a private page or draft needs a user who can read it.
        if ($post_id && !$this->visitor_can_view_post($post_id, $id)) {
            nocache_headers();
            status_header(403);
            exit('Not allowed');
        }

        // Shared caches (CDN, reverse proxy, page cache) must not store an asset whose visibility
        // depends on the visitor. The visitor's own browser may, so the URL stays stable.
        $cache_scope   = ($this->is_protected_post($post_id) || has_filter('gallery_for_immich_can_view')) ? 'private' : 'public';
        $cache_control = 'Cache-Control: ' . $cache_scope . ', max-age=31536000';

        if ($type === 'video' && ($options['video_mode'] ?? 'shared') === 'ignore') {
            nocache_headers();
            status_header(403);
            exit('Video streaming disabled');
        }

        // Build URL based on type.
        // `edited=true` returns the edited render for assets that were rotated, cropped or
        // filtered in Immich (non-destructive editing, Immich v2.5+). Older Immich versions
        // ignore unknown query parameters, so this is a safe no-op there.
        if ($type === 'thumbnail') {
            $url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/thumbnail?size=thumbnail&edited=true';
            $timeout = 10;
        } elseif ($type === 'preview') {
            $url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/thumbnail?size=preview&edited=true';
            $timeout = 20;
        } else {
            $this->stream_video(rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/video/playback', $options['api_key'], $cache_control);
            exit;
        }

        // For images, use wp_remote_get
        $resp = wp_remote_get($url, [
            'headers' => ['x-api-key' => $options['api_key']],
            'timeout' => $timeout,
            'sslverify' => true
        ]);

        // 502 when Immich could not be reached at all, so it is not mistaken for a missing asset.
        if (is_wp_error($resp)) {
            status_header(502);
            exit('Immich not reachable');
        }
        if (wp_remote_retrieve_response_code($resp) != 200) {
            status_header(404);
            exit('Asset not found');
        }

        // Kill ALL output buffering to prevent stray bytes corrupting binary image output.
        // Servers with output_buffering enabled in php.ini (common on shared hosts) accumulate
        // buffered output during WordPress bootstrap, which prepends garbage bytes to the image
        // data and breaks the JPEG header. This mirrors the pattern already used for video streaming.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Security headers. Immich serves thumbnails as WebP and previews as JPEG; pass the real
        // type on, but only for raster formats (an SVG could carry script on this site's origin).
        header('Content-Type: ' . $this->safe_media_type(wp_remote_retrieve_header($resp, 'content-type'), 'image', 'image/jpeg'));
        header('X-Content-Type-Options: nosniff');
        header($cache_control);
        
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image data
        echo wp_remote_retrieve_body($resp);
        exit;
    }

    /**
     * Media type to send for a proxied response, from an allow list per kind.
     *
     * @param string|array $content_type Content-Type header from Immich.
     * @param string       $kind         'image' or 'video'.
     * @param string       $fallback     Type to use when Immich's type is not on the list.
     * @return string
     */
    private function safe_media_type($content_type, $kind, $fallback) {
        $allowed = [
            'image' => ['image/jpeg', 'image/webp', 'image/png', 'image/avif', 'image/gif'],
            'video' => ['video/mp4', 'video/webm', 'video/quicktime'],
        ];
        $type = strtolower(trim(explode(';', is_array($content_type) ? (string) reset($content_type) : (string) $content_type)[0]));

        return in_array($type, $allowed[$kind] ?? [], true) ? $type : $fallback;
    }

    /**
     * Stream a video from Immich to the browser, passing Range requests through so seeking works.
     *
     * Uses fopen: wp_remote_get() cannot be used, it buffers the whole video in memory. cURL was
     * tried and rejected: under streaming load it made the network of a Docker Desktop VM hang
     * (virtio_net "NETDEV WATCHDOG: transmit queue timed out"), taking every container down.
     *
     * @param string $url           Immich playback URL.
     * @param string $api_key       Immich API key.
     * @param string $cache_control Cache-Control header line for a successful response.
     */
    private function stream_video($url, $api_key, $cache_control) {
        if (headers_sent()) {
            return;
        }

        // Kill ALL output buffering
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-lived streaming request; prevents premature termination.
            @set_time_limit(0);
        }
        ignore_user_abort(false);

        $headers = ['x-api-key: ' . $api_key];

        if (isset($_SERVER['HTTP_RANGE'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HTTP Range header is protocol-level input; validated via strict regex instead of sanitization.
            $raw_range = wp_unslash($_SERVER['HTTP_RANGE']);

            if (preg_match('/^bytes=\d*-\d*(,\d*-\d*)*$/', $raw_range)) {
                $headers[] = 'Range: ' . $raw_range;
            }
        }

        if (!ini_get('allow_url_fopen')) {
            status_header(501);
            echo 'Video streaming needs allow_url_fopen';
            return;
        }

        $streamed = $this->stream_video_fopen($url, $headers, $cache_control);

        if ($streamed === null) {
            status_header(502);
            echo 'Immich not reachable';
        } elseif (!$streamed) {
            status_header(404);
            echo 'Video not found';
        }
    }

    /**
     * Send the status line and the response headers worth passing on, once Immich has answered.
     *
     * @param int    $status          200 or 206.
     * @param array  $remote_headers  Raw header lines from Immich.
     * @param string $cache_control   Cache-Control header line.
     */
    private function send_video_headers($status, $remote_headers, $cache_control) {
        status_header($status);
        $type = 'video/mp4';
        foreach ($remote_headers as $line) {
            if (preg_match('/^Content-Type:\s*(.+)$/i', $line, $m)) {
                $type = $this->safe_media_type($m[1], 'video', 'video/mp4');
            } elseif (preg_match('/^(Content-Length|Content-Range|Accept-Ranges):/i', $line)) {
                header($line);
            }
        }
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header($cache_control);
    }

    /**
     * Stream through PHP's HTTP stream wrapper; requires allow_url_fopen.
     *
     * @return bool|null Whether Immich answered with video data (200 or 206); null when Immich
     *                   could not be reached.
     */
    private function stream_video_fopen($url, $headers, $cache_control) {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout'       => 60,
            ]
        ]);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming remote HTTP resource; WP_Filesystem is not suitable for chunked streaming with backpressure.
        $remote = @fopen($url, 'rb', false, $context);
        if (!$remote) {
            // With ignore_errors, an HTTP error status still opens the stream; failing means no connection.
            return null;
        }

        $meta = stream_get_meta_data($remote);
        $status = 0;
        foreach ($meta['wrapper_data'] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        if (!in_array($status, [200, 206], true)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required to explicitly close HTTP stream resource.
            fclose($remote);
            return false;
        }

        $this->send_video_headers($status, $meta['wrapper_data'], $cache_control);

        // Stream in small chunks with backpressure
        while (!feof($remote)) {
            if (connection_aborted()) {
                break;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Required for safe chunked streaming; WP_Filesystem does not support backpressure.
            $buffer = fread($remote, 8192);
            if ($buffer === false) {
                break;
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary video data; escaping would corrupt output. Safe streaming to client.
            echo $buffer;
            flush();
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required to explicitly close HTTP stream resource; WP_Filesystem does not manage stream lifecycles.
        fclose($remote);
        return true;
    }

    /* --- Admin settings --- */
    public function add_admin_menu() {
        add_options_page(
            __('Gallery for Immich', 'gallery-for-immich'),
            __('Gallery for Immich', 'gallery-for-immich'),
            'manage_options',
            'gallery_for_immich',
            [$this, 'options_page']
        );
    }

    public function settings_init() {
        register_setting('gallery_for_immich', $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section('gallery_for_immich_section', __('Settings', 'gallery-for-immich'), null, 'gallery_for_immich');

        add_settings_field('server_url', __('Immich server URL', 'gallery-for-immich'), [$this, 'field_server_url'], 'gallery_for_immich', 'gallery_for_immich_section');
        add_settings_field('api_key', __('API Key', 'gallery-for-immich'), [$this, 'field_api_key'], 'gallery_for_immich', 'gallery_for_immich_section');
        add_settings_field('video_mode', __('Video playback', 'gallery-for-immich'), [$this, 'field_video_mode'], 'gallery_for_immich', 'gallery_for_immich_section');
        add_settings_field('cache_ttl', __('Cache', 'gallery-for-immich'), [$this, 'field_cache_ttl'], 'gallery_for_immich', 'gallery_for_immich_section');
    }

    public function sanitize_settings($input) {
        $sanitized = [];
        
        // Sanitize and validate server URL
        if (!empty($input['server_url'])) {
            $url = esc_url_raw($input['server_url'], ['http', 'https']);
            if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
                $sanitized['server_url'] = rtrim($url, '/');
            } else {
                add_settings_error(
                    $this->option_name,
                    'invalid_url',
                    __('Server URL must start with http:// or https://.', 'gallery-for-immich')
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

        // Sanitize video mode
        if (!empty($input['video_mode'])) {
            $video_mode = sanitize_text_field($input['video_mode']);
            if (in_array($video_mode, ['shared', 'fopen', 'ignore'], true)) {
                $sanitized['video_mode'] = $video_mode;
            }
        }

        // Sanitize cache lifetime: one of the offered choices, in seconds (0 = off)
        if (isset($input['cache_ttl'])) {
            $cache_ttl = absint($input['cache_ttl']);
            if (array_key_exists($cache_ttl, $this->cache_ttl_choices())) {
                $sanitized['cache_ttl'] = $cache_ttl;
            }
        }
        
        return $sanitized;
    }

    public function field_server_url() {
        $options = get_option($this->option_name);
        ?>
        <input type="url" id="gfi-server-url" name="<?php echo esc_attr($this->option_name); ?>[server_url]" value="<?php echo esc_attr($options['server_url'] ?? ''); ?>" style="width:400px;" placeholder="https://immich.example.com">

        <!-- HTTP warning modal -->
        <div id="gfi-http-modal" style="display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;">
            <div style="position:absolute;inset:0;background:rgba(0,0,0,.5);"></div>
            <div style="position:relative;background:#fff;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,.25);max-width:480px;width:calc(100% - 40px);padding:28px 32px;font-family:inherit;">
                <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">
                    <span style="font-size:32px;line-height:1;">⚠️</span>
                    <h2 style="margin:0;font-size:17px;font-weight:600;color:#1d2327;"><?php echo esc_html__('Unencrypted connection', 'gallery-for-immich'); ?></h2>
                </div>
                <p style="margin:0 0 8px;color:#3c434a;line-height:1.6;"><?php echo esc_html__('You are using an unencrypted HTTP connection.', 'gallery-for-immich'); ?></p>
                <p style="margin:0 0 24px;color:#3c434a;line-height:1.6;"><?php echo esc_html__('Your API key and photo data will be transmitted without encryption. This is not recommended unless you are on a private local network.', 'gallery-for-immich'); ?></p>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button id="gfi-http-cancel" type="button" class="button"><?php echo esc_html__('Cancel', 'gallery-for-immich'); ?></button>
                    <button id="gfi-http-confirm" type="button" class="button" style="background:#d63638;border-color:#d63638;color:#fff;"><?php echo esc_html__('Continue with HTTP', 'gallery-for-immich'); ?></button>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var input  = document.getElementById('gfi-server-url');
            var modal  = document.getElementById('gfi-http-modal');
            var btnOk  = document.getElementById('gfi-http-confirm');
            var btnNo  = document.getElementById('gfi-http-cancel');
            if (!input || !modal) return;

            var form = input.closest('form');
            if (!form) return;

            var confirmed = false;

            function showModal() {
                modal.style.display = 'flex';
                btnNo.focus();
            }
            function hideModal() {
                modal.style.display = 'none';
            }

            form.addEventListener('submit', function(e) {
                if (confirmed) return; // already approved, let it through
                var val  = input.value.trim();
                var host = val.replace(/^http:\/\//i, '').split('/')[0].split(':')[0];
                if (val.toLowerCase().indexOf('http://') === 0 && host !== 'localhost' && host !== '127.0.0.1') {
                    e.preventDefault();
                    showModal();
                }
            });

            btnOk.addEventListener('click', function() {
                hideModal();
                confirmed = true;
                form.requestSubmit ? form.requestSubmit() : form.submit();
            });

            btnNo.addEventListener('click', hideModal);

            // Close on backdrop click
            modal.addEventListener('click', function(e) {
                if (e.target === modal || e.target === modal.firstElementChild) hideModal();
            });

            // Close on Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'flex') hideModal();
            });
        })();
        </script>
        <?php
    }

    public function field_api_key() {
        $options = get_option($this->option_name);
        ?>
        <input type="password" name="<?php echo esc_attr($this->option_name); ?>[api_key]" value="<?php echo esc_attr($options['api_key'] ?? ''); ?>" style="width:400px;" autocomplete="off">
        <?php
    }

    public function field_video_mode() {
        $options = get_option($this->option_name);
        $current = $options['video_mode'] ?? 'shared';
        ?>
        <select name="<?php echo esc_attr($this->option_name); ?>[video_mode]">
            <option value="shared" <?php selected($current, 'shared'); ?>><?php echo esc_html__('Shared links (default)', 'gallery-for-immich'); ?></option>
            <option value="fopen" <?php selected($current, 'fopen'); ?>><?php echo esc_html__('Stream through WordPress', 'gallery-for-immich'); ?></option>
            <option value="ignore" <?php selected($current, 'ignore'); ?>><?php echo esc_html__('Ignore videos', 'gallery-for-immich'); ?></option>
        </select>
        <p style="margin-top: 8px; font-size: 13px; color: #666;">
            <strong><?php echo esc_html__('How it works:', 'gallery-for-immich'); ?></strong><br>
            <?php echo esc_html__('Stream through WordPress: Videos are streamed through this WordPress site, so they also play for albums that are only shared with the API key\'s user. Uses the bandwidth of your WordPress server, and needs allow_url_fopen in PHP; if videos do not play, choose Shared links.', 'gallery-for-immich'); ?><br>
            <?php echo esc_html__('Shared links: Creates temporary shared links on Immich (bypasses WordPress server). The temporary links expire after a short period and will be removed from Immich.', 'gallery-for-immich'); ?><br>
            <?php echo esc_html__('Ignore videos: Videos will not be displayed in galleries.', 'gallery-for-immich'); ?><br>
            <?php echo esc_html__('On password-protected and private pages, videos are always streamed through WordPress, because a shared link cannot be protected.', 'gallery-for-immich'); ?>
        </p>
        <?php
    }

    /**
     * @return array Cache lifetimes in seconds => label.
     */
    private function cache_ttl_choices() {
        return [
            0     => __('Off', 'gallery-for-immich'),
            300   => __('5 minutes', 'gallery-for-immich'),
            900   => __('15 minutes (default)', 'gallery-for-immich'),
            3600  => __('1 hour', 'gallery-for-immich'),
            21600 => __('6 hours', 'gallery-for-immich'),
            86400 => __('1 day', 'gallery-for-immich'),
        ];
    }

    public function field_cache_ttl() {
        $current = $this->cache_ttl();
        ?>
        <select name="<?php echo esc_attr($this->option_name); ?>[cache_ttl]">
            <?php foreach ($this->cache_ttl_choices() as $seconds => $label) : ?>
                <option value="<?php echo esc_attr($seconds); ?>" <?php selected($current, $seconds); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p style="margin-top: 8px; font-size: 13px; color: #666;">
            <?php echo esc_html__('How long album lists, album contents and photo details from Immich are kept before they are fetched again. A change in Immich shows up on the site after this time, or right away when you clear the cache below.', 'gallery-for-immich'); ?>
        </p>
        <?php
    }

    public function options_page() {
        // Double-check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'gallery-for-immich'));
        }
        $options = get_option($this->option_name);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by handle_clear_cache() after its own nonce check
        $cache_cleared = isset($_GET['gallery_for_immich_cache_cleared']);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Gallery for Immich', 'gallery-for-immich'); ?></h1>
            <?php if ($cache_cleared) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Cache cleared. Galleries now show the current state of Immich.', 'gallery-for-immich'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('gallery_for_immich');
                do_settings_sections('gallery_for_immich');
                submit_button();
                ?>
            </form>

            <hr>
            <h2><?php echo esc_html__('Test connection & permissions', 'gallery-for-immich'); ?></h2>
            <p><?php echo esc_html__('After saving your settings, click the button below to verify that your API key works and has all required permissions.', 'gallery-for-immich'); ?></p>
            <button id="gfi-test-btn" class="button button-secondary"><?php echo esc_html__('Test connection & permissions', 'gallery-for-immich'); ?></button>
            <div id="gfi-test-results" style="margin-top:15px;"></div>

            <hr>
            <h2><?php echo esc_html__('Clear cache', 'gallery-for-immich'); ?></h2>
            <p><?php echo esc_html__('Added photos to an album, or renamed one? Clear the cache to show the change right away instead of after the cache time.', 'gallery-for-immich'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gallery_for_immich_clear_cache">
                <?php wp_nonce_field('gallery_for_immich_clear_cache'); ?>
                <?php submit_button(__('Clear cache', 'gallery-for-immich'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <script>
        (function() {
            var btn        = document.getElementById('gfi-test-btn');
            var resultsDiv = document.getElementById('gfi-test-results');
            if (!btn) return;

            var labels = {
                check:       <?php echo wp_json_encode(__('Test connection & permissions', 'gallery-for-immich')); ?>,
                checking:    <?php echo wp_json_encode(__('Testing…', 'gallery-for-immich')); ?>,
                connection:  <?php echo wp_json_encode(__('Connection', 'gallery-for-immich')); ?>,
                permission:  <?php echo wp_json_encode(__('Permission', 'gallery-for-immich')); ?>,
                status:      <?php echo wp_json_encode(__('Status', 'gallery-for-immich')); ?>,
                ok:          <?php echo wp_json_encode(__('OK', 'gallery-for-immich')); ?>,
                missing:     <?php echo wp_json_encode(__('Missing', 'gallery-for-immich')); ?>,
                failed:      <?php echo wp_json_encode(__('Failed', 'gallery-for-immich')); ?>,
                unknown:     <?php echo wp_json_encode(__('Could not test (no assets on server)', 'gallery-for-immich')); ?>,
                notOwned:    <?php echo wp_json_encode(__('Permission is set, but the test photo is not owned by this user', 'gallery-for-immich')); ?>,
                allOk:       <?php echo wp_json_encode(__('All required permissions are set.', 'gallery-for-immich')); ?>,
                someKo:      <?php echo wp_json_encode(__('One or more required permissions are missing.', 'gallery-for-immich')); ?>,
                someFailed:  <?php echo wp_json_encode(__('One or more checks failed. The table shows what Immich answered.', 'gallery-for-immich')); ?>,
                version:     <?php echo wp_json_encode(__('Immich server version:', 'gallery-for-immich')); ?>,
                versionUnknown: <?php echo wp_json_encode(__('unknown', 'gallery-for-immich')); ?>,
                someWarn:    <?php echo wp_json_encode(__('All required permissions are set, but one check could not be completed.', 'gallery-for-immich')); ?>,
                notOwnedHelp: <?php echo wp_json_encode(__('Immich only allows shared links for photos owned by the API key\'s user. If your albums are shared with this user instead of owned by them, videos will not play in video mode "Shared links" — choose "Stream through WordPress" or "Ignore videos" instead. Photos are unaffected.', 'gallery-for-immich')); ?>
            };
            var nonce      = <?php echo wp_json_encode(wp_create_nonce('gallery_for_immich_test')); ?>;
            var ajaxUrl    = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var videoMode  = <?php echo wp_json_encode($options['video_mode'] ?? 'shared'); ?>;
            var permissions = ['album.read', 'asset.read', 'asset.view'];
            if (videoMode === 'shared') {
                permissions.push('sharedLink.create', 'sharedLink.delete');
            }

            btn.addEventListener('click', function () {
                btn.disabled    = true;
                btn.textContent = labels.checking;
                resultsDiv.innerHTML = '';

                var data = new FormData();
                data.append('action', 'gallery_for_immich_test_connection');
                data.append('nonce', nonce);

                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        btn.disabled    = false;
                        btn.textContent = labels.check;

                        if (!resp.success) {
                            resultsDiv.innerHTML =
                                '<div class="notice notice-error inline" style="margin:0;"><p>' +
                                escHtml(resp.data.message) + '</p></div>';
                            return;
                        }

                        var r       = resp.data.results;
                        var details = resp.data.details || {};
                        var allGood = true;
                        var hasWarn = false;
                        var only403 = true;
                        var rows    = '';
                        var notes   = '';

                        /* Connection row */
                        var connOk = r['connection'] === true;
                        if (!connOk) allGood = false;
                        rows += row(labels.connection, connOk ? true : false);

                        /* Permission rows */
                        permissions.forEach(function (p) {
                            var val = r[p];
                            if (val === false) {
                                allGood = false;
                                if (details[p] && details[p].status !== 403) only403 = false;
                            } else if (val !== true) {
                                /* 'not_owned' or null: the permission is present, the probe was
                                   just inconclusive. Warn, but do not report it as missing. */
                                hasWarn = true;
                                if (val === 'not_owned') {
                                    notes += '<p style="max-width:560px;margin-top:12px;">' + escHtml(labels.notOwnedHelp) + '</p>';
                                }
                            }
                            rows += row(p, val, details[p]);
                        });

                        var summary;
                        if (!allGood) {
                            summary = '<div class="notice notice-error inline" style="margin:0 0 12px;"><p>' + escHtml(only403 ? labels.someKo : labels.someFailed) + '</p></div>';
                        } else if (hasWarn) {
                            summary = '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p>' + escHtml(labels.someWarn) + '</p></div>';
                        } else {
                            summary = '<div class="notice notice-success inline" style="margin:0 0 12px;"><p>' + escHtml(labels.allOk) + '</p></div>';
                        }

                        summary += '<p>' + escHtml(labels.version) + ' <strong>' + escHtml(resp.data.version || labels.versionUnknown) + '</strong></p>';

                        resultsDiv.innerHTML =
                            summary +
                            '<table class="widefat striped" style="max-width:560px;">' +
                            '<thead><tr><th>' + escHtml(labels.permission) + '</th><th>' + escHtml(labels.status) + '</th></tr></thead>' +
                            '<tbody>' + rows + '</tbody></table>' +
                            notes;
                    })
                    .catch(function (err) {
                        btn.disabled    = false;
                        btn.textContent = labels.check;
                        resultsDiv.innerHTML =
                            '<div class="notice notice-error inline" style="margin:0;"><p>' +
                            escHtml(err.message) + '</p></div>';
                    });
            });

            function row(label, val, detail) {
                var color, text;
                if (val === true)  { color = '#00a32a'; text = '✓ ' + labels.ok; }
                else if (val === false) {
                    /* Show what Immich actually answered: a 403 is a missing permission, anything
                       else (an error page from a reverse proxy, a 500) is a different problem. */
                    color = '#d63638';
                    text  = '✗ ' + (detail && detail.status !== 403 ? labels.failed : labels.missing);
                    if (detail) text += ' (' + detail.text + ')';
                }
                else if (val === 'not_owned') { color = '#996800'; text = '! ' + labels.notOwned; }
                else               { color = '#996800'; text = '? ' + labels.unknown; }
                return '<tr><td><code>' + escHtml(label) + '</code></td>' +
                       '<td style="color:' + color + ';font-weight:600;">' + escHtml(text) + '</td></tr>';
            }

            function escHtml(s) {
                return String(s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }
        })();
        </script>
        <?php
    }

    /* --- Scripts and CSS for Lightbox --- */
    public function enqueue_scripts() {
        wp_enqueue_style('glightbox-css', plugins_url('assets/glightbox/css/glightbox.min.css', __FILE__), [], '3.2.0');
        wp_enqueue_script('glightbox-js', plugins_url('assets/glightbox/js/glightbox.min.js', __FILE__), ['jquery'], '3.2.0', true);

        // JS config for Lightbox
        wp_add_inline_script('glightbox-js', 'var immichLivePhotoUrl = ' . wp_json_encode(rest_url('gallery-for-immich/v1/live-photo-url')) . '; var immichNonce = ' . wp_json_encode(wp_create_nonce('wp_rest')) . ';', 'before');

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
            
            let currentSlideElement = null;
            let videoAutoplayInterval = null;
            
            function tryPlayVideo() {
                const container = document.querySelector(".glightbox-container");
                if (!container) return;
                
                const slide = container.querySelector(".gslide.current");
                if (!slide) return;
                
                // If we switched to a new slide
                if (slide !== currentSlideElement) {
                    // Stop video in previous slide
                    if (currentSlideElement) {
                        const prevVideo = currentSlideElement.querySelector(".gvideo-local");
                        if (prevVideo && !prevVideo.paused) {
                            prevVideo.pause();
                            prevVideo.currentTime = 0;
                        }
                    }
                    
                    currentSlideElement = slide;
                    
                    // Look for video in this slide and play it
                    const video = slide.querySelector(".gvideo-local");
                    if (video) {
                        setTimeout(function() {
                            if (video.paused) {
                                const playPromise = video.play();
                                if (playPromise !== undefined) {
                                    playPromise.catch(function(error) {
                                        console.log("Video autoplay blocked");
                                    });
                                }
                            }
                        }, 150);
                    }
                }
            }
            
            // Start checking for slide changes every 100ms
            videoAutoplayInterval = setInterval(tryPlayVideo, 100);
            
            // Clean up when lightbox closes
            document.addEventListener("keydown", function(e) {
                if (e.key === "Escape" && !document.querySelector(".glightbox-container")) {
                    clearInterval(videoAutoplayInterval);
                    currentSlideElement = null;
                }
            });

            // --- Live Photo support ---
            // Build a map of preview-URL asset-id → live-photo-video-id from anchor tags
            var livePhotoMap = {};
            document.querySelectorAll(".immich-lightbox[data-live-photo-id]").forEach(function(el) {
                var match = el.href && el.href.match(/id=([a-f0-9-]{36})/i);
                if (match) {
                    livePhotoMap[match[1]] = {
                        id: el.getAttribute("data-live-photo-id"),
                        sig: el.getAttribute("data-live-photo-sig"),
                        post: el.getAttribute("data-live-photo-post") || "0"
                    };
                }
            });

            function removeLivePhotoUI() {
                var btn = document.querySelector(".immich-live-btn");
                if (btn) btn.remove();
                var vid = document.querySelector(".immich-live-video");
                if (vid) { vid.pause(); vid.src = ""; vid.remove(); }
                var hiddenImg = document.querySelector(".gslide.current .gslide-media img[style*=\"visibility\"]");
                if (hiddenImg) hiddenImg.style.visibility = "";
            }

            function checkForLivePhoto() {
                removeLivePhotoUI();
                setTimeout(function() {
                    var slide = document.querySelector(".gslide.current .gslide-media");
                    if (!slide) return;
                    var img = slide.querySelector("img");
                    if (!img) return;
                    var match = img.src.match(/id=([a-f0-9-]{36})/i);
                    if (!match) return;
                    var liveId = livePhotoMap[match[1]];
                    if (!liveId) return;

                    var btn = document.createElement("button");
                    btn.className = "immich-live-btn";
                    btn.setAttribute("aria-label", "Play live photo");
                    btn.innerHTML = "Live";
                    slide.style.position = "relative";
                    slide.appendChild(btn);

                    btn.addEventListener("click", function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        btn.disabled = true;
                        btn.innerHTML = "&#8230;";
                        fetch(immichLivePhotoUrl + (immichLivePhotoUrl.indexOf("?") === -1 ? "?" : "&") + "asset_id=" + encodeURIComponent(liveId.id) + "&sig=" + encodeURIComponent(liveId.sig) + "&post=" + encodeURIComponent(liveId.post), {
                            headers: { "X-WP-Nonce": immichNonce }
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!data.url) { btn.disabled = false; btn.innerHTML = "Live"; return; }
                            img.style.visibility = "hidden";
                            btn.style.display = "none";
                            var video = document.createElement("video");
                            video.className = "immich-live-video";
                            video.src = data.url;
                            video.autoplay = true;
                            video.loop = true;
                            video.playsInline = true;
                            video.controls = false;
                            video.style.cssText = "position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain;display:block;";
                            slide.appendChild(video);
                            video.play().catch(function() {});
                        })
                        .catch(function() { btn.disabled = false; btn.innerHTML = "Live"; });
                    });
                }, 350);
            }

            // GLightbox calls preventDefault() on every mouseup inside a slide, also when it is not
            // dragging. In Safari the seek bar of the video then never sees the button being released and
            // keeps following the mouse. Stop the mouseup at the video, after its own controls had it.
            lightbox.on("slide_after_load", function(data) {
                var node = (data && data.slideNode) || document.querySelector(".gslide.current");
                var video = node && node.querySelector(".gvideo-local");
                if (video && !video.immichMouseupGuard) {
                    video.addEventListener("mouseup", function(e) { e.stopPropagation(); });
                    video.immichMouseupGuard = true;
                }
            });

            lightbox.on("open", function() { checkForLivePhoto(); });
            lightbox.on("slide_changed", function() { checkForLivePhoto(); });
        ');

        // CSS fix for full scale, centered, maintaining aspect ratio
        wp_add_inline_style('glightbox-css', '
            .glightbox-container .gslide img {
                max-width: 95vw !important;
                max-height: 95vh !important;
                width: auto !important;
                height: auto !important;
                object-fit: contain !important;
                display: block !important;
                margin: 0 auto !important;
            }
            
            .glightbox-container .gslide-inline video {
                max-width: 100%;
                max-height: 100%;
                display: block;
                margin: auto auto;
            }
            
            .glightbox-container .gslide-inline,
            .glightbox-container .ginlined-content {
                background: transparent !important;
                padding: 0 !important;
                align-items: center;
                justify-content: center;
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

            /* Live Photo play button */
            .immich-live-btn::before {
                content: "\25BA";
                margin-right: 4px;
            }
            .immich-live-btn {
                position: absolute;
                bottom: 10px;
                right: 10px;
                background: rgba(0, 0, 0, 0.55);
                color: #fff;
                border: none;
                border-radius: 20px;
                padding: 6px 18px;
                font-size: 14px;
                font-family: inherit;
                cursor: pointer;
                z-index: 100;
                transition: background 0.2s;
                backdrop-filter: blur(4px);
                pointer-events: auto;
            }
            .immich-live-btn:hover:not(:disabled) {
                background: rgba(0, 0, 0, 0.8);
            }
            .immich-live-btn:disabled {
                cursor: default;
                opacity: 0.7;
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
        // Register the block - translation loading happens in enqueue_block_editor_assets
        register_block_type(__DIR__ . '/block.json', [
            'render_callback' => [$this, 'render_block']
        ]);
    }
    
    /**
     * Enqueue block editor assets and set up translations
     * This runs when the block editor is loaded, ensuring proper timing for translation setup
     */
    public function enqueue_block_editor_assets() {
        // Get the webpack build hash from the asset file
        $asset_file = plugin_dir_path(__FILE__) . 'build/index.asset.php';
        $asset = file_exists($asset_file) ? require $asset_file : ['version' => filemtime(plugin_dir_path(__FILE__) . 'build/index.js')];
        
        // Register and enqueue the block script
        wp_enqueue_script(
            'gallery-for-immich-block',
            plugins_url('build/index.js', __FILE__),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch'],
            $asset['version'],
            true
        );
        
        // Load translations inline - must use wp.i18n API correctly
        $locale = determine_locale();
        $json_file = plugin_dir_path(__FILE__) . 'languages/gallery-for-immich-' . $locale . '-' . $asset['version'] . '.json';
        
        if (file_exists($json_file)) {
            $translations = file_get_contents($json_file);
            $json_data = json_decode($translations, true);
            
            // Use the correct wp.i18n API
            if (isset($json_data['locale_data']['messages'])) {
                wp_add_inline_script(
                    'gallery-for-immich-block',
                    sprintf(
                        'wp.domReady(function() { wp.i18n.setLocaleData(%s, "gallery-for-immich"); });',
                        wp_json_encode($json_data['locale_data']['messages'])
                    ),
                    'before'
                );
            }
        }
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

        // An empty array is meaningful here (show nothing on the album page), so test isset().
        if (isset($attributes['detail_show']) && is_array($attributes['detail_show'])) {
            $shortcode_atts['detail_show'] = implode(',', $attributes['detail_show']);
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
        
        if (!empty($attributes['align'])) {
            $shortcode_atts['align'] = $attributes['align'];
        }

        if (!empty($attributes['link'])) {
            $shortcode_atts['link'] = $attributes['link'];
        }

        // link_url is used when link === 'custom'; pass the URL directly as the link value
        // so render_gallery() can detect it as a URL via filter_var(FILTER_VALIDATE_URL).
        if ( isset( $attributes['link'] ) && $attributes['link'] === 'custom'
             && !empty( $attributes['link_url'] ) ) {
            $shortcode_atts['link'] = $attributes['link_url'];
        }

        if (!empty($attributes['link_target'])) {
            $shortcode_atts['link_target'] = $attributes['link_target'];
        }

        if (!empty($attributes['limit'])) {
            $shortcode_atts['limit'] = $attributes['limit'];
        }

        if (!empty($attributes['pick'])) {
            $shortcode_atts['pick'] = $attributes['pick'];
        }

        return $this->render_gallery($shortcode_atts);
    }
    
    /* --- REST API endpoint for block editor --- */
    public function register_rest_routes() {
        register_rest_route('gallery-for-immich/v1', '/albums', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_albums'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ]);

        register_rest_route('gallery-for-immich/v1', '/live-photo-url', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_video_url'],
            'permission_callback' => '__return_true',
            'args' => [
                'asset_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($value) {
                        return (bool) preg_match('/^[a-f0-9\-]{36}$/i', $value);
                    },
                ],
                'sig' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($value) {
                        return (bool) preg_match('/^[a-f0-9]{64}$/', $value);
                    },
                ],
                'post' => [
                    'required'          => false,
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }
    
   public function rest_get_albums() {
        $immich_albums = $this->get_albums();
        
        if ($this->is_api_error($immich_albums)) {
            return new WP_Error('api_error', $this->format_api_error($immich_albums), ['status' => 500]);
        }
        
        if (!$immich_albums) {
            return ['albums' => []];
        }
        
        // Format albums for the block editor
        $formatted_albums = array_map(function($album) {
            return [
                'id' => $album['id'],
                'name' => $album['albumName'] ?? __('Untitled Album', 'gallery-for-immich')
            ];
        }, $immich_albums);
        
        return ['albums' => $formatted_albums];
    }

    /* --- AJAX: test Immich connection and permissions --- */
    public function ajax_test_connection() {
        check_ajax_referer('gallery_for_immich_test', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'gallery-for-immich')]);
            return;
        }

        $options = get_option($this->option_name);
        if (empty($options['server_url']) || empty($options['api_key'])) {
            wp_send_json_error(['message' => __('Please save your Server URL and API Key first.', 'gallery-for-immich')]);
            return;
        }

        $server_url = rtrim($options['server_url'], '/');
        $api_key    = $options['api_key'];
        $results    = [];
        $details    = [];
        $asset_id   = null;

        // What Immich answered for a failed check, so the settings page can show the real reason
        // (e.g. a reverse proxy in between) instead of only "Missing".
        $detail = function ($response) {
            $error = $this->response_error($response, []);
            return ['status' => $error['status'], 'text' => $this->format_api_error($error)];
        };

        // 1. Test basic connectivity + album.read simultaneously.
        //    GET /api/albums: 200 = connected + album.read OK
        //                     401 = invalid API key (fail early)
        //                     403 = connected but album.read missing
        //    Using /api/albums avoids needing `user.read` permission which is not required.
        $album_response = wp_remote_get($server_url . '/api/albums', [
            'headers'   => ['x-api-key' => $api_key],
            'timeout'   => 10,
            'sslverify' => true,
        ]);
        $album_code = is_wp_error($album_response) ? 0 : wp_remote_retrieve_response_code($album_response);

        // 401 / 0 means connection failed or token is completely invalid
        if ($album_code === 401 || $album_code === 0) {
            $error_msg = $this->format_api_error($this->response_error($album_response, []));
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: error description */
                    __('Connection failed: %s', 'gallery-for-immich'),
                    $error_msg
                ),
                'results' => [],
            ]);
            return;
        }

        $results['connection']  = true;
        $results['album.read']  = ($album_code === 200);
        if (!$results['album.read']) {
            $details['album.read'] = $detail($album_response);
        }

        // Refresh the cached server version; it decides which search API the galleries use.
        $version = $this->immich_version(true);
        $version_label = $version ? sprintf('v%d.%d.%d', $version['major'], $version['minor'], $version['patch']) : '';

        // Extract a real asset ID from the album thumbnail — needed for subsequent probes.
        // Iterate all albums because the first album(s) may be empty folders with a null thumbnail.
        if ($results['album.read']) {
            $albums_data = json_decode(wp_remote_retrieve_body($album_response), true);
            if (is_array($albums_data)) {
                foreach ($albums_data as $album) {
                    if (!empty($album['albumThumbnailAssetId'])) {
                        $asset_id = $album['albumThumbnailAssetId'];
                        break;
                    }
                }
            }
        }

        // 2. asset.read: GET /api/assets/{id}
        //    This is the exact endpoint the plugin uses to read asset metadata.
        //    200 = permission OK (real asset); 400/404 = permission OK (fake/missing asset);
        //    403 = permission missing.
        //    Note: Immich v2.7+ returns 400 (not 404) for UUIDs that fail server-side validation,
        //    so we must accept 400 as a "permission granted" signal when using a fallback probe ID.
        //    The fallback UUID must be a valid v4 UUID format to avoid a different 400 from format
        //    validation (Immich rejects non-v4 UUIDs like 00000000-0000-0000-0000-000000000001).
        $probe_id = $asset_id ?? 'a4138866-79fa-4e8a-af6a-1708463301b4';
        $asset_read_response = wp_remote_get($server_url . '/api/assets/' . $probe_id, [
            'headers'   => ['x-api-key' => $api_key],
            'timeout'   => 10,
            'sslverify' => true,
        ]);
        $asset_read_code = is_wp_error($asset_read_response) ? 0 : wp_remote_retrieve_response_code($asset_read_response);
        $results['asset.read'] = $asset_id ? ($asset_read_code === 200) : in_array($asset_read_code, [200, 400, 404], true);
        if (!$results['asset.read']) {
            $details['asset.read'] = $detail($asset_read_response);
        }

        // 3. asset.view — 200 = OK (real asset); 400/404 = OK (fake/missing asset); 403 = missing.
        $view_response = wp_remote_get($server_url . '/api/assets/' . $probe_id . '/thumbnail', [
            'headers'             => ['x-api-key' => $api_key],
            'timeout'             => 10,
            'sslverify'           => true,
            'limit_response_size' => 1024,
        ]);
        $view_code = is_wp_error($view_response) ? 0 : wp_remote_retrieve_response_code($view_response);
        $results['asset.view'] = $asset_id ? ($view_code === 200) : in_array($view_code, [200, 400, 404], true);
        if (!$results['asset.view']) {
            $details['asset.view'] = $detail($view_response);
        }

        // 6 & 7. sharedLink.create / sharedLink.delete — only needed for 'shared' video mode
        $video_mode = $options['video_mode'] ?? 'shared';
        if ($video_mode !== 'shared') {
            wp_send_json_success(['results' => $results, 'details' => $details, 'version' => $version_label]);
            return;
        }

        $link_body = $asset_id
            ? ['type' => 'INDIVIDUAL', 'assetIds' => [$asset_id], 'expiresAt' => gmdate('c', time() + 60)]
            : ['type' => 'INDIVIDUAL', 'assetIds' => []];

        $create_response = wp_remote_post($server_url . '/api/shared-links', [
            'headers'   => ['x-api-key' => $api_key, 'Content-Type' => 'application/json'],
            'body'      => wp_json_encode($link_body),
            'timeout'   => 10,
            'sslverify' => true,
        ]);
        $create_code = is_wp_error($create_response) ? 0 : wp_remote_retrieve_response_code($create_response);

        if ($asset_id) {
            if (in_array($create_code, [200, 201], true)) {
                $results['sharedLink.create'] = true;
            } elseif (in_array($create_code, [400, 404, 422], true)) {
                // The API key does hold the permission — a missing permission is rejected by the
                // auth guard with 403 before the request ever reaches the shared link service.
                // Immich refuses the link because this user does not *own* the probe asset, which
                // is the normal situation for an album that is shared with a secondary
                // "display user" rather than owned by them. Report this as a warning, not a
                // missing permission, so the settings page stops giving a false negative.
                $results['sharedLink.create'] = 'not_owned';
            } else {
                $results['sharedLink.create'] = false;
            }
        } else {
            // 400/422 = permission OK but bad input (no assets); 403 = permission missing
            $results['sharedLink.create'] = in_array($create_code, [200, 201, 400, 422], true);
        }
        if ($results['sharedLink.create'] === false) {
            $details['sharedLink.create'] = $detail($create_response);
        }

        if (in_array($create_code, [200, 201], true)) {
            // A real link was created — delete it immediately
            $link_data = json_decode(wp_remote_retrieve_body($create_response), true);
            if (!empty($link_data['id'])) {
                $delete_response = wp_remote_request($server_url . '/api/shared-links/' . $link_data['id'], [
                    'method'    => 'DELETE',
                    'headers'   => ['x-api-key' => $api_key],
                    'timeout'   => 10,
                    'sslverify' => true,
                ]);
                $delete_code = is_wp_error($delete_response) ? 0 : wp_remote_retrieve_response_code($delete_response);
                $results['sharedLink.delete'] = in_array($delete_code, [200, 204], true);
                if (!$results['sharedLink.delete']) {
                    $details['sharedLink.delete'] = $detail($delete_response);
                }
            } else {
                $results['sharedLink.delete'] = null;
            }
        } else {
            // Probe with fake link ID: 400/404 = permission OK; 403 = permission missing.
            // Immich v2.7+ returns 400 for UUIDs that pass format validation but don't exist,
            // so accept 400 alongside 404. Use a valid v4 UUID to avoid format-level rejection.
            $delete_response = wp_remote_request($server_url . '/api/shared-links/a4138866-79fa-4e8a-af6a-1708463301b4', [
                'method'    => 'DELETE',
                'headers'   => ['x-api-key' => $api_key],
                'timeout'   => 10,
                'sslverify' => true,
            ]);
            $delete_code = is_wp_error($delete_response) ? 0 : wp_remote_retrieve_response_code($delete_response);
            $results['sharedLink.delete'] = in_array($delete_code, [200, 204, 400, 404], true);
            if (!$results['sharedLink.delete']) {
                $details['sharedLink.delete'] = $detail($delete_response);
            }
        }

        wp_send_json_success(['results' => $results, 'details' => $details, 'version' => $version_label]);
    }

    /* --- Cache --- */

    public function handle_clear_cache() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'gallery-for-immich'));
        }
        check_admin_referer('gallery_for_immich_clear_cache');

        $this->clear_cache();

        wp_safe_redirect(add_query_arg(
            ['page' => 'gallery_for_immich', 'gallery_for_immich_cache_cleared' => 1],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Invalidate all cached Immich responses at once by moving to a new cache generation; the
     * old entries simply expire. This also works with a persistent object cache, where
     * transients cannot be listed.
     */
    private function clear_cache() {
        update_option('gallery_for_immich_cache_gen', (int) get_option('gallery_for_immich_cache_gen', 1) + 1);
        delete_transient($this->version_cache_key());
    }

    /**
     * @return int Lifetime of cached Immich responses in seconds; 0 = caching off.
     */
    private function cache_ttl() {
        $options = get_option($this->option_name);
        return isset($options['cache_ttl']) ? absint($options['cache_ttl']) : self::DEFAULT_CACHE_TTL;
    }

    /**
     * Return a cached Immich response, or fetch and cache it. Errors are never cached, so a
     * failing Immich is retried on the next page view.
     *
     * The key covers the server URL and API key, so changing either starts with an empty cache.
     *
     * @param array    $parts What identifies the request (endpoint and parameters).
     * @param callable $fetch Performs the request; returns data or an API error array.
     * @return array
     */
    private function cached($parts, $fetch) {
        $ttl = $this->cache_ttl();
        if ($ttl <= 0) {
            return $fetch();
        }

        $options = get_option($this->option_name);
        $key = 'gallery_for_immich_c_' . md5(wp_json_encode([
            (int) get_option('gallery_for_immich_cache_gen', 1),
            $options['server_url'] ?? '',
            $options['api_key'] ?? '',
            $parts,
        ]));

        $value = get_transient($key);
        if (is_array($value)) {
            return $value;
        }

        $value = $fetch();
        if (is_array($value) && !$this->is_api_error($value)) {
            set_transient($key, $value, $ttl);
        }
        return $value;
    }

    public function rest_get_video_url($request) {
        $asset_id = sanitize_text_field($request['asset_id']);
        
        if (!preg_match('/^[a-f0-9\-]{36}$/i', $asset_id)) {
            return new WP_Error('invalid_id', 'Invalid asset ID', ['status' => 400]);
        }

        $post_id = absint($request['post']);

        // Only Live Photo videos of photos rendered by this site carry a valid signature.
        if (!hash_equals($this->live_photo_signature($asset_id, $post_id), (string) $request['sig'])) {
            return new WP_Error('invalid_signature', 'Invalid signature', ['status' => 403]);
        }

        if ($post_id && !$this->visitor_can_view_post($post_id, $asset_id)) {
            return new WP_Error('not_allowed', 'Not allowed', ['status' => 403]);
        }
        
        // For REST API, only block if we're in admin/preview context (not public page loads)
        if (is_admin()) {
            return new WP_Error('preview_mode', 'Cannot generate video URL in preview mode', ['status' => 403]);
        }
        
        // Get asset data
        $asset_data = $this->get_asset($asset_id);
        if (!$asset_data || $this->is_api_error($asset_data)) {
            return new WP_Error('not_found', 'Asset not found', ['status' => 404]);
        }
        
        // Generate shared link
        $options = get_option($this->option_name);
        $video_mode = $options['video_mode'] ?? 'shared';

        if ($video_mode === 'ignore') {
            return new WP_Error('videos_disabled', 'Video playback is disabled', ['status' => 403]);
        }

        if ($this->video_via_proxy($post_id)) {
            $video_url = $this->proxy_url('video', $asset_id, $post_id);
        } else {
            // Generate shared link on demand
            $video_url = $this->get_video_url_with_shared_link($asset_data);
        }

        if (null === $video_url) {
            return new WP_Error('shared_link_failed', 'Could not create a shared link for this asset', ['status' => 502]);
        }

        return ['url' => $video_url];
    }

    /**
     * Signature for a Live Photo video ID, rendered next to the ID in the page.
     *
     * The public live-photo-url endpoint creates an Immich shared link, so it must only serve
     * Live Photo videos of photos this site actually displays. Only the server can compute this
     * signature, and it does not expire, so it also works on cached pages.
     *
     * @param string $asset_id Live Photo video asset ID.
     * @param int    $post_id  Post the photo is shown on; 0 outside a post.
     * @return string
     */
    private function live_photo_signature($asset_id, $post_id = 0) {
        // Without a post the message is the same as in 0.9, so cached pages keep working.
        $message = 'live-photo|' . strtolower($asset_id) . ($post_id ? '|' . (int) $post_id : '');
        return hash_hmac('sha256', $message, wp_salt('auth'));
    }

    /**
     * Signature for a proxy URL. Binding the asset to the post it is shown on means the proxy only
     * serves assets this site displays, and a URL from a protected page only works for visitors
     * who may see that page.
     *
     * @param string $asset_id Asset UUID.
     * @param int    $post_id  Post the asset is shown on; 0 outside a post.
     * @return string
     */
    private function proxy_signature($asset_id, $post_id) {
        return hash_hmac('sha256', 'proxy|' . strtolower($asset_id) . '|' . (int) $post_id, wp_salt('auth'));
    }

    /**
     * Signed URL of the image/video proxy.
     *
     * @param string $type     'thumbnail', 'preview' or 'video'.
     * @param string $asset_id Asset UUID.
     * @param int    $post_id  Post the asset is shown on; 0 outside a post.
     * @param array  $data     Asset or album data for the cache version, if known.
     * @return string
     */
    private function proxy_url($type, $asset_id, $post_id, $data = null) {
        $args = ['gallery_for_immich_proxy' => $type, 'id' => $asset_id];

        $version = $data ? $this->cache_version($data) : 0;
        if ($version) {
            $args['v'] = $version;
        }
        if ($post_id) {
            $args['post'] = (int) $post_id;
        }
        $args['sig'] = $this->proxy_signature($asset_id, $post_id);

        return add_query_arg($args, home_url('/'));
    }

    /**
     * Whether a post is hidden from some visitors: it has a password or is not published
     * (private, draft, scheduled).
     */
    private function is_protected_post($post_id) {
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) {
            return false;
        }
        return $post->post_password !== '' || get_post_status($post) !== 'publish';
    }

    /**
     * Whether the current visitor may see the content of a post, and so the photos on it.
     */
    private function visitor_can_view_post($post_id, $asset_id) {
        $post = get_post($post_id);
        if (!$post) {
            $allowed = false;
        } elseif (post_password_required($post)) {
            $allowed = false;
        } elseif (get_post_status($post) === 'publish') {
            $allowed = true;
        } else {
            $allowed = current_user_can('read_post', $post->ID);
        }

        /**
         * Filters whether the current visitor may see a photo or video shown on a post.
         *
         * Lets membership plugins apply their own access rules. While a callback is attached,
         * proxied images are sent with `Cache-Control: private`, so shared caches do not store them.
         *
         * @param bool   $allowed  Whether the visitor may see the post's content.
         * @param int    $post_id  Post the asset is shown on.
         * @param string $asset_id Immich asset ID.
         */
        return (bool) apply_filters('gallery_for_immich_can_view', $allowed, (int) $post_id, $asset_id);
    }

    /**
     * Videos go through the proxy in the "Stream through WordPress" mode, and always on protected
     * posts: an Immich shared link would let anyone with the link watch the video.
     */
    private function video_via_proxy($post_id) {
        $options = get_option($this->option_name);
        return ($options['video_mode'] ?? 'shared') === 'fopen' || $this->is_protected_post($post_id);
    }

    /**
     * Data attributes the lightbox script uses to play a Live Photo.
     *
     * @param string $live_photo_id Live Photo video asset ID, or '' for none.
     * @param int    $post_id       Post the photo is shown on.
     * @return string HTML attributes with a leading space, or ''.
     */
    private function live_photo_attributes($live_photo_id, $post_id) {
        if (!$live_photo_id) {
            return '';
        }
        return ' data-live-photo-id="' . esc_attr($live_photo_id) . '"'
            . ' data-live-photo-sig="' . esc_attr($this->live_photo_signature($live_photo_id, $post_id)) . '"'
            . ($post_id ? ' data-live-photo-post="' . esc_attr($post_id) . '"' : '');
    }

    /* --- API request helper --- */
    private function should_create_shared_links() {
        // Don't create shared links in preview/editor mode to avoid unnecessary API calls
        // REST requests (block editor, REST API) should not create real shared links
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        // Also check for other preview contexts
        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }
        return true;
    }

    /**
     * Cache-busting suffix for proxy URLs.
     *
     * Proxy responses carry a one-year Cache-Control header, so an asset that is edited in
     * Immich would otherwise keep showing its old render in visitors' browsers. Appending the
     * asset's `updatedAt` timestamp gives every version of an asset its own URL.
     *
     * @param array $data Asset or album data from the Immich API.
     * @return int Timestamp for the `v` parameter, or 0 when no timestamp is known.
     */
    private function cache_version($data) {
        $stamp = $data['updatedAt'] ?? ($data['fileModifiedAt'] ?? '');
        $timestamp = $stamp ? strtotime($stamp) : false;

        return $timestamp ? (int) $timestamp : 0;
    }

    /* --- Immich API --- */

    /**
     * One request to the Immich API, without caching.
     *
     * @param string     $method   'GET' or 'POST'.
     * @param string     $endpoint Path below /api/, e.g. 'albums'.
     * @param array|null $body     JSON body.
     * @return array Decoded JSON, or an API error array (see api_error()).
     */
    private function api_call($method, $endpoint, $body = null) {
        $options = get_option($this->option_name);

        // Validate options exist
        if (empty($options['server_url']) || empty($options['api_key'])) {
            return $this->api_error(0, __('Plugin not configured. Please set Server URL and API Key in settings.', 'gallery-for-immich'));
        }

        $args = [
            'method'    => $method,
            'headers'   => [
                'x-api-key'  => $options['api_key'],
                'User-Agent' => 'WordPress-Gallery-for-Immich/' . get_bloginfo('version'),
            ],
            'timeout'   => 15,
            'sslverify' => true, // Enforce SSL verification
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request(rtrim($options['server_url'], '/') . '/api/' . ltrim($endpoint, '/'), $args);

        $error = $this->response_error($response, [200]);
        if ($error) {
            return $error;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            // E.g. a login page from a reverse proxy in front of Immich.
            return $this->api_error(200, __('The response is not valid JSON. Is there a proxy or login page in front of Immich?', 'gallery-for-immich'));
        }
        return $data;
    }

    /**
     * Cached GET request. $slim reduces a successful response to the fields the plugin uses
     * before it is cached, so the cache holds no more than needed (no owner e-mail addresses,
     * no GPS data).
     *
     * @param string        $endpoint Path below /api/.
     * @param callable|null $slim     Applied to a successful response.
     * @return array
     */
    private function api_request($endpoint, $slim = null) {
        return $this->cached(['GET', $endpoint, $slim ? 'slim' : 'full'], function () use ($endpoint, $slim) {
            $result = $this->api_call('GET', $endpoint);
            return ($slim && !$this->is_api_error($result)) ? $slim($result) : $result;
        });
    }

    /** @return array The album list, reduced to the fields the plugin uses. */
    private function get_albums() {
        return $this->api_request('albums', function ($albums) {
            return array_map([$this, 'slim_album'], $albums);
        });
    }

    private function get_album($album_id) {
        return $this->api_request('albums/' . $album_id, [$this, 'slim_album']);
    }

    private function get_asset($asset_id) {
        return $this->api_request('assets/' . $asset_id, [$this, 'slim_asset']);
    }

    /**
     * Keep only the album fields the gallery and the block editor use.
     */
    private function slim_album($album) {
        return [
            'id'                    => $album['id'] ?? '',
            'albumName'             => $album['albumName'] ?? '',
            'description'           => $album['description'] ?? '',
            'albumThumbnailAssetId' => $album['albumThumbnailAssetId'] ?? null,
            'endDate'               => $album['endDate'] ?? '',
            'updatedAt'             => $album['updatedAt'] ?? '',
        ];
    }

    /**
     * @param int    $status  HTTP status, or 0 when Immich could not be reached.
     * @param string $message What went wrong.
     * @return array
     */
    private function api_error($status, $message) {
        return ['error' => true, 'status' => (int) $status, 'message' => (string) $message];
    }

    private function is_api_error($result) {
        return is_array($result) && ($result['error'] ?? null) === true && isset($result['status']);
    }

    /**
     * Turn a failed HTTP response into an API error, using the message from Immich's JSON
     * error body ({"message": "..."}) when there is one.
     *
     * @param array|WP_Error $response  Response from the WordPress HTTP API.
     * @param int[]          $ok_codes  Status codes that are not an error.
     * @return array|null API error, or null when the status is one of $ok_codes.
     */
    private function response_error($response, $ok_codes) {
        if (is_wp_error($response)) {
            return $this->api_error(0, $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if (in_array($status, $ok_codes, true)) {
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        $message = is_array($json) ? ($json['message'] ?? '') : '';
        if (is_array($message)) {
            // Validation errors come as a list of messages.
            $message = implode('; ', array_filter($message, 'is_string'));
        }
        if (!is_string($message) || $message === '') {
            $message = wp_remote_retrieve_response_message($response);
        }

        return $this->api_error($status, mb_substr($message, 0, 300));
    }

    /**
     * @param array $error API error.
     * @return string E.g. "HTTP 403: Missing required permission: asset.read".
     */
    private function format_api_error($error) {
        return $error['status'] > 0 ? sprintf('HTTP %d: %s', $error['status'], $error['message']) : $error['message'];
    }

    /**
     * Message for an Immich API failure in a gallery. Visitors get the friendly text;
     * administrators also see what Immich answered, so a broken key or a reverse proxy in front
     * of Immich is not mistaken for an empty album.
     *
     * @param string $visitor_message Text for everyone.
     * @param array  $error           API error.
     * @return string HTML.
     */
    private function error_html($visitor_message, $error) {
        $html = '<p>' . esc_html($visitor_message) . '</p>';

        if ($this->is_api_error($error) && current_user_can('manage_options')) {
            $html .= '<p class="immich-admin-error" style="border-left:4px solid #d63638;background:#fcf0f1;padding:8px 12px;font-size:14px;">'
                . esc_html(sprintf(
                    /* translators: %s: error from Immich, e.g. "HTTP 403: Missing required permission: asset.read" */
                    __('Only visible to administrators — Immich error: %s', 'gallery-for-immich'),
                    $this->format_api_error($error)
                ))
                . '</p>';
        }

        return $html;
    }

    private function version_cache_key() {
        $options = get_option($this->option_name);
        return 'gallery_for_immich_version_' . md5($options['server_url'] ?? '');
    }

    /**
     * Immich server version, cached for six hours (or ten minutes when it could not be read).
     *
     * @param bool $refresh Ask Immich again instead of using the cache.
     * @return array|null ['major' => int, 'minor' => int, 'patch' => int], or null when unknown.
     */
    private function immich_version($refresh = false) {
        $key = $this->version_cache_key();
        $version = $refresh ? false : get_transient($key);

        if (!is_array($version)) {
            $data = $this->api_call('GET', 'server/version');
            if ($this->is_api_error($data) || !isset($data['major'], $data['minor'])) {
                $version = ['known' => false];
                set_transient($key, $version, 10 * MINUTE_IN_SECONDS);
            } else {
                $version = [
                    'known' => true,
                    'major' => (int) $data['major'],
                    'minor' => (int) $data['minor'],
                    'patch' => (int) ($data['patch'] ?? 0),
                ];
                set_transient($key, $version, 6 * HOUR_IN_SECONDS);
            }
        }

        return empty($version['known']) ? null : $version;
    }

    /**
     * Immich 3.2 introduced a structured search API (filter, orderBy, cursor) and deprecated the
     * flat fields (albumIds, type, order, page), which Immich 4 removes (PR #30179). Older
     * servers only know the flat fields; an unknown version uses them too.
     */
    private function use_structured_search() {
        $version = $this->immich_version();
        return $version && ($version['major'] > 3 || ($version['major'] === 3 && $version['minor'] >= 2));
    }

    /**
     * Search the assets of one album via POST /api/search/metadata or /api/search/random.
     *
     * @param string $endpoint 'search/metadata' or 'search/random'.
     * @param array  $query {
     *     @type string      $album_id    Album UUID.
     *     @type bool        $images_only Leave out videos.
     *     @type int         $size        Page size (max. 1000).
     *     @type string|null $order       'asc' or 'desc' by date taken (metadata only).
     *     @type string|null $next        Page token from a previous result (metadata only).
     * }
     * @return array ['items' => array, 'next' => string|null], or an API error.
     */
    private function search_album_assets($endpoint, $query) {
        $query += ['images_only' => false, 'order' => null, 'next' => null];

        $fetch = function () use ($endpoint, $query) {
            $structured = $this->use_structured_search();
            $result = $this->api_call('POST', $endpoint, $this->search_body($endpoint, $query, $structured));

            // A 400 on the flat fields may mean Immich was upgraded since its version was cached.
            if (!$structured && $this->is_api_error($result) && $result['status'] === 400
                && $this->immich_version(true) && $this->use_structured_search()) {
                $result = $this->api_call('POST', $endpoint, $this->search_body($endpoint, $query, true));
            }

            if ($this->is_api_error($result)) {
                return $result;
            }

            if ($endpoint === 'search/random') {
                // Returns a plain array of assets rather than a paged result.
                return ['items' => array_map([$this, 'slim_asset'], $result), 'next' => null];
            }

            $next = $result['assets']['nextCursor'] ?? ($result['assets']['nextPage'] ?? null);
            return [
                'items' => array_map([$this, 'slim_asset'], $result['assets']['items'] ?? []),
                'next'  => $next !== null ? (string) $next : null,
            ];
        };

        // A random selection must differ per page view, so it is never cached.
        return $endpoint === 'search/random' ? $fetch() : $this->cached([$endpoint, $query], $fetch);
    }

    /**
     * Request body for a search, in the structured form (Immich 3.2+) or the flat form.
     */
    private function search_body($endpoint, $query, $structured) {
        $body = ['withExif' => true, 'size' => (int) $query['size']];

        if ($structured) {
            // Unlike the flat form, the structured form does not leave out trashed assets by itself.
            $filter = ['albumIds' => ['any' => [$query['album_id']]], 'trashedAt' => ['eq' => null]];
            if ($query['images_only']) {
                $filter['type'] = ['eq' => 'IMAGE'];
            }
            $body['filter'] = $filter;
            if ($endpoint === 'search/metadata') {
                if ($query['order']) {
                    $body['orderBy'] = ['field' => 'fileCreatedAt', 'direction' => $query['order']];
                }
                if ($query['next']) {
                    $body['cursor'] = $query['next'];
                }
            }
            return $body;
        }

        $body['albumIds'] = [$query['album_id']];
        if ($query['images_only']) {
            $body['type'] = 'IMAGE';
        }
        if ($endpoint === 'search/metadata') {
            if ($query['order']) {
                // Immich orders search results by the date the asset was taken.
                $body['order'] = $query['order'];
            }
            if ($query['next']) {
                $body['page'] = (int) $query['next'];
            }
        }
        return $body;
    }

    /**
     * Keep only the asset fields the gallery uses, so cached album contents stay small and hold
     * no location or camera data.
     */
    private function slim_asset($asset) {
        return [
            'id'               => $asset['id'] ?? '',
            'type'             => $asset['type'] ?? '',
            'originalFileName' => $asset['originalFileName'] ?? '',
            'updatedAt'        => $asset['updatedAt'] ?? '',
            'fileModifiedAt'   => $asset['fileModifiedAt'] ?? '',
            'livePhotoVideoId' => $asset['livePhotoVideoId'] ?? null,
            'exifInfo'         => [
                'description'      => $asset['exifInfo']['description'] ?? '',
                'dateTimeOriginal' => $asset['exifInfo']['dateTimeOriginal'] ?? '',
            ],
        ];
    }

    /**
     * All assets of an album.
     *
     * @return array Assets, or an API error when not even the first page could be fetched.
     */
    private function get_album_assets($album_id, $images_only = false) {
        $assets = [];
        $next = null;
        $pages = 0;
        $max_pages = 20; // Safety cap: Immich's max page size is 1000, so this covers up to 20,000 assets.

        do {
            $result = $this->search_album_assets('search/metadata', [
                'album_id'    => $album_id,
                'images_only' => $images_only,
                'size'        => 1000,
                'next'        => $next,
            ]);

            if ($this->is_api_error($result)) {
                // Show what we have when a later page fails.
                return $assets ? $assets : $result;
            }

            $assets = array_merge($assets, $result['items']);
            $next = $result['next'];
        } while ($next && ++$pages < $max_pages);

        return $assets;
    }

    /**
     * Fetch at most $limit assets of an album, letting Immich do the limiting where it can,
     * so a `limit=10` on a large album costs one small request instead of fetching everything.
     *
     * Returns null when the combination cannot be limited server-side (description sorting);
     * the caller then fetches all assets and applies limit_items() after sorting.
     *
     * @param string $album_id    Album UUID.
     * @param int    $limit       Maximum number of assets (1–1000).
     * @param string $pick        'first' or 'random'.
     * @param string $order       Sanitized order parameter.
     * @param bool   $images_only Skip videos (video mode 'ignore').
     * @return array|null Assets (unsorted for 'random'), an API error, or null when not limitable server-side.
     */
    private function get_album_assets_limited($album_id, $limit, $pick, $order, $images_only) {
        $query = [
            'album_id'    => $album_id,
            'images_only' => $images_only,
            'size'        => $limit,
        ];

        if ($pick === 'random') {
            $result = $this->search_album_assets('search/random', $query);
        } elseif (in_array($order, ['date_asc', 'date_desc'], true)) {
            $query['order'] = $order === 'date_asc' ? 'asc' : 'desc';
            $result = $this->search_album_assets('search/metadata', $query);
        } else {
            return null;
        }

        return $this->is_api_error($result) ? $result : $result['items'];
    }

    /**
     * Reduce an already sorted list to $limit items: the first ones, or a random
     * selection that keeps the original order.
     *
     * @param array  $items Sorted items.
     * @param int    $limit Maximum number of items; 0 means no limit.
     * @param string $pick  'first' or 'random'.
     * @return array
     */
    private function limit_items($items, $limit, $pick) {
        $items = array_values($items);
        if ($limit < 1 || count($items) <= $limit) {
            return $items;
        }
        if ($pick === 'random') {
            // array_rand() returns the keys in their original order, so the sort order is kept.
            $keys = (array) array_rand($items, $limit);
            return array_values(array_intersect_key($items, array_flip($keys)));
        }
        return array_slice($items, 0, $limit);
    }

    /**
     * Create a temporary Immich shared link so the browser can stream a video directly.
     *
     * Immich only allows shared links for assets the API key's user owns. For albums that are
     * merely shared with that user the call fails, which is a normal situation rather than a
     * fatal error — the caller is expected to fall back to showing just the thumbnail.
     *
     * @param array $asset_data Asset data from the Immich API.
     * @return string|null Playable video URL, or null when no shared link could be created.
     */
    private function get_video_url_with_shared_link($asset_data) {
        $options = get_option($this->option_name);

        // Reuse one shared link per asset while it is still valid, instead of creating a new
        // link (plus a cleanup event) on every page view or Live Photo request. The link lives
        // 10 minutes and is reused for 8, so a returned URL always has 2 minutes left.
        $cache_key = 'gallery_for_immich_link_' . strtolower($asset_data['id']);
        $cached_url = get_transient($cache_key);
        if ($cached_url) {
            return $cached_url;
        }

        $expiresAt = gmdate('c', time() + 600); // 10 minutes

        $share_response = wp_remote_post(
            rtrim($options['server_url'], '/') . '/api/shared-links',
            [
                'headers' => [
                    'x-api-key'    => $options['api_key'],
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'type'       => 'INDIVIDUAL',
                    'assetIds'   => [$asset_data['id']],
                    'expiresAt'  => $expiresAt,
                    'allowDownload' => false,
                ]),
                'timeout' => 10,
            ]
        );

        // Never abort the page render here: a single unshareable video used to kill the whole
        // gallery halfway through the HTML output. Return null and let the caller degrade.
        if (is_wp_error($share_response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($share_response);
        if ($code !== 201 && $code !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($share_response), true);
        if (empty($data['key'])) {
            return null;
        }

        if (!empty($data['id'])) {
            $this->schedule_shared_link_cleanup($data['id'], $expiresAt);
        }

        $video_url = rtrim($options['server_url'], '/') . '/api/assets/' . $asset_data['id'] . '/video/playback?key=' . $data['key'];
        set_transient($cache_key, $video_url, 8 * MINUTE_IN_SECONDS);
        return $video_url;
    }

    private function schedule_shared_link_cleanup($shared_link_id, $expiresAtIso) {
        $shared_link_id = sanitize_text_field($shared_link_id);
        if (empty($shared_link_id)) {
            return;
        }

        $expires_ts = strtotime($expiresAtIso);
        if (!$expires_ts) {
            $expires_ts = time() + 600;
        }

        $delete_at = $expires_ts + 60; // Buffer to allow expiry on Immich side.
        $transient_key = 'gallery_for_immich_cleanup_' . $shared_link_id;

        if (!get_transient($transient_key)) {
            set_transient($transient_key, 1, 2 * HOUR_IN_SECONDS);
            wp_schedule_single_event($delete_at, 'gallery_for_immich_cleanup_shared_link', [$shared_link_id]);
        }
    }

    public function cleanup_shared_link($shared_link_id) {
        $shared_link_id = sanitize_text_field($shared_link_id);
        if (empty($shared_link_id)) {
            return;
        }

        $options = get_option($this->option_name);
        if (empty($options['server_url']) || empty($options['api_key'])) {
            return;
        }

        $url = rtrim($options['server_url'], '/') . '/api/shared-links/' . $shared_link_id;

        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'headers' => [
                'x-api-key' => $options['api_key'],
            ],
            'timeout' => 10,
            'sslverify' => true,
        ]);

        // Log the result for debugging
        if (is_wp_error($response)) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // error_log('Gallery for Immich: Failed to delete shared link ' . $shared_link_id . ': ' . $response->get_error_message());
            }
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 204 && $status_code !== 200) {
                if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    // error_log('Gallery for Immich: Delete shared link returned status ' . $status_code . ' for ' . $shared_link_id);
                }
            }
        }
    }

    /**
     * Sanitize and validate the shortcode attributes.
     *
     * @param array  $atts        Shortcode attributes.
     * @param string $album_param Album ID from the URL (?gallery_for_immich=), sanitized.
     * @return array Normalized values; see the compact() call at the end.
     */
    private function parse_attributes($atts, $album_param) {
        // Sanitize and validate all input parameters
        $albums = $atts['albums'] ?? [];
        if ($albums) {
            $albums = array_map('sanitize_text_field', explode(',', $albums));
            // Validate each album ID is UUID format
            $albums = array_filter($albums, function($id) {
                return preg_match('/^[a-f0-9\-]{36}$/i', trim($id));
            });
        }
        
        // Album from the shortcode, or from the URL when a visitor clicks an album in an overview.
        $album = sanitize_text_field($atts['album'] ?? '');
        if ($album && !preg_match('/^[a-f0-9\-]{36}$/i', $album)) {
            $album = ''; // Invalid format, ignore
        }

        // The URL parameter is only honoured by an overview shortcode, and only for an album that
        // overview actually lists. Otherwise any visitor who knows an album ID could open any album
        // the API key can see, on any page that carries the shortcode.
        $album_from_url = false;
        if ($album_param && preg_match('/^[a-f0-9\-]{36}$/i', $album_param)
            && !$album && empty($atts['asset'])
            && (!$albums || in_array(strtolower($album_param), array_map('strtolower', $albums), true))) {
            $album = $album_param;
            $album_from_url = true;
        }
        
        // Validate asset parameter
        $asset = sanitize_text_field($atts['asset'] ?? '');
        $asset_requested = !empty($asset); // Remember if asset was explicitly requested
        if ($asset && !preg_match('/^[a-f0-9\-]{36}$/i', $asset)) {
            $asset = ''; // Invalid format, ignore
        }
        
        // Sanitize show parameter - only allow specific values
        $parse_show = function($value) {
            if (!$value) {
                return [];
            }
            $allowed_show = ['gallery_name', 'gallery_description', 'asset_date', 'asset_description'];
            $items = array_map('trim', array_map('sanitize_text_field', explode(',', $value)));
            return array_filter($items, function($item) use ($allowed_show) {
                return in_array($item, $allowed_show);
            });
        };
        $show = $parse_show($atts['show'] ?? '');

        // detail_show replaces show on an album page opened from an overview
        // (?gallery_for_immich=), so the overview and the album page can show different
        // texts. When omitted, show applies to both; an empty value shows nothing.
        $opened_from_overview = $album_from_url;
        if ($opened_from_overview && isset($atts['detail_show'])) {
            $show = $parse_show($atts['detail_show']);
        }
        
        // Sanitize order parameter - only allow specific sort options
        // Default: date_desc for albums (newest first), date_asc for photos (oldest first/chronological)
        $default_order = $album ? 'date_asc' : 'date_desc';
        $order = sanitize_text_field($atts['order'] ?? $default_order);
        if (!in_array($order, ['date_asc', 'date_desc', 'name_asc', 'name_desc', 'description_asc', 'description_desc'])) {
            $order = $default_order;
        }
        
        // Sanitize size parameter.
        // For album grids: thumbnail size in pixels (100–500).
        // For single assets: max-width of the displayed preview (100–1200).
        $size = intval($atts['size'] ?? 200);
        $size_max = $asset_requested ? 1200 : 500;
        if ($size < 100 || $size > $size_max) {
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
        
        // Sanitize align parameter for single photos - allows text wrapping
        // Options: left, right, center, none (default)
        $align = sanitize_text_field($atts['align'] ?? 'none');
        if (!in_array($align, ['left', 'right', 'center', 'none'])) {
            $align = 'none';
        }

        // Sanitize link parameter for single photos and albums.
        // Accepted: 'lightbox' (default), 'none', or a valid URL (custom link).
        $link_raw = sanitize_text_field( $atts['link'] ?? 'lightbox' );
        if ( in_array( $link_raw, [ 'lightbox', 'none' ], true ) ) {
            $link_type    = $link_raw;
            $link_url_val = '';
        } elseif ( filter_var( $link_raw, FILTER_VALIDATE_URL ) ) {
            $link_type    = 'custom';
            $link_url_val = esc_url( $link_raw );
        } else {
            $link_type    = 'lightbox'; // Unknown value → safe default.
            $link_url_val = '';
        }

        // Sanitize link_target for custom links: 'new' (default) opens a new tab,
        // 'same' opens in the current tab (e.g. a visual menu to pages on the same site).
        $link_target = sanitize_text_field( $atts['link_target'] ?? 'new' );
        if ( ! in_array( $link_target, [ 'new', 'same' ], true ) ) {
            $link_target = 'new';
        }
        $link_target_attr = $link_target === 'new' ? ' target="_blank" rel="noopener noreferrer"' : '';

        // Sanitize limit (maximum number of albums or photos, 1–1000; 0 = no limit)
        // and pick ('first' in sort order, or a 'random' selection).
        $limit = intval($atts['limit'] ?? 0);
        if ($limit < 1 || $limit > 1000) {
            $limit = 0;
        }
        $pick = sanitize_text_field($atts['pick'] ?? 'first');
        if (!in_array($pick, ['first', 'random'], true)) {
            $pick = 'first';
        }
        // limit/pick apply to the level the shortcode itself defines. An album opened from
        // an overview (?gallery_for_immich=) shows all its photos, unless the shortcode
        // itself is for that album.
        if ($opened_from_overview) {
            $limit = 0;
            // Likewise, photos on an album page opened from an overview open in the lightbox.
            $link_type = 'lightbox';
        }

        return compact(
            'albums',
            'album',
            'album_from_url',
            'asset',
            'asset_requested',
            'show',
            'order',
            'size',
            'title_size',
            'description_size',
            'date_size',
            'align',
            'link_type',
            'link_url_val',
            'link_target_attr',
            'limit',
            'pick'
        );
    }

    /* --- Shortcode: overview + detail --- */
    public function render_gallery($atts) {
        // A shortcode without attributes passes an empty string.
        $atts = is_array($atts) ? $atts : [];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public gallery view, no privileged action
        $album_param = sanitize_text_field(wp_unslash($_GET['gallery_for_immich'] ?? ''));

        [
            'albums' => $albums,
            'album' => $album,
            'album_from_url' => $album_from_url,
            'asset' => $asset,
            'asset_requested' => $asset_requested,
            'show' => $show,
            'order' => $order,
            'size' => $size,
            'title_size' => $title_size,
            'description_size' => $description_size,
            'date_size' => $date_size,
            'align' => $align,
            'link_type' => $link_type,
            'link_url_val' => $link_url_val,
            'link_target_attr' => $link_target_attr,
            'limit' => $limit,
            'pick' => $pick,
        ] = $this->parse_attributes($atts, $album_param);

        // Proxy URLs are signed for the post the gallery is shown on, so the proxy can check that
        // the visitor may see that post (password, private).
        $post_id = (int) get_the_ID();

        // Enable lazy loading for all images
        $lazy_attr = ' loading="lazy"';
        $options = get_option($this->option_name);
        $video_mode = $options['video_mode'] ?? 'shared';

        if ($asset_requested) {
            // Direct link to single asset
            $asset_data = $this->get_asset($asset);

            if (!$asset_data || empty($asset_data['id'])) {
                return $this->error_html(__('Asset not found.', 'gallery-for-immich'), $asset_data);
            }

            // In preview/editor mode, show minimal placeholder
            if (!$this->should_create_shared_links()) {
                return '<div style="background:#f0f0f0;padding:20px;text-align:center;border-radius:6px;color:#999;">' . 
                    ($asset_data['type'] === 'VIDEO' ? '🎬 ' : '🖼 ') . 
                    esc_html($asset_data['originalFileName'] ?? 'Asset') . 
                    '</div>';
            }

            $is_video = ($asset_data['type'] === 'VIDEO');
            $thumb_url = $this->proxy_url('thumbnail', $asset_data['id'], $post_id, $asset_data);
            $full_url  = $this->proxy_url('preview', $asset_data['id'], $post_id, $asset_data);

            // Build inline container with image + metadata
            $html = '<div class="immich-single-photo">';

            // Apply alignment with float for text wrapping
            $img_wrapper_style = 'max-width:' . $size . 'px;margin-bottom:1em;';

            if ($align === 'left') {
                $img_wrapper_style = 'max-width:' . $size . 'px;float:left;margin:0 1.5em 1em 0;';
            } elseif ($align === 'center') {
                $img_wrapper_style = 'max-width:' . $size . 'px;margin:0 auto 1em auto;text-align:center;';
            } elseif ($align === 'right') {
                $img_wrapper_style = 'max-width:' . $size . 'px;float:right;margin:0 0 1em 1.5em;';
            }
            // 'none' uses default style without float

            $html .= '<div style="' . esc_attr($img_wrapper_style) . '">';

            $img_style = 'width:' . $size . 'px;max-width:100%;height:auto;border-radius:6px;display:block;margin-bottom:0.5em;';

            
            // Prepare description for lightbox
            $description = '';
            if (!empty($asset_data['exifInfo']['description'])) {
                $description = esc_attr($asset_data['exifInfo']['description']);
            }
            if (!empty($asset_data['exifInfo']['dateTimeOriginal'])) {
                $date = wp_date('Y-m-d', strtotime($asset_data['exifInfo']['dateTimeOriginal']));
                if ($description) {
                    $description .= ' • ' . $date;
                } else {
                    $description = $date;
                }
            }
            
            if ($is_video) {
                if ($video_mode === 'ignore') {
                    return '<p>' . __('Video is not displayed.', 'gallery-for-immich') . '</p>';
                }

                if ( $link_type === 'none' ) {
                    // No link: show thumbnail only, no click action.
                    $html .= '<img src="' . esc_url( $thumb_url ) . '" style="' . esc_attr( $img_style ) . '"' . $lazy_attr . '>';
                } elseif ( $link_type === 'custom' ) {
                    // Custom URL: thumbnail linked to provided URL.
                    $html .= '<a href="' . esc_url( $link_url_val ) . '"' . $link_target_attr . '>'
                           . '<img src="' . esc_url( $thumb_url ) . '" style="' . esc_attr( $img_style ) . '"' . $lazy_attr . '>'
                           . '</a>';
                } else {
                    // 'lightbox' or 'full': open video in lightbox (inline embed not supported for videos).
                    if ($this->video_via_proxy($post_id)) {
                        $video_url = $this->proxy_url('video', $asset_data['id'], $post_id);
                    } else {
                        // Only create shared links on public pages, not in editor/preview.
                        if ($this->should_create_shared_links()) {
                            $video_url = $this->get_video_url_with_shared_link($asset_data);
                        } else {
                            // In preview mode, use a placeholder.
                            $video_url = '#';
                        }
                    }

                    if ( null === $video_url ) {
                        // No playable URL — typically an album shared with (not owned by) the
                        // API key's Immich user. Show the thumbnail without a lightbox link.
                        $html .= '<img src="' . esc_url( $thumb_url ) . '" style="' . esc_attr( $img_style ) . '"' . $lazy_attr . '>';
                    } else {
                        // `nodrag` keeps GLightbox's slide dragging off the video, so the player's
                        // own seek bar works (Safari otherwise drags the whole slide).
                        $video_html  = '<video class="gvideo-local nodrag" controls="controls" controlsList="" playsinline preload="metadata">';
                        $video_html .= '<source src="' . esc_url( $video_url ) . '" type="video/mp4">';
                        $video_html .= '</video>';

                        $html .= '<a href="#" class="immich-lightbox immich-video-thumb" data-video="true" data-gallery="asset-' . esc_attr( $asset_data['id'] ) . '" data-content="' . esc_attr( $video_html ) . '" data-width="90vw" data-height="90vh">';
                        $html .= '<img src="' . esc_url( $thumb_url ) . '" style="' . esc_attr( $img_style ) . '"' . $lazy_attr . '>';
                        $html .= '</a>';
                    }
                }
            } else {
                // For images, always show the preview (not the small thumbnail).
                // link= controls only click behaviour: lightbox (default), none, or a custom URL.
                $preview_style = 'width:100%;max-width:' . $size . 'px;height:auto;border-radius:6px;display:block;margin-bottom:0.5em;';

                $live_photo_id = '';
                if ($video_mode !== 'ignore' && !empty($asset_data['livePhotoVideoId']) && preg_match('/^[a-f0-9\-]{36}$/i', $asset_data['livePhotoVideoId'])) {
                    $live_photo_id = $asset_data['livePhotoVideoId'];
                }
                $live_attr = $this->live_photo_attributes($live_photo_id, $post_id);

                if ( $link_type === 'lightbox' ) {
                    $html .= '<a href="' . esc_url( $full_url ) . '" class="immich-lightbox"' . $live_attr
                           . ' data-gallery="asset-' . esc_attr( $asset_data['id'] ) . '">'
                           . '<img src="' . esc_url( $full_url ) . '" style="' . esc_attr( $preview_style ) . '"' . $lazy_attr . '>'
                           . '</a>';
                } elseif ( $link_type === 'none' ) {
                    $html .= '<img src="' . esc_url( $full_url ) . '" style="' . esc_attr( $preview_style ) . '"' . $lazy_attr . '>';
                } elseif ( $link_type === 'custom' ) {
                    // Custom URL: preview image linked to provided URL, target per link_target.
                    $html .= '<a href="' . esc_url( $link_url_val ) . '"' . $link_target_attr . '>'
                           . '<img src="' . esc_url( $full_url ) . '" style="' . esc_attr( $preview_style ) . '"' . $lazy_attr . '>'
                           . '</a>';
                }
            }
            if (in_array('asset_date', $show) && !empty($asset_data['exifInfo']['dateTimeOriginal'])) {
                $date = wp_date('Y-m-d', strtotime($asset_data['exifInfo']['dateTimeOriginal']));
                $html .= '<div>' . esc_html($date) . '</div>';
            }
            if (in_array('asset_description', $show)) {
                $html .= '<div>' . esc_html($asset_data['exifInfo']['description'] ?? '') . '</div>';
            }
            $html .= '</div></div>';

            return $html;
        } elseif ($album) {
            // Detail page
            
            // In preview/editor mode, show placeholder
            if (!$this->should_create_shared_links()) {
                return '<div style="background:#f0f0f0;padding:40px;text-align:center;border-radius:6px;color:#999;">' . 
                    __('Gallery preview not available in editor. This will display on the published page.', 'gallery-for-immich') . 
                    '</div>';
            }
            
            $album = $this->get_album($album);

            if (!$album || empty($album['id'])) {
                return $this->error_html(__('No photos found in this album.', 'gallery-for-immich'), $album);
            }

            $images_only = ($video_mode === 'ignore');
            $assets_to_render = $limit
                ? $this->get_album_assets_limited($album['id'], $limit, $pick, $order, $images_only)
                : null;
            $needs_limit = ($assets_to_render === null);
            if ($needs_limit) {
                $assets_to_render = $this->get_album_assets($album['id'], $images_only);
            }

            if (empty($assets_to_render) || $this->is_api_error($assets_to_render)) {
                return $this->error_html(__('No photos found in this album.', 'gallery-for-immich'), $assets_to_render);
            }

            // Sort album photos based on order parameter
            // date_asc: oldest first (chronological), date_desc: newest first
            // description_asc: A-Z by description, description_desc: Z-A by description
            
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

            if ($needs_limit) {
                $assets_to_render = $this->limit_items($assets_to_render, $limit, $pick);
            }

            $html = '';
            if (in_array('gallery_name', $show)) {
                $html .= '<h2 style="font-size:' . $title_size . 'px;">' . esc_html($album['albumName']) . '</h2>';
            }
            if (in_array('gallery_description', $show) && !empty($album['description'])) {
                $html .= '<p style="font-size:' . $description_size . 'px;">' . esc_html($album['description']) . '</p>';
            }
            $html .= '<div class="immich-grid immich-album-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $size . 'px,' . $size . 'px));gap:15px;">';

            foreach ($assets_to_render as $asset) {
                if (empty($asset['id'])) continue;
                
                $is_video = ($asset['type'] === 'VIDEO');
                if ($is_video && $video_mode === 'ignore') {
                    continue;
                }
                $thumb_url = $this->proxy_url('thumbnail', $asset['id'], $post_id, $asset);
                $full_url  = $this->proxy_url('preview', $asset['id'], $post_id, $asset);

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

                if ($link_type === 'none' || $link_type === 'custom') {
                    // No lightbox: a plain thumbnail, optionally linked to a custom URL
                    // (e.g. a single latest photo on a home page that links to the full gallery).
                    $thumb_img = '<img src="' . esc_url($thumb_url) . '" style="width:100%;height:' . $size . 'px;object-fit:cover;border-radius:6px;display:block;"' . $lazy_attr . '>';
                    $html .= $link_type === 'custom'
                        ? '<a href="' . esc_url($link_url_val) . '"' . $link_target_attr . '>' . $thumb_img . '</a>'
                        : $thumb_img;
                } elseif ($is_video) {
                    // For videos, create inline video HTML for lightbox
                    if ($this->video_via_proxy($post_id)) {
                        $video_url = $this->proxy_url('video', $asset['id'], $post_id);
                    } else {
                        // Only create shared links on public pages, not in editor/preview
                        if ($this->should_create_shared_links()) {
                            $video_url = $this->get_video_url_with_shared_link($asset);
                        } else {
                            // In preview mode, use a placeholder
                            $video_url = '#';
                        }
                    }
                    if (null === $video_url) {
                        // No playable URL — typically an album shared with (not owned by) the
                        // API key's Immich user. Show the thumbnail without a lightbox link.
                        $html .= '<img src="' . esc_url($thumb_url) . '" style="width:100%;height:' . $size . 'px;object-fit:cover;border-radius:6px;display:block;"' . $lazy_attr . '>';
                    } else {
                        $video_html = '<video class="gvideo-local nodrag" controls="controls" controlsList="" playsinline preload="metadata">';
                        $video_html .= '<source src="' . esc_url($video_url) . '" type="video/mp4">';
                        $video_html .= '</video>';

                        $html .= '<a href="#" class="immich-lightbox immich-video-thumb" data-video="true" data-gallery="album-' . esc_attr($album['id']) . '" data-content="' . esc_attr($video_html) . '" data-width="90vw" data-height="90vh">';
                        $html .= '<img src="' . esc_url($thumb_url) . '" style="width:100%;height:' . $size . 'px;object-fit:cover;border-radius:6px;display:block;"' . $lazy_attr . '>';
                        $html .= '</a>';
                    }
                } else {
                    $live_photo_id = '';
                    if ($video_mode !== 'ignore' && !empty($asset['livePhotoVideoId']) && preg_match('/^[a-f0-9\-]{36}$/i', $asset['livePhotoVideoId'])) {
                        $live_photo_id = $asset['livePhotoVideoId'];
                    }
                    $live_attr = $this->live_photo_attributes($live_photo_id, $post_id);
                    $html .= '<a href="' . esc_url($full_url) . '" class="immich-lightbox"' . $live_attr . '
                                data-gallery="album-' . esc_attr($album['id']) . '">
                                <img src="' . esc_url($thumb_url) . '" style="width:100%;height:' . $size . 'px;object-fit:cover;border-radius:6px;display:block;"' . $lazy_attr . '>
                              </a>';
                }
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
            
            // Only show "back to gallery" link if album was accessed via URL parameter
            // (meaning user navigated from a gallery overview on the same page)
            if ($album_from_url) {
                $html .= '<p><a href="' . get_permalink() . '">&larr; ' . __('Return to gallery', 'gallery-for-immich') . '</a></p>';
            }

            return $html;
        } else {
            // Overview page
            
            // In preview/editor mode, show placeholder
            if (!$this->should_create_shared_links()) {
                return '<div style="background:#f0f0f0;padding:40px;text-align:center;border-radius:6px;color:#999;">' . 
                    __('Gallery preview not available in editor. This will display on the published page.', 'gallery-for-immich') . 
                    '</div>';
            }
            
            $immich_albums = $this->get_albums();

            // Check for API error
            if ($this->is_api_error($immich_albums)) {
                return $this->error_html(__('No albums found.', 'gallery-for-immich'), $immich_albums);
            }

            if (!$immich_albums) {
                return '<p>' . __('No albums found.', 'gallery-for-immich') . '</p>';
            }

            $html = '<div class="immich-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $size . 'px,' . $size . 'px));gap:20px;">';
            
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

            // Albums without a thumbnail are not rendered, so leave them out before limiting.
            $albums_to_render = array_filter($albums_to_render, function($a) {
                return !empty($a['albumThumbnailAssetId']);
            });
            $albums_to_render = $this->limit_items($albums_to_render, $limit, $pick);

            // Render the albums
            foreach ($albums_to_render as $album) {
                // The album list carries no per-asset timestamp, so the album's own `updatedAt`
                // is used as a best-effort cache version for its thumbnail.
                $thumb_url = $this->proxy_url('thumbnail', $album['albumThumbnailAssetId'], $post_id, $album);

                $html .= '<div>';
                $html .= '<a href="' . get_permalink() . '?gallery_for_immich=' . esc_attr($album['id']) . '">
                        <img src="' . esc_url($thumb_url) . '" style="width:100%;height:' . $size . 'px;object-fit:cover;display:block;"' . $lazy_attr . '></a>';;
                
                if (in_array('gallery_name', $show) || in_array('gallery_description', $show)) {
                    $html .= '<div style="text-align:center;">';
                    if (in_array('gallery_name', $show)) {
                        $html .= '<a href="' . get_permalink() . '?gallery_for_immich=' . esc_attr($album['id']) . '">
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

new Gallery_For_Immich();
