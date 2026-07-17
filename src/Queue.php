<?php
/**
 * Database backed job queue.
 *
 * @package WPTechnix\WP_Background_Jobs
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs;

use InvalidArgumentException;
use Override;
use stdClass;
use Throwable;
use wpdb;
use WPTechnix\WP_Background_Jobs\Contracts\Job_Interface;
use WPTechnix\WP_Background_Jobs\Contracts\Queue_Interface;
use WPTechnix\WP_Background_Jobs\Exceptions\Invalid_Job_Exception;
use WPTechnix\WP_Background_Jobs\Support\Serializer;

/**
 * Class Queue.
 *
 * Stores jobs in a dedicated table and reserves them with an atomic
 * compare-and-swap update, which is safe under concurrency on every MySQL and
 * MariaDB version without needing row level locking such as `SKIP LOCKED`.
 */
final class Queue implements Queue_Interface {

	/**
	 * Maximum number of reservation attempts before giving up in a single pop.
	 */
	private const MAX_CLAIM_TRIES = 25;

	/**
	 * Rows inserted per statement by {@see Queue::push_many()}.
	 *
	 * Bulk inserts are split into chunks of this size so a very large batch cannot
	 * build one oversized statement that trips the server's `max_allowed_packet`
	 * limit and silently drops every job.
	 */
	private const INSERT_CHUNK_SIZE = 500;

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
	 * Serializer used to store and restore jobs.
	 */
	private Serializer $serializer;

	/**
	 * Seconds after which a reservation is considered stale and reclaimed.
	 */
	private int $reserve_timeout;

	/**
	 * Unix timestamp of the most recent stale-reservation reclaim.
	 */
	private int $last_reclaim_at = 0;

	/**
	 * Queue constructor.
	 *
	 * @param wpdb       $wpdb            WordPress database object.
	 * @param string     $jobs_table      Full name of the jobs table.
	 * @param string     $failures_table  Full name of the failures table.
	 * @param Serializer $serializer      Serializer used to store and restore jobs.
	 * @param int        $reserve_timeout Seconds before a reservation is reclaimed.
	 */
	public function __construct(
		wpdb $wpdb,
		string $jobs_table,
		string $failures_table,
		Serializer $serializer,
		int $reserve_timeout = 300
	) {
		$this->assert_table_name( $jobs_table );
		$this->assert_table_name( $failures_table );

		$this->wpdb            = $wpdb;
		$this->jobs_table      = $jobs_table;
		$this->failures_table  = $failures_table;
		$this->serializer      = $serializer;
		$this->reserve_timeout = $reserve_timeout;
	}

	/** @inheritDoc */
	#[Override]
	public function push( Job_Interface $job, int $delay = 0 ): int|false {
		$result = $this->wpdb->insert(
			$this->jobs_table,
			[
				'queue'        => $job->get_queue(),
				'payload'      => $this->serializer->serialize( $job ),
				'attempts'     => 0,
				'available_at' => $this->now( max( 0, $delay ) ),
				'created_at'   => $this->now(),
			],
			[ '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( false === $result ) {
			return false;
		}

		$id = (int) $this->wpdb->insert_id;
		$job->set_id( $id );

		return $id;
	}

	/** @inheritDoc */
	#[Override]
	public function push_many( array $jobs, int $delay = 0 ): int {
		if ( [] === $jobs ) {
			return 0;
		}

		$now       = $this->now();
		$available = $this->now( max( 0, $delay ) );
		$inserted  = 0;

		// Split into bounded chunks so a huge batch cannot build one statement that
		// exceeds max_allowed_packet and silently fails. A chunk that fails still
		// leaves earlier chunks persisted and its jobs counted out of the total.
		foreach ( array_chunk( $jobs, self::INSERT_CHUNK_SIZE ) as $chunk ) {
			$inserted += $this->insert_chunk( $chunk, $available, $now );
		}

		return $inserted;
	}

	/**
	 * Inserts one chunk of jobs with a single multi-row statement.
	 *
	 * @param array<int, Job_Interface> $jobs      The jobs in this chunk.
	 * @param string                    $available The shared `available_at` timestamp.
	 * @param string                    $now       The shared `created_at` timestamp.
	 *
	 * @return int The number of rows inserted.
	 */
	private function insert_chunk( array $jobs, string $available, string $now ): int {
		$columns      = [ 'queue', 'payload', 'attempts', 'available_at', 'created_at' ];
		$formats      = [ '%s', '%s', '%d', '%s', '%s' ];
		$placeholders = [];
		$values       = [];

		foreach ( $jobs as $job ) {
			$values[]       = $job->get_queue();
			$values[]       = $this->serializer->serialize( $job );
			$values[]       = 0;
			$values[]       = $available;
			$values[]       = $now;
			$placeholders[] = '(' . implode( ', ', $formats ) . ')';
		}

		$columns_sql = '`' . implode( '`, `', $columns ) . '`';
		$sql         = "INSERT INTO {$this->jobs_table} ($columns_sql) VALUES " . implode( ', ', $placeholders );

		// @phpstan-ignore-next-line
		$result = $this->wpdb->query( (string) $this->wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return is_int( $result ) ? $result : 0;
	}

	/** @inheritDoc */
	#[Override]
	public function pop( ?string $queue = null ): ?Job_Interface {
		// Reclaiming only becomes meaningful once per reserve window, so throttle
		// it rather than scanning the table before every single popped job.
		if ( time() - $this->last_reclaim_at >= max( 1, $this->reserve_timeout ) ) {
			$this->reclaim( $queue );
			$this->last_reclaim_at = time();
		}

		$tries = 0;
		while ( $tries < self::MAX_CLAIM_TRIES ) {
			++$tries;

			$id = $this->find_candidate_id( $queue );
			if ( null === $id ) {
				return null;
			}

			if ( ! $this->claim( $id ) ) {
				continue;
			}

			$job = $this->resolve_claimed( $id );
			if ( $job instanceof Job_Interface ) {
				return $job;
			}
		}

		return null;
	}

	/** @inheritDoc */
	#[Override]
	public function delete( Job_Interface $job ): bool {
		$id = $job->get_id();
		if ( null === $id ) {
			return false;
		}

		return false !== $this->wpdb->delete( $this->jobs_table, [ 'id' => $id ], [ '%d' ] );
	}

	/** @inheritDoc */
	#[Override]
	public function release( Job_Interface $job, int $delay = 0 ): bool {
		$id = $job->get_id();
		if ( null === $id ) {
			return false;
		}

		$result = $this->wpdb->update(
			$this->jobs_table,
			[
				'payload'      => $this->serializer->serialize( $job ),
				'attempts'     => $job->get_attempts(),
				'reserved_at'  => null,
				'available_at' => $this->now( max( 0, $delay ) ),
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/** @inheritDoc */
	#[Override]
	public function fail( Job_Interface $job, Throwable $exception ): bool {
		$insert = $this->wpdb->insert(
			$this->failures_table,
			[
				'queue'     => $job->get_queue(),
				'payload'   => $this->serializer->serialize( $job ),
				'exception' => $this->format_exception( $exception ),
				'failed_at' => $this->now(),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		if ( false === $insert ) {
			return false;
		}

		$this->delete( $job );

		return true;
	}

	/** @inheritDoc */
	#[Override]
	public function count( ?string $queue = null ): int {
		if ( null === $queue ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			$total = $this->wpdb->get_var( "SELECT COUNT(id) FROM {$this->jobs_table}" );
		} else {
			// @phpstan-ignore-next-line
			$total = $this->wpdb->get_var( (string) $this->wpdb->prepare( "SELECT COUNT(id) FROM {$this->jobs_table} WHERE queue = %s", $queue ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return (int) $total;
	}

	/** @inheritDoc */
	#[Override]
	public function count_available( ?string $queue = null ): int {
		$now = $this->now();

		if ( null === $queue ) {
			$sql = "SELECT COUNT(id) FROM {$this->jobs_table} WHERE reserved_at IS NULL AND available_at <= %s";
			// @phpstan-ignore-next-line
			$total = $this->wpdb->get_var( (string) $this->wpdb->prepare( $sql, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$sql = "SELECT COUNT(id) FROM {$this->jobs_table} WHERE queue = %s AND reserved_at IS NULL AND available_at <= %s";
			// @phpstan-ignore-next-line
			$total = $this->wpdb->get_var( (string) $this->wpdb->prepare( $sql, $queue, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return (int) $total;
	}

	/** @inheritDoc */
	#[Override]
	public function is_empty( ?string $queue = null ): bool {
		return 0 === $this->count( $queue );
	}

	/**
	 * Counts the jobs recorded in the failures table.
	 *
	 * @return int The number of failed jobs.
	 */
	public function count_failed(): int {
		// phpcs:ignore WordPress.DB.PreparedSQL
		$total = $this->wpdb->get_var( "SELECT COUNT(id) FROM {$this->failures_table}" );

		return (int) $total;
	}

	/**
	 * Lists recorded failures, newest first.
	 *
	 * @param string|null $queue  The queue to list, or null for all queues.
	 * @param int         $limit  Maximum rows to return.
	 * @param int         $offset Rows to skip for pagination.
	 *
	 * @return array<int, stdClass> The failure rows (id, queue, exception, failed_at).
	 */
	public function list_failed( ?string $queue = null, int $limit = 50, int $offset = 0 ): array {
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		if ( null === $queue ) {
			$sql = "SELECT id, queue, exception, failed_at FROM {$this->failures_table} ORDER BY failed_at DESC, id DESC LIMIT %d OFFSET %d";
			// @phpstan-ignore-next-line
			$rows = $this->wpdb->get_results( (string) $this->wpdb->prepare( $sql, $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$sql = "SELECT id, queue, exception, failed_at FROM {$this->failures_table} WHERE queue = %s ORDER BY failed_at DESC, id DESC LIMIT %d OFFSET %d";
			// @phpstan-ignore-next-line
			$rows = $this->wpdb->get_results( (string) $this->wpdb->prepare( $sql, $queue, $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Moves failed jobs back to the queue for another attempt.
	 *
	 * Re-queued jobs get a fresh attempt budget (attempts reset to 0) and become
	 * available immediately. Only rows that exist when the retry starts are moved,
	 * so failures recorded concurrently are never lost.
	 *
	 * @param string|null $queue The queue to retry, or null for all queues.
	 *
	 * @return int The number of jobs moved back to the queue.
	 */
	public function retry_failed( ?string $queue = null ): int {
		if ( null === $queue ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			$max_id = (int) $this->wpdb->get_var( "SELECT MAX(id) FROM {$this->failures_table}" );
		} else {
			// @phpstan-ignore-next-line
			$max_id = (int) $this->wpdb->get_var( (string) $this->wpdb->prepare( "SELECT MAX(id) FROM {$this->failures_table} WHERE queue = %s", $queue ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		if ( 0 === $max_id ) {
			return 0;
		}

		$now = $this->now();

		if ( null === $queue ) {
			$insert_sql = "INSERT INTO {$this->jobs_table} (`queue`, `payload`, `attempts`, `available_at`, `created_at`) SELECT queue, payload, 0, %s, %s FROM {$this->failures_table} WHERE id <= %d";
			// @phpstan-ignore-next-line
			$moved = $this->wpdb->query( (string) $this->wpdb->prepare( $insert_sql, $now, $now, $max_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

			// @phpstan-ignore-next-line
			$this->wpdb->query( (string) $this->wpdb->prepare( "DELETE FROM {$this->failures_table} WHERE id <= %d", $max_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$insert_sql = "INSERT INTO {$this->jobs_table} (`queue`, `payload`, `attempts`, `available_at`, `created_at`) SELECT queue, payload, 0, %s, %s FROM {$this->failures_table} WHERE id <= %d AND queue = %s";
			// @phpstan-ignore-next-line
			$moved = $this->wpdb->query( (string) $this->wpdb->prepare( $insert_sql, $now, $now, $max_id, $queue ) ); // phpcs:ignore WordPress.DB.PreparedSQL

			// @phpstan-ignore-next-line
			$this->wpdb->query( (string) $this->wpdb->prepare( "DELETE FROM {$this->failures_table} WHERE id <= %d AND queue = %s", $max_id, $queue ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return is_int( $moved ) ? $moved : 0;
	}

	/**
	 * Deletes rows from the failures table.
	 *
	 * @param string|null $queue The queue to clear, or null for all queues.
	 *
	 * @return int The number of failed jobs removed.
	 */
	public function purge_failed( ?string $queue = null ): int {
		if ( null === $queue ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			$result = $this->wpdb->query( "DELETE FROM {$this->failures_table}" );
		} else {
			// @phpstan-ignore-next-line
			$result = $this->wpdb->query( (string) $this->wpdb->prepare( "DELETE FROM {$this->failures_table} WHERE queue = %s", $queue ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return is_int( $result ) ? $result : 0;
	}

	/** @inheritDoc */
	#[Override]
	public function purge( ?string $queue = null ): int {
		if ( null === $queue ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			$result = $this->wpdb->query( "DELETE FROM {$this->jobs_table}" );
		} else {
			// @phpstan-ignore-next-line
			$result = $this->wpdb->query( (string) $this->wpdb->prepare( "DELETE FROM {$this->jobs_table} WHERE queue = %s", $queue ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return is_int( $result ) ? $result : 0;
	}

	/** @inheritDoc */
	#[Override]
	public function reclaim( ?string $queue = null ): int {
		$threshold = $this->now( -$this->reserve_timeout );

		if ( null === $queue ) {
			$sql = "UPDATE {$this->jobs_table} SET reserved_at = NULL WHERE reserved_at IS NOT NULL AND reserved_at <= %s";
			// @phpstan-ignore-next-line
			$result = $this->wpdb->query( (string) $this->wpdb->prepare( $sql, $threshold ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$sql = "UPDATE {$this->jobs_table} SET reserved_at = NULL WHERE queue = %s AND reserved_at IS NOT NULL AND reserved_at <= %s";
			// @phpstan-ignore-next-line
			$result = $this->wpdb->query( (string) $this->wpdb->prepare( $sql, $queue, $threshold ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Finds the id of the next available job.
	 *
	 * @param string|null $queue The queue to search, or null for any queue.
	 *
	 * @return int|null The job id, or null when none is available.
	 */
	private function find_candidate_id( ?string $queue ): ?int {
		$now = $this->now();

		if ( null === $queue ) {
			$sql = "SELECT id FROM {$this->jobs_table} WHERE reserved_at IS NULL AND available_at <= %s ORDER BY available_at ASC, id ASC LIMIT 1";
			// @phpstan-ignore-next-line
			$id = $this->wpdb->get_var( (string) $this->wpdb->prepare( $sql, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$sql = "SELECT id FROM {$this->jobs_table} WHERE queue = %s AND reserved_at IS NULL AND available_at <= %s ORDER BY available_at ASC, id ASC LIMIT 1";
			// @phpstan-ignore-next-line
			$id = $this->wpdb->get_var( (string) $this->wpdb->prepare( $sql, $queue, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return null === $id ? null : (int) $id;
	}

	/**
	 * Attempts to reserve a specific job atomically.
	 *
	 * @param int $id The job id to claim.
	 *
	 * @return bool True when this process won the reservation.
	 */
	private function claim( int $id ): bool {
		$sql = "UPDATE {$this->jobs_table} SET reserved_at = %s, attempts = attempts + 1 WHERE id = %d AND reserved_at IS NULL";
		// @phpstan-ignore-next-line
		$result = $this->wpdb->query( (string) $this->wpdb->prepare( $sql, $this->now(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return 1 === $result;
	}

	/**
	 * Loads and rehydrates a job that was just claimed.
	 *
	 * @param int $id The claimed job id.
	 *
	 * @return Job_Interface|null The job, or null when the row is gone or invalid.
	 */
	private function resolve_claimed( int $id ): ?Job_Interface {
		$row = $this->fetch_row( $id );
		if ( null === $row ) {
			return null;
		}

		$job = $this->serializer->unserialize( (string) $row->payload );
		if ( ! $job instanceof Job_Interface ) {
			$this->fail_raw( $id, (string) $row->queue, (string) $row->payload );
			return null;
		}

		$job->set_id( $id );
		$job->set_attempts( (int) $row->attempts );

		return $job;
	}

	/**
	 * Fetches the raw row for a job.
	 *
	 * @param int $id The job id.
	 *
	 * @return stdClass|null The row, or null when it no longer exists.
	 */
	private function fetch_row( int $id ): ?stdClass {
		// @phpstan-ignore-next-line
		$row = $this->wpdb->get_row( (string) $this->wpdb->prepare( "SELECT id, queue, payload, attempts FROM {$this->jobs_table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row instanceof stdClass ? $row : null;
	}

	/**
	 * Moves an unrecoverable raw payload to the failures table.
	 *
	 * @param int    $id      The job id.
	 * @param string $queue   The originating queue name.
	 * @param string $payload The raw serialized payload.
	 */
	private function fail_raw( int $id, string $queue, string $payload ): void {
		$this->wpdb->insert(
			$this->failures_table,
			[
				'queue'     => $queue,
				'payload'   => $payload,
				'exception' => $this->format_exception(
					new Invalid_Job_Exception( 'Queued payload could not be resolved to a valid job.' )
				),
				'failed_at' => $this->now(),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		$this->wpdb->delete( $this->jobs_table, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Formats an exception for storage in the failures table.
	 *
	 * @param Throwable $exception The exception to format.
	 *
	 * @return string The formatted exception summary and trace.
	 */
	private function format_exception( Throwable $exception ): string {
		return sprintf(
			"%s: %s (#%s)\n%s",
			$exception::class,
			$exception->getMessage(),
			(string) $exception->getCode(),
			$exception->getTraceAsString()
		);
	}

	/**
	 * Returns a UTC timestamp string, optionally offset by seconds.
	 *
	 * @param int $offset Seconds to add to the current time.
	 *
	 * @return string The formatted `Y-m-d H:i:s` timestamp.
	 */
	private function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
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
