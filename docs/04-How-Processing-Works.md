# How Processing Works

## The life of a job

1. `dispatch()` inserts a row into the jobs table with an `available_at` timestamp (now, or now plus the delay).
2. Once per request, the manager fires a single non-blocking request to `admin-ajax.php`. This starts a worker in a separate PHP process and does not block the request that dispatched the job.
3. The worker reserves and runs jobs one at a time until the queue is empty or it reaches its time or memory budget.
4. When the budget is reached with work remaining, the worker starts a fresh process to continue. Work therefore spans many short requests, which keeps each one well under typical host timeouts.

## Reserving a job safely

The worker claims a job with a compare-and-swap update:

1. Reclaim any reservation older than the reserve timeout (a worker that died mid-job).
2. Select the id of the oldest available, unreserved job.
3. Run `UPDATE ... SET reserved_at = now, attempts = attempts + 1 WHERE id = ? AND reserved_at IS NULL` and only take the job if exactly one row changed.

Because the conditional update is atomic, two workers racing for the same job cannot both win. This holds on every MySQL and MariaDB version, with no dependence on `SELECT ... FOR UPDATE SKIP LOCKED`. Attempts are incremented at claim time, so even a job that fatally crashes the process still counts as an attempt and cannot loop forever.

## The three drivers

The `driver` option selects how work is started. The default is `async`.

### async (default)

Registers both the asynchronous loopback dispatcher and the WP-Cron watchdog.

- The **loopback dispatcher** fires one non-blocking request per originating request, no matter how many jobs were dispatched, using a shutdown hook to debounce.
- The **cron watchdog** runs on a schedule as an always-on safety net. If the loopback request is blocked by the host (a staging gate, a firewall, a self-signed certificate), the watchdog still drains the queue within the cron interval.

The loopback kick only fires when there is work ready to run right now. Delayed jobs and jobs waiting out a retry backoff are deliberately left to the cron watchdog, so the queue never spins up a stream of loopback requests that would have nothing to do. The practical effect is that a job which is not yet due starts within one cron interval of becoming due, rather than instantly.

### cron

Registers only the WP-Cron watchdog. Choose this when loopback requests are unavailable or undesirable. Latency is bounded by the cron interval, and processing depends on site traffic or a real system cron triggering WP-Cron.

```php
Background_Jobs::create( $wpdb, 'myplugin', [ 'driver' => 'cron' ] );
```

### sync

Runs each job inline, in the same request, at dispatch time. Intended for local development and tests where deterministic behaviour is easier to reason about.

```php
Background_Jobs::create( $wpdb, 'myplugin', [ 'driver' => 'sync' ] );
```

## Time and memory budgets

Each worker run stops when either budget is reached, then hands off to a new process if work remains:

- **Time budget**: 20 seconds by default, chosen to stay under the common 30 second host limit.
- **Memory budget**: 80 percent of the PHP memory limit by default.

Both are configurable per instance and per request. See [Configuration and Hooks](07-Configuration-and-Hooks.md).

## Locking

A short-lived blog-local transient lock prevents redundant overlapping worker runs. This is an optimisation, not the correctness guarantee: the per-job compare-and-swap reservation is what actually prevents double processing, so a lost lock race is harmless. On multisite the lock is per-blog, matching the per-blog job tables, so two blogs sharing an instance key never serialize each other's workers.

## Scaling up

For large or steady backlogs, run the WP-CLI worker from a system cron or a process manager rather than relying on loopback and WP-Cron. See [WP-CLI](06-WP-CLI.md).
