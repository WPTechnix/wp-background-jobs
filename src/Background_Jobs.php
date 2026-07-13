<?php
/**
 * Entry point and facade for the background jobs library.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs;

use DateTimeInterface;
use stdClass;
use wpdb;
use WPTechnix\WP_Background_Jobs\CLI\Worker_Command;
use WPTechnix\WP_Background_Jobs\Contracts\Dispatcher_Interface;
use WPTechnix\WP_Background_Jobs\Contracts\Job_Interface;
use WPTechnix\WP_Background_Jobs\Dispatchers\Async_Dispatcher;
use WPTechnix\WP_Background_Jobs\Dispatchers\Cron_Dispatcher;
use WPTechnix\WP_Background_Jobs\Dispatchers\Sync_Dispatcher;
use WPTechnix\WP_Background_Jobs\Schema\Installer;
use WPTechnix\WP_Background_Jobs\Scheduling\Scheduler;
use WPTechnix\WP_Background_Jobs\Support\Config;
use WPTechnix\WP_Background_Jobs\Support\Lock;
use WPTechnix\WP_Background_Jobs\Support\Serializer;

/**
 * Class Background_Jobs.
 *
 * Wires together the queue, worker, installer, and dispatchers for one instance.
 * Create one per plugin with a unique key, call {@see Background_Jobs::install()}
 * on activation and {@see Background_Jobs::boot()} on every request, then
 * dispatch jobs with {@see Background_Jobs::dispatch()}.
 *
 * @phpstan-import-type Config_Options from Config
 */
final class Background_Jobs {

	/**
	 * Manager configuration.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * The database backed queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * The job worker.
	 *
	 * @var Worker
	 */
	private Worker $worker;

	/**
	 * The schema installer.
	 *
	 * @var Installer
	 */
	private Installer $installer;

	/**
	 * Active dispatchers.
	 *
	 * @var array<int, Dispatcher_Interface>
	 */
	private array $dispatchers;

	/**
	 * The recurring task scheduler.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Whether {@see Background_Jobs::boot()} has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Background_Jobs constructor.
	 *
	 * @param Config                           $config      Manager configuration.
	 * @param Queue                            $queue       The database backed queue.
	 * @param Worker                           $worker      The job worker.
	 * @param Installer                        $installer   The schema installer.
	 * @param array<int, Dispatcher_Interface> $dispatchers Active dispatchers.
	 * @param Scheduler                        $scheduler   The recurring task scheduler.
	 */
	private function __construct(
		Config $config,
		Queue $queue,
		Worker $worker,
		Installer $installer,
		array $dispatchers,
		Scheduler $scheduler
	) {
		$this->config      = $config;
		$this->queue       = $queue;
		$this->worker      = $worker;
		$this->installer   = $installer;
		$this->dispatchers = $dispatchers;
		$this->scheduler   = $scheduler;
	}

	/**
	 * Creates a manager instance.
	 *
	 * @param wpdb   $wpdb    WordPress database object.
	 * @param string $key     Unique instance key. Lowercase letters, numbers and underscores only.
	 * @param array  $options Optional configuration overrides.
	 *
	 * @phpstan-param Config_Options&array{driver?: 'async'|'cron'|'sync'} $options
	 *
	 * @return self The configured manager.
	 */
	public static function create( wpdb $wpdb, string $key, array $options = [] ): self {
		$config     = Config::create( $key, $wpdb->prefix, $options );
		$serializer = new Serializer( $config->get_allowed_job_classes() );

		$queue = new Queue(
			$wpdb,
			$config->get_jobs_table(),
			$config->get_failures_table(),
			$serializer,
			$config->get_reserve_timeout()
		);

		$worker    = new Worker( $queue, $config );
		$installer = new Installer(
			$wpdb,
			$config->get_jobs_table(),
			$config->get_failures_table(),
			$config->get_version_option()
		);

		$driver      = is_string( $options['driver'] ?? null ) ? $options['driver'] : 'async';
		$dispatchers = self::build_dispatchers( $driver, $worker, $queue, $config );
		$scheduler   = new Scheduler( $config );

		return new self( $config, $queue, $worker, $installer, $dispatchers, $scheduler );
	}

	/**
	 * Creates or upgrades the database tables. Call on plugin activation.
	 */
	public function install(): void {
		$this->installer->install();
	}

	/**
	 * Drops the database tables and removes background triggers.
	 *
	 * Call on plugin uninstall. This drops the tables, unschedules the cron
	 * watchdog and every recurring task, and clears the worker lock so nothing is
	 * left behind.
	 */
	public function uninstall(): void {
		$this->installer->uninstall();
		$this->scheduler->unschedule_all();

		$key = $this->config->get_key();
		wp_clear_scheduled_hook( $key . '_cron_worker' );
		delete_transient( $key . '_worker_lock' );
	}

	/**
	 * Registers hooks, cron events, and CLI commands. Call on every request.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		foreach ( $this->dispatchers as $dispatcher ) {
			$dispatcher->schedule();
		}

		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		Worker_Command::register( $this->config->get_key(), $this->worker, $this->queue );
	}

	/**
	 * Queues a job and requests processing.
	 *
	 * @param Job_Interface $job   The job to dispatch.
	 * @param int           $delay Seconds to wait before the job becomes available.
	 *
	 * @return int|false The new job id, or false on failure.
	 */
	public function dispatch( Job_Interface $job, int $delay = 0 ): int|false {
		$id = $this->queue->push( $job, $delay );

		if ( false === $id ) {
			return false;
		}

		$this->notify_dispatchers( $job->get_queue() );

		return $id;
	}

	/**
	 * Queues many jobs in a single insert and requests processing.
	 *
	 * @param array<int, Job_Interface> $jobs  The jobs to dispatch.
	 * @param int                       $delay Seconds to wait before the jobs become available.
	 *
	 * @return int The number of jobs dispatched.
	 */
	public function dispatch_many( array $jobs, int $delay = 0 ): int {
		$count = $this->queue->push_many( $jobs, $delay );

		if ( $count > 0 ) {
			$this->notify_dispatchers( '' );
		}

		return $count;
	}

	/**
	 * Queues a job to become available at a specific date and time.
	 *
	 * The instant is absolute: `$when->getTimestamp()` is used, so any timezone on
	 * the object is respected and a time in the past runs as soon as possible.
	 *
	 * @param Job_Interface     $job  The job to dispatch.
	 * @param DateTimeInterface $when When the job should become available.
	 *
	 * @return int|false The new job id, or false on failure.
	 */
	public function dispatch_at( Job_Interface $job, DateTimeInterface $when ): int|false {
		return $this->dispatch( $job, $this->to_delay( $when ) );
	}

	/**
	 * Queues many jobs to become available at a specific date and time.
	 *
	 * @param array<int, Job_Interface> $jobs The jobs to dispatch.
	 * @param DateTimeInterface         $when When the jobs should become available.
	 *
	 * @return int The number of jobs dispatched.
	 */
	public function dispatch_many_at( array $jobs, DateTimeInterface $when ): int {
		return $this->dispatch_many( $jobs, $this->to_delay( $when ) );
	}

	/**
	 * Registers a recurring task that runs on a WP-Cron schedule.
	 *
	 * Call this on every request, alongside {@see Background_Jobs::boot()}. The
	 * callback fires on each run and usually dispatches a job, so the work runs
	 * through the queue with retries and failure handling. Changing the interval
	 * for an existing name reschedules it automatically.
	 *
	 * @param string   $name     Unique task name. Lowercase letters, numbers and underscores only.
	 * @param int      $interval Seconds between runs (floored to 60).
	 * @param callable $callback Invoked on each run; usually dispatches a job.
	 */
	public function recurring( string $name, int $interval, callable $callback ): void {
		$this->scheduler->recurring( $name, $interval, $callback );
	}

	/**
	 * Removes a recurring task previously registered with the same name.
	 *
	 * @param string $name The task name to unschedule.
	 */
	public function unschedule_recurring( string $name ): void {
		$this->scheduler->unschedule_recurring( $name );
	}

	/**
	 * Begins a dispatch chain targeting a specific queue.
	 *
	 * @param string $queue The queue to dispatch onto.
	 *
	 * @return Pending_Dispatch The fluent dispatch helper.
	 */
	public function on( string $queue ): Pending_Dispatch {
		return new Pending_Dispatch( $this, $queue );
	}

	/**
	 * Returns the underlying queue for advanced use.
	 *
	 * @return Queue The queue.
	 */
	public function get_queue(): Queue {
		return $this->queue;
	}

	/**
	 * Returns the worker for advanced use.
	 *
	 * @return Worker The worker.
	 */
	public function get_worker(): Worker {
		return $this->worker;
	}

	/**
	 * Returns the resolved configuration.
	 *
	 * @return Config The configuration.
	 */
	public function get_config(): Config {
		return $this->config;
	}

	/**
	 * Returns the recurring task scheduler for advanced use.
	 *
	 * @return Scheduler The scheduler.
	 */
	public function get_scheduler(): Scheduler {
		return $this->scheduler;
	}

	/**
	 * Re-queues failed jobs so they are attempted again with a fresh budget.
	 *
	 * Requests processing for the recovered jobs, just like a normal dispatch.
	 *
	 * @param string|null $queue The queue to retry, or null for all queues.
	 *
	 * @return int The number of jobs moved back to the queue.
	 */
	public function retry_failed( ?string $queue = null ): int {
		$count = $this->queue->retry_failed( $queue );

		if ( $count > 0 ) {
			$this->notify_dispatchers( $queue ?? '' );
		}

		return $count;
	}

	/**
	 * Deletes rows from the failures table.
	 *
	 * @param string|null $queue The queue to clear, or null for all queues.
	 *
	 * @return int The number of failed jobs removed.
	 */
	public function purge_failed( ?string $queue = null ): int {
		return $this->queue->purge_failed( $queue );
	}

	/**
	 * Lists recorded failures, newest first.
	 *
	 * @param string|null $queue  The queue to list, or null for all queues.
	 * @param int         $limit  Maximum rows to return.
	 * @param int         $offset Rows to skip for pagination.
	 *
	 * @return array<int, stdClass> The failure rows.
	 */
	public function list_failed( ?string $queue = null, int $limit = 50, int $offset = 0 ): array {
		return $this->queue->list_failed( $queue, $limit, $offset );
	}

	/**
	 * Counts the jobs currently waiting in the queue.
	 *
	 * @param string|null $queue The queue to count, or null for all queues.
	 *
	 * @return int The number of pending jobs.
	 */
	public function count_pending( ?string $queue = null ): int {
		return $this->queue->count( $queue );
	}

	/**
	 * Counts the jobs recorded in the failures table.
	 *
	 * @return int The number of failed jobs.
	 */
	public function count_failed(): int {
		return $this->queue->count_failed();
	}

	/**
	 * Determines whether the queue has no pending jobs.
	 *
	 * @param string|null $queue The queue to check, or null for all queues.
	 *
	 * @return bool True when the queue is empty.
	 */
	public function is_empty( ?string $queue = null ): bool {
		return $this->queue->is_empty( $queue );
	}

	/**
	 * Deletes pending jobs from the queue.
	 *
	 * @param string|null $queue The queue to purge, or null for all queues.
	 *
	 * @return int The number of pending jobs removed.
	 */
	public function purge_pending( ?string $queue = null ): int {
		return $this->queue->purge( $queue );
	}

	/**
	 * Converts an absolute instant into a non-negative delay in seconds.
	 *
	 * @param DateTimeInterface $when The target instant.
	 *
	 * @return int The seconds from now until $when, or 0 when it is in the past.
	 */
	private function to_delay( DateTimeInterface $when ): int {
		return max( 0, $when->getTimestamp() - time() );
	}

	/**
	 * Builds the dispatchers for the selected driver.
	 *
	 * @param string $driver The driver name: async, cron, or sync.
	 * @param Worker $worker The job worker.
	 * @param Queue  $queue  The queue.
	 * @param Config $config Manager configuration.
	 *
	 * @return array<int, Dispatcher_Interface> The dispatchers.
	 */
	private static function build_dispatchers( string $driver, Worker $worker, Queue $queue, Config $config ): array {
		if ( 'sync' === $driver ) {
			return [ new Sync_Dispatcher( $worker ) ];
		}

		$lock = new Lock( $config->get_key() . '_worker_lock', $config->get_lock_time() );

		if ( 'cron' === $driver ) {
			return [ new Cron_Dispatcher( $worker, $queue, $config, $lock ) ];
		}

		return [
			new Async_Dispatcher( $worker, $queue, $config, $lock ),
			new Cron_Dispatcher( $worker, $queue, $config, $lock ),
		];
	}

	/**
	 * Notifies every active dispatcher that new work is available.
	 *
	 * @param string $queue The queue that received work.
	 */
	private function notify_dispatchers( string $queue ): void {
		foreach ( $this->dispatchers as $dispatcher ) {
			$dispatcher->dispatch( $queue );
		}
	}
}
