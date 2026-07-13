<?php
/**
 * WP-CLI commands for running and inspecting the queue.
 *
 * @package WPTechnix\WP_Background_Jobs\CLI
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\CLI;

use WP_CLI;
use WPTechnix\WP_Background_Jobs\Queue;
use WPTechnix\WP_Background_Jobs\Worker;

use function WP_CLI\Utils\format_items;

/**
 * Class Worker_Command.
 *
 * Registered under the manager's key, for example `wp myplugin work`. Provides a
 * long running worker for large backlogs plus simple inspection and maintenance
 * commands.
 */
final class Worker_Command {

	/**
	 * The worker used to process jobs.
	 *
	 * @var Worker
	 */
	private Worker $worker;

	/**
	 * The queue, used for counts and maintenance.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Worker_Command constructor.
	 *
	 * @param Worker $worker The worker used to process jobs.
	 * @param Queue  $queue  The queue, used for counts and maintenance.
	 */
	public function __construct( Worker $worker, Queue $queue ) {
		$this->worker = $worker;
		$this->queue  = $queue;
	}

	/**
	 * Registers the command set under the given key.
	 *
	 * @param string $key    The manager key, used as the command namespace.
	 * @param Worker $worker The worker used to process jobs.
	 * @param Queue  $queue  The queue, used for counts and maintenance.
	 */
	public static function register( string $key, Worker $worker, Queue $queue ): void {
		WP_CLI::add_command( $key, new self( $worker, $queue ) );
	}

	/**
	 * Processes pending jobs until the queue is drained.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Only process the named queue. Defaults to all queues.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 *
	 * @phpstan-param array<int, string>    $args
	 * @phpstan-param array<string, string> $assoc_args
	 */
	public function work( array $args, array $assoc_args ): void {
		unset( $args );

		$queue = isset( $assoc_args['queue'] ) ? (string) $assoc_args['queue'] : null;
		$total = 0;

		do {
			$processed = $this->worker->run( $queue );
			$total    += $processed;
		} while ( $processed > 0 && ! $this->queue->is_empty( $queue ) );

		WP_CLI::success( sprintf( 'Processed %d job(s).', $total ) );
	}

	/**
	 * Shows how many jobs are pending and how many have failed.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 *
	 * @phpstan-param array<int, string>    $args
	 * @phpstan-param array<string, string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		WP_CLI::line( sprintf( 'Pending: %d', $this->queue->count( null ) ) );
		WP_CLI::line( sprintf( 'Failed:  %d', $this->queue->count_failed() ) );
	}

	/**
	 * Deletes jobs from the queue.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Only flush the named queue. Defaults to all queues.
	 *
	 * [--failed]
	 * : Clear the failures table instead of pending jobs.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 *
	 * @phpstan-param array<int, string>    $args
	 * @phpstan-param array<string, string> $assoc_args
	 */
	public function flush( array $args, array $assoc_args ): void {
		unset( $args );

		$queue = isset( $assoc_args['queue'] ) ? (string) $assoc_args['queue'] : null;

		if ( isset( $assoc_args['failed'] ) ) {
			$removed = $this->queue->purge_failed( $queue );
			WP_CLI::success( sprintf( 'Removed %d failed job(s).', $removed ) );
			return;
		}

		$removed = $this->queue->purge( $queue );
		WP_CLI::success( sprintf( 'Removed %d pending job(s).', $removed ) );
	}

	/**
	 * Moves failed jobs back to the queue for another attempt.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Only retry the named queue. Defaults to all queues.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 *
	 * @phpstan-param array<int, string>    $args
	 * @phpstan-param array<string, string> $assoc_args
	 */
	public function retry( array $args, array $assoc_args ): void {
		unset( $args );

		$queue    = isset( $assoc_args['queue'] ) ? (string) $assoc_args['queue'] : null;
		$requeued = $this->queue->retry_failed( $queue );

		WP_CLI::success( sprintf( 'Requeued %d failed job(s).', $requeued ) );
	}

	/**
	 * Lists recorded failures, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Only list the named queue. Defaults to all queues.
	 *
	 * [--limit=<limit>]
	 * : Maximum number of failures to show. Defaults to 50.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 *
	 * @phpstan-param array<int, string>    $args
	 * @phpstan-param array<string, string> $assoc_args
	 */
	public function failed( array $args, array $assoc_args ): void {
		unset( $args );

		$queue = isset( $assoc_args['queue'] ) ? (string) $assoc_args['queue'] : null;
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50;

		$rows = $this->queue->list_failed( $queue, $limit );

		if ( [] === $rows ) {
			WP_CLI::line( 'No failed jobs.' );
			return;
		}

		$items = array_map(
			static fn ( $row ) => [
				'id'        => (int) $row->id,
				'queue'     => (string) $row->queue,
				'failed_at' => (string) $row->failed_at,
				'exception' => (string) strtok( (string) $row->exception, "\n" ),
			],
			$rows
		);

		format_items( 'table', $items, [ 'id', 'queue', 'failed_at', 'exception' ] );
	}
}
