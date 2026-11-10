<?php
/**
 * Global helper functions for Better Recovery Pro.
 *
 * Only non-class helpers belong here. Prefix everything with acrfwp_.
 * Free-plugin helpers (acrfw_setting(), acrfw_table(), …) are available too.
 *
 * @package ACRFWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Pro is running: the free plugin is active and new enough, and Pro
 * has dispatched its modules.
 *
 * @return bool
 */
if ( ! function_exists( 'acrfwp_is_active' ) ) {
	function acrfwp_is_active(): bool {
		return did_action( 'acrfwp_init' ) > 0;
	}
}
