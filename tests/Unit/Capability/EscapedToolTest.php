<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Capability;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionExtension\Cache\OutputStore;
use WickedOne\InfectionExtension\Cache\RunCache;
use WickedOne\InfectionExtension\Capability\EscapedTool;
use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Formatter\ResultFormatter;
use WickedOne\InfectionExtension\Parser\JsonLogParser;
use WickedOne\InfectionExtension\Parser\MutantResult;
use WickedOne\InfectionExtension\Tests\Support\FakePathResolver;

class EscapedToolTest extends TestCase
{
    private string $root;
    private string $cacheDirectory;
    private RunCache $cache;
    private EscapedTool $tool;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('project')->url();
        $this->cacheDirectory = $this->root.'/cache';
        $this->cache = new RunCache($this->cacheDirectory);
        $this->tool = new EscapedTool($this->cache, new ResultFormatter($this->root, new FakePathResolver()), new OutputStore($this->root.'/outputs'));

        mkdir($this->root.'/src', 0o775, true);
        file_put_contents($this->root.'/src/Calc.php', implode("\n", array_map(static fn (int $i): string => 'line '.$i, range(1, 30)))."\n");
    }

    protected function tearDown(): void
    {
        unset($this->root, $this->cacheDirectory, $this->cache, $this->tool);
    }

    public function testListsEscapedMutantsByDefault(): void
    {
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute());

        self::assertSame($runId, $data['runId']);
        self::assertSame(2, $data['total']);
        self::assertSame(['escaped-0', 'escaped-1'], array_column($data['mutants'], 'id'));
    }

    public function testListsOnlyTheIdsOfThePageWhenAsked(): void
    {
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute(idsOnly: true));

        self::assertSame(['runId' => $runId, 'total' => 2, 'offset' => 0, 'count' => 2, 'ids' => ['escaped-0', 'escaped-1']], $data);
    }

    public function testIdsOnlyHonoursThePaging(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(limit: 1, offset: 1, idsOnly: true));

        self::assertSame(2, $data['total']);
        self::assertSame(1, $data['offset']);
        self::assertSame(['escaped-1'], $data['ids']);
    }

    public function testListsFromLatestRunWhenRunIdIsOmitted(): void
    {
        $this->seedRun();
        $newer = $this->seedRun();

        $data = $this->untrusted($this->tool->execute());

        self::assertSame($newer, $data['runId']);
    }

    public function testListsRequestedRunById(): void
    {
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute($runId, 'killed'));

        self::assertSame($runId, $data['runId']);
    }

    public function testFiltersByBucket(): void
    {
        $this->seedRun();

        $timeouted = $this->untrusted($this->tool->execute(null, 'timeouted'));
        $killed = $this->untrusted($this->tool->execute(null, 'killed'));
        $uncovered = $this->untrusted($this->tool->execute(null, 'uncovered'));

        self::assertSame(['timeouted-0'], array_column($timeouted['mutants'], 'id'));
        self::assertSame(['killed-0', 'killed-1'], array_column($killed['mutants'], 'id'));
        self::assertSame(['uncovered-0'], array_column($uncovered['mutants'], 'id'));
    }

    public function testReturnsEmptyListForBucketWithoutMutants(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'errored'));

        self::assertSame(0, $data['total']);
        self::assertSame([], $data['mutants']);
    }

    public function testFiltersByFile(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', 'src/Calc.php'));

        self::assertSame(1, $data['total']);
        self::assertSame('escaped-0', $data['mutants'][0]['id']);
    }

    public function testFiltersByMutator(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', null, 'Increment'));

        self::assertSame(1, $data['total']);
        self::assertSame('escaped-1', $data['mutants'][0]['id']);
    }

    public function testPagesWithLimitAndOffset(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', null, null, 1, 1));

        self::assertSame(2, $data['total']);
        self::assertSame(1, $data['offset']);
        self::assertSame(1, $data['count']);
        self::assertSame('escaped-1', $data['mutants'][0]['id']);
    }

    public function testClampsNonPositiveLimitToOne(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', null, null, 0));

        self::assertSame(1, $data['count']);
    }

    public function testClampsNegativeOffsetToZero(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', null, null, 20, -5));

        self::assertSame(0, $data['offset']);
        self::assertSame(2, $data['count']);
    }

    public function testReturnsErrorForUnknownBucket(): void
    {
        $this->seedRun();

        $data = $this->decode($this->tool->execute(null, 'bogus'));

        self::assertSame('Unknown bucket "bogus".', $data['error']);
        self::assertSame('Use one of: '.implode(', ', array_column(BucketEnum::cases(), 'value')), $data['hint']);
        self::assertArrayNotHasKey('untrusted_data', $data);
    }

    public function testReturnsErrorForUnknownRun(): void
    {
        $ids = [];
        for ($i = 0; $i < 7; ++$i) {
            $ids[] = $this->seedRun();
        }

        $data = $this->decode($this->tool->execute('r000000000'));

        self::assertSame('Run not found.', $data['error']);
        self::assertSame('Call infection-run first. Recent run ids: '.implode(', ', \array_slice(array_reverse($ids), 0, 5)), $data['hint']);
    }

    public function testReturnsErrorWhenNoRunExists(): void
    {
        $data = $this->decode($this->tool->execute());

        self::assertSame('Run not found.', $data['error']);
        self::assertSame('Call infection-run first. Recent run ids: ', $data['hint']);
    }

    public function testPagesTwentyMutantsByDefault(): void
    {
        $this->seedMany(150);

        $data = $this->untrusted($this->tool->execute());

        self::assertSame(150, $data['total']);
        self::assertSame(20, $data['count']);
        self::assertSame(0, $data['offset']);
        self::assertSame('escaped-0', $data['mutants'][0]['id']);
        self::assertSame('escaped-19', $data['mutants'][19]['id']);
    }

    public function testClampsLimitToOneHundred(): void
    {
        $this->seedMany(150);

        self::assertSame(1, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 1))['count']);
        self::assertSame(99, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 99))['count']);
        self::assertSame(100, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 100))['count']);
        self::assertSame(100, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 101))['count']);
        self::assertSame(100, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 100000))['count']);
    }

    public function testClampsNegativeLimitToOne(): void
    {
        $this->seedMany(5);

        self::assertSame(1, $this->untrusted($this->tool->execute(null, 'escaped', null, null, -10))['count']);
    }

    public function testPaginatesFilteredMutantsFromTheMatchingPosition(): void
    {
        $this->seedMany(30);

        $data = $this->untrusted($this->tool->execute(null, 'escaped', 'src/File1.php', null, 2, 1));

        self::assertSame(3, $data['total']);
        self::assertSame(['escaped-11', 'escaped-21'], array_column($data['mutants'], 'id'));
    }

    public function testListsMutantsWithExactKeys(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute());

        self::assertSame(['runId', 'total', 'offset', 'count', 'mutants'], array_keys($data));
        self::assertSame(['id', 'mutator', 'file', 'line', 'diff'], array_keys($data['mutants'][0]));
    }

    public function testOffsetDefaultsToZero(): void
    {
        $parameter = new \ReflectionMethod(EscapedTool::class, 'execute');

        $defaults = [];

        foreach ($parameter->getParameters() as $p) {
            $defaults[$p->getName()] = $p->getDefaultValue();
        }

        self::assertSame(0, $defaults['offset']);
    }

    public function testIdReturnsMutantDetailsFromLatestRun(): void
    {
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute(id: 'escaped-0'));

        self::assertSame($runId, $data['runId']);
        self::assertSame('escaped-0', $data['id']);
        self::assertSame('escaped', $data['bucket']);
        self::assertSame('GreaterThanOrEqualTo', $data['mutator']);
        self::assertSame('src/Calc.php', $data['file']);
        self::assertSame(12, $data['line']);
        self::assertStringContainsString('$value > $this->max', $data['diff']);
    }

    public function testIdIncludesSurroundingSourceLines(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(id: 'escaped-0'));

        self::assertCount(13, $data['context']);
        self::assertSame('6: line 6', $data['context'][0]);
        self::assertSame('12: line 12', $data['context'][6]);
        self::assertSame('18: line 18', $data['context'][12]);
    }

    public function testIdOmitsProcessOutputByDefault(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(id: 'escaped-0'));

        self::assertArrayNotHasKey('processOutput', $data);
    }

    public function testIdIncludesProcessOutputWhenRequested(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(id: 'escaped-0', withOutput: true));

        self::assertStringContainsString('FAILURES!', $data['processOutput']);
    }

    public function testIdIsLookedUpInTheRequestedRun(): void
    {
        $this->seedRun();
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute($runId, id: 'killed-1'));

        self::assertSame($runId, $data['runId']);
        self::assertSame('ReturnRemoveNonVoid', $data['mutator']);
    }

    public function testIdReturnsErrorForUnknownMutant(): void
    {
        $runId = $this->seedRun();

        $data = $this->decode($this->tool->execute(id: 'escaped-9'));

        self::assertSame(\sprintf('Mutant "escaped-9" not found in run %s.', $runId), $data['error']);
        self::assertStringContainsString('id from infection-escaped', $data['hint']);
        self::assertArrayNotHasKey('untrusted_data', $data);
    }

    public function testIdReturnsErrorForUnknownRun(): void
    {
        $ids = [];

        for ($i = 0; $i < 7; ++$i) {
            $ids[] = $this->seedRun();
        }

        $data = $this->decode($this->tool->execute('r000000000', id: 'escaped-0'));

        self::assertSame('Run not found.', $data['error']);
        self::assertSame('Call infection-run first. Recent run ids: '.implode(', ', \array_slice(array_reverse($ids), 0, 5)), $data['hint']);
    }

    public function testIdReturnsEmptyRecentRunsHintWhenNoRunExists(): void
    {
        $data = $this->decode($this->tool->execute(id: 'escaped-0'));

        self::assertSame('Call infection-run first. Recent run ids: ', $data['hint']);
    }

    public function testIdIgnoresBucketFileMutatorAndPaging(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(bucket: 'nonsense', file: 'src/Other.php', mutator: 'Nope', limit: 1, offset: 5, idsOnly: true, id: 'killed-1'));

        self::assertSame('killed-1', $data['id']);
        self::assertSame('killed', $data['bucket']);
    }

    public function testIdKeepsTheFullProcessOutputInAFileWhenTheExcerptCutsIt(): void
    {
        $long = 'FAILURES!'.str_repeat("\nfailing line", 1000);
        $this->cache->store([], [new MutantResult(BucketEnum::Escaped, 'noisy', 'Plus', 'src/Calc.php', 3, '-a', $long)], 1);

        $data = $this->untrusted($this->tool->execute(id: 'noisy', withOutput: true));

        self::assertStringStartsWith($this->root.'/outputs/', $data['processOutputFile']);
        self::assertStringEndsWith('-mutant-noisy.log', $data['processOutputFile']);
        self::assertSame($long, file_get_contents($data['processOutputFile']));
        self::assertStringContainsString($data['processOutputFile'], $data['processOutput']);
    }

    public function testIdWritesNoFileWhenTheExcerptHoldsTheWholeOutput(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(id: 'escaped-0', withOutput: true));

        self::assertStringContainsString('FAILURES!', $data['processOutput']);
        self::assertArrayNotHasKey('processOutputFile', $data);
        self::assertDirectoryDoesNotExist($this->root.'/outputs');
    }

    public function testIdWritesNoFileWithoutWithOutput(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(id: 'escaped-0'));

        self::assertArrayNotHasKey('processOutputFile', $data);
        self::assertDirectoryDoesNotExist($this->root.'/outputs');
    }

    public function testIdWritesNoFileForAMutantWithoutOutput(): void
    {
        $this->cache->store([], [new MutantResult(BucketEnum::Escaped, 'quiet', 'Plus', 'src/Calc.php', 3, '-a', '')], 1);

        $data = $this->untrusted($this->tool->execute(id: 'quiet', withOutput: true));

        self::assertSame('', $data['processOutput']);
        self::assertArrayNotHasKey('processOutputFile', $data);
        self::assertDirectoryDoesNotExist($this->root.'/outputs');
    }

    private function seedMany(int $count): string
    {
        $mutants = [];

        for ($i = 0; $i < $count; ++$i) {
            $mutants[] = new MutantResult(BucketEnum::Escaped, 'escaped-'.$i, 'Plus', 'src/File'.($i % 10).'.php', $i + 1, 'diff', '');
        }

        return $this->cache->store(['msi' => 0], $mutants, 1);
    }

    private function seedRun(): string
    {
        $parsed = (new JsonLogParser(new FakePathResolver()))->parse((string) file_get_contents(__DIR__.'/../../Fixtures/infection-log.json'), '/project');

        return $this->cache->store($parsed['stats'], $parsed['mutants'], 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function untrusted(string $response): array
    {
        $decoded = ResponseEncoder::decode($response);
        self::assertIsArray($decoded);
        self::assertSame(ResponseEncoder::UNTRUSTED_NOTICE, $decoded['_security_notice']);
        self::assertIsArray($decoded['untrusted_data']);

        return $decoded['untrusted_data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $response): array
    {
        $decoded = ResponseEncoder::decode($response);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
