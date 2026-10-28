<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Filesystem;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class PathResolver
{
    /**
     * canonical path with symlinks resolved, null when the path does not exist.
     */
    public function resolve(string $path): ?string
    {
        $real = realpath($path);

        return false === $real ? null : $real;
    }

    public function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/');
    }
}
