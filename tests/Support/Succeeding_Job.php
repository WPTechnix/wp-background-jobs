<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Support;

use WPTechnix\WP_Background_Jobs\Job;

/**
 * A job that records that it ran and always succeeds.
 */
final class Succeeding_Job extends Job
{
	public static int $handled = 0;

	public int $value;

	public function __construct(int $value = 0)
	{
		$this->value = $value;
	}

	public function handle(): void
	{
		self::$handled++;
	}
}
