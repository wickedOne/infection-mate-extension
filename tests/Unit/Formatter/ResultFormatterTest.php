<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Formatter;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Formatter\ResultFormatter;
use WickedOne\InfectionMateExtension\Parser\MutantResult;
use WickedOne\InfectionMateExtension\Tests\Support\FakePathResolver;

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

    public function testSummaryCountsEscapedMutantsByFileAndMutator(): void
    {
        $summary = $this->formatter()->summary('r1', ['msi' => 50], $this->mutants(), 1);

        $this->assertSame('r1', $summary['runId']);
        $this->assertSame(1, $summary['exitCode']);
        $this->assertSame(['msi' => 50], $summary['stats']);
        $this->assertSame(['src/A.php' => 2, 'src/B.php' => 1], $summary['escapedByFile']);
        $this->assertSame(['GreaterThanOrEqualTo' => 2, 'Increment' => 1], $summary['escapedByMutator']);
        $this->assertStringContainsString('infection-escaped', $summary['hint']);
    }

    public function testSummaryHintPointsToOtherBucketsWhenNothingEscaped(): void
    {
        $summary = $this->formatter()->summary('r1', [], [new MutantResult('killed', 0, 'Plus', 'src/B.php', 6, '', '')], 0);

        $this->assertSame([], $summary['escapedByFile']);
        $this->assertSame([], $summary['escapedByMutator']);
        $this->assertStringContainsString('uncovered', $summary['hint']);
    }

    public function testSummaryLimitsGroupingToFifteenEntries(): void
    {
        $mutants = [];

        for ($i = 0; $i < 20; ++$i) {
            $mutants[] = new MutantResult('escaped', $i, 'Plus', \sprintf('src/File%02d.php', $i), 1, '', '');
        }

        $summary = $this->formatter()->summary('r1', [], $mutants, 0);

        $this->assertCount(15, $summary['escapedByFile']);
    }

    public function testListingPagesThroughMutants(): void
    {
        $listing = $this->formatter()->listing('r1', $this->mutants(), 2, 2);

        $this->assertSame('r1', $listing['runId']);
        $this->assertSame(5, $listing['total']);
        $this->assertSame(2, $listing['offset']);
        $this->assertSame(2, $listing['count']);
        $this->assertSame(['escaped:2', 'killed:0'], array_column($listing['mutants'], 'ref'));
    }

    public function testListingReportsCountOfTrailingPage(): void
    {
        $listing = $this->formatter()->listing('r1', $this->mutants(), 10, 4);

        $this->assertSame(5, $listing['total']);
        $this->assertSame(1, $listing['count']);
        $this->assertSame('timeouted:0', $listing['mutants'][0]['ref']);
    }

    public function testListingReturnsEntryFieldsWithoutContext(): void
    {
        $listing = $this->formatter()->listing('r1', $this->mutants(), 1, 0);

        $this->assertSame(
            ['ref' => 'escaped:0', 'mutator' => 'GreaterThanOrEqualTo', 'file' => 'src/A.php', 'line' => 10, 'diff' => '-a'],
            $listing['mutants'][0],
        );
    }

    public function testListingTruncatesLongDiffs(): void
    {
        $long = str_repeat('x', 1500);
        $listing = $this->formatter()->listing('r1', [new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, $long, '')], 10, 0);

        $diff = $listing['mutants'][0]['diff'];
        $this->assertStringStartsWith(str_repeat('x', 1200), $diff);
        $this->assertStringEndsWith('… [truncated]', $diff);
        $this->assertSame(1200 + \strlen('… [truncated]'), \strlen($diff));
    }

    public function testListingKeepsDiffsAtTheLimitUntouched(): void
    {
        $exact = str_repeat('y', 1200);
        $listing = $this->formatter()->listing('r1', [new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, $exact, '')], 10, 0);

        $this->assertSame($exact, $listing['mutants'][0]['diff']);
    }

    public function testMutantReturnsContextAroundTheMutatedLine(): void
    {
        $this->sourceFile(30);

        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', 'src/Calc.php', 15, '-a', ''), false);

        $this->assertSame('escaped:0', $payload['ref']);
        $this->assertSame(13, \count($payload['context']));
        $this->assertSame('9: line 9', $payload['context'][0]);
        $this->assertSame('15: line 15', $payload['context'][6]);
        $this->assertSame('21: line 21', $payload['context'][12]);
    }

    public function testMutantContextStartsAtFirstLineNearTop(): void
    {
        $this->sourceFile(30);

        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', 'src/Calc.php', 3, '-a', ''), false);

        $this->assertSame('1: line 1', $payload['context'][0]);
        $this->assertSame(13, \count($payload['context']));
    }

    public function testMutantContextAcceptsAbsolutePathInsideRoot(): void
    {
        $this->sourceFile(30);

        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', $this->root.'/src/Calc.php', 15, '-a', ''), false);

        $this->assertSame('9: line 9', $payload['context'][0]);
    }

    public function testMutantContextIsEmptyForFileOutsideRoot(): void
    {
        $outsideFile = $this->outside.'/Other.php';
        file_put_contents($outsideFile, "secret\nvalue\n");

        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', $outsideFile, 1, '-a', ''), false);

        $this->assertSame([], $payload['context']);
    }

    public function testMutantContextIsEmptyForMissingFile(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', 'src/Missing.php', 1, '-a', ''), false);

        $this->assertSame([], $payload['context']);
    }

    public function testMutantOmitsProcessOutputByDefault(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, '-a', 'FAILURES!'), false);

        $this->assertArrayNotHasKey('processOutput', $payload);
    }

    public function testMutantIncludesProcessOutputWhenRequested(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, '-a', 'FAILURES!'), true);

        $this->assertSame('FAILURES!', $payload['processOutput']);
    }

    public function testMutantTruncatesLongProcessOutput(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, '', str_repeat('o', 2500)), true);

        $this->assertStringStartsWith(str_repeat('o', 2000), $payload['processOutput']);
        $this->assertStringEndsWith('… [truncated]', $payload['processOutput']);
        $this->assertSame(2000 + \strlen('… [truncated]'), \strlen($payload['processOutput']));
    }

    public function testMutantAllowsDiffsUpToTwiceTheListingLimit(): void
    {
        $diff = str_repeat('d', 2400);

        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, $diff, ''), false);

        $this->assertSame($diff, $payload['diff']);
    }

    public function testMutantTruncatesDiffsBeyondTwiceTheListingLimit(): void
    {
        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, str_repeat('d', 2500), ''), false);

        $this->assertStringEndsWith('… [truncated]', $payload['diff']);
        $this->assertSame(2400 + \strlen('… [truncated]'), \strlen($payload['diff']));
    }

    public function testSummaryOrdersGroupsByCountDescending(): void
    {
        $mutants = [
            new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, '', ''),
            new MutantResult('escaped', 1, 'Minus', 'src/B.php', 1, '', ''),
            new MutantResult('escaped', 2, 'Minus', 'src/B.php', 1, '', ''),
            new MutantResult('escaped', 3, 'Increment', 'src/C.php', 1, '', ''),
            new MutantResult('escaped', 4, 'Increment', 'src/C.php', 1, '', ''),
            new MutantResult('escaped', 5, 'Increment', 'src/C.php', 1, '', ''),
        ];

        $summary = $this->formatter()->summary('r1', [], $mutants, 1);

        $this->assertSame(['src/C.php' => 3, 'src/B.php' => 2, 'src/A.php' => 1], $summary['escapedByFile']);
        $this->assertSame(['Increment' => 3, 'Minus' => 2, 'Plus' => 1], $summary['escapedByMutator']);
    }

    public function testSummaryKeepsTheLargestGroupsWhenLimiting(): void
    {
        $mutants = [];

        for ($i = 0; $i < 16; ++$i) {
            $mutants[] = new MutantResult('escaped', $i, 'Plus', \sprintf('src/File%02d.php', $i), 1, '', '');
        }

        $mutants[] = new MutantResult('escaped', 16, 'Plus', 'src/File15.php', 1, '', '');

        $summary = $this->formatter()->summary('r1', [], $mutants, 1);

        $this->assertCount(15, $summary['escapedByFile']);
        $this->assertSame('src/File15.php', array_key_first($summary['escapedByFile']));
        $this->assertSame(2, $summary['escapedByFile']['src/File15.php']);
    }

    public function testListingTruncationKeepsTheStartOfTheDiff(): void
    {
        $diff = 'a'.str_repeat('b', 1500);

        $listing = $this->formatter()->listing('r1', [new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, $diff, '')], 10, 0);

        $this->assertSame('a'.str_repeat('b', 1199).'… [truncated]', $listing['mutants'][0]['diff']);
    }

    public function testListingTruncationNeverSplitsMultibyteCharacters(): void
    {
        $diff = 'x'.str_repeat('é', 700);

        $listing = $this->formatter()->listing('r1', [new MutantResult('escaped', 0, 'Plus', 'src/A.php', 1, $diff, '')], 10, 0);

        $this->assertSame('x'.str_repeat('é', 599).'… [truncated]', $listing['mutants'][0]['diff']);
    }

    public function testMutantContextIsEmptyForSiblingDirectorySharingTheRootPrefix(): void
    {
        $sibling = $this->root.'-evil';
        mkdir($sibling);
        file_put_contents($sibling.'/Other.php', "secret\n");

        $payload = $this->formatter()->mutant('r1', new MutantResult('escaped', 0, 'Plus', $sibling.'/Other.php', 1, '-a', ''), false);

        $this->assertSame([], $payload['context']);
    }

    public function testMutantContextIsEmptyWhenProjectRootDoesNotExist(): void
    {
        $outsideFile = $this->outside.'/Other.php';
        file_put_contents($outsideFile, "secret\n");

        // the link target is a real, readable file whose path starts with the slash an unresolved root would become
        $payload = (new ResultFormatter($this->root.'/missing-root', new FakePathResolver([$outsideFile => __FILE__])))->mutant('r1', new MutantResult('escaped', 0, 'Plus', $outsideFile, 1, '-a', ''), false);

        $this->assertSame([], $payload['context']);
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
            new MutantResult('escaped', 0, 'GreaterThanOrEqualTo', 'src/A.php', 10, '-a', ''),
            new MutantResult('escaped', 1, 'GreaterThanOrEqualTo', 'src/A.php', 20, '-b', ''),
            new MutantResult('escaped', 2, 'Increment', 'src/B.php', 5, '-c', ''),
            new MutantResult('killed', 0, 'Plus', 'src/B.php', 6, '-d', ''),
            new MutantResult('timeouted', 0, 'WhileLoopNegation', 'src/C.php', 7, '-e', ''),
        ];
    }
}
