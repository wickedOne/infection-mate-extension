<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Capability;

use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionMateExtension\Cache\RunCache;
use WickedOne\InfectionMateExtension\Formatter\ResultFormatter;
use WickedOne\InfectionMateExtension\Parser\JsonLogParser;
use WickedOne\InfectionMateExtension\Runner\InfectionRunner;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class RunTool
{
    public function __construct(
        private readonly InfectionRunner $runner,
        private readonly JsonLogParser $parser,
        private readonly RunCache $cache,
        private readonly ResultFormatter $formatter,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @param string[]|null $paths         source files or directories (relative to the project root) to mutate; omit for the configured sources
     * @param bool          $gitDiffLines  only mutate lines changed compared to the base branch
     * @param string|null   $gitDiffBase   base ref for gitDiffLines, for example "main"
     * @param string|null   $mutators      comma separated mutator names or profiles, for example "@default" or "GreaterThanOrEqualTo,Increment"
     * @param bool          $onlyCovering  run only the tests covering each mutated line (faster)
     * @param bool          $withUncovered also mutate code that no test covers
     * @param int|null      $threads       parallel Infection processes
     * @param int|null      $timeout       overall timeout in seconds (default 1800)
     */
    #[MateTool(
        name: 'infection-run',
        title: 'Run Infection',
        description: 'Run mutation testing with Infection. Use when the user asks for mutation testing, the mutation score (MSI), or escaped mutants. Scope the run with paths or gitDiffLines, because full runs are slow. Returns stats and a runId; follow up with infection-escaped, infection-mutant or infection-summary instead of re-running.'
    )]
    public function execute(
        ?array $paths = null,
        bool $gitDiffLines = false,
        ?string $gitDiffBase = null,
        ?string $mutators = null,
        bool $onlyCovering = false,
        bool $withUncovered = false,
        ?int $threads = null,
        ?int $timeout = null,
    ): string {
        $arguments = [];

        if (null !== $timeout && $timeout < 1) {
            return $this->error('Invalid timeout.', 'Pass a number of seconds of at least 1, or omit it for the default.');
        }

        if (null !== $gitDiffBase && !$gitDiffLines) {
            return $this->error('gitDiffBase has no effect without gitDiffLines.', 'Set gitDiffLines to true, otherwise the whole codebase is mutated.');
        }

        if (null !== $mutators) {
            if (!preg_match('/^[@A-Za-z0-9_,!-]+$/D', $mutators)) {
                return $this->error('Invalid mutators value.', 'Use mutator names or @profiles separated by commas.');
            }
            $arguments[] = '--mutators='.$mutators;
        }

        if ($gitDiffLines) {
            $arguments[] = '--git-diff-lines';
        }

        if (null !== $gitDiffBase) {
            if (!preg_match('#^[A-Za-z0-9_][A-Za-z0-9._/~^-]*$#D', $gitDiffBase)) {
                return $this->error('Invalid gitDiffBase value.', 'Pass a branch, tag or commit such as "main".');
            }
            $arguments[] = '--git-diff-base='.$gitDiffBase;
        }

        foreach ([$onlyCovering ? '--only-covering-test-cases' : null, $withUncovered ? '--with-uncovered' : null] as $flag) {
            if (null !== $flag) {
                $arguments[] = $flag;
            }
        }

        if (null !== $threads) {
            $arguments[] = '--threads='.max(1, $threads);
        }

        foreach ($paths ?? [] as $path) {
            if ('' === $path || str_starts_with($path, '-') || \in_array('..', explode('/', $path), true) || str_starts_with($path, '/')) {
                return $this->error(\sprintf('Invalid path "%s".', $path), 'Paths must be relative to the project root and stay inside it.');
            }

            $real = realpath($this->projectRoot.'/'.$path);
            $root = realpath($this->projectRoot);

            if (false !== $real && false !== $root && $real !== $root && !str_starts_with($real, $root.'/')) {
                return $this->error(\sprintf('Path "%s" resolves outside the project.', $path), 'Paths must stay inside the project root.');
            }

            if (false === $real) {
                return $this->error(\sprintf('Path "%s" does not exist.', $path), 'Check the path relative to the project root.');
            }

            $arguments[] = $path;
        }

        try {
            $result = $this->runner->run($arguments, $timeout);
        } catch (\Throwable $e) {
            return $this->error('Could not run Infection: '.$e->getMessage(), 'Check that infection/infection is installed (composer require --dev infection/infection) and that infection.json5 is valid.');
        }

        if ($result->timedOut) {
            return $this->error('Infection timed out.', 'Narrow the run with paths, gitDiffLines or onlyCovering, or raise timeout.', $result->output);
        }

        if (null === $result->log) {
            return $this->error(
                'Infection produced no JSON log.',
                'It likely failed before mutating: no coverage driver (pcov/xdebug), failing initial tests, or invalid config. See output.',
                $result->output,
            );
        }

        try {
            $parsed = $this->parser->parse($result->log, $this->projectRoot);
            $runId = $this->cache->store($parsed['stats'], $parsed['mutants'], $result->exitCode);
        } catch (\Throwable $e) {
            return $this->error('Could not read the Infection log: '.$e->getMessage(), 'Run infection manually to inspect the problem.', $result->output);
        }

        return ResponseEncoder::encode($this->formatter->summary($runId, $parsed['stats'], $parsed['mutants'], $result->exitCode));
    }

    private function error(string $message, string $hint, ?string $output = null): string
    {
        if (null === $output) {
            return ResponseEncoder::encode(['error' => $message, 'hint' => $hint]);
        }

        return ResponseEncoder::encodeUntrusted(['error' => $message, 'hint' => $hint, 'output' => mb_strcut($output, max(0, \strlen($output) - 2000))]);
    }
}
