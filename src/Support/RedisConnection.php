<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use Predis\ClientInterface;
use Predis\Response\ErrorInterface;
use Predis\Response\Status;
use Redis;
use RuntimeException;

/**
 * The few Redis commands RedisStore and RedisLockFactory need, over either
 * phpredis or Predis, so neither class has to care which client it was given.
 *
 * phpredis commands go through rawCommand() so that a serializer or
 * compression option on a shared connection cannot transform the values: the
 * Lua scripts must read exactly the JSON that put() wrote. Keys still get the
 * connection's OPT_PREFIX via _prefix(), as every other command would, and
 * Predis applies its own key prefix to scripts as well.
 *
 * @internal
 */
final class RedisConnection
{
    public function __construct(private readonly Redis|ClientInterface $client)
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->client instanceof Redis
            ? $this->client->rawCommand('GET', $this->client->_prefix($key))
            : $this->client->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * SET with an expiry in seconds.
     */
    public function set(string $key, string $value, int $ttlSeconds): bool
    {
        return $this->ok($this->client instanceof Redis
            ? $this->client->rawCommand('SET', $this->client->_prefix($key), $value, 'EX', $ttlSeconds)
            : $this->client->set($key, $value, 'EX', $ttlSeconds));
    }

    /**
     * SET only when the key does not exist yet, with an expiry in milliseconds.
     */
    public function setIfAbsent(string $key, string $value, int $ttlMilliseconds): bool
    {
        return $this->ok($this->client instanceof Redis
            ? $this->client->rawCommand('SET', $this->client->_prefix($key), $value, 'NX', 'PX', $ttlMilliseconds)
            : $this->client->set($key, $value, 'PX', $ttlMilliseconds, 'NX'));
    }

    public function delete(string $key): void
    {
        if ($this->client instanceof Redis) {
            $this->client->rawCommand('DEL', $this->client->_prefix($key));

            return;
        }

        $this->client->del($key);
    }

    /**
     * Run a Lua script. Script errors are thrown rather than returned, so a
     * broken reply can never be mistaken for an answer.
     *
     * @param list<string> $keys
     * @param list<string> $arguments
     */
    public function evaluate(string $script, array $keys, array $arguments): mixed
    {
        if ($this->client instanceof Redis) {
            $keys = array_map($this->client->_prefix(...), $keys);
            $result = $this->client->rawCommand('EVAL', $script, count($keys), ...$keys, ...$arguments);

            // Our scripts never return nil, so false can only be an error reply.
            if ($result === false) {
                $error = $this->client->getLastError();
                $this->client->clearLastError();

                throw new RuntimeException('Redis script failed: ' . ($error ?? 'unknown error'));
            }

            return $result;
        }

        $result = $this->client->eval($script, count($keys), ...$keys, ...$arguments);

        if ($result instanceof ErrorInterface) {
            throw new RuntimeException('Redis script failed: ' . $result->getMessage());
        }

        return $result;
    }

    /**
     * phpredis answers a successful SET with true, Predis with an OK status;
     * a SET NX that lost comes back as false or null.
     */
    private function ok(mixed $reply): bool
    {
        return $reply === true || ($reply instanceof Status && $reply->getPayload() === 'OK');
    }
}
