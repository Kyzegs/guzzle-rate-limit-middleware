<?php

namespace Kyzegs\GuzzleRateLimitMiddleware\Utilities;

use Psr\Http\Message\ResponseInterface;

class HeaderParser
{
    /**
     * Get a header value from response with fallback.
     */
    public static function getHeaderValue(ResponseInterface $response, ?string $headerName, mixed $fallback = null): mixed
    {
        if ($headerName === null || !$response->hasHeader($headerName)) {
            return $fallback;
        }

        $headerValue = $response->getHeaderLine($headerName);
        return $headerValue !== '' ? $headerValue : $fallback;
    }
}
