<?php

declare(strict_types=1);

namespace Zana\Outbox;

use Zana\Database\ConnectionInterface;

/**
 * Handles a message once per consumer (ADR-028), although the outbox, a
 * provider or a retry may deliver it more than once:
 *
 *     public function __invoke(PaymentConfirmed $event): void
 *     {
 *         $this->inbox->once('tickets.issue', $event->getEventId(), fn () => $this->issueTickets($event));
 *     }
 *
 * For a provider callback, the message id is the provider's reference. The
 * handling and the record of it commit together: if $handle throws, nothing
 * is recorded and the next delivery tries again.
 */
final readonly class Inbox
{
    public const string TABLE = 'inbox_messages';

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param \Closure(): int|null $clock
     */
    public function __construct(private ConnectionInterface $connection, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Run $handle unless $consumer has handled $messageId already.
     *
     * @param callable(): mixed $handle
     * @return bool Whether $handle ran
     */
    public function once(string $consumer, string $messageId, callable $handle): bool
    {
        return $this->connection->transaction(function () use ($consumer, $messageId, $handle): bool {
            // Concurrent deliveries of one message wait on the unique key; the second finds it recorded.
            $recorded = $this->connection->table(self::TABLE)->upsert(
                ['consumer' => $consumer, 'message_id' => $messageId, 'handled_at' => ($this->clock)()],
                uniqueBy: ['consumer', 'message_id'],
                update: [],
            );
            if ($recorded === 0) {
                return false;
            }
            $handle();

            return true;
        });
    }

    public function handled(string $consumer, string $messageId): bool
    {
        return $this->connection->table(self::TABLE)->where('consumer', $consumer)->where('message_id', $messageId)->exists();
    }
}
