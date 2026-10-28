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

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class RunResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly ?string $log,
        public readonly bool $timedOut = false,
    ) {
    }
}
