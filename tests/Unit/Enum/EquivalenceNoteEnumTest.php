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
use WickedOne\InfectionExtension\Enum\EquivalenceNoteEnum;
use WickedOne\InfectionExtension\Parser\MutantResult;

class EquivalenceNoteEnumTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, EquivalenceNoteEnum}>
     */
    public static function mutators(): iterable
    {
        yield 'unwrap array values' => ['UnwrapArrayValues', '-a', EquivalenceNoteEnum::KeysUnobserved];
        yield 'cast string' => ['CastString', '-a', EquivalenceNoteEnum::TypeKnown];
        yield 'cast int' => ['CastInt', '-a', EquivalenceNoteEnum::TypeKnown];
        yield 'cast float' => ['CastFloat', '-a', EquivalenceNoteEnum::TypeKnown];
        yield 'cast bool' => ['CastBool', '-a', EquivalenceNoteEnum::TypeKnown];
        yield 'cast array' => ['CastArray', '-a', EquivalenceNoteEnum::TypeKnown];
        yield 'cast object' => ['CastObject', '-a', EquivalenceNoteEnum::TypeKnown];
        yield 'true value in an array' => ['TrueValue', "@@ @@\n-    'extend' => true,\n+    'extend' => false,", EquivalenceNoteEnum::KeysOnly];
        yield 'false value in an array' => ['FalseValue', "@@ @@\n-    'extend' => true,\n+    'extend' => false,", EquivalenceNoteEnum::KeysOnly];
        yield 'instanceof on a traversable' => ['InstanceOf_', '-if ($a instanceof Traversable) {', EquivalenceNoteEnum::Traversable];
        yield 'ternary on a traversable' => ['Ternary', '-$a instanceof Traversable ? 1 : 2;', EquivalenceNoteEnum::Traversable];
    }

    #[DataProvider('mutators')]
    public function testPicksTheNoteForTheMutator(string $mutator, string $diff, EquivalenceNoteEnum $expected): void
    {
        self::assertSame($expected, EquivalenceNoteEnum::forMutant($this->mutant($mutator, $diff)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function withoutNote(): iterable
    {
        yield 'other mutator' => ['Plus', '-a'];
        yield 'true value on a plain boolean' => ['TrueValue', "@@ @@\n-    bool \$extend = true,\n+    bool \$extend = false,"];
        yield 'false value on a plain boolean' => ['FalseValue', "@@ @@\n-    bool \$extend = true,\n+    bool \$extend = false,"];
        yield 'instanceof without a traversable' => ['InstanceOf_', '-if ($a instanceof Countable) {'];
        yield 'ternary without a traversable' => ['Ternary', '-$a = $b ? 1 : 2;'];
    }

    #[DataProvider('withoutNote')]
    public function testHasNoNoteWhenTheMutatorOrDiffDoesNotFit(string $mutator, string $diff): void
    {
        self::assertNull(EquivalenceNoteEnum::forMutant($this->mutant($mutator, $diff)));
    }

    public function testTheValueIsTheNoteText(): void
    {
        self::assertStringStartsWith('Often equivalent when', EquivalenceNoteEnum::KeysOnly->value);
        self::assertStringContainsString('Not a verdict', EquivalenceNoteEnum::TypeKnown->value);
    }

    private function mutant(string $mutator, string $diff): MutantResult
    {
        return new MutantResult(BucketEnum::Escaped, 'id', $mutator, 'src/Calc.php', 15, $diff, '');
    }
}
