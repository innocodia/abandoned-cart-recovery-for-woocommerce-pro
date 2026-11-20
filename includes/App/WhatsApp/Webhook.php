<?php
namespace ACRFWP\App\WhatsApp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACRFW\App\Messaging\MessageLog;

/**
 * What Meta posts to /whatsapp/webhook (API\WhatsAppWebhook does the HTTP part).
 *
 * - Signature: X-Hub-Signature-256 = "sha256=" + HMAC-SHA256 of the raw body
 *   with the app secret. Without a saved app secret nothing is accepted.
 * - statuses[]: delivered → delivered_at; read → delivered_at + opened
 *   (MessageLog::mark(), so stats and the followup.opened webhook see it);
 *   failed → status failed with Meta's reason. "sent" changes nothing.
 * - messages[]: a text or button reply that equals an opt-out keyword
 *   (Settings stop_keywords: STOP, UNSUBSCRIBE, "Stop promotions"…) puts
 *   the sender's number on OptOuts.
 */
class Webhook {

	public static function valid_signature( string $body, string $header ): bool {
		$secret = (string) Settings::get( 'app_secret' );
		if ( '' === $secret || 0 !== strpos( $header, 'sha256=' ) ) {
			return false;
		}

		return hash_equals( 'sha256=' . hash_hmac( 'sha256', $body, $secret ), $header );
	}

	/**
	 * Handle a verified payload.
	 *
	 * @param array $payload Decoded JSON.
	 * @return array{statuses: int, optouts: int} What was applied.
	 */
	public static function handle( array $payload ): array {
		$done = array(
			'statuses' => 0,
			'optouts'  => 0,
		);

		if ( 'whatsapp_business_account' !== ( $payload['object'] ?? '' ) ) {
			return $done;
		}

		foreach ( (array) ( $payload['entry'] ?? array() ) as $entry ) {
			foreach ( (array) ( $entry['changes'] ?? array() ) as $change ) {
				$value = (array) ( $change['value'] ?? array() );

				foreach ( (array) ( $value['statuses'] ?? array() ) as $status ) {
					$done['statuses'] += self::status( (array) $status ) ? 1 : 0;
				}
				foreach ( (array) ( $value['messages'] ?? array() ) as $message ) {
					$done['optouts'] += self::message( (array) $message ) ? 1 : 0;
				}
			}
		}

		return $done;
	}

	/** @param array $status { id, status, errors[] }. */
	private static function status( array $status ): bool {
		$row = MessageLog::find_by_provider_id( sanitize_text_field( (string) ( $status['id'] ?? '' ) ) );
		if ( ! $row || 'whatsapp' !== $row->channel ) {
			return false;
		}

		$id = (int) $row->id;
		switch ( (string) ( $status['status'] ?? '' ) ) {
			case 'delivered':
				MessageLog::mark_delivered( $id );
				return true;

			case 'read':
				MessageLog::mark_delivered( $id );
				MessageLog::mark( $id, 'opened' );
				return true;

			case 'failed':
				$error  = (array) ( $status['errors'][0] ?? array() );
				$reason = (string) ( $error['error_data']['details'] ?? ( $error['message'] ?? ( $error['title'] ?? '' ) ) );
				if ( ! empty( $error['code'] ) ) {
					$reason .= ' (#' . (int) $error['code'] . ')';
				}
				MessageLog::mark_failed( $id, sanitize_text_field( '' !== trim( $reason ) ? $reason : __( 'WhatsApp could not deliver the message.', 'abandoned-cart-recovery-for-woocommerce-pro' ) ) );
				return true;
		}

		return false;
	}

	/** @param array $message { from, type, text.body | button.text / payload | interactive }. */
	private static function message( array $message ): bool {
		switch ( (string) ( $message['type'] ?? '' ) ) {
			case 'text':
				$texts = array( $message['text']['body'] ?? '' );
				break;
			case 'button':
				$texts = array( $message['button']['text'] ?? '', $message['button']['payload'] ?? '' );
				break;
			case 'interactive':
				$texts = array( $message['interactive']['button_reply']['title'] ?? '', $message['interactive']['button_reply']['id'] ?? '' );
				break;
			default:
				return false;
		}

		if ( ! self::is_stop( $texts ) ) {
			return false;
		}

		$phone = '+' . preg_replace( '/\D+/', '', (string) ( $message['from'] ?? '' ) );

		return OptOuts::add( $phone, 'reply' );
	}

	/** @param string[] $texts Reply texts. */
	public static function is_stop( array $texts ): bool {
		$keywords = array_map( 'strtoupper', (array) Settings::get( 'stop_keywords' ) );

		foreach ( $texts as $text ) {
			$text = strtoupper( trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) ) );
			$text = rtrim( $text, " .!\t" );
			if ( '' !== $text && in_array( $text, $keywords, true ) ) {
				return true;
			}
		}

		return false;
	}
}
