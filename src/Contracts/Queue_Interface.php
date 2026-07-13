<?php
/**
 * Contract for the persistent job store.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Contracts;

use Throwable;

/**
 * Interface Queue_Interface.
 *
 * Abstracts where and how jobs are stored so the worker can be driven against a
 * real database or an in memory fake. The default implementation is
 * {@see \WPTechnix\WP_Background_Jobs\Queue}.
 */
interface Queue_Interface {

	/**
	 * Adds a job to the queue.
	 *
	 * @param Job_Interface $job   The job to enqueue.
	 * @param int           $delay Seconds to wait before the job becomes available.
	 *
	 * @return int|false The new job id, or false on failure.
	 */
	public function push( Job_Interface $job, int $delay = 0 ): int|false;

	/**
	 * Adds many jobs to the queue in a single insert.
	 *
	 * @param array<int, Job_Interface> $jobs  The jobs to enqueue.
	 * @param int                       $delay Seconds to wait before the jobs become available.
	 *
	 * @return int The number of jobs inserted.
	 */
	public function push_many( array $jobs, int $delay = 0 ): int;

	/**
	 * Atomically reserves and returns the next available job.
	 *
	 * @param string|null $queue The queue to pop from, or null for any queue.
	 *
	 * @return Job_Interface|null The reserved job, or null when none is available.
	 */
	public function pop( ?string $queue = null ): ?Job_Interface;

	/**
	 * Permanently removes a job from the queue.
	 *
	 * @param Job_Interface $job The job to delete.
	 *
	 * @return bool True on success.
	 */
	public function delete( Job_Interface $job ): bool;

	/**
	 * Returns a job to the queue for a later retry.
	 *
	 * @param Job_Interface $job   The job to release.
	 * @param int           $delay Seconds to wait before the job becomes available again.
	 *
	 * @return bool True on success.
	 */
	public function release( Job_Interface $job, int $delay = 0 ): bool;

	/**
	 * Moves a job to the failures table and removes it from the queue.
	 *
	 * @param Job_Interface $job       The failed job.
	 * @param Throwable     $exception The exception that caused the failure.
	 *
	 * @return bool True on success.
	 */
	public function fail( Job_Interface $job, Throwable $exception ): bool;

	/**
	 * Releases reservations that have exceeded the reserve timeout.
	 *
	 * A worker that dies mid-job leaves its reservation behind. This returns
	 * those stale reservations to the queue so the job can be retried, which is
	 * why a watchdog must be able to run it without first seeing available work.
	 *
	 * @param string|null $queue The queue to reclaim, or null for all queues.
	 *
	 * @return int The number of reservations reclaimed.
	 */
	public function reclaim( ?string $queue = null ): int;

	/**
	 * Counts the jobs currently waiting in the queue.
	 *
	 * @param string|null $queue The queue to count, or null for all queues.
	 *
	 * @return int The number of pending jobs.
	 */
	public function count( ?string $queue = null ): int;

	/**
	 * Counts the jobs that are ready to run right now.
	 *
	 * Excludes reserved jobs and jobs whose delay or backoff has not yet elapsed.
	 * This is the count that should drive scheduling decisions, so a worker is
	 * not started when there is nothing it can actually process.
	 *
	 * @param string|null $queue The queue to count, or null for all queues.
	 *
	 * @return int The number of jobs ready to run now.
	 */
	public function count_available( ?string $queue = null ): int;

	/**
	 * Determines whether the queue has no pending jobs.
	 *
	 * @param string|null $queue The queue to check, or null for all queues.
	 *
	 * @return bool True when the queue is empty.
	 */
	public function is_empty( ?string $queue = null ): bool;

	/**
	 * Deletes every pending job from the queue.
	 *
	 * @param string|null $queue The queue to purge, or null for all queues.
	 *
	 * @return int The number of jobs removed.
	 */
	public function purge( ?string $queue = null ): int;
}
