<?php

declare(strict_types=1);

namespace Zana\Outbox;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Zana\Database\ConnectionInterface;
use Zana\Outbox\Internal\Payload;

/**
 * Delivers outbox messages through the event dispatcher (ADR-028). Each
 * message is claimed with one conditional update, so two relays never
 * deliver it at the same time; a listener that throws makes it wait
 * (backoff) until it has had all its tries, then it is marked failed with
 * the error. Delivery is at least once.
 */
final class OutboxRelay
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param list<int> $backoff Seconds to wait before each retry; the last value repeats
     * @param \Closure(): int|null $clock
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly EventDispatcherInterface $events,
        private readonly int $tries = 5,
        private readonly array $backoff = [10, 60, 300, 1800],
        private readonly int $claimSeconds = 60,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Deliver the messages that are due, oldest first; only $ids when given.
     *
     * @param list<string>|null $ids
     * @return int The messages delivered
     */
    public function relay(?array $ids = null, int $limit = 100): int
    {
        $now = ($this->clock)();
        $due = $this->connection->table(Outbox::TABLE)
            ->whereNull('dispatched_at')->whereNull('failed_at')
            ->where('available_at', '<=', $now)->where('claimed_until', '<', $now)
            ->orderBy('created_at')->limit($limit);
        if ($ids !== null) {
            if ($ids === []) {
                return 0;
            }
            $due->whereIn('id', $ids);
        }

        $delivered = 0;
        foreach ($due->pluck('id') as $id) {
            if (is_string($id) && $this->claim($id, $now)) {
                $delivered += $this->deliver($id) ? 1 : 0;
            }
        }

        return $delivered;
    }

    /**
     * Make failed messages due again, with all their tries: one by id, or all of them.
     *
     * @return int The messages made due
     */
    public function retry(?string $id = null): int
    {
        $failed = $this->connection->table(Outbox::TABLE)->whereNotNull('failed_at');
        if ($id !== null) {
            $failed->where('id', $id);
        }

        return $failed->update(['failed_at' => null, 'attempts' => 0, 'available_at' => ($this->clock)(), 'claimed_until' => 0], force: $id === null);
    }

    /**
     * @return array{pending: int, failed: int, dispatched: int, oldest_pending_seconds: int|null}
     */
    public function counts(): array
    {
        $table = fn () => $this->connection->table(Outbox::TABLE);
        $oldest = $table()->whereNull('dispatched_at')->whereNull('failed_at')->orderBy('created_at')->value('created_at');

        return [
            'pending' => $table()->whereNull('dispatched_at')->whereNull('failed_at')->count(),
            'failed' => $table()->whereNotNull('failed_at')->count(),
            'dispatched' => $table()->whereNotNull('dispatched_at')->count(),
            'oldest_pending_seconds' => is_numeric($oldest) ? max(0, ($this->clock)() - (int) $oldest) : null,
        ];
    }

    private function claim(string $id, int $now): bool
    {
        return $this->connection->table(Outbox::TABLE)
            ->where('id', $id)->whereNull('dispatched_at')->whereNull('failed_at')->where('claimed_until', '<', $now)
            ->increment('attempts', 1, ['claimed_until' => $now + $this->claimSeconds]) === 1;
    }

    private function deliver(string $id): bool
    {
        $row = $this->connection->table(Outbox::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return false;
        }
        $attempts = (int) $row['attempts'];

        try {
            $this->events->dispatch(Payload::decode((string) $row['payload']));
        } catch (\Throwable $error) {
            $this->failed($id, (string) $row['type'], $attempts, $error);

            return false;
        }

        $this->connection->table(Outbox::TABLE)->where('id', $id)->update(['dispatched_at' => ($this->clock)(), 'claimed_until' => 0, 'last_error' => null]);

        return true;
    }

    private function failed(string $id, string $type, int $attempts, \Throwable $error): void
    {
        $now = ($this->clock)();
        $message = $error::class . ': ' . $error->getMessage();
        $context = ['id' => $id, 'type' => $type, 'attempts' => $attempts, 'exception' => $error];

        if ($attempts >= $this->tries || $error instanceof OutboxException) {
            $this->connection->table(Outbox::TABLE)->where('id', $id)->update(['failed_at' => $now, 'claimed_until' => 0, 'last_error' => $message]);
            $this->logger?->error('Outbox message {id} ({type}) failed after {attempts} attempt(s).', $context);

            return;
        }

        $delay = $this->backoff === [] ? 0 : $this->backoff[min($attempts, count($this->backoff)) - 1];
        $this->connection->table(Outbox::TABLE)->where('id', $id)->update(['available_at' => $now + $delay, 'claimed_until' => 0, 'last_error' => $message]);
        $this->logger?->warning("Outbox message {id} ({type}) failed on attempt {attempts}; retrying in {$delay}s.", $context);
    }
}
