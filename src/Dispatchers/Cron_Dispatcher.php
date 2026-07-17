<?php
/**
 * Dispatcher that processes jobs on a recurring WP-Cron schedule.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Dispatchers;

use Override;
use WPTechnix\WP_Background_Jobs\Contracts\Dispatcher_Interface;
use WPTechnix\WP_Background_Jobs\Contracts\Queue_Interface;
use WPTechnix\WP_Background_Jobs\Support\Config;
use WPTechnix\WP_Background_Jobs\Support\Lock;
use WPTechnix\WP_Background_Jobs\Worker;

/**
 * Class Cron_Dispatcher.
 *
 * Acts as an always on safety net. Even when the asynchronous loopback request
 * is blocked by the host, a recurring WP-Cron event still drains the queue. It
 * also becomes the primary driver when the manager is configured for cron only
 * processing.
 */
final class Cron_Dispatcher implements Dispatcher_Interface {

	/**
	 * Practical lower bound for the cron interval, in seconds.
	 */
	private const MIN_INTERVAL = 30;

	/**
	 * The cron event hook name.
	 */
	private string $hook;

	/**
	 * The custom cron schedule name.
	 */
	private string $schedule_name;

	/**
	 * Cron_Dispatcher constructor.
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
		$this->hook          = $config->get_key() . '_cron_worker';
		$this->schedule_name = $config->get_key() . '_cron_interval';
	}

	/** @inheritDoc */
	#[Override]
	public function schedule(): void {
		add_filter( 'cron_schedules', [ $this, 'register_schedule' ] );
		add_action( $this->hook, [ $this, 'run_worker' ] );
		$this->ensure_event();
	}

	/** @inheritDoc */
	#[Override]
	public function dispatch( string $queue ): void {
		unset( $queue );
		$this->ensure_event();
	}

	/**
	 * Registers the custom cron schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing cron schedules.
	 *
	 * @return array<string, array{interval: int, display: string}> The schedules including this instance's interval.
	 */
	public function register_schedule( array $schedules ): array {
		$interval = $this->interval();

		$schedules[ $this->schedule_name ] = [
			'interval' => $interval,
			'display'  => sprintf( 'Every %d seconds (%s)', $interval, $this->config->get_key() ),
		];

		return $schedules;
	}

	/**
	 * Runs the worker when there is pending work and no run is in progress.
	 *
	 * Reclaims stale reservations first so a job stranded by a crashed worker is
	 * retried even when it is the only row left in the queue. Without this the
	 * loopback kick never fires (there is no available work to detect) and the
	 * job would sit reserved forever.
	 */
	public function run_worker(): void {
		$this->queue->reclaim( null );

		if ( 0 === $this->queue->count_available( null ) ) {
			return;
		}

		if ( ! $this->lock->acquire() ) {
			return;
		}

		try {
			$this->worker->run( null );
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * Schedules the recurring event when it is not already scheduled.
	 */
	private function ensure_event(): void {
		if ( false !== wp_next_scheduled( $this->hook ) ) {
			return;
		}

		wp_schedule_event( time() + $this->interval(), $this->schedule_name, $this->hook );
	}

	/**
	 * Resolves the effective cron interval in seconds.
	 *
	 * @return int The interval, never below {@see Cron_Dispatcher::MIN_INTERVAL}.
	 */
	private function interval(): int {
		$interval = (int) apply_filters( "{$this->config->get_key()}_cron_interval", $this->config->get_cron_interval() );

		return max( self::MIN_INTERVAL, $interval );
	}
}
