<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use WPTechnix\WP_Background_Jobs\Background_Jobs;
use WPTechnix\WP_Background_Jobs\Tests\Support\Succeeding_Job;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;

final class Background_Jobs_Test extends TestCase
{
	/**
	 * Builds a manager over a mocked wpdb whose insert() captures the row data.
	 *
	 * @param array<string, mixed>|null $captured Populated with the inserted row.
	 */
	private function make_manager(&$captured): Background_Jobs
	{
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$wpdb->insert_id = 7;
		$wpdb->shouldReceive('insert')->once()->andReturnUsing(
			static function ($table, $data) use (&$captured) {
				$captured = $data;

				return 1;
			}
		);

		return Background_Jobs::create($wpdb, 'test');
	}

	private function delay_of(string $available_at): int
	{
		$available = new DateTimeImmutable($available_at, new DateTimeZone('UTC'));

		return $available->getTimestamp() - time();
	}

	public function test_dispatch_at_schedules_for_a_future_instant(): void
	{
		$captured = null;
		$manager = $this->make_manager($captured);

		$id = $manager->dispatch_at(new Succeeding_Job(1), new DateTimeImmutable('+1 hour'));

		$this->assertSame(7, $id);
		$this->assertIsArray($captured);
		$this->assertEqualsWithDelta(3600, $this->delay_of($captured['available_at']), 5);
	}

	public function test_dispatch_at_in_the_past_becomes_available_now(): void
	{
		$captured = null;
		$manager = $this->make_manager($captured);

		$manager->dispatch_at(new Succeeding_Job(1), new DateTimeImmutable('-1 hour'));

		$this->assertIsArray($captured);
		$this->assertEqualsWithDelta(0, $this->delay_of($captured['available_at']), 5);
	}

	public function test_count_pending_delegates_to_the_queue(): void
	{
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive('get_var')->once()->andReturn('5');

		$this->assertSame(5, Background_Jobs::create($wpdb, 'test')->count_pending());
	}

	public function test_is_empty_is_true_when_no_pending_jobs(): void
	{
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive('get_var')->once()->andReturn('0');

		$this->assertTrue(Background_Jobs::create($wpdb, 'test')->is_empty());
	}

	public function test_purge_pending_delegates_to_the_queue(): void
	{
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive('query')->once()->andReturn(3);

		$this->assertSame(3, Background_Jobs::create($wpdb, 'test')->purge_pending());
	}
}
