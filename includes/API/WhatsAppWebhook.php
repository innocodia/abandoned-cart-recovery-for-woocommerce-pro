<?php
namespace ACRFWP\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use ACRFWP\App\WhatsApp\Settings;
use ACRFWP\App\WhatsApp\Webhook;

/**
 * Meta's WhatsApp webhook (Pro). Public: Meta calls it, never a browser.
 *
 * GET  /whatsapp/webhook  Verification handshake: hub.mode=subscribe and
 *                         hub.verify_token equal to ours → echo hub.challenge
 *                         as plain text (403 otherwise).
 * POST /whatsapp/webhook  Status updates and replies; X-Hub-Signature-256 must
 *                         match (401 otherwise). Handled by App\WhatsApp\Webhook.
 */
class WhatsAppWebhook {

	public static function init(): void {
		$self = new self();
		add_action( 'rest_api_init', array( $self, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			\ACRFW\API::NAMESPACE_NAME . '/' . \ACRFW\API::VERSION,
			'/whatsapp/webhook',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handshake' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * PHP turns "hub.mode" into "hub_mode" in $_GET; accept both.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    e.g. mode.
	 */
	private static function hub( $request, string $name ): string {
		$value = $request->get_param( 'hub_' . $name );
		if ( null === $value ) {
			$value = $request->get_param( 'hub.' . $name );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handshake( $request ) {
		$token = self::hub( $request, 'verify_token' );

		if ( 'subscribe' !== self::hub( $request, 'mode' ) || '' === $token || ! hash_equals( Settings::verify_token(), $token ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Verify token does not match.', 'abandoned-cart-recovery-for-woocommerce-pro' ), array( 'status' => 403 ) );
		}

		// Meta expects the challenge back exactly, not as a JSON string.
		$response = new WP_REST_Response( self::hub( $request, 'challenge' ), 200 );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		$response->header( ProductsReport::RAW_HEADER, '1' );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( $request ) {
		$body = (string) $request->get_body();

		if ( ! Webhook::valid_signature( $body, (string) $request->get_header( 'x_hub_signature_256' ) ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Invalid signature.', 'abandoned-cart-recovery-for-woocommerce-pro' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $body, true );
		$done    = Webhook::handle( is_array( $payload ) ? $payload : array() );

		return new WP_REST_Response( array_merge( array( 'received' => true ), $done ), 200 );
	}
}
