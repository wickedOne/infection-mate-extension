<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Enum\KillHintEnum;
use WickedOne\InfectionExtension\Parser\MutantResult;

class KillHintEnumTest extends TestCase
{
    /**
     * @return iterable<string, array{string, KillHintEnum}>
     */
    public static function mutators(): iterable
    {
        yield 'increment integer' => ['IncrementInteger', KillHintEnum::Integer];
        yield 'decrement integer' => ['DecrementInteger', KillHintEnum::Integer];
        yield 'bitwise or' => ['BitwiseOr', KillHintEnum::Bitwise];
        yield 'bitwise and' => ['BitwiseAnd', KillHintEnum::Bitwise];
        yield 'concat' => ['Concat', KillHintEnum::Concat];
        yield 'concat operand removal' => ['ConcatOperandRemoval', KillHintEnum::Concat];
        yield 'unwrap finally' => ['UnwrapFinally', KillHintEnum::Finally];
        yield 'unwrap array filter' => ['UnwrapArrayFilter', KillHintEnum::Filter];
        yield 'identical' => ['Identical', KillHintEnum::Comparison];
        yield 'not identical' => ['NotIdentical', KillHintEnum::Comparison];
        yield 'equal' => ['Equal', KillHintEnum::Comparison];
        yield 'not equal' => ['NotEqual', KillHintEnum::Comparison];
        yield 'logical and' => ['LogicalAnd', KillHintEnum::Logical];
        yield 'logical or single negation' => ['LogicalOrSingleSubExprNegation', KillHintEnum::Logical];
        yield 'array item removal' => ['ArrayItemRemoval', KillHintEnum::ArrayItem];
        yield 'unwrap trim' => ['UnwrapTrim', KillHintEnum::Trim];
        yield 'unwrap ltrim' => ['UnwrapLtrim', KillHintEnum::Trim];
        yield 'unwrap rtrim' => ['UnwrapRtrim', KillHintEnum::Trim];
    }

    #[DataProvider('mutators')]
    public function testPicksTheHintForTheMutator(string $mutator, KillHintEnum $expected): void
    {
        self::assertSame($expected, KillHintEnum::forMutant($this->mutant($mutator, '-a')));
    }

    /**
     * @return iterable<string, array{string, string, KillHintEnum|null}>
     */
    public static function booleans(): iterable
    {
        yield 'true value on a plain boolean' => ['TrueValue', "@@ @@\n-    bool \$extend = true,\n+    bool \$extend = false,", KillHintEnum::Boolean];
        yield 'false value on a plain boolean' => ['FalseValue', "@@ @@\n-    bool \$extend = true,\n+    bool \$extend = false,", KillHintEnum::Boolean];
        yield 'true value in an array' => ['TrueValue', "@@ @@\n-    'extend' => true,\n+    'extend' => false,", null];
        yield 'false value in an array' => ['FalseValue', "@@ @@\n-    'extend' => true,\n+    'extend' => false,", null];
    }

    #[DataProvider('booleans')]
    public function testHintsAPlainBooleanButNotAnArrayValue(string $mutator, string $diff, ?KillHintEnum $expected): void
    {
        self::assertSame($expected, KillHintEnum::forMutant($this->mutant($mutator, $diff)));
    }

    public function testHasNoHintForOtherMutators(): void
    {
        self::assertNull(KillHintEnum::forMutant($this->mutant('Plus', '-a')));
    }

    public function testTheValueIsTheHintText(): void
    {
        self::assertStringStartsWith('To kill it,', KillHintEnum::Integer->value);
    }

    private function mutant(string $mutator, string $diff): MutantResult
    {
        return new MutantResult(BucketEnum::Escaped, 'id', $mutator, 'src/Calc.php', 15, $diff, '');
    }
}
