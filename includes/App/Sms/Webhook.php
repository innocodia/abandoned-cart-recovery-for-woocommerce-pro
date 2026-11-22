<?php
namespace ACRFWP\App\Sms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACRFW\App\Messaging\MessageLog;
use ACRFWP\App\Messaging\OptOuts;

/**
 * What the SMS provider posts to our webhooks (API\SmsWebhook does the HTTP
 * part and the signature check; the provider parses).
 *
 * - Status: delivered → delivered_at; undelivered / failed → status failed
 *   with the reason. SMS has no "read", so no opens.
 * - Inbound: STOP (provider keyword type, or the text equals a stop keyword)
 *   puts the number on the SMS opt-out list; START removes it.
 */
class Webhook {

	/**
	 * @param array|null $status Provider::parse_status() result.
	 */
	public static function status( ?array $status ): bool {
		if ( ! $status ) {
			return false;
		}

		$row = MessageLog::find_by_provider_id( $status['id'] );
		if ( ! $row || 'sms' !== $row->channel ) {
			return false;
		}

		if ( 'delivered' === $status['status'] ) {
			MessageLog::mark_delivered( (int) $row->id );
			return true;
		}
		if ( 'failed' === $status['status'] ) {
			MessageLog::mark_failed( (int) $row->id, $status['error'] );
			return true;
		}

		return false;
	}

	/**
	 * @param array|null $inbound Provider::parse_inbound() result.
	 * @return string 'stop', 'start' or '' (what was applied).
	 */
	public static function inbound( ?array $inbound ): string {
		if ( ! $inbound || ! preg_match( '/^\+\d{8,15}$/', $inbound['from'] ) ) {
			return '';
		}

		$keyword = $inbound['keyword'];
		if ( '' === $keyword ) {
			$keyword = self::keyword( $inbound['body'] );
		}

		if ( 'stop' === $keyword ) {
			OptOuts::add( $inbound['from'], 'reply', 'sms' );
		} elseif ( 'start' === $keyword ) {
			OptOuts::remove_phone( $inbound['from'], 'sms' );
		}

		return $keyword;
	}

	/** 'stop' / 'start' when the whole text is one of the keywords. */
	public static function keyword( string $text ): string {
		$text = rtrim( strtoupper( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) ), ' .!' );
		if ( '' === $text ) {
			return '';
		}

		if ( in_array( $text, array_map( 'strtoupper', (array) Settings::get( 'stop_keywords' ) ), true ) ) {
			return 'stop';
		}
		if ( in_array( $text, array_map( 'strtoupper', (array) Settings::get( 'start_keywords' ) ), true ) ) {
			return 'start';
		}

		return '';
	}
}
