<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\BucketResolvers;

use Kyzegs\GuzzleRateLimitMiddleware\Contracts\BucketResolverInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Discord-specific bucket resolver that handles Discord's complex rate limiting.
 * This implements Discord's bucket logic with major parameters.
 */
class DiscordBucketResolver implements BucketResolverInterface
{
    public function resolveBucket(RequestInterface $request): string
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        
        // Strip minor parameters to get the route template
        $routeTemplate = $this->stripMinorParameters($url);
        
        return sprintf('%s %s', $method, $routeTemplate);
    }

    public function getBucketParameters(RequestInterface $request): string
    {
        $url = (string) $request->getUri();
        
        // Extract major parameters from the URL
        $majorParams = [];
        
        // Channel ID
        if (preg_match('/\/channels\/(\d+)/', $url, $matches)) {
            $majorParams[] = sprintf('channel:%s', $matches[1]);
        }
        
        // Guild ID
        if (preg_match('/\/guilds\/(\d+)/', $url, $matches)) {
            $majorParams[] = sprintf('guild:%s', $matches[1]);
        }
        
        // Webhook ID and token
        if (preg_match('/\/webhooks\/(\d+)\/([^\/]+)/', $url, $matches)) {
            $majorParams[] = sprintf('webhook:%s', $matches[1]);
            $majorParams[] = sprintf('token:%s', $matches[2]);
        } elseif (preg_match('/\/webhooks\/(\d+)/', $url, $matches)) {
            $majorParams[] = sprintf('webhook:%s', $matches[1]);
        }
        
        return implode('+', $majorParams);
    }

    public function getFullBucketKey(RequestInterface $request): string
    {
        $bucket = $this->resolveBucket($request);
        $parameters = $this->getBucketParameters($request);
        
        return $parameters ? sprintf('%s:%s', $bucket, $parameters) : $bucket;
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
