<?php

declare(strict_types=1);

namespace Zana\Outbox;

/**
 * Signs outbox messages, so that a row the application did not write is
 * refused before it is unserialized (2.20, S28): unserialize() builds
 * objects, and a class's __wakeup() or __destruct() runs before anything can
 * check that the object is a message.
 *
 * The key is outbox.key, or one derived from security.secret. Payloads written
 * before 2.20 are unsigned: they are refused, unless outbox.accept_unsigned is
 * on while the outbox drains. Without any key, payloads are neither signed nor
 * checked, as before 2.20, and check warns.
 */
final readonly class PayloadKey
{
    public const int MINIMUM_LENGTH = 32;

    private const string PREFIX = 'zs1.';

    private ?string $key;

    public function __construct(#[\SensitiveParameter] ?string $key, private bool $acceptUnsigned = false)
    {
        if ($key !== null && strlen($key) < self::MINIMUM_LENGTH) {
            throw new OutboxException('outbox.key must be at least ' . self::MINIMUM_LENGTH . ' characters; generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }
        $this->key = $key;
    }

    /**
     * A key derived from the application secret, so that one secret signs several kinds of payload apart.
     */
    public static function fromSecret(#[\SensitiveParameter] ?string $secret, string $purpose, bool $acceptUnsigned = false): self
    {
        return new self($secret === null || $secret === '' ? null : hash_hmac('sha256', $purpose, $secret), $acceptUnsigned);
    }

    public function signs(): bool
    {
        return $this->key !== null;
    }

    public function acceptsUnsigned(): bool
    {
        return $this->acceptUnsigned;
    }

    public function seal(string $payload): string
    {
        return $this->key === null ? $payload : self::PREFIX . $this->mac($payload) . '.' . $payload;
    }

    /**
     * The payload inside, once its signature is checked.
     *
     * @throws OutboxException When the signature is wrong, or an unsigned payload is not accepted
     */
    public function open(string $stored): string
    {
        if (!self::isSealed($stored)) {
            if ($this->key !== null && !$this->acceptUnsigned) {
                throw new OutboxException('The payload is not signed, so it was not read. Payloads written before 2.20 are unsigned: set outbox.accept_unsigned to true until the outbox is drained.');
            }

            return $stored;
        }
        if ($this->key === null) {
            throw new OutboxException('The payload is signed, but no key is set to check it: set security.secret or outbox.key.');
        }
        $parts = explode('.', substr($stored, strlen(self::PREFIX)), 2);
        if (count($parts) !== 2 || !hash_equals($this->mac($parts[1]), $parts[0])) {
            throw new OutboxException('The payload signature is wrong, so it was not read: it was changed, or written with another key.');
        }

        return $parts[1];
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /**
     * The payload inside, unchecked: to name its class, never to read it.
     */
    public static function inner(string $stored): string
    {
        if (!self::isSealed($stored)) {
            return $stored;
        }
        $parts = explode('.', substr($stored, strlen(self::PREFIX)), 2);

        return $parts[1] ?? '';
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) $this->key);
    }
}
