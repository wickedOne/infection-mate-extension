<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Runner;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Discovery\ConfigurationDiscovery;
use WickedOne\InfectionExtension\Runner\InfectionRunner;
use WickedOne\InfectionExtension\Runner\RunResult;
use WickedOne\InfectionExtension\Tests\Support\FakeProcessExecutor;

class InfectionRunnerTest extends TestCase
{
    private string $root;
    private FakeProcessExecutor $executor;
    private ?string $logPath = null;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('project')->url();
        $this->executor = new FakeProcessExecutor($this->handle(...));
    }

    protected function tearDown(): void
    {
        unset($this->root, $this->executor, $this->logPath);
    }

    public function testDefaultTimeoutIsThirtyMinutes(): void
    {
        $parameter = (new \ReflectionClass(InfectionRunner::class))->getConstructor()?->getParameters()[3];

        self::assertSame('defaultTimeout', $parameter?->getName());
        self::assertSame(1800, $parameter->getDefaultValue());
    }

    public function testRunReturnsLogOutputAndExitCode(): void
    {
        $result = $this->runner()->run(['--exit=2']);

        self::assertSame(2, $result->exitCode);
        self::assertFalse($result->timedOut);
        self::assertSame('{"stats":{"msi":42}}', $result->log);
        self::assertStringContainsString('out-line', $result->output);
        self::assertStringContainsString('err-line', $result->output);
    }

    public function testRunPassesFixedFlagsThenCallerArguments(): void
    {
        $this->runner()->run(['--threads=2', '--filter=src/A.php']);

        $call = $this->executor->lastCall();
        self::assertSame('--logger-json='.$this->logPath(), $call['command'][1]);
        self::assertSame(['--no-progress', '--no-interaction', '--no-ansi', '--threads=2', '--filter=src/A.php'], \array_slice($call['command'], 2));
        self::assertSame($this->root, $call['cwd']);
    }

    public function testRunStartsTheProjectBinaryWhenInstalled(): void
    {
        mkdir($this->root.'/vendor/bin', 0o775, true);
        file_put_contents($this->root.'/vendor/bin/infection', '');

        $this->runner()->run([]);

        self::assertSame([\PHP_BINARY, $this->root.'/vendor/bin/infection'], \array_slice($this->executor->lastCall()['command'], 0, 2));
    }

    public function testRunLeavesTheProjectConfigAloneAndRemovesTheLogAfterwards(): void
    {
        $this->write('infection.json5', '{"source": {"directories": ["lib"]}}');

        $this->runner()->run([]);

        self::assertSame(['infection.json5'], $this->rootFiles());
        self::assertSame('{"source": {"directories": ["lib"]}}', file_get_contents($this->root.'/infection.json5'));
        self::assertFileDoesNotExist($this->logPath());
        self::assertMatchesRegularExpression('#^'.preg_quote($this->root, '#').'/\.infection\.mate\.[0-9a-f]{12}\.log\.json$#', $this->logPath());
    }

    public function testRunRemovesTheLogWhenInfectionFails(): void
    {
        $this->runner()->run(['--exit=1']);

        self::assertSame([], $this->rootFiles());
        self::assertFileDoesNotExist($this->logPath());
    }

    public function testRunRemovesTheLogWhenTheExecutorThrows(): void
    {
        $runner = new InfectionRunner($this->root, new ConfigurationDiscovery($this->root), new FakeProcessExecutor(static fn (): RunResult => throw new \RuntimeException('boom')));

        try {
            $runner->run([]);
            self::fail('The exception should propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame([], $this->rootFiles());
    }

    public function testRunRemovesTemporaryFilesLeftByRunsThatDiedLongAgo(): void
    {
        $this->write('.infection.mate.aaaaaaaaaaaa.json', '{}');
        $this->write('.infection.mate.aaaaaaaaaaaa.log.json', '{}');
        touch($this->root.'/.infection.mate.aaaaaaaaaaaa.json', time() - 86401);
        touch($this->root.'/.infection.mate.aaaaaaaaaaaa.log.json', time() - 86401);

        $this->runner()->run([]);

        self::assertSame([], $this->rootFiles());
    }

    public function testRunKeepsTemporaryFilesOfRunsStillInProgress(): void
    {
        $this->write('.infection.mate.bbbbbbbbbbbb.json', '{}');
        $this->write('infection.mate.json', '{}');
        touch($this->root.'/.infection.mate.bbbbbbbbbbbb.json', time() - 86399);
        touch($this->root.'/infection.mate.json', time() - 86402);

        $this->runner()->run([]);

        self::assertSame(['.infection.mate.bbbbbbbbbbbb.json', 'infection.mate.json'], $this->rootFiles());
    }

    public function testRunReturnsNullLogWhenInfectionWritesNone(): void
    {
        $result = $this->runner()->run(['--no-log']);

        self::assertNull($result->log);
        self::assertSame(0, $result->exitCode);
    }

    public function testRunReportsTimeout(): void
    {
        $result = $this->runner()->run(['--sleep'], 1);

        self::assertTrue($result->timedOut);
        self::assertSame(1, $result->exitCode);
        self::assertNull($result->log);
        self::assertStringContainsString('out-line', $result->output);
        self::assertSame([], $this->rootFiles());
    }

    public function testRunUsesDefaultTimeoutWhenNoneIsGiven(): void
    {
        $this->runner()->run([]);

        self::assertSame(1800, $this->executor->lastCall()['timeout']);
    }

    public function testRunUsesConstructorTimeoutWhenNoneIsGiven(): void
    {
        $runner = new InfectionRunner($this->root, new ConfigurationDiscovery($this->root), $this->executor, 5);

        $runner->run([]);

        self::assertSame(5, $this->executor->lastCall()['timeout']);
    }

    public function testRunUsesUniqueTokenInTemporaryLogPath(): void
    {
        $runner = $this->runner();
        $runner->run([]);
        $first = $this->logPath();
        $runner->run([]);

        self::assertNotSame($first, $this->logPath());
    }

    /**
     * @param list<string> $command
     */
    private function handle(array $command, string $cwd): RunResult
    {
        $logPath = '';
        $exit = 0;
        $sleep = false;
        $writeLog = true;

        foreach ($command as $argument) {
            if (str_starts_with($argument, '--logger-json=')) {
                $logPath = substr($argument, 14);
            } elseif (str_starts_with($argument, '--exit=')) {
                $exit = (int) substr($argument, 7);
            } elseif ('--sleep' === $argument) {
                $sleep = true;
            } elseif ('--no-log' === $argument) {
                $writeLog = false;
            }
        }

        $this->logPath = $logPath;

        if ($sleep) {
            return new RunResult(1, "out-line\n", null, true);
        }

        if ($writeLog) {
            file_put_contents($logPath, '{"stats":{"msi":42}}');
        }

        return new RunResult($exit, "out-line\nerr-line\n", null);
    }

    private function logPath(): string
    {
        self::assertNotNull($this->logPath);

        return $this->logPath;
    }

    /**
     * @return list<string>
     */
    private function rootFiles(): array
    {
        return array_values(array_diff(scandir($this->root) ?: [], ['.', '..']));
    }

    private function runner(): InfectionRunner
    {
        return new InfectionRunner($this->root, new ConfigurationDiscovery($this->root), $this->executor);
    }

    private function write(string $relativePath, string $contents): void
    {
        file_put_contents($this->root.'/'.$relativePath, $contents);
    }
}
