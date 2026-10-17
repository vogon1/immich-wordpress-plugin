<?php
/**
 * Remove the test pages and restore the plugin settings. Run inside wp-env by tests/run.js.
 */

require __DIR__ . '/bootstrap.php';

$pages = get_posts([
    'post_type'   => 'any',
    'post_status' => 'any',
    'numberposts' => -1,
    'meta_key'    => '_gallery_for_immich_test',
    'fields'      => 'ids',
]);
foreach ($pages as $id) {
    wp_delete_post($id, true);
}

require_once ABSPATH . 'wp-admin/includes/user.php';
$editor_id = username_exists('gfi-test-editor');
if ($editor_id) {
    wp_delete_user($editor_id);
}

$permalinks = get_option('gallery_for_immich_test_permalinks');
if (is_array($permalinks)) {
    update_option('permalink_structure', $permalinks['structure']);
    flush_rewrite_rules();
    delete_option('gallery_for_immich_test_permalinks');
}

$backup = get_option('gallery_for_immich_test_backup');
if ($backup) {
    update_option('gallery_for_immich_settings', $backup);
    delete_option('gallery_for_immich_test_backup');
}

gfi_test_output(['deleted' => count($pages)]);
