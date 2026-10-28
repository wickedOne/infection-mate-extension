<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Parser;

/**
 * mutants have no id in the log: they are referenced by bucket and position within it.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class JsonLogParser
{
    public const BUCKETS = ['escaped', 'timeouted', 'killed', 'killedByStaticAnalysis', 'errored', 'syntaxErrors', 'uncovered', 'ignored'];

    /**
     * @return array{stats: array<string, int|float>, mutants: list<MutantResult>}
     */
    public function parse(string $json, string $projectRoot = ''): array
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data) || !isset($data['stats']) || !\is_array($data['stats'])) {
            throw new \InvalidArgumentException('Not an Infection JSON log: "stats" is missing.');
        }

        $mutants = [];

        foreach (self::BUCKETS as $bucket) {
            foreach (array_values($data[$bucket] ?? []) as $index => $entry) {
                $file = (string) ($entry['mutator']['originalFilePath'] ?? '');

                $mutants[] = new MutantResult(
                    $bucket,
                    $index,
                    (string) ($entry['mutator']['mutatorName'] ?? 'unknown'),
                    '' !== $projectRoot && str_starts_with($file, $projectRoot.'/') ? substr($file, \strlen($projectRoot) + 1) : $file,
                    (int) ($entry['mutator']['originalStartLine'] ?? 0),
                    (string) ($entry['diff'] ?? ''),
                    (string) ($entry['processOutput'] ?? ''),
                );
            }
        }

        return ['stats' => $data['stats'], 'mutants' => $mutants];
    }
}
