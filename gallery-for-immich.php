<?php
/**
 * Plugin Name: Gallery for Immich
 * Plugin URI: https://github.com/vogon1/immich-wordpress-plugin
 * Description: Show Immich albums and photos in a WordPress site. Requires Immich server with API access.
 * Version: 0.6.1
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

    public function __construct() {
        // Load text domain for translations (required for JavaScript translations)
        add_action('init', [$this, 'handle_image_proxy']);
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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated below with strict whitelist
        $type = isset($_GET['gallery_for_immich_proxy']) ? sanitize_text_field(wp_unslash($_GET['gallery_for_immich_proxy'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated with UUID regex below
        $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';

        // Validate type with strict whitelist
        if (!in_array($type, ['thumbnail', 'preview', 'original', 'video'], true)) {
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
        } elseif ($type === 'preview') {
            $url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/thumbnail?size=preview';
            $timeout = 20;
        } elseif ($type === 'video') {
            $url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/video/playback';
            $timeout = 60;
        } else {
            $url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/original';
            $timeout = 30;
        }

        // For video streaming, use curl with range request support
        if ($type === 'video') {

            if (headers_sent()) {
                exit;
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

            $range = '';

            if (isset($_SERVER['HTTP_RANGE'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HTTP Range header is protocol-level input; validated via strict regex instead of sanitization.
                $raw_range = wp_unslash($_SERVER['HTTP_RANGE']);

                if (preg_match('/^bytes=\d*-\d*(,\d*-\d*)*$/', $raw_range)) {
                    $range = $raw_range;
                }
            }

            $headers = [
                'x-api-key: ' . $options['api_key'],
            ];

            if ($range) {
                $headers[] = 'Range: ' . $range;
            }

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
                status_header(404);
                exit('Video not found');
            }

            // Parse response headers
            $meta = stream_get_meta_data($remote);
            foreach ($meta['wrapper_data'] as $header) {
                if (stripos($header, 'HTTP/') === 0) {
                    if (str_contains($header, '206')) {
                        status_header(206);
                    } else {
                        status_header(200);
                    }
                }

                if (preg_match('/^(Content-Type|Content-Length|Content-Range|Accept-Ranges):/i', $header)) {
                    header($header);
                }
            }

            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=31536000');

            // Stream in small chunks with backpressure
            $chunkSize = 8192;

            while (!feof($remote)) {
                if (connection_aborted()) {
                    break;
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Required for safe chunked streaming; WP_Filesystem does not support backpressure.
                $buffer = fread($remote, $chunkSize);
                if ($buffer === false) {
                    break;
                }

                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary video data; escaping would corrupt output. Safe streaming to client.
                echo $buffer;
                flush();
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required to explicitly close HTTP stream resource; WP_Filesystem does not manage stream lifecycles.
            fclose($remote);
            exit;
        }

        // For images, use wp_remote_get
        $resp = wp_remote_get($url, [
            'headers' => ['x-api-key' => $options['api_key']],
            'timeout' => $timeout,
            'sslverify' => true
        ]);

        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) != 200) {
            status_header(404);
            exit('Asset not found');
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
            <option value="fopen" <?php selected($current, 'fopen'); ?>><?php echo esc_html__('Proxy via fopen', 'gallery-for-immich'); ?></option>
            <option value="ignore" <?php selected($current, 'ignore'); ?>><?php echo esc_html__('Ignore videos', 'gallery-for-immich'); ?></option>
        </select>
        <p style="margin-top: 8px; font-size: 13px; color: #666;">
            <strong><?php echo esc_html__('How it works:', 'gallery-for-immich'); ?></strong><br>
            <?php echo esc_html__('Proxy via fopen: Streams videos through this WordPress site. Most elegant, but not all WordPress configurations support fopen. If it does not work for you, choose Shared Links.', 'gallery-for-immich'); ?><br>
            <?php echo esc_html__('Shared links: Creates temporary shared links on Immich (bypasses WordPress server). The temporary links expire after a short period and will be removed from Immich.', 'gallery-for-immich'); ?><br>
            <?php echo esc_html__('Ignore videos: Videos will not be displayed in galleries.', 'gallery-for-immich'); ?>
        </p>
        <?php
    }

    public function options_page() {
        // Double-check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'gallery-for-immich'));
        }
        $options = get_option($this->option_name);
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

            <hr>
            <h2><?php echo esc_html__('Test connection & permissions', 'gallery-for-immich'); ?></h2>
            <p><?php echo esc_html__('After saving your settings, click the button below to verify that your API key works and has all required permissions.', 'gallery-for-immich'); ?></p>
            <button id="gfi-test-btn" class="button button-secondary"><?php echo esc_html__('Test connection & permissions', 'gallery-for-immich'); ?></button>
            <div id="gfi-test-results" style="margin-top:15px;"></div>
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
                unknown:     <?php echo wp_json_encode(__('Could not test (no assets on server)', 'gallery-for-immich')); ?>,
                allOk:       <?php echo wp_json_encode(__('All required permissions are set.', 'gallery-for-immich')); ?>,
                someKo:      <?php echo wp_json_encode(__('One or more required permissions are missing.', 'gallery-for-immich')); ?>
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
                        var allGood = true;
                        var rows    = '';

                        /* Connection row */
                        var connOk = r['connection'] === true;
                        if (!connOk) allGood = false;
                        rows += row(labels.connection, connOk ? true : false);

                        /* Permission rows */
                        permissions.forEach(function (p) {
                            var val = r[p];
                            if (val !== true) allGood = false;
                            rows += row(p, val);
                        });

                        var summary = allGood
                            ? '<div class="notice notice-success inline" style="margin:0 0 12px;"><p>' + escHtml(labels.allOk) + '</p></div>'
                            : '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p>' + escHtml(labels.someKo) + '</p></div>';

                        resultsDiv.innerHTML =
                            summary +
                            '<table class="widefat fixed striped" style="max-width:480px;">' +
                            '<thead><tr><th>' + escHtml(labels.permission) + '</th><th style="width:200px;">' + escHtml(labels.status) + '</th></tr></thead>' +
                            '<tbody>' + rows + '</tbody></table>';
                    })
                    .catch(function (err) {
                        btn.disabled    = false;
                        btn.textContent = labels.check;
                        resultsDiv.innerHTML =
                            '<div class="notice notice-error inline" style="margin:0;"><p>' +
                            escHtml(err.message) + '</p></div>';
                    });
            });

            function row(label, val) {
                var color, text;
                if (val === true)  { color = '#00a32a'; text = '✓ ' + labels.ok; }
                else if (val === false) { color = '#d63638'; text = '✗ ' + labels.missing; }
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
                    livePhotoMap[match[1]] = el.getAttribute("data-live-photo-id");
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
                        fetch(immichLivePhotoUrl + "?asset_id=" + liveId, {
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

            lightbox.on("open", function() { checkForLivePhoto(); });
            lightbox.on("slide_changed", function() { checkForLivePhoto(); });
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
                padding: 0 !important;f
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
            ],
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
        $asset_id   = null;

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
            $error_msg = is_wp_error($album_response)
                ? $album_response->get_error_message()
                : 'HTTP ' . $album_code;
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

        // Extract a real asset ID from the album thumbnail — needed for subsequent probes.
        if ($results['album.read']) {
            $albums_data = json_decode(wp_remote_retrieve_body($album_response), true);
            if (!empty($albums_data[0]['albumThumbnailAssetId'])) {
                $asset_id = $albums_data[0]['albumThumbnailAssetId'];
            }
        }

        // 2. asset.read: GET /api/assets/{id}
        //    This is the exact endpoint the plugin uses to read asset metadata.
        //    200/404 = permission OK (404 just means fake ID); 403 = permission missing.
        $probe_id = $asset_id ?? '00000000-0000-0000-0000-000000000001';
        $asset_read_response = wp_remote_get($server_url . '/api/assets/' . $probe_id, [
            'headers'   => ['x-api-key' => $api_key],
            'timeout'   => 10,
            'sslverify' => true,
        ]);
        $asset_read_code = is_wp_error($asset_read_response) ? 0 : wp_remote_retrieve_response_code($asset_read_response);
        $results['asset.read'] = $asset_id ? ($asset_read_code === 200) : in_array($asset_read_code, [200, 404], true);

        // 3. asset.view — 200/404 → permission exists; 403 → permission missing.
        $view_response = wp_remote_get($server_url . '/api/assets/' . $probe_id . '/thumbnail', [
            'headers'             => ['x-api-key' => $api_key],
            'timeout'             => 10,
            'sslverify'           => true,
            'limit_response_size' => 1024,
        ]);
        $view_code = is_wp_error($view_response) ? 0 : wp_remote_retrieve_response_code($view_response);
        $results['asset.view'] = $asset_id ? ($view_code === 200) : in_array($view_code, [200, 404], true);

        // 6 & 7. sharedLink.create / sharedLink.delete — only needed for 'shared' video mode
        $video_mode = $options['video_mode'] ?? 'shared';
        if ($video_mode !== 'shared') {
            wp_send_json_success(['results' => $results]);
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
            $results['sharedLink.create'] = in_array($create_code, [200, 201], true);
        } else {
            // 400/422 = permission OK but bad input (no assets); 403 = permission missing
            $results['sharedLink.create'] = in_array($create_code, [200, 201, 400, 422], true);
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
            } else {
                $results['sharedLink.delete'] = null;
            }
        } else {
            // Probe with fake link ID: 404 = permission OK; 403 = permission missing
            $delete_response = wp_remote_request($server_url . '/api/shared-links/00000000-0000-0000-0000-000000000001', [
                'method'    => 'DELETE',
                'headers'   => ['x-api-key' => $api_key],
                'timeout'   => 10,
                'sslverify' => true,
            ]);
            $delete_code = is_wp_error($delete_response) ? 0 : wp_remote_retrieve_response_code($delete_response);
            $results['sharedLink.delete'] = in_array($delete_code, [200, 204, 404], true);
        }

        wp_send_json_success(['results' => $results]);
    }

    public function rest_get_video_url($request) {
        $asset_id = sanitize_text_field($request['asset_id']);
        
        if (!preg_match('/^[a-f0-9\-]{36}$/i', $asset_id)) {
            return new WP_Error('invalid_id', 'Invalid asset ID', ['status' => 400]);
        }
        
        // For REST API, only block if we're in admin/preview context (not public page loads)
        if (is_admin()) {
            return new WP_Error('preview_mode', 'Cannot generate video URL in preview mode', ['status' => 403]);
        }
        
        // Get asset data
        $asset_data = $this->api_request('assets/' . $asset_id);
        if (!$asset_data || isset($asset_data['error'])) {
            return new WP_Error('not_found', 'Asset not found', ['status' => 404]);
        }
        
        // Generate shared link
        $options = get_option($this->option_name);
        $video_mode = $options['video_mode'] ?? 'shared';

        if ($video_mode === 'ignore') {
            return new WP_Error('videos_disabled', 'Video playback is disabled', ['status' => 403]);
        }

        if ($video_mode === 'fopen') {
            $video_url = home_url('/?gallery_for_immich_proxy=video&id=') . $asset_id;
        } else {
            // Generate shared link on demand
            $video_url = $this->get_video_url_with_shared_link($asset_data);
        }
        
        return ['url' => $video_url];
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
                'User-Agent' => 'WordPress-Gallery-for-Immich/' . get_bloginfo('version')
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
            return ['error' => true, 'message' => sprintf(__('API returned status code: %d', 'gallery-for-immich'), $status_code)];
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    private function get_video_url_with_shared_link($asset_data) {
        $options = get_option($this->option_name);
        $expiresAt = gmdate('c', time() + 600); // 10 minuten

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

        if (is_wp_error($share_response)) {
            status_header(502);
            exit('Failed to create shared link');
        }

        $code = wp_remote_retrieve_response_code($share_response);
        if ($code !== 201 && $code !== 200) {
            status_header(502);
            exit('Invalid Immich response');
        }

        $data = json_decode(wp_remote_retrieve_body($share_response), true);
        if (empty($data['key'])) {
            status_header(502);
            exit('Missing shared link key');
        }

        if (!empty($data['id'])) {
            $this->schedule_shared_link_cleanup($data['id'], $expiresAt);
        }

        $video_url = rtrim($options['server_url'], '/') . '/api/assets/' . $asset_data['id'] . '/video/playback?key=' . $data['key'];
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
        $album = sanitize_text_field(wp_unslash($_GET['gallery_for_immich'] ?? ($atts['album'] ?? '')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Used for UI display logic only
        $album_from_url = !empty($_GET['gallery_for_immich']); // Track if album came from URL (navigation)
        if ($album && !preg_match('/^[a-f0-9\-]{36}$/i', $album)) {
            $album = ''; // Invalid format, ignore
        }
        
        // Validate asset parameter
        $asset = sanitize_text_field($atts['asset'] ?? '');
        $asset_requested = !empty($asset); // Remember if asset was explicitly requested
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
        
        // Sanitize align parameter for single photos - allows text wrapping
        // Options: left, right, center, none (default)
        $align = sanitize_text_field($atts['align'] ?? 'none');
        if (!in_array($align, ['left', 'right', 'center', 'none'])) {
            $align = 'none';
        }
        
        // Enable lazy loading for all images
        $lazy_attr = ' loading="lazy"';
        $options = get_option($this->option_name);
        $video_mode = $options['video_mode'] ?? 'shared';

        if ($asset_requested) {
            // Direct link to single asset
            $asset_data = $this->api_request('assets/' . $asset);
            // error_log(print_r($asset_data, true));

            if (!$asset_data || empty($asset_data['id'])) {
                return '<p>' . __('Asset not found.', 'gallery-for-immich') . '</p>';
            }

            // In preview/editor mode, show minimal placeholder
            if (!$this->should_create_shared_links()) {
                return '<div style="background:#f0f0f0;padding:20px;text-align:center;border-radius:6px;color:#999;">' . 
                    ($asset_data['type'] === 'VIDEO' ? '🎬 ' : '🖼 ') . 
                    esc_html($asset_data['originalFileName'] ?? 'Asset') . 
                    '</div>';
            }

            $is_video = ($asset_data['type'] === 'VIDEO');
            $thumb_url = home_url('/?gallery_for_immich_proxy=thumbnail&id=') . $asset_data['id'];
            $full_url  = home_url('/?gallery_for_immich_proxy=preview&id=') . $asset_data['id'];

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

                // For videos, create inline video HTML for lightbox
                if ($video_mode === 'fopen') {
                    $video_url = home_url('/?gallery_for_immich_proxy=video&id=') . $asset_data['id'];
                } else {
                    // Only create shared links on public pages, not in editor/preview
                    if ($this->should_create_shared_links()) {
                        $video_url = $this->get_video_url_with_shared_link($asset_data);
                    } else {
                        // In preview mode, use a placeholder
                        $video_url = '#';
                    }
                }

                $video_html = '<video class="gvideo-local" controls="controls" controlsList="" playsinline preload="metadata">';
                $video_html .= '<source src="' . esc_url($video_url) . '" type="video/mp4">';
                $video_html .= '</video>';
                
                $html .= '<a href="#" class="immich-lightbox immich-video-thumb" data-video="true" data-gallery="asset-' . esc_attr($asset_data['id']) . '" data-content="' . esc_attr($video_html) . '" data-width="90vw" data-height="90vh">';
                $html .= '<img src="' . esc_url($thumb_url) . '" style="' . esc_attr($img_style) . '"' . $lazy_attr . '>';
                $html .= '</a>';
            } else {
                // For images, use lightbox
                $live_photo_id = '';
                if ($video_mode !== 'ignore' && !empty($asset_data['livePhotoVideoId']) && preg_match('/^[a-f0-9\-]{36}$/i', $asset_data['livePhotoVideoId'])) {
                    $live_photo_id = $asset_data['livePhotoVideoId'];
                }
                $live_attr = $live_photo_id ? ' data-live-photo-id="' . esc_attr($live_photo_id) . '"' : '';
                $html .= '<a href="' . esc_url($full_url) . '" class="immich-lightbox"' . $live_attr . '
                            data-gallery="asset-' . esc_attr($asset_data['id']) . '">
                            <img src="' . esc_url($thumb_url) . '" style="' . esc_attr($img_style) . '"' . $lazy_attr . '>
                            </a>';
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
            
            $album = $this->api_request('albums/' . $album);
            // error_log(print_r($album, true));

            if (!$album || empty($album['assets'])) return '<p>' . __('No photos found in this album.', 'gallery-for-immich') . '</p>';

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
            $html .= '<div class="immich-grid immich-album-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $size . 'px,' . $size . 'px));gap:15px;">';

            foreach ($assets_to_render as $asset) {
                if (empty($asset['id'])) continue;
                
                $is_video = ($asset['type'] === 'VIDEO');
                if ($is_video && $video_mode === 'ignore') {
                    continue;
                }
                $thumb_url = home_url('/?gallery_for_immich_proxy=thumbnail&id=') . $asset['id'];
                $full_url  = home_url('/?gallery_for_immich_proxy=preview&id=') . $asset['id'];

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
                
                if ($is_video) {
                    // For videos, create inline video HTML for lightbox
                    if ($video_mode === 'fopen') {
                        $video_url = home_url('/?gallery_for_immich_proxy=video&id=') . $asset['id'];
                    } else {
                        // Only create shared links on public pages, not in editor/preview
                        if ($this->should_create_shared_links()) {
                            $video_url = $this->get_video_url_with_shared_link($asset);
                        } else {
                            // In preview mode, use a placeholder
                            $video_url = '#';
                        }
                    }
                    $video_html = '<video class="gvideo-local" controls="controls" controlsList="" playsinline preload="metadata">';
                    $video_html .= '<source src="' . esc_url($video_url) . '" type="video/mp4">';
                    $video_html .= '</video>';
                    
                    $html .= '<a href="#" class="immich-lightbox immich-video-thumb" data-video="true" data-gallery="album-' . esc_attr($album['id']) . '" data-content="' . esc_attr($video_html) . '" data-width="90vw" data-height="90vh">';
                    $html .= '<img src="' . esc_url($thumb_url) . '" style="width:100%;height:' . $size . 'px;object-fit:cover;border-radius:6px;display:block;"' . $lazy_attr . '>';
                    $html .= '</a>';
                } else {
                    $live_photo_id = '';
                    if ($video_mode !== 'ignore' && !empty($asset['livePhotoVideoId']) && preg_match('/^[a-f0-9\-]{36}$/i', $asset['livePhotoVideoId'])) {
                        $live_photo_id = $asset['livePhotoVideoId'];
                    }
                    $live_attr = $live_photo_id ? ' data-live-photo-id="' . esc_attr($live_photo_id) . '"' : '';
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
            
            $immich_albums = $this->api_request('albums');
            // error_log(print_r($immich_albums, true));

            // Check for API error
            if (isset($immich_albums['error']) && $immich_albums['error']) {
                return '<p>' . __('Error from Immich API: ', 'gallery-for-immich') . esc_html($immich_albums['error']) . ': ' . esc_html($immich_albums['message']) . '</p>';
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

            // Render the albums
            foreach ($albums_to_render as $album) {
                if (empty($album['albumThumbnailAssetId'])) continue;
                $thumb_url = home_url('/?gallery_for_immich_proxy=thumbnail&id=') . $album['albumThumbnailAssetId'];

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
