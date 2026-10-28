<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Formatter;

use WickedOne\InfectionMateExtension\Parser\MutantResult;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class ResultFormatter
{
    private const DIFF_LIMIT = 1200;
    private const OUTPUT_LIMIT = 2000;
    private const CONTEXT_LINES = 6;

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @param array<string, int|float> $stats
     * @param list<MutantResult>       $mutants
     *
     * @return array<string, mixed>
     */
    public function summary(string $runId, array $stats, array $mutants, int $exitCode): array
    {
        $escaped = array_filter($mutants, static fn (MutantResult $m): bool => 'escaped' === $m->bucket);

        return [
            'runId' => $runId,
            'exitCode' => $exitCode,
            'stats' => $stats,
            'escapedByFile' => $this->count($escaped, static fn (MutantResult $m): string => $m->file),
            'escapedByMutator' => $this->count($escaped, static fn (MutantResult $m): string => $m->mutator),
            'hint' => 0 === \count($escaped)
                ? 'No escaped mutants. Check "uncovered" and "timeouted" counts in stats.'
                : 'Use infection-escaped to list escaped mutants and infection-mutant to inspect one.',
        ];
    }

    /**
     * @param array<int, MutantResult> $mutants
     *
     * @return array<string, mixed>
     */
    public function listing(string $runId, array $mutants, int $limit, int $offset): array
    {
        $page = \array_slice($mutants, $offset, $limit);

        return [
            'runId' => $runId,
            'total' => \count($mutants),
            'offset' => $offset,
            'count' => \count($page),
            'mutants' => array_map(fn (MutantResult $m): array => [
                'ref' => $m->ref(),
                'mutator' => $m->mutator,
                'file' => $m->file,
                'line' => $m->line,
                'diff' => $this->cap($m->diff, self::DIFF_LIMIT),
            ], $page),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mutant(string $runId, MutantResult $mutant, bool $withOutput): array
    {
        $payload = [
            'runId' => $runId,
            'ref' => $mutant->ref(),
            'bucket' => $mutant->bucket,
            'mutator' => $mutant->mutator,
            'file' => $mutant->file,
            'line' => $mutant->line,
            'diff' => $this->cap($mutant->diff, self::DIFF_LIMIT * 2),
            'context' => $this->context($mutant->file, $mutant->line),
        ];

        if ($withOutput) {
            $payload['processOutput'] = $this->cap($mutant->processOutput, self::OUTPUT_LIMIT);
        }

        return $payload;
    }

    /**
     * empty when the file is unreadable or outside the project.
     *
     * @return list<string>
     */
    private function context(string $file, int $line): array
    {
        $path = str_starts_with($file, '/') ? $file : $this->projectRoot.'/'.$file;
        $real = realpath($path);
        $root = realpath($this->projectRoot);

        if (false === $real || false === $root || !str_starts_with($real, $root.'/') || !is_file($real)) {
            return [];
        }

        $lines = file($real, \FILE_IGNORE_NEW_LINES) ?: [];
        $start = max(1, $line - self::CONTEXT_LINES);
        $out = [];

        foreach (\array_slice($lines, $start - 1, self::CONTEXT_LINES * 2 + 1) as $i => $text) {
            $out[] = \sprintf('%d: %s', $start + $i, $text);
        }

        return $out;
    }

    /**
     * @param array<int, MutantResult>       $mutants
     * @param callable(MutantResult): string $key
     *
     * @return array<string, int>
     */
    private function count(array $mutants, callable $key): array
    {
        $counts = [];

        foreach ($mutants as $mutant) {
            $k = $key($mutant);
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }

        arsort($counts);

        return \array_slice($counts, 0, 15, true);
    }

    private function cap(string $text, int $limit): string
    {
        return \strlen($text) > $limit ? mb_strcut($text, 0, $limit).'… [truncated]' : $text;
    }
}
