<?php
namespace ACRFWP\App\Sms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACRFW\App\Messaging\Renderer;
use ACRFWP\App\Messaging\CheckoutOptin;
use ACRFWP\App\Messaging\OptOuts;
use ACRFWP\App\Messaging\PhoneNumber;

/**
 * Sends SMS follow-up steps through the configured provider (Twilio).
 *
 * The free FollowupSender has done every shared check and built the merge-tag
 * values; {{recovery_url}} is already a short link (?acr=CODE). Here:
 * 1. connection saved (else skipped: not_configured);
 * 2. the cart's phone becomes E.164 (invalid_phone);
 * 3. real sends only: the SMS opt-in when that checkbox is on (no_sms_optin),
 *    and the SMS STOP list (sms_opted_out);
 * 4. the text: template body_text with merge tags, plus the opt-out line
 *    ("Reply STOP to opt out") unless the text already mentions STOP;
 * 5. send, asking the provider to post delivery updates to our webhook.
 * The provider saying the number opted out (Twilio 21610) adds it to the list.
 */
class SmsChannel {

	public static function init(): void {
		add_filter( 'acrfw_followup_channels', array( self::class, 'channels' ) );
		add_filter( 'acrfw_followup_send', array( self::class, 'send' ), 10, 3 );
	}

	/**
	 * @param array $channels Channel key => { label, available, pro }.
	 */
	public static function channels( $channels ): array {
		$channels = (array) $channels;
		if ( isset( $channels['sms'] ) ) {
			$channels['sms']['available'] = true;
		}

		return $channels;
	}

	/**
	 * acrfw_followup_send callback.
	 *
	 * @param bool|array $sent    Result so far.
	 * @param string     $channel Channel of the step.
	 * @param array      $context cart, step, template, values, to, message_id, is_test.
	 * @return bool|array
	 */
	public static function send( $sent, $channel, $context ) {
		if ( 'sms' !== $channel ) {
			return $sent;
		}

		$cart     = (object) ( $context['cart'] ?? array() );
		$template = (array) ( $context['template'] ?? array() );
		$is_test  = ! empty( $context['is_test'] );

		if ( ! Settings::configured() ) {
			return self::skip( 'not_configured' );
		}

		// A test goes to the number the admin typed: read it in the store's country.
		$details = json_decode( (string) ( $cart->user_details ?? '' ), true );
		$country = $is_test ? '' : (string) ( $details['billing']['country'] ?? '' );
		$to      = PhoneNumber::to_e164( (string) ( $context['to'] ?? '' ), $country );
		if ( '' === $to ) {
			return self::skip( 'invalid_phone' );
		}

		if ( ! $is_test ) {
			if ( CheckoutOptin::required( 'sms' ) && '1' !== (string) ( $cart->sms_optin ?? '' ) ) {
				return self::skip( 'no_sms_optin' );
			}
			if ( OptOuts::has( $to, 'sms' ) ) {
				return self::skip( 'sms_opted_out' );
			}
		}

		$body = self::body( (string) ( $template['body_text'] ?? '' ), (array) ( $context['values'] ?? array() ) );
		if ( '' === $body ) {
			return self::skip( 'sms_text_missing' );
		}

		$result = Settings::provider()->send( $to, $body, Settings::status_url() );

		if ( $result['ok'] ) {
			return array(
				'sent'                => true,
				'provider_message_id' => $result['id'],
			);
		}

		if ( $result['opted_out'] ) {
			OptOuts::add( $to, 'provider', 'sms' );
			return self::skip( 'sms_opted_out' );
		}
		if ( $result['invalid_phone'] ) {
			return self::skip( 'invalid_phone' );
		}

		return array(
			'sent'   => false,
			'reason' => $result['error'],
		);
	}

	/**
	 * The message text: merge tags, then the opt-out line on its own line.
	 *
	 * @param string $text   Template body_text.
	 * @param array  $values Merge-tag values.
	 */
	public static function body( string $text, array $values ): string {
		$body = trim( str_replace( array( "\r\n", "\r" ), "\n", Renderer::text( $text, $values ) ) );
		if ( '' === $body ) {
			return '';
		}

		$opt_out = trim( (string) Settings::get( 'opt_out_text' ) );
		if ( '' !== $opt_out && false === stripos( $body, 'STOP' ) ) {
			$body .= "\n" . $opt_out;
		}

		return $body;
	}

	private static function skip( string $reason ): array {
		return array(
			'sent'    => false,
			'skipped' => true,
			'reason'  => $reason,
		);
	}
}
