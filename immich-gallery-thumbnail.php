<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');

$options = get_option('immich_gallery_settings');
$id = $_GET['id'] ?? '';

// Validate ID: must be alphanumeric with hyphens (UUID format)
if (!$id || !preg_match('/^[a-f0-9\-]{36}$/i', $id)) {
    status_header(400);
    exit('Invalid ID format');
}

// Validate plugin is configured
if (empty($options['server_url']) || empty($options['api_key'])) {
    status_header(503);
    exit('Plugin not configured');
}

$url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/thumbnail?w=300&h=300';

$resp = wp_remote_get($url, [
    'headers' => ['x-api-key' => $options['api_key']],
    'timeout' => 10,
    'sslverify' => true
]);

if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) != 200) {
    status_header(404);
    exit('Image not found');
}

// Security headers
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
echo wp_remote_retrieve_body($resp);
exit;
