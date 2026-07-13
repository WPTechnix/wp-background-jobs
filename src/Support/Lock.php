<?php
/**
 * Best effort worker lock backed by a transient.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Support;

/**
 * Class Lock.
 *
 * Prevents redundant overlapping worker runs. This is an optimisation, not a
 * correctness guarantee: the queue's per job compare-and-swap reservation is
 * what actually prevents a job from being processed twice, so a lost race here
 * is harmless.
 *
 * The lock is a blog-local transient, matching the per-blog job tables. On
 * multisite this keeps each site's workers independent instead of serialising
 * every blog that shares an instance key behind one network-wide lock.
 */
final class Lock {

	/**
	 * The transient name backing this lock.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * How long, in seconds, the lock is held before it auto expires.
	 *
	 * @var int
	 */
	private int $ttl;

	/**
	 * Lock constructor.
	 *
	 * @param string $name The transient name backing this lock.
	 * @param int    $ttl  Seconds before the lock auto expires.
	 */
	public function __construct( string $name, int $ttl ) {
		$this->name = $name;
		$this->ttl  = max( 1, $ttl );
	}

	/**
	 * Acquires the lock when it is free.
	 *
	 * @return bool True when the lock was acquired by this call.
	 */
	public function acquire(): bool {
		if ( $this->is_locked() ) {
			return false;
		}

		set_transient( $this->name, time(), $this->ttl );

		return true;
	}

	/**
	 * Releases the lock.
	 */
	public function release(): void {
		delete_transient( $this->name );
	}

	/**
	 * Determines whether the lock is currently held.
	 *
	 * @return bool True when the lock is held.
	 */
	public function is_locked(): bool {
		return false !== get_transient( $this->name );
	}
}
