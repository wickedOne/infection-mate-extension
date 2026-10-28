<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Cache;

use WickedOne\InfectionMateExtension\Parser\MutantResult;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class RunCache
{
    public function __construct(
        private readonly string $directory,
        private readonly int $keep = 10,
    ) {
    }

    /**
     * @param array<string, int|float> $stats
     * @param list<MutantResult>       $mutants
     */
    public function store(array $stats, array $mutants, int $exitCode): string
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

        $id = 'r'.\sprintf('%011s', dechex($millis)).bin2hex(random_bytes(1));
        $written = @file_put_contents($this->path($id), json_encode([
            'stats' => $stats,
            'exitCode' => $exitCode,
            'mutants' => $mutants,
        ], \JSON_THROW_ON_ERROR));

        if (false === $written) {
            throw new \RuntimeException(\sprintf('Cannot write run to "%s".', $this->path($id)));
        }

        $this->prune();

        return $id;
    }

    /**
     * @return array{id: string, stats: array<string, int|float>, exitCode: int, mutants: list<MutantResult>}|null
     */
    public function load(?string $id): ?array
    {
        $id ??= $this->latestId();

        if (null === $id || !preg_match('/^r[0-9a-f]{13}$/D', $id) || !is_file($this->path($id))) {
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
            if (!\is_array($entry) || [] !== array_diff(MutantResult::KEYS, array_keys($entry))) {
                return null;
            }
        }

        return [
            'id' => $id,
            'stats' => $data['stats'],
            'exitCode' => (int) ($data['exitCode'] ?? 1),
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

    private function latestId(): ?string
    {
        return $this->ids()[0] ?? null;
    }

    private function path(string $id): string
    {
        return $this->directory.'/'.$id.'.json';
    }

    private function prune(): void
    {
        foreach (\array_slice($this->ids(), $this->keep) as $old) {
            @unlink($this->path($old));
        }
    }
}
