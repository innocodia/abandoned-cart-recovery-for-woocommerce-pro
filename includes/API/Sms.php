<?php
namespace ACRFWP\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use ACRFWP\App\Messaging\OptOuts;
use ACRFWP\App\Messaging\PhoneNumber;
use ACRFWP\App\Sms\Settings;

/**
 * Integrations → SMS (Pro). Permission: manage_woocommerce.
 *
 * GET    /integrations/sms               connection settings (no secrets)
 * PUT    /integrations/sms               partial update
 * POST   /integrations/sms/verify        check the account and sender with the provider
 * GET    /integrations/sms/optouts       numbers that replied STOP (search, page, per_page)
 * POST   /integrations/sms/optouts       add a number by hand
 * DELETE /integrations/sms/optouts/{id}  remove one
 */
class Sms extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = \ACRFW\API::NAMESPACE_NAME . '/' . \ACRFW\API::VERSION;
		$this->rest_base = 'integrations/sms';
	}

	public static function init(): void {
		$self = new self();
		add_action( 'rest_api_init', array( $self, 'register_routes' ) );
	}

	public function register_routes() { //phpcs:ignore
		$perm = array( $this, 'permissions' );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => $perm,
					'args'                => array(
						'enabled'               => array( 'type' => 'boolean' ),
						'provider'              => array(
							'type' => 'string',
							'enum' => Settings::PROVIDERS,
						),
						'account_sid'           => array( 'type' => 'string' ),
						'auth_token'            => array(
							'description' => __( 'Empty keeps the saved token; null removes it.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
							'type'        => array( 'string', 'null' ),
						),
						'from'                  => array( 'type' => 'string' ),
						'messaging_service_sid' => array( 'type' => 'string' ),
						'opt_out_text'          => array( 'type' => 'string' ),
						'stop_keywords'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'start_keywords'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/verify',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'verify' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/optouts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'optouts' ),
					'permission_callback' => $perm,
					'args'                => array(
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'maxLength'         => 30,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_optout' ),
					'permission_callback' => $perm,
					'args'                => array(
						'phone' => array(
							'description'       => __( 'Phone number, with country code (+8801712345678) or in the store country\'s format.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/optouts/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_optout' ),
					'permission_callback' => $perm,
				),
			)
		);
	}

	public function permissions(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function get_settings() {
		return rest_ensure_response( Settings::for_response() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function update_settings( $request ) {
		$input = array_intersect_key( $request->get_json_params() ?? array(), Settings::defaults() );
		if ( ! $input ) {
			$input = array_intersect_key( $request->get_params(), Settings::defaults() );
		}

		$errors = Settings::save( $input );
		if ( $errors ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Some SMS settings are not valid.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				array(
					'status' => 400,
					'params' => $errors,
				)
			);
		}

		return rest_ensure_response( Settings::for_response() );
	}

	public function verify() {
		if ( '' === (string) Settings::get( 'account_sid' ) || '' === Settings::get( 'auth_token' ) ) {
			return new WP_Error(
				'acrfwp_sms_not_configured',
				__( 'Save the Account SID and Auth Token first.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( Settings::provider()->verify() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function optouts( $request ) {
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = OptOuts::page( (string) $request->get_param( 'search' ), (int) $request->get_param( 'page' ), $per_page, 'sms' );

		$response = rest_ensure_response( $result );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $per_page ) ) );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function add_optout( $request ) {
		$phone = PhoneNumber::to_e164( (string) $request->get_param( 'phone' ) );
		if ( '' === $phone ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Enter a valid phone number.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				array(
					'status' => 400,
					'params' => array( 'phone' => __( 'Enter a valid phone number.', 'abandoned-cart-recovery-for-woocommerce-pro' ) ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'phone' => $phone,
				'added' => OptOuts::add( $phone, 'admin', 'sms' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_optout( $request ) {
		$id  = (int) $request['id'];
		$row = OptOuts::get( $id, 'sms' );
		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Opt-out not found.', 'abandoned-cart-recovery-for-woocommerce-pro' ), array( 'status' => 404 ) );
		}

		OptOuts::remove( $id );

		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $row,
			)
		);
	}
}
