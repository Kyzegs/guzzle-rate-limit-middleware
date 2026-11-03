<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Resolver;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\ClockInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Contracts\MajorParameterAwareInterface;
use Kyzegs\GuzzleRateLimitMiddleware\Support\SystemClock;
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

    /**
     * Values that are not major parameters and not numeric ids, so without this
     * they would stay literal in the template: every emoji its own route until
     * its first response, and an interaction's token kept in the clear.
     *
     * @var array<string, list<string>>
     */
    private const PLACEHOLDERS = [
        'reactions' => ['emoji'],
        'interactions' => ['interaction_id', 'interaction_token'],
        'templates' => ['template_code'],
    ];

    /**
     * Deleting a message older than 14 days is its own bucket. The clock that
     * decides the age is injected so it agrees with the one the handler times
     * buckets by, and so the boundary can be tested.
     */
    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    public function resolve(RequestInterface $request): string
    {
        $segments = $this->segments($request->getUri()->getPath());
        $named = $this->majorPositions($segments) + $this->positionsAfter($segments, self::PLACEHOLDERS);

        $template = [];
        foreach ($segments as $i => $segment) {
            if (isset($named[$i])) {
                $template[] = '{' . $named[$i] . '}';
            } elseif (ctype_digit($segment)) {
                $template[] = '{id}';
            } else {
                $template[] = $segment;
            }
        }

        $key = sprintf('%s /%s', strtoupper($request->getMethod()), implode('/', $template));

        if ($request->getMethod() === 'DELETE' && preg_match('#/channels/\d+/messages/(\d+)$#', $request->getUri()->getPath(), $matches) === 1) {
            $timestamp = (((int) $matches[1]) >> 22) + 1420070400000;
            if ($this->clock->now() * 1000 - $timestamp > 1_209_600_000) {
                $key .= ':delete-old-message';
            }
        }

        return $key;
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
        // /guilds/templates/{code} is the one route where the word after
        // "guilds" is not a guild id.
        if (($i = array_search('guilds', $segments, true)) !== false && ($segments[$i + 1] ?? null) === 'templates') {
            unset($segments[$i]);
        }

        return $this->positionsAfter($segments, self::MAJOR);
    }

    /**
     * @param array<int, string>          $segments
     * @param array<string, list<string>> $keywords
     * @return array<int, string>
     */
    private function positionsAfter(array $segments, array $keywords): array
    {
        $positions = [];

        foreach ($segments as $i => $segment) {
            $names = $keywords[$segment] ?? null;
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
