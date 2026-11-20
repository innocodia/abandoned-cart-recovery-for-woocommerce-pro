<?php
namespace ACRFWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation and version-gated upgrades for the Pro plugin.
 *
 * Like ACRFW\Installer: init() runs on activation, check_update() on every
 * load and runs maybe_run_migrations() once per new version. Pro's own tables
 * are created here (maybe_create_tables(), gated by DB_VERSION so a new table
 * also reaches sites without a version bump); the free plugin's tables are
 * owned by the free plugin.
 *
 * Pro tables: {prefix}acrfwp_phone_optouts (App\WhatsApp\OptOuts).
 */
class Installer {

	const VERSION_OPTION = 'acrfwp_version';

	/** Bump when a Pro table is added or changed. */
	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'acrfwp_db_version';

	/** Runs on activation. */
	public static function init(): void {
		self::maybe_create_tables();
		self::maybe_run_migrations( (string) get_option( self::VERSION_OPTION, '' ) );
		update_option( self::VERSION_OPTION, ACRFWP_VERSION, false );
	}

	/** Runs on every load; does work only after an update. */
	public static function check_update(): void {
		self::maybe_create_tables();

		$installed = (string) get_option( self::VERSION_OPTION, '' );

		if ( ACRFWP_VERSION === $installed ) {
			return;
		}

		self::maybe_run_migrations( $installed );
		update_option( self::VERSION_OPTION, ACRFWP_VERSION, false );
	}

	/** Create or update Pro's tables when DB_VERSION changed. */
	private static function maybe_create_tables(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}

		App\WhatsApp\OptOuts::create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Upgrade steps for installs older than the current version.
	 *
	 * @param string $from Previously installed version ('' on first install).
	 */
	private static function maybe_run_migrations( string $from ): void {
		/**
		 * Fires once per Pro version change, before the new version is stored.
		 *
		 * @param string $from Previously installed version ('' on first install).
		 * @param string $to   ACRFWP_VERSION.
		 */
		do_action( 'acrfwp_migrate', $from, ACRFWP_VERSION );
	}
}
