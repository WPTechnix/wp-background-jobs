<?php
/**
 * Contract implemented by every queued job.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Contracts;

/**
 * Interface Job_Interface.
 *
 * A job is a serializable unit of work. The only method a concrete job must
 * implement is {@see Job_Interface::handle()}; the rest have sensible defaults
 * on the {@see \WPTechnix\WP_Background_Jobs\Job} base class and may be overridden.
 */
interface Job_Interface {

	/**
	 * Performs the work for this job.
	 *
	 * Throwing any {@see \Throwable} marks the attempt as failed, which triggers a
	 * retry with backoff until the maximum number of attempts is reached.
	 */
	public function handle(): void;

	/**
	 * Returns the name of the queue this job belongs to.
	 *
	 * @return string The queue name.
	 */
	public function get_queue(): string;

	/**
	 * Returns the maximum number of attempts for this job.
	 *
	 * A value of 0 means the manager wide default should be used.
	 *
	 * @return int The maximum attempts, or 0 to inherit the configured default.
	 */
	public function get_max_attempts(): int;

	/**
	 * Returns the delay in seconds before the next retry of a failed attempt.
	 *
	 * @param int $attempt The attempt number that has just failed (1 based).
	 *
	 * @return int The number of seconds to wait before retrying.
	 */
	public function get_backoff( int $attempt ): int;

	/**
	 * Returns the database identifier of this job, if it has been persisted.
	 *
	 * @return int|null The row id, or null when the job is not yet stored.
	 */
	public function get_id(): ?int;

	/**
	 * Assigns the database identifier to this job.
	 *
	 * @param int $id The row id.
	 */
	public function set_id( int $id ): void;

	/**
	 * Returns how many times this job has been attempted.
	 *
	 * @return int The attempt count.
	 */
	public function get_attempts(): int;

	/**
	 * Sets how many times this job has been attempted.
	 *
	 * @param int $attempts The attempt count.
	 */
	public function set_attempts( int $attempts ): void;
}
