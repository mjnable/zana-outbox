<?php

declare(strict_types=1);

namespace Zana\Outbox\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\InspectionSection;
use Zana\Database\ConnectionInterface;
use Zana\Database\DatabaseServiceProvider;
use Zana\Events\EventsServiceProvider;
use Zana\Events\ListenerProvider;
use Zana\Ddd\Domain\Uuid;
use Zana\Outbox\Inbox;
use Zana\Outbox\Internal\Payload;
use Zana\Outbox\Inspection\OutboxCheck;
use Zana\Outbox\Inspection\OutboxSection;
use Zana\Outbox\Outbox;
use Zana\Outbox\OutboxException;
use Zana\Outbox\OutboxRelay;
use Zana\Outbox\OutboxServiceProvider;
use Zana\Outbox\OutboxTablesMigration;
use Zana\Testing\ConsoleTester;

/**
 * FE-55, ADR-028, on every database available here: messages exist exactly
 * when the change does, are delivered after the commit or by the relay, at
 * least once, with retries; the inbox handles each once.
 */
final class OutboxTest extends TestCase
{
    private ApplicationInterface $app;

    private ConnectionInterface $db;

    private Recorder $listener;

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return Databases::names();
    }

    #[DataProvider('drivers')]
    public function testMessagesAreRecordedOnlyInTheTransactionOfTheChange(string $driver): void
    {
        $this->boot($driver);
        $outbox = $this->outbox();

        try {
            $outbox->add(new TicketsIssued('o-1', 2));
            $this->fail('Outside a transaction, a message could exist without its change.');
        } catch (OutboxException $e) {
            $this->assertStringContainsString('inside the transaction', $e->getMessage());
        }

        try {
            $outbox->transactional(static function () use ($outbox): void {
                $outbox->record(Order::pay(2));
                throw new \RuntimeException('The payment provider refused.');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $this->db->table(Outbox::TABLE)->count(), 'a rollback leaves no message');
        $this->assertSame([], $this->listener->received);
    }

    #[DataProvider('drivers')]
    public function testMessagesAreRelayedRightAfterTheCommit(string $driver): void
    {
        $this->boot($driver);
        $outbox = $this->outbox();
        $order = Order::pay(3);

        $outbox->transactional(function () use ($outbox, $order): void {
            $outbox->record($order);
            $this->assertSame([], $this->listener->received, 'not before the commit');
        });

        $this->assertCount(1, $this->listener->received);
        $this->assertSame(3, $this->listener->received[0]->tickets);
        $row = $this->db->table(Outbox::TABLE)->first();
        $this->assertSame($this->listener->received[0]->getEventId(), $row['id'] ?? null, 'the message keeps its event id');
        $this->assertNotNull($row['dispatched_at'] ?? null);
        $this->assertSame([], $order->releaseEvents(), 'record() released the events');
    }

    #[DataProvider('drivers')]
    public function testTheRelayDeliversWhatACrashLeftBehind(string $driver): void
    {
        $this->boot($driver);
        $outbox = $this->outbox();
        // The process stops after the commit, before relaying: nothing flushes the outbox.
        $this->db->transaction(static fn () => $outbox->add(new TicketsIssued('o-2', 1), new TicketsIssued('o-3', 4)));
        $this->assertSame([], $this->listener->received);

        $result = (new ConsoleTester($this->app->container()->get(ConsoleKernel::class)))->run('outbox:relay');

        $this->assertSame(0, $result->exitCode(), $result->output());
        $this->assertStringContainsString('Delivered 2 outbox messages.', $result->output());
        $this->assertSame(['o-2', 'o-3'], array_map(static fn (TicketsIssued $e): string => $e->orderId, $this->listener->received));
        $this->assertSame(0, $this->relay()->relay(), 'delivered messages are not delivered again');
    }

    #[DataProvider('drivers')]
    public function testMessagesOfTheApplicationsOwnTransactionAreRelayedWhenTheScopeEnds(string $driver): void
    {
        $this->boot($driver);
        $scope = $this->app->container()->createScope();
        $outbox = $scope->get(Outbox::class);
        assert($outbox instanceof Outbox);

        $this->db->transaction(static fn () => $outbox->record(Order::pay(1)));
        $this->assertSame([], $this->listener->received, 'no commit hook: nothing yet');

        $scope->dispose();
        $this->assertCount(1, $this->listener->received, 'the end of the request, job or command relays them');
    }

    #[DataProvider('drivers')]
    public function testAFailingListenerIsRetriedWithBackoffThenMarkedFailed(string $driver): void
    {
        $this->boot($driver);
        $m1 = (string) Uuid::v7();
        $now = 1_000;
        $relay = new OutboxRelay($this->db, $this->dispatcher(), tries: 3, backoff: [10, 60], clock: static function () use (&$now): int {
            return $now;
        });
        $this->db->transaction(fn () => $this->db->table(Outbox::TABLE)->insert(self::row($m1, new TicketsIssued('o-4', 1), $now)));
        $this->listener->failures = 5;

        $this->assertSame(0, $relay->relay(), 'attempt 1 fails');
        $this->assertSame(0, $relay->relay(), 'not due before its backoff');
        $now += 10;
        $this->assertSame(0, $relay->relay(), 'attempt 2 fails');
        $now += 59;
        $this->assertSame(0, $relay->relay(), 'the second wait is 60 seconds');
        $now += 1;
        $this->assertSame(0, $relay->relay(), 'attempt 3 fails: no tries left');

        $row = $this->db->table(Outbox::TABLE)->where('id', $m1)->first();
        $this->assertNotNull($row['failed_at'] ?? null);
        $this->assertSame('RuntimeException: The mail server is down.', $row['last_error'] ?? null);
        $this->assertSame(['pending' => 0, 'failed' => 1, 'dispatched' => 0, 'oldest_pending_seconds' => null], $relay->counts());
        $this->assertSame(['outbox.failed'], array_map(static fn (Finding $f): string => $f->id, (new OutboxCheck(static fn (): OutboxRelay => $relay))->findings($this->app)));

        $this->listener->failures = 0;
        $this->assertSame(1, $relay->retry($m1));
        $this->assertSame(1, $relay->relay());
        $this->assertCount(1, $this->listener->received);
    }

    #[DataProvider('drivers')]
    public function testTwoRelaysNeverDeliverAMessageAtTheSameTime(string $driver): void
    {
        $this->boot($driver);
        $first = $this->relay();
        $second = new OutboxRelay($this->db, $this->dispatcher());
        $m2 = (string) Uuid::v7();
        $this->db->transaction(fn () => $this->db->table(Outbox::TABLE)->insert(self::row($m2, new TicketsIssued('o-5', 1), time())));

        // While the first relay delivers the message, the second one runs: the message is claimed.
        $this->listener->during = function () use ($second): void {
            $this->listener->during = null;
            $this->assertSame(0, $second->relay());
        };

        $this->assertSame(1, $first->relay());
        $this->assertCount(1, $this->listener->received);
    }

    #[DataProvider('drivers')]
    public function testTheInboxHandlesAMessageOncePerConsumer(string $driver): void
    {
        $this->boot($driver);
        $inbox = $this->app->container()->get(Inbox::class);
        assert($inbox instanceof Inbox);
        $sent = 0;
        $send = static function () use (&$sent): void {
            $sent++;
        };

        $this->assertTrue($inbox->once('tickets.email', 'evt-1', $send));
        $this->assertFalse($inbox->once('tickets.email', 'evt-1', $send), 'a second delivery is skipped');
        $this->assertTrue($inbox->once('tickets.sms', 'evt-1', $send), 'another consumer handles it too');
        $this->assertSame(2, $sent);

        try {
            $inbox->once('tickets.email', 'evt-2', static fn () => throw new \RuntimeException('SMTP timeout'));
        } catch (\RuntimeException) {
        }
        $this->assertFalse($inbox->handled('tickets.email', 'evt-2'), 'a failed handling is not recorded, so it is tried again');
        $this->assertTrue($inbox->once('tickets.email', 'evt-2', $send));
    }

    public function testPayloadsOnlyHoldDeclaredClasses(): void
    {
        $this->boot('sqlite');
        $m3 = (string) Uuid::v7();
        $this->db->transaction(fn () => $this->db->table(Outbox::TABLE)->insert(self::row($m3, new Loose(new \ArrayObject()), time())));

        $this->assertSame(0, $this->relay()->relay());
        $row = $this->db->table(Outbox::TABLE)->where('id', $m3)->first();
        $this->assertNotNull($row['failed_at'] ?? null, 'an unreadable payload fails at once');
        $this->assertStringContainsString('holds objects of classes its properties do not declare', (string) ($row['last_error'] ?? ''));
    }

    public function testInstallCheckAndInspect(): void
    {
        $base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-outbox-install-' . bin2hex(random_bytes(4));
        mkdir("{$base}/var", 0777, true);
        $app = Application::configure($base)->withEnvironment('testing')
            ->withProviders(DatabaseServiceProvider::class, EventsServiceProvider::class, ConsoleServiceProvider::class, OutboxServiceProvider::class)
            ->withConfiguration(['database.connections.main' => ['driver' => 'sqlite', 'database' => ':memory:']])
            ->build();
        $console = new ConsoleTester($app->container()->get(ConsoleKernel::class));

        $check = self::tagged($app, ApplicationCheck::TAG, OutboxCheck::class);
        $this->assertSame(['outbox.table'], array_map(static fn (Finding $f): string => $f->id, $check->findings($app)), 'no table yet');

        $this->assertStringContainsString('_create_outbox_tables.php', $console->run('outbox:install')->output());
        $this->assertStringContainsString('The outbox migration exists', $console->run('outbox:install')->output());
        $this->assertSame(0, $console->run('migrate')->exitCode());
        $this->assertSame([], $check->findings($app));
        $this->assertSame(['pending' => 0, 'failed' => 0, 'dispatched' => 0, 'oldest_pending_seconds' => null], self::tagged($app, InspectionSection::TAG, OutboxSection::class)->inspect($app));

        $relay = $app->container()->get(OutboxRelay::class);
        assert($relay instanceof OutboxRelay);
        $db = $app->container()->get(ConnectionInterface::class);
        assert($db instanceof ConnectionInterface);
        $m4 = (string) Uuid::v7();
        $db->transaction(static fn () => $db->table(Outbox::TABLE)->insert(self::row($m4, new TicketsIssued('o-6', 1), time() - 600)));
        $this->assertSame(['outbox.pending'], array_map(static fn (Finding $f): string => $f->id, $check->findings($app)), 'waiting longer than outbox.warn_after');

        exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $base)) : 'rm -rf ' . escapeshellarg($base));
    }

    private function boot(string $driver): void
    {
        $settings = Databases::settings($driver);
        if (is_string($settings)) {
            $this->markTestSkipped($settings);
        }
        $this->listener = new Recorder();
        $listener = $this->listener;
        $this->app = Application::configure(sys_get_temp_dir() . '/zana-outbox-tests')
            ->withEnvironment('testing')
            ->withProviders(DatabaseServiceProvider::class, EventsServiceProvider::class, ConsoleServiceProvider::class, OutboxServiceProvider::class)
            ->withConfiguration(['database.connections.main' => $settings])
            ->withServices(static fn (ServiceCollectionInterface $s) => $s->instance(Recorder::class, $listener))
            ->build();
        $listeners = $this->app->container()->get(ListenerProvider::class);
        assert($listeners instanceof ListenerProvider);
        $listeners->listen(TicketsIssued::class, Recorder::class);

        $db = $this->app->container()->get(ConnectionInterface::class);
        assert($db instanceof ConnectionInterface);
        $this->db = $db;
        (new OutboxTablesMigration())->down($db->schema());
        (new OutboxTablesMigration())->up($db->schema());
    }

    private function outbox(): Outbox
    {
        $outbox = $this->app->container()->createScope()->get(Outbox::class);
        assert($outbox instanceof Outbox);

        return $outbox;
    }

    private function relay(): OutboxRelay
    {
        $relay = $this->app->container()->get(OutboxRelay::class);
        assert($relay instanceof OutboxRelay);

        return $relay;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $events = $this->app->container()->get(EventDispatcherInterface::class);
        assert($events instanceof EventDispatcherInterface);

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $id, object $message, int $now): array
    {
        return ['id' => $id, 'type' => $message::class, 'payload' => Payload::encode($message), 'attempts' => 0, 'created_at' => $now, 'available_at' => $now];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function tagged(ApplicationInterface $app, string $tag, string $class): object
    {
        foreach ($app->container()->tagged($tag) as $service) {
            if ($service instanceof $class) {
                return $service;
            }
        }
        self::fail("{$class} is not tagged {$tag}.");
    }
}
