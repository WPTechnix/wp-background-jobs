<?php
/**
 * Dispatcher that starts the worker via a non-blocking loopback request.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Dispatchers;

use WPTechnix\WP_Background_Jobs\Contracts\Dispatcher_Interface;
use WPTechnix\WP_Background_Jobs\Contracts\Queue_Interface;
use WPTechnix\WP_Background_Jobs\Support\Config;
use WPTechnix\WP_Background_Jobs\Support\Lock;
use WPTechnix\WP_Background_Jobs\Worker;

/**
 * Class Async_Dispatcher.
 *
 * After work is queued this dispatcher fires a single non-blocking request to
 * `admin-ajax.php`, which starts the worker in a separate PHP process so the
 * originating request is never blocked. Dispatching many jobs in one request
 * still results in exactly one loopback request thanks to the shutdown hook
 * debounce.
 */
final class Async_Dispatcher implements Dispatcher_Interface {

	/**
	 * Whether the shutdown kick has already been registered this request.
	 *
	 * @var bool
	 */
	private bool $kick_registered = false;

	/**
	 * Async_Dispatcher constructor.
	 *
	 * @param Worker          $worker The worker used to process jobs.
	 * @param Queue_Interface $queue  The queue, used to detect remaining work.
	 * @param Config          $config Manager configuration.
	 * @param Lock            $lock   Best effort worker lock.
	 */
	public function __construct(
		private Worker $worker,
		private Queue_Interface $queue,
		private Config $config,
		private Lock $lock
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): void {
		$action = $this->get_action();
		add_action( 'wp_ajax_' . $action, [ $this, 'handle_request' ] );
		add_action( 'wp_ajax_nopriv_' . $action, [ $this, 'handle_request' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $queue The queue that received new work.
	 */
	public function dispatch( string $queue ): void {
		unset( $queue );

		if ( $this->kick_registered ) {
			return;
		}

		$this->kick_registered = true;
		add_action( 'shutdown', [ $this, 'send_kick' ], PHP_INT_MAX );
	}

	/**
	 * Sends the non-blocking loopback request that starts the worker.
	 *
	 * Registered on `shutdown` so it runs once, after the response is sent.
	 */
	public function send_kick(): void {
		if ( $this->lock->is_locked() ) {
			return;
		}

		// Only start a worker when there is work ready to run now. Delayed and
		// backoff jobs are left to the cron watchdog, which avoids a loop of
		// no-op loopback requests while the queue holds only future-dated jobs.
		if ( 0 === $this->queue->count_available( null ) ) {
			return;
		}

		$action = $this->get_action();
		$url    = add_query_arg(
			[
				'action' => $action,
				'nonce'  => wp_create_nonce( $action ),
			],
			admin_url( 'admin-ajax.php' )
		);

		$args = [
			'timeout'   => 0.01,
			'blocking'  => false,
			'body'      => [],
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			'cookies'   => $_COOKIE,
			'sslverify' => (bool) apply_filters( "{$this->config->get_key()}_loopback_sslverify", false ),
		];

		wp_remote_post( esc_url_raw( $url ), $args );
	}

	/**
	 * Handles the loopback request and drains the queue.
	 */
	public function handle_request(): void {
		check_ajax_referer( $this->get_action(), 'nonce' );

		if ( function_exists( 'session_write_close' ) ) {
			session_write_close();
		}

		if ( $this->lock->acquire() ) {
			$processed = 0;

			try {
				$processed = $this->worker->run( null );
			} finally {
				$this->lock->release();
			}

			// Chain another run only when this run made progress and jobs remain
			// that are ready now. Gating on progress means a run that processed
			// nothing (for example a memory baseline above the worker's ceiling)
			// never re-fires the loopback, which would otherwise spin with no
			// progress; the cron watchdog stays the safety net for that case. A
			// queue of purely delayed or backoff jobs is likewise left alone.
			if ( $processed > 0 && $this->queue->count_available( null ) > 0 ) {
				$this->dispatch( '' );
			}
		}

		wp_die( '', '', [ 'response' => 200 ] );
	}

	/**
	 * Returns the admin-ajax action name for this instance.
	 *
	 * @return string The action name.
	 */
	private function get_action(): string {
		return $this->config->get_key() . '_run_worker';
	}
}
