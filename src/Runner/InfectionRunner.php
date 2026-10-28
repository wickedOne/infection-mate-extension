<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Runner;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use WickedOne\InfectionMateExtension\Config\ConfigurationDetector;

/**
 * infection has no cli switch for the json log, so a temp config copying the project's is written with
 * logs.json redirected; it must sit in the project root as phpunit.xml is resolved relative to it.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class InfectionRunner
{
    private const DESCRIBE_TIMEOUT = 60;

    public function __construct(
        private readonly string $projectRoot,
        private readonly ConfigurationDetector $configuration,
        private readonly int $defaultTimeout = 1800,
    ) {
    }

    /**
     * @param list<string> $arguments validated Infection arguments, without binary and config
     */
    public function run(array $arguments, ?int $timeout = null): RunResult
    {
        $token = bin2hex(random_bytes(6));
        $configFile = $this->projectRoot.'/.infection.mate.'.$token.'.json';
        $logFile = sys_get_temp_dir().'/infection-mate-'.$token.'.json';

        $config = $this->configuration->readObject();

        if ([] === get_object_vars($config)) {
            $config->source = ['directories' => ['src']];
        }

        unset($config->{'$schema'});

        // drop the project's own loggers, partial runs would overwrite their reports
        $config->logs = ['json' => $logFile];

        try {
            file_put_contents($configFile, json_encode($config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

            $process = new Process(
                [...$this->configuration->binary(), '--configuration='.basename($configFile), '--no-progress', '--no-interaction', '--no-ansi', ...$arguments],
                $this->projectRoot,
                null,
                null,
                $timeout ?? $this->defaultTimeout,
            );

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                return new RunResult(1, $process->getOutput().$process->getErrorOutput(), null, true);
            }

            $log = @file_get_contents($logFile);

            return new RunResult($this->exitCode($process), $process->getOutput().$process->getErrorOutput(), false === $log ? null : $log);
        } finally {
            @unlink($configFile);
            @unlink($logFile);
        }
    }

    public function describe(string $mutator): RunResult
    {
        $process = new Process([...$this->configuration->binary(), 'describe', '--no-ansi', '--no-interaction', $mutator], $this->projectRoot, null, null, self::DESCRIBE_TIMEOUT);
        $process->run();

        return new RunResult($this->exitCode($process), $process->getOutput().$process->getErrorOutput(), null);
    }

    private function exitCode(Process $process): int
    {
        // null only before the process has run
        // @infection-ignore-all
        return $process->getExitCode() ?? 1;
    }
}
