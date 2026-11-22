<?php
namespace ACRFWP\App\Sms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACRFWP\App\Messaging\Secrets;

/**
 * SMS connection (Integrations → SMS), stored in the `acrfwp_sms` option (not
 * autoloaded). The provider's secret (Twilio auth token) is encrypted at rest
 * (Messaging\Secrets) and never returned by the API.
 *
 * Sender: a phone number in E.164 (+15551234567), an alphanumeric sender ID
 * ("MYSHOP": 1–11 letters/digits/spaces, at least one letter; not allowed in
 * the US/Canada and can't receive STOP replies), or a Twilio Messaging
 * Service SID (MG…), which wins when both are set.
 */
class Settings {

	const OPTION    = 'acrfwp_sms';
	const SECRETS   = array( 'auth_token' );
	const PROVIDERS = array( 'twilio' );

	public static function defaults(): array {
		return array(
			'enabled'               => false,
			'provider'              => 'twilio',
			'account_sid'           => '',
			'auth_token'            => '',
			'from'                  => '',
			'messaging_service_sid' => '',
			'opt_out_text'          => 'Reply STOP to opt out',
			'stop_keywords'         => array( 'STOP', 'STOPALL', 'STOP ALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT', 'REVOKE' ),
			'start_keywords'        => array( 'START', 'UNSTOP', 'YES' ),
		);
	}

	private static function raw(): array {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array() );
	}

	/**
	 * One setting, with secrets decrypted.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$raw   = self::raw();
		$value = $raw[ $key ] ?? null;

		return in_array( $key, self::SECRETS, true ) ? Secrets::decrypt( (string) $value ) : $value;
	}

	/** Ready to send: on, with an account, a token and a sender. */
	public static function configured(): bool {
		return (bool) self::get( 'enabled' )
			&& '' !== (string) self::get( 'account_sid' )
			&& '' !== self::get( 'auth_token' )
			&& ( '' !== (string) self::get( 'from' ) || '' !== (string) self::get( 'messaging_service_sid' ) );
	}

	public static function status_url(): string {
		return rest_url( \ACRFW\API::NAMESPACE_NAME . '/' . \ACRFW\API::VERSION . '/sms/webhook/twilio/status' );
	}

	public static function inbound_url(): string {
		return rest_url( \ACRFW\API::NAMESPACE_NAME . '/' . \ACRFW\API::VERSION . '/sms/webhook/twilio/inbound' );
	}

	/** What the admin app sees: no secrets. */
	public static function for_response(): array {
		$raw = self::raw();

		return array(
			'enabled'               => (bool) $raw['enabled'],
			'provider'              => (string) $raw['provider'],
			'account_sid'           => (string) $raw['account_sid'],
			'from'                  => (string) $raw['from'],
			'messaging_service_sid' => (string) $raw['messaging_service_sid'],
			'opt_out_text'          => (string) $raw['opt_out_text'],
			'stop_keywords'         => array_values( (array) $raw['stop_keywords'] ),
			'start_keywords'        => array_values( (array) $raw['start_keywords'] ),
			'token_set'             => '' !== self::get( 'auth_token' ),
			'configured'            => self::configured(),
			'inbound_url'           => self::inbound_url(),
		);
	}

	/**
	 * Partial update. For the token, '' keeps the stored value and null clears it.
	 *
	 * @param array $input Fields to change.
	 * @return array Field => error message; empty when saved.
	 */
	public static function save( array $input ): array {
		$raw    = self::raw();
		$errors = array();

		if ( array_key_exists( 'enabled', $input ) ) {
			$raw['enabled'] = rest_sanitize_boolean( $input['enabled'] );
		}

		if ( array_key_exists( 'provider', $input ) ) {
			if ( in_array( $input['provider'], self::PROVIDERS, true ) ) {
				$raw['provider'] = (string) $input['provider'];
			} else {
				$errors['provider'] = __( 'Choose a supported SMS provider.', 'abandoned-cart-recovery-for-woocommerce-pro' );
			}
		}

		if ( array_key_exists( 'account_sid', $input ) ) {
			$sid = trim( (string) $input['account_sid'] );
			if ( '' !== $sid && ! preg_match( '/^AC[0-9a-fA-F]{32}$/', $sid ) ) {
				$errors['account_sid'] = __( 'The Account SID starts with AC followed by 32 letters and digits (Twilio Console → Account info).', 'abandoned-cart-recovery-for-woocommerce-pro' );
			} else {
				$raw['account_sid'] = $sid;
			}
		}

		if ( array_key_exists( 'messaging_service_sid', $input ) ) {
			$sid = trim( (string) $input['messaging_service_sid'] );
			if ( '' !== $sid && ! preg_match( '/^MG[0-9a-fA-F]{32}$/', $sid ) ) {
				$errors['messaging_service_sid'] = __( 'A Messaging Service SID starts with MG followed by 32 letters and digits.', 'abandoned-cart-recovery-for-woocommerce-pro' );
			} else {
				$raw['messaging_service_sid'] = $sid;
			}
		}

		if ( array_key_exists( 'from', $input ) ) {
			$from = trim( (string) $input['from'] );
			if ( '' !== $from && ! self::valid_sender( $from ) ) {
				$errors['from'] = __( 'Enter a phone number with its country code (+15551234567) or a sender name of up to 11 letters and digits.', 'abandoned-cart-recovery-for-woocommerce-pro' );
			} else {
				$raw['from'] = 0 === strpos( $from, '+' ) ? '+' . preg_replace( '/\D+/', '', $from ) : $from;
			}
		}

		if ( array_key_exists( 'auth_token', $input ) ) {
			if ( null === $input['auth_token'] ) {
				$raw['auth_token'] = '';
			} else {
				$token = trim( (string) $input['auth_token'] );
				if ( '' !== $token ) {
					if ( preg_match( '/\s/', $token ) || strlen( $token ) > 200 ) {
						$errors['auth_token'] = __( 'This doesn\'t look like a valid token: paste it again without spaces.', 'abandoned-cart-recovery-for-woocommerce-pro' );
					} else {
						$raw['auth_token'] = Secrets::encrypt( $token );
					}
				}
			}
		}

		if ( array_key_exists( 'opt_out_text', $input ) ) {
			$raw['opt_out_text'] = substr( sanitize_text_field( (string) $input['opt_out_text'] ), 0, 100 );
		}

		foreach ( array( 'stop_keywords', 'start_keywords' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$words = array();
			foreach ( (array) $input[ $key ] as $word ) {
				$word = strtoupper( trim( sanitize_text_field( (string) $word ) ) );
				if ( '' !== $word ) {
					$words[] = substr( $word, 0, 50 );
				}
			}
			if ( 'stop_keywords' === $key && ! $words ) {
				$errors[ $key ] = __( 'Keep at least one opt-out keyword, such as STOP.', 'abandoned-cart-recovery-for-woocommerce-pro' );
				continue;
			}
			$raw[ $key ] = array_values( array_unique( array_slice( $words, 0, 20 ) ) );
		}

		if ( $errors ) {
			return $errors;
		}

		update_option( self::OPTION, $raw, false );

		return array();
	}

	/** E.164 number, or an alphanumeric sender ID (1–11 chars, at least one letter). */
	public static function valid_sender( string $from ): bool {
		if ( 0 === strpos( $from, '+' ) ) {
			return (bool) preg_match( '/^\+\d{8,15}$/', '+' . preg_replace( '/\D+/', '', $from ) );
		}

		return (bool) preg_match( '/^(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11}$/', $from );
	}

	/** The configured provider. */
	public static function provider(): Providers\SmsProvider {
		return new Providers\Twilio();
	}
}
