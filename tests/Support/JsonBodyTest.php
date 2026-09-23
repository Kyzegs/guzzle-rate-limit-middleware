<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Support;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Kyzegs\GuzzleRateLimitMiddleware\Support\JsonBody;
use PHPUnit\Framework\TestCase;

final class JsonBodyTest extends TestCase
{
    public function test_decodes_an_object_and_restores_the_position(): void
    {
        $response = new Response(429, [], '{"retry_after":1.5}');
        $response->getBody()->seek(3);

        $this->assertSame(['retry_after' => 1.5], JsonBody::decode($response));
        $this->assertSame(3, $response->getBody()->tell());
    }

    public function test_returns_null_for_non_json(): void
    {
        $this->assertNull(JsonBody::decode(new Response(429, [], 'Too Many Requests')));
        $this->assertNull(JsonBody::decode(new Response(429, [], '"a string"')));
    }

    public function test_does_not_read_a_non_seekable_body(): void
    {
        $response = new Response(429, [], new NoSeekStream(Utils::streamFor('{"global":true}')));

        $this->assertNull(JsonBody::decode($response));
        $this->assertSame('{"global":true}', (string) $response->getBody());
    }
}
