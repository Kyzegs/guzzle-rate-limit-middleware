<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Store;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\AtomicReservationStoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Lock\RedisLock;
use Kyzegs\GuzzleRateLimitMiddleware\Lock\RedisLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Reservation;
use Kyzegs\GuzzleRateLimitMiddleware\Support\RedisConnection;
use Predis\ClientInterface;
use Redis;
use UnexpectedValueException;

/**
 * Cross-process store on Redis, through phpredis or Predis.
 *
 * Besides plain get/put it reserves bucket capacity in a single Lua script,
 * so the handler takes no lock around a bucket: reading the allowance, taking
 * a slot and claiming the probe of an unknown bucket happen as one step on
 * the server. The global and invalid-request budgets still go through the
 * lock factory; pair this with {@see RedisLockFactory} for those.
 *
 * Values are JSON written with SET ... EX, under the prefix plus a SHA-1 of the
 * key, as Psr16Store names them. A bucket's probe marker sits next to its
 * state, under the same name plus ":probe". On Redis Cluster, put a hash tag
 * in the prefix (e.g. "{grl}:") so the two share a slot.
 */
final class RedisStore implements AtomicReservationStoreInterface
{
    /**
     * How often a caller waiting on another caller's probe asks again. The
     * probe's response is a single round trip away, so poll briskly.
     */
    private const PROBE_POLL_SECONDS = 0.05;

    /**
     * The handler's reservation rules, as one atomic step.
     *
     * KEYS[1] bucket state (RateLimit::toArray() as JSON), KEYS[2] probe marker.
     * ARGV[1] the caller's now, ARGV[2] a probe token, ARGV[3] the probe marker
     * TTL in milliseconds, ARGV[4] the probe poll interval in seconds.
     *
     * Only `remaining` changes, and KEEPTTL (Redis 6.0+) leaves the expiry that
     * put() chose for the window. Delays are returned as strings because Redis
     * truncates Lua numbers to integers.
     */
    private const RESERVE_SCRIPT = <<<'LUA'
        local now = tonumber(ARGV[1])
        local raw = redis.call('GET', KEYS[1])

        if raw then
            local ok, state = pcall(cjson.decode, raw)

            if ok and type(state) == 'table' then
                local reset = tonumber(state['reset']) or 0

                if reset > now then
                    local remaining = tonumber(state['remaining']) or 0

                    if remaining > 0 then
                        state['remaining'] = remaining - 1
                        redis.call('SET', KEYS[1], cjson.encode(state), 'KEEPTTL')

                        return {'reserved'}
                    end

                    return {'wait', tostring(reset - now)}
                end
            end
        end

        if redis.call('SET', KEYS[2], ARGV[2], 'NX', 'PX', ARGV[3]) then
            return {'probe'}
        end

        local poll = tonumber(ARGV[4])
        local ttl = redis.call('PTTL', KEYS[2]) / 1000

        if ttl > 0 and ttl < poll then
            poll = ttl
        end

        return {'pending', tostring(poll)}
        LUA;

    private readonly RedisConnection $redis;

    /**
     * @param float $probeTtlSeconds How long a probe marker outlives a prober that
     *                               never reports back (a crashed process): longer
     *                               than a round trip, short enough that a lost
     *                               probe does not stall the bucket for long.
     */
    public function __construct(
        Redis|ClientInterface $redis,
        private readonly string $prefix = 'grl:',
        private readonly float $probeTtlSeconds = 10.0,
    ) {
        $this->redis = new RedisConnection($redis);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $raw = $this->redis->get($this->key($key));

        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(string $key, array $data, int $ttl): bool
    {
        // Redis rejects a zero or negative expiry; such a value is already gone.
        if ($ttl <= 0) {
            return $this->forget($key);
        }

        $payload = json_encode($data, JSON_PRESERVE_ZERO_FRACTION);

        if ($payload === false) {
            return false;
        }

        return $this->redis->set($this->key($key), $payload, $ttl);
    }

    public function forget(string $key): bool
    {
        $this->redis->delete($this->key($key));

        return true;
    }

    public function reserve(string $key, float $now): Reservation
    {
        $stateKey = $this->key($key);
        $probeKey = $stateKey . ':probe';
        $token = bin2hex(random_bytes(16));

        $reply = $this->redis->evaluate(self::RESERVE_SCRIPT, [$stateKey, $probeKey], [
            sprintf('%.6F', $now),
            $token,
            (string) max(1, (int) ceil($this->probeTtlSeconds * 1000)),
            sprintf('%.3F', self::PROBE_POLL_SECONDS),
        ]);

        [$outcome, $seconds] = (is_array($reply) ? array_values($reply) : []) + [null, '0'];

        return match ($outcome) {
            'reserved' => Reservation::reserved(),
            // The script has already taken the marker under this token; the
            // lock hands it to the caller to release once its response lands.
            'probe' => Reservation::probe(new RedisLock($this->redis, $key, $probeKey, $this->probeTtlSeconds, token: $token)),
            'wait' => Reservation::wait((float) $seconds),
            'pending' => Reservation::waitForProbe((float) $seconds),
            default => throw new UnexpectedValueException('Unexpected reply from the Redis reservation script.'),
        };
    }

    private function key(string $key): string
    {
        return $this->prefix . sha1($key);
    }
}
