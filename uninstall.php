<?php
/**
 * Runs when the Pro plugin is deleted (Plugins → Delete).
 *
 * Pro keeps its data in the free plugin (settings, tables), which removes it
 * through its own uninstall when Settings → Advanced → "Delete Plugin Data on
 * Uninstall" is on. Pro only stores its own acrfwp_* options; they're removed
 * when that switch is on, or when the free plugin's settings are already gone
 * (the free plugin was deleted with its data first).
 *
 * @package ACRFWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Remove Pro's options on the current site when the free plugin's switch allows it. */
function acrfwp_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'acrfw_settings', null );
	$delete   = null === $settings || ( is_array( $settings ) && ! empty( $settings['advanced']['delete_plugin_data'] ) );
	if ( ! $delete ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, 'acrfwp\_%' ) );
	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $acrfwp_site_id ) {
		switch_to_blog( (int) $acrfwp_site_id );
		acrfwp_uninstall_site();
		restore_current_blog();
	}
} else {
	acrfwp_uninstall_site();
}
