<?php
/**
 * Checks of the plugin's internals: attribute parsing, signatures, search bodies, caching and
 * error messages. Run inside wp-env by tests/run.js.
 */

require __DIR__ . '/bootstrap.php';

$r = [];
$album_a = 'a4138866-79fa-4e8a-af6a-1708463301b4';
$album_b = 'b5249977-8a0b-4f9b-b07c-2819574412c5';

// --- Attribute parsing ---------------------------------------------------------------

$parse = function ($atts, $album_param = '') {
    return gfi_test_call('parse_attributes', $atts, $album_param);
};

foreach (['0' => 0, '1' => 1, '1000' => 1000, '1001' => 0, '-5' => 0, 'abc' => 0] as $in => $want) {
    $got = $parse(['album' => $album_a, 'limit' => (string) $in])['limit'];
    gfi_test_check($r, "limit=\"$in\" becomes $want", $got === $want, "got $got");
}
gfi_test_check($r, 'pick defaults to first', $parse(['album' => $album_a])['pick'] === 'first');
gfi_test_check($r, 'invalid pick becomes first', $parse(['album' => $album_a, 'pick' => 'all'])['pick'] === 'first');
gfi_test_check($r, 'pick="random" is kept', $parse(['album' => $album_a, 'pick' => 'random'])['pick'] === 'random');

$show = $parse(['show' => 'gallery_name, asset_date,bogus'])['show'];
gfi_test_check($r, 'show keeps only known values', array_values($show) === ['gallery_name', 'asset_date'], wp_json_encode($show));

$opened = $parse(['albums' => $album_a, 'show' => 'gallery_name', 'detail_show' => 'asset_date', 'limit' => '2', 'link' => 'https://example.com/'], $album_a);
gfi_test_check($r, 'album opened from overview uses detail_show', array_values($opened['show']) === ['asset_date'], wp_json_encode($opened['show']));
gfi_test_check($r, 'album opened from overview shows all photos', $opened['limit'] === 0);
gfi_test_check($r, 'album opened from overview uses the lightbox', $opened['link_type'] === 'lightbox');
gfi_test_check($r, 'empty detail_show shows no text', $parse(['detail_show' => '', 'show' => 'gallery_name'], $album_a)['show'] === []);

gfi_test_check($r, 'URL album outside albums= is ignored', $parse(['albums' => $album_a], $album_b)['album'] === '');
gfi_test_check($r, 'URL album never overrides album=', $parse(['album' => $album_a], $album_b)['album'] === $album_a);
gfi_test_check($r, 'URL album is ignored by asset=', $parse(['asset' => $album_a], $album_b)['album'] === '');
gfi_test_check($r, 'URL album opens from an unrestricted overview', $parse([], $album_b)['album'] === $album_b);

$link = $parse(['link' => 'https://example.com/x', 'link_target' => 'same']);
gfi_test_check($r, 'link URL is a custom link', $link['link_type'] === 'custom' && $link['link_url_val'] === 'https://example.com/x');
gfi_test_check($r, 'link_target="same" has no target attribute', $link['link_target_attr'] === '');
gfi_test_check($r, 'link_target defaults to a new tab', strpos($parse(['link' => 'https://example.com/'])['link_target_attr'], '_blank') !== false);
gfi_test_check($r, 'unknown link value falls back to lightbox', $parse(['link' => 'javascript:alert(1)'])['link_type'] === 'lightbox');

// --- Signatures ----------------------------------------------------------------------

gfi_test_check($r, 'Live Photo signature without post matches 0.9 (cached pages keep working)',
    gfi_test_call('live_photo_signature', $album_a, 0) === hash_hmac('sha256', 'live-photo|' . $album_a, wp_salt('auth')));
gfi_test_check($r, 'Live Photo signature depends on the post',
    gfi_test_call('live_photo_signature', $album_a, 5) !== gfi_test_call('live_photo_signature', $album_a, 6));
gfi_test_check($r, 'proxy signature depends on the post',
    gfi_test_call('proxy_signature', $album_a, 0) !== gfi_test_call('proxy_signature', $album_a, 5));
gfi_test_check($r, 'proxy signature ignores UUID case',
    gfi_test_call('proxy_signature', strtoupper($album_a), 5) === gfi_test_call('proxy_signature', $album_a, 5));

// --- Protected posts -------------------------------------------------------------------

$protected_id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'GFI unit', 'post_password' => 'x']);
$public_id    = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'GFI unit']);
$shared_mode  = function ($value) {
    $value['video_mode'] = 'shared';
    return $value;
};
add_filter('option_gallery_for_immich_settings', $shared_mode);
gfi_test_check($r, 'a password page is protected', gfi_test_call('is_protected_post', $protected_id) === true);
gfi_test_check($r, 'a published page is not protected', gfi_test_call('is_protected_post', $public_id) === false);
gfi_test_check($r, 'mode "shared": videos on a protected page go through the proxy', gfi_test_call('video_via_proxy', $protected_id) === true);
gfi_test_check($r, 'mode "shared": videos on a public page use shared links', gfi_test_call('video_via_proxy', $public_id) === false);
remove_filter('option_gallery_for_immich_settings', $shared_mode);
gfi_test_check($r, 'visitors may see a published page', gfi_test_call('visitor_can_view_post', $public_id, $album_a) === true);
gfi_test_check($r, 'visitors may not see a password page without its cookie', gfi_test_call('visitor_can_view_post', $protected_id, $album_a) === false);
$members_only = function ($allowed, $post_id) use ($public_id) {
    return $post_id === $public_id ? false : $allowed;
};
add_filter('gallery_for_immich_can_view', $members_only, 10, 2);
gfi_test_check($r, 'gallery_for_immich_can_view can refuse a visitor', gfi_test_call('visitor_can_view_post', $public_id, $album_a) === false);
remove_filter('gallery_for_immich_can_view', $members_only, 10);
wp_delete_post($protected_id, true);
wp_delete_post($public_id, true);

// --- Media types -----------------------------------------------------------------------

gfi_test_check($r, 'WebP thumbnails keep their type', gfi_test_call('safe_media_type', 'image/webp', 'image', 'image/jpeg') === 'image/webp');
gfi_test_check($r, 'parameters are stripped from the type', gfi_test_call('safe_media_type', 'image/jpeg; charset=binary', 'image', 'x') === 'image/jpeg');
gfi_test_check($r, 'SVG is never passed on', gfi_test_call('safe_media_type', 'image/svg+xml', 'image', 'image/jpeg') === 'image/jpeg');
gfi_test_check($r, 'HTML is never passed on as video', gfi_test_call('safe_media_type', 'text/html', 'video', 'video/mp4') === 'video/mp4');

// --- Search bodies ---------------------------------------------------------------------

$query = ['album_id' => $album_a, 'images_only' => true, 'size' => 10, 'order' => 'desc', 'next' => 'abc'];
$structured = gfi_test_call('search_body', 'search/metadata', $query, true);
gfi_test_check($r, 'structured search filters on the album', ($structured['filter']['albumIds']['any'] ?? null) === [$album_a]);
gfi_test_check($r, 'structured search leaves out trashed assets', array_key_exists('trashedAt', $structured['filter']) && $structured['filter']['trashedAt'] === ['eq' => null]);
gfi_test_check($r, 'structured search leaves out videos', ($structured['filter']['type'] ?? null) === ['eq' => 'IMAGE']);
gfi_test_check($r, 'structured search orders by date taken', ($structured['orderBy'] ?? null) === ['field' => 'fileCreatedAt', 'direction' => 'desc']);
gfi_test_check($r, 'structured search pages with a cursor', ($structured['cursor'] ?? null) === 'abc' && !isset($structured['albumIds']) && !isset($structured['page']));
gfi_test_check($r, 'structured JSON sends trashedAt.eq as null', strpos(wp_json_encode($structured), '"trashedAt":{"eq":null}') !== false);

$legacy = gfi_test_call('search_body', 'search/metadata', ['next' => '2'] + $query, false);
gfi_test_check($r, 'flat search uses albumIds, type, order and page',
    $legacy['albumIds'] === [$album_a] && $legacy['type'] === 'IMAGE' && $legacy['order'] === 'desc' && $legacy['page'] === 2 && !isset($legacy['filter']));

$random = gfi_test_call('search_body', 'search/random', $query, true);
gfi_test_check($r, 'random search has no order or cursor', !isset($random['orderBy']) && !isset($random['cursor']));

// --- Version detection -----------------------------------------------------------------

$version_key = gfi_test_call('version_cache_key');
$saved_version = get_transient($version_key);
$structured_for = function ($major, $minor) use ($version_key) {
    set_transient($version_key, ['known' => true, 'major' => $major, 'minor' => $minor, 'patch' => 0], 60);
    return gfi_test_call('use_structured_search');
};
gfi_test_check($r, 'Immich 3.1 uses the flat search', $structured_for(3, 1) === false);
gfi_test_check($r, 'Immich 3.2 uses the structured search', $structured_for(3, 2) === true);
gfi_test_check($r, 'Immich 4.0 uses the structured search', $structured_for(4, 0) === true);
set_transient($version_key, ['known' => false], 60);
gfi_test_check($r, 'unknown version uses the flat search', gfi_test_call('use_structured_search') === false);
$saved_version ? set_transient($version_key, $saved_version, HOUR_IN_SECONDS) : delete_transient($version_key);

// --- Caching -----------------------------------------------------------------------------

$requests = 0;
$count = function ($pre, $args, $url) use (&$requests) {
    if (strpos($url, '/api/albums') !== false) {
        $requests++;
    }
    return $pre;
};
add_filter('pre_http_request', $count, 10, 3);

gfi_test_call('clear_cache');
gfi_test_call('api_request', 'albums');
gfi_test_call('api_request', 'albums');
gfi_test_check($r, 'a second album list comes from the cache', $requests === 1, "$requests requests");
gfi_test_call('clear_cache');
gfi_test_call('api_request', 'albums');
gfi_test_check($r, 'clearing the cache fetches again', $requests === 2, "$requests requests");

$force_ttl = function ($value) {
    $value['cache_ttl'] = 0;
    return $value;
};
add_filter('option_gallery_for_immich_settings', $force_ttl);
gfi_test_call('api_request', 'albums');
gfi_test_check($r, 'cache off always fetches', $requests === 3, "$requests requests");
remove_filter('option_gallery_for_immich_settings', $force_ttl);
remove_filter('pre_http_request', $count, 10);

// Errors are not cached, so a failing Immich is retried on the next page view.
$fail = function () {
    return ['headers' => [], 'body' => '{"message":"Invalid API key"}', 'response' => ['code' => 401, 'message' => 'Unauthorized'], 'cookies' => []];
};
add_filter('pre_http_request', $fail);
gfi_test_call('clear_cache');
$error = gfi_test_call('api_request', 'albums');
remove_filter('pre_http_request', $fail);
gfi_test_check($r, 'Immich error message is kept', ($error['status'] ?? 0) === 401 && ($error['message'] ?? '') === 'Invalid API key', wp_json_encode($error));
gfi_test_check($r, 'an error is not cached', empty(gfi_test_call('api_request', 'albums')['error']));

// Cached responses hold only the fields the plugin uses.
$full_album = ['id' => $album_a, 'albumName' => 'A', 'owner' => ['email' => 'owner@example.com'], 'albumUsers' => [['user' => ['email' => 'guest@example.com']]], 'assets' => []];
$full_asset = ['id' => $album_a, 'type' => 'IMAGE', 'ownerId' => 'x', 'exifInfo' => ['description' => 'D', 'latitude' => 52.1, 'longitude' => 5.1, 'make' => 'Apple', 'city' => 'Utrecht']];
$slim_album = gfi_test_call('slim_album', $full_album);
$slim_asset = gfi_test_call('slim_asset', $full_asset);
gfi_test_check($r, 'cached albums hold no e-mail addresses', strpos(wp_json_encode($slim_album), '@') === false && $slim_album['albumName'] === 'A', wp_json_encode($slim_album));
gfi_test_check($r, 'cached assets hold no location or camera data', array_keys($slim_asset['exifInfo']) === ['description', 'dateTimeOriginal'] && !isset($slim_asset['ownerId']), wp_json_encode($slim_asset));
$mock_album = function ($pre, $args, $url) use ($full_album) {
    return strpos($url, '/api/albums/') !== false
        ? ['headers' => [], 'body' => wp_json_encode($full_album), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []]
        : $pre;
};
add_filter('pre_http_request', $mock_album, 10, 3);
gfi_test_call('clear_cache');
gfi_test_call('get_album', $album_a);
remove_filter('pre_http_request', $mock_album, 10);
global $wpdb;
$cached_rows = $wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_gallery\\_for\\_immich\\_c\\_%'");
gfi_test_check($r, 'the album is cached without e-mail addresses', $cached_rows && strpos(implode('', $cached_rows), 'example.com') === false, count($cached_rows) . ' rows');
gfi_test_call('clear_cache');

// --- Error messages in galleries --------------------------------------------------------

$html_proxy = function () {
    return ['headers' => ['content-type' => 'text/html'], 'body' => '<html>Login</html>', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
};
add_filter('pre_http_request', $html_proxy);
gfi_test_call('clear_cache');
$error = gfi_test_call('api_call', 'GET', 'albums');
gfi_test_check($r, 'a login page in front of Immich is reported', ($error['error'] ?? false) === true && strpos($error['message'], 'JSON') !== false, wp_json_encode($error));

wp_set_current_user(0);
$visitor = do_shortcode('[gallery_for_immich]');
wp_set_current_user(1);
$admin = do_shortcode('[gallery_for_immich]');
wp_set_current_user(0);
remove_filter('pre_http_request', $html_proxy);
gfi_test_call('clear_cache');

gfi_test_check($r, 'visitors see a plain message', strpos($visitor, 'No albums found.') !== false && strpos($visitor, 'immich-admin-error') === false, $visitor);
gfi_test_check($r, 'administrators also see the Immich error', strpos($admin, 'immich-admin-error') !== false && strpos($admin, 'HTTP 200') !== false, $admin);
gfi_test_check($r, 'the "Error from Immich API: 1" bug is gone', strpos($visitor . $admin, ': 1:') === false);

// --- Rendering with the real Immich ------------------------------------------------------

$php_errors = [];
set_error_handler(function ($errno, $errstr, $file, $line) use (&$php_errors) {
    $php_errors[] = "$errstr ($file:$line)";
    return true;
});
$invalid_list = do_shortcode('[gallery_for_immich albums="invalid-id"]');
restore_error_handler();
gfi_test_check($r, 'albums="invalid-id" renders without PHP errors', $php_errors === [] && $invalid_list !== '', implode('; ', $php_errors));

// A well-formed ID of an album that does not exist: Immich answers 400.
wp_set_current_user(0);
$visitor = do_shortcode('[gallery_for_immich album="' . $album_a . '"]');
wp_set_current_user(1);
$admin = do_shortcode('[gallery_for_immich album="' . $album_a . '"]');
wp_set_current_user(0);
gfi_test_check($r, 'unknown album: visitors see "No photos found in this album."', strpos($visitor, 'No photos found in this album.') !== false && strpos($visitor, 'immich-admin-error') === false, $visitor);
gfi_test_check($r, 'unknown album: administrators also see the Immich error', strpos($admin, 'immich-admin-error') !== false && strpos($admin, 'HTTP 400') !== false, $admin);

$api_key = get_option('gallery_for_immich_settings')['api_key'] ?? '';
$albums  = gfi_test_call('get_albums');
$first   = '';
foreach ((array) $albums as $album) {
    if (!empty($album['albumThumbnailAssetId'])) {
        $first = $album['id'];
        break;
    }
}
$pages_html = do_shortcode('[gallery_for_immich]') . ($first ? do_shortcode('[gallery_for_immich album="' . $first . '"]') : '');
ob_start();
wp_enqueue_scripts();
wp_print_scripts();
wp_print_styles();
$pages_html .= ob_get_clean();
gfi_test_check($r, 'the API key is not in the page source', $api_key !== '' && strpos($pages_html, 'gallery_for_immich_proxy') !== false && strpos($pages_html, $api_key) === false);

gfi_test_output(['results' => $r]);
