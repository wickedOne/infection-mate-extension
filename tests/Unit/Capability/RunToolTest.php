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
use WickedOne\InfectionMateExtension\Discovery\ConfigurationDiscovery;
use WickedOne\InfectionMateExtension\Formatter\ResultFormatter;
use WickedOne\InfectionMateExtension\Parser\JsonLogParser;
use WickedOne\InfectionMateExtension\Runner\Argument\ArgumentResolver;
use WickedOne\InfectionMateExtension\Runner\Argument\InfectionCapabilities;
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

    /** @var array<string, array{accept_value: bool, is_value_required: bool, is_multiple: bool}> */
    private array $definition = [];

    /** @var list<string> */
    private array $arguments = ['paths'];

    protected function setUp(): void
    {
        $base = vfsStream::setup('base', null, ['project' => ['src' => ['Calc.php' => "<?php\n"]], 'outside' => []])->url();
        $this->root = $base.'/project';
        $this->outside = $base.'/outside';
        $this->executor = new FakeProcessExecutor($this->handle(...));
        $this->definition = [
            'threads' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => false],
            'mutators' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => false],
            'git-diff-base' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => false],
            'show-mutations' => ['accept_value' => true, 'is_value_required' => false, 'is_multiple' => false],
            'filter' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => true],
            'git-diff-lines' => ['accept_value' => false, 'is_value_required' => false, 'is_multiple' => false],
            'only-covering-test-cases' => ['accept_value' => false, 'is_value_required' => false, 'is_multiple' => false],
            'configuration' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => false],
            'logger-text' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => false],
            'no-progress' => ['accept_value' => false, 'is_value_required' => false, 'is_multiple' => false],
        ];

        $this->buildTool();
    }

    protected function tearDown(): void
    {
        unset($this->root, $this->outside, $this->cache, $this->tool, $this->executor, $this->fake, $this->definition, $this->arguments);
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
        $data = $this->untrusted($this->tool->execute(timeout: 1));

        $this->assertArrayNotHasKey('error', $data);
        $this->assertArrayHasKey('runId', $data);
    }

    public function testPassesFlagsValuesAndRepeatedValuesInOrderBeforePaths(): void
    {
        $this->tool->execute(['src/Calc.php', 'src'], ['git-diff-lines' => true, 'git-diff-base' => 'main', 'mutators' => '@default,!Plus', 'threads' => 4, 'filter' => ['a.php', 'b.php']], 30);

        $this->assertSame(
            ['--git-diff-lines', '--git-diff-base=main', '--mutators=@default,!Plus', '--threads=4', '--filter=a.php', '--filter=b.php', 'src/Calc.php', 'src'],
            $this->extraArguments(),
        );
    }

    public function testOptionsThatAreFalseOrNullAreLeftOut(): void
    {
        $this->assertArrayHasKey('runId', $this->untrusted($this->tool->execute(options: ['git-diff-lines' => false, 'threads' => null])));
        $this->assertSame([], $this->extraArguments());
    }

    public function testFlagsWithOptionalValueAcceptTrueAndAValue(): void
    {
        $this->tool->execute(options: ['show-mutations' => true]);
        $this->assertSame(['--show-mutations'], $this->extraArguments());

        $this->tool->execute(options: ['show-mutations' => 'max']);
        $this->assertSame(['--show-mutations=max'], $this->extraArguments());
    }

    public function testNumbersAreAcceptedAsValues(): void
    {
        $this->tool->execute(options: ['threads' => 1.5]);

        $this->assertSame(['--threads=1.5'], $this->extraArguments());
    }

    public function testNeedsNoOptionDefinitionWithoutOptions(): void
    {
        $this->executor = new FakeProcessExecutor(fn (array $command, string $cwd): RunResult => \in_array('--help', $command, true) ? new RunResult(1, '', null) : $this->handle($command, $cwd));
        $this->buildTool();

        $this->assertArrayHasKey('runId', $this->untrusted($this->tool->execute(['src'])));
    }

    public function testRejectsAnOptionTheInstalledInfectionDoesNotKnow(): void
    {
        $data = $this->error($this->tool->execute(options: ['brand-new' => true]));

        $this->assertSame('The installed Infection does not support the option "brand-new".', $data['error']);
        $this->assertSame('Use one of: threads, mutators, git-diff-base, show-mutations, filter, git-diff-lines, only-covering-test-cases.', $data['hint']);
        $this->assertCount(1, $this->executor->calls);
    }

    public function testAcceptsAnOptionOnceTheInstalledInfectionKnowsIt(): void
    {
        $this->definition['brand-new'] = ['accept_value' => false, 'is_value_required' => false, 'is_multiple' => false];
        $this->buildTool();

        $this->tool->execute(options: ['brand-new' => true]);

        $this->assertSame(['--brand-new'], $this->extraArguments());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function managedOptionProvider(): iterable
    {
        yield 'configuration' => ['configuration'];
        yield 'logger' => ['logger-text'];
        yield 'progress' => ['no-progress'];
    }

    #[DataProvider('managedOptionProvider')]
    public function testRejectsOptionsTheExtensionManages(string $name): void
    {
        $data = $this->error($this->tool->execute(options: [$name => 'x']));

        $this->assertSame(\sprintf('The installed Infection does not support the option "%s".', $name), $data['error']);
        $this->assertStringNotContainsString($name, $data['hint']);
    }

    public function testRejectsAListAsOptions(): void
    {
        $data = $this->error($this->tool->execute(options: ['threads']));

        $this->assertSame('The installed Infection does not support the option "0".', $data['error']);
    }

    public function testRejectsOptionsWhenTheDefinitionCannotBeRead(): void
    {
        $this->executor = new FakeProcessExecutor(static fn (): RunResult => new RunResult(1, 'boom', null));
        $this->buildTool();

        $data = $this->error($this->tool->execute(options: ['threads' => 2]));

        $this->assertSame('Could not read the options of the installed Infection.', $data['error']);
        $this->assertSame('Check that "infection run --help" works in the project, or leave options out.', $data['hint']);
    }

    public function testRejectsAValueForAFlag(): void
    {
        $data = $this->error($this->tool->execute(options: ['git-diff-lines' => 'main']));

        $this->assertSame('The option "git-diff-lines" takes no value.', $data['error']);
        $this->assertSame('Pass true to switch it on, or leave it out.', $data['hint']);
    }

    public function testRejectsTrueForAnOptionThatNeedsAValue(): void
    {
        $data = $this->error($this->tool->execute(options: ['threads' => true]));

        $this->assertSame('The option "threads" needs a value.', $data['error']);
        $this->assertSame('Pass a string or number for it.', $data['hint']);
    }

    public function testRejectsAListForASingleValueOption(): void
    {
        $data = $this->error($this->tool->execute(options: ['threads' => ['1', '2']]));

        $this->assertSame('The option "threads" takes a single value.', $data['error']);
        $this->assertSame('Pass one string or number.', $data['hint']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValueProvider(): iterable
    {
        yield 'dash prefix' => ['--upload-pack=x'];
        yield 'single dash' => ['-main'];
        yield 'empty' => [''];
        yield 'newline' => ["main\nrm"];
        yield 'trailing newline' => ["main\n"];
        yield 'nul byte' => ["main\0"];
        yield 'nested array' => [[['x']]];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('invalidValueProvider')]
    public function testRejectsUnsafeValues(mixed $value): void
    {
        $data = $this->error($this->tool->execute(options: ['filter' => \is_array($value) ? $value : [$value]]));

        $this->assertSame('Invalid value for the option "filter".', $data['error']);
        $this->assertSame('Pass a non-empty string or number that does not start with a dash.', $data['hint']);
        $this->assertCount(1, $this->executor->calls);
    }

    public function testValidatesValuesBeforeAskingInfection(): void
    {
        $this->error($this->tool->execute(['../x'], ['threads' => 1]));
        $this->error($this->tool->execute(options: ['threads' => 1], timeout: 0));

        $this->assertSame([], $this->executor->calls);
    }

    public function testRejectsPathsWhenInfectionNoLongerAcceptsThem(): void
    {
        $this->arguments = [];
        $this->buildTool();

        $data = $this->error($this->tool->execute(['src']));

        $this->assertSame('The installed Infection does not accept paths.', $data['error']);
        $this->assertSame('Update infection/infection, or leave paths out.', $data['hint']);
    }

    public function testRunsWithoutOptionalFlagsByDefault(): void
    {
        $this->tool->execute();

        $command = $this->executor->lastCall()['command'];
        $this->assertSame('infection', $command[0]);
        $this->assertMatchesRegularExpression('/^--logger-json=.*\/\.infection\.mate\.[0-9a-f]{12}\.log\.json$/', $command[1]);
        $this->assertSame(['--no-progress', '--no-interaction', '--no-ansi'], \array_slice($command, 2));
    }

    public function testAcceptsProjectRootAndNestedFileAsPaths(): void
    {
        $data = $this->untrusted($this->tool->execute(paths: ['.', 'src/Calc.php']));

        $this->assertArrayNotHasKey('error', $data);
        $this->assertSame(['.', 'src/Calc.php'], $this->extraArguments());
    }

    public function testAcceptsDirectoryPath(): void
    {
        $data = $this->untrusted($this->tool->execute(paths: ['src']));

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

        $data = $this->untrusted($this->tool->execute(paths: ['inner']));

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

        $data = $this->untrusted($this->tool->execute());

        $this->assertSame($this->cache->ids()[0], $data['runId']);
        $this->assertSame(2, $data['exitCode']);
        $this->assertSame(50, $data['stats']['msi']);
        $this->assertSame(['src/Calc.php' => 1, 'src/Str/Slugger.php' => 1], $data['escapedByFile']);
        $this->assertSame(['GreaterThanOrEqualTo' => 1, 'Increment' => 1], $data['escapedByMutator']);
        $this->assertSame(['runId', 'exitCode', 'stats', 'escapedByFile', 'escapedByMutator', 'hint'], array_keys($data));
    }

    public function testStillReturnsSummaryWithWarningWhenTheRunCannotBeCached(): void
    {
        $this->configureFake(['mode' => 'log']);
        file_put_contents($this->root.'/cache', 'occupied');

        $data = $this->untrusted($this->tool->execute());

        $this->assertArrayNotHasKey('error', $data);
        $this->assertNull($data['runId']);
        $this->assertSame(50, $data['stats']['msi']);
        $this->assertSame(['src/Calc.php' => 1, 'src/Str/Slugger.php' => 1], $data['escapedByFile']);
        $this->assertStringStartsWith('Run could not be cached, so infection-escaped, infection-mutant and infection-summary cannot follow up: Cannot create cache directory', $data['warning']);
        $this->assertSame('warning', array_key_last($data));
    }

    public function testStoresRunInCacheWithExitCode(): void
    {
        $this->configureFake(['mode' => 'log', 'exit' => 1]);

        $data = $this->untrusted($this->tool->execute());

        $run = $this->cache->load($data['runId']);
        $this->assertNotNull($run);
        $this->assertSame(1, $run['exitCode']);
        $this->assertCount(6, $run['mutants']);
        $this->assertSame('src/Calc.php', $run['mutants'][0]->file);
    }

    public function testPropagatesZeroExitCode(): void
    {
        $data = $this->untrusted($this->tool->execute());

        $this->assertSame(0, $data['exitCode']);
    }

    public function testReturnsTimeoutErrorWithUntrustedOutput(): void
    {
        $this->configureFake(['mode' => 'sleep', 'stdout' => 'partial out', 'stderr' => ' partial err']);

        $data = $this->untrusted($this->tool->execute(timeout: 1));

        $this->assertSame('Infection timed out.', $data['error']);
        $this->assertSame('Narrow the run with paths or options such as git-diff-lines or only-covering-test-cases, or raise timeout.', $data['hint']);
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
            new InfectionRunner($this->root, new ConfigurationDiscovery($this->root), $this->executor),
            new JsonLogParser(new FakePathResolver()),
            $this->cache = new RunCache($this->root.'/cache'),
            new ResultFormatter($this->root, $paths),
            new ArgumentResolver($this->root, $paths, new InfectionCapabilities($this->root, new ConfigurationDiscovery($this->root), $this->executor)),
            $this->root,
        );
    }

    /**
     * stands in for infection: the json log is written to the path --logger-json asks for.
     *
     * @param list<string> $command
     */
    private function handle(array $command, string $cwd): RunResult
    {
        if (\in_array('--help', $command, true)) {
            return new RunResult(0, (string) json_encode(['definition' => ['options' => $this->definition, 'arguments' => array_fill_keys($this->arguments, [])]]), null);
        }

        $output = $this->fake['stdout'].$this->fake['stderr'];

        if ('throw' === $this->fake['mode']) {
            throw new \RuntimeException('boom');
        }

        if ('sleep' === $this->fake['mode']) {
            return new RunResult(1, $output, null, true);
        }

        $logFile = '';

        foreach ($command as $argument) {
            if (str_starts_with($argument, '--logger-json=')) {
                $logFile = substr($argument, 14);
            }
        }

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
