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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionExtension\Cache\OutputStore;
use WickedOne\InfectionExtension\Cache\RunCache;
use WickedOne\InfectionExtension\Capability\RunTool;
use WickedOne\InfectionExtension\Discovery\ConfigurationDiscovery;
use WickedOne\InfectionExtension\Formatter\ResultFormatter;
use WickedOne\InfectionExtension\Parser\JsonLogParser;
use WickedOne\InfectionExtension\Runner\Argument\ArgumentResolver;
use WickedOne\InfectionExtension\Runner\Argument\InfectionCapabilities;
use WickedOne\InfectionExtension\Runner\InfectionRunner;
use WickedOne\InfectionExtension\Runner\RunResult;
use WickedOne\InfectionExtension\Tests\Support\FakePathResolver;
use WickedOne\InfectionExtension\Tests\Support\FakeProcessExecutor;

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
            'logger-json' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => false],
            'id' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => true],
            'test-framework-extra-args' => ['accept_value' => true, 'is_value_required' => true, 'is_multiple' => false],
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

        self::assertSame('Invalid timeout.', $data['error']);
    }

    public function testAcceptsDoubleDotInsideFileName(): void
    {
        $data = $this->error($this->tool->execute(paths: ['src/Foo..Bar.php']));

        self::assertSame('Path "src/Foo..Bar.php" does not exist.', $data['error']);
    }

    public function testRejectsPathEscapingProjectRoot(): void
    {
        $data = $this->error($this->tool->execute(paths: ['../x']));

        self::assertSame('Invalid path "../x".', $data['error']);
    }

    public function testRejectsAbsolutePath(): void
    {
        $data = $this->error($this->tool->execute(paths: ['/etc/passwd']));

        self::assertSame('Invalid path "/etc/passwd".', $data['error']);
    }

    public function testRejectsPathStartingWithDash(): void
    {
        $data = $this->error($this->tool->execute(paths: ['--configuration=/tmp/evil.json']));

        self::assertSame('Invalid path "--configuration=/tmp/evil.json".', $data['error']);
    }

    public function testRejectsMissingPath(): void
    {
        $data = $this->error($this->tool->execute(paths: ['src/Missing.php']));

        self::assertSame('Path "src/Missing.php" does not exist.', $data['error']);
    }

    public function testReturnsExactHintForInvalidTimeout(): void
    {
        $data = $this->error($this->tool->execute(timeout: -1));

        self::assertSame('Invalid timeout.', $data['error']);
        self::assertSame('Pass a number of seconds of at least 1, or omit it for the default.', $data['hint']);
        self::assertArrayNotHasKey('untrusted_data', $data);
    }

    public function testAcceptsTimeoutOfOneSecond(): void
    {
        $data = $this->untrusted($this->tool->execute(timeout: 1));

        self::assertArrayNotHasKey('error', $data);
        self::assertArrayHasKey('runId', $data);
    }

    public function testPassesFlagsValuesAndRepeatedValuesInOrderBeforePaths(): void
    {
        $this->tool->execute(['src/Calc.php', 'src'], ['git-diff-lines' => true, 'git-diff-base' => 'main', 'mutators' => '@default,!Plus', 'threads' => 4, 'filter' => ['a.php', 'b.php']], 30);

        self::assertSame(
            ['--git-diff-lines', '--git-diff-base=main', '--mutators=@default,!Plus', '--threads=4', '--filter=a.php', '--filter=b.php', 'src/Calc.php', 'src'],
            $this->extraArguments(),
        );
    }

    public function testOptionsThatAreFalseOrNullAreLeftOut(): void
    {
        self::assertArrayHasKey('runId', $this->untrusted($this->tool->execute(options: ['git-diff-lines' => false, 'threads' => null])));
        self::assertSame([], $this->extraArguments());
    }

    public function testFlagsWithOptionalValueAcceptTrueAndAValue(): void
    {
        $this->tool->execute(options: ['show-mutations' => true]);
        self::assertSame(['--show-mutations'], $this->extraArguments());

        $this->tool->execute(options: ['show-mutations' => 'max']);
        self::assertSame(['--show-mutations=max'], $this->extraArguments());
    }

    public function testNumbersAreAcceptedAsValues(): void
    {
        $this->tool->execute(options: ['threads' => 1.5]);

        self::assertSame(['--threads=1.5'], $this->extraArguments());
    }

    public function testNeedsNoOptionDefinitionWithoutOptions(): void
    {
        $this->executor = new FakeProcessExecutor(fn (array $command, string $cwd): RunResult => \in_array('--help', $command, true) ? new RunResult(1, '', null) : $this->handle($command, $cwd));
        $this->buildTool();

        self::assertArrayHasKey('runId', $this->untrusted($this->tool->execute(['src'])));
    }

    public function testRejectsAnInfectionWithoutTheJsonLogger(): void
    {
        unset($this->definition['logger-json']);
        $this->buildTool();

        $data = $this->error($this->tool->execute());

        self::assertSame('The installed Infection does not support the option "logger-json", which the extension needs to read the results.', $data['error']);
        self::assertSame('Update infection/infection to 0.35.11 or newer.', $data['hint']);
        self::assertCount(1, $this->executor->calls);
    }

    public function testRepeatsTheIdOptionPerMutantId(): void
    {
        $this->tool->execute(['src'], ['threads' => 2, 'id' => ['abc', 'def']]);

        self::assertSame(['--threads=2', '--id=abc', '--id=def', 'src'], $this->extraArguments());
    }

    public function testRejectsAnEmptyListForARepeatableOption(): void
    {
        $data = $this->error($this->tool->execute(options: ['id' => []]));

        self::assertSame('The option "id" got an empty list.', $data['error']);
        self::assertSame('Pass at least one value, or leave the option out.', $data['hint']);
        self::assertCount(1, $this->executor->calls);
    }

    public function testMarksARunOfSelectedMutantsAsPartial(): void
    {
        $data = $this->untrusted($this->tool->execute(options: ['id' => ['abc', 'def']]));

        // the fixture log holds 6 mutants, whatever ids were asked for
        self::assertSame('Limited to the mutants matching the given ids (6): the stats cover just those, not the project. Run the wider scope again for the real score.', $data['partial']);
        $run = $this->cache->load($data['runId']);
        self::assertNotNull($run);
        self::assertSame(6, $run['selected']);
    }

    public function testCountsTheMutantsInTheLogNotTheIdsGiven(): void
    {
        $this->configureFake(['mode' => 'nomatch', 'exit' => 1]);

        $data = $this->untrusted($this->tool->execute(options: ['id' => ['stale', 'stale']]));

        self::assertStringStartsWith('None of the given mutant ids matched a mutant', $data['partial']);
        $run = $this->cache->load($data['runId']);
        self::assertNotNull($run);
        self::assertSame(0, $run['selected']);
    }

    public function testMarksARunSelectedThroughASingleIdAsPartial(): void
    {
        $data = $this->untrusted($this->tool->execute(options: ['id' => 'abc']));

        self::assertStringStartsWith('Limited to the mutants matching the given ids', $data['partial']);
    }

    public function testDoesNotMarkARunAsPartialWhenTheIdOptionIsSwitchedOff(): void
    {
        $data = $this->untrusted($this->tool->execute(options: ['id' => false, 'threads' => 2]));

        self::assertArrayNotHasKey('partial', $data);
        self::assertSame(['--threads=2'], $this->extraArguments());
    }

    public function testDoesNotMarkARunWithOtherOptionsOnlyAsPartial(): void
    {
        $data = $this->untrusted($this->tool->execute(['src'], ['threads' => 2]));

        self::assertArrayNotHasKey('partial', $data);
    }

    public function testDoesNotMarkARegularRunAsPartial(): void
    {
        $data = $this->untrusted($this->tool->execute());

        self::assertArrayNotHasKey('partial', $data);
        $run = $this->cache->load($data['runId']);
        self::assertNotNull($run);
        self::assertNull($run['selected']);
    }

    public function testPassesRawTestFrameworkArgumentsThatStartWithADash(): void
    {
        $this->tool->execute(options: ['test-framework-extra-args' => '--filter=Foo|Bar']);

        self::assertSame(['--test-framework-extra-args=--filter=Foo|Bar'], $this->extraArguments());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidRawValueProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'newline' => ["--filter=Foo\n--bar"];
        yield 'nul byte' => ["--filter=Foo\0"];
        yield 'array' => [['--filter=Foo']];
    }

    #[DataProvider('invalidRawValueProvider')]
    public function testStillRejectsUnusableRawTestFrameworkArguments(mixed $value): void
    {
        $data = $this->error($this->tool->execute(options: ['test-framework-extra-args' => $value]));

        self::assertContains($data['error'], ['Invalid value for the option "test-framework-extra-args".', 'The option "test-framework-extra-args" takes a single value.']);
    }

    public function testRejectsAnOptionTheInstalledInfectionDoesNotKnow(): void
    {
        $data = $this->error($this->tool->execute(options: ['brand-new' => true]));

        self::assertSame('The installed Infection does not support the option "brand-new".', $data['error']);
        self::assertSame('Use one of: threads, mutators, git-diff-base, show-mutations, filter, git-diff-lines, only-covering-test-cases, id, test-framework-extra-args.', $data['hint']);
        self::assertCount(1, $this->executor->calls);
    }

    public function testAcceptsAnOptionOnceTheInstalledInfectionKnowsIt(): void
    {
        $this->definition['brand-new'] = ['accept_value' => false, 'is_value_required' => false, 'is_multiple' => false];
        $this->buildTool();

        $this->tool->execute(options: ['brand-new' => true]);

        self::assertSame(['--brand-new'], $this->extraArguments());
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

        self::assertSame(\sprintf('The installed Infection does not support the option "%s".', $name), $data['error']);
        self::assertStringNotContainsString($name, $data['hint']);
    }

    public function testRejectsAListAsOptions(): void
    {
        $data = $this->error($this->tool->execute(options: ['threads']));

        self::assertSame('The installed Infection does not support the option "0".', $data['error']);
    }

    public function testRejectsOptionsWhenTheDefinitionCannotBeRead(): void
    {
        $this->executor = new FakeProcessExecutor(static fn (): RunResult => new RunResult(1, 'boom', null));
        $this->buildTool();

        $data = $this->error($this->tool->execute(options: ['threads' => 2]));

        self::assertSame('Could not read the options of the installed Infection.', $data['error']);
        self::assertSame('Check that "infection run --help" works in the project, or leave options out.', $data['hint']);
    }

    public function testRejectsAValueForAFlag(): void
    {
        $data = $this->error($this->tool->execute(options: ['git-diff-lines' => 'main']));

        self::assertSame('The option "git-diff-lines" takes no value.', $data['error']);
        self::assertSame('Pass true to switch it on, or leave it out.', $data['hint']);
    }

    public function testRejectsTrueForAnOptionThatNeedsAValue(): void
    {
        $data = $this->error($this->tool->execute(options: ['threads' => true]));

        self::assertSame('The option "threads" needs a value.', $data['error']);
        self::assertSame('Pass a string or number for it.', $data['hint']);
    }

    public function testRejectsAListForASingleValueOption(): void
    {
        $data = $this->error($this->tool->execute(options: ['threads' => ['1', '2']]));

        self::assertSame('The option "threads" takes a single value.', $data['error']);
        self::assertSame('Pass one string or number.', $data['hint']);
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

        self::assertSame('Invalid value for the option "filter".', $data['error']);
        self::assertSame('Pass a non-empty string or number that does not start with a dash.', $data['hint']);
        self::assertCount(1, $this->executor->calls);
    }

    public function testValidatesValuesBeforeAskingInfection(): void
    {
        $this->error($this->tool->execute(['../x'], ['threads' => 1]));
        $this->error($this->tool->execute(options: ['threads' => 1], timeout: 0));

        self::assertSame([], $this->executor->calls);
    }

    public function testRejectsPathsWhenInfectionNoLongerAcceptsThem(): void
    {
        $this->arguments = [];
        $this->buildTool();

        $data = $this->error($this->tool->execute(['src']));

        self::assertSame('The installed Infection does not accept paths.', $data['error']);
        self::assertSame('Update infection/infection, or leave paths out.', $data['hint']);
    }

    public function testRunsWithoutOptionalFlagsByDefault(): void
    {
        $this->tool->execute();

        $command = $this->executor->lastCall()['command'];
        self::assertSame('infection', $command[0]);
        self::assertMatchesRegularExpression('/^--logger-json=.*\/\.infection\.mate\.[0-9a-f]{12}\.log\.json$/', $command[1]);
        self::assertSame(['--no-progress', '--no-interaction', '--no-ansi'], \array_slice($command, 2));
    }

    public function testAcceptsProjectRootAndNestedFileAsPaths(): void
    {
        $data = $this->untrusted($this->tool->execute(paths: ['.', 'src/Calc.php']));

        self::assertArrayNotHasKey('error', $data);
        self::assertSame(['.', 'src/Calc.php'], $this->extraArguments());
    }

    public function testAcceptsDirectoryPath(): void
    {
        $data = $this->untrusted($this->tool->execute(paths: ['src']));

        self::assertArrayNotHasKey('error', $data);
        self::assertSame(['src'], $this->extraArguments());
    }

    public function testRejectsEmptyPath(): void
    {
        $data = $this->error($this->tool->execute(paths: ['']));

        self::assertSame('Invalid path "".', $data['error']);
        self::assertSame('Paths must be relative to the project root and stay inside it.', $data['hint']);
    }

    public function testRejectsPathWithDoubleDotSegment(): void
    {
        $data = $this->error($this->tool->execute(paths: ['src/../src/Calc.php']));

        self::assertSame('Invalid path "src/../src/Calc.php".', $data['error']);
    }

    public function testRejectsSymlinkResolvingOutsideProject(): void
    {
        $this->buildTool([$this->root.'/link' => $this->outside]);

        $data = $this->error($this->tool->execute(paths: ['link']));

        self::assertSame('Path "link" resolves outside the project.', $data['error']);
        self::assertSame('Paths must stay inside the project root.', $data['hint']);
        self::assertSame([], $this->executor->calls);
    }

    public function testRejectsSymlinkResolvingToSiblingSharingTheRootPrefix(): void
    {
        $this->buildTool([$this->root.'/link' => $this->root.'-evil']);

        $data = $this->error($this->tool->execute(paths: ['link']));

        self::assertSame('Path "link" resolves outside the project.', $data['error']);
    }

    public function testAcceptsSymlinkResolvingInsideProject(): void
    {
        $this->buildTool([$this->root.'/inner' => $this->root.'/src']);

        $data = $this->untrusted($this->tool->execute(paths: ['inner']));

        self::assertArrayNotHasKey('error', $data);
        self::assertSame(['inner'], $this->extraArguments());
    }

    public function testReturnsMissingPathHint(): void
    {
        $data = $this->error($this->tool->execute(paths: ['src/Missing.php']));

        self::assertSame('Check the path relative to the project root.', $data['hint']);
    }

    public function testReturnsSummaryWithRunIdAndExitCode(): void
    {
        $this->configureFake(['mode' => 'log', 'exit' => 2]);

        $data = $this->untrusted($this->tool->execute());

        self::assertSame($this->cache->ids()[0], $data['runId']);
        self::assertSame(2, $data['exitCode']);
        self::assertSame(50, $data['stats']['msi']);
        self::assertSame(['src/Calc.php' => 1, 'src/Str/Slugger.php' => 1], $data['escapedByFile']);
        self::assertSame(['GreaterThanOrEqualTo' => 1, 'Increment' => 1], $data['escapedByMutator']);
        self::assertSame(['runId', 'exitCode', 'stats', 'escapedByFile', 'escapedByMutator', 'hint'], array_keys($data));
    }

    public function testStillReturnsSummaryWithWarningWhenTheRunCannotBeCached(): void
    {
        $this->configureFake(['mode' => 'log']);
        file_put_contents($this->root.'/cache', 'occupied');

        $data = $this->untrusted($this->tool->execute());

        self::assertArrayNotHasKey('error', $data);
        self::assertNull($data['runId']);
        self::assertSame(50, $data['stats']['msi']);
        self::assertSame(['src/Calc.php' => 1, 'src/Str/Slugger.php' => 1], $data['escapedByFile']);
        self::assertStringStartsWith('Run could not be cached, so infection-escaped and infection-summary cannot follow up: Cannot create cache directory', $data['warning']);
        self::assertSame('warning', array_key_last($data));
    }

    public function testStoresRunInCacheWithExitCode(): void
    {
        $this->configureFake(['mode' => 'log', 'exit' => 1]);

        $data = $this->untrusted($this->tool->execute());

        $run = $this->cache->load($data['runId']);
        self::assertNotNull($run);
        self::assertSame(1, $run['exitCode']);
        self::assertCount(6, $run['mutants']);
        self::assertSame('src/Calc.php', $run['mutants'][0]->file);
    }

    public function testPropagatesZeroExitCode(): void
    {
        $data = $this->untrusted($this->tool->execute());

        self::assertSame(0, $data['exitCode']);
    }

    public function testReturnsTimeoutErrorWithUntrustedOutput(): void
    {
        $this->configureFake(['mode' => 'sleep', 'stdout' => 'partial out', 'stderr' => ' partial err']);

        $data = $this->untrusted($this->tool->execute(timeout: 1));

        self::assertSame('Infection timed out.', $data['error']);
        self::assertSame('Narrow the run with paths or options such as git-diff-lines or only-covering-test-cases, or raise timeout.', $data['hint']);
        self::assertSame('partial out partial err', $data['output']);
        self::assertSame([], $this->cache->ids());
    }

    public function testReturnsErrorWhenInfectionCannotBeStarted(): void
    {
        $this->configureFake(['mode' => 'throw']);

        $data = $this->error($this->tool->execute());

        self::assertSame('Could not run Infection: boom', $data['error']);
        self::assertSame('Check that infection/infection is installed (composer require --dev infection/infection) and that infection.json5 is valid.', $data['hint']);
        self::assertArrayNotHasKey('untrusted_data', $data);
    }

    public function testReturnsNoLogErrorWithUntrustedOutput(): void
    {
        $this->configureFake(['mode' => 'nolog', 'exit' => 3, 'stdout' => 'out', 'stderr' => 'err']);

        $data = $this->untrusted($this->tool->execute());

        self::assertSame('Infection produced no JSON log.', $data['error']);
        self::assertSame('It likely failed before mutating: no coverage driver (pcov/xdebug), failing initial tests, failing initial static analysis (for example errors in new test code), invalid config, or another Infection run sharing the system temp directory (retry once that one finished). See output, and outputFile for all of it.', $data['hint']);
        self::assertSame('outerr', $data['output']);
        self::assertSame([], $this->cache->ids());
    }

    public function testReturnsInvalidLogErrorWithUntrustedOutput(): void
    {
        $this->configureFake(['mode' => 'invalid', 'stdout' => 'some output']);

        $data = $this->untrusted($this->tool->execute());

        self::assertStringStartsWith('Could not read the Infection log: ', $data['error']);
        self::assertGreaterThan(\strlen('Could not read the Infection log: '), \strlen($data['error']));
        self::assertSame('Run infection manually to inspect the problem.', $data['hint']);
        self::assertSame('some output', $data['output']);
        self::assertSame([], $this->cache->ids());
    }

    public function testReturnsErrorForLogWithoutStats(): void
    {
        $this->configureFake(['mode' => 'empty']);

        $data = $this->untrusted($this->tool->execute());

        self::assertSame('Could not read the Infection log: Not an Infection JSON log: "stats" is missing.', $data['error']);
    }

    public function testKeepsTheHeadAndTheTailOfLongOutputAndTheFullOutputInAFile(): void
    {
        $output = str_repeat('A', 3000).str_repeat('M', 4000).str_repeat('B', 6000);
        $this->configureFake(['mode' => 'nolog', 'stdout' => $output]);

        $data = $this->untrusted($this->tool->execute());

        self::assertSame(str_repeat('A', 2000)."\n[… 5000 bytes left out; full output: ".$data['outputFile']." …]\n".str_repeat('B', 6000), $data['output']);
        self::assertStringStartsWith($this->root.'/outputs/', $data['outputFile']);
        self::assertStringEndsWith('-failure.log', $data['outputFile']);
        self::assertSame($output, file_get_contents($data['outputFile']));
    }

    public function testKeepsShortOutputUntouched(): void
    {
        $this->configureFake(['mode' => 'nolog', 'stdout' => str_repeat('A', 2000)]);

        $data = $this->untrusted($this->tool->execute());

        self::assertSame(str_repeat('A', 2000), $data['output']);
        self::assertSame(str_repeat('A', 2000), file_get_contents($data['outputFile']));
    }

    public function testCleansTheOutputOfWhatOnlyTakesRoom(): void
    {
        $this->configureFake(['mode' => 'nolog', 'stdout' => "   0/39 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%\n\n         [ERROR]   Found 3 errors      \n"]);

        $data = $this->untrusted($this->tool->execute());

        self::assertSame('  [ERROR] Found 3 errors', $data['output']);
    }

    public function testTruncatesOutputWithoutSplittingMultibyteCharacters(): void
    {
        $this->configureFake(['mode' => 'nolog', 'stdout' => str_repeat('é', 5000).'x']);

        $data = $this->untrusted($this->tool->execute());

        self::assertSame(str_repeat('é', 1000)."\n[… 2000 bytes left out; full output: ".$data['outputFile']." …]\n".str_repeat('é', 3000).'x', $data['output']);
        self::assertTrue(mb_check_encoding($data['output'], 'UTF-8'));
    }

    public function testReturnsTheExcerptWithoutAFileWhenTheOutputCannotBeKept(): void
    {
        file_put_contents($this->root.'/outputs', 'a file where the directory should be');
        $this->configureFake(['mode' => 'nolog', 'stdout' => str_repeat('A', 9000)]);

        $data = $this->untrusted($this->tool->execute());

        self::assertArrayNotHasKey('outputFile', $data);
        self::assertSame(str_repeat('A', 2000)."\n[… 1000 bytes left out …]\n".str_repeat('A', 6000), $data['output']);
    }

    public function testKeepsTheOutputOfATimedOutRunInAFileToo(): void
    {
        $this->configureFake(['mode' => 'sleep', 'stdout' => 'still running']);

        $data = $this->untrusted($this->tool->execute(timeout: 1));

        self::assertSame('Infection timed out.', $data['error']);
        self::assertSame('still running', file_get_contents($data['outputFile']));
    }

    public function testErrorsWithoutOutputHaveNoOutputFile(): void
    {
        $data = $this->decode($this->tool->execute(timeout: 0));

        self::assertArrayNotHasKey('outputFile', $data);
        self::assertArrayNotHasKey('output', $data);
    }

    public function testDoesNotLeaveTemporaryConfigBehind(): void
    {
        $this->tool->execute();

        self::assertSame([], array_values(array_diff(scandir($this->root) ?: [], ['.', '..', 'src', 'cache'])));
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
            new OutputStore($this->root.'/outputs'),
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
            'nomatch' => '{"stats":{"totalMutantsCount":0,"msi":0}}',
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

    /**
     * @return array<string, mixed>
     */
    private function error(string $response): array
    {
        $decoded = ResponseEncoder::decode($response);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('error', $decoded);
        self::assertArrayHasKey('hint', $decoded);

        return $decoded;
    }
}
