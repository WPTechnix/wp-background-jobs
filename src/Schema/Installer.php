<?php
/**
 * Creates and upgrades the background jobs database tables.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Schema;

use InvalidArgumentException;
use RuntimeException;
use wpdb;

/**
 * Class Installer.
 *
 * Manages the schema for one manager instance. Table names are derived from a
 * per instance key so two plugins using this library never share tables. The
 * schema version is tracked in an option and upgrades run through `dbDelta`.
 */
final class Installer {

	/**
	 * Current schema version. Increment when the table structure changes.
	 */
	private const DB_VERSION = 100_001;

	/**
	 * WordPress database object.
	 */
	private wpdb $wpdb;

	/**
	 * Full name of the jobs table.
	 */
	private string $jobs_table;

	/**
	 * Full name of the failures table.
	 */
	private string $failures_table;

	/**
	 * Option name holding the installed schema version.
	 */
	private string $version_option;

	/**
	 * Installer constructor.
	 *
	 * @param wpdb   $wpdb           WordPress database object.
	 * @param string $jobs_table     Full name of the jobs table.
	 * @param string $failures_table Full name of the failures table.
	 * @param string $version_option Option name holding the schema version.
	 */
	public function __construct( wpdb $wpdb, string $jobs_table, string $failures_table, string $version_option ) {
		$this->assert_table_name( $jobs_table );
		$this->assert_table_name( $failures_table );

		$this->wpdb           = $wpdb;
		$this->jobs_table     = $jobs_table;
		$this->failures_table = $failures_table;
		$this->version_option = $version_option;
	}

	/**
	 * Creates or upgrades the tables when the installed version is out of date.
	 */
	public function install(): void {
		$installed = get_option( $this->version_option, 0 );
		$installed = is_numeric( $installed ) ? (int) $installed : 0;

		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		$this->run_schema_update();
		update_option( $this->version_option, self::DB_VERSION );
	}

	/**
	 * Drops the tables and removes the version option.
	 *
	 * Intended for use on plugin uninstall. This permanently deletes queued and
	 * failed jobs.
	 */
	public function uninstall(): void {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query( "DROP TABLE IF EXISTS {$this->jobs_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query( "DROP TABLE IF EXISTS {$this->failures_table}" );
		delete_option( $this->version_option );
	}

	/**
	 * Executes the schema creation and updates via `dbDelta`.
	 */
	private function run_schema_update(): void {
		$charset_collate = $this->wpdb->get_charset_collate();

		$jobs_sql = "CREATE TABLE {$this->jobs_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			queue VARCHAR(191) NOT NULL DEFAULT 'default',
			payload LONGTEXT NOT NULL,
			attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			reserved_at DATETIME NULL DEFAULT NULL,
			available_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY queue_reserved_available (queue, reserved_at, available_at),
			KEY reserved_available (reserved_at, available_at)
		) {$charset_collate};";

		$failures_sql = "CREATE TABLE {$this->failures_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			queue VARCHAR(191) NOT NULL,
			payload LONGTEXT NOT NULL,
			exception LONGTEXT NULL,
			failed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY queue (queue),
			KEY failed_at (failed_at)
		) {$charset_collate};";

		if ( ! file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			throw new RuntimeException( 'WordPress upgrade.php is missing. Cannot run database schema update.' );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $jobs_sql );
		dbDelta( $failures_sql );
	}

	/**
	 * Validates that a table name is safe to interpolate into SQL.
	 *
	 * @param string $table_name The table name to validate.
	 */
	private function assert_table_name( string $table_name ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid table name "%s". Only letters, numbers and underscores are allowed.', $table_name )
			);
		}
	}
}
