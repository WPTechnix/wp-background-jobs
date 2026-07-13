# WP Background Jobs

A simple, reliable background job queue for WordPress plugins, with zero runtime dependencies.

Use it when a plugin needs to move work out of the current request: sending email, calling remote APIs, processing imports, rebuilding caches, or anything else that should not block a page load.

## Why this library

- **Dedicated, indexed tables** instead of `wp_options`, so the queue stays fast as it grows.
- **Atomic reservation** with a compare-and-swap claim, so a job is never processed twice even under concurrency, on any MySQL or MariaDB version.
- **Retries with backoff** and a **failed-jobs table**, so transient errors recover and permanent ones are recorded rather than lost.
- **Three drivers**: an instant async kick, a WP-Cron watchdog, and a WP-CLI worker. The async and cron drivers run together by default.
- **Conflict-free by construction**: table and hook names come from a per-plugin key, so two plugins that both use this library never collide.

## Documentation

- [Getting Started](01-Getting-Started.md)
- [Defining Jobs](02-Defining-Jobs.md)
- [Dispatching Jobs](03-Dispatching-Jobs.md)
- [How Processing Works](04-How-Processing-Works.md)
- [Retries and Failures](05-Retries-and-Failures.md)
- [WP-CLI](06-WP-CLI.md)
- [Configuration and Hooks](07-Configuration-and-Hooks.md)
- [Recurring Tasks](08-Recurring-Tasks.md)
