# Configuration and Hooks

## Options

Pass an options array as the third argument to `Background_Jobs::create()`. Every option has a default, so pass only what you want to change.

```php
$jobs = Background_Jobs::create( $wpdb, 'myplugin', [
    'driver'          => 'async',
    'max_attempts'    => 3,
    'time_limit'      => 20,
    'memory_factor'   => 0.8,
    'reserve_timeout' => 300,
    'lock_time'       => 300,
    'cron_interval'   => 60,
    'allowed_job_classes' => true,
] );
```

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `driver` | `'async'` \| `'cron'` \| `'sync'` | `'async'` | How the worker is started. See [How Processing Works](04-How-Processing-Works.md). |
| `max_attempts` | int | `3` | Default attempts before a job is treated as failed. |
| `time_limit` | int (seconds) | `20` | How long a single worker run may spend processing. |
| `memory_factor` | float | `0.8` | Fraction of the PHP memory limit a worker may use before stopping. |
| `reserve_timeout` | int (seconds) | `300` | How long a reservation is held before a stalled job is reclaimed. Set this above your longest expected job runtime: a job that runs longer than the reservation can be reclaimed and started again in parallel. |
| `lock_time` | int (seconds) | `300` | How long the worker stampede lock is held. |
| `cron_interval` | int (seconds) | `60` | How often the WP-Cron watchdog runs. Floored to 30 seconds. |
| `allowed_job_classes` | `array` \| `bool` | `true` | Classes allowed when restoring a job from storage. See below. |

## Action hooks

Each hook name is prefixed with your manager key. The examples use the key `myplugin`.

| Hook | Arguments | Fires when |
| --- | --- | --- |
| `myplugin_job_processing` | `$job` | Just before a job runs. |
| `myplugin_job_processed` | `$job` | After a job succeeds and is deleted. |
| `myplugin_job_released` | `$job, $exception, $delay` | After a failed job is released for retry. |
| `myplugin_job_failed` | `$job, $exception` | After a job exhausts its attempts and is moved to the failures table. |
| `myplugin_queue_empty` | `$queue` | When a worker run finds no more work. |

```php
add_action( 'myplugin_job_processed', static function ( $job ) {
    // Record a metric, bust a cache, etc.
}, 10, 1 );
```

## Filters

| Filter | Value | Extra arguments |
| --- | --- | --- |
| `myplugin_max_attempts` | int | `$job` |
| `myplugin_backoff` | int (seconds) | `$attempt, $job` |
| `myplugin_time_limit` | int (seconds) | none |
| `myplugin_memory_factor` | float | none |
| `myplugin_cron_interval` | int (seconds) | none |
| `myplugin_loopback_sslverify` | bool | none |

```php
// Give the CLI and cron workers more time on a capable server.
add_filter( 'myplugin_time_limit', static fn () => 55 );
```

## Securing unserialization

Jobs are restored with PHP unserialization, guarded by an allowed classes list. The default, `true`, allows any class, which is convenient during development. For defense in depth, restrict it to your own job classes so a tampered row can never instantiate an unexpected object:

```php
Background_Jobs::create( $wpdb, 'myplugin', [
    'allowed_job_classes' => [
        Send_Welcome_Email::class,
        Sync_Order_To_Crm::class,
    ],
] );
```

Any payload that resolves to a class outside the list is moved to the failures table rather than executed.

## Advanced wiring

For custom transports, the building blocks are public. `get_queue()`, `get_worker()`, `get_config()`, and `get_scheduler()` expose the queue, the worker, the resolved configuration, and the recurring-task scheduler, and `Dispatcher_Interface` defines the contract for starting a worker. You can implement the interface and drive `$jobs->get_worker()->run()` from your own trigger (for example a message from an external queue service) instead of, or alongside, the built-in drivers.
