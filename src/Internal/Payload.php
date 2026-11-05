<?php

declare(strict_types=1);

namespace Zana\Outbox\Internal;

use Zana\Outbox\OutboxException;

/**
 * Turns messages into strings and back, as the queue's payloads do: reading
 * allows only the message's class and the classes its typed properties
 * declare, recursively, stored as base64 (serialize() writes NUL bytes,
 * which PostgreSQL text columns refuse); objects held in untyped or array properties come
 * back as __PHP_Incomplete_Class, which is rejected.
 *
 * @internal
 */
final class Payload
{
    /** @var array<class-string, list<string>> */
    private static array $allowed = [];

    public static function encode(object $message): string
    {
        if ($message instanceof \Closure || (new \ReflectionClass($message))->isAnonymous()) {
            throw new OutboxException('Closures and anonymous classes cannot go in the outbox; use a named message class.');
        }

        try {
            // Base64: serialize() writes NUL bytes for protected and private properties, which PostgreSQL text columns refuse.
            return base64_encode(serialize($message));
        } catch (\Throwable $e) {
            throw new OutboxException('The message ' . $message::class . ' cannot be serialized: ' . $e->getMessage(), previous: $e);
        }
    }

    public static function decode(string $encoded): object
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || preg_match('/^O:\d+:"([^"]+)"/', $payload, $match) !== 1 || !class_exists($match[1])) {
            throw new OutboxException('The payload does not describe a known message class.');
        }
        $class = $match[1];

        try {
            $message = @unserialize($payload, ['allowed_classes' => self::allowedClasses($class)]);
        } catch (\Throwable $e) {
            throw new OutboxException("The payload for {$class} cannot be read: " . $e->getMessage(), previous: $e);
        }
        if (!$message instanceof $class || self::containsIncomplete($message)) {
            throw new OutboxException("The payload for {$class} cannot be read, or holds objects of classes its properties do not declare.");
        }

        return $message;
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function allowedClasses(string $class): array
    {
        if (!isset(self::$allowed[$class])) {
            $found = [];
            self::collect($class, $found);
            self::$allowed[$class] = array_keys($found);
        }

        return self::$allowed[$class];
    }

    /**
     * @param array<string, true> $found
     */
    private static function collect(string $class, array &$found): void
    {
        if (isset($found[$class]) || !(class_exists($class) || enum_exists($class))) {
            return;
        }
        $found[$class] = true;

        for ($reflection = new \ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $found[$reflection->getName()] = true;
            foreach ($reflection->getProperties() as $property) {
                foreach (self::namedTypes($property->getType()) as $type) {
                    self::collect($type, $found);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function namedTypes(?\ReflectionType $type): array
    {
        return match (true) {
            $type instanceof \ReflectionNamedType => $type->isBuiltin() ? [] : [$type->getName()],
            $type instanceof \ReflectionUnionType, $type instanceof \ReflectionIntersectionType => array_merge(...array_map(self::namedTypes(...), $type->getTypes())),
            default => [],
        };
    }

    private static function containsIncomplete(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if ($depth > 32 || !(is_array($value) || is_object($value))) {
            return false;
        }
        foreach (is_object($value) ? (array) $value : $value as $item) {
            if (self::containsIncomplete($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
