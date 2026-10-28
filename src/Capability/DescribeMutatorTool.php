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
use WickedOne\InfectionMateExtension\Runner\InfectionRunner;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class DescribeMutatorTool
{
    public function __construct(
        private readonly InfectionRunner $runner,
    ) {
    }

    /**
     * @param string $mutator mutator name as shown in a mutant, for example "GreaterThanOrEqualTo"
     */
    #[MateTool(
        name: 'infection-describe-mutator',
        title: 'Describe mutator',
        description: 'Describe an Infection mutator: category, what it changes, an example diff and how to kill it. Use when an escaped mutant names a mutator you need to explain.'
    )]
    public function execute(string $mutator): string
    {
        if (!preg_match('/^[A-Za-z0-9_\\\\]+$/D', $mutator)) {
            return ResponseEncoder::encode(['error' => 'Invalid mutator name.', 'hint' => 'Use the name as shown on a mutant, for example "Increment".']);
        }

        try {
            $result = $this->runner->describe($mutator);
        } catch (\Throwable $e) {
            return ResponseEncoder::encode(['error' => 'Could not start Infection: '.$e->getMessage(), 'hint' => 'Install it with "composer require --dev infection/infection".']);
        }

        if (0 !== $result->exitCode) {
            return ResponseEncoder::encode(['error' => \sprintf('Could not describe "%s".', $mutator), 'hint' => trim($result->output)]);
        }

        return ResponseEncoder::encode(['mutator' => $mutator, 'description' => trim($result->output)]);
    }
}
