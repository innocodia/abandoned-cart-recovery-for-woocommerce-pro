<?php
/**
 * Plugin Name:       Better Recovery Pro - Abandoned Cart Recovery for WooCommerce
 * Plugin URI:        https://innocodia.com/abandoned-cart-recovery-for-woocommerce
 * Description:       Pro add-on for Better Recovery: SMS and WhatsApp follow-ups, follow-up stats and more.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            InnoCodia
 * Author URI:        https://innocodia.com
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       abandoned-cart-recovery-for-woocommerce-pro
 * Domain Path:       /languages/
 * Requires Plugins:  woocommerce, abandoned-cart-recovery-for-woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Pro plugin main class.
 *
 * Same singleton + hook-dispatch pattern as the free plugin (ACRFW):
 * - define_constants() → sets all ACRFWP_* constants
 * - load_dependency()  → composer autoload + helpers
 * - init_plugin()      → dispatches all module classes
 *
 * Pro is an add-on: it starts on the free plugin's `acrfw_init` action, which
 * only fires once the free plugin has dispatched all of its own modules, so
 * every free class, table and hook is available here. If the free plugin is
 * missing or too old, Pro does nothing but show an admin notice.
 */
final class ACRFWP {

	/** Oldest free plugin version this Pro build works with. */
	const MIN_FREE_VERSION = '1.0.0';

	private function __construct() {
		$this->define_constants();
		$this->load_dependency();

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Registered at load time, so it works whichever plugin WordPress loads first.
		add_action( 'acrfw_init', array( $this, 'init_plugin' ) );
		add_action( 'plugins_loaded', array( $this, 'check_free_plugin' ), 20 );
	}

	/**
	 * Singleton accessor.
	 */
	public static function init(): self {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Define all plugin-wide constants.
	 */
	public function define_constants(): void {
		define( 'ACRFWP_VERSION', '1.0.0' );
		define( 'ACRFWP_PLUGIN_FILE', __FILE__ );
		define( 'ACRFWP_PLUGIN_SLUG', 'acrfwp' );
		define( 'ACRFWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
		define( 'ACRFWP_ROOT_DIR_PATH', plugin_dir_path( __FILE__ ) );
		define( 'ACRFWP_INCLUDES_PATH', ACRFWP_ROOT_DIR_PATH . 'includes/' );
		define( 'ACRFWP_PLUGIN_ROOT_URI', plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Load composer autoload and helper files.
	 */
	public function load_dependency(): void {
		// Composer PSR-4 autoloader (ACRFWP\ → includes/).
		require_once ACRFWP_ROOT_DIR_PATH . 'vendor/autoload.php';

		// Global helper functions (non-class utilities).
		require_once ACRFWP_INCLUDES_PATH . 'functions.php';

		// Global action/filter hooks wired to those functions.
		require_once ACRFWP_INCLUDES_PATH . 'hooks.php';
	}

	/**
	 * Initialize Pro — runs on the free plugin's acrfw_init.
	 */
	public function init_plugin(): void {
		if ( ! self::free_plugin_ok() ) {
			return;
		}

		do_action( 'acrfwp_before_init' );

		$this->dispatch_hooks();

		do_action( 'acrfwp_init' );
	}

	/**
	 * Central dispatcher — every top-level Pro module, each wiring its own hooks
	 * in a static init(), like ACRFW::dispatch_hooks() in the free plugin.
	 */
	public function dispatch_hooks(): void {
		ACRFWP\Installer::check_update();
		ACRFWP\API::init();
		ACRFWP\Frontend::init();

		if ( is_admin() ) {
			ACRFWP\Admin::init();
		}
	}

	/** Whether the free plugin is loaded and new enough. */
	public static function free_plugin_ok(): bool {
		return defined( 'ACRFW_VERSION' ) && version_compare( ACRFW_VERSION, self::MIN_FREE_VERSION, '>=' );
	}

	/**
	 * After every plugin has loaded: if the free plugin didn't start (inactive,
	 * missing or too old), tell the admin why Pro isn't running.
	 */
	public function check_free_plugin(): void {
		if ( did_action( 'acrfw_init' ) && self::free_plugin_ok() ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'free_plugin_notice' ) );
	}

	public function free_plugin_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$too_old = defined( 'ACRFW_VERSION' ) && ! self::free_plugin_ok();
		$message = $too_old
			/* translators: 1: required free plugin version, 2: installed version */
			? sprintf( __( 'Better Recovery Pro needs Better Recovery %1$s or newer (installed: %2$s). Please update it.', 'abandoned-cart-recovery-for-woocommerce-pro' ), self::MIN_FREE_VERSION, ACRFW_VERSION )
			: __( 'Better Recovery Pro needs the free Better Recovery plugin. Please install and activate it.', 'abandoned-cart-recovery-for-woocommerce-pro' );

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Runs on plugin activation.
	 */
	public function activate(): void {
		ACRFWP\Installer::init();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public function deactivate(): void {
		do_action( 'acrfwp_deactivated' );
	}
}

/**
 * Returns the Pro plugin instance.
 */
function acrfwp(): ACRFWP {
	return ACRFWP::init();
}

// Kick off the plugin.
acrfwp();

/**
 * Declare compatibility with WooCommerce features (HPOS, Cart & Checkout
 * blocks). Must run on before_woocommerce_init, ahead of plugins_loaded.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
);
