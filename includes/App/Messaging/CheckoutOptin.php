<?php
namespace ACRFWP\App\Messaging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACRFW\App\Compliance\Consent;

/**
 * Settings → GDPR → WhatsApp / SMS opt-in (Pro).
 *
 * Meta's policy and SMS carrier rules expect customers to agree to these
 * messages. When gdpr.enable_whatsapp_optin / enable_sms_optin is on, this
 * adds an unticked checkbox under the checkout phone field (label:
 * gdpr.whatsapp_optin_label / sms_optin_label, or the default) through the
 * free plugin's `acrfw_checkout_optins` filter. The free plugin renders it on
 * both checkouts and saves the choice on the cart (whatsapp_optin /
 * sms_optin); the channel then sends only to carts that ticked it. With a
 * setting off there's no box and that channel goes to every cart with a phone.
 */
class CheckoutOptin {

	const CHANNELS = array( 'whatsapp', 'sms' );

	public static function init(): void {
		add_filter( 'acrfw_checkout_optins', array( self::class, 'add' ) );
	}

	/**
	 * @param array $optins Opt-ins so far.
	 */
	public static function add( $optins ): array {
		$optins = (array) $optins;

		foreach ( self::CHANNELS as $channel ) {
			if ( self::required( $channel ) ) {
				$optins[ $channel ] = array( 'label' => Consent::optin_label( $channel ) );
			}
		}

		return $optins;
	}

	/** Whether a channel sends only to carts that ticked its box. */
	public static function required( string $channel ): bool {
		return (bool) acrfw_setting( 'gdpr.enable_' . $channel . '_optin', false );
	}
}
