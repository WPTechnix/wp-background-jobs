# Defining Jobs

A job is a small class that extends `WPTechnix\WP_Background_Jobs\Job` and implements `handle()`. Its properties are the payload: they are serialized when the job is queued and restored when it runs.

```php
use WPTechnix\WP_Background_Jobs\Job;

final class Resize_Image extends Job {

    public function __construct( private int $attachment_id ) {}

    public function handle(): void {
        // Do the work. Throwing anything here triggers a retry.
        myplugin_regenerate_thumbnails( $this->attachment_id );
    }
}
```

## Payload rules

The payload is stored with PHP serialization, so keep it to plain, serializable data:

- Scalars, arrays, and simple objects are fine.
- Prefer storing identifiers (a post ID, a user ID) over whole objects. It keeps the row small and avoids stale data.
- **Closures and resources cannot be queued.** A database handle, a file pointer, or an anonymous function will fail to serialize.

Public, protected, and private properties are all part of the payload, so the constructor-promoted `private int $attachment_id` above is stored and restored correctly.

## Customizing a job

Override these methods to change how a specific job behaves. Every one has a working default, so you only override what you need.

### Queue

Route a job type to a named queue by overriding `get_queue()`:

```php
public function get_queue(): string {
    return 'emails';
}
```

You can also choose the queue at dispatch time with `on_queue()` or `$jobs->on( 'emails' )`. See [Dispatching Jobs](03-Dispatching-Jobs.md).

### Maximum attempts

Return the number of times this job may be attempted before it is treated as failed. Return `0` (the default) to inherit the manager wide setting.

```php
public function get_max_attempts(): int {
    return 5;
}
```

### Backoff

Return the number of seconds to wait before the next retry, given the attempt that just failed. Return a negative number (the default) to inherit the manager wide backoff formula.

```php
public function get_backoff( int $attempt ): int {
    // Linear backoff: 30s, 60s, 90s, ...
    return 30 * $attempt;
}
```

See [Retries and Failures](05-Retries-and-Failures.md) for how attempts and backoff work together.

## A complete example

```php
use WPTechnix\WP_Background_Jobs\Job;

final class Sync_Order_To_Crm extends Job {

    public function __construct( private int $order_id ) {}

    public function handle(): void {
        $response = wp_remote_post( 'https://crm.example.com/orders', [
            'body' => wp_json_encode( [ 'id' => $this->order_id ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            // Throwing schedules a retry with backoff.
            throw new RuntimeException( $response->get_error_message() );
        }
    }

    public function get_queue(): string {
        return 'crm';
    }

    public function get_max_attempts(): int {
        return 4;
    }
}
```
