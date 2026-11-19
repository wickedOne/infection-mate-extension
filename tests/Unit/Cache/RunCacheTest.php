<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Cache;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Cache\RunCache;
use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Parser\MutantResult;

class RunCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = vfsStream::setup('root')->url().'/cache';
    }

    protected function tearDown(): void
    {
        unset($this->directory);
    }

    public function testStoreReturnsIdInExpectedFormat(): void
    {
        $id = (new RunCache($this->directory))->store(['msi' => 50], $this->mutants(), 1);

        self::assertMatchesRegularExpression('/^r[0-9a-f]{13}$/', $id);
    }

    public function testStoreCreatesMissingDirectory(): void
    {
        self::assertDirectoryDoesNotExist($this->directory);

        (new RunCache($this->directory))->store([], [], 0);

        self::assertDirectoryExists($this->directory);
    }

    public function testStoreThrowsWhenDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->directory, 'occupied');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create cache directory');

        (new RunCache($this->directory.'/nested'))->store([], [], 0);
    }

    public function testStoreThrowsWhenTheRunCannotBeWritten(): void
    {
        mkdir($this->directory, 0o775, true);
        vfsStream::setQuota(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot write run to "'.$this->directory.'/r');

        (new RunCache($this->directory))->store(['msi' => 50], $this->mutants(), 1);
    }

    public function testLoadRestoresStoredRun(): void
    {
        $cache = new RunCache($this->directory);
        $stats = ['msi' => 50, 'escapedCount' => 1];

        $id = $cache->store($stats, $this->mutants(), 2);
        $run = $cache->load($id);

        self::assertNotNull($run);
        self::assertSame($id, $run['id']);
        self::assertSame($stats, $run['stats']);
        self::assertSame(2, $run['exitCode']);
        self::assertContainsOnlyInstancesOf(MutantResult::class, $run['mutants']);
        self::assertSame(
            array_map(static fn (MutantResult $m): array => $m->jsonSerialize(), $this->mutants()),
            array_map(static fn (MutantResult $m): array => $m->jsonSerialize(), $run['mutants']),
        );
    }

    public function testLoadRestoresTheNumberOfSelectedMutants(): void
    {
        $cache = new RunCache($this->directory);

        $partial = $cache->load($cache->store([], [], 0, 3));
        $regular = $cache->load($cache->store([], [], 0));

        self::assertNotNull($partial);
        self::assertNotNull($regular);
        self::assertSame(3, $partial['selected']);
        self::assertNull($regular['selected']);
    }

    public function testLoadTreatsARunStoredWithoutSelectionAsRegular(): void
    {
        mkdir($this->directory, 0o775, true);
        file_put_contents($this->directory.'/r000000000001a.json', json_encode(['stats' => [], 'exitCode' => 0, 'mutants' => []]));

        $run = (new RunCache($this->directory))->load('r000000000001a');

        self::assertNotNull($run);
        self::assertNull($run['selected']);
    }

    public function testLoadIgnoresASelectionThatIsNotANumber(): void
    {
        mkdir($this->directory, 0o775, true);
        file_put_contents($this->directory.'/r000000000001a.json', json_encode(['stats' => [], 'exitCode' => 0, 'mutants' => [], 'selected' => 'all']));

        $run = (new RunCache($this->directory))->load('r000000000001a');

        self::assertNotNull($run);
        self::assertNull($run['selected']);
    }

    public function testLoadWithoutIdReturnsMostRecentRun(): void
    {
        $cache = new RunCache($this->directory);
        $cache->store(['msi' => 10], [], 0);
        $newer = $cache->store(['msi' => 90], [], 0);

        $run = $cache->load(null);

        self::assertNotNull($run);
        self::assertSame($newer, $run['id']);
        self::assertSame(['msi' => 90], $run['stats']);
    }

    public function testLoadWithoutIdReturnsNullWhenCacheIsEmpty(): void
    {
        self::assertNull((new RunCache($this->directory))->load(null));
    }

    public function testLoadReturnsNullForUnknownId(): void
    {
        $cache = new RunCache($this->directory);
        $cache->store([], [], 0);

        self::assertNull($cache->load('r0000000000000'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdProvider(): iterable
    {
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'missing prefix' => ['0123456789ab'];
        yield 'too short' => ['r12345'];
        yield 'uppercase hex' => ['rABCDEF123'];
        yield 'empty string' => [''];
    }

    #[DataProvider('invalidIdProvider')]
    public function testLoadReturnsNullForInvalidId(string $id): void
    {
        $cache = new RunCache($this->directory);
        $cache->store([], [], 0);

        self::assertNull($cache->load($id));
    }

    public function testIdsAreListedNewestFirst(): void
    {
        $cache = new RunCache($this->directory);
        $first = $cache->store([], [], 0);
        $second = $cache->store([], [], 0);
        $third = $cache->store([], [], 0);

        self::assertSame([$third, $second, $first], $cache->ids());
    }

    public function testIdsIsEmptyWhenDirectoryDoesNotExist(): void
    {
        self::assertSame([], (new RunCache($this->directory))->ids());
    }

    public function testPruneKeepsOnlyTheNewestRuns(): void
    {
        $cache = new RunCache($this->directory, 2);
        $first = $cache->store([], [], 0);
        $second = $cache->store([], [], 0);

        // the third store prunes the oldest run
        $third = $cache->store([], [], 0);

        self::assertSame([$third, $second], $cache->ids());
        self::assertNull($cache->load($first));
        self::assertNotNull($cache->load($second));
        self::assertNotNull($cache->load($third));
    }

    public function testDefaultKeepRetainsTenRuns(): void
    {
        $cache = new RunCache($this->directory);
        for ($i = 0; $i < 11; ++$i) {
            $cache->store([], [], 0);
        }

        self::assertCount(10, $cache->ids());
    }

    public function testPartialRunsGetATaggedId(): void
    {
        $id = (new RunCache($this->directory))->store([], [], 0, 2);

        self::assertMatchesRegularExpression('/^r[0-9a-f]{13}p$/', $id);
    }

    public function testLoadWithoutIdSkipsPartialRuns(): void
    {
        $cache = new RunCache($this->directory);
        $full = $cache->store(['msi' => 90], [], 0);
        $partial = $cache->store(['msi' => 100], [], 0, 2);

        $run = $cache->load(null);

        self::assertNotNull($run);
        self::assertSame($full, $run['id']);
        self::assertNotNull($cache->load($partial));
    }

    public function testLoadWithoutIdFallsBackToAPartialRunWhenThereIsNoOther(): void
    {
        $cache = new RunCache($this->directory);
        $cache->store([], [], 0, 1);
        $newer = $cache->store([], [], 0, 2);

        $run = $cache->load(null);

        self::assertNotNull($run);
        self::assertSame($newer, $run['id']);
    }

    public function testPartialRunsDoNotPushRegularRunsOutOfTheCache(): void
    {
        $cache = new RunCache($this->directory, 2);
        $full = $cache->store([], [], 0);
        $partials = [];

        for ($i = 0; $i < 3; ++$i) {
            $partials[] = $cache->store([], [], 0, 1);
        }

        self::assertNotNull($cache->load($full));
        self::assertNull($cache->load($partials[0]));
        self::assertNotNull($cache->load($partials[1]));
        self::assertNotNull($cache->load($partials[2]));
    }

    public function testRegularRunsDoNotPushPartialRunsOutOfTheCache(): void
    {
        $cache = new RunCache($this->directory, 2);
        $partial = $cache->store([], [], 0, 1);

        for ($i = 0; $i < 3; ++$i) {
            $cache->store([], [], 0);
        }

        self::assertNotNull($cache->load($partial));
        self::assertCount(3, $cache->ids());
    }

    public function testStoreCreatesDirectoryWithGroupWritableMode(): void
    {
        $previous = vfsStream::umask(0);

        try {
            (new RunCache($this->directory))->store([], [], 0);
        } finally {
            vfsStream::umask($previous);
        }

        self::assertSame(0o775, fileperms($this->directory) & 0o777);
    }

    public function testStoreIdStartsWithCurrentMillisecondTimestamp(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_DEPRECATED);

        try {
            $before = (int) (microtime(true) * 1000);
            $id = (new RunCache($this->directory))->store([], [], 0);
            $after = (int) (microtime(true) * 1000);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
        self::assertSame(14, \strlen($id));
        self::assertGreaterThanOrEqual($before, hexdec(substr($id, 1, 11)));
        self::assertLessThanOrEqual($after, hexdec(substr($id, 1, 11)));
    }

    public function testStoreSortsAfterNewestExistingRunEvenWhenClockIsBehind(): void
    {
        mkdir($this->directory, 0o775, true);
        $future = (int) (microtime(true) * 1000) + 10_000_000;
        $this->seedId($future - 500);
        $this->seedId($future);

        $id = (new RunCache($this->directory))->store([], [], 0);

        self::assertSame($future + 1, (int) hexdec(substr($id, 1, 11)));
        self::assertSame($id, $this->ids($this->directory)[0]);
    }

    public function testLoadRejectsMalformedIdsEvenWhenTheFileExists(): void
    {
        mkdir($this->directory, 0o775, true);
        foreach (['xr0123456789abc', 'r0123456789abcd'] as $id) {
            file_put_contents($this->directory.'/'.$id.'.json', '{"stats":{},"mutants":[]}');
            self::assertNull((new RunCache($this->directory))->load($id), $id);
        }

        file_put_contents($this->directory.'/r0123456789abc.json', '{"stats":{},"mutants":[]}');
        self::assertNotNull((new RunCache($this->directory))->load('r0123456789abc'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptRunProvider(): iterable
    {
        yield 'not json' => ['{nope'];
        yield 'scalar' => ['5'];
        yield 'list' => ['[]'];
        yield 'missing stats' => ['{"mutants":[]}'];
        yield 'missing mutants' => ['{"stats":{}}'];
        yield 'stats not an array' => ['{"stats":5,"mutants":[]}'];
        yield 'mutants not an array' => ['{"stats":{},"mutants":5}'];
        yield 'mutant not an array' => ['{"stats":{},"mutants":[5]}'];
        yield 'mutant without keys' => ['{"stats":{},"mutants":[{}]}'];
        yield 'mutant with an unknown bucket' => ['{"stats":{},"mutants":[{"bucket":"bogus","id":"a","mutator":"Plus","file":"a.php","line":1,"diff":"","processOutput":""}]}'];
        yield 'mutant with a bucket that is not a string' => ['{"stats":{},"mutants":[{"bucket":["escaped"],"id":"a","mutator":"Plus","file":"a.php","line":1,"diff":"","processOutput":""}]}'];
        yield 'mutant missing the process output' => ['{"stats":{},"mutants":[{"bucket":"escaped","id":"escaped-0","mutator":"Plus","file":"a.php","line":1,"diff":""}]}'];
    }

    #[DataProvider('corruptRunProvider')]
    public function testLoadReturnsNullForCorruptRunFile(string $contents): void
    {
        mkdir($this->directory, 0o775, true);
        file_put_contents($this->directory.'/r0000000000001.json', $contents);

        self::assertNull((new RunCache($this->directory))->load('r0000000000001'));
    }

    public function testLoadDefaultsExitCodeToOneAndCastsItToInt(): void
    {
        mkdir($this->directory, 0o775, true);
        file_put_contents($this->directory.'/r0000000000001.json', '{"stats":{},"mutants":[]}');
        file_put_contents($this->directory.'/r0000000000002.json', '{"stats":{},"mutants":[],"exitCode":"2"}');
        $cache = new RunCache($this->directory);

        self::assertSame(1, $cache->load('r0000000000001')['exitCode'] ?? null);
        self::assertSame(2, $cache->load('r0000000000002')['exitCode'] ?? null);
    }

    public function testLoadRejectsIdWithTrailingNewlineEvenWhenSuchFileExists(): void
    {
        $cache = new RunCache($this->directory);
        $id = $cache->store(['msi' => 1], [], 0);
        copy($this->directory.'/'.$id.'.json', $this->directory.'/'.$id."\n.json");

        self::assertNull($cache->load($id."\n"));
    }

    /**
     * @return list<MutantResult>
     */
    private function mutants(): array
    {
        return [
            new MutantResult(BucketEnum::Escaped, 'escaped-0', 'Plus', 'src/Calc.php', 8, '-a+b', 'FAILURES!'),
            new MutantResult(BucketEnum::Killed, 'killed-0', 'Increment', 'src/Str.php', 3, '-a++', ''),
        ];
    }

    private function seedId(int $millis): void
    {
        file_put_contents($this->directory.'/r'.str_pad(dechex($millis), 11, '0', \STR_PAD_LEFT).'aa.json', '{"stats":{},"mutants":[]}');
    }

    /**
     * @return list<string>
     */
    private function ids(string $directory): array
    {
        return (new RunCache($directory))->ids();
    }
}
