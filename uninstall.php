<?php
/**
 * Uninstall script for Gallery for Immich plugin
 * 
 * This file is executed when the plugin is deleted via WordPress admin.
 * It removes all plugin data from the database.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('gallery_for_immich_settings');

// For multisite installations
delete_site_option('gallery_for_immich_settings');

// Cached Immich responses, the cached server version, and shared links waiting for cleanup
delete_option('gallery_for_immich_cache_gen');
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transients cannot be listed through the API; one-off cleanup on uninstall.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_gallery_for_immich_') . '%',
        $wpdb->esc_like('_transient_timeout_gallery_for_immich_') . '%'
    )
);

// Note: We don't delete the translations as they're just files
// WordPress will remove the entire plugin directory automatically
