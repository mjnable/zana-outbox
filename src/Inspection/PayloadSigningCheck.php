<?php

declare(strict_types=1);

namespace Zana\Outbox\Inspection;

use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Outbox\PayloadKey;

/**
 * outbox.payload_signing (2.20, S28): outbox messages are signed. A warning,
 * as an unsigned outbox still relays: without a key, a row added to the
 * outbox table by anything but the application is read as a message.
 */
final readonly class PayloadSigningCheck implements ApplicationCheck
{
    public function __construct(private PayloadKey $key)
    {
    }

    public function findings(ApplicationInterface $application): array
    {
        $findings = [];
        if (!$this->key->signs()) {
            $findings[] = new Finding('outbox.payload_signing', Severity::Warning, 'Outbox messages are not signed, so a row added to the outbox table by anything but the application is read as a message.', hint: 'Set security.secret, or outbox.key.');
        }
        if ($this->key->acceptsUnsigned()) {
            $findings[] = new Finding('outbox.payload_signing', Severity::Warning, 'outbox.accept_unsigned is on, so unsigned rows are read as messages.', hint: 'Turn it off once the messages stored before 2.20 have been relayed.');
        }

        return $findings;
    }
}