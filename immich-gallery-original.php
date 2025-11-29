<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');

$immich_gallery_options = get_option('immich_gallery_settings');
// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Public image proxy, validated with UUID regex
$immich_gallery_id = isset($_GET['id']) ? wp_unslash($_GET['id']) : '';

// Validate ID: must be alphanumeric with hyphens (UUID format)
if (!$immich_gallery_id || !preg_match('/^[a-f0-9\-]{36}$/i', $immich_gallery_id)) {
    status_header(400);
    exit('Invalid ID format');
}

// Validate plugin is configured
if (empty($immich_gallery_options['server_url']) || empty($immich_gallery_options['api_key'])) {
    status_header(503);
    exit('Plugin not configured');
}

$immich_gallery_url = rtrim($immich_gallery_options['server_url'], '/') . '/api/assets/' . $immich_gallery_id . '/original';

$immich_gallery_resp = wp_remote_get($immich_gallery_url, [
    'headers' => ['x-api-key' => $immich_gallery_options['api_key']],
    'timeout' => 30, // Longer timeout for full images
    'sslverify' => true
]);

if (is_wp_error($immich_gallery_resp) || wp_remote_retrieve_response_code($immich_gallery_resp) != 200) {
    status_header(404);
    exit('Image not found');
}

// Security headers
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image data, cannot be escaped
echo wp_remote_retrieve_body($immich_gallery_resp);
exit;