<?php
namespace ACRFWP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Better Recovery → License: the Freemius license page for Pro.
 *
 * Freemius is initialised in the plugin bootstrap with the menu slug
 * `acrfw-license` under the free plugin's `acrfw` menu. This adds the
 * "License" submenu for that slug and sends it on to Freemius' own page:
 * - license activated → the account page (`acrfw-license-account`): license
 *   key, plan, renewal, deactivate;
 * - not activated yet → Freemius' activation screen (it takes over the
 *   `acrfw-license` page itself until a license is entered).
 *
 * The redirect runs on the page's load-{hook} action, before any output, so
 * it is a real HTTP redirect (same idea as Better Badge Pro's license menu).
 */
class License {

	const SLUG = 'acrfw-license';

	public static function init(): void {
		// After the free plugin's menu (priority 10) so the parent exists.
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 11 );
	}

	public static function add_menu(): void {
		$hook = add_submenu_page(
			'acrfw',
			__( 'License', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			__( 'License', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'maybe_redirect' ) );
		}
	}

	/** Activated license: go to the Freemius account page. */
	public static function maybe_redirect(): void {
		if ( ! function_exists( 'acrfwp_fs' ) || ! acrfwp_fs()->is_registered() ) {
			return; // Freemius shows its activation screen on this page.
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-account' ) );
		exit;
	}

	/**
	 * Fallback output; Freemius normally replaces this page (activation) or
	 * maybe_redirect() has already left it (account).
	 */
	public static function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Better Recovery Pro license', 'abandoned-cart-recovery-for-woocommerce-pro' ) . '</h1></div>';
	}
}
