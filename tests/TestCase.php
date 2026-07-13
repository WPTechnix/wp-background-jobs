<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case wiring Brain Monkey and stubbing the WordPress functions used
 * across the library.
 */
abstract class TestCase extends PHPUnitTestCase
{
    /**
     * Filter overrides keyed by hook-name suffix.
     *
     * When `apply_filters` is called with a tag ending in one of these
     * suffixes, the mapped value is returned instead of the default.
     *
     * @var array<string, mixed>
     */
    public array $filters = [];

    /**
     * Recorded `do_action` calls as `[tag, args]` pairs.
     *
     * @var list<array{0: string, 1: array<int, mixed>}>
     */
    public array $actions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stub_wordpress_functions();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stubs the WordPress functions the library calls so units can run in
     * isolation.
     */
    private function stub_wordpress_functions(): void
    {
        $self = $this;

        Functions\when('apply_filters')->alias(static function ($tag, $value = null, ...$args) use ($self) {
            foreach ($self->filters as $suffix => $override) {
                if (is_string($tag) && str_ends_with($tag, (string) $suffix)) {
                    return $override;
                }
            }

            return $value;
        });

        Functions\when('do_action')->alias(static function ($tag, ...$args) use ($self) {
            $self->actions[] = [(string) $tag, $args];
        });

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('get_option')->justReturn(0);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('wp_unschedule_event')->justReturn(true);
        Functions\when('wp_clear_scheduled_hook')->justReturn(0);
        Functions\when('wp_get_schedule')->justReturn(false);
        Functions\when('admin_url')->alias(static fn ($path = '') => 'https://example.test/wp-admin/' . $path);
        Functions\when('wp_create_nonce')->justReturn('nonce-value');
        Functions\when('add_query_arg')->alias(static fn ($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('sanitize_key')->returnArg();
        Functions\when('wp_unslash')->returnArg();
    }

    /**
     * Returns the tags of all recorded `do_action` calls.
     *
     * @return list<string>
     */
    protected function fired_action_tags(): array
    {
        return array_map(static fn ($entry) => $entry[0], $this->actions);
    }
}
