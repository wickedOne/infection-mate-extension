<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
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
