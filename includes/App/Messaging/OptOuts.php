<?php
namespace ACRFWP\App\Messaging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phone numbers that must not get WhatsApp or SMS reminders, per channel:
 * the customer replied with an opt-out keyword (STOP…), the provider said
 * they opted out, or an admin added the number.
 *
 * Table {prefix}acrfwp_phone_optouts, one row per number and channel:
 * - id
 * - phone      E.164 with "+", as PhoneNumber::to_e164() returns it
 * - channel    'whatsapp' or 'sms'
 * - source     'reply' (keyword from the customer), 'provider' (the
 *              provider reported an opt-out) or 'admin'
 * - created_at site time
 */
class OptOuts {

	const CHANNELS = array( 'whatsapp', 'sms' );
	const SOURCES  = array( 'reply', 'provider', 'admin' );

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'acrfwp_phone_optouts';
	}

	/** dbDelta, safe to run again. */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table();
		$sql   = "CREATE TABLE {$table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phone      VARCHAR(20)     NOT NULL,
			channel    VARCHAR(20)     NOT NULL DEFAULT 'whatsapp',
			source     VARCHAR(20)     NOT NULL DEFAULT 'reply',
			created_at DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY phone_channel (phone, channel)
		) {$wpdb->get_charset_collate()};";

		dbDelta( $sql );
	}

	/** @return bool True when added (false when already listed or invalid). */
	public static function add( string $phone, string $source, string $channel ): bool {
		global $wpdb;

		if ( ! preg_match( '/^\+\d{8,15}$/', $phone ) || ! in_array( $channel, self::CHANNELS, true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table; INSERT IGNORE keeps one row per number and channel.
		$added = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i ( phone, channel, source, created_at ) VALUES ( %s, %s, %s, %s )',
				self::table(),
				$phone,
				$channel,
				in_array( $source, self::SOURCES, true ) ? $source : 'admin',
				current_time( 'mysql' )
			)
		);

		return (bool) $added;
	}

	public static function has( string $phone, string $channel ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checked once per send.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT 1 FROM %i WHERE phone = %s AND channel = %s LIMIT 1', self::table(), $phone, $channel )
		);
	}

	/** One row of a channel's list, or null. */
	public static function get( int $id, string $channel ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin request.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND channel = %s', self::table(), $id, $channel ), ARRAY_A );

		return $row ? self::shape( $row ) : null;
	}

	public static function remove( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin request.
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/** The customer opted back in (e.g. replied START). */
	public static function remove_phone( string $phone, string $channel ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- webhook.
		return (bool) $wpdb->delete(
			self::table(),
			array(
				'phone'   => $phone,
				'channel' => $channel,
			)
		);
	}

	/**
	 * @param string $search  Part of the number.
	 * @param string $channel 'whatsapp' or 'sms'.
	 * @return array{items: array, total: int}
	 */
	public static function page( string $search, int $page, int $per_page, string $channel ): array {
		global $wpdb;

		$like   = '%' . $wpdb->esc_like( preg_replace( '/[^\d+]/', '', $search ) ) . '%';
		$offset = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list.
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE channel = %s AND phone LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $channel, $like, $per_page, $offset ),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE channel = %s AND phone LIKE %s', self::table(), $channel, $like ) );
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	private static function shape( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'phone'      => (string) $row['phone'],
			'channel'    => (string) $row['channel'],
			'source'     => (string) $row['source'],
			'created_at' => (string) $row['created_at'],
		);
	}
}
