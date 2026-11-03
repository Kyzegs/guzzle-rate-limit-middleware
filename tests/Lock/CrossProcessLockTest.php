<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Lock;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Lock\FlockLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Store\FilesystemStore;
use PHPUnit\Framework\TestCase;

/**
 * Runs real PHP processes against one lock directory: a lock that only ever
 * meets itself inside one process proves little about the processes it exists
 * to coordinate.
 */
final class CrossProcessLockTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/';

    private const READ_TIMEOUT_SECONDS = 10;

    private string $directory = '';

    /** @var list<array{process: resource, stdin: resource, stdout: resource, log: string}> */
    private array $workers = [];

    protected function setUp(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is unavailable, so no worker processes can be started.');
        }

        $this->directory = sys_get_temp_dir() . '/grl-process-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/locks', 0775, true);
        mkdir($this->directory . '/store');
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            fclose($worker['stdin']);
            fclose($worker['stdout']);
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }

        $this->workers = [];

        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->removeDirectory($this->directory);
        }
    }

    public function test_flock_lock_serialises_read_modify_write_across_processes(): void
    {
        $counter = $this->directory . '/counter';
        file_put_contents($counter, '0');

        for ($i = 0; $i < 4; $i++) {
            $this->spawn('counter_worker.php', $this->directory . '/locks', $counter, '250');
        }

        $this->startTogether();

        foreach (array_keys($this->workers) as $worker) {
            $this->assertSame('done', $this->readLine($worker));
        }

        $this->finish();

        $this->assertSame('1000', file_get_contents($counter), 'Every increment must survive.');
    }

    public function test_workers_sharing_a_bucket_never_send_more_than_its_allowance(): void
    {
        $url = 'https://api.test/users';
        $reset = (string) (time() + 60);
        $store = $this->directory . '/store';
        $locks = $this->directory . '/locks';

        // Discover the bucket the way a first request would: three requests
        // left in a window that resets a minute from now.
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [
                'X-RateLimit-Limit' => '3',
                'X-RateLimit-Remaining' => '3',
                'X-RateLimit-Reset' => $reset,
            ]),
        ]));
        $stack->push(RateLimitMiddleware::create(
            store: new FilesystemStore($store),
            lockFactory: new FlockLockFactory($locks),
        ));
        (new Client(['handler' => $stack]))->get($url);

        for ($i = 0; $i < 8; $i++) {
            $this->spawn('burst_worker.php', $store, $locks, $url, $reset);
        }

        $this->startTogether();

        $outcomes = [];
        foreach (array_keys($this->workers) as $worker) {
            $outcomes[$worker] = $this->readLine($worker);
        }

        // Every worker has decided; only now let the sent requests complete.
        foreach ($outcomes as $worker => $outcome) {
            if ($outcome === 'sent') {
                $this->send($worker, 'respond');
            }
        }

        $this->finish();

        $counts = array_count_values($outcomes);
        $this->assertSame(3, $counts['sent'] ?? 0, 'Only the remaining allowance may go out without waiting.');
        $this->assertSame(5, $counts['waited'] ?? 0);
    }

    private function spawn(string $script, string ...$arguments): void
    {
        $log = $this->directory . '/worker-' . count($this->workers) . '.log';

        $process = proc_open(
            [PHP_BINARY, self::FIXTURES . $script, ...array_values($arguments)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
            $pipes,
        );

        if ($process === false) {
            $this->fail(sprintf('Could not start %s.', $script));
        }

        stream_set_timeout($pipes[1], self::READ_TIMEOUT_SECONDS);

        $this->workers[] = ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'log' => $log];
    }

    /**
     * Hold every worker at the start line until all have booted, then let
     * them go together; otherwise the first could finish before the last one
     * has even started.
     */
    private function startTogether(): void
    {
        foreach (array_keys($this->workers) as $worker) {
            $this->assertSame('ready', $this->readLine($worker));
        }

        foreach (array_keys($this->workers) as $worker) {
            $this->send($worker, 'go');
        }
    }

    private function readLine(int $worker): string
    {
        $line = fgets($this->workers[$worker]['stdout']);

        if ($line === false) {
            $this->fail(sprintf(
                'Worker %d stopped responding. Its output: %s',
                $worker,
                (string) file_get_contents($this->workers[$worker]['log']),
            ));
        }

        return rtrim($line, "\n");
    }

    private function send(int $worker, string $line): void
    {
        fwrite($this->workers[$worker]['stdin'], $line . "\n");
        fflush($this->workers[$worker]['stdin']);
    }

    /**
     * Let every worker run to completion and fail on any that crashed.
     */
    private function finish(): void
    {
        $workers = $this->workers;
        $this->workers = [];

        foreach ($workers as $index => $worker) {
            fclose($worker['stdin']);
            stream_get_contents($worker['stdout']);
            fclose($worker['stdout']);

            $this->assertSame(0, proc_close($worker['process']), sprintf(
                'Worker %d failed. Its output: %s',
                $index,
                (string) file_get_contents($worker['log']),
            ));
        }
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDirectory($entry) : unlink($entry);
        }

        rmdir($path);
    }
}
