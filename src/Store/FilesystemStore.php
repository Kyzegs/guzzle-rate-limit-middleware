<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Store;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Support\SystemClock;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cross-process store backed by JSON files, one per key. Writes are atomic
 * (temp file + rename) so concurrent readers never see a half-written payload.
 * Suitable for sharing rate-limit state between separate CLI invocations or
 * web requests on the same host.
 *
 * A file is otherwise only removed when its own key is read after expiring,
 * which never happens to most per-channel or per-resource buckets, so about one
 * write in $sweepEvery also clears out everything that has expired. A short-lived
 * process can't keep a count, hence chance rather than a counter.
 */
final class FilesystemStore implements StoreInterface
{
    private readonly string $directory;

    public function __construct(
        ?string $directory = null,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly int $sweepEvery = 1000,
    ) {
        if ($sweepEvery < 1) {
            throw new InvalidArgumentException(sprintf('FilesystemStore::$sweepEvery must be at least 1, got %d.', $sweepEvery));
        }

        $this->directory = $directory ?? sys_get_temp_dir() . '/guzzle-rate-limit';

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create cache directory "%s".', $this->directory));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $path = $this->path($key);

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $payload = json_decode($contents, true);
        if (! is_array($payload) || ! is_numeric($payload['expires'] ?? null) || ! is_array($payload['data'] ?? null)) {
            return null;
        }

        if ($payload['expires'] <= $this->clock->now()) {
            @unlink($path);

            return null;
        }

        return $payload['data'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(string $key, array $data, int $ttl): bool
    {
        $payload = json_encode([
            'expires' => $this->clock->now() + $ttl,
            'data' => $data,
        ]);

        if ($payload === false) {
            return false;
        }

        $path = $this->path($key);
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        if (@file_put_contents($temp, $payload) === false) {
            return false;
        }

        if (! $this->replace($temp, $path)) {
            @unlink($temp);

            return false;
        }

        if (random_int(1, $this->sweepEvery) === 1) {
            $this->sweep();
        }

        return true;
    }

    public function forget(string $key): bool
    {
        $path = $this->path($key);

        if (is_file($path)) {
            return @unlink($path);
        }

        return true;
    }

    /**
     * Windows refuses to replace a file another process has open, which a
     * concurrent read does for a moment, so give that a few chances to pass.
     */
    private function replace(string $temp, string $path): bool
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($temp, $path)) {
                return true;
            }

            usleep(1000);
        }

        return false;
    }

    /**
     * Racing a writer can at worst delete a value just refreshed, which only
     * means relearning that bucket. Temp files have no expiry of their own; one
     * still here an hour on belongs to a process that died mid-write.
     */
    private function sweep(): void
    {
        // scandir, as glob would read brackets in the directory's name as a pattern.
        foreach (scandir($this->directory) ?: [] as $name) {
            $file = $this->directory . '/' . $name;

            if (str_ends_with($name, '.json')) {
                $contents = @file_get_contents($file);
                $payload = $contents === false ? null : json_decode($contents, true);

                if (is_array($payload) && is_numeric($payload['expires'] ?? null) && $payload['expires'] <= $this->clock->now()) {
                    @unlink($file);
                }
            } elseif (str_ends_with($name, '.tmp') && @filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.json';
    }
}
