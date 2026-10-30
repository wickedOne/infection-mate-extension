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
use WickedOne\InfectionMateExtension\Capability\SummaryTool;
use WickedOne\InfectionMateExtension\Formatter\ResultFormatter;
use WickedOne\InfectionMateExtension\Parser\JsonLogParser;
use WickedOne\InfectionMateExtension\Tests\Support\FakePathResolver;

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

        $this->assertSame(ResponseEncoder::UNTRUSTED_NOTICE, $decoded['_security_notice']);
        $data = $decoded['untrusted_data'];
        $this->assertSame($runId, $data['runId']);
        $this->assertSame(2, $data['exitCode']);
        $this->assertSame(50, $data['stats']['msi']);
        $this->assertSame(['src/Calc.php' => 1, 'src/Str/Slugger.php' => 1], $data['escapedByFile']);
        $this->assertSame(['GreaterThanOrEqualTo' => 1, 'Increment' => 1], $data['escapedByMutator']);
        $this->assertStringContainsString('infection-escaped', $data['hint']);
    }

    public function testListsRecentRunsInsideUntrustedData(): void
    {
        $runId = $this->seedRun();

        $decoded = $this->decode($this->tool->execute($runId));

        $this->assertSame([$runId], $decoded['untrusted_data']['recentRuns']);
    }

    public function testSummarisesLatestRunWhenRunIdIsOmitted(): void
    {
        $older = $this->seedRun();
        $newer = $this->seedRun();

        $decoded = $this->decode($this->tool->execute());

        $this->assertSame($newer, $decoded['untrusted_data']['runId']);
        $this->assertSame([$newer, $older], $decoded['untrusted_data']['recentRuns']);
    }

    public function testListsAtMostFiveRecentRuns(): void
    {
        $ids = [];
        for ($i = 0; $i < 7; ++$i) {
            $ids[] = $this->seedRun();
        }

        $decoded = $this->decode($this->tool->execute());

        $this->assertSame(\array_slice(array_reverse($ids), 0, 5), $decoded['untrusted_data']['recentRuns']);
    }

    public function testReturnsErrorForUnknownRun(): void
    {
        $this->seedRun();

        $decoded = $this->decode($this->tool->execute('r000000000'));

        $this->assertSame('Run not found.', $decoded['error']);
        $this->assertSame('Call infection-run first.', $decoded['hint']);
        $this->assertArrayNotHasKey('untrusted_data', $decoded);
    }

    private function seedRun(): string
    {
        $parsed = (new JsonLogParser(new FakePathResolver()))->parse((string) file_get_contents(__DIR__.'/../../Fixtures/infection-log.json'), '/project');

        return $this->cache->store($parsed['stats'], $parsed['mutants'], 2);
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
