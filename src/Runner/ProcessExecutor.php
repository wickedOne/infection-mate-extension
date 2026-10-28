<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Runner;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class ProcessExecutor
{
    /**
     * @param list<string> $command
     */
    public function execute(array $command, string $workingDirectory, int $timeout): RunResult
    {
        $process = new Process($command, $workingDirectory, null, null, $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new RunResult(1, $process->getOutput().$process->getErrorOutput(), null, true);
        }

        // @infection-ignore-all null only before the process has run
        $exitCode = $process->getExitCode() ?? 1;

        return new RunResult($exitCode, $process->getOutput().$process->getErrorOutput(), null);
    }
}
