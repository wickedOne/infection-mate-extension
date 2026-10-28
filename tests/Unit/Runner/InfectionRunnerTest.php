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
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Config\ConfigurationDetector;
use WickedOne\InfectionMateExtension\Runner\InfectionRunner;
use WickedOne\InfectionMateExtension\Runner\RunResult;
use WickedOne\InfectionMateExtension\Tests\Support\FakeProcessExecutor;

class InfectionRunnerTest extends TestCase
{
    private string $root;
    private FakeProcessExecutor $executor;

    /** @var array{config: string, logPath: string}|null */
    private ?array $captured = null;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('project')->url();
        $this->executor = new FakeProcessExecutor($this->handle(...));
    }

    public function testDefaultTimeoutIsThirtyMinutes(): void
    {
        $parameter = (new \ReflectionClass(InfectionRunner::class))->getConstructor()?->getParameters()[3];

        $this->assertSame('defaultTimeout', $parameter?->getName());
        $this->assertSame(1800, $parameter->getDefaultValue());
    }

    public function testRunReturnsLogOutputAndExitCode(): void
    {
        $result = $this->runner()->run(['--exit=2']);

        $this->assertSame(2, $result->exitCode);
        $this->assertFalse($result->timedOut);
        $this->assertSame('{"stats":{"msi":42}}', $result->log);
        $this->assertStringContainsString('out-line', $result->output);
        $this->assertStringContainsString('err-line', $result->output);
    }

    public function testRunPassesFixedFlagsThenCallerArguments(): void
    {
        $this->runner()->run(['--threads=2', '--filter=src/A.php']);

        $call = $this->executor->lastCall();
        $this->assertMatchesRegularExpression('/^--configuration=\.infection\.mate\.[0-9a-f]{12}\.json$/', $call['command'][1]);
        $this->assertSame(['--no-progress', '--no-interaction', '--no-ansi', '--threads=2', '--filter=src/A.php'], \array_slice($call['command'], 2));
        $this->assertSame($this->root, $call['cwd']);
    }

    public function testRunStartsTheProjectBinaryWhenInstalled(): void
    {
        mkdir($this->root.'/vendor/bin', 0o775, true);
        file_put_contents($this->root.'/vendor/bin/infection', '');

        $this->runner()->run([]);

        $this->assertSame([\PHP_BINARY, $this->root.'/vendor/bin/infection'], \array_slice($this->executor->lastCall()['command'], 0, 2));
    }

    public function testRunRemovesTemporaryConfigAndLogAfterwards(): void
    {
        $this->write('infection.json5', '{"source": {"directories": ["lib"]}}');

        $this->runner()->run([]);

        $captured = $this->captured();
        $this->assertSame(['infection.json5'], $this->rootFiles());
        $this->assertFileDoesNotExist($captured['logPath']);
        $this->assertMatchesRegularExpression('#^'.preg_quote($this->root, '#').'/\.infection\.mate\.[0-9a-f]{12}\.log\.json$#', $captured['logPath']);
    }

    public function testRunRemovesTemporaryConfigWhenInfectionFails(): void
    {
        $this->runner()->run(['--exit=1']);

        $this->assertSame([], $this->rootFiles());
        $this->assertFileDoesNotExist($this->captured()['logPath']);
    }

    public function testRunRemovesTemporaryConfigWhenTheExecutorThrows(): void
    {
        $runner = new InfectionRunner($this->root, new ConfigurationDetector($this->root), new FakeProcessExecutor(static fn (): RunResult => throw new \RuntimeException('boom')));

        try {
            $runner->run([]);
            $this->fail('The exception should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->rootFiles());
    }

    public function testRunOverridesJsonLogAndDropsOtherLoggers(): void
    {
        $this->write('infection.json5', <<<'JSON5'
            {
                "$schema": "vendor/infection/infection/resources/schema.json",
                // comment
                "source": {"directories": ["lib"]},
                "mutators": {},
                "logs": {"text": "build/infection.log", "json": "build/old.json", "summary": "build/summary.log"},
                "minMsi": 80,
            }
            JSON5);

        $this->runner()->run([]);

        $captured = $this->captured();
        $expected = '{"source":{"directories":["lib"]},"mutators":{},"logs":{"json":'.json_encode($captured['logPath'], \JSON_UNESCAPED_SLASHES).'},"minMsi":80}';
        $this->assertSame($expected, $captured['config']);
    }

    public function testRunFallsBackToSourceDirectoryWhenNoConfigExists(): void
    {
        $this->runner()->run([]);

        $captured = $this->captured();
        $this->assertSame(
            '{"source":{"directories":["src"]},"logs":{"json":'.json_encode($captured['logPath'], \JSON_UNESCAPED_SLASHES).'}}',
            $captured['config'],
        );
    }

    public function testRunDoesNotInjectSourceWhenConfigHasOnlyASchema(): void
    {
        $this->write('infection.json', '{"$schema": "x"}');

        $this->runner()->run([]);

        $captured = $this->captured();
        $this->assertSame('{"logs":{"json":'.json_encode($captured['logPath'], \JSON_UNESCAPED_SLASHES).'}}', $captured['config']);
    }

    public function testRunReturnsNullLogWhenInfectionWritesNone(): void
    {
        $result = $this->runner()->run(['--no-log']);

        $this->assertNull($result->log);
        $this->assertSame(0, $result->exitCode);
    }

    public function testRunReportsTimeout(): void
    {
        $result = $this->runner()->run(['--sleep'], 1);

        $this->assertTrue($result->timedOut);
        $this->assertSame(1, $result->exitCode);
        $this->assertNull($result->log);
        $this->assertStringContainsString('out-line', $result->output);
        $this->assertSame([], $this->rootFiles());
    }

    public function testRunUsesDefaultTimeoutWhenNoneIsGiven(): void
    {
        $this->runner()->run([]);

        $this->assertSame(1800, $this->executor->lastCall()['timeout']);
    }

    public function testRunUsesConstructorTimeoutWhenNoneIsGiven(): void
    {
        $runner = new InfectionRunner($this->root, new ConfigurationDetector($this->root), $this->executor, 5);

        $runner->run([]);

        $this->assertSame(5, $this->executor->lastCall()['timeout']);
    }

    public function testRunPrefersExplicitTimeoutOverDefault(): void
    {
        $runner = new InfectionRunner($this->root, new ConfigurationDetector($this->root), $this->executor, 5);

        $runner->run([], 30);

        $this->assertSame(30, $this->executor->lastCall()['timeout']);
    }

    public function testDescribeRunsInfectionDescribeAndReturnsOutput(): void
    {
        $result = $this->runner()->describe('Plus');

        $this->assertSame(0, $result->exitCode);
        $this->assertNull($result->log);
        $this->assertFalse($result->timedOut);
        $this->assertSame("described:Plus\n", $result->output);
        $this->assertSame(['infection', 'describe', '--no-ansi', '--no-interaction', 'Plus'], $this->executor->lastCall()['command']);
        $this->assertSame($this->root, $this->executor->lastCall()['cwd']);
        $this->assertSame(60, $this->executor->lastCall()['timeout']);
    }

    public function testDescribeStartsTheProjectBinaryWhenInstalled(): void
    {
        mkdir($this->root.'/vendor/bin', 0o775, true);
        file_put_contents($this->root.'/vendor/bin/infection', '');

        $this->runner()->describe('Plus');

        $this->assertSame([\PHP_BINARY, $this->root.'/vendor/bin/infection', 'describe'], \array_slice($this->executor->lastCall()['command'], 0, 3));
    }

    public function testDescribeReturnsExitCodeAndErrorOutput(): void
    {
        $result = $this->runner()->describe('Unknown');

        $this->assertSame(3, $result->exitCode);
        $this->assertSame("no such mutator\n", $result->output);
    }

    public function testRunUsesUniqueTokenInTemporaryLogPath(): void
    {
        $runner = $this->runner();
        $runner->run([]);
        $first = $this->captured()['logPath'];
        $runner->run([]);

        $this->assertNotSame($first, $this->captured()['logPath']);
    }

    /**
     * @param list<string> $command
     */
    private function handle(array $command, string $cwd): RunResult
    {
        if (\in_array('describe', $command, true)) {
            return match (end($command)) {
                'Unknown' => new RunResult(3, "no such mutator\n", null),
                default => new RunResult(0, 'described:'.end($command)."\n", null),
            };
        }

        $configFile = null;
        $exit = 0;
        $sleep = false;
        $writeLog = true;

        foreach ($command as $argument) {
            if (str_starts_with($argument, '--configuration=')) {
                $configFile = substr($argument, 16);
            } elseif (str_starts_with($argument, '--exit=')) {
                $exit = (int) substr($argument, 7);
            } elseif ('--sleep' === $argument) {
                $sleep = true;
            } elseif ('--no-log' === $argument) {
                $writeLog = false;
            }
        }

        $raw = (string) file_get_contents($cwd.'/'.$configFile);
        $logPath = json_decode($raw, false, flags: \JSON_THROW_ON_ERROR)->logs->json;
        $this->captured = ['config' => $raw, 'logPath' => $logPath];

        if ($sleep) {
            return new RunResult(1, "out-line\n", null, true);
        }

        if ($writeLog) {
            file_put_contents($logPath, '{"stats":{"msi":42}}');
        }

        return new RunResult($exit, "out-line\nerr-line\n", null);
    }

    /**
     * @return array{config: string, logPath: string}
     */
    private function captured(): array
    {
        $this->assertNotNull($this->captured);

        return $this->captured;
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
        return new InfectionRunner($this->root, new ConfigurationDetector($this->root), $this->executor);
    }

    private function write(string $relativePath, string $contents): void
    {
        file_put_contents($this->root.'/'.$relativePath, $contents);
    }
}
