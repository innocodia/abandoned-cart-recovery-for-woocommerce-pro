<?php
namespace ACRFWP\App\WhatsApp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → GDPR → WhatsApp opt-in (Pro).
 *
 * Meta's policy requires customers to agree to WhatsApp messages. When
 * gdpr.enable_whatsapp_optin is on, this adds an unticked checkbox under the
 * checkout phone field (label: gdpr.whatsapp_optin_label, or the default)
 * through the free plugin's `acrfw_checkout_optins` filter. The free plugin
 * renders it on both checkouts and saves the choice on the cart
 * (abandoned_carts.whatsapp_optin); WhatsAppChannel then sends only to carts
 * that ticked it. With the setting off there's no checkbox and WhatsApp goes
 * to every cart with a phone number.
 */
class CheckoutOptin {

	public static function init(): void {
		add_filter( 'acrfw_checkout_optins', array( self::class, 'add' ) );
	}

	/**
	 * @param array $optins Opt-ins so far.
	 */
	public static function add( $optins ): array {
		$optins = (array) $optins;

		if ( acrfw_setting( 'gdpr.enable_whatsapp_optin', false ) ) {
			$optins['whatsapp'] = array( 'label' => \ACRFW\App\Compliance\Consent::optin_label( 'whatsapp' ) );
		}

		return $optins;
	}
}
