<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Formatter;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Formatter\ResultFormatter;
use WickedOne\InfectionExtension\Parser\MutantResult;
use WickedOne\InfectionExtension\Tests\Support\FakePathResolver;

class ResultFormatterTest extends TestCase
{
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        $base = vfsStream::setup('base', null, ['project' => ['src' => []], 'outside' => []])->url();
        $this->root = $base.'/project';
        $this->outside = $base.'/outside';
    }

    protected function tearDown(): void
    {
        unset($this->root, $this->outside);
    }

    public function testSummaryOfARegularRunIsNotMarkedPartial(): void
    {
        self::assertArrayNotHasKey('partial', $this->formatter()->summary('r1', [], [], 0));
    }

    public function testSummaryOfAPartialRunSaysWhatItCovers(): void
    {
        $summary = $this->formatter()->summary('r1', [], [], 0, 4);

        self::assertSame('Limited to the mutants matching the given ids (4): the stats cover just those, not the project. Run the wider scope again for the real score.', $summary['partial']);
    }

    public function testSummaryOfAPartialRunWithoutMatchesIsNotAScore(): void
    {
        $summary = $this->formatter()->summary('r1', [], [], 0, 0);

        self::assertSame('None of the given mutant ids matched a mutant, so nothing ran and the stats are empty, not a score. Infection invalidates an id when the mutated source changes: list the escaped mutants again for current ids.', $summary['partial']);
    }

    public function testSummaryCountsEscapedMutantsByFileAndMutator(): void
    {
        $summary = $this->formatter()->summary('r1', ['msi' => 50], $this->mutants(), 1);

        self::assertSame('r1', $summary['runId']);
        self::assertSame(1, $summary['exitCode']);
        self::assertSame(['msi' => 50], $summary['stats']);
        self::assertSame(['src/A.php' => 2, 'src/B.php' => 1], $summary['escapedByFile']);
        self::assertSame(['GreaterThanOrEqualTo' => 2, 'Increment' => 1], $summary['escapedByMutator']);
        self::assertStringContainsString('infection-escaped', $summary['hint']);
    }

    public function testSummaryHintPointsToOtherBucketsWhenNothingEscaped(): void
    {
        $summary = $this->formatter()->summary('r1', [], [new MutantResult(BucketEnum::Killed, 'killed-0', 'Plus', 'src/B.php', 6, '', '')], 0);

        self::assertSame([], $summary['escapedByFile']);
        self::assertSame([], $summary['escapedByMutator']);
        self::assertStringContainsString('uncovered', $summary['hint']);
    }

    public function testSummaryLimitsGroupingToFifteenEntries(): void
    {
        $mutants = [];

        for ($i = 0; $i < 20; ++$i) {
            $mutants[] = new MutantResult(BucketEnum::Escaped, 'escaped-'.$i, 'Plus', \sprintf('src/File%02d.php', $i), 1, '', '');
        }

        $summary = $this->formatter()->summary('r1', [], $mutants, 0);

        self::assertCount(15, $summary['escapedByFile']);
    }

    public function testListingPagesThroughMutants(): void
    {
        $listing = $this->formatter()->listing('r1', $this->mutants(), 2, 2);

        self::assertSame('r1', $listing['runId']);
        self::assertSame(5, $listing['total']);
        self::assertSame(2, $listing['offset']);
        self::assertSame(2, $listing['count']);
        self::assertSame(['escaped-2', 'killed-0'], array_column($listing['mutants'], 'id'));
    }

    public function testListingReportsCountOfTrailingPage(): void
    {
        $listing = $this->formatter()->listing('r1', $this->mutants(), 10, 4);

        self::assertSame(5, $listing['total']);
        self::assertSame(1, $listing['count']);
        self::assertSame('timeouted-0', $listing['mutants'][0]['id']);
    }

    public function testListingReturnsEntryFieldsWithoutContext(): void
    {
        $listing = $this->formatter()->listing('r1', $this->mutants(), 1, 0);

        self::assertSame(
            ['id' => 'escaped-0', 'mutator' => 'GreaterThanOrEqualTo', 'file' => 'src/A.php', 'line' => 10, 'diff' => '-a'],
            $listing['mutants'][0],
        );
    }

    public function testListingTruncatesLongDiffs(): void
    {
        $long = str_repeat('x', 1500);
        $listing = $this->formatter()->listing('r1', [new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, $long, '')], 10, 0);

        $diff = $listing['mutants'][0]['diff'];
        self::assertStringStartsWith(str_repeat('x', 1200), $diff);
        self::assertStringEndsWith('… [truncated]', $diff);
        self::assertSame(1200 + \strlen('… [truncated]'), \strlen($diff));
    }

    public function testListingKeepsDiffsAtTheLimitUntouched(): void
    {
        $exact = str_repeat('y', 1200);
        $listing = $this->formatter()->listing('r1', [new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, $exact, '')], 10, 0);

        self::assertSame($exact, $listing['mutants'][0]['diff']);
    }

    public function testIdsListsOnlyTheIdsOfThePage(): void
    {
        $ids = $this->formatter()->ids('r1', $this->mutants(), 2, 2);

        self::assertSame(['runId' => 'r1', 'total' => 5, 'offset' => 2, 'count' => 2, 'ids' => ['escaped-2', 'killed-0']], $ids);
    }

    public function testNotesTellTheAgentToProveEquivalence(): void
    {
        $mutant = new MutantResult(BucketEnum::Escaped, 'escaped-0', 'TrueValue', 'src/Calc.php', 15, "-'a' => true", '');

        self::assertStringContainsString('Not a verdict', $this->formatter()->mutant('r1', $mutant, false)['note']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalenceNotes(): iterable
    {
        yield 'true value' => ['TrueValue', 'only used for its keys'];
        yield 'false value' => ['FalseValue', 'only used for its keys'];
        yield 'unwrap array values' => ['UnwrapArrayValues', 'keys of the result are never read'];
        yield 'cast string' => ['CastString', 'already has this type'];
        yield 'cast int' => ['CastInt', 'already has this type'];
        yield 'cast float' => ['CastFloat', 'already has this type'];
        yield 'cast bool' => ['CastBool', 'already has this type'];
        yield 'cast array' => ['CastArray', 'already has this type'];
        yield 'cast object' => ['CastObject', 'already has this type'];
    }

    #[DataProvider('equivalenceNotes')]
    public function testListingAndMutantNoteWhatToCheckForOftenEquivalentEscapedMutants(string $mutator, string $expected): void
    {
        $mutant = new MutantResult(BucketEnum::Escaped, 'escaped-0', $mutator, 'src/Calc.php', 15, "-'a' => true", '');

        $listing = $this->formatter()->listing('r1', [$mutant], 10, 0);
        $payload = $this->formatter()->mutant('r1', $mutant, false);

        self::assertStringStartsWith('Often equivalent when', $listing['mutants'][0]['note']);
        self::assertStringContainsString($expected, $listing['mutants'][0]['note']);
        self::assertSame($listing['mutants'][0]['note'], $payload['note']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversableMutators(): iterable
    {
        yield 'instanceof' => ['InstanceOf_'];
        yield 'ternary' => ['Ternary'];
    }

    #[DataProvider('traversableMutators')]
    public function testListingAndMutantNoteHowATestInputHidesATraversableCheck(string $mutator): void
    {
        $mutant = new MutantResult(BucketEnum::Escaped, 'escaped-0', $mutator, 'src/Calc.php', 15, '-if ($a instanceof Traversable) {', '');

        $listing = $this->formatter()->listing('r1', [$mutant], 10, 0);
        $payload = $this->formatter()->mutant('r1', $mutant, false);

        self::assertStringStartsWith('On an instanceof Traversable check', $listing['mutants'][0]['note']);
        self::assertStringContainsString('ArrayIterator also implements ArrayAccess', $listing['mutants'][0]['note']);
        self::assertStringContainsString('Generator', $listing['mutants'][0]['note']);
        self::assertSame($listing['mutants'][0]['note'], $payload['note']);
    }

    #[DataProvider('traversableMutators')]
    public function testNoTraversableNoteWhenTheDiffDoesNotTouchATraversableCheck(string $mutator): void
    {
        $mutant = new MutantResult(BucketEnum::Escaped, 'escaped-0', $mutator, 'src/Calc.php', 15, '-$a = $b ? 1 : 2;', '');

        self::assertArrayNotHasKey('note', $this->formatter()->listing('r1', [$mutant], 10, 0)['mutants'][0]);
        // without the Traversable note the mutator gets its usual remedy
        self::assertStringNotContainsString('Traversable', $this->formatter()->mutant('r1', $mutant, false)['note']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function remedies(): iterable
    {
        yield 'increment integer' => ['IncrementInteger', 'when the literal is a default argument'];
        yield 'decrement integer' => ['DecrementInteger', 'when the literal is a default argument'];
        yield 'bitwise or' => ['BitwiseOr', 'exact integer result of the bit operation'];
        yield 'bitwise and' => ['BitwiseAnd', 'exact integer result of the bit operation'];
        yield 'concat' => ['Concat', 'the exact resulting string'];
        yield 'concat operand removal' => ['ConcatOperandRemoval', 'the exact resulting string'];
        yield 'unwrap finally' => ['UnwrapFinally', 'Make the guarded code fail'];
        yield 'unwrap array filter' => ['UnwrapArrayFilter', 'an input the filter would drop'];
        yield 'identical' => ['Identical', 'both sides of the comparison'];
        yield 'not identical' => ['NotIdentical', 'both sides of the comparison'];
        yield 'equal' => ['Equal', 'both sides of the comparison'];
        yield 'not equal' => ['NotEqual', 'both sides of the comparison'];
        yield 'logical or single negation' => ['LogicalOrSingleSubExprNegation', 'Cover every combination of the operands'];
        yield 'logical and' => ['LogicalAnd', 'Cover every combination of the operands'];
        yield 'array item removal' => ['ArrayItemRemoval', 'Assert the whole array'];
        yield 'unwrap trim' => ['UnwrapTrim', 'Pass input the function changes'];
        yield 'unwrap ltrim' => ['UnwrapLtrim', 'Pass input the function changes'];
        yield 'unwrap rtrim' => ['UnwrapRtrim', 'Pass input the function changes'];
        yield 'true value on a plain boolean' => ['TrueValue', 'flips a plain boolean'];
        yield 'false value on a plain boolean' => ['FalseValue', 'flips a plain boolean'];
    }

    #[DataProvider('remedies')]
    public function testListingHintsHowToKillTheEscapedMutantsOncePerMutator(string $mutator, string $expected): void
    {
        $mutant = new MutantResult(BucketEnum::Escaped, 'escaped-0', $mutator, 'src/Calc.php', 15, '-$extend = true', '');

        $listing = $this->formatter()->listing('r1', [$mutant], 10, 0);

        self::assertStringContainsString($expected, $listing['hints'][$mutator]);
        self::assertSame([$mutator], array_keys($listing['hints']));
        self::assertArrayNotHasKey('note', $listing['mutants'][0]);
    }

    #[DataProvider('remedies')]
    public function testMutantHintsHowToKillTheEscapedMutantInItsNote(string $mutator, string $expected): void
    {
        $mutant = new MutantResult(BucketEnum::Escaped, 'escaped-0', $mutator, 'src/Calc.php', 15, '-$extend = true', '');

        $listing = $this->formatter()->listing('r1', [$mutant], 10, 0);

        self::assertSame($listing['hints'][$mutator], $this->formatter()->mutant('r1', $mutant, false)['note']);
        self::assertStringContainsString($expected, $this->formatter()->mutant('r1', $mutant, false)['note']);
    }

    public function testListingKeepsTheHintsBeforeTheMutantsAndListsEachOnce(): void
    {
        $mutants = [
            new MutantResult(BucketEnum::Escaped, 'escaped-0', 'IncrementInteger', 'src/A.php', 1, '-1', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-1', 'IncrementInteger', 'src/A.php', 2, '-1', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-2', 'Concat', 'src/A.php', 3, '-a', ''),
        ];

        $listing = $this->formatter()->listing('r1', $mutants, 10, 0);

        self::assertSame(['runId', 'total', 'offset', 'count', 'hints', 'mutants'], array_keys($listing));
        self::assertSame(['IncrementInteger', 'Concat'], array_keys($listing['hints']));
        self::assertCount(3, $listing['mutants']);
    }

    public function testListingHintsOnlyCoverThePage(): void
    {
        $mutants = [
            new MutantResult(BucketEnum::Escaped, 'escaped-0', 'IncrementInteger', 'src/A.php', 1, '-1', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-1', 'Concat', 'src/A.php', 2, '-a', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-2', 'UnwrapLtrim', 'src/A.php', 3, '-a', ''),
        ];

        self::assertSame(['Concat'], array_keys($this->formatter()->listing('r1', $mutants, 1, 1)['hints']));
    }

    public function testListingHasNoHintsWithoutAHintableEscapedMutant(): void
    {
        $mutants = [
            new MutantResult(BucketEnum::Escaped, 'escaped-0', 'NotARealMutator', 'src/A.php', 1, '-a', ''),
            new MutantResult(BucketEnum::Killed, 'killed-0', 'Concat', 'src/A.php', 2, '-a', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-1', 'CastString', 'src/A.php', 3, '-a', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-2', 'TrueValue', 'src/A.php', 4, "-'a' => true", ''),
        ];

        $listing = $this->formatter()->listing('r1', $mutants, 10, 0);

        self::assertArrayNotHasKey('hints', $listing);
        self::assertArrayHasKey('note', $listing['mutants'][2]);
        self::assertArrayHasKey('note', $listing['mutants'][3]);
    }

    public function testListingSkipsMutantsWithoutHintAndKeepsLookingForTheRest(): void
    {
        $mutants = [
            new MutantResult(BucketEnum::Killed, 'killed-0', 'Concat', 'src/A.php', 1, '-a', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-0', 'TrueValue', 'src/A.php', 2, "-'a' => true", ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-1', 'NotARealMutator', 'src/A.php', 3, '-a', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-2', 'UnwrapLtrim', 'src/A.php', 4, '-a', ''),
        ];

        self::assertSame(['UnwrapLtrim'], array_keys($this->formatter()->listing('r1', $mutants, 10, 0)['hints']));
    }

    public function testIdsListingHasNoHints(): void
    {
        $mutants = [new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Concat', 'src/A.php', 1, '-a', '')];

        self::assertArrayNotHasKey('hints', $this->formatter()->ids('r1', $mutants, 10, 0));
    }

    public function testNoRemedyForABooleanArrayValueWhichGetsTheKeysNoteInstead(): void
    {
        $mutant = new MutantResult(BucketEnum::Escaped, 'escaped-0', 'TrueValue', 'src/Calc.php', 15, "-'a' => true", '');

        $note = $this->formatter()->mutant('r1', $mutant, false)['note'];

        self::assertStringContainsString('only used for its keys', $note);
        self::assertStringNotContainsString('plain boolean', $note);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleanDiffs(): iterable
    {
        yield 'removed array item after the hunk header' => ["@@ @@\n-    'extend' => true,\n+    'extend' => false,", true];
        yield 'added array item only' => ["@@ @@\n+    'extend' => false,", true];
        yield 'plain boolean default' => ["@@ @@\n-    bool \$extend = true,\n+    bool \$extend = false,", false];
        yield 'array item only in an unchanged line' => ["@@ @@\n     \$a = [1 => 2];\n-    bool \$extend = true,\n+    bool \$extend = false,", false];
        yield 'arrow function returning true' => ["@@ @@\n-    \$f = fn (\$x) => true;\n+    \$f = fn (\$x) => false;", false];
        yield 'static arrow function with a return type' => ["@@ @@\n-    \$f = static fn (?int \$x): bool => true;", false];
        yield 'arrow function and an array item on one line' => ["@@ @@\n-    \$f = array_map(fn (\$x) => \$x, ['a' => true]);", true];
        yield 'arrow after a minus sign in an unchanged line' => ["@@ @@\n     \$a = \$b - [1 => 2];\n-    bool \$extend = true,", false];
    }

    #[DataProvider('booleanDiffs')]
    public function testTrueValueGetsTheKeysNoteOnlyWhenAChangedLineHoldsAnArrayItem(string $diff, bool $arrayItem): void
    {
        $mutant = new MutantResult(BucketEnum::Escaped, 'escaped-0', 'TrueValue', 'src/Calc.php', 15, $diff, '');

        $note = $this->formatter()->mutant('r1', $mutant, false)['note'];

        self::assertSame($arrayItem, str_contains($note, 'only used for its keys'));
        self::assertSame(!$arrayItem, str_contains($note, 'plain boolean'));
    }

    public function testNoRemedyForOtherBuckets(): void
    {
        $killed = new MutantResult(BucketEnum::Killed, 'killed-0', 'Concat', 'src/Calc.php', 15, '-a', '');

        self::assertArrayNotHasKey('note', $this->formatter()->mutant('r1', $killed, false));
    }

    public function testNoNoteForOtherMutatorsOrOtherBuckets(): void
    {
        $other = new MutantResult(BucketEnum::Escaped, 'escaped-0', 'NotARealMutator', 'src/Calc.php', 15, '-a', '');
        $killed = new MutantResult(BucketEnum::Killed, 'killed-0', 'TrueValue', 'src/Calc.php', 15, '-a', '');

        $listing = $this->formatter()->listing('r1', [$other, $killed], 10, 0);

        self::assertArrayNotHasKey('note', $listing['mutants'][0]);
        self::assertArrayNotHasKey('note', $listing['mutants'][1]);
        self::assertArrayNotHasKey('note', $this->formatter()->mutant('r1', $other, false));
        self::assertArrayNotHasKey('note', $this->formatter()->mutant('r1', $killed, false));
    }

    public function testMutantReturnsContextAroundTheMutatedLine(): void
    {
        $this->sourceFile(30);

        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/Calc.php', 15, '-a', ''), false);

        self::assertSame('escaped-0', $payload['id']);
        self::assertSame(13, \count($payload['context']));
        self::assertSame('9: line 9', $payload['context'][0]);
        self::assertSame('15: line 15', $payload['context'][6]);
        self::assertSame('21: line 21', $payload['context'][12]);
    }

    public function testMutantContextStartsAtFirstLineNearTop(): void
    {
        $this->sourceFile(30);

        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/Calc.php', 3, '-a', ''), false);

        self::assertSame('1: line 1', $payload['context'][0]);
        self::assertSame(13, \count($payload['context']));
    }

    public function testMutantContextAcceptsAbsolutePathInsideRoot(): void
    {
        $this->sourceFile(30);

        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', $this->root.'/src/Calc.php', 15, '-a', ''), false);

        self::assertSame('9: line 9', $payload['context'][0]);
    }

    public function testMutantContextIsEmptyForFileOutsideRoot(): void
    {
        $outsideFile = $this->outside.'/Other.php';
        file_put_contents($outsideFile, "secret\nvalue\n");

        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', $outsideFile, 1, '-a', ''), false);

        self::assertSame([], $payload['context']);
    }

    public function testMutantContextIsEmptyForMissingFile(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/Missing.php', 1, '-a', ''), false);

        self::assertSame([], $payload['context']);
    }

    public function testMutantOmitsProcessOutputByDefault(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, '-a', 'FAILURES!'), false);

        self::assertArrayNotHasKey('processOutput', $payload);
    }

    public function testMutantIncludesProcessOutputWhenRequested(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, '-a', 'FAILURES!'), true);

        self::assertSame('FAILURES!', $payload['processOutput']);
    }

    public function testMutantCutsLongProcessOutputAndNamesTheFileWithTheFullOutput(): void
    {
        $output = str_repeat('a', 2000).str_repeat('m', 4000).str_repeat('z', 6000);

        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, '', $output), true, '/cache/mutant.log');

        self::assertSame(str_repeat('a', 2000)."\n[… 4000 bytes left out; full output: /cache/mutant.log …]\n".str_repeat('z', 6000), $payload['processOutput']);
        self::assertSame('/cache/mutant.log', $payload['processOutputFile']);
    }

    public function testMutantCleansProcessOutputAndHasNoFileKeyWithoutAFile(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, '', "   ok   \n\n  39/39 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%\n"), true);

        self::assertSame('  ok', $payload['processOutput']);
        self::assertArrayNotHasKey('processOutputFile', $payload);
    }

    public function testMutantLeavesOutTheFileKeyWithoutOutput(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, '', 'out'), false, '/cache/mutant.log');

        self::assertArrayNotHasKey('processOutput', $payload);
        self::assertArrayNotHasKey('processOutputFile', $payload);
    }

    public function testMutantAllowsDiffsUpToTwiceTheListingLimit(): void
    {
        $diff = str_repeat('d', 2400);

        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, $diff, ''), false);

        self::assertSame($diff, $payload['diff']);
    }

    public function testMutantTruncatesDiffsBeyondTwiceTheListingLimit(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, str_repeat('d', 2500), ''), false);

        self::assertStringEndsWith('… [truncated]', $payload['diff']);
        self::assertSame(2400 + \strlen('… [truncated]'), \strlen($payload['diff']));
    }

    public function testSummaryOrdersGroupsByCountDescending(): void
    {
        $mutants = [
            new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, '', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-1', 'Minus', 'src/B.php', 1, '', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-2', 'Minus', 'src/B.php', 1, '', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-3', 'Increment', 'src/C.php', 1, '', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-4', 'Increment', 'src/C.php', 1, '', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-5', 'Increment', 'src/C.php', 1, '', ''),
        ];

        $summary = $this->formatter()->summary('r1', [], $mutants, 1);

        self::assertSame(['src/C.php' => 3, 'src/B.php' => 2, 'src/A.php' => 1], $summary['escapedByFile']);
        self::assertSame(['Increment' => 3, 'Minus' => 2, 'Plus' => 1], $summary['escapedByMutator']);
    }

    public function testSummaryKeepsTheLargestGroupsWhenLimiting(): void
    {
        $mutants = [];

        for ($i = 0; $i < 16; ++$i) {
            $mutants[] = new MutantResult(BucketEnum::Escaped, 'escaped-'.$i, 'Plus', \sprintf('src/File%02d.php', $i), 1, '', '');
        }

        $mutants[] = new MutantResult(BucketEnum::Escaped, 'escaped-16', 'Plus', 'src/File15.php', 1, '', '');

        $summary = $this->formatter()->summary('r1', [], $mutants, 1);

        self::assertCount(15, $summary['escapedByFile']);
        self::assertSame('src/File15.php', array_key_first($summary['escapedByFile']));
        self::assertSame(2, $summary['escapedByFile']['src/File15.php']);
    }

    public function testListingTruncationKeepsTheStartOfTheDiff(): void
    {
        $diff = 'a'.str_repeat('b', 1500);

        $listing = $this->formatter()->listing('r1', [new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, $diff, '')], 10, 0);

        self::assertSame('a'.str_repeat('b', 1199).'… [truncated]', $listing['mutants'][0]['diff']);
    }

    public function testListingTruncationNeverSplitsMultibyteCharacters(): void
    {
        $diff = 'x'.str_repeat('é', 700);

        $listing = $this->formatter()->listing('r1', [new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/A.php', 1, $diff, '')], 10, 0);

        self::assertSame('x'.str_repeat('é', 599).'… [truncated]', $listing['mutants'][0]['diff']);
    }

    public function testMutantContextIsEmptyForSiblingDirectorySharingTheRootPrefix(): void
    {
        $sibling = $this->root.'-evil';
        mkdir($sibling);
        file_put_contents($sibling.'/Other.php', "secret\n");

        $payload = $this->formatter()->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', $sibling.'/Other.php', 1, '-a', ''), false);

        self::assertSame([], $payload['context']);
    }

    public function testMutantContextIsEmptyWhenProjectRootDoesNotExist(): void
    {
        $outsideFile = $this->outside.'/Other.php';
        file_put_contents($outsideFile, "secret\n");

        // the link target is a real, readable file whose path starts with the slash an unresolved root would become
        $payload = (new ResultFormatter($this->root.'/missing-root', new FakePathResolver([$outsideFile => __FILE__])))->mutant('r1', new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', $outsideFile, 1, '-a', ''), false);

        self::assertSame([], $payload['context']);
    }

    private function formatter(): ResultFormatter
    {
        return new ResultFormatter($this->root, new FakePathResolver());
    }

    private function sourceFile(int $lines): void
    {
        $content = '';

        for ($i = 1; $i <= $lines; ++$i) {
            $content .= 'line '.$i."\n";
        }

        file_put_contents($this->root.'/src/Calc.php', $content);
    }

    /**
     * @return list<MutantResult>
     */
    private function mutants(): array
    {
        return [
            new MutantResult(BucketEnum::Escaped, 'escaped-0', 'GreaterThanOrEqualTo', 'src/A.php', 10, '-a', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-1', 'GreaterThanOrEqualTo', 'src/A.php', 20, '-b', ''),
            new MutantResult(BucketEnum::Escaped, 'escaped-2', 'Increment', 'src/B.php', 5, '-c', ''),
            new MutantResult(BucketEnum::Killed, 'killed-0', 'Plus', 'src/B.php', 6, '-d', ''),
            new MutantResult(BucketEnum::Timeouted, 'timeouted-0', 'WhileLoopNegation', 'src/C.php', 7, '-e', ''),
        ];
    }
}
