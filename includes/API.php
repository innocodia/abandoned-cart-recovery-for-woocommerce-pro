<?php
namespace ACRFWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API module — dispatches every Pro REST controller.
 *
 * Each sub-class lives in includes/API/, extends WP_REST_Controller and
 * registers its routes on rest_api_init from its own static init(). Routes
 * use the free plugin's namespace (ACRFW\API::NAMESPACE_NAME / VERSION) so
 * the admin app talks to one API.
 *
 * To add a capability: create includes/API/YourClass.php (namespace
 * ACRFWP\API) with a static init(), then call it below.
 */
class API {

	public static function init(): void {
		API\ProductsReport::init(); // GET /reports/products (+ /export).
	}
}
