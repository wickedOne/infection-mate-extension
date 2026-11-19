<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Runner;

use WickedOne\InfectionExtension\Discovery\ConfigurationDiscovery;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class InfectionRunner
{
    public const string LOG_OPTION = 'logger-json';

    private const string TEMP_PREFIX = '.infection.mate.';
    private const int STALE_AFTER = 86400;

    public function __construct(
        private readonly string $projectRoot,
        private readonly ConfigurationDiscovery $configuration,
        private readonly ProcessExecutor $executor,
        private readonly int $defaultTimeout = 1800,
    ) {
    }

    /**
     * @param list<string> $arguments validated Infection arguments, without binary and fixed flags
     */
    public function run(array $arguments, ?int $timeout = null): RunResult
    {
        $this->removeStaleTempFiles();

        $logFile = $this->projectRoot.'/'.self::TEMP_PREFIX.bin2hex(random_bytes(6)).'.log.json';

        try {
            $result = $this->executor->execute(
                [...$this->configuration->binary(), '--'.self::LOG_OPTION.'='.$logFile, '--no-progress', '--no-interaction', '--no-ansi', ...$arguments],
                $this->projectRoot,
                $timeout ?? $this->defaultTimeout,
            );

            if ($result->timedOut) {
                return $result;
            }

            $log = @file_get_contents($logFile);

            return new RunResult($result->exitCode, $result->output, false === $log ? null : $log);
        } finally {
            @unlink($logFile);
        }
    }

    /**
     * a killed server skips the finally block above, so leftovers of long gone runs are swept at the next run.
     */
    private function removeStaleTempFiles(): void
    {
        foreach (@scandir($this->projectRoot) ?: [] as $file) {
            $path = $this->projectRoot.'/'.$file;

            if (str_starts_with($file, self::TEMP_PREFIX) && $this->isStale($path)) {
                @unlink($path);
            }
        }
    }

    private function isStale(string $path): bool
    {
        // @infection-ignore-all the boundary second cannot be pinned without a clock
        return @filemtime($path) < time() - self::STALE_AFTER;
    }
}
