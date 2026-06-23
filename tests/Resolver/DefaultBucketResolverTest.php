<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Resolver;

use GuzzleHttp\Psr7\Request;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DefaultBucketResolver;
use PHPUnit\Framework\TestCase;

final class DefaultBucketResolverTest extends TestCase
{
    public function test_numeric_ids_collapse_to_same_bucket(): void
    {
        $resolver = new DefaultBucketResolver();

        $a = $resolver->resolve(new Request('GET', 'https://api.test/users/1/posts'));
        $b = $resolver->resolve(new Request('GET', 'https://api.test/users/2/posts'));

        $this->assertSame($a, $b);
        $this->assertSame('GET api.test/users/{id}/posts', $a);
    }

    public function test_uuids_collapse_to_same_bucket(): void
    {
        $resolver = new DefaultBucketResolver();

        $a = $resolver->resolve(new Request('GET', 'https://api.test/users/550e8400-e29b-41d4-a716-446655440000'));
        $b = $resolver->resolve(new Request('GET', 'https://api.test/users/f47ac10b-58cc-4372-a567-0e02b2c3d479'));

        $this->assertSame($a, $b);
        $this->assertSame('GET api.test/users/{id}', $a);
    }

    public function test_long_hex_tokens_collapse_to_same_bucket(): void
    {
        $resolver = new DefaultBucketResolver();

        $a = $resolver->resolve(new Request('GET', 'https://api.test/objects/0123456789abcdef'));
        $b = $resolver->resolve(new Request('GET', 'https://api.test/objects/fedcba98765432100123456789abcdef'));

        $this->assertSame($a, $b);
        $this->assertSame('GET api.test/objects/{id}', $a);
    }

    public function test_human_slugs_are_left_literal(): void
    {
        $resolver = new DefaultBucketResolver();

        $a = $resolver->resolve(new Request('GET', 'https://api.test/repos/octocat/hello-world'));
        $b = $resolver->resolve(new Request('GET', 'https://api.test/repos/torvalds/linux'));

        $this->assertNotSame($a, $b);
        $this->assertSame('GET api.test/repos/octocat/hello-world', $a);
    }

    public function test_method_and_host_are_part_of_the_key(): void
    {
        $resolver = new DefaultBucketResolver();

        $get = $resolver->resolve(new Request('GET', 'https://api.test/users/1'));
        $post = $resolver->resolve(new Request('POST', 'https://api.test/users/1'));

        $this->assertNotSame($get, $post);
    }
}
