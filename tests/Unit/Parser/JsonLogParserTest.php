<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Parser\JsonLogParser;
use WickedOne\InfectionMateExtension\Parser\MutantResult;
use WickedOne\InfectionMateExtension\Tests\Support\FakePathResolver;

class JsonLogParserTest extends TestCase
{
    private JsonLogParser $parser;
    private string $fixture;

    protected function setUp(): void
    {
        $this->parser = new JsonLogParser(new FakePathResolver());
        $this->fixture = (string) file_get_contents(__DIR__.'/../../Fixtures/infection-log.json');
    }

    public function testReturnsStatsFromLog(): void
    {
        $parsed = $this->parser->parse($this->fixture);

        $this->assertSame(6, $parsed['stats']['totalMutantsCount']);
        $this->assertSame(50, $parsed['stats']['msi']);
        $this->assertSame(2, $parsed['stats']['escapedCount']);
    }

    public function testCollectsMutantsFromAllBucketsInBucketOrder(): void
    {
        $refs = array_map(static fn (MutantResult $m): string => $m->ref(), $this->parser->parse($this->fixture)['mutants']);

        $this->assertSame(['escaped:0', 'escaped:1', 'timeouted:0', 'killed:0', 'killed:1', 'uncovered:0'], $refs);
    }

    public function testMapsEntryFieldsToMutantResult(): void
    {
        $mutant = $this->parser->parse($this->fixture)['mutants'][0];

        $this->assertSame('escaped', $mutant->bucket);
        $this->assertSame(0, $mutant->index);
        $this->assertSame('GreaterThanOrEqualTo', $mutant->mutator);
        $this->assertSame(12, $mutant->line);
        $this->assertStringContainsString('-        if ($value >= $this->max) {', $mutant->diff);
        $this->assertStringContainsString('PHPUnit 10.5.0', $mutant->processOutput);
    }

    public function testStripsProjectRootFromFilePaths(): void
    {
        $mutants = $this->parser->parse($this->fixture, '/project')['mutants'];

        $this->assertSame('src/Calc.php', $mutants[0]->file);
        $this->assertSame('src/Str/Slugger.php', $mutants[1]->file);
    }

    public function testStripsTheResolvedProjectRootWhenTheConfiguredOneIsASymlink(): void
    {
        $parser = new JsonLogParser(new FakePathResolver(['/link/project' => '/project']));

        $mutants = $parser->parse($this->fixture, '/link/project')['mutants'];

        $this->assertSame('src/Calc.php', $mutants[0]->file);
        $this->assertSame('src/Str/Slugger.php', $mutants[1]->file);
    }

    public function testStillStripsTheConfiguredProjectRootWhenItIsReportedAsIs(): void
    {
        $parser = new JsonLogParser(new FakePathResolver(['/project' => '/elsewhere']));

        $this->assertSame('src/Calc.php', $parser->parse($this->fixture, '/project')['mutants'][0]->file);
    }

    public function testKeepsAbsoluteFilePathsWithoutProjectRoot(): void
    {
        $mutants = $this->parser->parse($this->fixture)['mutants'];

        $this->assertSame('/project/src/Calc.php', $mutants[0]->file);
    }

    public function testDoesNotStripPrefixThatIsNotAPathSegment(): void
    {
        $log = json_encode([
            'stats' => [],
            'escaped' => [
                ['mutator' => ['mutatorName' => 'Plus', 'originalFilePath' => '/project-other/src/A.php', 'originalStartLine' => 1]],
            ],
        ], \JSON_THROW_ON_ERROR);

        $mutant = $this->parser->parse((string) $log, '/project')['mutants'][0];

        $this->assertSame('/project-other/src/A.php', $mutant->file);
    }

    public function testFallsBackToDefaultsForMissingFields(): void
    {
        $log = json_encode(['stats' => [], 'killed' => [[]]], \JSON_THROW_ON_ERROR);

        $mutant = $this->parser->parse((string) $log)['mutants'][0];

        $this->assertSame('killed', $mutant->bucket);
        $this->assertSame('unknown', $mutant->mutator);
        $this->assertSame('', $mutant->file);
        $this->assertSame(0, $mutant->line);
        $this->assertSame('', $mutant->diff);
        $this->assertSame('', $mutant->processOutput);
    }

    public function testIgnoresUnknownTopLevelKeys(): void
    {
        $log = json_encode(['stats' => ['msi' => 100], 'somethingElse' => [['mutator' => []]]], \JSON_THROW_ON_ERROR);

        $parsed = $this->parser->parse((string) $log);

        $this->assertSame([], $parsed['mutants']);
    }

    public function testCastsEntryValuesToTheirTypes(): void
    {
        $log = json_encode([
            'stats' => [],
            'escaped' => [[
                'mutator' => ['mutatorName' => ['x'], 'originalFilePath' => ['x'], 'originalStartLine' => ['x']],
                'diff' => ['x'],
                'processOutput' => ['x'],
            ]],
        ], \JSON_THROW_ON_ERROR);

        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            $mutant = $this->parser->parse($log)['mutants'][0];
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['Array', 'Array', 1, 'Array', 'Array'], [$mutant->mutator, $mutant->file, $mutant->line, $mutant->diff, $mutant->processOutput]);
    }

    public function testIndexesEntriesFromZeroPerBucketRegardlessOfLogKeys(): void
    {
        $entry = ['mutator' => ['mutatorName' => 'Plus']];
        $log = json_encode(['stats' => [], 'escaped' => ['5' => $entry, '9' => $entry]], \JSON_THROW_ON_ERROR);

        $refs = array_map(static fn (MutantResult $m): string => $m->ref(), $this->parser->parse($log)['mutants']);

        $this->assertSame(['escaped:0', 'escaped:1'], $refs);
    }

    public function testAcceptsLogsNestedUpToFiveHundredAndTwelveLevels(): void
    {
        $nested = str_repeat('[', 510).str_repeat(']', 510);

        $parsed = $this->parser->parse('{"stats":{},"extra":'.$nested.'}');

        $this->assertSame([], $parsed['mutants']);
    }

    public function testRejectsLogsNestedBeyondFiveHundredAndTwelveLevels(): void
    {
        $nested = str_repeat('[', 511).str_repeat(']', 511);

        $this->expectException(\JsonException::class);

        $this->parser->parse('{"stats":{},"extra":'.$nested.'}');
    }

    public function testThrowsWhenStatsAreMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not an Infection JSON log');

        $this->parser->parse('{"escaped": []}');
    }

    public function testThrowsWhenStatsIsNotAnObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->parser->parse('{"stats": 5}');
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        $this->parser->parse('{not json');
    }
}
