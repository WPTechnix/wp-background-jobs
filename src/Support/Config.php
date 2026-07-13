<?php
/**
 * Immutable configuration for a background jobs manager instance.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Support;

use InvalidArgumentException;

/**
 * Class Config.
 *
 * Holds the identifiers, table names, and tunables for a single manager
 * instance. Values are resolved once from the options array passed to
 * {@see Config::create()} and are then read only for the life of the request.
 * Runtime overrides are still possible through the filters applied by the
 * worker and dispatchers.
 *
 * @phpstan-type Config_Options array{
 *     max_attempts?: int,
 *     time_limit?: int,
 *     memory_factor?: float,
 *     reserve_timeout?: int,
 *     lock_time?: int,
 *     cron_interval?: int,
 *     allowed_job_classes?: list<class-string>|bool
 * }
 */
final class Config {

	/**
	 * Base number of seconds used by the default backoff formula.
	 */
	private const BACKOFF_BASE = 60;

	/**
	 * Upper bound, in seconds, for the default backoff formula.
	 */
	private const BACKOFF_MAX = 3_600;

	/**
	 * Config constructor.
	 *
	 * @param string                  $key                 Unique instance key used as a hook and table prefix.
	 * @param string                  $jobs_table          Full name of the jobs table.
	 * @param string                  $failures_table      Full name of the failures table.
	 * @param string                  $version_option      Option name holding the schema version.
	 * @param int                     $max_attempts        Default maximum attempts per job.
	 * @param int                     $time_limit          Seconds a single worker run may spend processing.
	 * @param float                   $memory_factor       Fraction of the PHP memory limit a worker may use.
	 * @param int                     $reserve_timeout     Seconds after which a stale reservation is reclaimed.
	 * @param int                     $lock_time           Seconds the worker stampede lock is held.
	 * @param int                     $cron_interval       Seconds between WP-Cron watchdog runs.
	 * @param list<class-string>|bool $allowed_job_classes Classes allowed during unserialization.
	 */
	public function __construct(
		private string $key,
		private string $jobs_table,
		private string $failures_table,
		private string $version_option,
		private int $max_attempts,
		private int $time_limit,
		private float $memory_factor,
		private int $reserve_timeout,
		private int $lock_time,
		private int $cron_interval,
		private array|bool $allowed_job_classes
	) {
	}

	/**
	 * Builds a configuration from an instance key and an options array.
	 *
	 * @param string $key          Unique instance key. Lowercase letters, numbers and underscores only.
	 * @param string $table_prefix The WordPress table prefix (usually `$wpdb->prefix`).
	 * @param array  $options      Optional overrides for the defaults.
	 *
	 * @phpstan-param Config_Options $options
	 *
	 * @return self The resolved configuration.
	 */
	public static function create( string $key, string $table_prefix, array $options = [] ): self {
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $key ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid instance key "%s". Use lowercase letters, numbers and underscores only.', $key )
			);
		}

		$base = $table_prefix . $key;

		// A non-positive fraction would set the memory ceiling to zero and stall
		// the worker on every run, so fall back to the default; a fraction above
		// one is meaningless and is capped so the guard still bounds usage.
		$memory_factor = (float) ( $options['memory_factor'] ?? 0.8 );
		if ( $memory_factor <= 0.0 ) {
			$memory_factor = 0.8;
		}
		$memory_factor = min( 1.0, $memory_factor );

		return new self(
			$key,
			$base . '_jobs',
			$base . '_job_failures',
			$key . '_jobs_db_version',
			(int) ( $options['max_attempts'] ?? 3 ),
			(int) ( $options['time_limit'] ?? 20 ),
			$memory_factor,
			(int) ( $options['reserve_timeout'] ?? 300 ),
			(int) ( $options['lock_time'] ?? 300 ),
			(int) ( $options['cron_interval'] ?? 60 ),
			$options['allowed_job_classes'] ?? true
		);
	}

	/**
	 * Returns the unique instance key.
	 *
	 * @return string The key.
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * Returns the full jobs table name.
	 *
	 * @return string The table name.
	 */
	public function get_jobs_table(): string {
		return $this->jobs_table;
	}

	/**
	 * Returns the full failures table name.
	 *
	 * @return string The table name.
	 */
	public function get_failures_table(): string {
		return $this->failures_table;
	}

	/**
	 * Returns the option name that stores the schema version.
	 *
	 * @return string The option name.
	 */
	public function get_version_option(): string {
		return $this->version_option;
	}

	/**
	 * Returns the default maximum attempts per job.
	 *
	 * @return int The maximum attempts.
	 */
	public function get_max_attempts(): int {
		return $this->max_attempts;
	}

	/**
	 * Returns the per run processing time budget in seconds.
	 *
	 * @return int The time limit.
	 */
	public function get_time_limit(): int {
		return $this->time_limit;
	}

	/**
	 * Returns the fraction of the PHP memory limit a worker may use.
	 *
	 * @return float The memory factor between 0 and 1.
	 */
	public function get_memory_factor(): float {
		return $this->memory_factor;
	}

	/**
	 * Returns the seconds after which a stale reservation is reclaimed.
	 *
	 * @return int The reserve timeout.
	 */
	public function get_reserve_timeout(): int {
		return $this->reserve_timeout;
	}

	/**
	 * Returns the seconds the worker stampede lock is held.
	 *
	 * @return int The lock time.
	 */
	public function get_lock_time(): int {
		return $this->lock_time;
	}

	/**
	 * Returns the seconds between WP-Cron watchdog runs.
	 *
	 * @return int The cron interval.
	 */
	public function get_cron_interval(): int {
		return $this->cron_interval;
	}

	/**
	 * Returns the classes allowed during unserialization.
	 *
	 * @return list<class-string>|bool The allow list, or a boolean for all/none.
	 */
	public function get_allowed_job_classes(): array|bool {
		return $this->allowed_job_classes;
	}

	/**
	 * Computes the default backoff delay for a failed attempt.
	 *
	 * Uses exponential growth capped at {@see Config::BACKOFF_MAX}: 60s, 120s,
	 * 240s, and so on.
	 *
	 * @param int $attempt The attempt number that has just failed (1 based).
	 *
	 * @return int The number of seconds to wait before the next attempt.
	 */
	public function backoff( int $attempt ): int {
		$exponent = max( 0, $attempt - 1 );
		$delay    = self::BACKOFF_BASE * ( 2 ** $exponent );

		return (int) min( $delay, self::BACKOFF_MAX );
	}
}
