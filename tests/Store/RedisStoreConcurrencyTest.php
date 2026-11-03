<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Store;

use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Kyzegs\GuzzleRateLimitMiddleware\Store\RedisStore;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RedisServer;
use PHPUnit\Framework\TestCase;

/**
 * Separate PHP processes racing for one bucket. Nothing but the store's
 * script keeps them from over-spending it: there is no lock anywhere.
 */
final class RedisStoreConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private const NOW = 1_000_000.0;

    protected function setUp(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available.');
        }
    }

    protected function tearDown(): void
    {
        RedisServer::cleanUp();
    }

    public function test_racing_processes_never_take_more_slots_than_remain(): void
    {
        [$store, $prefix] = $this->store();
        $remaining = 200;
        $store->put('bucket', (new RateLimit(limit: 250, remaining: $remaining, reset: self::NOW + 60.0))->toArray(), 120);

        $taken = array_map('intval', $this->race($prefix, 'drain'));

        $this->assertSame($remaining, array_sum($taken), sprintf('Slots taken per worker: %s', implode(', ', $taken)));

        $stored = $store->get('bucket');
        $this->assertNotNull($stored);
        $this->assertSame(0, RateLimit::fromArray($stored)->remaining);
    }

    public function test_racing_processes_on_an_unknown_bucket_send_a_single_probe(): void
    {
        [, $prefix] = $this->store();

        $outcomes = $this->race($prefix, 'once');

        $this->assertCount(1, array_keys($outcomes, 'probe', true), implode(', ', $outcomes));
        $this->assertCount(self::WORKERS - 1, array_keys($outcomes, 'pending', true), implode(', ', $outcomes));
    }

    /**
     * @return array{RedisStore, string}
     */
    private function store(): array
    {
        $redis = extension_loaded('redis') ? RedisServer::phpredis() : RedisServer::predis();
        $prefix = RedisServer::prefix($redis);

        return [new RedisStore($redis, $prefix), $prefix];
    }

    /**
     * Start the workers, release them at once, and collect what each printed.
     *
     * @return list<string>
     */
    private function race(string $prefix, string $mode): array
    {
        $workers = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            // Mix both clients when phpredis is there, to show they agree.
            $client = extension_loaded('redis') && $i % 2 === 0 ? 'phpredis' : 'predis';
            $pipes = [];
            $process = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/Fixtures/reserve_worker.php',
                    $client,
                    RedisServer::host(),
                    (string) RedisServer::port(),
                    $prefix,
                    'bucket',
                    sprintf('%.6F', self::NOW),
                    $mode,
                ],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process);
            $workers[] = [$process, $pipes];
        }

        foreach ($workers as [, $pipes]) {
            if (fgets($pipes[1]) !== "ready\n") {
                // Only now is stderr safe to drain: the worker has given up.
                $this->fail('Worker failed to start: ' . stream_get_contents($pipes[2]));
            }
        }

        foreach ($workers as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }

        $outputs = [];

        foreach ($workers as [$process, $pipes]) {
            $output = trim((string) stream_get_contents($pipes[1]));
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertSame(0, proc_close($process), 'Worker failed: ' . $errors);
            $outputs[] = $output;
        }

        return $outputs;
    }
}
