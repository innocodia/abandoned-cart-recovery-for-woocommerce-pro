<?php
namespace ACRFWP\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use ACRFWP\App\WhatsApp\Client;
use ACRFWP\App\Messaging\OptOuts;
use ACRFWP\App\Messaging\PhoneNumber;
use ACRFWP\App\WhatsApp\Settings;
use ACRFWP\App\WhatsApp\Templates;

/**
 * Integrations → WhatsApp (Pro). Permission: manage_woocommerce.
 *
 * GET    /integrations/whatsapp               connection settings (no secrets)
 * PUT    /integrations/whatsapp               partial update
 * POST   /integrations/whatsapp/verify        check the phone number ID + token with Meta
 * GET    /integrations/whatsapp/templates     approved templates (?refresh=1)
 * GET    /integrations/whatsapp/optouts       numbers that replied STOP (search, page, per_page)
 * POST   /integrations/whatsapp/optouts       add a number by hand
 * DELETE /integrations/whatsapp/optouts/{id}  remove one
 */
class WhatsApp extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = \ACRFW\API::NAMESPACE_NAME . '/' . \ACRFW\API::VERSION;
		$this->rest_base = 'integrations/whatsapp';
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
						'enabled'         => array( 'type' => 'boolean' ),
						'phone_number_id' => array( 'type' => 'string' ),
						'waba_id'         => array( 'type' => 'string' ),
						'access_token'    => array(
							'description' => __( 'Empty keeps the saved token; null removes it.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
							'type'        => array( 'string', 'null' ),
						),
						'app_secret'      => array(
							'description' => __( 'Empty keeps the saved secret; null removes it.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
							'type'        => array( 'string', 'null' ),
						),
						'stop_keywords'   => array(
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
			'/' . $this->rest_base . '/templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'templates' ),
					'permission_callback' => $perm,
					'args'                => array(
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
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
				__( 'Some WhatsApp settings are not valid.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				array(
					'status' => 400,
					'params' => $errors,
				)
			);
		}

		return rest_ensure_response( Settings::for_response() );
	}

	public function verify() {
		$phone_number_id = (string) Settings::get( 'phone_number_id' );
		if ( '' === $phone_number_id || '' === Settings::get( 'access_token' ) ) {
			return new WP_Error(
				'acrfwp_whatsapp_not_configured',
				__( 'Save the phone number ID and access token first.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				array( 'status' => 400 )
			);
		}

		$result = ( new Client() )->phone_number( $phone_number_id );
		if ( ! $result['ok'] ) {
			return rest_ensure_response(
				array(
					'ok'    => false,
					'error' => $result['error'],
				)
			);
		}

		$display = (string) ( $result['data']['display_phone_number'] ?? '' );
		Settings::set_display_phone( $display );

		return rest_ensure_response(
			array(
				'ok'             => true,
				'display_phone'  => $display,
				'verified_name'  => (string) ( $result['data']['verified_name'] ?? '' ),
				'quality_rating' => (string) ( $result['data']['quality_rating'] ?? '' ),
				'app_secret_set' => '' !== Settings::get( 'app_secret' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function templates( $request ) {
		$list = Templates::all( (bool) $request->get_param( 'refresh' ) );

		return is_wp_error( $list ) ? $list : rest_ensure_response( array( 'items' => $list ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function optouts( $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = OptOuts::page( (string) $request->get_param( 'search' ), $page, $per_page, 'whatsapp' );

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

		$added = OptOuts::add( $phone, 'admin', 'whatsapp' );

		return rest_ensure_response(
			array(
				'phone' => $phone,
				'added' => $added,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_optout( $request ) {
		$id  = (int) $request['id'];
		$row = OptOuts::get( $id, 'whatsapp' );
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
