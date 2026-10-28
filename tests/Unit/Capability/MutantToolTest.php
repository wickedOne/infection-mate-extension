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
use WickedOne\InfectionMateExtension\Capability\MutantTool;
use WickedOne\InfectionMateExtension\Formatter\ResultFormatter;
use WickedOne\InfectionMateExtension\Parser\JsonLogParser;
use WickedOne\InfectionMateExtension\Tests\Support\FakePathResolver;

class MutantToolTest extends TestCase
{
    private string $root;
    private RunCache $cache;
    private MutantTool $tool;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('project')->url();
        mkdir($this->root.'/src', 0o775, true);

        $source = '';

        for ($i = 1; $i <= 30; ++$i) {
            $source .= 'line '.$i."\n";
        }
        file_put_contents($this->root.'/src/Calc.php', $source);

        $this->cache = new RunCache($this->root.'/cache');
        $this->tool = new MutantTool($this->cache, new ResultFormatter($this->root, new FakePathResolver()));
    }

    public function testReturnsMutantDetailsFromLatestRun(): void
    {
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute('escaped:0'));

        $this->assertSame($runId, $data['runId']);
        $this->assertSame('escaped:0', $data['ref']);
        $this->assertSame('escaped', $data['bucket']);
        $this->assertSame('GreaterThanOrEqualTo', $data['mutator']);
        $this->assertSame('src/Calc.php', $data['file']);
        $this->assertSame(12, $data['line']);
        $this->assertStringContainsString('$value > $this->max', $data['diff']);
    }

    public function testIncludesSurroundingSourceLines(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute('escaped:0'));

        $this->assertCount(13, $data['context']);
        $this->assertSame('6: line 6', $data['context'][0]);
        $this->assertSame('12: line 12', $data['context'][6]);
        $this->assertSame('18: line 18', $data['context'][12]);
    }

    public function testOmitsProcessOutputByDefault(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute('escaped:0'));

        $this->assertArrayNotHasKey('processOutput', $data);
    }

    public function testIncludesProcessOutputWhenRequested(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute('escaped:0', null, true));

        $this->assertStringContainsString('FAILURES!', $data['processOutput']);
    }

    public function testResolvesRefFromRequestedRun(): void
    {
        $this->seedRun();
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute('killed:1', $runId));

        $this->assertSame($runId, $data['runId']);
        $this->assertSame('ReturnRemoveNonVoid', $data['mutator']);
    }

    public function testReturnsErrorForUnknownRef(): void
    {
        $runId = $this->seedRun();

        $data = $this->decode($this->tool->execute('escaped:9'));

        $this->assertSame(\sprintf('Mutant "escaped:9" not found in run %s.', $runId), $data['error']);
        $this->assertStringContainsString('bucket:index', $data['hint']);
        $this->assertArrayNotHasKey('untrusted_data', $data);
    }

    public function testReturnsErrorForUnknownRun(): void
    {
        $ids = [];

        for ($i = 0; $i < 7; ++$i) {
            $ids[] = $this->seedRun();
        }

        $data = $this->decode($this->tool->execute('escaped:0', 'r000000000'));

        $this->assertSame('Run not found.', $data['error']);
        $this->assertSame('Call infection-run first. Recent run ids: '.implode(', ', \array_slice(array_reverse($ids), 0, 5)), $data['hint']);
    }

    public function testReturnsEmptyRecentRunsHintWhenNoRunExists(): void
    {
        $data = $this->decode($this->tool->execute('escaped:0'));

        $this->assertSame('Call infection-run first. Recent run ids: ', $data['hint']);
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

    /**
     * @return array<string, mixed>
     */
    private function untrusted(string $response): array
    {
        $decoded = $this->decode($response);
        $this->assertSame(ResponseEncoder::UNTRUSTED_NOTICE, $decoded['_security_notice']);
        $this->assertIsArray($decoded['untrusted_data']);

        return $decoded['untrusted_data'];
    }
}
