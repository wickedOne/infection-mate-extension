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
use WickedOne\InfectionExtension\Capability\SummaryTool;
use WickedOne\InfectionExtension\Formatter\ResultFormatter;
use WickedOne\InfectionExtension\Parser\JsonLogParser;
use WickedOne\InfectionExtension\Tests\Support\FakePathResolver;

class SummaryToolTest extends TestCase
{
    private string $root;
    private string $cacheDirectory;
    private RunCache $cache;
    private SummaryTool $tool;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('project')->url();
        $this->cacheDirectory = $this->root.'/cache';
        $this->cache = new RunCache($this->cacheDirectory);
        $this->tool = new SummaryTool($this->cache, new ResultFormatter($this->root, new FakePathResolver()));
    }

    protected function tearDown(): void
    {
        unset($this->root, $this->cacheDirectory, $this->cache, $this->tool);
    }

    public function testSummarisesRunAsUntrustedData(): void
    {
        $runId = $this->seedRun();

        $decoded = $this->decode($this->tool->execute($runId));

        self::assertSame(ResponseEncoder::UNTRUSTED_NOTICE, $decoded['_security_notice']);
        $data = $decoded['untrusted_data'];
        self::assertSame($runId, $data['runId']);
        self::assertSame(2, $data['exitCode']);
        self::assertSame(50, $data['stats']['msi']);
        self::assertSame(['src/Calc.php' => 1, 'src/Str/Slugger.php' => 1], $data['escapedByFile']);
        self::assertSame(['GreaterThanOrEqualTo' => 1, 'Increment' => 1], $data['escapedByMutator']);
        self::assertStringContainsString('infection-escaped', $data['hint']);
    }

    public function testListsRecentRunsInsideUntrustedData(): void
    {
        $runId = $this->seedRun();

        $decoded = $this->decode($this->tool->execute($runId));

        self::assertSame([$runId], $decoded['untrusted_data']['recentRuns']);
    }

    public function testSummarisesLatestRunWhenRunIdIsOmitted(): void
    {
        $older = $this->seedRun();
        $newer = $this->seedRun();

        $decoded = $this->decode($this->tool->execute());

        self::assertSame($newer, $decoded['untrusted_data']['runId']);
        self::assertSame([$newer, $older], $decoded['untrusted_data']['recentRuns']);
    }

    public function testListsAtMostFiveRecentRuns(): void
    {
        $ids = [];
        for ($i = 0; $i < 7; ++$i) {
            $ids[] = $this->seedRun();
        }

        $decoded = $this->decode($this->tool->execute());

        self::assertSame(\array_slice(array_reverse($ids), 0, 5), $decoded['untrusted_data']['recentRuns']);
    }

    public function testReturnsErrorForUnknownRun(): void
    {
        $this->seedRun();

        $decoded = $this->decode($this->tool->execute('r000000000'));

        self::assertSame('Run not found.', $decoded['error']);
        self::assertSame('Call infection-run first.', $decoded['hint']);
        self::assertArrayNotHasKey('untrusted_data', $decoded);
    }

    public function testMarksAPartialRun(): void
    {
        $data = $this->decode($this->tool->execute($this->seedRun(3)));

        self::assertIsArray($data['untrusted_data']);
        self::assertStringStartsWith('Limited to the mutants matching the given ids (3)', $data['untrusted_data']['partial']);
    }

    public function testDoesNotMarkARegularRun(): void
    {
        $data = $this->decode($this->tool->execute($this->seedRun()));

        self::assertIsArray($data['untrusted_data']);
        self::assertArrayNotHasKey('partial', $data['untrusted_data']);
    }

    private function seedRun(?int $selected = null): string
    {
        $parsed = (new JsonLogParser(new FakePathResolver()))->parse((string) file_get_contents(__DIR__.'/../../Fixtures/infection-log.json'), '/project');

        return $this->cache->store($parsed['stats'], $parsed['mutants'], 2, $selected);
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
