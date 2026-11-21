<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Formatter;

use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Enum\EquivalenceNoteEnum;
use WickedOne\InfectionExtension\Enum\RemedyEnum;
use WickedOne\InfectionExtension\Filesystem\PathResolver;
use WickedOne\InfectionExtension\Parser\MutantResult;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class ResultFormatter
{
    private const int DIFF_LIMIT = 1200;
    private const int CONTEXT_LINES = 6;

    public function __construct(
        private readonly string $projectRoot,
        private readonly PathResolver $paths,
    ) {
    }

    /**
     * @param array<string, int|float> $stats
     * @param list<MutantResult>       $mutants
     * @param int|null                 $selected number of mutants in a run limited to selected mutant ids
     *
     * @return array<string, mixed>
     */
    public function summary(?string $runId, array $stats, array $mutants, int $exitCode, ?int $selected = null): array
    {
        $escaped = array_filter($mutants, static fn (MutantResult $m): bool => BucketEnum::Escaped === $m->bucket);

        return [
            'runId' => $runId,
            'exitCode' => $exitCode,
            'stats' => $stats,
            'escapedByFile' => $this->count($escaped, static fn (MutantResult $m): string => $m->file),
            'escapedByMutator' => $this->count($escaped, static fn (MutantResult $m): string => $m->mutator),
            'hint' => 0 === \count($escaped)
                ? 'No escaped mutants. Check "uncovered" and "timeouted" counts in stats.'
                : 'Use infection-escaped to list escaped mutants, or with an id to inspect one.',
        ] + (null === $selected ? [] : [
            'partial' => $this->partial($selected),
        ]);
    }

    /**
     * @param array<int, MutantResult> $mutants
     *
     * @return array<string, mixed>
     */
    public function listing(string $runId, array $mutants, int $limit, int $offset): array
    {
        // the hints are listed once per mutator for the page instead of on every entry
        $hints = $this->hints(\array_slice($mutants, $offset, $limit));

        return $this->page($runId, $mutants, $limit, $offset, 'mutants', fn (MutantResult $m): array => [
            'id' => $m->id,
            'mutator' => $m->mutator,
            'file' => $m->file,
            'line' => $m->line,
            'diff' => $this->cap($m->diff, self::DIFF_LIMIT),
        ] + $this->note($m, false), [] === $hints ? [] : ['hints' => $hints]);
    }

    /**
     * just the ids of a page, to hand straight to the id option of infection-run.
     *
     * @param array<int, MutantResult> $mutants
     *
     * @return array<string, mixed>
     */
    public function ids(string $runId, array $mutants, int $limit, int $offset): array
    {
        return $this->page($runId, $mutants, $limit, $offset, 'ids', static fn (MutantResult $m): string => $m->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function mutant(string $runId, MutantResult $mutant, bool $withOutput, ?string $outputFile = null): array
    {
        $payload = [
            'runId' => $runId,
            'id' => $mutant->id,
            'bucket' => $mutant->bucket->value,
            'mutator' => $mutant->mutator,
            'file' => $mutant->file,
            'line' => $mutant->line,
            'diff' => $this->cap($mutant->diff, self::DIFF_LIMIT * 2),
            'context' => $this->context($mutant->file, $mutant->line),
        ] + $this->note($mutant);

        if ($withOutput) {
            $payload['processOutput'] = OutputExcerpt::of($mutant->processOutput, $outputFile);

            if (null !== $outputFile) {
                $payload['processOutputFile'] = $outputFile;
            }
        }

        return $payload;
    }

    private function partial(int $selected): string
    {
        if (0 === $selected) {
            return 'None of the given mutant ids matched a mutant, so nothing ran and the stats are empty, not a score. Infection invalidates an id when the mutated source changes: list the escaped mutants again for current ids.';
        }

        return \sprintf('Limited to the mutants matching the given ids (%d): the stats cover just those, not the project. Run the wider scope again for the real score.', $selected);
    }

    /**
     * @param array<int, MutantResult>      $mutants
     * @param callable(MutantResult): mixed $item    how an entry of the page is listed under $key
     * @param array<string, mixed>          $extra   page level entries listed before $key
     *
     * @return array<string, mixed>
     */
    private function page(string $runId, array $mutants, int $limit, int $offset, string $key, callable $item, array $extra = []): array
    {
        $page = \array_slice($mutants, $offset, $limit);

        return [
            'runId' => $runId,
            'total' => \count($mutants),
            'offset' => $offset,
            'count' => \count($page),
        ] + $extra + [
            $key => array_map($item, $page),
        ];
    }

    /**
     * @return array{note?: string}
     */
    private function note(MutantResult $mutant, bool $withHint = true): array
    {
        $note = BucketEnum::Escaped === $mutant->bucket ? $this->noteFor($mutant, $withHint) : null;

        return null === $note ? [] : ['note' => $note];
    }

    private function noteFor(MutantResult $mutant, bool $withHint): ?string
    {
        $note = EquivalenceNoteEnum::forMutant($mutant);

        if ($note instanceof EquivalenceNoteEnum || !$withHint) {
            return $note?->value;
        }

        return RemedyEnum::forMutant($mutant)?->value;
    }

    /**
     * the hints of the escaped mutants of a page that have no equivalence note, once per mutator.
     *
     * @param array<int, MutantResult> $page
     *
     * @return array<string, string>
     */
    private function hints(array $page): array
    {
        $hints = [];

        foreach ($page as $mutant) {
            if (BucketEnum::Escaped !== $mutant->bucket || EquivalenceNoteEnum::forMutant($mutant) instanceof EquivalenceNoteEnum) {
                continue;
            }

            $hint = RemedyEnum::forMutant($mutant);

            if ($hint instanceof RemedyEnum) {
                $hints[$mutant->mutator] = $hint->value;
            }
        }

        return $hints;
    }

    /**
     * empty when the file is unreadable or outside the project.
     *
     * @return list<string>
     */
    private function context(string $file, int $line): array
    {
        $path = $this->paths->isAbsolute($file) ? $file : $this->projectRoot.'/'.$file;
        $real = $this->paths->resolve($path);
        $root = $this->paths->resolve($this->projectRoot);

        if (null === $real || null === $root || !str_starts_with($real, $root.'/') || !is_file($real)) {
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
