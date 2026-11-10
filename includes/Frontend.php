<?php
namespace ACRFWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend module — dispatches the Pro classes that hook into the storefront
 * and into the free plugin's actions and filters (e.g. acrfw_followup_channels,
 * acrfw_followup_send for SMS / WhatsApp delivery).
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
