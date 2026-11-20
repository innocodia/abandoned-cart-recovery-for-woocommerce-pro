<?php
namespace ACRFWP\App\WhatsApp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a phone number as typed at checkout into E.164 (+8801712345678),
 * using the billing country's calling code when the number has none.
 * No external library: good enough for mobile numbers, which is all
 * WhatsApp reaches.
 */
class PhoneNumber {

	/**
	 * @param string $raw     Number as typed.
	 * @param string $country Billing country (ISO 3166-1 alpha-2); empty = the store's.
	 * @return string E.164 with a leading +, or '' when it can't be a valid number.
	 */
	public static function to_e164( string $raw, string $country = '' ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$digits = preg_replace( '/\D+/', '', $raw );

		if ( 0 === strpos( $raw, '+' ) ) {
			$number = $digits;
		} elseif ( 0 === strpos( $digits, '00' ) ) {
			$number = substr( $digits, 2 );
		} else {
			$code = self::calling_code( $country );
			if ( '' === $code ) {
				return '';
			}

			// Typed with the country code but without "+" (8801712345678).
			if ( 0 === strpos( $digits, $code ) && strlen( $digits ) - strlen( $code ) >= 8 ) {
				$number = $digits;
			} else {
				// National format: drop the trunk prefix (01712345678 → 1712345678).
				$number = $code . ltrim( $digits, '0' );
			}
		}

		$length = strlen( $number );
		if ( $length < 8 || $length > 15 || 0 === strpos( $number, '0' ) ) {
			return '';
		}

		return '+' . $number;
	}

	/** Calling code digits for a country ('880'), or '' when unknown. */
	public static function calling_code( string $country ): string {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}

		$country = strtoupper( '' !== $country ? $country : (string) WC()->countries->get_base_country() );
		$code    = WC()->countries->get_country_calling_code( $country );
		$code    = is_array( $code ) ? (string) reset( $code ) : (string) $code;

		return (string) preg_replace( '/\D+/', '', $code );
	}
}
