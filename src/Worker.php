<?php
/**
 * Processes queued jobs within time and memory budgets.
 *
 * @package WPTechnix\WP_Background_Jobs
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs;

use Throwable;
use WPTechnix\WP_Background_Jobs\Contracts\Job_Interface;
use WPTechnix\WP_Background_Jobs\Contracts\Queue_Interface;
use WPTechnix\WP_Background_Jobs\Exceptions\Max_Attempts_Exceeded_Exception;
use WPTechnix\WP_Background_Jobs\Support\Config;

/**
 * Class Worker.
 *
 * Drains jobs from the queue, catching every {@see Throwable} so a single bad
 * job can never fatally stop the run. Failed jobs are retried with backoff until
 * they exhaust their attempts, at which point they are moved to the failures
 * table.
 */
final class Worker {

	/**
	 * Hard ceiling for the resolved maximum attempts.
	 *
	 * The `attempts` column is `TINYINT(3) UNSIGNED`, so a job's attempt count
	 * saturates at 255. A configured maximum above that could never be reached,
	 * leaving a perpetually failing job to retry forever, so the resolved value is
	 * capped here to keep the retirement path reachable.
	 */
	private const MAX_ATTEMPTS_CEILING = 255;

	/**
	 * The queue to process.
	 */
	private Queue_Interface $queue;

	/**
	 * Manager configuration.
	 */
	private Config $config;

	/**
	 * Unix timestamp when the current run started.
	 */
	private int $start_time = 0;

	/**
	 * Worker constructor.
	 *
	 * @param Queue_Interface $queue  The queue to process.
	 * @param Config          $config Manager configuration.
	 */
	public function __construct( Queue_Interface $queue, Config $config ) {
		$this->queue  = $queue;
		$this->config = $config;
	}

	/**
	 * Processes jobs until the queue drains or a resource budget is reached.
	 *
	 * @param string|null $queue The queue to process, or null for all queues.
	 *
	 * @return int The number of jobs processed during this run.
	 */
	public function run( ?string $queue = null ): int {
		$key              = $this->config->get_key();
		$this->start_time = time();
		$processed        = 0;

		while ( ! $this->should_stop( $processed ) ) {
			$job = $this->queue->pop( $queue );

			if ( null === $job ) {
				do_action( "{$key}_queue_empty", $queue );
				break;
			}

			$this->process( $job );
			++$processed;
		}

		return $processed;
	}

	/**
	 * Runs a single job and records the outcome.
	 *
	 * @param Job_Interface $job The job to process.
	 */
	public function process( Job_Interface $job ): void {
		$key = $this->config->get_key();

		// A job whose attempt count already exceeds its maximum can only reach
		// here after crashing the process on every prior attempt: each crash
		// consumed an attempt at claim time but never ran the catchable failure
		// path, and the stale reservation was later reclaimed. Retire it now so a
		// job that fatally crashes cannot be retried forever.
		$max = $this->resolve_max_attempts( $job );
		if ( $job->get_attempts() > $max ) {
			$exception = new Max_Attempts_Exceeded_Exception(
				sprintf( 'Job exceeded its maximum of %d attempt(s) without completing.', $max )
			);
			$this->queue->fail( $job, $exception );
			do_action( "{$key}_job_failed", $job, $exception );
			return;
		}

		do_action( "{$key}_job_processing", $job );

		try {
			$job->handle();
		} catch ( Throwable $exception ) {
			$this->handle_failure( $job, $exception, $max );
			return;
		}

		$this->queue->delete( $job );
		do_action( "{$key}_job_processed", $job );
	}

	/**
	 * Decides whether a failed job is retried or moved to the failures table.
	 *
	 * @param Job_Interface $job       The failed job.
	 * @param Throwable     $exception The exception thrown by the job.
	 * @param int           $max       The resolved maximum attempts for this job.
	 */
	private function handle_failure( Job_Interface $job, Throwable $exception, int $max ): void {
		$key     = $this->config->get_key();
		$attempt = $job->get_attempts();

		if ( $attempt >= $max ) {
			$this->queue->fail( $job, $exception );
			do_action( "{$key}_job_failed", $job, $exception );
			return;
		}

		$delay = $this->resolve_backoff( $job, $attempt );
		$this->queue->release( $job, $delay );
		do_action( "{$key}_job_released", $job, $exception, $delay );
	}

	/**
	 * Resolves the effective maximum attempts for a job.
	 *
	 * The result is clamped to between 1 and {@see Worker::MAX_ATTEMPTS_CEILING},
	 * the largest value the `attempts` column can hold.
	 *
	 * @param Job_Interface $job The job being evaluated.
	 *
	 * @return int The maximum number of attempts, between 1 and 255.
	 */
	private function resolve_max_attempts( Job_Interface $job ): int {
		$max = $job->get_max_attempts();
		if ( $max <= 0 ) {
			$max = $this->config->get_max_attempts();
		}

		$filtered = apply_filters( "{$this->config->get_key()}_max_attempts", $max, $job );

		return max( 1, min( self::MAX_ATTEMPTS_CEILING, (int) $filtered ) );
	}

	/**
	 * Resolves the retry delay in seconds for a failed attempt.
	 *
	 * @param Job_Interface $job     The job being retried.
	 * @param int           $attempt The attempt number that just failed.
	 *
	 * @return int The number of seconds to wait, never negative.
	 */
	private function resolve_backoff( Job_Interface $job, int $attempt ): int {
		$delay = $job->get_backoff( $attempt );
		if ( $delay < 0 ) {
			$delay = $this->config->backoff( $attempt );
		}

		$filtered = apply_filters( "{$this->config->get_key()}_backoff", $delay, $attempt, $job );

		return max( 0, (int) $filtered );
	}

	/**
	 * Determines whether the run should stop due to a resource budget.
	 *
	 * The memory budget is only enforced once at least one job has been
	 * processed. This guarantees forward progress: on a host whose baseline
	 * memory already sits above the ceiling the queue would otherwise never
	 * drain, and the async dispatcher would keep re-firing loopback requests
	 * that each process nothing. Allowing one job per run means the queue
	 * advances one job at a time instead of stalling forever. The time budget
	 * is always checked but never trips on the first iteration because the run
	 * has only just started.
	 *
	 * @param int $processed Number of jobs processed so far in this run.
	 *
	 * @return bool True when the time or memory budget is reached.
	 */
	private function should_stop( int $processed ): bool {
		if ( $this->time_exceeded() ) {
			return true;
		}

		return $processed > 0 && $this->memory_exceeded();
	}

	/**
	 * Checks whether the processing time budget has been reached.
	 *
	 * @return bool True when the time limit is reached.
	 */
	private function time_exceeded(): bool {
		$limit = (int) apply_filters( "{$this->config->get_key()}_time_limit", $this->config->get_time_limit() );

		return ( time() - $this->start_time ) >= max( 1, $limit );
	}

	/**
	 * Checks whether the memory budget has been reached.
	 *
	 * @return bool True when memory usage exceeds the configured fraction.
	 */
	private function memory_exceeded(): bool {
		$factor  = (float) apply_filters( "{$this->config->get_key()}_memory_factor", $this->config->get_memory_factor() );
		$ceiling = (float) $this->get_memory_limit() * $factor;

		return (float) memory_get_usage( true ) >= $ceiling;
	}

	/**
	 * Returns the PHP memory limit in bytes, treating "unlimited" as 1 GB.
	 *
	 * @return int The memory limit in bytes.
	 */
	private function get_memory_limit(): int {
		$limit = ini_get( 'memory_limit' );

		if ( false === $limit || '' === $limit || '-1' === $limit ) {
			return 1_024 * 1_024 * 1_024;
		}

		return $this->parse_bytes( $limit );
	}

	/**
	 * Converts a PHP shorthand byte value (for example `256M`) to bytes.
	 *
	 * @param string $value The shorthand value.
	 *
	 * @return int The number of bytes.
	 */
	private function parse_bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}

		$unit        = strtolower( $value[ strlen( $value ) - 1 ] );
		$bytes       = (int) $value;
		$multipliers = [
			'g' => 1_024 ** 3,
			'm' => 1_024 ** 2,
			'k' => 1_024,
		];

		if ( isset( $multipliers[ $unit ] ) ) {
			return $bytes * $multipliers[ $unit ];
		}

		return $bytes;
	}
}
