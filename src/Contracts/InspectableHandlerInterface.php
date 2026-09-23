<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Contracts;

use Kyzegs\GuzzleRateLimitMiddleware\BudgetState;
use Kyzegs\GuzzleRateLimitMiddleware\RateLimit;
use Psr\Http\Message\RequestInterface;

/**
 * A handler that can report and clear the state it keeps, so an application
 * can show "N requests left", decide whether to schedule work now, or drop a
 * bucket it knows is stale.
 *
 * Kept apart from {@see HandlerInterface} so existing custom handlers stay
 * valid; the middleware only offers inspection when its handler opts in.
 */
interface InspectableHandlerInterface extends HandlerInterface
{
    /**
     * The stored state of the bucket $request would use right now, or null
     * when nothing is known yet or its window has already reset.
     */
    public function state(RequestInterface $request): ?RateLimit;

    /**
     * Drop the stored state of the bucket $request would use, so the next
     * request goes out without waiting and relearns the allowance. A bucket
     * hash learned through discovery is kept.
     */
    public function forget(RequestInterface $request): void;

    /**
     * The global request budget of $request's identity, or null when no
     * global limit is configured or $request is exempt from it.
     */
    public function globalBudget(RequestInterface $request): ?BudgetState;

    /**
     * The invalid-request budget of $request's identity, or null when no
     * invalid-request limit is configured.
     */
    public function invalidRequestBudget(RequestInterface $request): ?BudgetState;
}
