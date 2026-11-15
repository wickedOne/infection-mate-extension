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
use WickedOne\InfectionExtension\Capability\MutantTool;
use WickedOne\InfectionExtension\Formatter\ResultFormatter;
use WickedOne\InfectionExtension\Parser\JsonLogParser;
use WickedOne\InfectionExtension\Tests\Support\FakePathResolver;

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

    protected function tearDown(): void
    {
        unset($this->root, $this->cache, $this->tool);
    }

    public function testReturnsMutantDetailsFromLatestRun(): void
    {
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute('escaped-0'));

        self::assertSame($runId, $data['runId']);
        self::assertSame('escaped-0', $data['id']);
        self::assertSame('escaped', $data['bucket']);
        self::assertSame('GreaterThanOrEqualTo', $data['mutator']);
        self::assertSame('src/Calc.php', $data['file']);
        self::assertSame(12, $data['line']);
        self::assertStringContainsString('$value > $this->max', $data['diff']);
    }

    public function testIncludesSurroundingSourceLines(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute('escaped-0'));

        self::assertCount(13, $data['context']);
        self::assertSame('6: line 6', $data['context'][0]);
        self::assertSame('12: line 12', $data['context'][6]);
        self::assertSame('18: line 18', $data['context'][12]);
    }

    public function testOmitsProcessOutputByDefault(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute('escaped-0'));

        self::assertArrayNotHasKey('processOutput', $data);
    }

    public function testIncludesProcessOutputWhenRequested(): void
    {
        $this->seedRun();

        $data = $this->untrusted($this->tool->execute('escaped-0', null, true));

        self::assertStringContainsString('FAILURES!', $data['processOutput']);
    }

    public function testResolvesRefFromRequestedRun(): void
    {
        $this->seedRun();
        $runId = $this->seedRun();

        $data = $this->untrusted($this->tool->execute('killed-1', $runId));

        self::assertSame($runId, $data['runId']);
        self::assertSame('ReturnRemoveNonVoid', $data['mutator']);
    }

    public function testReturnsErrorForUnknownId(): void
    {
        $runId = $this->seedRun();

        $data = $this->decode($this->tool->execute('escaped-9'));

        self::assertSame(\sprintf('Mutant "escaped-9" not found in run %s.', $runId), $data['error']);
        self::assertStringContainsString('id from infection-escaped', $data['hint']);
        self::assertArrayNotHasKey('untrusted_data', $data);
    }

    public function testReturnsErrorForUnknownRun(): void
    {
        $ids = [];

        for ($i = 0; $i < 7; ++$i) {
            $ids[] = $this->seedRun();
        }

        $data = $this->decode($this->tool->execute('escaped-0', 'r000000000'));

        self::assertSame('Run not found.', $data['error']);
        self::assertSame('Call infection-run first. Recent run ids: '.implode(', ', \array_slice(array_reverse($ids), 0, 5)), $data['hint']);
    }

    public function testReturnsEmptyRecentRunsHintWhenNoRunExists(): void
    {
        $data = $this->decode($this->tool->execute('escaped-0'));

        self::assertSame('Call infection-run first. Recent run ids: ', $data['hint']);
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
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function untrusted(string $response): array
    {
        $decoded = $this->decode($response);
        self::assertSame(ResponseEncoder::UNTRUSTED_NOTICE, $decoded['_security_notice']);
        self::assertIsArray($decoded['untrusted_data']);

        return $decoded['untrusted_data'];
    }
}
