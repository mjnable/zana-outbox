# Zana Outbox

The transactional outbox for Zana applications. Events are saved in the same
transaction as the change that caused them. They are delivered through the
event dispatcher after the commit, at least once, with retries. The inbox
lets a consumer handle each message once. Requires PHP 8.5 or later.

```
composer require mj-zana/outbox
```

## Recording events

Add messages inside the transaction of the change. The outbox refuses to
add them outside a transaction, where a message could exist without its
change, or a change without its message.

```php
$outbox->transactional(function () use ($outbox, $orders, $order): void {
    $order->pay();
    $orders->save($order);
    $outbox->record($order);                      // the aggregate's released domain events
    $outbox->add(new InvoiceRequested($order->id)); // or any message object
});
// Committed: the messages are now dispatched to their listeners.
```

- **After the commit:** `transactional()` relays the messages once the
  outermost transaction commits. A rollback leaves no message behind.
- **Your own transaction:** when you call `$db->transaction()` yourself,
  the messages are relayed at the end of the scope, or by `outbox:relay`.
- **Ids:** a `DomainEvent` keeps its event id. Other messages get a UUID v7.
- **Payloads:** messages are stored with `serialize()`, as base64. When a
  payload is read back, only the message class and the classes its typed
  properties declare are allowed. Closures and anonymous classes are
  refused.

`Outbox` is scoped: each request, job or command has its own.

## Delivery

Each message is dispatched through the PSR-14 event dispatcher, so its
listeners are ordinary event listeners. A relay claims a message before
delivering it, so two relays never deliver it at the same time.

- **Retries:** a listener that throws makes the message wait
  `backoff[attempt - 1]` seconds. The last value repeats. Each retry logs a
  `warning`.
- **Final failure:** after `tries` attempts, or when the payload cannot be
  read, the message is marked failed with its error, and an `error` is
  logged.
- **At least once:** a process can stop after a listener ran and before the
  message is marked delivered, so a listener can see a message twice. Make
  its side effects idempotent, or use the inbox.

Schedule the relay, so messages left behind by a crash or a failed listener
are delivered:

```
php bin/zana outbox:relay              # deliver what is due, then stop
php bin/zana outbox:relay --loop --sleep=5
php bin/zana outbox:retry 01J9...      # make a failed message due again
php bin/zana outbox:retry all
```

## The inbox

`Inbox::once()` runs the handler only if this consumer has not handled the
message yet. It records the message in the handler's transaction, so a
handler that throws is tried again on the next delivery.

```php
final class SendTicketsMail
{
    public function __construct(private Inbox $inbox, private Mailer $mailer) {}

    public function __invoke(TicketsIssued $event): void
    {
        $this->inbox->once('tickets.mail', $event->getEventId(), fn () => $this->mailer->send(/* ... */));
    }
}
```

## Configuration

Register `OutboxServiceProvider` with `DatabaseServiceProvider` and
`EventsServiceProvider`. Create the tables with:

```
php bin/zana outbox:install
php bin/zana migrate
```

```php
// config/outbox.php
return [
    'tries' => 5,                     // attempts before a message is marked failed
    'backoff' => [10, 60, 300, 1800], // seconds before each retry
    'claim_seconds' => 60,            // how long a relay holds a message it delivers
    'warn_after' => 300,              // check warns when a message waits longer
];
```

## Check and inspect

- **`check`:** reports an error when the outbox table cannot be read. It
  warns when a message has waited longer than `warn_after`, and when
  messages have failed.
- **`inspect outbox`:** shows the pending, failed and delivered counts, and
  the age of the oldest pending message.

## License

MIT
