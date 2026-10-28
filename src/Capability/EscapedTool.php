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
use WickedOne\InfectionMateExtension\Parser\MutantResult;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class EscapedTool
{
    public function __construct(
        private readonly RunCache $cache,
        private readonly ResultFormatter $formatter,
    ) {
    }

    /**
     * @param string|null $runId   run id from infection-run; defaults to the latest run
     * @param string      $bucket  one of escaped, timeouted, uncovered, errored, syntaxErrors, ignored, killed, killedByStaticAnalysis
     * @param string|null $file    only mutants in this file (relative path, as listed in the summary)
     * @param string|null $mutator only mutants of this mutator, for example "GreaterThanOrEqualTo"
     * @param int         $limit   page size, at most 100
     * @param int         $offset  page offset
     */
    #[MateTool(
        name: 'infection-escaped',
        title: 'List mutants',
        description: 'List mutants from a previous infection-run, escaped by default. Use when the user wants to know which mutations tests failed to catch, which code is untested, or to triage a file or mutator. Escaped means no test failed on the mutated code, so it points to a missing or weak assertion. Other buckets: uncovered (no test runs the line), timeouted (mutation likely caused an infinite loop, usually counted as killed), errored and syntaxErrors (mutant could not run).'
    )]
    public function execute(?string $runId = null, string $bucket = 'escaped', ?string $file = null, ?string $mutator = null, int $limit = 20, int $offset = 0): string
    {
        if (!\in_array($bucket, JsonLogParser::BUCKETS, true)) {
            return ResponseEncoder::encode(['error' => \sprintf('Unknown bucket "%s".', $bucket), 'hint' => 'Use one of: '.implode(', ', JsonLogParser::BUCKETS)]);
        }

        $run = $this->cache->load($runId);

        if (null === $run) {
            return ResponseEncoder::encode(['error' => 'Run not found.', 'hint' => 'Call infection-run first. Recent run ids: '.implode(', ', \array_slice($this->cache->ids(), 0, 5))]);
        }

        $mutants = array_filter(
            $run['mutants'],
            static fn (MutantResult $m): bool => $m->bucket === $bucket
                && (null === $file || $m->file === $file)
                && (null === $mutator || $m->mutator === $mutator),
        );

        return ResponseEncoder::encodeUntrusted($this->formatter->listing($run['id'], $mutants, min(100, max(1, $limit)), max(0, $offset)));
    }
}
