<?php

declare(strict_types=1);

namespace Kyzegs\GuzzleRateLimitMiddleware\Tests\Support;

use Kyzegs\GuzzleRateLimitMiddleware\Support\StructuredFieldParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StructuredFieldParserTest extends TestCase
{
    public function test_parses_a_list_of_items_with_parameters(): void
    {
        $this->assertSame(
            [
                ['burst', ['r' => 50, 't' => 30]],
                ['daily', ['r' => 950, 't' => 5000]],
            ],
            StructuredFieldParser::parseList('"burst";r=50;t=30, "daily";r=950;t=5000'),
        );
    }

    public function test_parses_every_bare_item_type(): void
    {
        $this->assertSame(
            [
                [-42, []],
                [1.5, []],
                ['say "hi" \\', []],
                ['text/html', []],
                ['hello', []],
                [true, []],
                [false, []],
            ],
            StructuredFieldParser::parseList('-42, 1.5, "say \"hi\" \\\\", text/html, :aGVsbG8=:, ?1, ?0'),
        );
    }

    public function test_bare_parameter_keys_are_true(): void
    {
        $this->assertSame(
            [['default', ['flag' => true, 'r' => 1]]],
            StructuredFieldParser::parseList('default;flag; r=1'),
        );
    }

    public function test_parses_a_dictionary(): void
    {
        $this->assertSame(
            [
                'limit' => [100, []],
                'remaining' => [50, ['note' => 'x']],
                'reset' => [30, []],
                'flag' => [true, ['a' => 1]],
            ],
            StructuredFieldParser::parseDictionary('limit=100,remaining=50;note="x",  reset=30, flag;a=1'),
        );
    }

    public function test_later_dictionary_keys_overwrite_earlier_ones(): void
    {
        $this->assertSame(
            ['remaining' => [1, []]],
            StructuredFieldParser::parseDictionary('remaining=5, remaining=1'),
        );
    }

    public function test_empty_input_is_an_empty_container(): void
    {
        $this->assertSame([], StructuredFieldParser::parseList('  '));
        $this->assertSame([], StructuredFieldParser::parseDictionary(''));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedLists(): array
    {
        return [
            'trailing comma' => ['a, b,'],
            'leading comma' => [', a'],
            'missing comma' => ['a b'],
            'unterminated string' => ['"abc'],
            'bad escape' => ['"a\\b"'],
            'control character in string' => ["\"a\tb\""],
            'integer too long' => ['1234567890123456'],
            'too many decimals' => ['1.2345'],
            'decimal too long' => ['1234567890123.5'],
            'trailing dot' => ['1.'],
            'lone minus' => ['-'],
            'bad boolean' => ['?2'],
            'bad byte sequence' => [':not base64!:'],
            'unterminated byte sequence' => [':aGVsbG8='],
            'uppercase parameter key' => ['a;R=1'],
            'missing parameter value' => ['a;r='],
            'inner list' => ['(a b)'],
            'non-ascii' => ["caf\u{e9}"],
        ];
    }

    #[DataProvider('malformedLists')]
    public function test_malformed_lists_return_null(string $field): void
    {
        $this->assertNull(StructuredFieldParser::parseList($field));
    }

    public function test_malformed_dictionaries_return_null(): void
    {
        $this->assertNull(StructuredFieldParser::parseDictionary('"default";r=50'));
        $this->assertNull(StructuredFieldParser::parseDictionary('Limit=100'));
        $this->assertNull(StructuredFieldParser::parseDictionary('limit=100,'));
    }
}
