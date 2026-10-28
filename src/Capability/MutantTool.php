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

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class MutantTool
{
    public function __construct(
        private readonly RunCache $cache,
        private readonly ResultFormatter $formatter,
    ) {
    }

    /**
     * @param string      $ref        mutant reference such as "escaped:3", as listed by infection-escaped
     * @param string|null $runId      run id from infection-run; defaults to the latest run
     * @param bool        $withOutput include the (truncated) test runner output for this mutant
     */
    #[MateTool(
        name: 'infection-mutant',
        title: 'Inspect mutant',
        description: 'Inspect a single mutant: its diff, the surrounding source lines, and optionally the test output. Use before explaining why a mutant escaped or writing a test that kills it. Check whether the mutation changes behaviour at all; if not, it is an equivalent mutant and no test can kill it.'
    )]
    public function execute(string $ref, ?string $runId = null, bool $withOutput = false): string
    {
        $run = $this->cache->load($runId);

        if (null === $run) {
            return ResponseEncoder::encode(['error' => 'Run not found.', 'hint' => 'Call infection-run first. Recent run ids: '.implode(', ', \array_slice($this->cache->ids(), 0, 5))]);
        }

        foreach ($run['mutants'] as $mutant) {
            if ($mutant->ref() === $ref) {
                return ResponseEncoder::encodeUntrusted($this->formatter->mutant($run['id'], $mutant, $withOutput));
            }
        }

        return ResponseEncoder::encode(['error' => \sprintf('Mutant "%s" not found in run %s.', $ref, $run['id']), 'hint' => 'Use a ref from infection-escaped, in the form bucket:index.']);
    }
}
