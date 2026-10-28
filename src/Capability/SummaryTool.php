<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Capability;

use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionMateExtension\Cache\RunCache;
use WickedOne\InfectionMateExtension\Formatter\ResultFormatter;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class SummaryTool
{
    public function __construct(
        private readonly RunCache $cache,
        private readonly ResultFormatter $formatter,
    ) {
    }

    /**
     * @param string|null $runId run id from infection-run; defaults to the latest run
     */
    #[MateTool(
        name: 'infection-summary',
        title: 'Summarise run',
        description: 'Summarise a previous infection-run: MSI, counts per outcome, and escaped mutants grouped by file and mutator. Use to find test gaps at a glance, or to compare MSI before and after adding tests (latest runs are listed in recentRuns).'
    )]
    public function execute(?string $runId = null): string
    {
        $run = $this->cache->load($runId);

        if (null === $run) {
            return ResponseEncoder::encode(['error' => 'Run not found.', 'hint' => 'Call infection-run first.']);
        }

        return ResponseEncoder::encodeUntrusted($this->formatter->summary($run['id'], $run['stats'], $run['mutants'], $run['exitCode']) + ['recentRuns' => \array_slice($this->cache->ids(), 0, 5)]);
    }
}
