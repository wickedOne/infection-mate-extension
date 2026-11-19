<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Capability;

use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionExtension\Cache\OutputStore;
use WickedOne\InfectionExtension\Cache\RunCache;
use WickedOne\InfectionExtension\Enum\BucketEnum;
use WickedOne\InfectionExtension\Formatter\OutputExcerpt;
use WickedOne\InfectionExtension\Formatter\ResultFormatter;
use WickedOne\InfectionExtension\Parser\MutantResult;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class EscapedTool
{
    public function __construct(
        private readonly RunCache $cache,
        private readonly ResultFormatter $formatter,
        private readonly OutputStore $outputs,
    ) {
    }

    /**
     * @param string|null $runId      run id from infection-run; defaults to the latest regular run, not a partial one
     * @param string      $bucket     one of escaped, timeouted, uncovered, errored, syntaxErrors, ignored, killed, killedByStaticAnalysis
     * @param string|null $file       only mutants in this file (relative path, as listed in the summary)
     * @param string|null $mutator    only mutants of this mutator, for example "GreaterThanOrEqualTo"
     * @param int         $limit      page size, default 20, at most 100; page with offset when total is larger
     * @param int         $offset     page offset
     * @param bool        $idsOnly    only list the ids of the page, without diffs, to hand straight to the id option of infection-run
     * @param string|null $id         inspect this one mutant, whatever its bucket, instead of listing: its diff, the surrounding source lines and a kill hint or note; bucket, file, mutator and paging are ignored
     * @param bool        $withOutput with id, include the test runner output for this mutant, cleaned and cut when long; the full output is kept in the file named in processOutputFile
     */
    #[MateTool(
        name: 'infection-escaped',
        title: 'List mutants',
        description: 'List mutants from a previous infection-run, escaped by default. Use when the user wants to know which mutations tests failed to catch, which code is untested, or to triage a file or mutator. Escaped means no test failed on the mutated code, so it points to a missing or weak assertion. Other buckets: uncovered (no test runs the line), timeouted (mutation likely caused an infinite loop, usually counted as killed), errored and syntaxErrors (mutant could not run). Pass id to inspect a single mutant instead: its diff, the surrounding source lines and, with withOutput, the test output; check whether the mutation changes behaviour at all, because if not it is an equivalent mutant and no test can kill it.'
    )]
    public function execute(?string $runId = null, string $bucket = 'escaped', ?string $file = null, ?string $mutator = null, int $limit = 20, int $offset = 0, bool $idsOnly = false, ?string $id = null, bool $withOutput = false): string
    {
        if (null !== $id) {
            return $this->mutant($runId, $id, $withOutput);
        }

        $group = BucketEnum::tryFrom($bucket);

        if (null === $group) {
            return ResponseEncoder::encode(['error' => \sprintf('Unknown bucket "%s".', $bucket), 'hint' => 'Use one of: '.implode(', ', array_column(BucketEnum::cases(), 'value'))]);
        }

        $run = $this->cache->load($runId);

        if (null === $run) {
            return $this->runNotFound();
        }

        $mutants = array_filter(
            $run['mutants'],
            static fn (MutantResult $m): bool => $m->bucket === $group
                && (null === $file || $m->file === $file)
                && (null === $mutator || $m->mutator === $mutator),
        );

        $limit = min(100, max(1, $limit));
        $offset = max(0, $offset);

        return ResponseEncoder::encodeUntrusted($idsOnly ? $this->formatter->ids($run['id'], $mutants, $limit, $offset) : $this->formatter->listing($run['id'], $mutants, $limit, $offset));
    }

    private function mutant(?string $runId, string $id, bool $withOutput): string
    {
        $run = $this->cache->load($runId);

        if (null === $run) {
            return $this->runNotFound();
        }

        foreach ($run['mutants'] as $mutant) {
            if ($mutant->id === $id) {
                // the response only carries an excerpt of the output, when that leaves something out the full output stays readable in a file
                $file = $withOutput && OutputExcerpt::isCut($mutant->processOutput) ? $this->outputs->save('mutant-'.$mutant->id, $mutant->processOutput) : null;

                return ResponseEncoder::encodeUntrusted($this->formatter->mutant($run['id'], $mutant, $withOutput, $file));
            }
        }

        return ResponseEncoder::encode(['error' => \sprintf('Mutant "%s" not found in run %s.', $id, $run['id']), 'hint' => 'Use an id from infection-escaped, and the runId it was listed in.']);
    }

    private function runNotFound(): string
    {
        return ResponseEncoder::encode(['error' => 'Run not found.', 'hint' => 'Call infection-run first. Recent run ids: '.implode(', ', \array_slice($this->cache->ids(), 0, 5))]);
    }
}
