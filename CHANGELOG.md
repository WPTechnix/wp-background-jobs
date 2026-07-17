# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Docblock hygiene.** Stripped redundant `@var` annotations from natively-typed
  properties. Normalised `{@inheritDoc}` usage across all interface implementations
  and replaced docblocks that merely duplicated an inherited contract with
  `@inheritDoc`. Added missing `@inheritDoc` to test-double methods.
- **Override attribute.** Annotated every interface implementation and abstract
  override with `#[Override]`. The attribute is silently ignored below PHP 8.3
  and becomes a compile-time check when the engine supports it.

## [1.0.0] - 2026-07-12

Initial release: a zero-dependency, database-backed background job queue for
WordPress plugins.

### Added

- **Jobs.** Define background work as a class that extends `Job` and implements
  `handle()`. Public, protected, and private properties form the serialized
  payload, so keep them to plain, serializable data (closures and resources cannot
  be queued). The persisted id and attempt count are restored from dedicated columns
  after unserialization and are never trusted from the payload.
- **Dispatching.** `dispatch()` for a single job and `dispatch_many()` for a chunked
  bulk insert, plus `dispatch_at()` and `dispatch_many_at()` for absolute
  `DateTimeInterface` scheduling. Any dispatch accepts a per-second delay.
- **Named queues.** Route a job per class by overriding `get_queue()`, per instance
  with `on_queue()`, or per dispatch with the fluent
  `$jobs->on( 'emails' )->dispatch( $job )` chain. One worker drains every queue.
- **Three processing drivers.** `async` (default): one non-blocking loopback request
  per originating request plus a WP-Cron watchdog safety net. `cron`: the watchdog
  only. `sync`: inline execution for tests and local development.
- **Atomic reservation.** A compare-and-swap claim reserves each job, preventing
  double processing under concurrency on every MySQL and MariaDB version with no
  dependence on `SELECT ... FOR UPDATE SKIP LOCKED`. Attempts are counted at claim
  time, so a job that fatally crashes the PHP process still consumes an attempt.
- **Retries and failures.** Automatic retry on any `Throwable` with a configurable
  maximum attempt count (default 3) and exponential backoff (60s, 120s, 240s, up to
  a one-hour cap), both overridable per job and via filters. Exhausted jobs are moved
  to a dedicated failures table with the full exception trace. Manage them with
  `retry_failed()`, `purge_failed()`, `list_failed()`, and `count_failed()`.
- **Queue inspection.** `count_pending()`, `is_empty()`, and `purge_pending()` mirror
  the failed-job helpers. `count_pending()` and `is_empty()` include reserved
  (in-flight) and delayed rows; `Queue::count_available()` is the "runnable right
  now" count that drives scheduling.
- **Recurring tasks.** `recurring()` registers an interval-based WP-Cron task whose
  callback runs work through the durable queue (with retries and failure handling);
  `unschedule_recurring()` stops one, and all are cleared on `uninstall()`.
- **WP-CLI.** Commands registered under the manager key: `work`, `status`, `flush`
  (with `--failed`), `failed` (with `--queue` and `--limit`), and `retry`. The `work`
  command runs without the stampede lock for long-running, at-scale draining of large
  backlogs.
- **Configuration.** Every option has a default; override only what you need:
  `driver`, `max_attempts`, `time_limit`, `memory_factor`, `reserve_timeout`,
  `lock_time`, `cron_interval`, and `allowed_job_classes`.
- **Observability.** Action hooks (`{key}_job_processing`, `{key}_job_processed`,
  `{key}_job_released`, `{key}_job_failed`, `{key}_queue_empty`) and filters
  (`{key}_max_attempts`, `{key}_backoff`, `{key}_time_limit`, `{key}_memory_factor`,
  `{key}_cron_interval`, `{key}_loopback_sslverify`). No runtime dependencies, so you
  bring your own logger.
- **Conflict-free, per-instance schema.** Table names, option names, hook names, and
  the cron schedule are all derived from a per-plugin key, so two plugins that both
  use this library never collide. Dedicated indexed tables keep the queue fast as it
  grows instead of bloating `wp_options`.
- **Multisite and environment support.** All persistent state is blog-local: the job
  and failures tables (from `$wpdb->prefix`), the schema-version and recurring-task
  registry options, the worker lock transient, and the cron events. Each blog runs an
  independent queue and workers never serialize across blogs. Compatible with
  persistent object caches, `DISABLE_WP_CRON` and system cron, InnoDB or MyISAM, and
  PHP 8.0 through 8.5.
- **Forward progress under memory pressure.** The memory budget is enforced only
  after at least one job has been processed, so a host whose baseline memory already
  exceeds the ceiling drains the queue one job per run instead of stalling without
  processing anything.
- **No no-progress loopback storm.** The async dispatcher chains another loopback
  request only after a run that made progress and while work remains ready now, so a
  run that processed nothing can never spin an endless chain of no-op requests; the
  WP-Cron watchdog remains the safety net for that case.
- **Poison-job retirement.** A job that fatally crashes the process on every attempt
  (out of memory, timeout, `exit`) is moved to the failures table once it exceeds its
  maximum attempts, instead of being reclaimed and retried forever. A configured
  maximum above 255 is capped to the `TINYINT` `attempts` column ceiling so a
  saturating job is still retired rather than retried indefinitely.
- **Safe bulk inserts.** `dispatch_many()` and `push_many()` split into bounded
  chunks so a large batch can never build a single statement that trips the server's
  `max_allowed_packet` limit and fails as a whole.
- **Resilient reclamation.** The WP-Cron watchdog reclaims stale reservations before
  checking for available work, so a job stranded by a crashed worker is retried even
  when it is the only row left in the queue. Reclamation is throttled to once per
  reserve-timeout window, keeping a full-table scan out of the per-job drain loop.

### Security

- Unserialization is guarded by an `allowed_job_classes` allowlist for
  defense-in-depth. It defaults to `true` (any class) for convenience; restrict it to
  your own job classes in production. A payload that resolves to a class outside the
  list is moved to the failures table rather than executed.

[Unreleased]: https://github.com/wptechnix/wp-background-jobs/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/wptechnix/wp-background-jobs/releases/tag/v1.0.0
