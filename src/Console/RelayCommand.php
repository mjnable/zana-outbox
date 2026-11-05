<?php

declare(strict_types=1);

namespace Zana\Outbox\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Outbox\OutboxRelay;

/**
 * outbox:relay [--limit=100] [--loop] [--sleep=5]
 *
 * Delivers the outbox messages that are due: those a crash or a failing
 * listener left behind. Run it every minute from the scheduler or a cron,
 * or keep it running with --loop.
 */
#[AsCommand(name: 'outbox:relay', description: 'Deliver the outbox messages that are due')]
final class RelayCommand extends Command
{
    private bool $stop = false;

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Messages per round', '100')
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Keep relaying, until stopped')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds between rounds with nothing to do (--loop)', '5');
    }

    public function handle(OutboxRelay $relay, InputInterface $input, SymfonyStyle $io): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $total = 0;
        do {
            $delivered = $relay->relay(limit: $limit);
            $total += $delivered;
            if ($input->getOption('loop') && $delivered === 0 && !$this->stop) {
                sleep(max(1, (int) $input->getOption('sleep')));
            }
        } while ($input->getOption('loop') && !$this->stop);

        $io->writeln(sprintf('Delivered %d outbox message%s.', $total, $total === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /**
     * Ends a --loop run after the current round (for tests and signal handlers).
     */
    public function stop(): void
    {
        $this->stop = true;
    }
}
