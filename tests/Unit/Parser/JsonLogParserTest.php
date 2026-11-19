<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Parser\JsonLogParser;
use WickedOne\InfectionExtension\Parser\MutantResult;
use WickedOne\InfectionExtension\Tests\Support\FakePathResolver;

class JsonLogParserTest extends TestCase
{
    private JsonLogParser $parser;
    private string $fixture;

    protected function setUp(): void
    {
        $this->parser = new JsonLogParser(new FakePathResolver());
        $this->fixture = (string) file_get_contents(__DIR__.'/../../Fixtures/infection-log.json');
    }

    protected function tearDown(): void
    {
        unset($this->parser, $this->fixture);
    }

    public function testReturnsStatsFromLog(): void
    {
        $parsed = $this->parser->parse($this->fixture);

        self::assertSame(6, $parsed['stats']['totalMutantsCount']);
        self::assertSame(50, $parsed['stats']['msi']);
        self::assertSame(2, $parsed['stats']['escapedCount']);
    }

    public function testCollectsMutantsFromAllBucketsInBucketOrder(): void
    {
        $refs = array_map(static fn (MutantResult $m): string => $m->id, $this->parser->parse($this->fixture)['mutants']);

        self::assertSame(['escaped-0', 'escaped-1', 'timeouted-0', 'killed-0', 'killed-1', 'uncovered-0'], $refs);
    }

    public function testMapsEntryFieldsToMutantResult(): void
    {
        $mutant = $this->parser->parse($this->fixture)['mutants'][0];

        self::assertSame(BucketEnum::Escaped, $mutant->bucket);
        self::assertSame('escaped-0', $mutant->id);
        self::assertSame('GreaterThanOrEqualTo', $mutant->mutator);
        self::assertSame(12, $mutant->line);
        self::assertStringContainsString('-        if ($value >= $this->max) {', $mutant->diff);
        self::assertStringContainsString('PHPUnit 10.5.0', $mutant->processOutput);
    }

    public function testStripsProjectRootFromFilePaths(): void
    {
        $mutants = $this->parser->parse($this->fixture, '/project')['mutants'];

        self::assertSame('src/Calc.php', $mutants[0]->file);
        self::assertSame('src/Str/Slugger.php', $mutants[1]->file);
    }

    public function testStripsTheResolvedProjectRootWhenTheConfiguredOneIsASymlink(): void
    {
        $parser = new JsonLogParser(new FakePathResolver(['/link/project' => '/project']));

        $mutants = $parser->parse($this->fixture, '/link/project')['mutants'];

        self::assertSame('src/Calc.php', $mutants[0]->file);
        self::assertSame('src/Str/Slugger.php', $mutants[1]->file);
    }

    public function testStillStripsTheConfiguredProjectRootWhenItIsReportedAsIs(): void
    {
        $parser = new JsonLogParser(new FakePathResolver(['/project' => '/elsewhere']));

        self::assertSame('src/Calc.php', $parser->parse($this->fixture, '/project')['mutants'][0]->file);
    }

    public function testKeepsAbsoluteFilePathsWithoutProjectRoot(): void
    {
        $mutants = $this->parser->parse($this->fixture)['mutants'];

        self::assertSame('/project/src/Calc.php', $mutants[0]->file);
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

        self::assertSame('/project-other/src/A.php', $mutant->file);
    }

    public function testFallsBackToDefaultsForMissingFields(): void
    {
        $log = json_encode(['stats' => [], 'killed' => [[]]], \JSON_THROW_ON_ERROR);

        $mutant = $this->parser->parse((string) $log)['mutants'][0];

        self::assertSame(BucketEnum::Killed, $mutant->bucket);
        self::assertSame('unknown', $mutant->mutator);
        self::assertSame('', $mutant->file);
        self::assertSame(0, $mutant->line);
        self::assertSame('', $mutant->diff);
        self::assertSame('', $mutant->processOutput);
    }

    public function testIgnoresUnknownTopLevelKeys(): void
    {
        $log = json_encode(['stats' => ['msi' => 100], 'somethingElse' => [['mutator' => []]]], \JSON_THROW_ON_ERROR);

        $parsed = $this->parser->parse((string) $log);

        self::assertSame([], $parsed['mutants']);
    }

    public function testCastsEntryValuesToTheirTypes(): void
    {
        $log = json_encode([
            'stats' => [],
            'escaped' => [[
                'id' => ['x'],
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

        self::assertSame(['Array', 'Array', 'Array', 1, 'Array', 'Array'], [$mutant->id, $mutant->mutator, $mutant->file, $mutant->line, $mutant->diff, $mutant->processOutput]);
    }

    public function testReadsIdPerEntryRegardlessOfLogKeys(): void
    {
        $log = json_encode(['stats' => [], 'escaped' => ['5' => ['id' => 'abc', 'mutator' => ['mutatorName' => 'Plus']], '9' => ['id' => 'def']]], \JSON_THROW_ON_ERROR);

        $ids = array_map(static fn (MutantResult $m): string => $m->id, $this->parser->parse($log)['mutants']);

        self::assertSame(['abc', 'def'], $ids);
    }

    public function testEntryWithoutIdGetsAnEmptyId(): void
    {
        $log = json_encode(['stats' => [], 'escaped' => [['mutator' => ['mutatorName' => 'Plus']]]], \JSON_THROW_ON_ERROR);

        self::assertSame('', $this->parser->parse($log)['mutants'][0]->id);
    }

    public function testAcceptsLogsNestedUpToFiveHundredAndTwelveLevels(): void
    {
        $nested = str_repeat('[', 510).str_repeat(']', 510);

        $parsed = $this->parser->parse('{"stats":{},"extra":'.$nested.'}');

        self::assertSame([], $parsed['mutants']);
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
