<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');

$gallery_for_immich_options = get_option('gallery_for_immich_settings');
// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Public image proxy, validated with UUID regex
$gallery_for_immich_id = isset($_GET['id']) ? wp_unslash($_GET['id']) : '';

// Validate ID: must be alphanumeric with hyphens (UUID format)
if (!$gallery_for_immich_id || !preg_match('/^[a-f0-9\-]{36}$/i', $gallery_for_immich_id)) {
    status_header(400);
    exit('Invalid ID format');
}

// Validate plugin is configured
if (empty($gallery_for_immich_options['server_url']) || empty($gallery_for_immich_options['api_key'])) {
    status_header(503);
    exit('Plugin not configured');
}

$gallery_for_immich_url = rtrim($gallery_for_immich_options['server_url'], '/') . '/api/assets/' . $gallery_for_immich_id . '/thumbnail?w=300&h=300';

$gallery_for_immich_resp = wp_remote_get($gallery_for_immich_url, [
    'headers' => ['x-api-key' => $gallery_for_immich_options['api_key']],
    'timeout' => 10,
    'sslverify' => true
]);

if (is_wp_error($gallery_for_immich_resp) || wp_remote_retrieve_response_code($gallery_for_immich_resp) != 200) {
    status_header(404);
    exit('Image not found');
}

// Security headers
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image data, cannot be escaped
echo wp_remote_retrieve_body($gallery_for_immich_resp);
exit;
