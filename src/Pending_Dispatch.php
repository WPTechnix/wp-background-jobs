<?php
/**
 * Fluent helper for dispatching jobs to a specific queue.
 *
 * @package WPTechnix\WP_Background_Jobs
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs;

use DateTimeInterface;

/**
 * Class Pending_Dispatch.
 *
 * Returned by {@see Background_Jobs::on()} so a queue can be chosen at dispatch
 * time, for example `$jobs->on( 'emails' )->dispatch( $job )`.
 */
final class Pending_Dispatch {

	/**
	 * Pending_Dispatch constructor.
	 *
	 * @param Background_Jobs $manager The owning manager.
	 * @param string          $queue   The queue jobs will be routed to.
	 */
	public function __construct( private Background_Jobs $manager, private string $queue ) {
	}

	/**
	 * Dispatches a single job onto the selected queue.
	 *
	 * @param Job $job   The job to dispatch.
	 * @param int $delay Seconds to wait before the job becomes available.
	 *
	 * @return int|false The new job id, or false on failure.
	 */
	public function dispatch( Job $job, int $delay = 0 ): int|false {
		return $this->manager->dispatch( $job->on_queue( $this->queue ), $delay );
	}

	/**
	 * Dispatches many jobs onto the selected queue.
	 *
	 * @param array<int, Job> $jobs  The jobs to dispatch.
	 * @param int             $delay Seconds to wait before the jobs become available.
	 *
	 * @return int The number of jobs dispatched.
	 */
	public function dispatch_many( array $jobs, int $delay = 0 ): int {
		foreach ( $jobs as $job ) {
			$job->on_queue( $this->queue );
		}

		return $this->manager->dispatch_many( $jobs, $delay );
	}

	/**
	 * Dispatches a single job onto the selected queue at a specific time.
	 *
	 * @param Job               $job  The job to dispatch.
	 * @param DateTimeInterface $when When the job should become available.
	 *
	 * @return int|false The new job id, or false on failure.
	 */
	public function dispatch_at( Job $job, DateTimeInterface $when ): int|false {
		return $this->manager->dispatch_at( $job->on_queue( $this->queue ), $when );
	}

	/**
	 * Dispatches many jobs onto the selected queue at a specific time.
	 *
	 * @param array<int, Job>   $jobs The jobs to dispatch.
	 * @param DateTimeInterface $when When the jobs should become available.
	 *
	 * @return int The number of jobs dispatched.
	 */
	public function dispatch_many_at( array $jobs, DateTimeInterface $when ): int {
		foreach ( $jobs as $job ) {
			$job->on_queue( $this->queue );
		}

		return $this->manager->dispatch_many_at( $jobs, $when );
	}
}
