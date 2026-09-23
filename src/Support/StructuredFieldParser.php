<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Support;

use UnexpectedValueException;

/**
 * Parses the subset of RFC 8941 structured fields that rate-limit headers use:
 * lists and dictionaries of items with parameters, where an item is an
 * integer, decimal, string, token, byte sequence or boolean.
 *
 * Inner lists are not used by any rate-limit field and are rejected. Strings
 * and tokens both come back as PHP strings (the distinction carries no meaning
 * here), and byte sequences come back decoded.
 *
 * Header values come from the other side of the wire, so malformed input is
 * an expected outcome rather than a bug: the public methods return null for
 * it instead of throwing.
 *
 * @internal
 *
 * @phpstan-type BareItem int|float|string|bool
 * @phpstan-type Parameters array<string, BareItem>
 * @phpstan-type Item array{0: BareItem, 1: Parameters}
 *
 * @see https://www.rfc-editor.org/rfc/rfc8941#section-4.2
 */
final class StructuredFieldParser
{
    private int $position = 0;

    private function __construct(private readonly string $input)
    {
    }

    /**
     * @return list<Item>|null
     */
    public static function parseList(string $field): ?array
    {
        return self::attempt($field, static fn (self $parser): array => $parser->list());
    }

    /**
     * @return array<string, Item>|null
     */
    public static function parseDictionary(string $field): ?array
    {
        return self::attempt($field, static fn (self $parser): array => $parser->dictionary());
    }

    /**
     * @template T
     *
     * @param callable(self): T $parse
     *
     * @return T|null
     */
    private static function attempt(string $field, callable $parse): mixed
    {
        if (preg_match('/[^\x00-\x7F]/', $field) === 1) {
            return null;
        }

        $parser = new self($field);

        try {
            $parser->skipSpaces();
            $result = $parse($parser);
            $parser->skipSpaces();
        } catch (UnexpectedValueException) {
            return null;
        }

        return $parser->atEnd() ? $result : null;
    }

    /**
     * @return list<Item>
     */
    private function list(): array
    {
        $members = [];

        while (! $this->atEnd()) {
            $members[] = $this->item();

            if (! $this->nextMember()) {
                break;
            }
        }

        return $members;
    }

    /**
     * @return array<string, Item>
     */
    private function dictionary(): array
    {
        $members = [];

        while (! $this->atEnd()) {
            $key = $this->key();

            if ($this->peek() === '=') {
                $this->position++;
                $members[$key] = $this->item();
            } else {
                // A bare key is shorthand for the boolean true.
                $members[$key] = [true, $this->parameters()];
            }

            if (! $this->nextMember()) {
                break;
            }
        }

        return $members;
    }

    /**
     * Step over the comma between two members. Returns false at the end of
     * the input; a trailing comma is an error.
     */
    private function nextMember(): bool
    {
        $this->skipWhitespace();

        if ($this->atEnd()) {
            return false;
        }

        if ($this->peek() !== ',') {
            $this->fail();
        }

        $this->position++;
        $this->skipWhitespace();

        if ($this->atEnd()) {
            $this->fail();
        }

        return true;
    }

    /**
     * @return Item
     */
    private function item(): array
    {
        return [$this->bareItem(), $this->parameters()];
    }

    /**
     * @return Parameters
     */
    private function parameters(): array
    {
        $parameters = [];

        while ($this->peek() === ';') {
            $this->position++;
            $this->skipSpaces();
            $key = $this->key();
            $value = true;

            if ($this->peek() === '=') {
                $this->position++;
                $value = $this->bareItem();
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }

    /**
     * @return BareItem
     */
    private function bareItem(): int|float|string|bool
    {
        $char = $this->peek();

        return match (true) {
            $char === '-' || ctype_digit($char) => $this->number(),
            $char === '"' => $this->string(),
            $char === ':' => $this->byteSequence(),
            $char === '?' => $this->boolean(),
            $char === '*' || ctype_alpha($char) => $this->match('/\G[A-Za-z*][!#$%&\'*+\-.^_`|~0-9A-Za-z:\/]*/'),
            default => $this->fail(),
        };
    }

    private function number(): int|float
    {
        $number = $this->match('/\G-?\d+(?:\.\d+)?/');
        $sign = $number[0] === '-' ? -1 : 1;
        $digits = ltrim($number, '-');
        $parts = explode('.', $digits);

        if (count($parts) === 1) {
            if (strlen($digits) > 15) {
                $this->fail();
            }

            return $sign * (int) $digits;
        }

        if (strlen($parts[0]) > 12 || strlen($parts[1]) > 3) {
            $this->fail();
        }

        return $sign * (float) $digits;
    }

    private function string(): string
    {
        $this->position++; // opening quote
        $output = '';

        while (! $this->atEnd()) {
            $char = $this->input[$this->position++];

            if ($char === '"') {
                return $output;
            }

            if ($char === '\\') {
                $escaped = $this->peek();

                if ($escaped !== '"' && $escaped !== '\\') {
                    $this->fail();
                }

                $this->position++;
                $output .= $escaped;

                continue;
            }

            if (ord($char) < 0x20 || ord($char) > 0x7E) {
                $this->fail();
            }

            $output .= $char;
        }

        $this->fail(); // unterminated
    }

    private function byteSequence(): string
    {
        $encoded = $this->match('/\G:[A-Za-z0-9+\/=]*:/');
        $decoded = base64_decode(substr($encoded, 1, -1), true);

        return $decoded === false ? $this->fail() : $decoded;
    }

    private function boolean(): bool
    {
        return $this->match('/\G\?[01]/') === '?1';
    }

    private function key(): string
    {
        return $this->match('/\G[a-z*][a-z0-9_\-.*]*/');
    }

    /**
     * Consume and return the text matching an anchored (\G) pattern at the
     * current position.
     */
    private function match(string $pattern): string
    {
        if (preg_match($pattern, $this->input, $matches, 0, $this->position) !== 1) {
            $this->fail();
        }

        $this->position += strlen($matches[0]);

        return $matches[0];
    }

    private function peek(): string
    {
        return $this->input[$this->position] ?? '';
    }

    private function atEnd(): bool
    {
        return $this->position >= strlen($this->input);
    }

    private function skipSpaces(): void
    {
        while ($this->peek() === ' ') {
            $this->position++;
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->peek() === ' ' || $this->peek() === "\t") {
            $this->position++;
        }
    }

    private function fail(): never
    {
        throw new UnexpectedValueException('Malformed structured field.');
    }
}
