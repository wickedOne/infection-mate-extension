<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
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
use WickedOne\InfectionMateExtension\Runner\Argument\ArgumentResolver;
use WickedOne\InfectionMateExtension\Runner\Exception\InvalidArgumentException;
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
        private readonly ArgumentResolver $arguments,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @param string[]|null                $paths   source files or directories (relative to the project root) to mutate; omit for the configured sources
     * @param array<array-key, mixed>|null $options Infection run options by name without dashes, validated against the installed Infection; true switches a flag on, for example {"git-diff-lines": true, "git-diff-base": "main", "mutators": "@default", "only-covering-test-cases": true, "threads": 4}
     * @param int|null                     $timeout overall timeout in seconds (default 1800)
     */
    #[MateTool(
        name: 'infection-run',
        title: 'Run Infection',
        description: 'Run mutation testing with Infection. Use when the user asks for mutation testing, the mutation score (MSI), or escaped mutants. Scope the run with paths or the git-diff-lines option, because full runs are slow. Options map to the installed Infection\'s run options; an unknown one is rejected with the list of valid names. Returns stats and a runId; follow up with infection-escaped, infection-mutant or infection-summary instead of re-running.'
    )]
    public function execute(?array $paths = null, ?array $options = null, ?int $timeout = null): string
    {
        try {
            $arguments = $this->arguments->resolve($paths, $options, $timeout);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), $e->hint);
        }

        try {
            $result = $this->runner->run($arguments, $timeout);
        } catch (\Throwable $e) {
            return $this->error('Could not run Infection: '.$e->getMessage(), 'Check that infection/infection is installed (composer require --dev infection/infection) and that infection.json5 is valid.');
        }

        if ($result->timedOut) {
            return $this->error('Infection timed out.', 'Narrow the run with paths or options such as git-diff-lines or only-covering-test-cases, or raise timeout.', $result->output);
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
        } catch (\Throwable $e) {
            return $this->error('Could not read the Infection log: '.$e->getMessage(), 'Run infection manually to inspect the problem.', $result->output);
        }

        $warning = null;

        try {
            $runId = $this->cache->store($parsed['stats'], $parsed['mutants'], $result->exitCode);
        } catch (\Throwable $e) {
            $runId = null;
            $warning = 'Run could not be cached, so infection-escaped, infection-mutant and infection-summary cannot follow up: '.$e->getMessage();
        }

        $summary = $this->formatter->summary($runId, $parsed['stats'], $parsed['mutants'], $result->exitCode);

        return ResponseEncoder::encodeUntrusted(null === $warning ? $summary : $summary + ['warning' => $warning]);
    }

    private function error(string $message, string $hint, ?string $output = null): string
    {
        if (null === $output) {
            return ResponseEncoder::encode(['error' => $message, 'hint' => $hint]);
        }

        return ResponseEncoder::encodeUntrusted(['error' => $message, 'hint' => $hint, 'output' => mb_strcut($output, max(0, \strlen($output) - 2000))]);
    }
}
