<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Resolver;

use GuzzleHttp\Psr7\Request;
use Kyzegs\GuzzleRateLimitMiddleware\Resolver\DiscordBucketResolver;
use Kyzegs\GuzzleRateLimitMiddleware\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class DiscordBucketResolverTest extends TestCase
{
    public function test_route_key_is_a_template_shared_across_resources(): void
    {
        $resolver = new DiscordBucketResolver();

        $a = $resolver->resolve(new Request('GET', 'https://discord.com/api/v10/channels/123/messages/456'));
        $b = $resolver->resolve(new Request('GET', 'https://discord.com/api/v10/channels/789/messages/000'));

        // All ids normalised so the template is shared (major parameters are
        // tracked separately) — matching discord.py.
        $this->assertSame('GET /api/v10/channels/{channel_id}/messages/{id}', $a);
        $this->assertSame($a, $b);
    }

    public function test_extracts_channel_major_parameter(): void
    {
        $resolver = new DiscordBucketResolver();

        $major = $resolver->majorParameters(new Request('POST', 'https://discord.com/api/v10/channels/123/messages'));

        $this->assertSame('channel_id=123', $major);
    }

    public function test_webhook_id_and_token_are_both_major(): void
    {
        $resolver = new DiscordBucketResolver();
        $request = new Request('POST', 'https://discord.com/api/v10/webhooks/111/tok-abc');

        $this->assertSame('POST /api/v10/webhooks/{webhook_id}/{webhook_token}', $resolver->resolve($request));
        $this->assertSame('webhook_id=111:webhook_token=tok-abc', $resolver->majorParameters($request));
    }

    public function test_every_emoji_on_a_message_shares_one_route(): void
    {
        $resolver = new DiscordBucketResolver();

        $thumbsUp = $resolver->resolve(new Request('PUT', 'https://discord.com/api/v10/channels/1/messages/2/reactions/%F0%9F%91%8D/@me'));
        $fire = $resolver->resolve(new Request('PUT', 'https://discord.com/api/v10/channels/1/messages/2/reactions/%F0%9F%94%A5/@me'));

        $this->assertSame('PUT /api/v10/channels/{channel_id}/messages/{id}/reactions/{emoji}/@me', $thumbsUp);
        $this->assertSame($thumbsUp, $fire);
    }

    public function test_interaction_tokens_stay_out_of_the_route(): void
    {
        $route = (new DiscordBucketResolver())->resolve(new Request('POST', 'https://discord.com/api/v10/interactions/9/secret-token/callback'));

        $this->assertSame('POST /api/v10/interactions/{interaction_id}/{interaction_token}/callback', $route);
    }

    public function test_guild_template_code_is_not_a_guild(): void
    {
        $resolver = new DiscordBucketResolver();
        $request = new Request('GET', 'https://discord.com/api/v10/guilds/templates/abc');

        $this->assertSame('GET /api/v10/guilds/templates/{template_code}', $resolver->resolve($request));
        $this->assertSame('', $resolver->majorParameters($request));
    }

    public function test_no_major_parameters_for_top_level_routes(): void
    {
        $resolver = new DiscordBucketResolver();

        $major = $resolver->majorParameters(new Request('GET', 'https://discord.com/api/v10/users/@me'));

        $this->assertSame('', $major);
    }

    public function test_deleting_a_message_switches_bucket_once_it_is_older_than_fourteen_days(): void
    {
        // Snowflake for a message created at exactly 1_700_000_000 (UNIX seconds).
        $createdAt = 1_700_000_000.0;
        $messageId = ((int) ($createdAt * 1000) - 1_420_070_400_000) << 22;
        $request = new Request('DELETE', "https://discord.com/api/v10/channels/123/messages/{$messageId}");
        $clock = new FakeClock($createdAt + 14 * 86_400);
        $resolver = new DiscordBucketResolver($clock);

        $this->assertSame(
            'DELETE /api/v10/channels/{channel_id}/messages/{id}',
            $resolver->resolve($request),
            'A message exactly 14 days old still uses the regular bucket.',
        );

        $clock->advance(0.001);

        $this->assertSame(
            'DELETE /api/v10/channels/{channel_id}/messages/{id}:delete-old-message',
            $resolver->resolve($request),
        );
    }
}
