<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * The plugin stores no settings. The only data it leaves behind is the short-lived
 * per-user result transient (scopedsr_flash_<user id>), removed here on every site.
 * Content changed by a replacement is the site's own content and is not touched.
 *
 * @package Scoped_Search_Replace
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function scopedsr_delete_transients(): void {
    global $wpdb;

    $like = $wpdb->esc_like('_transient_scopedsr_flash_') . '%';
    $timeout_like = $wpdb->esc_like('_transient_timeout_scopedsr_flash_') . '%';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout_like));

    // With a persistent object cache the transients live there instead and expire by themselves
    // within 5 minutes, so nothing else is needed.
}

if (is_multisite()) {
    $scopedsr_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($scopedsr_site_ids as $scopedsr_site_id) {
        switch_to_blog($scopedsr_site_id);
        scopedsr_delete_transients();
        restore_current_blog();
    }
} else {
    scopedsr_delete_transients();
}
