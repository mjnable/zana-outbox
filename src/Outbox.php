<?php

declare(strict_types=1);

namespace Zana\Outbox;

use Zana\Container\Contracts\Disposable;
use Zana\Database\ConnectionInterface;
use Zana\Ddd\Domain\AggregateRoot;
use Zana\Ddd\Domain\DomainEvent;
use Zana\Ddd\Domain\Uuid;
use Zana\Outbox\Internal\Payload;

/**
 * Messages stored with the change they describe (FE-55, ADR-028): inside the
 * transaction, record() writes an aggregate's released events, or add() any
 * message, to the outbox table; after the commit they are relayed through
 * the event dispatcher.
 *
 *     $this->outbox->transactional(function () use ($order): void {
 *         $this->orders->add($order);
 *         $this->outbox->record($order);   // instead of dispatching after the commit
 *     });
 *
 * transactional() relays right after the outermost commit; messages still
 * recorded when the scope ends are relayed then. Whatever a crash or a
 * failing listener leaves is delivered by `outbox:relay`, so delivery is at
 * least once: listeners whose effect must not repeat use the Inbox.
 */
final class Outbox implements Disposable
{
    public const string TABLE = 'outbox_messages';

    /** @var list<string> Ids recorded in this scope and not relayed yet */
    private array $recorded = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly OutboxRelay $relay,
    ) {
    }

    /**
     * Store messages in the outbox, in the current transaction.
     *
     * @throws OutboxException Outside a transaction
     */
    public function add(object ...$messages): void
    {
        if ($messages === []) {
            return;
        }
        if ($this->connection->transactionLevel() === 0) {
            throw new OutboxException('Messages go in the outbox inside the transaction of the change they describe; use transactional() or a unit of work.');
        }

        $now = time();
        $rows = [];
        foreach ($messages as $message) {
            $id = $message instanceof DomainEvent ? $message->getEventId() : Uuid::v7();
            $rows[] = ['id' => $id, 'type' => $message::class, 'payload' => Payload::encode($message), 'attempts' => 0, 'created_at' => $now, 'available_at' => $now];
            $this->recorded[] = $id;
        }
        $this->connection->table(self::TABLE)->insert($rows);
    }

    /**
     * Store the events the aggregates have recorded (releasing them), in the current transaction.
     */
    public function record(AggregateRoot ...$aggregates): void
    {
        $events = [];
        foreach ($aggregates as $aggregate) {
            array_push($events, ...$aggregate->releaseEvents());
        }
        $this->add(...$events);
    }

    /**
     * Run $callback in a transaction, then relay the messages it recorded once the outermost
     * transaction has committed.
     *
     * @template T
     * @param callable(ConnectionInterface): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed
    {
        $result = $this->connection->transaction($callback);
        $this->flush();

        return $result;
    }

    /**
     * Relay the messages recorded in this scope, if no transaction is open any more.
     */
    public function flush(): void
    {
        if ($this->recorded === [] || $this->connection->transactionLevel() > 0) {
            return;
        }
        $ids = $this->recorded;
        $this->recorded = [];
        $this->relay->relay($ids);
    }

    /**
     * At the end of the request, command or job: relay what is left. A message whose transaction rolled
     * back is not in the table, so nothing is sent for it.
     */
    public function dispose(): void
    {
        try {
            $this->flush();
        } catch (\Throwable) {
            // The scheduled relay delivers it; the end of a scope must not fail because of a listener.
        }
    }
}
