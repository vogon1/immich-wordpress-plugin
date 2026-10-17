<?php
/**
 * Shared helpers for the test scripts. Loaded by the other tests/*.php files, which run inside
 * wp-env through `wp eval-file` (see tests/run.js).
 */

if (!defined('ABSPATH')) exit;

/**
 * Call a private method of the plugin class.
 */
function gfi_test_call($method, ...$args) {
    static $plugin = null;
    if (!$plugin) {
        $plugin = new Gallery_For_Immich();
    }
    $ref = new ReflectionMethod($plugin, $method);
    $ref->setAccessible(true);
    return $ref->invoke($plugin, ...$args);
}

/**
 * Print the data for tests/run.js between markers, so wp-env's own output is ignored.
 */
function gfi_test_output($data) {
    echo "\n@@GFI-JSON@@" . wp_json_encode($data) . "@@GFI-JSON@@\n";
}

/**
 * Assertion collector: each check becomes one result line in the test report.
 */
function gfi_test_check(&$results, $name, $ok, $detail = '') {
    $results[] = ['name' => $name, 'ok' => (bool) $ok, 'detail' => $ok ? '' : (string) $detail];
}
