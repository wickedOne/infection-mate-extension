<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Runner;

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
        private readonly ProcessExecutor $executor,
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
        $logFile = $this->projectRoot.'/.infection.mate.'.$token.'.log.json';

        $config = $this->configuration->readObject();

        if ([] === get_object_vars($config)) {
            $config->source = ['directories' => ['src']];
        }

        unset($config->{'$schema'});

        // drop the project's own loggers, partial runs would overwrite their reports
        $config->logs = ['json' => $logFile];

        try {
            file_put_contents($configFile, json_encode($config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

            $result = $this->executor->execute(
                [...$this->configuration->binary(), '--configuration='.basename($configFile), '--no-progress', '--no-interaction', '--no-ansi', ...$arguments],
                $this->projectRoot,
                $timeout ?? $this->defaultTimeout,
            );

            if ($result->timedOut) {
                return $result;
            }

            $log = @file_get_contents($logFile);

            return new RunResult($result->exitCode, $result->output, false === $log ? null : $log);
        } finally {
            @unlink($configFile);
            @unlink($logFile);
        }
    }

    public function describe(string $mutator): RunResult
    {
        return $this->executor->execute([...$this->configuration->binary(), 'describe', '--no-ansi', '--no-interaction', $mutator], $this->projectRoot, self::DESCRIBE_TIMEOUT);
    }
}
