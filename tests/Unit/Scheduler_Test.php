<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use WPTechnix\WP_Background_Jobs\Scheduling\Scheduler;
use WPTechnix\WP_Background_Jobs\Support\Config;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;

final class Scheduler_Test extends TestCase
{
	private function make(): Scheduler
	{
		return new Scheduler(Config::create('test', 'wp_'));
	}

	public function test_recurring_registers_the_interval_schedule(): void
	{
		$scheduler = $this->make();
		$scheduler->recurring('cleanup', 3600, static fn () => null);

		$schedules = $scheduler->register_schedules([]);

		$this->assertArrayHasKey('test_interval_3600', $schedules);
		$this->assertSame(3600, $schedules['test_interval_3600']['interval']);
	}

	public function test_recurring_floors_short_intervals(): void
	{
		$scheduler = $this->make();
		$scheduler->recurring('ping', 5, static fn () => null);

		$schedules = $scheduler->register_schedules([]);

		$this->assertArrayHasKey('test_interval_60', $schedules);
		$this->assertArrayNotHasKey('test_interval_5', $schedules);
	}

	public function test_recurring_rejects_invalid_names(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->make()->recurring('Bad Name', 3600, static fn () => null);
	}

	public function test_unschedule_recurring_clears_the_hook(): void
	{
		$cleared = null;
		Functions\when('wp_clear_scheduled_hook')->alias(static function ($hook) use (&$cleared) {
			$cleared = $hook;

			return 0;
		});

		$this->make()->unschedule_recurring('cleanup');

		$this->assertSame('test_scheduled_cleanup', $cleared);
	}
}
