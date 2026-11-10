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

/**
 * Tell the free plugin Pro is active (window.ACRFW.is_pro in its admin app).
 *
 * Answers with acrfwp_is_active(), not a plain true: this file loads before
 * Pro checks the free plugin, so is_pro is only true once Pro has started.
 */
add_filter( 'acrfw_is_pro', 'acrfwp_is_active' );
