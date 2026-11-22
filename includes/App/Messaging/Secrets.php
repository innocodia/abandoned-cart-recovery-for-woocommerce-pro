<?php
namespace ACRFWP\App\Messaging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption at rest for provider secrets (WhatsApp token and app secret,
 * Twilio auth token): libsodium secretbox, key derived from the site's AUTH
 * salt. If the salts change, a secret can't be decrypted any more and reads
 * as '' (the admin enters it again).
 */
class Secrets {

	const PREFIX = 'enc:';

	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	public static function decrypt( string $stored ): string {
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

	private static function key(): string {
		// Same derivation as the first WhatsApp release, so saved secrets still open.
		return sodium_crypto_generichash( 'acrfwp-whatsapp|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
