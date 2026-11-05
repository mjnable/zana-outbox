<?php

declare(strict_types=1);

namespace Zana\Outbox;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Zana\Configuration\ConfigurationInterface;
use Zana\Console\ConsoleKernel;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\InspectionSection;
use Zana\Core\ServiceProvider;
use Zana\Database\ConnectionInterface;
use Zana\Outbox\Console\InstallCommand;
use Zana\Outbox\Console\RelayCommand;
use Zana\Outbox\Console\RetryCommand;
use Zana\Outbox\Inspection\OutboxCheck;
use Zana\Outbox\Inspection\OutboxSection;

/**
 * Registers the outbox (scoped), its relay, the inbox, the outbox: commands
 * and their check and inspect contributions. Needs DatabaseServiceProvider
 * and EventsServiceProvider. Reads outbox.tries (5), outbox.backoff
 * ([10, 60, 300, 1800]), outbox.claim_seconds (60) and outbox.warn_after (300).
 */
final class OutboxServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(OutboxRelay::class, static function (ServiceResolverInterface $r): OutboxRelay {
                $config = $r->get(ConfigurationInterface::class);
                $events = $r->get(EventDispatcherInterface::class);
                $logger = $r->has(LoggerInterface::class) ? $r->get(LoggerInterface::class) : null;
                assert($config instanceof ConfigurationInterface && $events instanceof EventDispatcherInterface);

                return new OutboxRelay(
                    self::connection($r),
                    $events,
                    $config->int('outbox.tries', 5),
                    array_values(array_map(intval(...), $config->array('outbox.backoff', [10, 60, 300, 1800]))),
                    $config->int('outbox.claim_seconds', 60),
                    $logger instanceof LoggerInterface ? $logger : null,
                );
            })->uses(ConnectionInterface::class, EventDispatcherInterface::class, ConfigurationInterface::class, LoggerInterface::class)
            ->scoped(Outbox::class, static fn (ServiceResolverInterface $r): Outbox => new Outbox(self::connection($r), self::relay($r)))
            ->uses(ConnectionInterface::class, OutboxRelay::class)
            ->singleton(Inbox::class, static fn (ServiceResolverInterface $r): Inbox => new Inbox(self::connection($r)))->uses(ConnectionInterface::class)
            // The relay is resolved lazily: resolving the tagged checks and sections must not need the database.
            ->singleton(OutboxCheck::class, static fn (ServiceResolverInterface $r): OutboxCheck => new OutboxCheck(
                static fn (): OutboxRelay => self::relay($r),
                self::config($r)->int('outbox.warn_after', 300),
            ))->uses(ConfigurationInterface::class, OutboxRelay::class)
            ->tag(ApplicationCheck::TAG, OutboxCheck::class)
            ->singleton(OutboxSection::class, static fn (ServiceResolverInterface $r): OutboxSection => new OutboxSection(static fn (): OutboxRelay => self::relay($r)))
            ->uses(OutboxRelay::class)
            ->tag(InspectionSection::TAG, OutboxSection::class);

        if (class_exists(ConsoleKernel::class)) {
            foreach ([RelayCommand::class, RetryCommand::class, InstallCommand::class] as $command) {
                $services->singleton($command)->tag(ConsoleKernel::TAG, $command);
            }
        }
    }

    public function origin(): string
    {
        return 'package:mj-zana/outbox';
    }

    private static function relay(ServiceResolverInterface $r): OutboxRelay
    {
        $relay = $r->get(OutboxRelay::class);
        assert($relay instanceof OutboxRelay);

        return $relay;
    }

    private static function connection(ServiceResolverInterface $r): ConnectionInterface
    {
        $connection = $r->get(ConnectionInterface::class);
        assert($connection instanceof ConnectionInterface);

        return $connection;
    }

    private static function config(ServiceResolverInterface $r): ConfigurationInterface
    {
        $config = $r->get(ConfigurationInterface::class);
        assert($config instanceof ConfigurationInterface);

        return $config;
    }
}
