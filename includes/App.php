<?php
namespace ACRFWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * App module — dispatches the Pro services that plug into the free plugin's
 * actions and filters (no REST routes, no storefront output). For example
 * App\Stats\FollowupStats answers `acrfw_followup_stats`.
 *
 * Each service lives under includes/App/<Area>/ and wires its own hooks in a
 * static init(). REST controllers go in API/, storefront hooks in Frontend/.
 */
class App {

	public static function init(): void {
		App\Stats\FollowupStats::init();
	}
}
