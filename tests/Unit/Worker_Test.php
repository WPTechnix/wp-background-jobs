<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use WPTechnix\WP_Background_Jobs\Exceptions\Max_Attempts_Exceeded_Exception;
use WPTechnix\WP_Background_Jobs\Support\Config;
use WPTechnix\WP_Background_Jobs\Tests\Support\Fake_Queue;
use WPTechnix\WP_Background_Jobs\Tests\Support\Succeeding_Job;
use WPTechnix\WP_Background_Jobs\Tests\Support\Throwing_Job;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;
use WPTechnix\WP_Background_Jobs\Worker;

final class Worker_Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Succeeding_Job::$handled = 0;
    }

    private function config(array $options = []): Config
    {
        return Config::create('test', 'wp_', $options);
    }

    public function test_successful_job_is_handled_and_deleted(): void
    {
        $queue = new Fake_Queue();
        $queue->push(new Succeeding_Job(5));
        $job = $queue->pop();

        (new Worker($queue, $this->config()))->process($job);

        $this->assertSame(1, Succeeding_Job::$handled);
        $this->assertTrue($queue->is_empty());
    }

    public function test_failing_job_below_max_is_released_with_backoff(): void
    {
        $queue = new Fake_Queue();
        $queue->push(new Throwing_Job(3, 42));
        $job = $queue->pop();

        (new Worker($queue, $this->config()))->process($job);

        $this->assertCount(1, $queue->released);
        $this->assertSame(42, $queue->released[0]['delay']);
        $this->assertCount(0, $queue->failed);
        $this->assertFalse($queue->is_empty());
    }

    public function test_failing_job_at_max_is_moved_to_failures(): void
    {
        $queue = new Fake_Queue();
        $queue->push(new Throwing_Job(1));
        $job = $queue->pop();

        (new Worker($queue, $this->config()))->process($job);

        $this->assertCount(1, $queue->failed);
        $this->assertCount(0, $queue->released);
        $this->assertTrue($queue->is_empty());
    }

    public function test_job_that_overshot_max_attempts_is_failed_without_running(): void
    {
        Succeeding_Job::$handled = 0;

        $queue = new Fake_Queue();
        $queue->push($job = new Succeeding_Job(1));
        $job->set_attempts(4); // Simulate a crash-reclaim overshoot past the default max of 3.

        (new Worker($queue, $this->config()))->process($job);

        $this->assertSame(0, Succeeding_Job::$handled, 'The job must not run.');
        $this->assertCount(1, $queue->failed);
        $this->assertInstanceOf(
            Max_Attempts_Exceeded_Exception::class,
            $queue->failed[0]['exception']
        );
    }

    public function test_max_attempts_is_capped_at_the_column_ceiling(): void
    {
        // A job asking for more attempts than the TINYINT attempts column can hold
        // must still be retired once it saturates, never retried forever.
        $queue = new Fake_Queue();
        $queue->push($job = new Throwing_Job(1000));
        $job->set_attempts(255); // Saturated at the column ceiling.

        (new Worker($queue, $this->config()))->process($job);

        $this->assertCount(1, $queue->failed, 'The saturated job must be failed.');
        $this->assertCount(0, $queue->released, 'It must not be released for another attempt.');
    }

    public function test_backoff_inherits_config_when_job_defers(): void
    {
        $queue = new Fake_Queue();
        $queue->push(new Throwing_Job(3, -1));
        $job = $queue->pop();

        (new Worker($queue, $this->config()))->process($job);

        $this->assertSame(60, $queue->released[0]['delay']);
    }

    public function test_max_attempts_inherits_config_when_job_defers(): void
    {
        $queue = new Fake_Queue();
        $queue->push(new Throwing_Job(0, 0));
        $worker = new Worker($queue, $this->config(['max_attempts' => 2]));

        $worker->process($queue->pop());
        $this->assertCount(0, $queue->failed, 'First failure should be retried.');

        $worker->process($queue->pop());
        $this->assertCount(1, $queue->failed, 'Second failure should exhaust attempts.');
    }

    public function test_run_drains_the_queue_and_fires_empty_action(): void
    {
        $queue = new Fake_Queue();
        for ($i = 0; $i < 5; $i++) {
            $queue->push(new Succeeding_Job($i));
        }

        $processed = (new Worker($queue, $this->config()))->run();

        $this->assertSame(5, $processed);
        $this->assertSame(5, Succeeding_Job::$handled);
        $this->assertTrue($queue->is_empty());
        $this->assertContains('test_queue_empty', $this->fired_action_tags());
    }

    public function test_memory_guard_stops_after_one_job(): void
    {
        // Even with an impossibly small memory ceiling the worker must make
        // forward progress: it processes exactly one job, then stops. Without
        // this guarantee a host whose baseline already exceeds the ceiling would
        // never drain the queue and the async dispatcher would spin loopbacks.
        $this->filters['_memory_factor'] = 0.0000001;

        $queue = new Fake_Queue();
        $queue->push(new Succeeding_Job(1));
        $queue->push(new Succeeding_Job(2));

        $processed = (new Worker($queue, $this->config()))->run();

        $this->assertSame(1, $processed, 'The memory guard must allow one job before stopping.');
        $this->assertSame(1, Succeeding_Job::$handled);
        $this->assertSame(1, $queue->count(), 'The remaining job must stay queued for the next run.');
    }
}
