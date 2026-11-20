<?php
namespace ACRFWP\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use ACRFWP\App\Sms\Settings;
use ACRFWP\App\Sms\Webhook;

/**
 * Twilio's SMS webhooks (Pro). Public: Twilio calls them, never a browser.
 * Every request must carry a valid X-Twilio-Signature (401 otherwise).
 *
 * POST /sms/webhook/twilio/status   Delivery updates (the URL is sent with
 *                                   each message as StatusCallback).
 * POST /sms/webhook/twilio/inbound  Replies: STOP / START. The store pastes
 *                                   this URL in Twilio (phone number or
 *                                   Messaging Service → "A message comes in").
 *                                   Answers with empty TwiML so Twilio sends
 *                                   no automatic reply of ours.
 */
class SmsWebhook {

	public static function init(): void {
		$self = new self();
		add_action( 'rest_api_init', array( $self, 'register_routes' ) );
	}

	public function register_routes(): void {
		$namespace = \ACRFW\API::NAMESPACE_NAME . '/' . \ACRFW\API::VERSION;

		register_rest_route(
			$namespace,
			'/sms/webhook/twilio/status',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$namespace,
			'/sms/webhook/twilio/inbound',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'inbound' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( $request ) {
		$provider = Settings::provider();
		if ( ! $provider->valid_signature( $request, Settings::status_url() ) ) {
			return self::forbidden();
		}

		$applied = Webhook::status( $provider->parse_status( $request ) );

		return new WP_REST_Response( array( 'applied' => $applied ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function inbound( $request ) {
		$provider = Settings::provider();
		if ( ! $provider->valid_signature( $request, Settings::inbound_url() ) ) {
			return self::forbidden();
		}

		Webhook::inbound( $provider->parse_inbound( $request ) );

		$response = new WP_REST_Response( '<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200 );
		$response->header( 'Content-Type', 'text/xml; charset=utf-8' );
		$response->header( ProductsReport::RAW_HEADER, '1' );

		return $response;
	}

	private static function forbidden(): WP_Error {
		return new WP_Error( 'rest_forbidden', __( 'Invalid signature.', 'abandoned-cart-recovery-for-woocommerce-pro' ), array( 'status' => 401 ) );
	}
}
