<?php
/**
 * Uninstall script for Immich Gallery plugin
 * 
 * This file is executed when the plugin is deleted via WordPress admin.
 * It removes all plugin data from the database.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('immich_gallery_settings');

// For multisite installations
delete_site_option('immich_gallery_settings');

// Note: We don't delete the translations as they're just files
// WordPress will remove the entire plugin directory automatically
