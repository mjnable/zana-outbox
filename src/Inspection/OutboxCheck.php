<?php

declare(strict_types=1);

namespace Zana\Outbox\Inspection;

use Zana\Core\ApplicationInterface;
use Zana\Core\ConsoleScript;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Outbox\OutboxRelay;

/**
 * Messages that wait too long or failed (ADR-028): outbox.pending when the
 * oldest pending message is older than outbox.warn_after (300 seconds),
 * outbox.failed when some failed. The relay is given lazily, as the
 * database is only reached when check runs.
 */
final readonly class OutboxCheck implements ApplicationCheck
{
    /**
     * @param \Closure(): OutboxRelay $relay
     */
    public function __construct(private \Closure $relay, private int $warnAfter = 300)
    {
    }

    public function findings(ApplicationInterface $application): array
    {
        try {
            $counts = ($this->relay)()->counts();
        } catch (\Throwable $e) {
            return [new Finding('outbox.table', Severity::Error, 'The outbox cannot be read: ' . strtok($e->getMessage(), "\n"), hint: 'Run ' . ConsoleScript::command('outbox:install') . ', then ' . ConsoleScript::command('migrate') . '.')];
        }

        $findings = [];
        if ($counts['oldest_pending_seconds'] !== null && $counts['oldest_pending_seconds'] > $this->warnAfter) {
            $findings[] = new Finding(
                'outbox.pending',
                Severity::Warning,
                "{$counts['pending']} outbox message(s) are waiting; the oldest for {$counts['oldest_pending_seconds']} seconds.",
                hint: 'Run ' . ConsoleScript::command('outbox:relay') . ' from the scheduler or a cron, every minute.',
            );
        }
        if ($counts['failed'] > 0) {
            $findings[] = new Finding(
                'outbox.failed',
                Severity::Warning,
                "{$counts['failed']} outbox message(s) failed after all their tries.",
                hint: 'Fix the listener, then run ' . ConsoleScript::command('outbox:retry all') . '.',
            );
        }

        return $findings;
    }
}
