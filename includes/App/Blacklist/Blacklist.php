<?php
namespace ACRFWP\App\Blacklist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → Blacklist: customers who never get follow-ups.
 *
 * Answers the free plugin's `acrfw_is_blacklisted` filter, asked before each
 * real send (ACRFW\App\Messaging\FollowupSender). The settings live in the
 * free plugin (blacklist.enable_blacklist, blacklist.emails); entries are
 * emails or whole domains stored as "@domain.com"
 * (ACRFW\App\Core\Settings::email_or_domain()).
 *
 * A cart matches when its email equals an entry, or its domain equals an
 * "@domain" entry exactly (no subdomains). Case-insensitive. The cart is
 * still tracked and reported; it just gets no email, coupon or link.
 */
class Blacklist {

	public static function init(): void {
		add_filter( 'acrfw_is_blacklisted', array( self::class, 'on_check' ), 10, 2 );
		add_filter( 'acrfw_blacklist_entries', array( self::class, 'entries' ) );
	}

	/**
	 * acrfw_is_blacklisted callback.
	 *
	 * @param bool   $blocked Answer so far.
	 * @param object $cart    Cart row.
	 */
	public static function on_check( $blocked, $cart ): bool {
		if ( $blocked ) {
			return true;
		}

		return self::contains( (string) ( is_object( $cart ) ? ( $cart->email ?? '' ) : '' ) );
	}

	/**
	 * acrfw_blacklist_entries callback: the saved entries (emails and
	 * "@domains"), or [] when the blacklist is switched off. Used by the free
	 * plugin's Carts report (GET /carts?blacklisted=true and counts), which
	 * matches them the same way as contains().
	 */
	public static function entries(): array {
		if ( ! acrfw_setting( 'blacklist.enable_blacklist', false ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strtolower', (array) acrfw_setting( 'blacklist.emails', array() ) ) ) );
	}

	/**
	 * Whether an email is blacklisted under the current settings.
	 *
	 * @param string $email Customer email.
	 */
	public static function contains( string $email ): bool {
		if ( ! acrfw_setting( 'blacklist.enable_blacklist', false ) ) {
			return false;
		}

		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );
		if ( '' === $email || false === $at ) {
			return false;
		}

		$entries = array_map( 'strtolower', (array) acrfw_setting( 'blacklist.emails', array() ) );

		return in_array( $email, $entries, true ) || in_array( substr( $email, $at ), $entries, true );
	}
}
