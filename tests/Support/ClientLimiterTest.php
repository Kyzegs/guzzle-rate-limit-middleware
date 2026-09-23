<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Support;

use Closure;
use GuzzleHttp\Psr7\Request;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Limit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\LimitAlgorithm;
use Kyzegs\GuzzleRateLimitMiddleware\Config\LimitScope;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\LockFactoryInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\SleeperInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\StoreInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Exception\RateLimitDelayExceededException;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\ClientLimiter;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\FakeClock;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\HookedSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\RecordingSleeper;
use Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles\SpyLockFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ClientLimiterTest extends TestCase
{
    private FakeClock $clock;

    private RecordingSleeper $sleeper;

    private InMemoryStore $store;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1_000.0);
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->store = new InMemoryStore($this->clock);
    }

    public function test_fixed_window_allows_the_budget_then_waits_for_the_window_to_close(): void
    {
        $limiter = $this->limiter([Limit::perSecond(2, LimitAlgorithm::FixedWindow)]);
        $request = new Request('GET', 'https://api.test/users');

        // The window opens with the first request, not on a clock boundary.
        $this->clock->advance(0.3);

        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);
        $this->assertSame([], $this->sleeper->sleeps);

        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);

        $this->assertCount(2, $this->sleeper->sleeps);
        $this->assertEqualsWithDelta(1.0, $this->sleeper->sleeps[0], 0.001);
        $this->assertEqualsWithDelta(1.0, $this->sleeper->sleeps[1], 0.001);
    }

    public function test_fixed_window_starts_fresh_once_the_window_has_passed(): void
    {
        $limiter = $this->limiter([Limit::perSecond(2, LimitAlgorithm::FixedWindow)]);
        $request = new Request('GET', 'https://api.test/users');

        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);
        $this->clock->advance(1.0);
        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);

        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function test_sliding_window_counts_the_previous_window_by_its_overlap(): void
    {
        $limiter = $this->limiter([Limit::perMinute(10, LimitAlgorithm::SlidingWindow)]);
        $request = new Request('GET', 'https://api.test/users');

        for ($i = 0; $i < 10; $i++) {
            $this->reserve($limiter, $request);
        }

        // 33s into the next window, 4.5 of the previous ten still count, so
        // six more fit (4.5 + 5 is still within ten).
        $this->clock->advance(93.0);

        for ($i = 0; $i < 6; $i++) {
            $this->reserve($limiter, $request);
        }
        $this->assertSame([], $this->sleeper->sleeps);

        // The seventh waits until the previous share has slid down to 4.
        $this->reserve($limiter, $request);

        $this->assertCount(1, $this->sleeper->sleeps);
        $this->assertEqualsWithDelta(3.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_sliding_window_smooths_the_burst_at_a_window_edge(): void
    {
        $limiter = $this->limiter([Limit::perSecond(2, LimitAlgorithm::SlidingWindow)]);
        $request = new Request('GET', 'https://api.test/users');

        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);

        // A fixed window would let both through at the one-second boundary;
        // here the second waits until half of the previous window slid out.
        $this->assertCount(2, $this->sleeper->sleeps);
        $this->assertEqualsWithDelta(1.0, $this->sleeper->sleeps[0], 0.001);
        $this->assertEqualsWithDelta(0.5, $this->sleeper->sleeps[1], 0.001);
    }

    public function test_sliding_window_sustains_the_full_rate(): void
    {
        $limiter = $this->limiter([Limit::perSecond(1, LimitAlgorithm::SlidingWindow)]);
        $request = new Request('GET', 'https://api.test/users');

        for ($i = 0; $i < 5; $i++) {
            $this->reserve($limiter, $request);
        }

        // One per second, not one every other second while the previous
        // window's request is still sliding out.
        $this->assertCount(4, $this->sleeper->sleeps);
        $this->assertEqualsWithDelta(4.0, $this->sleeper->total(), 0.001);
    }

    public function test_sliding_window_forgets_the_previous_window_after_a_gap(): void
    {
        $limiter = $this->limiter([Limit::perSecond(2, LimitAlgorithm::SlidingWindow)]);
        $request = new Request('GET', 'https://api.test/users');

        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);
        $this->clock->advance(2.5);
        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);

        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function test_token_bucket_bursts_to_capacity_then_refills_continuously(): void
    {
        $limiter = $this->limiter([Limit::perSecond(2, LimitAlgorithm::TokenBucket)]);
        $request = new Request('GET', 'https://api.test/users');

        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);
        $this->assertSame([], $this->sleeper->sleeps);

        // Empty: one token takes half a second at two per second.
        $this->reserve($limiter, $request);

        // A quarter of a second refills half a token.
        $this->clock->advance(0.25);
        $this->reserve($limiter, $request);

        // A long idle refills to capacity and no further.
        $this->clock->advance(10.0);
        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);

        $this->assertCount(3, $this->sleeper->sleeps);
        $this->assertEqualsWithDelta(0.5, $this->sleeper->sleeps[0], 0.001);
        $this->assertEqualsWithDelta(0.25, $this->sleeper->sleeps[1], 0.001);
        $this->assertEqualsWithDelta(0.5, $this->sleeper->sleeps[2], 0.001);
    }

    public function test_a_request_waits_once_for_the_longest_of_its_limits(): void
    {
        $limiter = $this->limiter([
            Limit::perSecond(1, LimitAlgorithm::FixedWindow),
            Limit::perHour(1, LimitAlgorithm::FixedWindow),
        ]);
        $request = new Request('GET', 'https://api.test/users');

        $this->reserve($limiter, $request);
        $this->reserve($limiter, $request);

        $this->assertCount(1, $this->sleeper->sleeps, 'Both limits are spent; only the longer wait matters.');
        $this->assertEqualsWithDelta(3600.0, $this->sleeper->sleeps[0], 0.001);
    }

    public function test_waiting_on_one_limit_consumes_nothing_from_the_others(): void
    {
        $sleeper = new HookedSleeper($this->clock);
        $limiter = $this->limiter([
            Limit::perSecond(1, LimitAlgorithm::FixedWindow, name: 'burst'),
            Limit::perHour(1, LimitAlgorithm::FixedWindow, appliesTo: self::method('POST'), name: 'writes'),
        ], sleeper: $sleeper);
        $read = new Request('GET', 'https://api.test/messages');
        $write = new Request('POST', 'https://api.test/messages');

        $this->reserve($limiter, $write);
        $this->clock->advance(1.0);

        // While the second write waits out the hourly budget, a read that
        // only shares the per-second one must find it untouched.
        $sleeper->whileAsleep = fn () => $this->reserve($limiter, $read);
        $this->reserve($limiter, $write);

        $this->assertCount(1, $sleeper->sleeps, 'The read must not wait on a slot the waiting write never used.');
        $this->assertEqualsWithDelta(3599.0, $sleeper->sleeps[0], 0.001);
    }

    public function test_identity_scope_gives_each_credential_its_own_budget(): void
    {
        $limiter = $this->limiter([Limit::perSecond(1, LimitAlgorithm::FixedWindow, LimitScope::Identity)]);
        $request = new Request('GET', 'https://api.test/users');

        $this->reserve($limiter, $request, 'token-a');
        $this->reserve($limiter, $request, 'token-b');
        $this->assertSame([], $this->sleeper->sleeps);

        $this->reserve($limiter, new Request('GET', 'https://other.test/posts'), 'token-a');
        $this->assertCount(1, $this->sleeper->sleeps);
    }

    public function test_everything_scope_shares_one_budget_across_credentials(): void
    {
        $limiter = $this->limiter([Limit::perSecond(1, LimitAlgorithm::FixedWindow, LimitScope::Everything)]);

        $this->reserve($limiter, new Request('GET', 'https://api.test/users'), 'token-a');
        $this->reserve($limiter, new Request('GET', 'https://other.test/posts'), 'token-b');

        $this->assertCount(1, $this->sleeper->sleeps);
    }

    public function test_host_scope_separates_hosts_but_not_their_routes(): void
    {
        $limiter = $this->limiter([Limit::perSecond(1, LimitAlgorithm::FixedWindow, LimitScope::Host)]);

        $this->reserve($limiter, new Request('GET', 'https://one.test/users'));
        $this->reserve($limiter, new Request('GET', 'https://two.test/users'));
        $this->reserve($limiter, new Request('GET', 'https://one.test/users'), 'token-b');
        $this->assertSame([], $this->sleeper->sleeps);

        $this->reserve($limiter, new Request('POST', 'https://one.test/posts'));
        $this->assertCount(1, $this->sleeper->sleeps);
    }

    public function test_route_scope_follows_the_bucket_resolver(): void
    {
        $limiter = $this->limiter([Limit::perSecond(1, LimitAlgorithm::FixedWindow, LimitScope::Route)]);

        $this->reserve($limiter, new Request('GET', 'https://api.test/users/1'));
        $this->reserve($limiter, new Request('GET', 'https://api.test/posts/1'));
        $this->assertSame([], $this->sleeper->sleeps);

        // The resolver collapses ids, so another user is the same route.
        $this->reserve($limiter, new Request('GET', 'https://api.test/users/2'));
        $this->assertCount(1, $this->sleeper->sleeps);
    }

    public function test_limits_only_count_the_requests_they_apply_to(): void
    {
        $limiter = $this->limiter([
            Limit::perSecond(1, LimitAlgorithm::FixedWindow, appliesTo: self::method('POST')),
        ]);
        $read = new Request('GET', 'https://api.test/messages');
        $write = new Request('POST', 'https://api.test/messages');

        $this->reserve($limiter, $read);
        $this->reserve($limiter, $read);
        $this->reserve($limiter, $write);
        $this->reserve($limiter, $read);
        $this->assertSame([], $this->sleeper->sleeps);

        $this->reserve($limiter, $write);
        $this->assertCount(1, $this->sleeper->sleeps);
    }

    public function test_names_keep_otherwise_identical_limits_apart(): void
    {
        $limiter = $this->limiter([
            Limit::perSecond(1, LimitAlgorithm::FixedWindow, appliesTo: self::method('GET'), name: 'reads'),
            Limit::perSecond(1, LimitAlgorithm::FixedWindow, appliesTo: self::method('POST'), name: 'writes'),
        ]);

        $this->reserve($limiter, new Request('GET', 'https://api.test/messages'));
        $this->reserve($limiter, new Request('POST', 'https://api.test/messages'));

        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function test_identical_limits_are_one_budget_locked_once(): void
    {
        $locks = new SpyLockFactory();
        $limiter = $this->limiter([
            Limit::perSecond(2, LimitAlgorithm::FixedWindow),
            Limit::perSecond(2, LimitAlgorithm::FixedWindow),
        ], $locks);
        $request = new Request('GET', 'https://api.test/users');

        $this->reserve($limiter, $request);
        $this->assertCount(1, $locks->keys, 'A non-reentrant lock taken twice would deadlock.');

        $this->reserve($limiter, $request);
        $this->assertSame([], $this->sleeper->sleeps, 'The budget must not be consumed twice per request.');

        $this->reserve($limiter, $request);
        $this->assertCount(1, $this->sleeper->sleeps);
    }

    public function test_locks_every_limit_in_key_order_under_opaque_keys(): void
    {
        $locks = new SpyLockFactory();
        $limiter = $this->limiter([
            Limit::perHour(5000, name: 'hourly'),
            Limit::perSecond(10, name: 'burst'),
            Limit::perMinute(100, scope: LimitScope::Host),
            Limit::perMinute(30, scope: LimitScope::Route),
        ], $locks);

        $this->reserve($limiter, new Request('GET', 'https://api.test/users/1'), 'Bot secret-token');

        $sorted = $locks->keys;
        sort($sorted, SORT_STRING);

        $this->assertCount(4, $locks->keys);
        $this->assertSame($sorted, $locks->keys, 'Locks must be taken in a stable order to avoid deadlocks.');
        $this->assertSame(4, $locks->acquired);
        $this->assertSame(4, $locks->released);

        foreach ($locks->keys as $key) {
            $this->assertMatchesRegularExpression('/^limit:[0-9a-f]{64}$/', $key);
        }
    }

    public function test_releases_every_lock_when_the_wait_is_refused(): void
    {
        $locks = new SpyLockFactory();
        $limiter = new ClientLimiter(
            [Limit::perSecond(10, name: 'burst'), Limit::perHour(1, name: 'hourly')],
            $this->store,
            $locks,
            $this->clock,
            $this->sleeper,
            static function (float $delay): float {
                throw new RateLimitDelayExceededException($delay);
            },
        );
        $request = new Request('GET', 'https://api.test/users');

        $this->reserve($limiter, $request);

        try {
            $this->reserve($limiter, $request);
            $this->fail('The hourly wait should have been refused.');
        } catch (RateLimitDelayExceededException) {
        }

        $this->assertSame(4, $locks->acquired);
        $this->assertSame(4, $locks->released);
        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function test_state_expires_just_after_it_stops_mattering(): void
    {
        $store = $this->recordingStore();
        $request = new Request('GET', 'https://api.test/users');

        foreach (LimitAlgorithm::cases() as $algorithm) {
            $limiter = new ClientLimiter(
                [Limit::perMinute(10, $algorithm)],
                $store,
                new NullLockFactory(),
                $this->clock,
                $this->sleeper,
                static fn (float $delay): float => $delay,
            );

            $this->reserve($limiter, $request);
        }

        $this->assertSame([
            61,  // fixed window: until it closes
            121, // sliding window: its count still weighs on the next window
            7,   // token bucket: until the one spent token has refilled (60s / 10)
        ], $store->ttls);
    }

    /**
     * @param list<Limit> $limits
     */
    private function limiter(array $limits, ?LockFactoryInterface $locks = null, ?SleeperInterface $sleeper = null): ClientLimiter
    {
        return new ClientLimiter(
            $limits,
            $this->store,
            $locks ?? new NullLockFactory(),
            $this->clock,
            $sleeper ?? $this->sleeper,
            static fn (float $delay): float => $delay,
        );
    }

    /**
     * Reserve as the handler does: the route key is the identity-prefixed
     * bucket the default resolver computes.
     */
    private function reserve(ClientLimiter $limiter, RequestInterface $request, string $identity = 'token-a'): void
    {
        $limiter->reserve($request, $identity, $identity . ':' . (new DefaultBucketResolver())->resolve($request));
    }

    /**
     * @return Closure(RequestInterface): bool
     */
    private static function method(string $method): Closure
    {
        return static fn (RequestInterface $request): bool => $request->getMethod() === $method;
    }

    /**
     * @return StoreInterface&object{ttls: list<int>}
     */
    private function recordingStore(): StoreInterface
    {
        return new class ($this->store) implements StoreInterface {
            /** @var list<int> */
            public array $ttls = [];

            public function __construct(private readonly StoreInterface $inner)
            {
            }

            public function get(string $key): ?array
            {
                return $this->inner->get($key);
            }

            public function put(string $key, array $data, int $ttl): bool
            {
                $this->ttls[] = $ttl;

                return $this->inner->put($key, $data, $ttl);
            }

            public function forget(string $key): bool
            {
                return $this->inner->forget($key);
            }
        };
    }
}
