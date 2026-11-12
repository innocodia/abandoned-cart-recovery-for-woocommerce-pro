<?php
namespace ACRFWP\App\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Products report (Pro): per product, how often it was in abandoned carts and
 * how often those carts were recovered. See docs/plans/products-report.md.
 *
 * Data: the free plugin's acrfw_cart_items (one row per product per cart)
 * joined to acrfw_abandoned_carts, grouped in SQL. Variations roll up to their
 * parent product_id.
 *
 * Definitions match the dashboard (ACRFW\App\Analytics\Summary):
 * - abandoned: carts with status abandoned, recovered, lost or unsubscribed
 *   that contain the product ('completed' = bought without our help, and
 *   'active' are left out);
 * - recovered: those with status recovered;
 * - amounts: the product's line_total in those carts (after discounts,
 *   excluding tax, as captured);
 * - recovery_rate: recovered ÷ abandoned × 100;
 * - from/to: the cart's created date (store time, inclusive days).
 */
class ProductsReport {

	const ORDERBY = array(
		'name'             => 'name',
		'abandoned'        => 'abandoned',
		'abandoned_amount' => 'abandoned_amount',
		'recovered'        => 'recovered',
		'recovered_amount' => 'recovered_amount',
		'recovery_rate'    => 'recovery_rate',
	);

	/*
	 * Shared FROM + WHERE. Optional filters are guards switched off by a flag,
	 * so the SQL stays one literal template and every value is a placeholder.
	 */
	const FROM_WHERE = "FROM %i i
		JOIN %i c ON c.id = i.cart_id
		LEFT JOIN %i lp ON lp.product_id = i.product_id
		LEFT JOIN %i lv ON lv.product_id = i.variation_id AND i.variation_id > 0
		WHERE c.status IN ( 'abandoned', 'recovered', 'lost', 'unsubscribed' )
		  AND ( %d = 0 OR c.created_at >= %s )
		  AND ( %d = 0 OR c.created_at <= %s )
		  AND ( %s = ''
		        OR LOWER( i.product_name ) LIKE %s
		        OR LOWER( lp.sku ) LIKE %s
		        OR LOWER( lv.sku ) LIKE %s
		        OR ( %d > 0 AND i.product_id = %d ) )";

	const SELECT = "SELECT i.product_id,
		       MAX( i.product_name ) AS name,
		       COUNT( DISTINCT c.id ) AS abandoned,
		       SUM( i.quantity ) AS abandoned_quantity,
		       SUM( i.line_total ) AS abandoned_amount,
		       COUNT( DISTINCT CASE WHEN c.status = 'recovered' THEN c.id END ) AS recovered,
		       SUM( CASE WHEN c.status = 'recovered' THEN i.quantity ELSE 0 END ) AS recovered_quantity,
		       SUM( CASE WHEN c.status = 'recovered' THEN i.line_total ELSE 0 END ) AS recovered_amount,
		       COUNT( DISTINCT CASE WHEN c.status = 'recovered' THEN c.id END ) / COUNT( DISTINCT c.id ) AS recovery_rate ";

	/**
	 * Placeholder values: tables, then the WHERE guards.
	 *
	 * @param array $args Filters: search, from, to.
	 */
	private static function args( array $args ): array {
		global $wpdb;

		$search = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
		$like   = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';
		$id     = ltrim( $search, '#' );
		$id     = ( '' !== $id && ctype_digit( $id ) ) ? (int) $id : 0;
		$from   = (string) ( $args['from'] ?? '' );
		$to     = (string) ( $args['to'] ?? '' );

		return array(
			acrfw_table( 'cart_items' ),
			acrfw_table( 'abandoned_carts' ),
			$wpdb->prefix . 'wc_product_meta_lookup',
			$wpdb->prefix . 'wc_product_meta_lookup',
			'' !== $from ? 1 : 0,
			'' !== $from ? $from . ' 00:00:00' : '1000-01-01 00:00:00',
			'' !== $to ? 1 : 0,
			'' !== $to ? $to . ' 23:59:59' : '9999-12-31 23:59:59',
			$like,
			$like,
			$like,
			$like,
			$id,
			$id,
		);
	}

	/**
	 * Report rows.
	 *
	 * @param array    $args     Filters + orderby/order.
	 * @param int|null $page     1-based page, or null for all rows (export).
	 * @param int      $per_page Rows per page.
	 * @return array{items: array, total: int}
	 */
	public static function rows( array $args, ?int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$orderby = self::ORDERBY[ (string) ( $args['orderby'] ?? '' ) ] ?? 'abandoned_amount';
		$asc     = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) );
		$where   = self::args( $args );
		$limit   = null === $page ? PHP_INT_MAX : max( 1, $per_page );
		$offset  = null === $page ? 0 : max( 0, ( $page - 1 ) * $per_page );

		// The templates are this class's constants (no request data); every
		// value, including the sort alias (%i, from a fixed map), is a placeholder.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = $asc
			? self::SELECT . self::FROM_WHERE . ' GROUP BY i.product_id ORDER BY %i ASC, i.product_id ASC LIMIT %d OFFSET %d'
			: self::SELECT . self::FROM_WHERE . ' GROUP BY i.product_id ORDER BY %i DESC, i.product_id DESC LIMIT %d OFFSET %d';

		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $where, array( $orderby, $limit, $offset ) ) ), ARRAY_A );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT( DISTINCT i.product_id ) ' . self::FROM_WHERE, $where ) );
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'format' ), $rows ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Totals over every matching product. Carts are counted once, even when
	 * they hold several matching products.
	 *
	 * @param array $args Filters.
	 */
	public static function totals( array $args ): array {
		global $wpdb;

		// Template is a class constant; values are placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT c.id ) AS abandoned,
				        SUM( i.line_total ) AS abandoned_amount,
				        COUNT( DISTINCT CASE WHEN c.status = 'recovered' THEN c.id END ) AS recovered,
				        SUM( CASE WHEN c.status = 'recovered' THEN i.line_total ELSE 0 END ) AS recovered_amount " . self::FROM_WHERE,
				self::args( $args )
			),
			ARRAY_A
		);
		// phpcs:enable

		$abandoned = (int) ( $row['abandoned'] ?? 0 );
		$recovered = (int) ( $row['recovered'] ?? 0 );

		return array(
			'abandoned'        => $abandoned,
			'abandoned_amount' => round( (float) ( $row['abandoned_amount'] ?? 0 ), 2 ),
			'recovered'        => $recovered,
			'recovered_amount' => round( (float) ( $row['recovered_amount'] ?? 0 ), 2 ),
			'recovery_rate'    => $abandoned ? round( $recovered / $abandoned * 100, 1 ) : 0.0,
		);
	}

	/**
	 * One API row: live product details when the product still exists.
	 *
	 * @param array $row Grouped SQL row.
	 */
	public static function format( array $row ): array {
		$id      = (int) $row['product_id'];
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		$name    = $product ? $product->get_name() : (string) $row['name'];

		if ( ! $product ) {
			/* translators: %s: product name captured with the cart */
			$name = sprintf( __( '%s (deleted)', 'abandoned-cart-recovery-for-woocommerce-pro' ), '' !== $name ? $name : '#' . $id );
		}

		$abandoned = (int) $row['abandoned'];
		$recovered = (int) $row['recovered'];

		return array(
			'product_id'         => $id,
			'name'               => $name,
			'sku'                => $product ? (string) $product->get_sku() : '',
			'permalink'          => $product ? (string) get_permalink( $id ) : '',
			'edit_url'           => $product ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : '',
			'deleted'            => ! $product,
			'abandoned'          => $abandoned,
			'abandoned_quantity' => (int) $row['abandoned_quantity'],
			'abandoned_amount'   => round( (float) $row['abandoned_amount'], 2 ),
			'recovered'          => $recovered,
			'recovered_quantity' => (int) $row['recovered_quantity'],
			'recovered_amount'   => round( (float) $row['recovered_amount'], 2 ),
			'recovery_rate'      => $abandoned ? round( $recovered / $abandoned * 100, 1 ) : 0.0,
		);
	}

	/**
	 * CSV text (UTF-8 with BOM) for the given rows.
	 *
	 * @param array  $items    Formatted rows.
	 * @param string $currency Currency code for the amount headers.
	 */
	public static function csv( array $items, string $currency ): string {
		$header = array(
			__( 'Product ID', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			__( 'Product', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			__( 'SKU', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			__( 'Times abandoned', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			__( 'Quantity abandoned', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			/* translators: %s: currency code */
			sprintf( __( 'Amount abandoned (%s)', 'abandoned-cart-recovery-for-woocommerce-pro' ), $currency ),
			__( 'Times recovered', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			__( 'Quantity recovered', 'abandoned-cart-recovery-for-woocommerce-pro' ),
			/* translators: %s: currency code */
			sprintf( __( 'Amount recovered (%s)', 'abandoned-cart-recovery-for-woocommerce-pro' ), $currency ),
			__( 'Recovery %', 'abandoned-cart-recovery-for-woocommerce-pro' ),
		);

		$lines = array( self::csv_line( $header ) );
		foreach ( $items as $r ) {
			$lines[] = self::csv_line(
				array(
					$r['product_id'],
					$r['name'],
					$r['sku'],
					$r['abandoned'],
					$r['abandoned_quantity'],
					number_format( $r['abandoned_amount'], 2, '.', '' ),
					$r['recovered'],
					$r['recovered_quantity'],
					number_format( $r['recovered_amount'], 2, '.', '' ),
					number_format( $r['recovery_rate'], 1, '.', '' ),
				)
			);
		}

		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One CSV line. Text that a spreadsheet would run as a formula (starting
	 * with = + - @ or a tab/CR) is prefixed with an apostrophe.
	 *
	 * @param array $cells Cells.
	 */
	private static function csv_line( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			if ( ! is_numeric( $cell ) && '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ) {
				$cell = "'" . $cell;
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}
		return implode( ',', $out );
	}
}
