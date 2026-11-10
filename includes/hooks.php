<?php
/**
 * Global action/filter hooks for Better Recovery Pro.
 *
 * Only truly global wiring belongs here; class-level hooks are registered in
 * each class's init().
 *
 * @package ACRFWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load Pro translations from /languages.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'abandoned-cart-recovery-for-woocommerce-pro', false, dirname( ACRFWP_PLUGIN_BASENAME ) . '/languages' );
	}
);
