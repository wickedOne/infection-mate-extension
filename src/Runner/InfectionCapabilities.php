<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Runner;

use WickedOne\InfectionMateExtension\Config\ConfigurationDetector;

/**
 * the options and arguments of the installed infection's run command, read from its own console definition so
 * nothing about its flags is assumed: what a version adds, renames or drops is noticed here.
 *
 * @phpstan-type OptionShape array{accept_value: bool, is_value_required: bool, is_multiple: bool}
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class InfectionCapabilities
{
    private const TIMEOUT = 60;

    /** @var array{options: array<string, OptionShape>, arguments: list<string>}|null */
    private ?array $definition = null;

    private bool $loaded = false;

    public function __construct(
        private readonly string $projectRoot,
        private readonly ConfigurationDetector $configuration,
        private readonly ProcessExecutor $executor,
    ) {
    }

    /**
     * options are keyed by name without dashes.
     *
     * @return array{options: array<string, OptionShape>, arguments: list<string>}|null null when the definition cannot be read
     */
    public function definition(): ?array
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->definition = $this->read();
        }

        return $this->definition;
    }

    /**
     * @return array{options: array<string, OptionShape>, arguments: list<string>}|null
     */
    private function read(): ?array
    {
        try {
            $result = $this->executor->execute(
                [...$this->configuration->binary(), 'run', '--help', '--format=json', '--no-ansi', '--no-interaction'],
                $this->projectRoot,
                self::TIMEOUT,
            );
        } catch (\Throwable) {
            return null;
        }

        if ($result->timedOut || 0 !== $result->exitCode) {
            return null;
        }

        try {
            $decoded = json_decode($result->output, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $definition = \is_array($decoded) ? ($decoded['definition'] ?? null) : null;

        if (!\is_array($definition) || !\is_array($definition['options'] ?? null) || !\is_array($definition['arguments'] ?? null)) {
            return null;
        }

        $options = [];

        foreach ($definition['options'] as $name => $option) {
            $option = \is_array($option) ? $option : [];

            $options[$name] = [
                'accept_value' => (bool) ($option['accept_value'] ?? false),
                'is_value_required' => (bool) ($option['is_value_required'] ?? false),
                'is_multiple' => (bool) ($option['is_multiple'] ?? false),
            ];
        }

        return ['options' => $options, 'arguments' => array_map(strval(...), array_keys($definition['arguments']))];
    }
}
