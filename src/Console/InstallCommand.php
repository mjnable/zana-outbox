<?php

declare(strict_types=1);

namespace Zana\Outbox\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\ApplicationInterface;
use Zana\Core\ConsoleScript;
use Zana\Outbox\OutboxTablesMigration;

/**
 * outbox:install: writes database/migrations/<date>_create_outbox_tables.php,
 * a migration that returns OutboxTablesMigration. Run migrate afterwards.
 */
#[AsCommand(name: 'outbox:install', description: 'Write the migration of the outbox and inbox tables')]
final class InstallCommand extends Command
{
    private const string STUB = <<<'PHP'
        <?php

        declare(strict_types=1);

        // The outbox and inbox tables of mj-zana/outbox.
        return new \Zana\Outbox\OutboxTablesMigration();

        PHP;

    public function handle(ApplicationInterface $application, SymfonyStyle $io): int
    {
        $folder = $application->path('database', 'migrations');
        foreach (glob("{$folder}/*_create_outbox_tables.php") ?: [] as $existing) {
            $io->writeln('The outbox migration exists: ' . basename($existing) . '.');

            return self::SUCCESS;
        }
        if (!is_dir($folder) && !mkdir($folder, 0777, true) && !is_dir($folder)) {
            $io->error("Cannot create {$folder}.");

            return self::FAILURE;
        }

        $file = $folder . '/' . gmdate('Y_m_d_His') . '_create_outbox_tables.php';
        file_put_contents($file, self::STUB);
        $io->success('Wrote ' . basename($file) . ' (' . OutboxTablesMigration::class . '). Run ' . ConsoleScript::command('migrate') . '.');

        return self::SUCCESS;
    }
}
