<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Support;

use WickedOne\InfectionMateExtension\Runner\ProcessExecutor;
use WickedOne\InfectionMateExtension\Runner\RunResult;

/**
 * records every call and answers through the handler instead of starting a process.
 */
class FakeProcessExecutor extends ProcessExecutor
{
    /** @var list<array{command: list<string>, cwd: string, timeout: int}> */
    public array $calls = [];

    /** @var array{command: list<string>, cwd: string, timeout: int}|null */
    private ?array $last = null;

    /**
     * @param \Closure(list<string>, string, int): RunResult $handler
     */
    public function __construct(private readonly \Closure $handler)
    {
    }

    public function execute(array $command, string $workingDirectory, int $timeout): RunResult
    {
        $this->calls[] = $this->last = ['command' => $command, 'cwd' => $workingDirectory, 'timeout' => $timeout];

        return ($this->handler)($command, $workingDirectory, $timeout);
    }

    /**
     * @return array{command: list<string>, cwd: string, timeout: int}
     */
    public function lastCall(): array
    {
        return $this->last ?? throw new \LogicException('The executor was never called.');
    }

    /**
     * the arguments following the --configuration flag, which sits right after the binary.
     *
     * @return list<string>
     */
    public function lastArguments(): array
    {
        $command = $this->lastCall()['command'];

        foreach ($command as $index => $argument) {
            if (str_starts_with($argument, '--configuration=')) {
                return \array_slice($command, $index + 1);
            }
        }

        return [];
    }
}
