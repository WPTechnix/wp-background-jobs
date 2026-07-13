# WP Background Jobs

[![Latest Version](https://img.shields.io/packagist/v/wptechnix/wp-background-jobs.svg?style=for-the-badge)](https://packagist.org/packages/wptechnix/wp-background-jobs)
[![License](https://img.shields.io/packagist/l/wptechnix/wp-background-jobs.svg?style=for-the-badge)](LICENSE)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/wptechnix/wp-background-jobs/php.svg?style=for-the-badge)](https://packagist.org/packages/wptechnix/wp-background-jobs)

A simple, reliable background job queue for WordPress plugins. Define a job, dispatch it, and let it run outside the current request. It has zero runtime dependencies, its own indexed database tables, atomic job reservation that is safe under concurrency, and automatic retries with backoff.

It is built for plugins that need background work without pulling in a heavy queue stack, and it scales from a small site running on WP-Cron to a large one draining the queue with WP-CLI.

## Key Features

- 🧱 **Object jobs** - extend one class, implement `handle()`, dispatch it.
- 🗂️ **Dedicated, indexed tables** - no `wp_options` bloat, no `LIKE` scans.
- 🔒 **Atomic reservation** - a compare-and-swap claim prevents double processing on every MySQL and MariaDB version, with no `SKIP LOCKED` requirement.
- 🔁 **Retries with backoff** - configurable per job, with a failed-jobs table you can retry, list, or clear from PHP or WP-CLI.
- ⏱️ **Delayed and named queues** - schedule work for later, at a specific time, or on a recurring interval, and route it to named queues.
- 🚀 **Three ways to run** - an instant async kick, a WP-Cron watchdog safety net, and a WP-CLI worker for scale.
- 🧩 **No runtime dependencies** - observability comes from action hooks, so nothing can conflict with the host environment.
- 🛡️ **Conflict-free by design** - table names and hook names are derived from a per-plugin key, so two plugins never collide.
- 🌐 **Multisite ready** - all state is per-blog (tables, options, lock, cron), so every site runs an independent queue. See [Getting Started](docs/01-Getting-Started.md#multisite).

## Installation

**Requirements:** PHP 8.0+, WordPress 5.0+, Composer.

```bash
composer require wptechnix/wp-background-jobs
```

## Quick Start

### 1. Create the manager and install its tables

```php
use WPTechnix\WP_Background_Jobs\Background_Jobs;

function myplugin_jobs(): Background_Jobs {
    global $wpdb;
    static $jobs = null;
    if ( null === $jobs ) {
        $jobs = Background_Jobs::create( $wpdb, 'myplugin' );
    }
    return $jobs;
}

// Create the tables on activation.
register_activation_hook( __FILE__, static function () {
    myplugin_jobs()->install();
} );

// Register hooks, the cron watchdog, and CLI commands on every request.
add_action( 'plugins_loaded', static function () {
    myplugin_jobs()->boot();
} );
```

### 2. Define a job

```php
use WPTechnix\WP_Background_Jobs\Job;

final class Send_Welcome_Email extends Job {

    public function __construct( private int $user_id ) {}

    public function handle(): void {
        $user = get_userdata( $this->user_id );
        if ( false !== $user ) {
            wp_mail( $user->user_email, 'Welcome', 'Thanks for joining.' );
        }
    }
}
```

### 3. Dispatch it

```php
// Run as soon as possible, in the background.
myplugin_jobs()->dispatch( new Send_Welcome_Email( 42 ) );

// Run in five minutes.
myplugin_jobs()->dispatch( new Send_Welcome_Email( 42 ), delay: 300 );

// Run at a specific time.
myplugin_jobs()->dispatch_at( new Send_Welcome_Email( 42 ), new DateTimeImmutable( 'tomorrow 9am', wp_timezone() ) );

// Route to a named queue.
myplugin_jobs()->on( 'emails' )->dispatch( new Send_Welcome_Email( 42 ) );

// Queue many jobs in a single insert.
myplugin_jobs()->dispatch_many( [
    new Send_Welcome_Email( 42 ),
    new Send_Welcome_Email( 43 ),
] );
```

That is the whole loop: dispatch adds a row and fires one non-blocking request that starts a worker in a separate process. If that request cannot run on your host, the WP-Cron watchdog picks the work up within a minute instead.

## How Processing Works

Every dispatch inserts a row and, once per request, fires a single non-blocking loopback request to `admin-ajax.php` that starts a worker. The worker drains jobs until the queue is empty or it reaches its time or memory budget, then it hands off to a fresh process so work spans many short requests safely.

A WP-Cron event runs on a schedule as an always-on safety net, so the queue still drains on hosts where loopback requests are blocked. For large backlogs, a long running WP-CLI worker is available. See [How Processing Works](docs/04-How-Processing-Works.md) for the details.

## Full Documentation

- [Getting Started](docs/01-Getting-Started.md)
- [Defining Jobs](docs/02-Defining-Jobs.md)
- [Dispatching Jobs](docs/03-Dispatching-Jobs.md)
- [How Processing Works](docs/04-How-Processing-Works.md)
- [Retries and Failures](docs/05-Retries-and-Failures.md)
- [WP-CLI](docs/06-WP-CLI.md)
- [Configuration and Hooks](docs/07-Configuration-and-Hooks.md)
- [Recurring Tasks](docs/08-Recurring-Tasks.md)

## Development

The toolchain runs entirely in Docker, so no local PHP or Composer is required.

### Using Docker Compose

```bash
docker compose run --rm php composer install
docker compose run --rm php bash
```

### Running the checks

```bash
docker compose run --rm php composer test     # PHPUnit
docker compose run --rm php composer lint      # PHPCS + PHPStan
docker compose run --rm php composer phpcbf    # Auto-fix coding standards
```

## License

MIT. See [LICENSE](LICENSE).
