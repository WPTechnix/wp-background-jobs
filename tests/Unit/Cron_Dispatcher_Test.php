<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use WPTechnix\WP_Background_Jobs\Dispatchers\Cron_Dispatcher;
use WPTechnix\WP_Background_Jobs\Support\Config;
use WPTechnix\WP_Background_Jobs\Support\Lock;
use WPTechnix\WP_Background_Jobs\Tests\Support\Fake_Queue;
use WPTechnix\WP_Background_Jobs\Tests\Support\Succeeding_Job;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;
use WPTechnix\WP_Background_Jobs\Worker;

final class Cron_Dispatcher_Test extends TestCase
{
    private function make(Fake_Queue $queue, array $options = []): Cron_Dispatcher
    {
        $config = Config::create('test', 'wp_', $options);
        $worker = new Worker($queue, $config);
        $lock = new Lock('test_worker_lock', $config->get_lock_time());

        return new Cron_Dispatcher($worker, $queue, $config, $lock);
    }

    public function test_register_schedule_adds_the_interval(): void
    {
        $schedules = $this->make(new Fake_Queue())->register_schedule([]);

        $this->assertArrayHasKey('test_cron_interval', $schedules);
        $this->assertSame(60, $schedules['test_cron_interval']['interval']);
    }

    public function test_interval_has_a_lower_bound(): void
    {
        $schedules = $this->make(new Fake_Queue(), ['cron_interval' => 5])->register_schedule([]);

        $this->assertSame(30, $schedules['test_cron_interval']['interval']);
    }

    public function test_run_worker_processes_pending_jobs(): void
    {
        Succeeding_Job::$handled = 0;

        $queue = new Fake_Queue();
        $queue->push(new Succeeding_Job(1));

        $this->make($queue)->run_worker();

        $this->assertSame(1, Succeeding_Job::$handled);
        $this->assertTrue($queue->is_empty());
    }

    public function test_run_worker_skips_when_queue_empty(): void
    {
        Succeeding_Job::$handled = 0;

        $queue = new Fake_Queue();

        $this->make($queue)->run_worker();

        $this->assertSame(0, Succeeding_Job::$handled);
    }

    public function test_run_worker_reclaims_a_stale_reservation_and_processes_it(): void
    {
        Succeeding_Job::$handled = 0;

        $queue = new Fake_Queue();
        $queue->push_stale_reserved(new Succeeding_Job(1));

        // A job left reserved by a crashed worker is the only row, so nothing
        // looks available and the loopback kick would never fire for it.
        $this->assertSame(0, $queue->count_available());

        $this->make($queue)->run_worker();

        $this->assertSame(1, Succeeding_Job::$handled, 'The stranded job must be reclaimed and run.');
        $this->assertTrue($queue->is_empty());
    }

    public function test_run_worker_always_attempts_reclaim(): void
    {
        $queue = new Fake_Queue();

        $this->make($queue)->run_worker();

        $this->assertSame(1, $queue->reclaim_calls);
    }
}
