# Dispatching Jobs

## Dispatch one job

```php
myplugin_jobs()->dispatch( new Send_Welcome_Email( 42 ) );
```

`dispatch()` returns the new job id, or `false` if the insert failed.

## Delay a job

Pass a delay in seconds. The job will not be picked up until that time has passed.

```php
myplugin_jobs()->dispatch( new Send_Welcome_Email( 42 ), delay: 3600 ); // in one hour
```

A delayed job does not trigger the instant async kick, since there is nothing to run yet. It is picked up by the WP-Cron watchdog once its delay has elapsed, so the effective start latency for a delayed job is its delay plus up to one `cron_interval` (60 seconds by default). See [How Processing Works](04-How-Processing-Works.md).

## Schedule for a specific time

When you have an absolute moment rather than a duration, pass a `DateTimeInterface` to `dispatch_at()`:

```php
myplugin_jobs()->dispatch_at(
    new Send_Welcome_Email( 42 ),
    new DateTimeImmutable( '2026-08-01 09:00:00', wp_timezone() )
);
```

The instant is absolute: the object's timezone is respected, and a time already in the past runs as soon as possible (it is treated as no delay). Internally this is the same mechanism as `dispatch( $job, $delay )`, so all the delayed-job behaviour above applies. `dispatch_many_at()` and the queue chain (`$jobs->on( 'emails' )->dispatch_at( $job, $when )`) work the same way.

## Named queues

Queues are just labels that let you group and inspect work separately. The default worker drains every queue, so you do not need a separate worker per queue.

Choose a queue at dispatch time:

```php
myplugin_jobs()->on( 'emails' )->dispatch( new Send_Welcome_Email( 42 ) );
myplugin_jobs()->on( 'emails' )->dispatch_many( [ $job_a, $job_b ] );
```

Or set it on the job:

```php
$job = ( new Send_Welcome_Email( 42 ) )->on_queue( 'emails' );
myplugin_jobs()->dispatch( $job );
```

Or bake it into the job type by overriding `get_queue()`. See [Defining Jobs](02-Defining-Jobs.md).

## Dispatch many jobs at once

`dispatch_many()` inserts every job in a single operation (chunked internally into batch INSERTs), which is far faster than calling `dispatch()` in a loop.

```php
$jobs = array_map(
    static fn ( int $id ) => new Send_Welcome_Email( $id ),
    $user_ids
);

myplugin_jobs()->dispatch_many( $jobs );
```

`dispatch_many()` returns the number of jobs inserted. Because it is a bulk operation (chunked internally), it does not assign an id back onto each job object, so do not rely on `get_id()` after calling it. Use `dispatch()` when you need the id of an individual job.


### The self-chaining pattern

If you are iterating a source you cannot enumerate up front (for example paging through a remote API), have a job queue the next page as its final step. Each job does a bounded amount of work and hands off to the next, so the run spans many short requests and never times out.

```php
final class Import_Page extends Job {

    public function __construct( private int $page = 1 ) {}

    public function handle(): void {
        $rows = myplugin_fetch_page( $this->page );
        if ( [] === $rows ) {
            return; // Done.
        }

        foreach ( $rows as $row ) {
            myplugin_import_row( $row );
        }

        // Queue the next page.
        myplugin_jobs()->dispatch( new self( $this->page + 1 ) );
    }
}

// Kick off the import.
myplugin_jobs()->dispatch( new Import_Page( 1 ) );
```
