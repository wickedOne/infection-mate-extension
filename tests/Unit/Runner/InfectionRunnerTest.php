<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Config\ConfigurationDetector;
use WickedOne\InfectionMateExtension\Runner\InfectionRunner;

class InfectionRunnerTest extends TestCase
{
    private const FAKE_BINARY = <<<'PHP'
        <?php
        $args = array_slice($argv, 1);
        $capture = __DIR__.'/../../captured.json';

        if ('describe' === ($args[0] ?? '')) {
            file_put_contents($capture, json_encode(['args' => $args]));
            if ('Both' === end($args)) {
                echo "out\n";
                fwrite(STDERR, "err\n");
                exit(0);
            }
            if ('Unknown' === end($args)) {
                fwrite(STDERR, "no such mutator\n");
                exit(3);
            }
            echo 'described:'.end($args)."\n";
            
            exit(0);
        }

        $configFile = null;
        
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--configuration=')) {
                $configFile = substr($arg, 16);
            }
        }
        
        $raw = file_get_contents($configFile);
        $logPath = json_decode($raw, false, 512, JSON_THROW_ON_ERROR)->logs->json;
        file_put_contents($capture, json_encode(['args' => $args, 'config' => $raw, 'logPath' => $logPath, 'cwd' => getcwd()]));

        echo "out-line\n";
        
        fwrite(STDERR, "err-line\n");

        $exit = 0;
        $writeLog = true;
        
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--exit=')) {
                $exit = (int) substr($arg, 7);
            } elseif (str_starts_with($arg, '--sleep=')) {
                sleep((int) substr($arg, 8));
            } elseif ('--no-log' === $arg) {
                $writeLog = false;
            }
        }
        
        if ($writeLog) {
            file_put_contents($logPath, '{"stats":{"msi":42}}');
        }
        
        exit($exit);
        PHP;
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/infection-mate-runner-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/vendor/bin', 0o775, true);
        file_put_contents($this->root.'/vendor/bin/infection', self::FAKE_BINARY);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDefaultTimeoutIsThirtyMinutes(): void
    {
        $parameter = (new \ReflectionClass(InfectionRunner::class))->getConstructor()?->getParameters()[2];

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

        $captured = $this->captured();
        $this->assertMatchesRegularExpression('/^--configuration=\.infection\.mate\.[0-9a-f]{12}\.json$/', $captured['args'][0]);
        $this->assertSame(['--no-progress', '--no-interaction', '--no-ansi', '--threads=2', '--filter=src/A.php'], \array_slice($captured['args'], 1));
        $this->assertSame(realpath($this->root), realpath($captured['cwd']));
    }

    public function testRunRemovesTemporaryConfigAndLogAfterwards(): void
    {
        $this->write('infection.json5', '{"source": {"directories": ["lib"]}}');

        $this->runner()->run([]);

        $captured = $this->captured();
        $this->assertSame([], glob($this->root.'/.infection.mate.*.json'));
        $this->assertFileDoesNotExist($captured['logPath']);
        $this->assertStringStartsWith(sys_get_temp_dir().'/infection-mate-', $captured['logPath']);
        $this->assertStringEndsWith('.json', $captured['logPath']);
    }

    public function testRunRemovesTemporaryConfigWhenInfectionFails(): void
    {
        $this->runner()->run(['--exit=1']);

        $this->assertSame([], glob($this->root.'/.infection.mate.*.json'));
        $this->assertFileDoesNotExist($this->captured()['logPath']);
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
        $result = $this->runner()->run(['--sleep=3'], 1);

        $this->assertTrue($result->timedOut);
        $this->assertSame(1, $result->exitCode);
        $this->assertNull($result->log);
        $this->assertStringContainsString('out-line', $result->output);
        $this->assertSame([], glob($this->root.'/.infection.mate.*.json'));
    }

    public function testRunUsesConstructorTimeoutWhenNoneIsGiven(): void
    {
        $runner = new InfectionRunner($this->root, new ConfigurationDetector($this->root), 1);

        $this->assertTrue($runner->run(['--sleep=10'])->timedOut);
    }

    public function testRunPrefersExplicitTimeoutOverDefault(): void
    {
        $runner = new InfectionRunner($this->root, new ConfigurationDetector($this->root), 1);

        $result = $runner->run(['--sleep=2'], 30);

        $this->assertFalse($result->timedOut);
        $this->assertSame(0, $result->exitCode);
    }

    public function testDescribeRunsInfectionDescribeAndReturnsOutput(): void
    {
        $result = $this->runner()->describe('Plus');

        $this->assertSame(0, $result->exitCode);
        $this->assertNull($result->log);
        $this->assertFalse($result->timedOut);
        $this->assertSame("described:Plus\n", $result->output);
        $this->assertSame(['describe', '--no-ansi', '--no-interaction', 'Plus'], $this->captured()['args']);
    }

    public function testDescribePutsStandardOutputBeforeErrorOutput(): void
    {
        $this->assertSame("out\nerr\n", $this->runner()->describe('Both')->output);
    }

    public function testRunUsesUniqueTokenInTemporaryLogPath(): void
    {
        $this->runner()->run([]);

        $this->assertMatchesRegularExpression('#/infection-mate-[0-9a-f]{12}\.json$#', $this->captured()['logPath']);
    }

    public function testDescribeReturnsExitCodeAndErrorOutput(): void
    {
        $result = $this->runner()->describe('Unknown');

        $this->assertSame(3, $result->exitCode);
        $this->assertSame("no such mutator\n", $result->output);
    }

    /**
     * @return array{args: list<string>, config: string, logPath: string, cwd: string}
     */
    private function captured(): array
    {
        return json_decode((string) file_get_contents($this->root.'/captured.json'), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function runner(): InfectionRunner
    {
        return new InfectionRunner($this->root, new ConfigurationDetector($this->root));
    }

    private function write(string $relativePath, string $contents): void
    {
        file_put_contents($this->root.'/'.$relativePath, $contents);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
