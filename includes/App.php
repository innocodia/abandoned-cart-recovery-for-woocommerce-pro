<?php
namespace ACRFWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * App module — dispatches the Pro services that plug into the free plugin's
 * actions and filters (no REST routes, no storefront output):
 * - App\Stats\FollowupStats answers `acrfw_followup_stats`.
 * - App\Conditions\Conditions answers `acrfw_validate_conditions` and
 *   `acrfw_conditions_match`.
 * - App\Blacklist\Blacklist answers `acrfw_is_blacklisted`.
 * - App\Gdpr\PhoneConsent adds the phone notice to `acrfw_gdpr_notices`.
 * - App\WhatsApp\WhatsAppChannel makes WhatsApp available and sends it
 *   (`acrfw_followup_channels`, `acrfw_followup_send`).
 * - App\Sms\SmsChannel makes SMS available and sends it through the
 *   provider (Twilio).
 * - App\Messaging\CheckoutOptin adds the WhatsApp / SMS opt-in checkboxes to
 *   `acrfw_checkout_optins`.
 *
 * Each service lives under includes/App/<Area>/ and wires its own hooks in a
 * static init(). REST controllers go in API/, storefront hooks in Frontend/.
 */
class App {

	public static function init(): void {
		App\Stats\FollowupStats::init();
		App\Conditions\Conditions::init();
		App\Blacklist\Blacklist::init();
		App\Gdpr\PhoneConsent::init();
		App\WhatsApp\WhatsAppChannel::init();
		App\Sms\SmsChannel::init();
		App\Messaging\CheckoutOptin::init();
	}
}
