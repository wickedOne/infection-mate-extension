<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Capability;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionMateExtension\Cache\RunCache;
use WickedOne\InfectionMateExtension\Capability\EscapedTool;
use WickedOne\InfectionMateExtension\Formatter\ResultFormatter;
use WickedOne\InfectionMateExtension\Parser\JsonLogParser;
use WickedOne\InfectionMateExtension\Parser\MutantResult;
use WickedOne\InfectionMateExtension\Tests\Support\FakePathResolver;

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

    public function testListsEscapedMutantsByDefault(): void
    {
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame($runId, $data['runId']);
        $this->assertSame(2, $data['total']);
        $this->assertSame(['escaped:0', 'escaped:1'], array_column($data['mutants'], 'ref'));
    }

    public function testListsFromLatestRunWhenRunIdIsOmitted(): void
    {
        $this->seedRun();
        $newer = $this->seedRun();

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame($newer, $data['runId']);
    }

    public function testListsRequestedRunById(): void
    {
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute($runId, 'killed'));

        $this->assertSame($runId, $data['runId']);
    }

    public function testFiltersByBucket(): void
    {
        $this->seedRun();

        $timeouted = $this->untrusted($this->tool->execute(null, 'timeouted'));
        $killed = $this->untrusted($this->tool->execute(null, 'killed'));
        $uncovered = $this->untrusted($this->tool->execute(null, 'uncovered'));

        $this->assertSame(['timeouted:0'], array_column($timeouted['mutants'], 'ref'));
        $this->assertSame(['killed:0', 'killed:1'], array_column($killed['mutants'], 'ref'));
        $this->assertSame(['uncovered:0'], array_column($uncovered['mutants'], 'ref'));
    }

    public function testReturnsEmptyListForBucketWithoutMutants(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'errored'));

        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['mutants']);
    }

    public function testFiltersByFile(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', 'src/Calc.php'));

        $this->assertSame(1, $data['total']);
        $this->assertSame('escaped:0', $data['mutants'][0]['ref']);
    }

    public function testFiltersByMutator(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', null, 'Increment'));

        $this->assertSame(1, $data['total']);
        $this->assertSame('escaped:1', $data['mutants'][0]['ref']);
    }

    public function testPagesWithLimitAndOffset(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', null, null, 1, 1));

        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['offset']);
        $this->assertSame(1, $data['count']);
        $this->assertSame('escaped:1', $data['mutants'][0]['ref']);
    }

    public function testClampsNonPositiveLimitToOne(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', null, null, 0));

        $this->assertSame(1, $data['count']);
    }

    public function testClampsNegativeOffsetToZero(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute(null, 'escaped', null, null, 20, -5));

        $this->assertSame(0, $data['offset']);
        $this->assertSame(2, $data['count']);
    }

    public function testReturnsErrorForUnknownBucket(): void
    {
        $this->seedRun();

        $data = $this->decode($this->tool->execute(null, 'bogus'));

        $this->assertSame('Unknown bucket "bogus".', $data['error']);
        $this->assertSame('Use one of: '.implode(', ', JsonLogParser::BUCKETS), $data['hint']);
        $this->assertArrayNotHasKey('untrusted_data', $data);
    }

    public function testReturnsErrorForUnknownRun(): void
    {
        $ids = [];
        for ($i = 0; $i < 7; ++$i) {
            $ids[] = $this->seedRun();
        }

        $data = $this->decode($this->tool->execute('r000000000'));

        $this->assertSame('Run not found.', $data['error']);
        $this->assertSame('Call infection-run first. Recent run ids: '.implode(', ', \array_slice(array_reverse($ids), 0, 5)), $data['hint']);
    }

    public function testReturnsErrorWhenNoRunExists(): void
    {
        $data = $this->decode($this->tool->execute());

        $this->assertSame('Run not found.', $data['error']);
        $this->assertSame('Call infection-run first. Recent run ids: ', $data['hint']);
    }

    public function testPagesTwentyMutantsByDefault(): void
    {
        $this->seedMany(150);

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame(150, $data['total']);
        $this->assertSame(20, $data['count']);
        $this->assertSame(0, $data['offset']);
        $this->assertSame('escaped:0', $data['mutants'][0]['ref']);
        $this->assertSame('escaped:19', $data['mutants'][19]['ref']);
    }

    public function testClampsLimitToOneHundred(): void
    {
        $this->seedMany(150);

        $this->assertSame(1, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 1))['count']);
        $this->assertSame(99, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 99))['count']);
        $this->assertSame(100, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 100))['count']);
        $this->assertSame(100, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 101))['count']);
        $this->assertSame(100, $this->untrusted($this->tool->execute(null, 'escaped', null, null, 100000))['count']);
    }

    public function testClampsNegativeLimitToOne(): void
    {
        $this->seedMany(5);

        $this->assertSame(1, $this->untrusted($this->tool->execute(null, 'escaped', null, null, -10))['count']);
    }

    public function testPaginatesFilteredMutantsFromTheMatchingPosition(): void
    {
        $this->seedMany(30);

        $data = $this->untrusted($this->tool->execute(null, 'escaped', 'src/File1.php', null, 2, 1));

        $this->assertSame(3, $data['total']);
        $this->assertSame(['escaped:11', 'escaped:21'], array_column($data['mutants'], 'ref'));
    }

    public function testListsMutantsWithExactKeys(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame(['runId', 'total', 'offset', 'count', 'mutants'], array_keys($data));
        $this->assertSame(['ref', 'mutator', 'file', 'line', 'diff'], array_keys($data['mutants'][0]));
    }

    public function testOffsetDefaultsToZero(): void
    {
        $parameter = new \ReflectionMethod(EscapedTool::class, 'execute');

        $defaults = [];

        foreach ($parameter->getParameters() as $p) {
            $defaults[$p->getName()] = $p->getDefaultValue();
        }

        $this->assertSame(0, $defaults['offset']);
    }

    private function seedMany(int $count): string
    {
        $mutants = [];

        for ($i = 0; $i < $count; ++$i) {
            $mutants[] = new MutantResult('escaped', $i, 'Plus', 'src/File'.($i % 10).'.php', $i + 1, 'diff', '');
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
        $this->assertIsArray($decoded);
        $this->assertSame(ResponseEncoder::UNTRUSTED_NOTICE, $decoded['_security_notice']);
        $this->assertIsArray($decoded['untrusted_data']);

        return $decoded['untrusted_data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $response): array
    {
        $decoded = ResponseEncoder::decode($response);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
