<?php

declare(strict_types=1);

namespace Zana\Outbox\Tests;

use Zana\Ddd\Domain\AggregateRoot;
use Zana\Ddd\Domain\DomainEvent;
use Zana\Ddd\Domain\Uuid;

final class TicketsIssued extends DomainEvent
{
    public function __construct(public readonly string $orderId, public readonly int $tickets)
    {
        parent::__construct();
    }
}

final class Order extends AggregateRoot
{
    public static function pay(int $tickets): self
    {
        $order = new self(Uuid::v7());
        $order->recordEvent(new TicketsIssued((string) $order->getId(), $tickets));

        return $order;
    }
}

/**
 * A message whose untyped property holds an object: the payload reader refuses it.
 */
final class Loose
{
    public mixed $anything;

    public function __construct(mixed $anything)
    {
        $this->anything = $anything;
    }
}

/**
 * Records what it receives; fails while $failures > 0.
 */
final class Recorder
{
    /** @var list<TicketsIssued> */
    public array $received = [];

    public int $failures = 0;

    /** @var (\Closure(TicketsIssued): void)|null */
    public ?\Closure $during = null;

    public function __invoke(TicketsIssued $event): void
    {
        if ($this->during !== null) {
            ($this->during)($event);
        }
        if ($this->failures > 0) {
            $this->failures--;
            throw new \RuntimeException('The mail server is down.');
        }
        $this->received[] = $event;
    }
}
