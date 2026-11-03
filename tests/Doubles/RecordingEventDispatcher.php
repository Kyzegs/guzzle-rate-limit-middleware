<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Doubles;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Records every dispatched event in order. An optional listener runs on each
 * dispatch, for asserting what the world looked like at that moment (e.g.
 * that a delay was announced before it was slept).
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    /**
     * @param (Closure(object): void)|null $listener
     */
    public function __construct(private readonly ?Closure $listener = null)
    {
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        if ($this->listener !== null) {
            ($this->listener)($event);
        }

        return $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function ofType(string $class): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (object $event): bool => $event instanceof $class,
        ));
    }
}
