<?php
/**
 * Base class for background jobs.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs;

use WPTechnix\WP_Background_Jobs\Contracts\Job_Interface;

/**
 * Class Job.
 *
 * Extend this class and implement {@see Job::handle()} to define a unit of work.
 * Public and protected properties on the subclass form the payload and are
 * serialized when the job is queued, so keep them limited to plain, serializable
 * data. Closures and resources cannot be queued.
 *
 * The identifier and attempt count are runtime metadata assigned by the queue.
 * They are restored from dedicated table columns after unserialization, so their
 * serialized values are never trusted.
 */
abstract class Job implements Job_Interface {

	/**
	 * The database identifier once the job is persisted.
	 *
	 * @var int|null
	 */
	protected ?int $id = null;

	/**
	 * How many times this job has been attempted.
	 *
	 * @var int
	 */
	protected int $attempts = 0;

	/**
	 * The queue this job is routed to, when set explicitly.
	 *
	 * @var string|null
	 */
	protected ?string $queue = null;

	/**
	 * Performs the work for this job.
	 */
	abstract public function handle(): void;

	/**
	 * Returns the name of the queue this job belongs to.
	 *
	 * Defaults to the queue set via {@see Job::on_queue()}, or `default`. Override
	 * this method to hard code a queue for a specific job type.
	 *
	 * @return string The queue name.
	 */
	public function get_queue(): string {
		return $this->queue ?? 'default';
	}

	/**
	 * Routes this job to a named queue.
	 *
	 * @param string $queue The queue name.
	 *
	 * @return static This job, for chaining.
	 */
	public function on_queue( string $queue ): static {
		$this->queue = $queue;

		return $this;
	}

	/**
	 * Returns the maximum number of attempts for this job.
	 *
	 * Override to change how many times this specific job is retried. Returning
	 * 0 (the default) inherits the manager wide setting.
	 *
	 * @return int The maximum attempts, or 0 to inherit the configured default.
	 */
	public function get_max_attempts(): int {
		return 0;
	}

	/**
	 * Returns the delay in seconds before the next retry.
	 *
	 * Override to implement a custom backoff. Returning a negative number (the
	 * default) inherits the manager wide backoff formula.
	 *
	 * @param int $attempt The attempt number that has just failed (1 based).
	 *
	 * @return int The seconds to wait, or a negative number to inherit the default.
	 */
	public function get_backoff( int $attempt ): int {
		return -1;
	}

	/**
	 * Returns the database identifier of this job, if persisted.
	 *
	 * @return int|null The row id, or null when not yet stored.
	 */
	public function get_id(): ?int {
		return $this->id;
	}

	/**
	 * Assigns the database identifier to this job.
	 *
	 * @param int $id The row id.
	 */
	public function set_id( int $id ): void {
		$this->id = $id;
	}

	/**
	 * Returns how many times this job has been attempted.
	 *
	 * @return int The attempt count.
	 */
	public function get_attempts(): int {
		return $this->attempts;
	}

	/**
	 * Sets how many times this job has been attempted.
	 *
	 * @param int $attempts The attempt count.
	 */
	public function set_attempts( int $attempts ): void {
		$this->attempts = $attempts;
	}
}
