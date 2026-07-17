<?php
/**
 * Base class for background jobs.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs;

use Override;
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
	 */
	protected ?int $id = null;

	/**
	 * How many times this job has been attempted.
	 */
	protected int $attempts = 0;

	/**
	 * The queue this job is routed to, when set explicitly.
	 */
	protected ?string $queue = null;

	/** @inheritDoc */
	#[Override]
	abstract public function handle(): void;

	/** @inheritDoc */
	#[Override]
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

	/** @inheritDoc */
	#[Override]
	public function get_max_attempts(): int {
		return 0;
	}

	/** @inheritDoc */
	#[Override]
	public function get_backoff( int $attempt ): int {
		return -1;
	}

	/** @inheritDoc */
	#[Override]
	public function get_id(): ?int {
		return $this->id;
	}

	/** @inheritDoc */
	#[Override]
	public function set_id( int $id ): void {
		$this->id = $id;
	}

	/** @inheritDoc */
	#[Override]
	public function get_attempts(): int {
		return $this->attempts;
	}

	/** @inheritDoc */
	#[Override]
	public function set_attempts( int $attempts ): void {
		$this->attempts = $attempts;
	}
}
