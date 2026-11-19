<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Cache;

use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Parser\MutantResult;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class RunCache
{
    // marks the id of a run limited to selected mutants
    private const string PARTIAL = 'p';

    public function __construct(
        private readonly string $directory,
        private readonly int $keep = 10,
    ) {
    }

    /**
     * @param array<string, int|float> $stats
     * @param list<MutantResult>       $mutants
     * @param int|null                 $selected number of mutants in a run limited to selected mutant ids (0 when none matched), null for a regular run
     */
    public function store(array $stats, array $mutants, int $exitCode, ?int $selected = null): string
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0o775, true);
        }

        // re-check, another process may have created it
        if (!is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('Cannot create cache directory "%s".', $this->directory));
        }

        // timestamp prefix makes ids sort by creation time, file mtimes are too coarse
        $millis = (int) (microtime(true) * 1000);
        $latest = $this->ids()[0] ?? null;

        if (null !== $latest) {
            // same-millisecond runs must still sort in storage order
            $millis = max($millis, \intval(substr($latest, 1, 11), 16) + 1);
        }

        $id = 'r'.\sprintf('%011s', dechex($millis)).bin2hex(random_bytes(1)).(null === $selected ? '' : self::PARTIAL);
        $written = @file_put_contents($this->path($id), json_encode([
            'stats' => $stats,
            'exitCode' => $exitCode,
            'mutants' => $mutants,
            'selected' => $selected,
        ], \JSON_THROW_ON_ERROR));

        if (false === $written) {
            throw new \RuntimeException(\sprintf('Cannot write run to "%s".', $this->path($id)));
        }

        $this->prune();

        return $id;
    }

    /**
     * @return array{id: string, stats: array<string, int|float>, exitCode: int, selected: int|null, mutants: list<MutantResult>}|null
     */
    public function load(?string $id): ?array
    {
        $id ??= $this->latestId();

        if (null === $id || !preg_match('/^r[0-9a-f]{13}p?$/D', $id) || !is_file($this->path($id))) {
            return null;
        }

        $raw = @file_get_contents($this->path($id));

        try {
            $data = false === $raw ? null : json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !isset($data['stats'], $data['mutants']) || !\is_array($data['stats']) || !\is_array($data['mutants'])) {
            return null;
        }

        foreach ($data['mutants'] as $entry) {
            if (!\is_array($entry) || [] !== array_diff(MutantResult::KEYS, array_keys($entry)) || !\is_string($entry['bucket']) || null === BucketEnum::tryFrom($entry['bucket'])) {
                return null;
            }
        }

        return [
            'id' => $id,
            'stats' => $data['stats'],
            'exitCode' => (int) ($data['exitCode'] ?? 1),
            'selected' => \is_int($data['selected'] ?? null) ? $data['selected'] : null,
            'mutants' => array_values(array_map(MutantResult::fromArray(...), $data['mutants'])),
        ];
    }

    /**
     * @return list<string> newest first
     */
    public function ids(): array
    {
        $files = preg_grep('/^r.*\.json$/D', @scandir($this->directory) ?: []) ?: [];
        $ids = array_map(static fn (string $f): string => basename($f, '.json'), $files);
        rsort($ids);

        return $ids;
    }

    /**
     * partial runs only stand in when there is no regular one, as their stats do not cover the project.
     */
    private function latestId(): ?string
    {
        $ids = $this->ids();

        foreach ($ids as $id) {
            if (!$this->partial($id)) {
                return $id;
            }
        }

        return $ids[0] ?? null;
    }

    private function partial(string $id): bool
    {
        return str_ends_with($id, self::PARTIAL);
    }

    private function path(string $id): string
    {
        return $this->directory.'/'.$id.'.json';
    }

    private function prune(): void
    {
        // partial runs have their own window, so re-running a few mutants never pushes out the full runs
        $ids = $this->ids();
        $regular = array_filter($ids, fn (string $id): bool => !$this->partial($id));
        $partial = array_filter($ids, $this->partial(...));

        foreach ([...\array_slice($regular, $this->keep), ...\array_slice($partial, $this->keep)] as $old) {
            @unlink($this->path($old));
        }
    }
}
