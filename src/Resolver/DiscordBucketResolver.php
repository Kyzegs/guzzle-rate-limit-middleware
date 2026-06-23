<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Resolver;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\MajorParameterAwareInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Discord-aware resolver. Discord rate limits per "route", further subdivided
 * by major parameters. Per the Discord docs these are currently:
 * channel_id, guild_id, and webhook_id OR webhook_token — i.e. a webhook route
 * carries two major segments: /webhooks/{webhook_id}/{webhook_token}.
 *
 * {@see resolve()} returns a route *template* (every id, including major ids and
 * the webhook token, normalised) so a discovered bucket hash is shared across
 * resources. {@see majorParameters()} extracts the literal major values so each
 * resource keeps its own bucket.
 *
 * @see https://discord.com/developers/docs/topics/rate-limits
 */
final class DiscordBucketResolver implements BucketResolverInterface, MajorParameterAwareInterface
{
    /**
     * Map of the path keyword to the major-parameter name(s) of the segment(s)
     * that follow it.
     *
     * @var array<string, list<string>>
     */
    private const MAJOR = [
        'channels' => ['channel_id'],
        'guilds' => ['guild_id'],
        'webhooks' => ['webhook_id', 'webhook_token'],
    ];

    public function resolve(RequestInterface $request): string
    {
        $segments = $this->segments($request->getUri()->getPath());
        $majorAt = $this->majorPositions($segments);

        $template = [];
        foreach ($segments as $i => $segment) {
            if (isset($majorAt[$i])) {
                $template[] = '{' . $majorAt[$i] . '}';
            } elseif (ctype_digit($segment)) {
                $template[] = '{id}';
            } else {
                $template[] = $segment;
            }
        }

        return sprintf('%s /%s', strtoupper($request->getMethod()), implode('/', $template));
    }

    public function majorParameters(RequestInterface $request): string
    {
        $segments = $this->segments($request->getUri()->getPath());
        $major = [];

        foreach ($this->majorPositions($segments) as $i => $name) {
            $major[$name] = $segments[$i];
        }

        ksort($major);

        return implode(':', array_map(
            static fn (string $k, string $v): string => "{$k}={$v}",
            array_keys($major),
            array_values($major),
        ));
    }

    /**
     * Map of segment index => major-parameter name for every major value in the
     * path (the segment(s) immediately following a major keyword).
     *
     * @param list<string> $segments
     * @return array<int, string>
     */
    private function majorPositions(array $segments): array
    {
        $positions = [];

        foreach ($segments as $i => $segment) {
            $names = self::MAJOR[$segment] ?? null;
            if ($names === null) {
                continue;
            }

            foreach ($names as $offset => $name) {
                $index = $i + 1 + $offset;
                if (isset($segments[$index])) {
                    $positions[$index] = $name;
                }
            }
        }

        return $positions;
    }

    /**
     * @return list<string>
     */
    private function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }
}
