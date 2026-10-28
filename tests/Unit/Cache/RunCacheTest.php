<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Cache\RunCache;
use WickedOne\InfectionMateExtension\Parser\MutantResult;

class RunCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/infection-mate-cache-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testStoreReturnsIdInExpectedFormat(): void
    {
        $id = (new RunCache($this->directory))->store(['msi' => 50], $this->mutants(), 1);

        $this->assertMatchesRegularExpression('/^r[0-9a-f]{13}$/', $id);
    }

    public function testStoreCreatesMissingDirectory(): void
    {
        $this->assertDirectoryDoesNotExist($this->directory);

        (new RunCache($this->directory))->store([], [], 0);

        $this->assertDirectoryExists($this->directory);
    }

    public function testStoreThrowsWhenDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->directory, 'occupied');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create cache directory');

        (new RunCache($this->directory.'/nested'))->store([], [], 0);
    }

    public function testLoadRestoresStoredRun(): void
    {
        $cache = new RunCache($this->directory);
        $stats = ['msi' => 50, 'escapedCount' => 1];

        $id = $cache->store($stats, $this->mutants(), 2);
        $run = $cache->load($id);

        $this->assertNotNull($run);
        $this->assertSame($id, $run['id']);
        $this->assertSame($stats, $run['stats']);
        $this->assertSame(2, $run['exitCode']);
        $this->assertContainsOnlyInstancesOf(MutantResult::class, $run['mutants']);
        $this->assertSame(
            array_map(static fn (MutantResult $m): array => $m->jsonSerialize(), $this->mutants()),
            array_map(static fn (MutantResult $m): array => $m->jsonSerialize(), $run['mutants']),
        );
    }

    public function testLoadWithoutIdReturnsMostRecentRun(): void
    {
        $cache = new RunCache($this->directory);
        $older = $cache->store(['msi' => 10], [], 0);
        $newer = $cache->store(['msi' => 90], [], 0);
        $this->age($older, time() - 300);
        $this->age($newer, time() - 100);

        $run = $cache->load(null);

        $this->assertNotNull($run);
        $this->assertSame($newer, $run['id']);
        $this->assertSame(['msi' => 90], $run['stats']);
    }

    public function testLoadWithoutIdReturnsNullWhenCacheIsEmpty(): void
    {
        $this->assertNull((new RunCache($this->directory))->load(null));
    }

    public function testLoadReturnsNullForUnknownId(): void
    {
        $cache = new RunCache($this->directory);
        $cache->store([], [], 0);

        $this->assertNull($cache->load('r0000000000000'));
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

        $this->assertNull($cache->load($id));
    }

    public function testIdsAreListedNewestFirst(): void
    {
        $cache = new RunCache($this->directory);
        $first = $cache->store([], [], 0);
        $second = $cache->store([], [], 0);
        $third = $cache->store([], [], 0);
        $this->age($first, time() - 300);
        $this->age($second, time() - 200);
        $this->age($third, time() - 100);

        $this->assertSame([$third, $second, $first], $cache->ids());
    }

    public function testIdsIsEmptyWhenDirectoryDoesNotExist(): void
    {
        $this->assertSame([], (new RunCache($this->directory))->ids());
    }

    public function testPruneKeepsOnlyTheNewestRuns(): void
    {
        $cache = new RunCache($this->directory, 2);
        $base = time() - 1000;

        $first = $cache->store([], [], 0);
        $this->age($first, $base + 1);

        $second = $cache->store([], [], 0);
        $this->age($second, $base + 2);

        // the third store prunes the oldest run
        $third = $cache->store([], [], 0);

        $this->assertSame([$third, $second], $cache->ids());
        $this->assertNull($cache->load($first));
        $this->assertNotNull($cache->load($second));
        $this->assertNotNull($cache->load($third));
    }

    public function testDefaultKeepRetainsTenRuns(): void
    {
        $cache = new RunCache($this->directory);
        $base = time() - 1000;

        for ($i = 0; $i < 11; ++$i) {
            $id = $cache->store([], [], 0);
            $this->age($id, $base + $i);
        }

        $this->assertCount(10, $cache->ids());
    }

    public function testStoreCreatesDirectoryWithGroupWritableMode(): void
    {
        $previous = umask(0);

        try {
            (new RunCache($this->directory))->store([], [], 0);
        } finally {
            umask($previous);
        }

        $this->assertSame(0o775, fileperms($this->directory) & 0o777);
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

        $this->assertSame([], $deprecations);
        $this->assertSame(14, \strlen($id));
        $this->assertGreaterThanOrEqual($before, hexdec(substr($id, 1, 11)));
        $this->assertLessThanOrEqual($after, hexdec(substr($id, 1, 11)));
    }

    public function testStoreSortsAfterNewestExistingRunEvenWhenClockIsBehind(): void
    {
        mkdir($this->directory, 0o775, true);
        $future = (int) (microtime(true) * 1000) + 10_000_000;
        $this->seedId($future - 500);
        $this->seedId($future);

        $id = (new RunCache($this->directory))->store([], [], 0);

        $this->assertSame($future + 1, (int) hexdec(substr($id, 1, 11)));
        $this->assertSame($id, $this->ids($this->directory)[0]);
    }

    public function testLoadRejectsMalformedIdsEvenWhenTheFileExists(): void
    {
        mkdir($this->directory, 0o775, true);
        foreach (['xr0123456789abc', 'r0123456789abcd'] as $id) {
            file_put_contents($this->directory.'/'.$id.'.json', '{"stats":{},"mutants":[]}');
            $this->assertNull((new RunCache($this->directory))->load($id), $id);
        }

        file_put_contents($this->directory.'/r0123456789abc.json', '{"stats":{},"mutants":[]}');
        $this->assertNotNull((new RunCache($this->directory))->load('r0123456789abc'));
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
    }

    #[DataProvider('corruptRunProvider')]
    public function testLoadReturnsNullForCorruptRunFile(string $contents): void
    {
        mkdir($this->directory, 0o775, true);
        file_put_contents($this->directory.'/r0000000000001.json', $contents);

        $this->assertNull((new RunCache($this->directory))->load('r0000000000001'));
    }

    public function testLoadDefaultsExitCodeToOneAndCastsItToInt(): void
    {
        mkdir($this->directory, 0o775, true);
        file_put_contents($this->directory.'/r0000000000001.json', '{"stats":{},"mutants":[]}');
        file_put_contents($this->directory.'/r0000000000002.json', '{"stats":{},"mutants":[],"exitCode":"2"}');
        $cache = new RunCache($this->directory);

        $this->assertSame(1, $cache->load('r0000000000001')['exitCode'] ?? null);
        $this->assertSame(2, $cache->load('r0000000000002')['exitCode'] ?? null);
    }

    public function testLoadReturnsMutantsAsList(): void
    {
        mkdir($this->directory, 0o775, true);
        $mutant = static fn (int $i): array => (new MutantResult('escaped', $i, 'Plus', 'src/A.php', 1, '', ''))->jsonSerialize();
        file_put_contents($this->directory.'/r0000000000001.json', json_encode(['stats' => [], 'mutants' => [4 => $mutant(4), 9 => $mutant(9)]]));

        $run = (new RunCache($this->directory))->load('r0000000000001');

        $this->assertSame([0, 1], array_keys($run['mutants'] ?? []));
    }

    public function testLoadRejectsIdWithTrailingNewlineEvenWhenSuchFileExists(): void
    {
        $cache = new RunCache($this->directory);
        $id = $cache->store(['msi' => 1], [], 0);
        copy($this->directory.'/'.$id.'.json', $this->directory.'/'.$id."\n.json");

        $this->assertNull($cache->load($id."\n"));
    }

    /**
     * @return list<MutantResult>
     */
    private function mutants(): array
    {
        return [
            new MutantResult('escaped', 0, 'Plus', 'src/Calc.php', 8, '-a+b', 'FAILURES!'),
            new MutantResult('killed', 0, 'Increment', 'src/Str.php', 3, '-a++', ''),
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

    private function age(string $id, int $timestamp): void
    {
        touch($this->directory.'/'.$id.'.json', $timestamp);
    }
}
