<?php
if (!defined('ABSPATH')) require_once('../../../wp-load.php');

$options = get_option('immich_gallery_settings');
$id = $_GET['id'] ?? '';
if (!$id) exit;

$url = rtrim($options['server_url'], '/') . '/api/assets/' . $id . '/thumbnail?w=300&h=300';

$resp = wp_remote_get($url, ['headers'=>['x-api-key'=>$options['api_key']]]);
if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp)!=200) exit;

header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen(wp_remote_retrieve_body($resp)));
echo wp_remote_retrieve_body($resp);
exit;
