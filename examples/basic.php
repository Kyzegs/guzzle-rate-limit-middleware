<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimitMiddleware;

// In real usage you'd omit the MockHandler and let Guzzle make real requests.
$mock = new MockHandler([
    new Response(200, ['X-RateLimit-Remaining' => '4', 'X-RateLimit-Reset' => (string) (time() + 30)]),
    new Response(200, ['X-RateLimit-Remaining' => '3', 'X-RateLimit-Reset' => (string) (time() + 30)]),
]);

$stack = HandlerStack::create($mock);

// Preset for GitHub's X-RateLimit-* headers. Use new RateLimitMiddleware() for
// the generic defaults, or ::discord()/::twitter()/::ietf() for other APIs.
$stack->push(RateLimitMiddleware::github());

$client = new Client(['handler' => $stack]);

echo $client->get('https://api.github.com/user')->getStatusCode(), PHP_EOL;
echo $client->get('https://api.github.com/user')->getStatusCode(), PHP_EOL;
