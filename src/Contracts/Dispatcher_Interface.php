<?php
/**
 * Contract for the strategy that triggers background processing.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Contracts;

/**
 * Interface Dispatcher_Interface.
 *
 * A dispatcher decides how the worker is started after a job is queued. The
 * library ships an asynchronous loopback dispatcher, a WP-Cron watchdog, and a
 * synchronous dispatcher for tests. Implement this interface to plug in a custom
 * transport such as a real queue service.
 */
interface Dispatcher_Interface {

	/**
	 * Registers any long lived triggers (hooks, cron events).
	 *
	 * Called once when the manager boots. Implementations should be idempotent.
	 */
	public function schedule(): void;

	/**
	 * Requests that the worker runs as soon as possible for the given queue.
	 *
	 * @param string $queue The queue that received new work.
	 */
	public function dispatch( string $queue ): void;
}
