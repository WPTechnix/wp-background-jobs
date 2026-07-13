# Retries and Failures

## How a failure is handled

If `handle()` throws anything (any `Throwable`, including a `TypeError` or other `Error`), the worker catches it and decides what to do based on the job's attempt count:

- If the job still has attempts left, it is **released** back to the queue with a backoff delay. It becomes available again after that delay.
- If the job has reached its maximum attempts, it is **moved to the failures table** and removed from the queue.

Because one bad job is caught rather than allowed to fatal the process, it can never stop the rest of the queue from draining.

## Attempts

Every job has a maximum attempt count:

- Default: **3** attempts (configurable per instance via the `max_attempts` option).
- Per job: override `get_max_attempts()`, or return `0` to inherit the instance default.
- Per request: the `{key}_max_attempts` filter.

Attempts are counted when a job is claimed, so a job that crashes the whole PHP process still consumes an attempt. This prevents a "poison" job from being retried forever.

## Backoff

Backoff is the delay before a released job runs again. The default formula is exponential and capped at one hour: 60s, 120s, 240s, and so on.

- Per job: override `get_backoff( int $attempt )`, or return a negative number to inherit the default.
- Per request: the `{key}_backoff` filter.

```php
public function get_backoff( int $attempt ): int {
    return 30 * $attempt; // 30s, 60s, 90s, ...
}
```

## The failures table

When a job exhausts its attempts, a row is written to `{prefix}{key}_job_failures` containing the queue name, the serialized job, the formatted exception (class, message, code, and stack trace), and the time it failed. The job is then removed from the active queue.

A payload that can no longer be resolved to a valid job (for example because its class was removed, or it was excluded from the allowed classes list) is also moved here, so nothing is silently dropped.

## Managing failed jobs

Once the cause of a failure is fixed (a remote service is back, a bug is patched), you can move failed jobs back onto the queue. Retrying resets the attempt budget, so a retried job gets its full `max_attempts` again and becomes available immediately:

```php
$jobs = myplugin_jobs();

$jobs->retry_failed();            // re-queue every failure
$jobs->retry_failed( 'emails' );  // only the "emails" queue

$jobs->list_failed();             // inspect failures (newest first)
$jobs->count_failed();            // how many are recorded

$jobs->purge_failed();            // discard failures without retrying
$jobs->purge_failed( 'emails' );  // only the "emails" queue
```

`retry_failed()` requests processing for the recovered jobs, just like a normal dispatch, and only moves failures that already existed when it started, so records added concurrently are never lost. The same operations are available from [WP-CLI](06-WP-CLI.md).

## Observing failures

Attach to the failure hook to log or alert. There are no runtime dependencies, so you bring your own logger:

```php
add_action( 'myplugin_job_failed', static function ( $job, $exception ) {
    error_log( 'Job failed: ' . $exception->getMessage() );
}, 10, 2 );
```

See [Configuration and Hooks](07-Configuration-and-Hooks.md) for the full list of lifecycle hooks.

## Checking counts

From PHP, the manager exposes symmetric helpers for pending and failed work:

```php
$jobs = myplugin_jobs();

$jobs->count_pending();   // jobs waiting in the queue
$jobs->count_failed();    // jobs in the failures table
$jobs->is_empty();        // true when nothing is pending

$jobs->count_pending( 'emails' ); // scoped to a named queue
```

`count_pending()` and `is_empty()` count every row still in the queue, which
includes reserved (in-flight) jobs and delayed or backoff jobs that are not yet due.
They answer "is there anything in the queue", not "is there work to run this second".

Or use WP-CLI:

```bash
wp myplugin status
```

See [WP-CLI](06-WP-CLI.md).
