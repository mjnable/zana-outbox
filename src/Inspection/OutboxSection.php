<?php

declare(strict_types=1);

namespace Zana\Outbox\Inspection;

use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\InspectionSection;
use Zana\Outbox\OutboxRelay;

/**
 * `inspect outbox`: the pending, failed and delivered message counts, and the age of the oldest pending one.
 */
final readonly class OutboxSection implements InspectionSection
{
    /**
     * @param \Closure(): OutboxRelay $relay
     */
    public function __construct(private \Closure $relay)
    {
    }

    public function name(): string
    {
        return 'outbox';
    }

    public function inspect(ApplicationInterface $application): array
    {
        try {
            return ($this->relay)()->counts();
        } catch (\Throwable $e) {
            return ['error' => 'The outbox cannot be read: ' . strtok($e->getMessage(), "\n")];
        }
    }
}
