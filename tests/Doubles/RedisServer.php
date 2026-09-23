<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use PHPUnit\Framework\Assert;
use Predis\Client;
use Predis\ClientInterface;
use Redis;
use Throwable;

/**
 * Connections to the Redis the Redis-backed tests run against, at
 * GRL_REDIS_HOST:GRL_REDIS_PORT (127.0.0.1:6379 by default). A test that asks
 * for one is skipped when that Redis isn't reachable, so the suite still
 * passes without it.
 *
 * Every test works under its own random key prefix, and clean-up deletes
 * only the keys under those prefixes, never the whole database.
 */
final class RedisServer
{
    private static ?string $unreachable = null;

    /** @var list<array{Redis|ClientInterface, string}> */
    private static array $prefixes = [];

    /**
     * One entry per supported client, for data providers.
     *
     * @return array<string, array{callable(): (Redis|ClientInterface)}>
     */
    public static function clients(): array
    {
        return [
            'phpredis' => [static fn (): Redis|ClientInterface => self::phpredis()],
            'predis' => [static fn (): Redis|ClientInterface => self::predis()],
        ];
    }

    public static function host(): string
    {
        $host = getenv('GRL_REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    public static function port(): int
    {
        $port = getenv('GRL_REDIS_PORT');

        return is_string($port) && $port !== '' ? (int) $port : 6379;
    }

    public static function phpredis(): Redis
    {
        if (! extension_loaded('redis')) {
            Assert::markTestSkipped('The phpredis extension is not loaded.');
        }

        self::skipIfUnreachable();

        try {
            $redis = new Redis();
            $redis->connect(self::host(), self::port(), 1.0);
            $redis->ping();
        } catch (Throwable $e) {
            self::unreachable($e);
        }

        return $redis;
    }

    public static function predis(): ClientInterface
    {
        self::skipIfUnreachable();

        try {
            $client = new Client(['host' => self::host(), 'port' => self::port(), 'timeout' => 1.0]);
            $client->ping();
        } catch (Throwable $e) {
            self::unreachable($e);
        }

        return $client;
    }

    /**
     * A fresh key prefix on $client, deleted by {@see cleanUp()}.
     */
    public static function prefix(Redis|ClientInterface $client): string
    {
        $prefix = 'grl-test:' . bin2hex(random_bytes(6)) . ':';
        self::$prefixes[] = [$client, $prefix];

        return $prefix;
    }

    /**
     * Delete every key under the prefixes handed out so far.
     */
    public static function cleanUp(): void
    {
        foreach (self::$prefixes as [$client, $prefix]) {
            $keys = $client->keys($prefix . '*');

            if (is_array($keys) && $keys !== []) {
                $client->del($keys);
            }
        }

        self::$prefixes = [];
    }

    private static function skipIfUnreachable(): void
    {
        if (self::$unreachable !== null) {
            Assert::markTestSkipped(self::$unreachable);
        }
    }

    private static function unreachable(Throwable $e): never
    {
        self::$unreachable = sprintf('Redis is not reachable at %s:%d (%s).', self::host(), self::port(), $e->getMessage());

        Assert::markTestSkipped(self::$unreachable);
    }
}
