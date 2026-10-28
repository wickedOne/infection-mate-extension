<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Runner\ProcessExecutor;

class ProcessExecutorTest extends TestCase
{
    public function testReturnsExitCodeAndOutputWithStandardOutputFirst(): void
    {
        $result = (new ProcessExecutor())->execute($this->php('fwrite(STDERR, "err\n"); usleep(200000); echo "out\n"; exit(3);'), __DIR__, 30);

        $this->assertSame(3, $result->exitCode);
        $this->assertSame("out\nerr\n", $result->output);
        $this->assertNull($result->log);
        $this->assertFalse($result->timedOut);
    }

    public function testRunsInTheWorkingDirectory(): void
    {
        $result = (new ProcessExecutor())->execute($this->php('echo getcwd();'), __DIR__, 30);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame(realpath(__DIR__), $result->output);
    }

    public function testReportsTimeoutWithPartialOutput(): void
    {
        $result = (new ProcessExecutor())->execute($this->php('echo "partial"; sleep(5);'), __DIR__, 1);

        $this->assertTrue($result->timedOut);
        $this->assertSame(1, $result->exitCode);
        $this->assertNull($result->log);
        $this->assertSame('partial', $result->output);
    }

    public function testTimeoutOutputHasStandardOutputBeforeErrorOutput(): void
    {
        $result = (new ProcessExecutor())->execute($this->php('echo "out"; fwrite(STDERR, "err"); sleep(5);'), __DIR__, 1);

        $this->assertTrue($result->timedOut);
        $this->assertSame('outerr', $result->output);
    }

    /**
     * @return list<string>
     */
    private function php(string $code): array
    {
        return [\PHP_BINARY, '-r', $code];
    }
}
