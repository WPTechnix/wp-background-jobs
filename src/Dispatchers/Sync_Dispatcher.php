<?php
/**
 * Dispatcher that runs jobs inline.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Dispatchers;

use WPTechnix\WP_Background_Jobs\Contracts\Dispatcher_Interface;
use WPTechnix\WP_Background_Jobs\Worker;

/**
 * Class Sync_Dispatcher.
 *
 * Processes jobs immediately in the same request. Intended for local
 * development and automated tests where deterministic, synchronous behaviour is
 * easier to reason about than background execution.
 */
final class Sync_Dispatcher implements Dispatcher_Interface {

	/**
	 * Sync_Dispatcher constructor.
	 *
	 * @param Worker $worker The worker used to process jobs.
	 */
	public function __construct( private Worker $worker ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): void {
		// Nothing to schedule: the synchronous dispatcher runs jobs inline.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $queue The queue that received new work.
	 */
	public function dispatch( string $queue ): void {
		$this->worker->run( '' === $queue ? null : $queue );
	}
}
