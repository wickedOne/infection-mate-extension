<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Parser;

use WickedOne\InfectionMateExtension\Filesystem\PathResolver;

/**
 * mutants have no id in the log: they are referenced by bucket and position within it.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class JsonLogParser
{
    public const BUCKETS = ['escaped', 'timeouted', 'killed', 'killedByStaticAnalysis', 'errored', 'syntaxErrors', 'uncovered', 'ignored'];

    public function __construct(
        private readonly PathResolver $paths,
    ) {
    }

    /**
     * @return array{stats: array<string, int|float>, mutants: list<MutantResult>}
     */
    public function parse(string $json, string $projectRoot = ''): array
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data) || !isset($data['stats']) || !\is_array($data['stats'])) {
            throw new \InvalidArgumentException('Not an Infection JSON log: "stats" is missing.');
        }

        // infection reports real paths, the configured root may be a symlink
        $roots = '' === $projectRoot ? [] : [$projectRoot, $this->paths->resolve($projectRoot)];
        $mutants = [];

        foreach (self::BUCKETS as $bucket) {
            foreach (array_values($data[$bucket] ?? []) as $index => $entry) {
                $file = (string) ($entry['mutator']['originalFilePath'] ?? '');

                $mutants[] = new MutantResult(
                    $bucket,
                    $index,
                    (string) ($entry['mutator']['mutatorName'] ?? 'unknown'),
                    $this->relative($file, $roots),
                    (int) ($entry['mutator']['originalStartLine'] ?? 0),
                    (string) ($entry['diff'] ?? ''),
                    (string) ($entry['processOutput'] ?? ''),
                );
            }
        }

        return ['stats' => $data['stats'], 'mutants' => $mutants];
    }

    /**
     * @param list<string|null> $roots
     */
    private function relative(string $file, array $roots): string
    {
        foreach ($roots as $root) {
            if (null !== $root && str_starts_with($file, $root.'/')) {
                return substr($file, \strlen($root) + 1);
            }
        }

        return $file;
    }
}
