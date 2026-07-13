# Getting Started

## Requirements

- PHP 8.0 or newer (tested on PHP 8.0 through 8.5)
- WordPress 5.0 or newer
- Composer

It runs on single-site and multisite (see [Multisite](#multisite) below), on MySQL
or MariaDB with either InnoDB or MyISAM, and with or without a persistent object
cache. The atomic reservation needs no special engine features such as
`SKIP LOCKED`.

## Install

```bash
composer require wptechnix/wp-background-jobs
```

Make sure your plugin loads Composer's autoloader:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

## Create a manager

Create one manager per plugin, identified by a unique key. The key must contain only lowercase letters, numbers, and underscores. It becomes the prefix for this plugin's tables and hooks, which is what keeps two plugins from colliding.

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
```

With the key `myplugin` and a table prefix of `wp_`, this creates two tables: `wp_myplugin_jobs` and `wp_myplugin_job_failures`.

## Install the tables

Call `install()` on plugin activation. It creates the tables the first time and applies schema upgrades on later versions. It is safe to call more than once.

```php
register_activation_hook( __FILE__, static function () {
    myplugin_jobs()->install();
} );
```

If your plugin can be active across a schema change (for example after an update without deactivation), you can also call `install()` on an admin request guarded by your own version check.

## Boot the manager

Call `boot()` on every request. It registers the async handler, schedules the WP-Cron watchdog, and registers the WP-CLI commands. It only runs once per request even if called again.

```php
add_action( 'plugins_loaded', static function () {
    myplugin_jobs()->boot();
} );
```

## Multisite

The library keeps all of its state per blog: the job and failures tables (derived
from `$wpdb->prefix`), the schema-version and recurring-task registry options, the
worker lock, and the cron events. Each blog therefore runs an independent queue and
needs its own tables. Nothing is shared network-wide, so two blogs never serialize
each other's workers.

### Provisioning tables on every blog

A network activation hook runs only once, in the network admin context, so it
creates tables for a single blog. The simplest approach that covers every existing
blog and every blog created later is to call the version-guarded `install()`
alongside `boot()`. It short-circuits on an autoloaded option when the schema is
already current, so the cost on a normal request is negligible, and it self-heals
any blog that has not been provisioned yet:

```php
add_action( 'plugins_loaded', static function () {
    $jobs = myplugin_jobs();
    $jobs->install(); // No-op once the schema is current.
    $jobs->boot();
} );
```

If you prefer to provision at activation time instead, create a fresh manager for
each blog. Do not reuse the memoized `myplugin_jobs()` instance here: it is bound to
the blog it was first created on (see below), so it would install the same blog's
tables repeatedly.

```php
register_activation_hook( __FILE__, static function ( $network_wide ) {
    global $wpdb;

    if ( is_multisite() && $network_wide ) {
        foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            Background_Jobs::create( $wpdb, 'myplugin' )->install();
            restore_current_blog();
        }
        return;
    }

    myplugin_jobs()->install();
} );

// Provision blogs created after activation (WordPress 5.1+).
add_action( 'wp_initialize_site', static function ( $new_site ) {
    global $wpdb;

    switch_to_blog( (int) $new_site->blog_id );
    Background_Jobs::create( $wpdb, 'myplugin' )->install();
    restore_current_blog();
}, 20 );
```

### Dispatching onto another blog

The memoized `myplugin_jobs()` helper captures `$wpdb->prefix` at the moment it is
first created, so it is bound to that blog. To enqueue work onto a different blog,
switch first and build a fresh manager so it targets the switched blog's tables:

```php
switch_to_blog( $other_blog_id );
Background_Jobs::create( $GLOBALS['wpdb'], 'myplugin' )->dispatch( new Send_Welcome_Email( 42 ) );
restore_current_blog();
```

### WP-CLI on multisite

WP-CLI runs against one blog at a time. Pass `--url` so the command loads the target
blog's context and its tables:

```bash
wp myplugin work --url=shop.example.com
wp myplugin status --url=shop.example.com
```

## Dispatch your first job

```php
use WPTechnix\WP_Background_Jobs\Job;

final class Say_Hello extends Job {
    public function handle(): void {
        error_log( 'Hello from the background.' );
    }
}

myplugin_jobs()->dispatch( new Say_Hello() );
```

## Clean up on uninstall

If you want to drop the tables when the plugin is uninstalled, call `uninstall()` from your `uninstall.php`. This permanently deletes any queued and failed jobs.

```php
// uninstall.php
myplugin_jobs()->uninstall();
```

`uninstall()` acts on the current blog only. On multisite, drop each blog's tables
the same way you provisioned them, creating a fresh manager per blog:

```php
// uninstall.php
global $wpdb;

if ( is_multisite() ) {
    foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $blog_id ) {
        switch_to_blog( (int) $blog_id );
        Background_Jobs::create( $wpdb, 'myplugin' )->uninstall();
        restore_current_blog();
    }
} else {
    myplugin_jobs()->uninstall();
}
```

## Next steps

- [Defining Jobs](02-Defining-Jobs.md)
- [Dispatching Jobs](03-Dispatching-Jobs.md)
