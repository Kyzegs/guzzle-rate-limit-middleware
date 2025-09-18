<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\RouteResolvers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\RouteResolverInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Discord-specific route resolver that handles Discord's complex rate limiting.
 * 
 * This implements:
 * - Route key resolution (method + path template)
 * - Major parameter extraction (channel_id, guild_id, webhook_id, webhook_token)
 * - Proper fallback key construction
 */
class DiscordRouteResolver implements RouteResolverInterface
{
    public function resolveRouteKey(RequestInterface $request): string
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        
        // Strip minor parameters to get the route template
        $routeTemplate = $this->stripMinorParameters($url);
        
        return sprintf('%s %s', $method, $routeTemplate);
    }

    public function extractMajorParameters(RequestInterface $request): string
    {
        $url = (string) $request->getUri();
        
        // Extract major parameters from the URL (same as Python discord.py)
        $majorParams = [];
        
        // Channel ID
        if (preg_match('/\/channels\/(\d+)/', $url, $matches)) {
            $majorParams[] = $matches[1];
        }
        
        // Guild ID  
        if (preg_match('/\/guilds\/(\d+)/', $url, $matches)) {
            $majorParams[] = $matches[1];
        }
        
        // Webhook ID
        if (preg_match('/\/webhooks\/(\d+)/', $url, $matches)) {
            $majorParams[] = $matches[1];
        }
        
        // Webhook token
        if (preg_match('/\/webhooks\/\d+\/([^\/]+)/', $url, $matches)) {
            $majorParams[] = $matches[1];
        }
        
        return implode('+', array_filter($majorParams));
    }

    public function getFallbackKey(RequestInterface $request): string
    {
        $routeKey = $this->resolveRouteKey($request);
        $majorParams = $this->extractMajorParameters($request);
        
        return $majorParams ? sprintf('%s:%s', $routeKey, $majorParams) : $routeKey;
    }

    /**
     * Strip minor parameters from Discord URLs to get the route template.
     */
    protected function stripMinorParameters(string $url): string
    {
        $matches = [];

        if (
            (
                preg_match('/^(https:\/\/discord\.com\/api\/v\d+\/channels\/\d*).*?$/', $url, $matches) === 1 ||
                preg_match('/^(https:\/\/discord\.com\/api\/v\d+\/guilds\/\d*).*?$/', $url, $matches) === 1 ||
                preg_match('/^(https:\/\/discord\.com\/api\/v\d+\/users\/@me\/guilds\/\d*).*?$/', $url, $matches) === 1 ||
                preg_match('/^(https:\/\/discord\.com\/api\/v\d+\/webhooks\/\d*).*?$/', $url, $matches) === 1 ||
                preg_match('/^(https:\/\/discord\.com\/api\/v\d+\/applications\/\d*\/guilds\/\d*).*?$/', $url, $matches) === 1
            ) && count($matches) === 2
        ) {
            $url = sprintf('%s%s', $matches[1], preg_replace('/[0-9]+/', '', substr($url, strlen($matches[1]))));
        }

        return $url;
    }
}
