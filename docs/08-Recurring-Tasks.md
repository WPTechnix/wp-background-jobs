# Recurring Tasks

For one-off future work, use [`dispatch_at()`](03-Dispatching-Jobs.md). For work that repeats on a schedule, the manager includes a small scheduler built on WP-Cron.

The scheduler does not run the work itself; its callback fires on the schedule and normally **dispatches a job**, so the actual work runs through the durable queue with retries, backoff, the failures table, and lifecycle hooks. That is the difference from a raw `wp_schedule_event`, whose callback runs inline during the cron request with no retry.

## Register a recurring task

Call `recurring()` on every request, in the same place you call `boot()`. Registration is idempotent, so calling it each load simply keeps the schedule in place - exactly like registering any WordPress hook.

```php
add_action( 'plugins_loaded', static function () {
    $jobs = myplugin_jobs();
    $jobs->boot();

    $jobs->recurring( 'cleanup', HOUR_IN_SECONDS, static function () use ( $jobs ) {
        $jobs->dispatch( new Cleanup_Job() );
    } );
} );
```

- **`name`** - a unique identifier for the task (lowercase letters, numbers, and underscores). It becomes part of the cron hook name.
- **`interval`** - seconds between runs. Values below 60 are floored to 60. Use WordPress constants such as `HOUR_IN_SECONDS` and `DAY_IN_SECONDS` for readability.
- **`callback`** - any callable. It runs on each tick; dispatch one or more jobs from it, or do lightweight work directly.

Changing the interval for an existing name reschedules the event automatically on the next request.

## Stop a recurring task

```php
myplugin_jobs()->unschedule_recurring( 'cleanup' );
```

If you remove a `recurring()` call from your code, also call `unschedule_recurring()` once (for example during an upgrade routine) so the underlying cron event does not linger. Every recurring task registered by the manager is cleared automatically when you call `uninstall()`.

## How it runs

The task fires whenever WP-Cron runs - on site traffic, or from a system cron if you have disabled traffic-triggered cron (see [WP-CLI](06-WP-CLI.md)). Because the callback typically just enqueues a job, the heavy lifting happens in a separate worker process, and a failing job is retried and ultimately recorded in the failures table like any other. Missed runs are not backfilled; the next run happens on the following tick.
