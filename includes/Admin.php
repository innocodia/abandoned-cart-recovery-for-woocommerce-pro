<?php
namespace ACRFWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin module — dispatches the Pro classes that only run in wp-admin.
 *
 * Each sub-class lives in includes/Admin/ and wires its own hooks in a
 * static init(). Pro has no admin UI of its own: the free plugin's admin app
 * reads Pro data through the REST API.
 *
 * To add a capability: create includes/Admin/YourClass.php (namespace
 * ACRFWP\Admin) with a static init(), then call it below.
 */
class Admin {

	public static function init(): void {
		// e.g. Admin\YourClass::init();
	}
}
