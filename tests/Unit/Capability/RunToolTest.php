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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionMateExtension\Cache\RunCache;
use WickedOne\InfectionMateExtension\Capability\RunTool;
use WickedOne\InfectionMateExtension\Config\ConfigurationDetector;
use WickedOne\InfectionMateExtension\Formatter\ResultFormatter;
use WickedOne\InfectionMateExtension\Parser\JsonLogParser;
use WickedOne\InfectionMateExtension\Runner\ArgumentResolver;
use WickedOne\InfectionMateExtension\Runner\InfectionRunner;
use WickedOne\InfectionMateExtension\Runner\RunResult;
use WickedOne\InfectionMateExtension\Tests\Support\FakePathResolver;
use WickedOne\InfectionMateExtension\Tests\Support\FakeProcessExecutor;

class RunToolTest extends TestCase
{
    private string $root;
    private string $outside;
    private RunCache $cache;
    private RunTool $tool;
    private FakeProcessExecutor $executor;

    /** @var array{mode: string, exit: int, stdout: string, stderr: string} */
    private array $fake = ['mode' => 'log', 'exit' => 0, 'stdout' => '', 'stderr' => ''];

    protected function setUp(): void
    {
        $base = vfsStream::setup('base', null, ['project' => ['src' => ['Calc.php' => "<?php\n"]], 'outside' => []])->url();
        $this->root = $base.'/project';
        $this->outside = $base.'/outside';
        $this->executor = new FakeProcessExecutor($this->handle(...));

        $this->buildTool();
    }

    public function testRejectsMutatorsWithUnsafeCharacters(): void
    {
        $data = $this->error($this->tool->execute(mutators: 'Plus; rm -rf /'));

        $this->assertSame('Invalid mutators value.', $data['error']);
    }

    public function testRejectsGitDiffBaseStartingWithDash(): void
    {
        $data = $this->error($this->tool->execute(gitDiffLines: true, gitDiffBase: '--upload-pack=touch /tmp/x'));

        $this->assertSame('Invalid gitDiffBase value.', $data['error']);
    }

    public function testRejectsGitDiffBaseWithoutGitDiffLines(): void
    {
        $data = $this->error($this->tool->execute(gitDiffBase: 'main'));

        $this->assertSame('gitDiffBase has no effect without gitDiffLines.', $data['error']);
    }

    public function testRejectsNonPositiveTimeout(): void
    {
        $data = $this->error($this->tool->execute(timeout: 0));

        $this->assertSame('Invalid timeout.', $data['error']);
    }

    public function testAcceptsDoubleDotInsideFileName(): void
    {
        $data = $this->error($this->tool->execute(paths: ['src/Foo..Bar.php']));

        $this->assertSame('Path "src/Foo..Bar.php" does not exist.', $data['error']);
    }

    public function testRejectsGitDiffBaseWithWhitespace(): void
    {
        $data = $this->error($this->tool->execute(gitDiffLines: true, gitDiffBase: 'main branch'));

        $this->assertSame('Invalid gitDiffBase value.', $data['error']);
    }

    public function testRejectsPathEscapingProjectRoot(): void
    {
        $data = $this->error($this->tool->execute(paths: ['../x']));

        $this->assertSame('Invalid path "../x".', $data['error']);
    }

    public function testRejectsAbsolutePath(): void
    {
        $data = $this->error($this->tool->execute(paths: ['/etc/passwd']));

        $this->assertSame('Invalid path "/etc/passwd".', $data['error']);
    }

    public function testRejectsPathStartingWithDash(): void
    {
        $data = $this->error($this->tool->execute(paths: ['--configuration=/tmp/evil.json']));

        $this->assertSame('Invalid path "--configuration=/tmp/evil.json".', $data['error']);
    }

    public function testRejectsMissingPath(): void
    {
        $data = $this->error($this->tool->execute(paths: ['src/Missing.php']));

        $this->assertSame('Path "src/Missing.php" does not exist.', $data['error']);
    }

    public function testReturnsExactHintForInvalidTimeout(): void
    {
        $data = $this->error($this->tool->execute(timeout: -1));

        $this->assertSame('Invalid timeout.', $data['error']);
        $this->assertSame('Pass a number of seconds of at least 1, or omit it for the default.', $data['hint']);
        $this->assertArrayNotHasKey('untrusted_data', $data);
    }

    public function testAcceptsTimeoutOfOneSecond(): void
    {
        $data = $this->decode($this->tool->execute(timeout: 1));

        $this->assertArrayNotHasKey('error', $data);
        $this->assertArrayHasKey('runId', $data);
    }

    public function testReturnsExactMessageForGitDiffBaseWithoutGitDiffLines(): void
    {
        $data = $this->error($this->tool->execute(gitDiffBase: 'main'));

        $this->assertSame('Set gitDiffLines to true, otherwise the whole codebase is mutated.', $data['hint']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMutatorsProvider(): iterable
    {
        yield 'unsafe suffix' => ['Plus; rm -rf /'];
        yield 'garbage prefix' => ['bad Plus'];
        yield 'garbage suffix' => ['Plus bad'];
        yield 'garbage prefix with newline' => ["bad\nPlus"];
        yield 'empty' => [''];
        yield 'trailing newline' => ["Plus\n"];
        yield 'dollar' => ['Plus$'];
    }

    #[DataProvider('invalidMutatorsProvider')]
    public function testRejectsInvalidMutators(string $mutators): void
    {
        $data = $this->error($this->tool->execute(mutators: $mutators));

        $this->assertSame('Invalid mutators value.', $data['error']);
        $this->assertSame('Use mutator names or @profiles separated by commas.', $data['hint']);
        $this->assertSame([], $this->executor->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validMutatorsProvider(): iterable
    {
        yield 'profile' => ['@default'];
        yield 'list' => ['GreaterThanOrEqualTo,Increment'];
        yield 'negated' => ['@default,!Plus'];
        yield 'underscore and digits' => ['Mutator_1,Other_2'];
    }

    #[DataProvider('validMutatorsProvider')]
    public function testPassesValidMutators(string $mutators): void
    {
        $this->tool->execute(mutators: $mutators);

        $this->assertSame(['--mutators='.$mutators], $this->extraArguments());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidGitDiffBaseProvider(): iterable
    {
        yield 'dash prefix' => ['--upload-pack=x'];
        yield 'single dash prefix' => ['-main'];
        yield 'dot prefix' => ['.main'];
        yield 'slash prefix' => ['/main'];
        yield 'garbage prefix' => [' main'];
        yield 'garbage suffix' => ['main branch'];
        yield 'semicolon suffix' => ['main;ls'];
        yield 'newline suffix then garbage' => ["main\nrm"];
        yield 'newline prefix' => ["\nmain"];
        yield 'trailing newline' => ["main\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidGitDiffBaseProvider')]
    public function testRejectsInvalidGitDiffBase(string $base): void
    {
        $data = $this->error($this->tool->execute(gitDiffLines: true, gitDiffBase: $base));

        $this->assertSame('Invalid gitDiffBase value.', $data['error']);
        $this->assertSame('Pass a branch, tag or commit such as "main".', $data['hint']);
        $this->assertSame([], $this->executor->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validGitDiffBaseProvider(): iterable
    {
        yield 'branch' => ['main'];
        yield 'remote branch' => ['origin/main'];
        yield 'relative commit' => ['HEAD~3'];
        yield 'caret' => ['v1.0^'];
        yield 'underscore start' => ['_x-1.2'];
        yield 'digit start' => ['1abc'];
    }

    #[DataProvider('validGitDiffBaseProvider')]
    public function testPassesValidGitDiffBase(string $base): void
    {
        $this->tool->execute(gitDiffLines: true, gitDiffBase: $base);

        $this->assertSame(['--git-diff-lines', '--git-diff-base='.$base], $this->extraArguments());
    }

    public function testRunsWithoutOptionalFlagsByDefault(): void
    {
        $this->tool->execute();

        $command = $this->executor->lastCall()['command'];
        $this->assertSame('infection', $command[0]);
        $this->assertMatchesRegularExpression('/^--configuration=\.infection\.mate\.[0-9a-f]{12}\.json$/', $command[1]);
        $this->assertSame(['--no-progress', '--no-interaction', '--no-ansi'], \array_slice($command, 2));
    }

    public function testPassesGitDiffLinesWithoutBase(): void
    {
        $this->tool->execute(gitDiffLines: true);

        $this->assertSame(['--git-diff-lines'], $this->extraArguments());
    }

    public function testPassesOnlyCoveringFlagAlone(): void
    {
        $this->tool->execute(onlyCovering: true);

        $this->assertSame(['--only-covering-test-cases'], $this->extraArguments());
    }

    public function testPassesWithUncoveredFlagAlone(): void
    {
        $this->tool->execute(withUncovered: true);

        $this->assertSame(['--with-uncovered'], $this->extraArguments());
    }

    public function testPassesAllArgumentsInOrder(): void
    {
        $this->tool->execute(['src/Calc.php', 'src'], true, 'main', '@default', true, true, 4, 30);

        $this->assertSame(
            ['--mutators=@default', '--git-diff-lines', '--git-diff-base=main', '--only-covering-test-cases', '--with-uncovered', '--threads=4', 'src/Calc.php', 'src'],
            $this->extraArguments(),
        );
    }

    public function testPassesThreadsAndClampsToAtLeastOne(): void
    {
        $this->tool->execute(threads: 8);
        $this->assertSame(['--threads=8'], $this->extraArguments());

        $this->tool->execute(threads: 1);
        $this->assertSame(['--threads=1'], $this->extraArguments());

        $this->tool->execute(threads: 0);
        $this->assertSame(['--threads=1'], $this->extraArguments());

        $this->tool->execute(threads: -3);
        $this->assertSame(['--threads=1'], $this->extraArguments());
    }

    public function testAcceptsProjectRootAndNestedFileAsPaths(): void
    {
        $data = $this->decode($this->tool->execute(paths: ['.', 'src/Calc.php']));

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['.', 'src/Calc.php'], $this->extraArguments());
    }

    public function testAcceptsDirectoryPath(): void
    {
        $data = $this->decode($this->tool->execute(paths: ['src']));

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['src'], $this->extraArguments());
    }

    public function testRejectsEmptyPath(): void
    {
        $data = $this->error($this->tool->execute(paths: ['']));

        $this->assertSame('Invalid path "".', $data['error']);
        $this->assertSame('Paths must be relative to the project root and stay inside it.', $data['hint']);
    }

    public function testRejectsPathWithDoubleDotSegment(): void
    {
        $data = $this->error($this->tool->execute(paths: ['src/../src/Calc.php']));

        $this->assertSame('Invalid path "src/../src/Calc.php".', $data['error']);
    }

    public function testRejectsSymlinkResolvingOutsideProject(): void
    {
        $this->buildTool([$this->root.'/link' => $this->outside]);

        $data = $this->error($this->tool->execute(paths: ['link']));

        $this->assertSame('Path "link" resolves outside the project.', $data['error']);
        $this->assertSame('Paths must stay inside the project root.', $data['hint']);
        $this->assertSame([], $this->executor->calls);
    }

    public function testRejectsSymlinkResolvingToSiblingSharingTheRootPrefix(): void
    {
        $this->buildTool([$this->root.'/link' => $this->root.'-evil']);

        $data = $this->error($this->tool->execute(paths: ['link']));

        $this->assertSame('Path "link" resolves outside the project.', $data['error']);
    }

    public function testAcceptsSymlinkResolvingInsideProject(): void
    {
        $this->buildTool([$this->root.'/inner' => $this->root.'/src']);

        $data = $this->decode($this->tool->execute(paths: ['inner']));

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['inner'], $this->extraArguments());
    }

    public function testReturnsMissingPathHint(): void
    {
        $data = $this->error($this->tool->execute(paths: ['src/Missing.php']));

        $this->assertSame('Check the path relative to the project root.', $data['hint']);
    }

    public function testReturnsSummaryWithRunIdAndExitCode(): void
    {
        $this->configureFake(['mode' => 'log', 'exit' => 2]);

        $data = $this->decode($this->tool->execute());

        $this->assertArrayNotHasKey('untrusted_data', $data);
        $this->assertSame($this->cache->ids()[0], $data['runId']);
        $this->assertSame(2, $data['exitCode']);
        $this->assertSame(50, $data['stats']['msi']);
        $this->assertSame(['src/Calc.php' => 1, 'src/Str/Slugger.php' => 1], $data['escapedByFile']);
        $this->assertSame(['GreaterThanOrEqualTo' => 1, 'Increment' => 1], $data['escapedByMutator']);
        $this->assertSame(['runId', 'exitCode', 'stats', 'escapedByFile', 'escapedByMutator', 'hint'], array_keys($data));
    }

    public function testStoresRunInCacheWithExitCode(): void
    {
        $this->configureFake(['mode' => 'log', 'exit' => 1]);

        $data = $this->decode($this->tool->execute());

        $run = $this->cache->load($data['runId']);
        $this->assertNotNull($run);
        $this->assertSame(1, $run['exitCode']);
        $this->assertCount(6, $run['mutants']);
        $this->assertSame('src/Calc.php', $run['mutants'][0]->file);
    }

    public function testPropagatesZeroExitCode(): void
    {
        $data = $this->decode($this->tool->execute());

        $this->assertSame(0, $data['exitCode']);
    }

    public function testReturnsTimeoutErrorWithUntrustedOutput(): void
    {
        $this->configureFake(['mode' => 'sleep', 'stdout' => 'partial out', 'stderr' => ' partial err']);

        $data = $this->untrusted($this->tool->execute(timeout: 1));

        $this->assertSame('Infection timed out.', $data['error']);
        $this->assertSame('Narrow the run with paths, gitDiffLines or onlyCovering, or raise timeout.', $data['hint']);
        $this->assertSame('partial out partial err', $data['output']);
        $this->assertSame([], $this->cache->ids());
    }

    public function testReturnsErrorWhenInfectionCannotBeStarted(): void
    {
        $this->configureFake(['mode' => 'throw']);

        $data = $this->error($this->tool->execute());

        $this->assertSame('Could not run Infection: boom', $data['error']);
        $this->assertSame('Check that infection/infection is installed (composer require --dev infection/infection) and that infection.json5 is valid.', $data['hint']);
        $this->assertArrayNotHasKey('untrusted_data', $data);
    }

    public function testReturnsNoLogErrorWithUntrustedOutput(): void
    {
        $this->configureFake(['mode' => 'nolog', 'exit' => 3, 'stdout' => 'out', 'stderr' => 'err']);

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame('Infection produced no JSON log.', $data['error']);
        $this->assertSame('It likely failed before mutating: no coverage driver (pcov/xdebug), failing initial tests, or invalid config. See output.', $data['hint']);
        $this->assertSame('outerr', $data['output']);
        $this->assertSame([], $this->cache->ids());
    }

    public function testReturnsInvalidLogErrorWithUntrustedOutput(): void
    {
        $this->configureFake(['mode' => 'invalid', 'stdout' => 'some output']);

        $data = $this->untrusted($this->tool->execute());

        $this->assertStringStartsWith('Could not read the Infection log: ', $data['error']);
        $this->assertGreaterThan(\strlen('Could not read the Infection log: '), \strlen($data['error']));
        $this->assertSame('Run infection manually to inspect the problem.', $data['hint']);
        $this->assertSame('some output', $data['output']);
        $this->assertSame([], $this->cache->ids());
    }

    public function testReturnsErrorForLogWithoutStats(): void
    {
        $this->configureFake(['mode' => 'empty']);

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame('Could not read the Infection log: Not an Infection JSON log: "stats" is missing.', $data['error']);
    }

    public function testKeepsOnlyTheLastTwoThousandBytesOfOutput(): void
    {
        $this->configureFake(['mode' => 'nolog', 'stdout' => str_repeat('A', 3000).str_repeat('B', 2000)]);

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame(str_repeat('B', 2000), $data['output']);
    }

    public function testKeepsShortOutputUntouched(): void
    {
        $this->configureFake(['mode' => 'nolog', 'stdout' => str_repeat('A', 2000)]);

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame(str_repeat('A', 2000), $data['output']);
    }

    public function testTruncatesOutputWithoutSplittingMultibyteCharacters(): void
    {
        $this->configureFake(['mode' => 'nolog', 'stdout' => str_repeat('é', 1500).'x']);

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame(str_repeat('é', 1000).'x', $data['output']);
        $this->assertTrue(mb_check_encoding($data['output'], 'UTF-8'));
    }

    public function testDoesNotLeaveTemporaryConfigBehind(): void
    {
        $this->tool->execute();

        $this->assertSame([], array_values(array_diff(scandir($this->root) ?: [], ['.', '..', 'src', 'cache'])));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configureFake(array $settings): void
    {
        $this->fake = array_replace($this->fake, $settings);
    }

    /**
     * @param array<string, string> $links
     */
    private function buildTool(array $links = []): void
    {
        $paths = new FakePathResolver($links);

        $this->tool = new RunTool(
            new InfectionRunner($this->root, new ConfigurationDetector($this->root), $this->executor),
            new JsonLogParser(),
            $this->cache = new RunCache($this->root.'/cache'),
            new ResultFormatter($this->root, $paths),
            new ArgumentResolver($this->root, $paths),
            $this->root,
        );
    }

    /**
     * stands in for infection: the json log is written to the path the temporary config asks for.
     *
     * @param list<string> $command
     */
    private function handle(array $command, string $cwd): RunResult
    {
        $output = $this->fake['stdout'].$this->fake['stderr'];

        if ('throw' === $this->fake['mode']) {
            throw new \RuntimeException('boom');
        }

        if ('sleep' === $this->fake['mode']) {
            return new RunResult(1, $output, null, true);
        }

        $configFile = '';

        foreach ($command as $argument) {
            if (str_starts_with($argument, '--configuration=')) {
                $configFile = substr($argument, 16);
            }
        }

        $config = json_decode((string) file_get_contents($cwd.'/'.$configFile), true, flags: \JSON_THROW_ON_ERROR);
        $logFile = $config['logs']['json'];
        $contents = match ($this->fake['mode']) {
            'log' => str_replace('/project/', $this->root.'/', (string) file_get_contents(__DIR__.'/../../Fixtures/infection-log.json')),
            'invalid' => 'not json',
            'empty' => '{}',
            default => null,
        };

        if (null !== $contents) {
            file_put_contents($logFile, $contents);
        }

        return new RunResult($this->fake['exit'], $output, null);
    }

    /**
     * @return list<string> the arguments following the fixed Infection flags
     */
    private function extraArguments(): array
    {
        return \array_slice($this->executor->lastArguments(), 3);
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

    /**
     * @return array<string, mixed>
     */
    private function error(string $response): array
    {
        $decoded = ResponseEncoder::decode($response);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertArrayHasKey('hint', $decoded);

        return $decoded;
    }
}
