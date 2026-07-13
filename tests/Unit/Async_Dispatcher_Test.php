<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use Brain\Monkey\Functions;
use WPTechnix\WP_Background_Jobs\Dispatchers\Async_Dispatcher;
use WPTechnix\WP_Background_Jobs\Support\Config;
use WPTechnix\WP_Background_Jobs\Support\Lock;
use WPTechnix\WP_Background_Jobs\Tests\Support\Fake_Queue;
use WPTechnix\WP_Background_Jobs\Tests\Support\Succeeding_Job;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;
use WPTechnix\WP_Background_Jobs\Worker;

final class Async_Dispatcher_Test extends TestCase
{
    private function make(Fake_Queue $queue): Async_Dispatcher
    {
        $config = Config::create('test', 'wp_');
        $worker = new Worker($queue, $config);
        $lock = new Lock('test_worker_lock', $config->get_lock_time());

        return new Async_Dispatcher($worker, $queue, $config, $lock);
    }

    public function test_send_kick_fires_a_non_blocking_request_when_work_is_pending(): void
    {
        $queue = new Fake_Queue();
        $queue->push(new Succeeding_Job(1));

        $captured = null;
        Functions\when('wp_remote_post')->alias(static function ($url, $args) use (&$captured) {
            $captured = $args;

            return [];
        });

        $this->make($queue)->send_kick();

        $this->assertIsArray($captured);
        $this->assertFalse($captured['blocking']);
    }

    public function test_send_kick_is_skipped_when_queue_is_empty(): void
    {
        Functions\expect('wp_remote_post')->never();

        $this->make(new Fake_Queue())->send_kick();

        $this->assertTrue(true);
    }

    public function test_send_kick_is_skipped_when_only_delayed_jobs_exist(): void
    {
        Functions\expect('wp_remote_post')->never();

        $queue = new Fake_Queue();
        $queue->push(new Succeeding_Job(1), 3600); // Not available for an hour.

        $this->make($queue)->send_kick();

        $this->assertTrue(true);
    }
}
