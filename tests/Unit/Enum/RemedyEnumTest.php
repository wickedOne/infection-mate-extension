<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Enum;

use Infection\Mutator\ProfileList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Enum\EquivalenceNoteEnum;
use WickedOne\InfectionExtension\Enum\RemedyEnum;
use WickedOne\InfectionExtension\Parser\MutantResult;

class RemedyEnumTest extends TestCase
{
    /**
     * mutators of Infection that have no remedy on purpose.
     */
    private const array EXEMPT = [
        'SyntaxError', // not a mutator, the marker of code that no longer parses
        'UnwrapStrShuffle', // random output, no deterministic assertion kills it
    ];

    private const string ARRAY_ITEM_DIFF = "@@ @@\n-    'extend' => true,\n+    'extend' => false,";
    private const string PLAIN_BOOLEAN_DIFF = "@@ @@\n-    bool \$extend = true,\n+    bool \$extend = false,";

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mutators(): iterable
    {
        foreach (self::mapping() as $case => $mutators) {
            foreach ($mutators as $mutator) {
                yield $mutator => [$mutator, $case];
            }
        }
    }

    #[DataProvider('mutators')]
    public function testPicksTheRemedyForTheMutator(string $mutator, string $case): void
    {
        $remedy = RemedyEnum::forMutant($this->mutant($mutator, '-a'));

        self::assertSame($case, $remedy?->name);
    }

    public function testEveryCaseIsUsedByAMutator(): void
    {
        $cases = array_map(static fn (RemedyEnum $case): string => $case->name, RemedyEnum::cases());

        self::assertEqualsCanonicalizing($cases, array_keys(self::mapping()));
    }

    public function testMatchesTheLogicalMutatorsByPrefix(): void
    {
        self::assertSame(RemedyEnum::Logical, RemedyEnum::forMutant($this->mutant('LogicalSomethingNew', '-a')));
    }

    /**
     * @return iterable<string, array{string, string, RemedyEnum|null}>
     */
    public static function booleans(): iterable
    {
        yield 'true value on a plain boolean' => ['TrueValue', self::PLAIN_BOOLEAN_DIFF, RemedyEnum::Boolean];
        yield 'false value on a plain boolean' => ['FalseValue', self::PLAIN_BOOLEAN_DIFF, RemedyEnum::Boolean];
        yield 'true value in an array' => ['TrueValue', self::ARRAY_ITEM_DIFF, null];
        yield 'false value in an array' => ['FalseValue', self::ARRAY_ITEM_DIFF, null];
    }

    #[DataProvider('booleans')]
    public function testRemediesAPlainBooleanButNotAnArrayValue(string $mutator, string $diff, ?RemedyEnum $expected): void
    {
        self::assertSame($expected, RemedyEnum::forMutant($this->mutant($mutator, $diff)));
    }

    public function testHasNoRemedyForAnUnknownMutator(): void
    {
        self::assertNull(RemedyEnum::forMutant($this->mutant('NotARealMutator', '-a')));
    }

    public function testTheValueIsTheRemedyText(): void
    {
        self::assertStringStartsWith('Assert the exact integer', RemedyEnum::Integer->value);
    }

    public function testNoRemedyStartsWithToKillIt(): void
    {
        foreach (RemedyEnum::cases() as $case) {
            self::assertStringStartsNotWith('To kill it', $case->value, $case->name);
        }
    }

    public function testEveryMappedMutatorExistsInInfection(): void
    {
        $known = array_keys(ProfileList::ALL_MUTATORS);
        $mapped = array_merge(...array_values(self::mapping()));

        self::assertSame([], array_values(array_diff($mapped, $known)), 'mapped names that Infection does not know, likely a typo');
    }

    /**
     * a mutator Infection adds in a newer release fails here until it gets a remedy, an equivalence note, or is exempted above.
     */
    public function testEveryMutatorOfInfectionHasAnAdvice(): void
    {
        $without = [];

        foreach (array_diff(array_keys(ProfileList::ALL_MUTATORS), self::EXEMPT) as $name) {
            if (!$this->hasRemedyOrNote($name)) {
                $without[] = $name;
            }
        }

        self::assertSame([], $without, 'mutators of Infection without a remedy or note');
    }

    /**
     * @return array<string, list<string>> the mutators of each remedy, by case name
     */
    private static function mapping(): array
    {
        return [
            'Arithmetic' => ['Plus', 'Minus', 'Multiplication', 'Division', 'Modulus', 'Exponentiation', 'PlusEqual', 'MinusEqual', 'MulEqual', 'DivEqual', 'ModEqual', 'PowEqual'],
            'Assignment' => ['Assignment', 'AssignCoalesce'],
            'Counter' => ['Increment', 'Decrement'],
            'Rounding' => ['RoundingFamily'],
            'Bitwise' => ['BitwiseOr', 'BitwiseAnd', 'BitwiseXor', 'BitwiseNot', 'ShiftLeft', 'ShiftRight'],
            'Integer' => ['IncrementInteger', 'DecrementInteger'],
            'Float' => ['OneZeroFloat'],
            'Negation' => ['IntegerNegation', 'FloatNegation'],
            'Boundary' => ['GreaterThan', 'GreaterThanOrEqualTo', 'LessThan', 'LessThanOrEqualTo'],
            'Comparison' => ['Identical', 'NotIdentical', 'Equal', 'NotEqual', 'AssignmentEqual', 'GreaterThanNegotiation', 'GreaterThanOrEqualToNegotiation', 'LessThanNegotiation', 'LessThanOrEqualToNegotiation'],
            'LooseStrict' => ['EqualIdentical', 'NotEqualNotIdentical', 'NotIdenticalNotEqual'],
            'Logical' => ['LogicalAnd', 'LogicalAndAllSubExprNegation', 'LogicalAndNegation', 'LogicalAndSingleSubExprNegation', 'LogicalLowerAnd', 'LogicalLowerOr', 'LogicalNot', 'LogicalOr', 'LogicalOrAllSubExprNegation', 'LogicalOrNegation', 'LogicalOrSingleSubExprNegation'],
            'Boolean' => ['TrueValue', 'FalseValue'],
            'InstanceOf' => ['InstanceOf_'],
            'ArrayPredicate' => ['ArrayAll', 'ArrayAny'],
            'ArrayItem' => ['ArrayItemRemoval', 'ArrayItem', 'ArrayOneItem'],
            'Generator' => ['Yield_', 'YieldValue'],
            'Branch' => ['IfNegation', 'ElseIfNegation', 'Ternary'],
            'Coalesce' => ['Coalesce'],
            'NullSafe' => ['NullSafeMethodCall', 'NullSafePropertyCall'],
            'Spread' => ['SpreadAssignment', 'SpreadOneItem', 'SpreadRemoval'],
            'Catch' => ['Catch_', 'CatchBlockRemoval'],
            'Throw' => ['Throw_'],
            'Finally' => ['UnwrapFinally', 'Finally_'],
            'Loop' => ['DoWhile', 'For_', 'Foreach_', 'While_'],
            'LoopJump' => ['Break_', 'Continue_'],
            'Nullify' => ['ArrayFind', 'ArrayFindKey', 'ArrayFirst', 'ArrayLast'],
            'CallRemoval' => ['FunctionCallRemoval', 'MethodCallRemoval'],
            'Clone' => ['CloneRemoval'],
            'MatchArm' => ['MatchArmRemoval', 'SharedCaseRemoval'],
            'ReturnValue' => ['ReturnRemoval', 'FunctionCall', 'NewObject'],
            'FluentReturn' => ['This'],
            'Order' => ['Spaceship'],
            'RegexQuote' => ['PregQuote'],
            'RegexMatches' => ['PregMatchMatches'],
            'RegexAnchor' => ['PregMatchRemoveCaret', 'PregMatchRemoveDollar'],
            'RegexFlag' => ['PregMatchRemoveFlags'],
            'Visibility' => ['PublicVisibility', 'ProtectedVisibility'],
            'Multibyte' => ['MBString'],
            'BigNumber' => ['BCMath'],
            'Concat' => ['Concat', 'ConcatOperandRemoval'],
            'Trim' => ['UnwrapTrim', 'UnwrapLtrim', 'UnwrapRtrim'],
            'StringTransform' => ['UnwrapLcFirst', 'UnwrapUcFirst', 'UnwrapUcWords', 'UnwrapStrToLower', 'UnwrapStrToUpper', 'UnwrapStrReplace', 'UnwrapStrIreplace', 'UnwrapStrRepeat', 'UnwrapStrRev', 'UnwrapSubstr'],
            'SetOperation' => [
                'UnwrapArrayDiff', 'UnwrapArrayDiffAssoc', 'UnwrapArrayDiffKey', 'UnwrapArrayDiffUassoc', 'UnwrapArrayDiffUkey',
                'UnwrapArrayIntersect', 'UnwrapArrayIntersectAssoc', 'UnwrapArrayIntersectKey', 'UnwrapArrayIntersectUassoc', 'UnwrapArrayIntersectUkey',
                'UnwrapArrayUdiff', 'UnwrapArrayUdiffAssoc', 'UnwrapArrayUdiffUassoc',
                'UnwrapArrayUintersect', 'UnwrapArrayUintersectAssoc', 'UnwrapArrayUintersectUassoc',
            ],
            'Merge' => ['UnwrapArrayMerge', 'UnwrapArrayMergeRecursive', 'UnwrapArrayReplace', 'UnwrapArrayReplaceRecursive'],
            'ArrayTransform' => ['UnwrapArrayChangeKeyCase', 'UnwrapArrayChunk', 'UnwrapArrayColumn', 'UnwrapArrayCombine', 'UnwrapArrayFlip', 'UnwrapArrayKeys', 'UnwrapArrayMap', 'UnwrapArrayPad', 'UnwrapArrayReduce', 'UnwrapArrayReverse', 'UnwrapArraySlice', 'UnwrapArraySplice', 'UnwrapArrayUnique'],
            'Filter' => ['UnwrapArrayFilter'],
        ];
    }

    private function hasRemedyOrNote(string $name): bool
    {
        // a note only fits some shapes of diff
        foreach (['-a', self::ARRAY_ITEM_DIFF, '-if ($a instanceof Traversable) {'] as $diff) {
            $mutant = $this->mutant($name, $diff);

            if (RemedyEnum::forMutant($mutant) instanceof RemedyEnum || EquivalenceNoteEnum::forMutant($mutant) instanceof EquivalenceNoteEnum) {
                return true;
            }
        }

        return false;
    }

    private function mutant(string $mutator, string $diff): MutantResult
    {
        return new MutantResult(BucketEnum::Escaped, 'id', $mutator, 'src/Calc.php', 15, $diff, '');
    }
}
