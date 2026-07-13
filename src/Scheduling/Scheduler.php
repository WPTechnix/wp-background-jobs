<?php
/**
 * Registers recurring tasks on a WP-Cron schedule.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Scheduling;

use InvalidArgumentException;
use WPTechnix\WP_Background_Jobs\Support\Config;

/**
 * Class Scheduler.
 *
 * A thin, optional layer over WP-Cron for repeating work. Each recurring task is a
 * named callback that fires on a schedule; the callback typically enqueues a job so
 * the actual work runs through the durable queue with retries and failure handling,
 * rather than inline during the cron request.
 *
 * Registration is idempotent and, like any WordPress cron or hook wiring, must run
 * on every request (call {@see Scheduler::recurring()} from the same place you call
 * boot). The only persisted state is the WP-Cron event and a small registry option
 * used to clean up on uninstall.
 */
final class Scheduler {

	/**
	 * Practical lower bound for a recurring interval, in seconds.
	 */
	private const MIN_INTERVAL = 60;

	/**
	 * Manager configuration.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Intervals registered this request, keyed by cron hook name.
	 *
	 * @var array<string, int>
	 */
	private array $intervals = [];

	/**
	 * Whether the cron_schedules filter has been registered this request.
	 *
	 * @var bool
	 */
	private bool $schedules_hooked = false;

	/**
	 * Scheduler constructor.
	 *
	 * @param Config $config Manager configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Registers a recurring task, creating or updating its WP-Cron event.
	 *
	 * Call this on every request. Changing the interval for an existing name
	 * reschedules the event automatically.
	 *
	 * @param string   $name     Unique task name. Lowercase letters, numbers and underscores only.
	 * @param int      $interval Seconds between runs. Floored to {@see Scheduler::MIN_INTERVAL}.
	 * @param callable $callback Invoked on each run; usually dispatches a job.
	 *
	 * @throws InvalidArgumentException When the name is not a valid identifier.
	 */
	public function recurring( string $name, int $interval, callable $callback ): void {
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid task name "%s". Use lowercase letters, numbers and underscores only.', $name )
			);
		}

		$interval = max( self::MIN_INTERVAL, $interval );
		$hook     = $this->hook( $name );

		$this->intervals[ $hook ] = $interval;

		add_action( $hook, $callback );

		if ( ! $this->schedules_hooked ) {
			add_filter( 'cron_schedules', [ $this, 'register_schedules' ] );
			$this->schedules_hooked = true;
		}

		$this->ensure_event( $hook, $interval );
		$this->remember_hook( $hook );
	}

	/**
	 * Removes a recurring task and its WP-Cron event.
	 *
	 * @param string $name The task name to unschedule.
	 */
	public function unschedule_recurring( string $name ): void {
		$hook = $this->hook( $name );

		wp_clear_scheduled_hook( $hook );
		$this->forget_hook( $hook );
	}

	/**
	 * Removes every recurring task registered by this instance.
	 *
	 * Intended for use on plugin uninstall so no scheduled events are left behind.
	 */
	public function unschedule_all(): void {
		foreach ( $this->tracked_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		delete_option( $this->registry_option() );
	}

	/**
	 * Adds this instance's intervals to the list of cron schedules.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing cron schedules.
	 *
	 * @return array<string, array{interval: int, display: string}> The schedules including this instance's intervals.
	 */
	public function register_schedules( array $schedules ): array {
		foreach ( array_unique( array_values( $this->intervals ) ) as $interval ) {
			$schedules[ $this->schedule_name( $interval ) ] = [
				'interval' => $interval,
				'display'  => sprintf( 'Every %d seconds (%s)', $interval, $this->config->get_key() ),
			];
		}

		return $schedules;
	}

	/**
	 * Schedules the event, rescheduling when the interval has changed.
	 *
	 * @param string $hook     The cron hook name.
	 * @param int    $interval The interval in seconds.
	 */
	private function ensure_event( string $hook, int $interval ): void {
		$schedule = $this->schedule_name( $interval );
		$existing = wp_next_scheduled( $hook );

		if ( false !== $existing && wp_get_schedule( $hook ) === $schedule ) {
			return;
		}

		if ( false !== $existing ) {
			wp_clear_scheduled_hook( $hook );
		}

		wp_schedule_event( time() + $interval, $schedule, $hook );
	}

	/**
	 * Returns the cron hook name for a task.
	 *
	 * @param string $name The task name.
	 *
	 * @return string The hook name.
	 */
	private function hook( string $name ): string {
		return $this->config->get_key() . '_scheduled_' . $name;
	}

	/**
	 * Returns the custom cron schedule name for an interval.
	 *
	 * @param int $interval The interval in seconds.
	 *
	 * @return string The schedule name.
	 */
	private function schedule_name( int $interval ): string {
		return $this->config->get_key() . '_interval_' . $interval;
	}

	/**
	 * Returns the option name that tracks scheduled hooks for cleanup.
	 *
	 * @return string The option name.
	 */
	private function registry_option(): string {
		return $this->config->get_key() . '_scheduled_hooks';
	}

	/**
	 * Returns the tracked hook names from the registry option.
	 *
	 * @return list<string> The tracked hooks.
	 */
	private function tracked_hooks(): array {
		$hooks = get_option( $this->registry_option(), [] );

		return is_array( $hooks ) ? array_values( array_map( 'strval', $hooks ) ) : [];
	}

	/**
	 * Adds a hook to the registry option when it is not already tracked.
	 *
	 * @param string $hook The hook name.
	 */
	private function remember_hook( string $hook ): void {
		$hooks = $this->tracked_hooks();

		if ( in_array( $hook, $hooks, true ) ) {
			return;
		}

		$hooks[] = $hook;
		update_option( $this->registry_option(), $hooks );
	}

	/**
	 * Removes a hook from the registry option.
	 *
	 * @param string $hook The hook name.
	 */
	private function forget_hook( string $hook ): void {
		$hooks     = $this->tracked_hooks();
		$remaining = array_values( array_filter( $hooks, static fn ( $tracked ) => $tracked !== $hook ) );

		if ( $remaining === $hooks ) {
			return;
		}

		update_option( $this->registry_option(), $remaining );
	}
}
