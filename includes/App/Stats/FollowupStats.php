<?php
namespace ACRFWP\App\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Follow-up stats for the free plugin's step and template API responses.
 *
 * Not a REST controller: it answers the free plugin's filter, so it lives in
 * App/ and is registered by the ACRFWP\App dispatcher.
 *
 * The free plugin asks the `acrfw_followup_stats` filter once per response
 * (ACRFW\App\Followups\Stats::for()) with every step or template ID in it;
 * this answers with one grouped query over acrfw_followup_messages.
 *
 * - sent:         real sends that went out (sent_at set; not test, skipped,
 *                 failed or queued).
 * - opened:       open pixel loaded (email only; open_rate is null for SMS /
 *                 WhatsApp, which can't report opens).
 * - clicked:      recovery link clicked.
 * - converted:    paid order placed through this message's link.
 * - unsubscribed: unsubscribe link used.
 * - revenue:      net total (refunds deducted) of the converted orders,
 *                 summed in SQL — no order objects are loaded, however many
 *                 orders there are.
 * Rates are count ÷ sent × 100, 2 decimals; 0 when nothing was sent.
 * All-time numbers.
 */
class FollowupStats {

	/** Message column each type groups by. */
	const GROUP_COLUMNS = array(
		'step'     => 'sequence_id',
		'template' => 'template_id',
	);

	public static function init(): void {
		$self = new self();
		add_filter( 'acrfw_followup_stats', array( $self, 'stats' ), 10, 3 );
	}

	/**
	 * @param array  $stats Stats from earlier callbacks (array() by default).
	 * @param string $type  'step' or 'template'.
	 * @param int[]  $ids   Step or template IDs.
	 * @return array<int, array> ID => stats, for every ID asked.
	 */
	public function stats( $stats, $type, $ids ): array {
		$stats = is_array( $stats ) ? $stats : array();

		if ( ! isset( self::GROUP_COLUMNS[ $type ] ) || ! is_array( $ids ) ) {
			return $stats;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return $stats;
		}

		$rows    = $this->counts( self::GROUP_COLUMNS[ $type ], $ids );
		$revenue = $this->revenue( self::GROUP_COLUMNS[ $type ], $ids );

		foreach ( $ids as $id ) {
			$row          = $rows[ $id ] ?? null;
			$stats[ $id ] = $this->shape( $row, (float) ( $revenue[ $id ] ?? 0 ) );
		}

		return $stats;
	}

	/**
	 * Funnel counts per ID, one grouped query.
	 *
	 * @param string $column 'sequence_id' or 'template_id' (from GROUP_COLUMNS, never request input).
	 * @param int[]  $ids    IDs.
	 * @return array<int, object>
	 */
	private function counts( string $column, array $ids ): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $column comes from GROUP_COLUMNS and $placeholders is a list of %d; every value is bound by prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS id,
				        COUNT(*)                                       AS sent,
				        SUM( opened_at IS NOT NULL )                   AS opened,
				        SUM( clicked_at IS NOT NULL )                  AS clicked,
				        SUM( converted_at IS NOT NULL )                AS converted,
				        SUM( unsubscribed_at IS NOT NULL )             AS unsubscribed,
				        SUM( channel = 'email' )                       AS emails
				   FROM %i
				  WHERE {$column} IN ( {$placeholders} )
				    AND is_test = 0
				    AND sent_at IS NOT NULL
				    AND status NOT IN ( 'queued', 'failed', 'skipped' )
				  GROUP BY {$column}",
				array_merge( array( acrfw_table( 'followup_messages' ) ), $ids )
			)
		);
		// phpcs:enable

		$by_id = array();
		foreach ( (array) $rows as $row ) {
			$by_id[ (int) $row->id ] = $row;
		}

		return $by_id;
	}

	/**
	 * Net revenue of the orders converted through each ID's messages.
	 *
	 * One aggregate query, whatever the number of orders. It reads the order
	 * storage WooCommerce uses (HPOS tables or posts) directly. Refunds are
	 * stored with a negative total in both, so adding them gives the net
	 * amount, the same as $order->get_total() - $order->get_total_refunded().
	 * Orders deleted since are left out.
	 *
	 * @param string    $column 'sequence_id' or 'template_id'.
	 * @param int[]     $ids    IDs.
	 * @param bool|null $hpos   Storage to read; null = the store's setting.
	 * @return array<int, float>
	 */
	private function revenue( string $column, array $ids, ?bool $hpos = null ): array {
		global $wpdb;

		$hpos         = null === $hpos ? self::uses_hpos() : $hpos;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$messages     = acrfw_table( 'followup_messages' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $column comes from GROUP_COLUMNS and $placeholders is a list of %d; every value is bound by prepare().
		if ( $hpos ) {
			$orders = OrderUtil::get_table_for_orders();
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.{$column} AS id,
					        SUM( o.total_amount + COALESCE( (
					            SELECT SUM( r.total_amount ) FROM %i r
					             WHERE r.parent_order_id = o.id AND r.type = 'shop_order_refund'
					        ), 0 ) ) AS revenue
					   FROM %i m
					   JOIN %i o ON o.id = m.order_id AND o.type = 'shop_order'
					  WHERE m.{$column} IN ( {$placeholders} )
					    AND m.is_test = 0 AND m.converted_at IS NOT NULL
					  GROUP BY m.{$column}",
					array_merge( array( $orders, $messages, $orders ), $ids )
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.{$column} AS id,
					        SUM( CAST( t.meta_value AS DECIMAL(26,8) ) + COALESCE( (
					            SELECT SUM( CAST( rt.meta_value AS DECIMAL(26,8) ) )
					              FROM %i rp
					              JOIN %i rt ON rt.post_id = rp.ID AND rt.meta_key = '_order_total'
					             WHERE rp.post_parent = p.ID AND rp.post_type = 'shop_order_refund'
					        ), 0 ) ) AS revenue
					   FROM %i m
					   JOIN %i p ON p.ID = m.order_id AND p.post_type = 'shop_order'
					   JOIN %i t ON t.post_id = p.ID AND t.meta_key = '_order_total'
					  WHERE m.{$column} IN ( {$placeholders} )
					    AND m.is_test = 0 AND m.converted_at IS NOT NULL
					  GROUP BY m.{$column}",
					array_merge( array( $wpdb->posts, $wpdb->postmeta, $messages, $wpdb->posts, $wpdb->postmeta ), $ids )
				)
			);
		}
		// phpcs:enable

		$revenue = array();
		foreach ( (array) $rows as $row ) {
			$revenue[ (int) $row->id ] = (float) $row->revenue;
		}

		return $revenue;
	}

	/** Whether WooCommerce stores orders in its own tables (HPOS) rather than posts. */
	private static function uses_hpos(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Counts and rates for one ID.
	 *
	 * @param object|null $row     counts() row, or null when nothing was sent.
	 * @param float       $revenue Converted orders' total.
	 */
	private function shape( $row, float $revenue ): array {
		$sent         = $row ? (int) $row->sent : 0;
		$opened       = $row ? (int) $row->opened : 0;
		$clicked      = $row ? (int) $row->clicked : 0;
		$converted    = $row ? (int) $row->converted : 0;
		$unsubscribed = $row ? (int) $row->unsubscribed : 0;
		$emails       = $row ? (int) $row->emails : 0;

		$rate = static function ( int $count, int $of ): float {
			return $of > 0 ? round( $count / $of * 100, 2 ) : 0.0;
		};

		return array(
			'sent'             => $sent,
			'opened'           => $opened,
			'clicked'          => $clicked,
			'converted'        => $converted,
			'unsubscribed'     => $unsubscribed,
			// Opens are only trackable in email; null when none of these sends were emails.
			'open_rate'        => $sent > 0 && 0 === $emails ? null : $rate( $opened, $emails ),
			'click_rate'       => $rate( $clicked, $sent ),
			'conversion_rate'  => $rate( $converted, $sent ),
			'unsubscribe_rate' => $rate( $unsubscribed, $sent ),
			'revenue'          => round( $revenue, 2 ),
		);
	}
}
