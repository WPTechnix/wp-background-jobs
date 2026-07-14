<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use InvalidArgumentException;
use WPTechnix\WP_Background_Jobs\Support\Config;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;

final class Config_Test extends TestCase
{
	public function test_create_builds_table_and_option_names(): void
	{
		$config = Config::create('myplugin', 'wp_');

		$this->assertSame('wp_myplugin_jobs', $config->get_jobs_table());
		$this->assertSame('wp_myplugin_job_failures', $config->get_failures_table());
		$this->assertSame('myplugin_jobs_db_version', $config->get_version_option());
		$this->assertSame('myplugin', $config->get_key());
	}

	public function test_invalid_key_throws(): void
	{
		$this->expectException(InvalidArgumentException::class);
		Config::create('Bad Key', 'wp_');
	}

	public function test_defaults_are_applied(): void
	{
		$config = Config::create('test', 'wp_');

		$this->assertSame(3, $config->get_max_attempts());
		$this->assertSame(20, $config->get_time_limit());
		$this->assertSame(0.8, $config->get_memory_factor());
		$this->assertSame(300, $config->get_reserve_timeout());
		$this->assertSame(60, $config->get_cron_interval());
		$this->assertTrue($config->get_allowed_job_classes());
	}

	public function test_options_override_defaults(): void
	{
		$config = Config::create('test', 'wp_', [
			'max_attempts' => 7,
			'time_limit' => 5,
			'allowed_job_classes' => ['Foo'],
		]);

		$this->assertSame(7, $config->get_max_attempts());
		$this->assertSame(5, $config->get_time_limit());
		$this->assertSame(['Foo'], $config->get_allowed_job_classes());
	}

	public function test_memory_factor_is_clamped_to_a_safe_range(): void
	{
		// A non-positive factor would stall the worker, so it falls back to the default.
		$this->assertSame(0.8, Config::create('test', 'wp_', ['memory_factor' => 0.0])->get_memory_factor());
		$this->assertSame(0.8, Config::create('test', 'wp_', ['memory_factor' => -1.0])->get_memory_factor());

		// A fraction above one is capped so the guard still bounds usage.
		$this->assertSame(1.0, Config::create('test', 'wp_', ['memory_factor' => 2.5])->get_memory_factor());

		// A valid fraction passes through unchanged.
		$this->assertSame(0.5, Config::create('test', 'wp_', ['memory_factor' => 0.5])->get_memory_factor());
	}

	public function test_backoff_grows_exponentially_and_caps(): void
	{
		$config = Config::create('test', 'wp_');

		$this->assertSame(60, $config->backoff(1));
		$this->assertSame(120, $config->backoff(2));
		$this->assertSame(240, $config->backoff(3));
		$this->assertSame(3600, $config->backoff(100));
	}
}
