<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Parser;

use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Filesystem\PathResolver;

/**
 * the id is the mutant hash infection logs per entry: it is what `infection run --id` takes.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class JsonLogParser
{
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

        foreach (BucketEnum::cases() as $bucket) {
            foreach ($data[$bucket->value] ?? [] as $entry) {
                $file = (string) ($entry['mutator']['originalFilePath'] ?? '');

                $mutants[] = new MutantResult(
                    $bucket,
                    (string) ($entry['id'] ?? ''),
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
