<?php
namespace ACRFWP\App\Gdpr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → GDPR → Phone GDPR consent (Pro).
 *
 * Adds the phone notice to the free plugin's `acrfw_gdpr_notices` filter
 * when gdpr.enable_phone_gdpr is on, with the admin's text
 * (gdpr.phone_gdpr_message; the default when empty). The free plugin does the rest: it shows
 * the notice under the checkout phone field (classic and blocks), accepts
 * "No thanks" (POST /capture/opt-out with field=phone), and from then on
 * never saves the customer's phone number, so no SMS or WhatsApp reminder
 * can reach them. Email reminders continue.
 *
 * Pro deactivated: no phone notice and phone opt-out is refused (400). Phones
 * already cleared stay cleared, and the session's opt-out is still honoured.
 */
class PhoneConsent {

	public static function init(): void {
		add_filter( 'acrfw_gdpr_notices', array( self::class, 'add_notice' ) );
	}

	/**
	 * acrfw_gdpr_notices callback.
	 *
	 * @param array $notices Notices keyed by field.
	 */
	public static function add_notice( $notices ): array {
		$notices = (array) $notices;

		if ( acrfw_setting( 'gdpr.enable_phone_gdpr', false ) ) {
			$notices['phone'] = array(
				'text' => \ACRFW\App\Compliance\Consent::message( 'phone' ),
				'link' => __( 'No thanks', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			);
		}

		return $notices;
	}
}
