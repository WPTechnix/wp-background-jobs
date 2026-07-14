<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use WPTechnix\WP_Background_Jobs\Tests\Support\Succeeding_Job;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;

final class Job_Test extends TestCase
{
	public function test_default_queue_is_default(): void
	{
		$this->assertSame('default', (new Succeeding_Job())->get_queue());
	}

	public function test_on_queue_routes_the_job(): void
	{
		$job = new Succeeding_Job();
		$returned = $job->on_queue('emails');

		$this->assertSame($job, $returned);
		$this->assertSame('emails', $job->get_queue());
	}

	public function test_default_config_sentinels(): void
	{
		$job = new Succeeding_Job();

		$this->assertSame(0, $job->get_max_attempts());
		$this->assertSame(-1, $job->get_backoff(1));
	}

	public function test_metadata_accessors(): void
	{
		$job = new Succeeding_Job();

		$this->assertNull($job->get_id());
		$this->assertSame(0, $job->get_attempts());

		$job->set_id(15);
		$job->set_attempts(2);

		$this->assertSame(15, $job->get_id());
		$this->assertSame(2, $job->get_attempts());
	}
}
