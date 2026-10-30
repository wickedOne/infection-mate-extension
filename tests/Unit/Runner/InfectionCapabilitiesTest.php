<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Runner;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Config\ConfigurationDetector;
use WickedOne\InfectionMateExtension\Runner\InfectionCapabilities;
use WickedOne\InfectionMateExtension\Runner\RunResult;
use WickedOne\InfectionMateExtension\Tests\Support\FakeProcessExecutor;

class InfectionCapabilitiesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('project')->url();
    }

    protected function tearDown(): void
    {
        unset($this->root);
    }

    public function testExposesOptionShapesAndArguments(): void
    {
        $capabilities = $this->capabilities(new RunResult(0, '{"definition":{"options":{"threads":{"accept_value":true,"is_value_required":true,"is_multiple":false},"filter":{"accept_value":1,"is_value_required":0,"is_multiple":"yes"},"debug":{},"odd":5},"arguments":{"paths":{}}}}', null));

        $this->assertSame([
            'options' => [
                'threads' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => false],
                'filter' => ['accept_value' => true, 'is_value_required' => false, 'is_multiple' => true],
                'debug' => ['accept_value' => false, 'is_value_required' => false, 'is_multiple' => false],
                'odd' => ['accept_value' => false, 'is_value_required' => false, 'is_multiple' => false],
            ],
            'arguments' => ['paths'],
        ], $capabilities->definition());
    }

    public function testAsksInfectionForTheRunDefinitionInTheProjectRoot(): void
    {
        $executor = $this->executor(new RunResult(0, '{"definition":{"options":{},"arguments":{}}}', null));

        (new InfectionCapabilities($this->root, new ConfigurationDetector($this->root), $executor))->definition();

        $call = $executor->lastCall();
        $this->assertSame(['infection', 'run', '--help', '--format=json', '--no-ansi', '--no-interaction'], $call['command']);
        $this->assertSame($this->root, $call['cwd']);
        $this->assertSame(60, $call['timeout']);
    }

    public function testRunsTheProjectsLocalBinaryWhenInstalled(): void
    {
        vfsStream::create(['vendor' => ['bin' => ['infection' => '']]], vfsStream::setup('local'));
        $root = vfsStream::url('local');
        $executor = $this->executor(new RunResult(0, '{"definition":{"options":{},"arguments":{}}}', null));

        (new InfectionCapabilities($root, new ConfigurationDetector($root), $executor))->definition();

        $this->assertSame([\PHP_BINARY, $root.'/vendor/bin/infection', 'run'], \array_slice($executor->lastCall()['command'], 0, 3));
    }

    public function testArgumentNamesThatPhpTurnsIntoIntegersAreStrings(): void
    {
        $capabilities = $this->capabilities(new RunResult(0, '{"definition":{"options":{},"arguments":{"8":{}}}}', null));

        $this->assertSame(['8'], $capabilities->definition()['arguments'] ?? null);
    }

    public function testReadsTheDefinitionOnlyOnce(): void
    {
        $executor = $this->executor(new RunResult(0, '{"definition":{"options":{},"arguments":{}}}', null));
        $capabilities = new InfectionCapabilities($this->root, new ConfigurationDetector($this->root), $executor);

        $capabilities->definition();
        $capabilities->definition();

        $this->assertCount(1, $executor->calls);
    }

    /**
     * @return iterable<string, array{RunResult|\Throwable}>
     */
    public static function unreadableProvider(): iterable
    {
        yield 'failed' => [new RunResult(1, '{"definition":{"options":{},"arguments":{}}}', null)];
        yield 'timed out' => [new RunResult(0, '{"definition":{"options":{},"arguments":{}}}', null, true)];
        yield 'not json' => [new RunResult(0, 'nope', null)];
        yield 'scalar json' => [new RunResult(0, '5', null)];
        yield 'no definition' => [new RunResult(0, '{}', null)];
        yield 'no options' => [new RunResult(0, '{"definition":{"arguments":{}}}', null)];
        yield 'no arguments' => [new RunResult(0, '{"definition":{"options":{}}}', null)];
        yield 'executor failure' => [new \RuntimeException('boom')];
    }

    #[DataProvider('unreadableProvider')]
    public function testDefinitionIsNullWhenUnreadable(RunResult|\Throwable $outcome): void
    {
        $this->assertNull($this->capabilities($outcome)->definition());
    }

    private function capabilities(RunResult|\Throwable $outcome): InfectionCapabilities
    {
        return new InfectionCapabilities($this->root, new ConfigurationDetector($this->root), $this->executor($outcome));
    }

    private function executor(RunResult|\Throwable $outcome): FakeProcessExecutor
    {
        return new FakeProcessExecutor(static fn (): RunResult => $outcome instanceof \Throwable ? throw $outcome : $outcome);
    }
}
