<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Headers;
use Kyzegs\GuzzleRateLimitMiddleware\Config\Options;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\HandlerInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Handler\RateLimitHandler;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Store\InMemoryStore;
use Kyzegs\GuzzleRateLimitMiddleware\Support\NullLockFactory;
use Kyzegs\GuzzleRateLimitMiddleware\Support\SystemClock;
use Kyzegs\GuzzleRateLimitMiddleware\Support\UsleepSleeper;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * A custom handler that decorates the built-in RateLimitHandler with timing.
 * Any HandlerInterface can be passed to the middleware via the `handler:` arg.
 */
final class TimingHandler implements HandlerInterface
{
    public function __construct(private readonly HandlerInterface $inner)
    {
    }

    public function handle(callable $handler, RequestInterface $request, array $options): PromiseInterface
    {
        $start = microtime(true);

        // The inner handler returns before the transfer completes, so time it
        // from the promise rather than around the call.
        return Create::promiseFor($this->inner->handle($handler, $request, $options))->then(
            static function (ResponseInterface $response) use ($request, $start): ResponseInterface {
                fprintf(STDERR, "%s took %.3fs\n", $request->getUri(), microtime(true) - $start);

                return $response;
            },
        );
    }
}

$clock = new SystemClock();

$inner = new RateLimitHandler(
    new Options(),
    Headers::github(),
    new InMemoryStore($clock),
    new NullLogger(),
    new DefaultBucketResolver(),
    new NullLockFactory(),
    $clock,
    new UsleepSleeper(),
);

$middleware = new RateLimitMiddleware(handler: new TimingHandler($inner));

$mock = new MockHandler([new Response(200, ['X-RateLimit-Remaining' => '5'])]);
$stack = HandlerStack::create($mock);
$stack->push($middleware);

(new Client(['handler' => $stack]))->get('https://api.github.com/user');
