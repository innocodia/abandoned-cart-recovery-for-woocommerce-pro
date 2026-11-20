<?php
namespace ACRFWP\App\WhatsApp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin client for Meta's WhatsApp Cloud API (Graph API), over the WordPress
 * HTTP API. Every call returns { ok, data, error, code } and never throws;
 * Meta's error message is passed through so the admin sees what to fix.
 */
class Client {

	const GRAPH_URL = 'https://graph.facebook.com/';

	/** Graph API version; bump it when Meta deprecates this one. */
	const GRAPH_VERSION = 'v23.0';

	/** @var string */
	private $token;

	public function __construct( ?string $token = null ) {
		$this->token = null !== $token ? $token : (string) Settings::get( 'access_token' );
	}

	/**
	 * Send an approved template message.
	 *
	 * @param string $phone_number_id Sender's phone number ID.
	 * @param string $to              Recipient, E.164 (+ optional).
	 * @param string $name            Template name.
	 * @param string $language        Template language code, e.g. en_US.
	 * @param array  $components      Body / button parameters.
	 * @return array{ok: bool, id: string, error: string, code: int}
	 */
	public function send_template( string $phone_number_id, string $to, string $name, string $language, array $components ): array {
		$template = array(
			'name'     => $name,
			'language' => array( 'code' => $language ),
		);
		if ( $components ) {
			$template['components'] = $components;
		}

		$result = $this->request(
			'POST',
			rawurlencode( $phone_number_id ) . '/messages',
			array(
				'messaging_product' => 'whatsapp',
				'recipient_type'    => 'individual',
				'to'                => ltrim( $to, '+' ),
				'type'              => 'template',
				'template'          => $template,
			)
		);

		$result['id'] = (string) ( $result['data']['messages'][0]['id'] ?? '' );
		if ( $result['ok'] && '' === $result['id'] ) {
			$result['ok']    = false;
			$result['error'] = __( 'WhatsApp accepted the request but returned no message ID.', 'abandoned-cart-recovery-for-woocommerce-pro' );
		}

		return $result;
	}

	/** The sender number's details (Verify). */
	public function phone_number( string $phone_number_id ): array {
		return $this->request( 'GET', rawurlencode( $phone_number_id ), null, array( 'fields' => 'display_phone_number,verified_name,quality_rating' ) );
	}

	/**
	 * Approved templates of a WhatsApp Business Account (up to 500).
	 *
	 * @return array{ok: bool, data: array, error: string, code: int}
	 */
	public function templates( string $waba_id ): array {
		$all   = array();
		$query = array(
			'status' => 'APPROVED',
			'fields' => 'name,language,category,status,components',
			'limit'  => 100,
		);

		for ( $page = 0; $page < 5; $page++ ) {
			$result = $this->request( 'GET', rawurlencode( $waba_id ) . '/message_templates', null, $query );
			if ( ! $result['ok'] ) {
				return $result;
			}

			$all   = array_merge( $all, (array) ( $result['data']['data'] ?? array() ) );
			$after = (string) ( $result['data']['paging']['cursors']['after'] ?? '' );
			if ( '' === $after || empty( $result['data']['paging']['next'] ) ) {
				break;
			}
			$query['after'] = $after;
		}

		return array(
			'ok'    => true,
			'data'  => $all,
			'error' => '',
			'code'  => 200,
		);
	}

	/**
	 * @param string     $method GET or POST.
	 * @param string     $path   Graph path after the version.
	 * @param array|null $body   JSON body (POST).
	 * @param array      $query  Query string.
	 * @return array{ok: bool, data: array, error: string, code: int}
	 */
	private function request( string $method, string $path, ?array $body = null, array $query = array() ): array {
		if ( '' === $this->token ) {
			return self::fail( __( 'No WhatsApp access token is saved.', 'abandoned-cart-recovery-for-woocommerce-pro' ) );
		}

		$url  = add_query_arg( $query, self::GRAPH_URL . self::GRAPH_VERSION . '/' . $path );
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: connection error. */
			return self::fail( sprintf( __( 'Could not reach WhatsApp: %s', 'abandoned-cart-recovery-for-woocommerce-pro' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code >= 200 && $code < 300 && empty( $data['error'] ) ) {
			return array(
				'ok'    => true,
				'data'  => $data,
				'error' => '',
				'code'  => $code,
			);
		}

		return self::fail( self::error_text( $data, $code ), $code, $data );
	}

	/**
	 * A readable reason from a Graph error response.
	 *
	 * @param array $data Decoded body.
	 * @param int   $code HTTP status.
	 */
	private static function error_text( array $data, int $code ): string {
		$error   = (array) ( $data['error'] ?? array() );
		$number  = (int) ( $error['code'] ?? 0 );
		$message = (string) ( $error['error_data']['details'] ?? ( $error['message'] ?? '' ) );

		if ( 190 === $number ) {
			return __( 'The WhatsApp access token has expired or is invalid. Create a permanent System User token in Meta Business settings and save it again.', 'abandoned-cart-recovery-for-woocommerce-pro' );
		}

		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'WhatsApp returned HTTP %d.', 'abandoned-cart-recovery-for-woocommerce-pro' ), $code );
		}

		return $number ? $message . ' (#' . $number . ')' : $message;
	}

	private static function fail( string $error, int $code = 0, array $data = array() ): array {
		return array(
			'ok'    => false,
			'data'  => $data,
			'error' => $error,
			'code'  => $code,
		);
	}
}
