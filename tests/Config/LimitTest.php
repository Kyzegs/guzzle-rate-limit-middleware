<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Config;

use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Limit;
use Kyzegs\GuzzleRateLimitMiddleware\Config\LimitAlgorithm;
use Kyzegs\GuzzleRateLimitMiddleware\Config\LimitScope;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class LimitTest extends TestCase
{
    public function test_defaults_to_a_sliding_window_per_identity(): void
    {
        $limit = new Limit(10, 1.0);

        $this->assertSame(LimitAlgorithm::SlidingWindow, $limit->algorithm);
        $this->assertSame(LimitScope::Identity, $limit->scope);
        $this->assertNull($limit->appliesTo);
        $this->assertSame('', $limit->name);
    }

    public function test_named_constructors_set_the_window(): void
    {
        $this->assertSame(1.0, Limit::perSecond(10)->windowSeconds);
        $this->assertSame(60.0, Limit::perMinute(600)->windowSeconds);
        $this->assertSame(3600.0, Limit::perHour(5000)->windowSeconds);
        $this->assertSame(86400.0, Limit::perDay(100_000)->windowSeconds);
        $this->assertSame(5000, Limit::perHour(5000)->maxRequests);
    }

    public function test_named_constructors_pass_the_remaining_settings_through(): void
    {
        $appliesTo = static fn (RequestInterface $request): bool => true;

        $limit = Limit::perMinute(30, LimitAlgorithm::TokenBucket, LimitScope::Route, $appliesTo, 'search');

        $this->assertSame(LimitAlgorithm::TokenBucket, $limit->algorithm);
        $this->assertSame(LimitScope::Route, $limit->scope);
        $this->assertSame($appliesTo, $limit->appliesTo);
        $this->assertSame('search', $limit->name);
    }

    #[DataProvider('invalidMaxRequests')]
    public function test_rejects_a_budget_without_requests(int $maxRequests): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Limit($maxRequests, 1.0);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidMaxRequests(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    #[DataProvider('invalidWindows')]
    public function test_rejects_a_window_that_is_not_a_positive_duration(float $windowSeconds): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Limit(10, $windowSeconds);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidWindows(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    public function test_matches_every_request_without_a_filter(): void
    {
        $this->assertTrue(Limit::perSecond(1)->matches(new Request('DELETE', 'https://api.test/anything')));
    }

    public function test_matches_only_what_the_filter_accepts(): void
    {
        $limit = Limit::perSecond(1, appliesTo: static fn (RequestInterface $request): bool => $request->getMethod() === 'POST');

        $this->assertTrue($limit->matches(new Request('POST', 'https://api.test/messages')));
        $this->assertFalse($limit->matches(new Request('GET', 'https://api.test/messages')));
    }

    public function test_options_default_to_no_limits(): void
    {
        $this->assertSame([], (new Options())->limits);
    }

    public function test_options_reject_anything_but_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new Options(limits: [Limit::perSecond(1), 10]);
    }
}
