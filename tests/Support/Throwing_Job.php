<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Support;

use RuntimeException;
use WPTechnix\WP_Background_Jobs\Job;

/**
 * A job that always throws, used to exercise retry and failure handling.
 */
final class Throwing_Job extends Job
{
	private int $max_attempts;

	private int $backoff;

	public function __construct(int $max_attempts = 0, int $backoff = -1)
	{
		$this->max_attempts = $max_attempts;
		$this->backoff = $backoff;
	}

	public function handle(): void
	{
		throw new RuntimeException('boom');
	}

	public function get_max_attempts(): int
	{
		return $this->max_attempts;
	}

	public function get_backoff(int $attempt): int
	{
		return $this->backoff;
	}
}
