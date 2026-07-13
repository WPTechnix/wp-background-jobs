<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use Mockery;
use stdClass;
use WPTechnix\WP_Background_Jobs\Queue;
use WPTechnix\WP_Background_Jobs\Support\Serializer;
use WPTechnix\WP_Background_Jobs\Tests\Support\Succeeding_Job;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;

final class Queue_Test extends TestCase
{
    private function make_queue(object $wpdb): Queue
    {
        return new Queue($wpdb, 'wp_test_jobs', 'wp_test_job_failures', new Serializer(true), 300);
    }

    public function test_push_inserts_and_assigns_id(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('insert')->once()->andReturn(1);
        $wpdb->insert_id = 42;

        $queue = $this->make_queue($wpdb);
        $job = new Succeeding_Job(1);

        $this->assertSame(42, $queue->push($job));
        $this->assertSame(42, $job->get_id());
    }

    public function test_push_many_chunks_large_batches_into_multiple_inserts(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($query) => $query);
        // 1200 jobs split into chunks of 500 / 500 / 200 => three inserts.
        $wpdb->shouldReceive('query')->times(3)->andReturn(500, 500, 200);

        $jobs = array_fill(0, 1200, new Succeeding_Job(1));

        $this->assertSame(1200, $this->make_queue($wpdb)->push_many($jobs));
    }

    public function test_push_many_counts_successful_chunks_when_one_fails(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($query) => $query);
        // The middle chunk fails; the surrounding chunks still persist and count.
        $wpdb->shouldReceive('query')->times(3)->andReturn(500, false, 200);

        $jobs = array_fill(0, 1200, new Succeeding_Job(1));

        $this->assertSame(700, $this->make_queue($wpdb)->push_many($jobs));
    }

    public function test_push_many_returns_zero_for_empty_input(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('query')->never();

        $this->assertSame(0, $this->make_queue($wpdb)->push_many([]));
    }

    public function test_delete_without_id_returns_false(): void
    {
        $wpdb = Mockery::mock('wpdb');

        $this->assertFalse($this->make_queue($wpdb)->delete(new Succeeding_Job(1)));
    }

    public function test_delete_returns_true_on_success(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('delete')->once()->andReturn(1);

        $job = new Succeeding_Job(1);
        $job->set_id(7);

        $this->assertTrue($this->make_queue($wpdb)->delete($job));
    }

    public function test_count_returns_integer(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_var')->once()->andReturn('5');

        $this->assertSame(5, $this->make_queue($wpdb)->count());
    }

    public function test_count_available_returns_integer(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($query) => $query);
        $wpdb->shouldReceive('get_var')->once()->andReturn('4');

        $this->assertSame(4, $this->make_queue($wpdb)->count_available());
    }

    public function test_pop_returns_null_when_no_candidate(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($query) => $query);
        $wpdb->shouldReceive('query')->andReturn(0);
        $wpdb->shouldReceive('get_var')->andReturn(null);

        $this->assertNull($this->make_queue($wpdb)->pop());
    }

    public function test_pop_reserves_and_hydrates_a_job(): void
    {
        $row = new stdClass();
        $row->id = '10';
        $row->queue = 'default';
        $row->payload = (new Serializer(true))->serialize(new Succeeding_Job(3));
        $row->attempts = '1';

        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($query) => $query);
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn('10');
        $wpdb->shouldReceive('get_row')->andReturn($row);

        $job = $this->make_queue($wpdb)->pop();

        $this->assertInstanceOf(Succeeding_Job::class, $job);
        $this->assertSame(10, $job->get_id());
        $this->assertSame(1, $job->get_attempts());
    }

    public function test_reclaim_returns_number_of_reservations_freed(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->once()->andReturnUsing(static fn ($query) => $query);
        $wpdb->shouldReceive('query')->once()->andReturn(2);

        $this->assertSame(2, $this->make_queue($wpdb)->reclaim());
    }

    public function test_purge_failed_returns_count(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('query')->once()->andReturn(3);

        $this->assertSame(3, $this->make_queue($wpdb)->purge_failed());
    }

    public function test_retry_failed_moves_rows_back(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_var')->once()->andReturn('10');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($query) => $query);
        // First query is the INSERT ... SELECT (its count is returned), second is the DELETE.
        $wpdb->shouldReceive('query')->twice()->andReturn(4, 4);

        $this->assertSame(4, $this->make_queue($wpdb)->retry_failed());
    }

    public function test_retry_failed_returns_zero_when_nothing_failed(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_var')->once()->andReturn(null);
        $wpdb->shouldReceive('query')->never();

        $this->assertSame(0, $this->make_queue($wpdb)->retry_failed());
    }

    public function test_list_failed_returns_rows(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->queue = 'default';
        $row->exception = 'RuntimeException: boom';
        $row->failed_at = '2026-07-13 00:00:00';

        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($query) => $query);
        $wpdb->shouldReceive('get_results')->once()->andReturn([$row]);

        $this->assertSame([$row], $this->make_queue($wpdb)->list_failed());
    }
}
