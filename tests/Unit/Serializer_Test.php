<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Unit;

use stdClass;
use WPTechnix\WP_Background_Jobs\Support\Serializer;
use WPTechnix\WP_Background_Jobs\Tests\Support\Succeeding_Job;
use WPTechnix\WP_Background_Jobs\Tests\Support\Throwing_Job;
use WPTechnix\WP_Background_Jobs\Tests\TestCase;

final class Serializer_Test extends TestCase
{
    public function test_round_trip_preserves_payload(): void
    {
        $serializer = new Serializer(true);
        $restored = $serializer->unserialize($serializer->serialize(new Succeeding_Job(99)));

        $this->assertInstanceOf(Succeeding_Job::class, $restored);
        $this->assertSame(99, $restored->value);
    }

    public function test_empty_payload_returns_null(): void
    {
        $this->assertNull((new Serializer(true))->unserialize(''));
    }

    public function test_non_job_payload_returns_null(): void
    {
        $this->assertNull((new Serializer(true))->unserialize(serialize(new stdClass())));
    }

    public function test_disallowed_class_returns_null(): void
    {
        $payload = (new Serializer(true))->serialize(new Succeeding_Job(1));
        $restricted = new Serializer([Throwing_Job::class]);

        $this->assertNull($restricted->unserialize($payload));
    }
}
