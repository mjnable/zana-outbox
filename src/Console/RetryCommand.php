<?php

declare(strict_types=1);

namespace Zana\Outbox\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Outbox\OutboxRelay;

/**
 * outbox:retry <id|all>: makes failed messages due again, with all their tries.
 */
#[AsCommand(name: 'outbox:retry', description: 'Make failed outbox messages due again')]
final class RetryCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'A message id, or "all"');
    }

    public function handle(OutboxRelay $relay, InputInterface $input, SymfonyStyle $io): int
    {
        $id = (string) $input->getArgument('id');
        $count = $relay->retry($id === 'all' ? null : $id);
        $io->writeln(sprintf('%d failed message%s will be relayed again.', $count, $count === 1 ? '' : 's'));

        return $count === 0 && $id !== 'all' ? self::FAILURE : self::SUCCESS;
    }
}
