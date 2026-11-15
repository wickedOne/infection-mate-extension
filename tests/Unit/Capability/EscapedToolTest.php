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
use WickedOne\InfectionExtension\Cache\RunCache;
use WickedOne\InfectionExtension\Capability\EscapedTool;
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
        $this->tool = new EscapedTool($this->cache, new ResultFormatter($this->root, new FakePathResolver()));
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
        self::assertSame('Use one of: '.implode(', ', JsonLogParser::BUCKETS), $data['hint']);
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

    private function seedMany(int $count): string
    {
        $mutants = [];

        for ($i = 0; $i < $count; ++$i) {
            $mutants[] = new MutantResult('escaped', 'escaped-'.$i, 'Plus', 'src/File'.($i % 10).'.php', $i + 1, 'diff', '');
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
