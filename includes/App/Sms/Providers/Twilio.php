<?php
namespace ACRFWP\App\Sms\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACRFWP\App\Sms\Settings;

/**
 * Twilio Programmable Messaging over the WordPress HTTP API.
 *
 * - Send: POST /2010-04-01/Accounts/{SID}/Messages.json (Basic auth SID:token)
 *   with To, From or MessagingServiceSid, Body, StatusCallback.
 * - Signature: X-Twilio-Signature = base64( HMAC-SHA1( auth token,
 *   URL + each POST field name and value, sorted by name ) ).
 * - Error 21610: the recipient replied STOP (Twilio blocks the send).
 *   21211 / 21614: not a valid (mobile) number.
 */
class Twilio implements SmsProvider {

	const API = 'https://api.twilio.com/2010-04-01/';

	/** Undelivered / failed codes → readable text (others show the code). */
	const ERRORS = array(
		30003 => 'Unreachable handset',
		30004 => 'Message blocked by the recipient or carrier',
		30005 => 'Unknown or inactive number',
		30006 => 'Landline or unreachable carrier',
		30007 => 'Filtered by the carrier as spam',
		30008 => 'Unknown error',
		30034 => 'Sender not registered for US A2P 10DLC',
		21610 => 'The recipient replied STOP',
	);

	public function id(): string {
		return 'twilio';
	}

	private function sid(): string {
		return (string) Settings::get( 'account_sid' );
	}

	private function token(): string {
		return (string) Settings::get( 'auth_token' );
	}

	public function send( string $to, string $body, string $status_url ): array {
		$fields = array(
			'To'             => $to,
			'Body'           => $body,
			'StatusCallback' => $status_url,
		);

		$service = (string) Settings::get( 'messaging_service_sid' );
		if ( '' !== $service ) {
			$fields['MessagingServiceSid'] = $service;
		} else {
			$fields['From'] = (string) Settings::get( 'from' );
		}

		$result = $this->request( 'POST', 'Accounts/' . rawurlencode( $this->sid() ) . '/Messages.json', $fields );

		return array(
			'ok'            => $result['ok'] && ! empty( $result['data']['sid'] ),
			'id'            => (string) ( $result['data']['sid'] ?? '' ),
			'error'         => $result['ok'] && empty( $result['data']['sid'] ) ? __( 'Twilio accepted the request but returned no message SID.', 'abandoned-cart-recovery-for-woocommerce-pro' ) : $result['error'],
			'code'          => $result['code'],
			'opted_out'     => 21610 === $result['code'],
			'invalid_phone' => in_array( $result['code'], array( 21211, 21614 ), true ),
		);
	}

	public function verify(): array {
		$account = $this->request( 'GET', 'Accounts/' . rawurlencode( $this->sid() ) . '.json' );
		if ( ! $account['ok'] ) {
			return array(
				'ok'             => false,
				'account_name'   => '',
				'account_status' => '',
				'sender_ok'      => null,
				'error'          => $account['error'],
			);
		}

		// A number sender must belong to the account; a sender name or a
		// Messaging Service can't be checked this way.
		$sender_ok = null;
		$from      = (string) Settings::get( 'from' );
		if ( '' === (string) Settings::get( 'messaging_service_sid' ) && 0 === strpos( $from, '+' ) ) {
			$numbers   = $this->request( 'GET', 'Accounts/' . rawurlencode( $this->sid() ) . '/IncomingPhoneNumbers.json', array( 'PhoneNumber' => $from ) );
			$sender_ok = $numbers['ok'] && ! empty( $numbers['data']['incoming_phone_numbers'] );
		}

		return array(
			'ok'             => true,
			'account_name'   => (string) ( $account['data']['friendly_name'] ?? '' ),
			'account_status' => (string) ( $account['data']['status'] ?? '' ),
			'sender_ok'      => $sender_ok,
			'error'          => false === $sender_ok ? __( 'The sender number isn\'t in this Twilio account.', 'abandoned-cart-recovery-for-woocommerce-pro' ) : '',
		);
	}

	public function valid_signature( \WP_REST_Request $request, string $url ): bool {
		$token     = $this->token();
		$signature = (string) $request->get_header( 'x_twilio_signature' );
		if ( '' === $token || '' === $signature ) {
			return false;
		}

		return hash_equals( self::signature( $token, $url, (array) $request->get_body_params() ), $signature );
	}

	/**
	 * Twilio's request signature.
	 *
	 * @param string $token  Auth token.
	 * @param string $url    Full URL Twilio called.
	 * @param array  $params POST fields.
	 */
	public static function signature( string $token, string $url, array $params ): string {
		ksort( $params, SORT_STRING );
		$data = $url;
		foreach ( $params as $name => $value ) {
			$data .= $name . ( is_array( $value ) ? implode( '', $value ) : $value );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Twilio's signature format.
		return base64_encode( hash_hmac( 'sha1', $data, $token, true ) );
	}

	public function parse_status( \WP_REST_Request $request ): ?array {
		$id = sanitize_text_field( (string) $request->get_param( 'MessageSid' ) );
		if ( '' === $id ) {
			return null;
		}

		$status = (string) $request->get_param( 'MessageStatus' );
		$code   = (int) $request->get_param( 'ErrorCode' );
		$error  = '';
		if ( in_array( $status, array( 'undelivered', 'failed' ), true ) ) {
			$error = ( self::ERRORS[ $code ] ?? ( 'undelivered' === $status ? 'Undelivered' : 'Failed' ) ) . ( $code ? ' (Twilio error ' . $code . ')' : '' );
		}

		return array(
			'id'     => $id,
			'status' => 'delivered' === $status ? 'delivered' : ( '' !== $error ? 'failed' : 'other' ),
			'error'  => $error,
		);
	}

	public function parse_inbound( \WP_REST_Request $request ): ?array {
		$from = (string) $request->get_param( 'From' );
		if ( '' === $from ) {
			return null;
		}

		// With Advanced Opt-Out, Twilio says which keyword type it matched.
		$type    = strtoupper( (string) $request->get_param( 'OptOutType' ) );
		$keyword = 'STOP' === $type ? 'stop' : ( 'START' === $type ? 'start' : '' );

		return array(
			'from'    => '+' . preg_replace( '/\D+/', '', $from ),
			'body'    => sanitize_text_field( (string) $request->get_param( 'Body' ) ),
			'keyword' => $keyword,
		);
	}

	/**
	 * @param string $method GET or POST.
	 * @param string $path   Path after /2010-04-01/.
	 * @param array  $fields Form fields (POST) or query (GET).
	 * @return array{ok: bool, data: array, error: string, code: int}
	 */
	private function request( string $method, string $path, array $fields = array() ): array {
		if ( '' === $this->sid() || '' === $this->token() ) {
			return array(
				'ok'    => false,
				'data'  => array(),
				'error' => __( 'Save the Twilio Account SID and Auth Token first.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				'code'  => 0,
			);
		}

		$url  = self::API . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
				'Authorization' => 'Basic ' . base64_encode( $this->sid() . ':' . $this->token() ),
			),
		);
		if ( 'POST' === $method ) {
			$args['body'] = $fields;
		} elseif ( $fields ) {
			$url = add_query_arg( array_map( 'rawurlencode', $fields ), $url );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'data'  => array(),
				/* translators: %s: connection error. */
				'error' => sprintf( __( 'Could not reach Twilio: %s', 'abandoned-cart-recovery-for-woocommerce-pro' ), $response->get_error_message() ),
				'code'  => 0,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok'    => true,
				'data'  => $data,
				'error' => '',
				'code'  => 0,
			);
		}

		$code    = (int) ( $data['code'] ?? 0 );
		$message = (string) ( $data['message'] ?? '' );
		if ( 20003 === $code || 401 === $status ) {
			$message = __( 'Twilio rejected the Account SID or Auth Token.', 'abandoned-cart-recovery-for-woocommerce-pro' );
		} elseif ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'Twilio returned HTTP %d.', 'abandoned-cart-recovery-for-woocommerce-pro' ), $status );
		}

		return array(
			'ok'    => false,
			'data'  => $data,
			'error' => $code ? $message . ' (Twilio error ' . $code . ')' : $message,
			'code'  => $code,
		);
	}
}
