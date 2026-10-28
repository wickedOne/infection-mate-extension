<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Support;

use WickedOne\InfectionMateExtension\Filesystem\PathResolver;

/**
 * realpath() cannot see vfs urls; existing paths resolve to themselves, links redirect elsewhere.
 */
class FakePathResolver extends PathResolver
{
    /**
     * @param array<string, string> $links path => resolved target
     */
    public function __construct(private readonly array $links = [])
    {
    }

    public function resolve(string $path): ?string
    {
        $path = preg_replace('#(/\.)+$#D', '', $path) ?? $path;

        return $this->links[$path] ?? (file_exists($path) ? $path : null);
    }

    public function isAbsolute(string $path): bool
    {
        return str_starts_with($path, 'vfs://') || parent::isAbsolute($path);
    }
}
