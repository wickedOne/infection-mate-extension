<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Cache;

/**
 * keeps the full output of a failed run or of a mutant, so the agent can read all of it while the response only
 * carries an excerpt; only the newest files are kept.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class OutputStore
{
    public function __construct(
        private readonly string $directory,
        private readonly int $keep = 20,
    ) {
    }

    /**
     * @return string|null the path of the file, null when it could not be written
     */
    public function save(string $name, string $output): ?string
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0o775, true);
        }

        // timestamp prefix makes names sort by creation time, file mtimes are too coarse
        $path = $this->directory.'/'.\sprintf('%011s', dechex((int) (microtime(true) * 1000))).'-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $name).'.log';

        // also fails when the directory could not be created
        if (false === @file_put_contents($path, $output)) {
            return null;
        }

        $this->prune();

        return $path;
    }

    private function prune(): void
    {
        $files = preg_grep('/\.log$/D', @scandir($this->directory) ?: []) ?: [];
        rsort($files);

        foreach (\array_slice($files, $this->keep) as $file) {
            @unlink($this->directory.'/'.$file);
        }
    }
}
