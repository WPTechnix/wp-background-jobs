<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use WPTechnix\WP_Background_Jobs\Dispatchers\Sync_Dispatcher;
use WPTechnix\WP_Background_Jobs\Support\Config;
use WPTechnix\WP_Background_Jobs\Tests\Support\Fake_Queue;
use WPTechnix\WP_Background_Jobs\Tests\Support\Succeeding_Job;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;
use WPTechnix\WP_Background_Jobs\Worker;

final class Sync_Dispatcher_Test extends TestCase
{
	public function test_dispatch_runs_the_job_inline(): void
	{
		Succeeding_Job::$handled = 0;

		$queue = new Fake_Queue();
		$queue->push(new Succeeding_Job(1));
		$worker = new Worker($queue, Config::create('test', 'wp_'));

		(new Sync_Dispatcher($worker))->dispatch('default');

		$this->assertSame(1, Succeeding_Job::$handled);
		$this->assertTrue($queue->is_empty());
	}
}
