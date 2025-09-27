<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');

$options = get_option('immich_gallery_settings');
$id = $_GET['id'] ?? '';
if (!$id) exit;

$url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/original';

$resp = wp_remote_get($url, ['headers'=>['x-api-key'=>$options['api_key']]]);
if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp)!=200) exit;

$body = wp_remote_retrieve_body($resp);
$content_type = wp_remote_retrieve_header($resp, 'content-type') ?: 'image/jpeg';

header('Content-Type: ' . $content_type);
header('Content-Length: ' . strlen($body)); // For correct scaling
echo $body;
exit;