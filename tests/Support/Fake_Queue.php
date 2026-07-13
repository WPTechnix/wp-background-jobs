<?php

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Tests\Support;

use Throwable;
use WPTechnix\WP_Background_Jobs\Contracts\Job_Interface;
use WPTechnix\WP_Background_Jobs\Contracts\Queue_Interface;

/**
 * In-memory queue used to exercise the worker deterministically without a
 * database. Reservation is simulated: `pop()` increments attempts (as the real
 * compare-and-swap claim does) and hides the job so it is not popped again in
 * the same run.
 */
class Fake_Queue implements Queue_Interface
{
    /**
     * Pending jobs keyed by id. A non-null `reserved` timestamp mimics a held
     * reservation, so the entry is hidden from `pop()` and `count_available()`
     * until it is released, reclaimed, or deleted.
     *
     * @var array<int, array{job: Job_Interface, available: int, reserved: int|null}>
     */
    private array $jobs = [];

    /**
     * Seconds after which a reservation is treated as stale by {@see Fake_Queue::reclaim()}.
     */
    private int $reserve_timeout = 300;

    /**
     * Number of times {@see Fake_Queue::reclaim()} has been called.
     */
    public int $reclaim_calls = 0;

    /**
     * Failed jobs recorded by {@see Fake_Queue::fail()}.
     *
     * @var list<array{job: Job_Interface, exception: Throwable}>
     */
    public array $failed = [];

    /**
     * Release records as `[id, delay]` produced by {@see Fake_Queue::release()}.
     *
     * @var list<array{id: int, delay: int}>
     */
    public array $released = [];

    private int $next_id = 1;

    public function push(Job_Interface $job, int $delay = 0): int|false
    {
        $id = $this->next_id++;
        $job->set_id($id);
        $job->set_attempts(0);
        $this->jobs[$id] = ['job' => $job, 'available' => time() + $delay, 'reserved' => null];

        return $id;
    }

    /**
     * Injects a job that is already reserved and whose reservation is stale,
     * mimicking a worker that died mid-job. Used to exercise reclaim behaviour.
     */
    public function push_stale_reserved(Job_Interface $job): int
    {
        $id = (int) $this->push($job);
        $this->jobs[$id]['reserved'] = time() - ($this->reserve_timeout + 60);

        return $id;
    }

    public function push_many(array $jobs, int $delay = 0): int
    {
        $count = 0;
        foreach ($jobs as $job) {
            if (false !== $this->push($job, $delay)) {
                $count++;
            }
        }

        return $count;
    }

    public function pop(?string $queue = null): ?Job_Interface
    {
        $now = time();
        foreach ($this->jobs as $id => $entry) {
            if (null !== $entry['reserved'] || $entry['available'] > $now) {
                continue;
            }
            if (null !== $queue && $entry['job']->get_queue() !== $queue) {
                continue;
            }

            $job = $entry['job'];
            $job->set_attempts($job->get_attempts() + 1);
            // Reserve the job so it is not popped again until released or reclaimed.
            $this->jobs[$id]['reserved'] = $now;

            return $job;
        }

        return null;
    }

    public function delete(Job_Interface $job): bool
    {
        $id = $job->get_id();
        if (null === $id || ! isset($this->jobs[$id])) {
            return false;
        }

        unset($this->jobs[$id]);

        return true;
    }

    public function release(Job_Interface $job, int $delay = 0): bool
    {
        $id = $job->get_id();
        if (null === $id || ! isset($this->jobs[$id])) {
            return false;
        }

        $this->jobs[$id]['available'] = time() + $delay;
        $this->jobs[$id]['reserved'] = null;
        $this->released[] = ['id' => $id, 'delay' => $delay];

        return true;
    }

    public function reclaim(?string $queue = null): int
    {
        $this->reclaim_calls++;

        $now = time();
        $count = 0;
        foreach ($this->jobs as $id => $entry) {
            if (null === $entry['reserved'] || $entry['reserved'] > $now - $this->reserve_timeout) {
                continue;
            }
            if (null !== $queue && $entry['job']->get_queue() !== $queue) {
                continue;
            }

            $this->jobs[$id]['reserved'] = null;
            $count++;
        }

        return $count;
    }

    public function fail(Job_Interface $job, Throwable $exception): bool
    {
        $id = $job->get_id();
        if (null !== $id) {
            unset($this->jobs[$id]);
        }

        $this->failed[] = ['job' => $job, 'exception' => $exception];

        return true;
    }

    public function count(?string $queue = null): int
    {
        if (null === $queue) {
            return count($this->jobs);
        }

        $total = 0;
        foreach ($this->jobs as $entry) {
            if ($entry['job']->get_queue() === $queue) {
                $total++;
            }
        }

        return $total;
    }

    public function count_available(?string $queue = null): int
    {
        $now = time();
        $total = 0;
        foreach ($this->jobs as $entry) {
            if (null !== $entry['reserved'] || $entry['available'] > $now) {
                continue;
            }
            if (null !== $queue && $entry['job']->get_queue() !== $queue) {
                continue;
            }
            $total++;
        }

        return $total;
    }

    public function is_empty(?string $queue = null): bool
    {
        return 0 === $this->count($queue);
    }

    public function purge(?string $queue = null): int
    {
        if (null === $queue) {
            $removed = count($this->jobs);
            $this->jobs = [];

            return $removed;
        }

        $removed = 0;
        foreach ($this->jobs as $id => $entry) {
            if ($entry['job']->get_queue() === $queue) {
                unset($this->jobs[$id]);
                $removed++;
            }
        }

        return $removed;
    }
}
