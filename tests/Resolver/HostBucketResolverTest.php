<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Resolver;

use GuzzleHttp\Psr7\Request;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\HostBucketResolver;
use PHPUnit\Framework\TestCase;

final class HostBucketResolverTest extends TestCase
{
    public function test_every_route_on_a_host_shares_a_bucket(): void
    {
        $resolver = new HostBucketResolver();

        $products = $resolver->resolve(new Request('GET', 'https://shop.myshopify.com/admin/api/2024-10/products.json'));
        $orders = $resolver->resolve(new Request('POST', 'https://shop.myshopify.com/admin/api/2024-10/orders.json'));

        $this->assertSame($products, $orders);
        $this->assertSame('shop.myshopify.com', $products);
    }

    public function test_hosts_get_separate_buckets(): void
    {
        $resolver = new HostBucketResolver();

        $this->assertNotSame(
            $resolver->resolve(new Request('GET', 'https://one.myshopify.com/admin/api/2024-10/shop.json')),
            $resolver->resolve(new Request('GET', 'https://two.myshopify.com/admin/api/2024-10/shop.json')),
        );
    }
}
