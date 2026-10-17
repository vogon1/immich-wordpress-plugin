<?php
/**
 * Create test pages and compute the signed URLs the HTTP tests need.
 * Run inside wp-env by tests/run.js; tests/cleanup.php removes everything again.
 */

require __DIR__ . '/bootstrap.php';

$settings = get_option('gallery_for_immich_settings');
if (empty($settings['server_url']) || empty($settings['api_key'])) {
    gfi_test_output(['error' => 'Plugin not configured in wp-env; see TESTING.md.']);
    return;
}

// Keep the settings so cleanup can restore them after tests switch the video mode and the
// permalink structure.
if (!get_option('gallery_for_immich_test_backup')) {
    update_option('gallery_for_immich_test_backup', $settings, false);
}
if (get_option('gallery_for_immich_test_permalinks') === false) {
    update_option('gallery_for_immich_test_permalinks', ['structure' => get_option('permalink_structure')], false);
}

// An editor, to check that logged-in users see photos on private pages and drafts.
$editor_password = wp_generate_password(16, false);
$editor_id = username_exists('gfi-test-editor') ?: wp_insert_user([
    'user_login' => 'gfi-test-editor',
    'user_pass'  => $editor_password,
    'user_email' => 'gfi-test-editor@example.com',
    'role'       => 'editor',
]);
wp_set_password($editor_password, $editor_id);

// Uncached and unreduced: the selection below needs assetCount, which the plugin does not keep.
$albums = gfi_test_call('api_call', 'GET', 'albums');
if (!is_array($albums) || !empty($albums['error'])) {
    gfi_test_output(['error' => 'Immich albums could not be read: ' . wp_json_encode($albums)]);
    return;
}

// Two small albums with a thumbnail keep the tests fast.
$albums = array_values(array_filter($albums, function ($a) {
    return !empty($a['albumThumbnailAssetId']) && ($a['assetCount'] ?? 0) >= 3;
}));
usort($albums, function ($x, $y) {
    return $x['assetCount'] <=> $y['assetCount'];
});
if (count($albums) < 2) {
    gfi_test_output(['error' => 'Immich needs at least two albums with three or more photos.']);
    return;
}
[$album_a, $album_b] = $albums;

// Any video, for the streaming test.
$video = gfi_test_call('api_call', 'POST', 'search/metadata', gfi_test_call('use_structured_search')
    ? ['filter' => ['type' => ['eq' => 'VIDEO'], 'trashedAt' => ['eq' => null]], 'size' => 1]
    : ['type' => 'VIDEO', 'size' => 1]);
$video_id = $video['assets']['items'][0]['id'] ?? '';

$password = 'gfi-test-' . wp_generate_password(8, false);
$make_page = function ($title, $content, $extra = []) {
    $id = wp_insert_post(array_merge([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $content,
    ], $extra));
    update_post_meta($id, '_gallery_for_immich_test', 1);
    return ['id' => $id, 'url' => get_permalink($id)];
};

$album_shortcode = '[gallery_for_immich album="' . $album_a['id'] . '"]';
$pages = [
    'public'    => $make_page('GFI test: album', $album_shortcode),
    'overview'  => $make_page('GFI test: overview', '[gallery_for_immich albums="' . $album_a['id'] . '" show="gallery_name"]'),
    'password'  => $make_page('GFI test: password', $album_shortcode, ['post_password' => $password]),
    'private'   => $make_page('GFI test: private', $album_shortcode, ['post_status' => 'private']),
    'draft'     => $make_page('GFI test: draft', $album_shortcode, ['post_status' => 'draft']),
];
$pages['draft']['url'] = add_query_arg('preview', 'true', get_permalink($pages['draft']['id']));
if ($video_id) {
    $video_shortcode = '[gallery_for_immich asset="' . $video_id . '"]';
    $pages['video_public']   = $make_page('GFI test: video', $video_shortcode);
    $pages['video_password'] = $make_page('GFI test: video with password', $video_shortcode, ['post_password' => $password]);
}

$asset = $album_a['albumThumbnailAssetId'];
$url = function ($type, $asset_id, $post_id) {
    return gfi_test_call('proxy_url', $type, $asset_id, $post_id);
};

gfi_test_output([
    'album_a'   => $album_a['id'],
    'album_b'   => $album_b['id'],
    'asset'     => $asset,
    'pages'     => $pages,
    'password'  => $password,
    'editor'    => ['login' => 'gfi-test-editor', 'password' => $editor_password],
    'permalinks' => get_option('gallery_for_immich_test_permalinks')['structure'],
    'urls'      => [
        'public'   => $url('thumbnail', $asset, 0),
        'preview'  => $url('preview', $asset, 0),
        'password' => $url('thumbnail', $asset, $pages['password']['id']),
        'private'  => $url('thumbnail', $asset, $pages['private']['id']),
        'draft'    => $url('thumbnail', $asset, $pages['draft']['id']),
        'video'    => $video_id ? $url('video', $video_id, 0) : '',
    ],
    // A Live Photo signature for the password page; the asset need not exist, the endpoint
    // must refuse before it asks Immich.
    'live_photo' => [
        'asset_id' => 'a4138866-79fa-4e8a-af6a-1708463301b4',
        'post'     => $pages['password']['id'],
        'sig'      => gfi_test_call('live_photo_signature', 'a4138866-79fa-4e8a-af6a-1708463301b4', $pages['password']['id']),
        // Valid for a public page; Immich answers "not found" for this fake asset, which shows the
        // request got through the route and the signature check.
        'public_sig' => gfi_test_call('live_photo_signature', 'a4138866-79fa-4e8a-af6a-1708463301b4', 0),
    ],
]);
