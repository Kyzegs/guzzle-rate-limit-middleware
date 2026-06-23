<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Store;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Support\SystemClock;
use RuntimeException;

/**
 * Cross-process store backed by JSON files, one per key. Writes are atomic
 * (temp file + rename) so concurrent readers never see a half-written payload.
 * Suitable for sharing rate-limit state between separate CLI invocations or
 * web requests on the same host.
 */
final class FilesystemStore implements StoreInterface
{
    private readonly string $directory;

    public function __construct(?string $directory = null, private readonly ClockInterface $clock = new SystemClock())
    {
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
        if (! is_array($payload) || ! isset($payload['expires'], $payload['data'])) {
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

        if (@file_put_contents($temp, $payload, LOCK_EX) === false) {
            return false;
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);

            return false;
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

    private function path(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.json';
    }
}
