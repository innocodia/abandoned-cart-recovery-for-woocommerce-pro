<?php
namespace ACRFWP\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use ACRFWP\App\Reports\ProductsReport as Report;

/**
 * Products report (Pro).
 *
 * GET /wp-json/acrfw/v1/reports/products          paged JSON
 * GET /wp-json/acrfw/v1/reports/products/export   CSV download (same filters, all rows)
 *
 * Params: search, from, to (YYYY-MM-DD, inclusive, cart created date),
 * orderby, order, page, per_page (JSON only). Permission: manage_woocommerce.
 */
class ProductsReport extends WP_REST_Controller {

	const RAW_HEADER = 'X-ACRFW-Raw-Body';

	public function __construct() {
		$this->namespace = \ACRFW\API::NAMESPACE_NAME . '/' . \ACRFW\API::VERSION;
		$this->rest_base = 'reports/products';
	}

	public static function init(): void {
		$self = new self();
		add_action( 'rest_api_init', array( $self, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_raw' ), 10, 4 );
	}

	public function register_routes() { //phpcs:ignore
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		$export = $this->get_collection_params();
		unset( $export['page'], $export['per_page'] );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => $export,
				),
			)
		);
	}

	public function permissions(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Filters from the request, or a WP_Error for a reversed date range.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function filters( $request ) {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );

		if ( '' !== $from && '' !== $to && $from > $to ) {
			return new WP_Error(
				'rest_invalid_date_range',
				__( "'from' date must be on or before 'to' date.", 'abandoned-cart-recovery-for-woocommerce-pro' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'search'  => (string) $request->get_param( 'search' ),
			'from'    => $from,
			'to'      => $to,
			'orderby' => (string) $request->get_param( 'orderby' ),
			'order'   => (string) $request->get_param( 'order' ),
		);
	}

	public function get_items( $request ) { //phpcs:ignore
		$filters = $this->filters( $request );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = Report::rows( $filters, $page, $per_page );
		$total    = $result['total'];

		$response = new WP_REST_Response(
			array(
				'data'       => $result['items'],
				'pagination' => array(
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
					'page'        => $page,
					'per_page'    => $per_page,
				),
				'totals'     => Report::totals( $filters ),
				'currency'   => get_woocommerce_currency(),
			)
		);
		$response->header( 'X-WP-Total', (string) $total );

		return $response;
	}

	/**
	 * CSV of every matching product. Served raw (not JSON) by serve_raw().
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function export( $request ) {
		$filters = $this->filters( $request );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		$csv      = Report::csv( Report::rows( $filters, null )['items'], get_woocommerce_currency() );
		$filename = 'products-report-' . wp_date( 'Y-m-d' ) . '.csv';

		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( self::RAW_HEADER, '1' );

		return $response;
	}

	/**
	 * Send the export's CSV text as the body instead of a JSON string.
	 *
	 * @param bool             $served  Whether the request was already served.
	 * @param WP_REST_Response $result  Response.
	 */
	public static function serve_raw( $served, $result ) {
		if ( $served || ! $result instanceof WP_REST_Response || empty( $result->get_headers()[ self::RAW_HEADER ] ) || ! is_string( $result->get_data() ) ) {
			return $served;
		}

		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file body (text/csv), not HTML; cells are quoted and formula-escaped.
		return true;
	}

	public function get_collection_params() { //phpcs:ignore
		return array(
			'search'   => array(
				'description'       => __( 'Product name or SKU contains it, or the product ID.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				'type'              => 'string',
				'default'           => '',
				'maxLength'         => 100,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'from'     => array(
				'description'       => __( 'Start date (YYYY-MM-DD, store time, inclusive), on the cart created date.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_date' ),
			),
			'to'       => array(
				'description'       => __( 'End date (YYYY-MM-DD, store time, inclusive).', 'abandoned-cart-recovery-for-woocommerce-pro' ),
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_date' ),
			),
			'orderby'  => array(
				'type'              => 'string',
				'enum'              => array_keys( Report::ORDERBY ),
				'default'           => 'abandoned_amount',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'order'    => array(
				'type'              => 'string',
				'enum'              => array( 'desc', 'asc' ),
				'default'           => 'desc',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Empty, or a real YYYY-MM-DD date.
	 *
	 * @param mixed $value Value.
	 * @return true|WP_Error
	 */
	public function validate_date( $value ) {
		if ( '' === $value || null === $value || \ACRFW\App\Carts\CartQuery::date( $value ) ) {
			return true;
		}

		return new WP_Error(
			'rest_invalid_date',
			__( 'Dates must be real dates in YYYY-MM-DD form.', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			array( 'status' => 400 )
		);
	}
}
