<?php
namespace ACRFWP\App\WhatsApp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WhatsApp Cloud API connection (Integrations → WhatsApp), stored in the
 * `acrfwp_whatsapp` option (not autoloaded), outside the free plugin's
 * settings so secrets never travel with them.
 *
 * The access token and app secret are encrypted at rest (libsodium secretbox,
 * key derived from the site's AUTH salt) and never returned by the API. If the
 * salts change, they can't be decrypted any more: get() returns '' and the
 * admin enters them again.
 */
class Settings {

	const OPTION  = 'acrfwp_whatsapp';
	const SECRETS = array( 'access_token', 'app_secret' );
	const PREFIX  = 'enc:';

	public static function defaults(): array {
		return array(
			'enabled'         => false,
			'phone_number_id' => '',
			'waba_id'         => '',
			'access_token'    => '',
			'app_secret'      => '',
			'verify_token'    => '',
			'display_phone'   => '',
			'stop_keywords'   => array( 'STOP', 'UNSUBSCRIBE', 'STOP PROMOTIONS' ),
		);
	}

	/** Stored values merged with the defaults; secrets still encrypted. */
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

		return in_array( $key, self::SECRETS, true ) ? self::decrypt( (string) $value ) : $value;
	}

	/** Ready to send: switched on, with a phone number ID and a token. */
	public static function configured(): bool {
		return (bool) self::get( 'enabled' ) && '' !== (string) self::get( 'phone_number_id' ) && '' !== self::get( 'access_token' );
	}

	/** Verify token for Meta's webhook handshake; created on first use. */
	public static function verify_token(): string {
		$raw = self::raw();
		if ( '' === (string) $raw['verify_token'] ) {
			$raw['verify_token'] = wp_generate_password( 32, false );
			update_option( self::OPTION, $raw, false );
		}

		return (string) $raw['verify_token'];
	}

	public static function webhook_url(): string {
		return rest_url( \ACRFW\API::NAMESPACE_NAME . '/' . \ACRFW\API::VERSION . '/whatsapp/webhook' );
	}

	/** What the admin app sees: no secrets, only whether they're set. */
	public static function for_response(): array {
		$raw = self::raw();

		return array(
			'enabled'         => (bool) $raw['enabled'],
			'phone_number_id' => (string) $raw['phone_number_id'],
			'waba_id'         => (string) $raw['waba_id'],
			'display_phone'   => (string) $raw['display_phone'],
			'stop_keywords'   => array_values( (array) $raw['stop_keywords'] ),
			'token_set'       => '' !== self::get( 'access_token' ),
			'app_secret_set'  => '' !== self::get( 'app_secret' ),
			'configured'      => self::configured(),
			'verify_token'    => self::verify_token(),
			'webhook_url'     => self::webhook_url(),
		);
	}

	/**
	 * Partial update. For a secret, '' keeps the stored value and null clears it.
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

		foreach ( array( 'phone_number_id', 'waba_id' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = trim( (string) $input[ $key ] );
			if ( '' !== $value && ! ctype_digit( $value ) ) {
				/* translators: %s: field name. */
				$errors[ $key ] = sprintf( __( '%s must contain digits only (copy it from Meta: WhatsApp → API Setup).', 'abandoned-cart-recovery-for-woocommerce-pro' ), 'phone_number_id' === $key ? __( 'Phone number ID', 'abandoned-cart-recovery-for-woocommerce-pro' ) : __( 'WhatsApp Business Account ID', 'abandoned-cart-recovery-for-woocommerce-pro' ) );
				continue;
			}
			if ( $value !== $raw[ $key ] && 'phone_number_id' === $key ) {
				$raw['display_phone'] = '';
			}
			$raw[ $key ] = $value;
		}

		foreach ( self::SECRETS as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			if ( null === $input[ $key ] ) {
				$raw[ $key ] = '';
				continue;
			}
			$value = trim( (string) $input[ $key ] );
			if ( '' === $value ) {
				continue; // Keep the stored one.
			}
			if ( preg_match( '/\s/', $value ) || strlen( $value ) > 1000 ) {
				$errors[ $key ] = __( 'This doesn\'t look like a valid value: paste it again without spaces.', 'abandoned-cart-recovery-for-woocommerce-pro' );
				continue;
			}
			$raw[ $key ] = self::encrypt( $value );
		}

		if ( array_key_exists( 'stop_keywords', $input ) ) {
			$words = array();
			foreach ( (array) $input['stop_keywords'] as $word ) {
				$word = strtoupper( trim( sanitize_text_field( (string) $word ) ) );
				if ( '' !== $word ) {
					$words[] = substr( $word, 0, 50 );
				}
			}
			if ( ! $words ) {
				$errors['stop_keywords'] = __( 'Keep at least one opt-out keyword, such as STOP.', 'abandoned-cart-recovery-for-woocommerce-pro' );
			} else {
				$raw['stop_keywords'] = array_values( array_unique( array_slice( $words, 0, 20 ) ) );
			}
		}

		if ( $errors ) {
			return $errors;
		}

		if ( '' === (string) $raw['verify_token'] ) {
			$raw['verify_token'] = wp_generate_password( 32, false );
		}

		update_option( self::OPTION, $raw, false );
		Templates::flush();

		return array();
	}

	/** Remember the number Meta reports for the phone number ID (verify). */
	public static function set_display_phone( string $phone ): void {
		$raw                  = self::raw();
		$raw['display_phone'] = sanitize_text_field( $phone );
		update_option( self::OPTION, $raw, false );
	}

	// -------------------------------------------------------------------------
	// Encryption
	// -------------------------------------------------------------------------

	private static function key(): string {
		return sodium_crypto_generichash( 'acrfwp-whatsapp|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	private static function decrypt( string $stored ): string {
		if ( '' === $stored || 0 !== strpos( $stored, self::PREFIX ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
		$bytes = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $bytes || strlen( $bytes ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);

		return false === $plain ? '' : $plain;
	}
}
