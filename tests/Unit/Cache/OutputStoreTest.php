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
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Cache\OutputStore;

class OutputStoreTest extends TestCase
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

    public function testSavesTheFullOutputAndReturnsThePath(): void
    {
        $path = (new OutputStore($this->root.'/outputs'))->save('failure', "full\noutput");

        self::assertNotNull($path);
        self::assertStringStartsWith($this->root.'/outputs/', $path);
        self::assertStringEndsWith('-failure.log', $path);
        self::assertSame("full\noutput", file_get_contents($path));
    }

    public function testNamesAreSafeFileNames(): void
    {
        $path = (new OutputStore($this->root.'/outputs'))->save('mutant-../../etc/passwd', 'x');

        self::assertNotNull($path);
        self::assertSame($this->root.'/outputs', \dirname($path));
        self::assertStringEndsWith('-mutant-.._.._etc_passwd.log', $path);
    }

    public function testReturnsNullWhenTheDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->root.'/outputs', 'a file where the directory should be');

        self::assertNull((new OutputStore($this->root.'/outputs'))->save('failure', 'x'));
    }

    public function testReturnsNullWhenTheFileCannotBeWritten(): void
    {
        mkdir($this->root.'/outputs', 0o555);

        self::assertNull((new OutputStore($this->root.'/outputs'))->save('failure', 'x'));
    }

    public function testKeepsOnlyTheNewestFiles(): void
    {
        $store = new OutputStore($this->root.'/outputs', 3);

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $store->save($name, $name);
            usleep(2000);
        }

        $kept = array_values(array_diff(scandir($this->root.'/outputs') ?: [], ['.', '..']));

        self::assertCount(3, $kept);
        self::assertSame(['c.log', 'd.log', 'e.log'], array_map(static fn (string $f): string => substr($f, 12), $kept));
    }

    public function testPruningLeavesOtherFilesAlone(): void
    {
        mkdir($this->root.'/outputs', 0o775);
        file_put_contents($this->root.'/outputs/notes.txt', 'keep');
        $store = new OutputStore($this->root.'/outputs', 1);

        $store->save('a', 'a');
        usleep(2000);
        $store->save('b', 'b');

        self::assertFileExists($this->root.'/outputs/notes.txt');
        self::assertCount(2, array_diff(scandir($this->root.'/outputs') ?: [], ['.', '..']));
    }

    public function testKeepsTwentyFilesByDefault(): void
    {
        $store = new OutputStore($this->root.'/outputs');

        foreach (range('a', 'u') as $name) {
            $store->save($name, $name);
            usleep(2000);
        }

        $kept = array_values(array_diff(scandir($this->root.'/outputs') ?: [], ['.', '..']));

        self::assertCount(20, $kept);
        self::assertStringEndsWith('-b.log', $kept[0]);
        self::assertStringEndsWith('-u.log', $kept[19]);
    }

    public function testTheDirectoryIsCreatedGroupWritable(): void
    {
        (new OutputStore($this->root.'/outputs'))->save('failure', 'x');

        self::assertSame(0o775, fileperms($this->root.'/outputs') & 0o777);
    }

    public function testTheNameStartsWithTheCreationTimeInMilliseconds(): void
    {
        $before = (int) (microtime(true) * 1000);
        $path = (new OutputStore($this->root.'/outputs'))->save('failure', 'x');
        $after = (int) (microtime(true) * 1000);

        self::assertNotNull($path);
        self::assertSame(11, strpos(basename($path), '-'));
        self::assertGreaterThanOrEqual($before, hexdec(substr(basename($path), 0, 11)));
        self::assertLessThanOrEqual($after, hexdec(substr(basename($path), 0, 11)));
    }
}
