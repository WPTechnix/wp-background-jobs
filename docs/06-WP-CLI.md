# WP-CLI

When WP-CLI is available and the manager has been booted, it registers a command set under your manager key. With the key `myplugin`, the commands are `wp myplugin <command>`.

On multisite, every command runs against one blog at a time. Add `--url` to target a
specific blog, for example `wp myplugin work --url=shop.example.com`. See the
[Multisite section](01-Getting-Started.md#multisite) of Getting Started.

## work

Processes pending jobs until the queue is drained. Unlike the async and cron drivers, this keeps running across time budgets in a single invocation, so it is the right tool for large backlogs.

```bash
wp myplugin work
wp myplugin work --queue=emails
```

`work` processes every job that is ready to run and then exits. It does not block waiting for delayed jobs or jobs in a retry backoff to become due, so run it on a schedule (see below) rather than expecting a single invocation to wait around.

Run it from a system cron or a process manager for steady, high-throughput processing:

```cron
* * * * * cd /var/www/site && wp myplugin work --quiet
```

## status

Shows how many jobs are pending and how many have failed.

```bash
wp myplugin status
```

```
Pending: 128
Failed:  3
```

## flush

Deletes pending jobs from the queue. By default this leaves the failures table untouched; pass `--failed` to clear failures instead of pending jobs.

```bash
wp myplugin flush              # all pending jobs, all queues
wp myplugin flush --queue=emails
wp myplugin flush --failed     # clear the failures table instead
```

## failed

Lists recorded failures, newest first, with the failing exception's first line.

```bash
wp myplugin failed
wp myplugin failed --queue=emails --limit=20
```

## retry

Moves failed jobs back onto the queue for another attempt. Retried jobs get a fresh attempt budget and become available immediately.

```bash
wp myplugin retry              # retry every failure
wp myplugin retry --queue=emails
```

## Running the worker from system cron

For the most reliable processing on a busy site, disable WordPress's traffic-triggered cron and drive both WP-Cron and the worker from the system scheduler:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
* * * * * cd /var/www/site && wp cron event run --due-now --quiet
* * * * * cd /var/www/site && wp myplugin work --quiet
```
