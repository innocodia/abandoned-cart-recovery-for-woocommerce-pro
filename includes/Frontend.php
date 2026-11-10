<?php
namespace ACRFWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend module — dispatches the Pro classes that hook into the storefront
 * (checkout, cart, customer-facing pages). Services that only answer the free
 * plugin's filters go in App/ instead.
 *
 * Each sub-class lives in includes/Frontend/ and wires its own hooks in a
 * static init().
 *
 * To add a capability: create includes/Frontend/YourClass.php (namespace
 * ACRFWP\Frontend) with a static init(), then call it below.
 */
class Frontend {

	public static function init(): void {
		// e.g. Frontend\YourClass::init();
	}
}
