<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Runner;

use WickedOne\InfectionMateExtension\Exception\InvalidArgument;
use WickedOne\InfectionMateExtension\Filesystem\PathResolver;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class ArgumentResolver
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly PathResolver $paths,
    ) {
    }

    /**
     * @param string[]|null $paths
     *
     * @return list<string>
     *
     * @throws InvalidArgument
     */
    public function resolve(?array $paths, bool $gitDiffLines, ?string $gitDiffBase, ?string $mutators, bool $onlyCovering, bool $withUncovered, ?int $threads, ?int $timeout): array
    {
        if (null !== $timeout && $timeout < 1) {
            throw new InvalidArgument('Invalid timeout.', 'Pass a number of seconds of at least 1, or omit it for the default.');
        }

        if (null !== $gitDiffBase && !$gitDiffLines) {
            throw new InvalidArgument('gitDiffBase has no effect without gitDiffLines.', 'Set gitDiffLines to true, otherwise the whole codebase is mutated.');
        }

        $arguments = [
            ...$this->mutators($mutators),
            ...($gitDiffLines ? ['--git-diff-lines'] : []),
            ...$this->gitDiffBase($gitDiffBase),
            ...($onlyCovering ? ['--only-covering-test-cases'] : []),
            ...($withUncovered ? ['--with-uncovered'] : []),
            ...(null === $threads ? [] : ['--threads='.max(1, $threads)]),
        ];

        foreach ($paths ?? [] as $path) {
            $arguments[] = $this->path($path);
        }

        return $arguments;
    }

    /**
     * @return list<string>
     */
    private function mutators(?string $mutators): array
    {
        if (null === $mutators) {
            return [];
        }

        if (!preg_match('/^[@A-Za-z0-9_,!-]+$/D', $mutators)) {
            throw new InvalidArgument('Invalid mutators value.', 'Use mutator names or @profiles separated by commas.');
        }

        return ['--mutators='.$mutators];
    }

    /**
     * @return list<string>
     */
    private function gitDiffBase(?string $base): array
    {
        if (null === $base) {
            return [];
        }

        if (!preg_match('#^[A-Za-z0-9_][A-Za-z0-9._/~^-]*$#D', $base)) {
            throw new InvalidArgument('Invalid gitDiffBase value.', 'Pass a branch, tag or commit such as "main".');
        }

        return ['--git-diff-base='.$base];
    }

    private function path(string $path): string
    {
        if ('' === $path || str_starts_with($path, '-') || \in_array('..', explode('/', $path), true) || str_starts_with($path, '/')) {
            throw new InvalidArgument(\sprintf('Invalid path "%s".', $path), 'Paths must be relative to the project root and stay inside it.');
        }

        $real = $this->paths->resolve($this->projectRoot.'/'.$path);
        $root = $this->paths->resolve($this->projectRoot);

        if (null !== $real && null !== $root && $real !== $root && !str_starts_with($real, $root.'/')) {
            throw new InvalidArgument(\sprintf('Path "%s" resolves outside the project.', $path), 'Paths must stay inside the project root.');
        }

        if (null === $real) {
            throw new InvalidArgument(\sprintf('Path "%s" does not exist.', $path), 'Check the path relative to the project root.');
        }

        return $path;
    }
}
