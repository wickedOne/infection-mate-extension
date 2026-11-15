<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Runner\ProcessExecutor;

class ProcessExecutorTest extends TestCase
{
    public function testReturnsExitCodeAndOutputWithStandardOutputFirst(): void
    {
        $result = (new ProcessExecutor())->execute($this->php('fwrite(STDERR, "err\n"); usleep(200000); echo "out\n"; exit(3);'), __DIR__, 30);

        self::assertSame(3, $result->exitCode);
        self::assertSame("out\nerr\n", $result->output);
        self::assertNull($result->log);
        self::assertFalse($result->timedOut);
    }

    public function testRunsInTheWorkingDirectory(): void
    {
        $result = (new ProcessExecutor())->execute($this->php('echo getcwd();'), __DIR__, 30);

        self::assertSame(0, $result->exitCode);
        self::assertSame(realpath(__DIR__), $result->output);
    }

    public function testReportsTimeoutWithPartialOutput(): void
    {
        $result = (new ProcessExecutor())->execute($this->php('echo "partial"; sleep(5);'), __DIR__, 1);

        self::assertTrue($result->timedOut);
        self::assertSame(1, $result->exitCode);
        self::assertNull($result->log);
        self::assertSame('partial', $result->output);
    }

    public function testTimeoutOutputHasStandardOutputBeforeErrorOutput(): void
    {
        $result = (new ProcessExecutor())->execute($this->php('echo "out"; fwrite(STDERR, "err"); sleep(5);'), __DIR__, 1);

        self::assertTrue($result->timedOut);
        self::assertSame('outerr', $result->output);
    }

    /**
     * @return list<string>
     */
    private function php(string $code): array
    {
        return [\PHP_BINARY, '-r', $code];
    }
}
