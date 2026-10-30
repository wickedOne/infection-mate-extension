<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Config;

use ColinODell\Json5\Json5Decoder;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class ConfigurationDetector
{
    private const array CANDIDATES = ['infection.json5', 'infection.json', 'infection.json5.dist', 'infection.json.dist'];

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function detect(): ?string
    {
        foreach (self::CANDIDATES as $candidate) {
            if (is_file($this->projectRoot.'/'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $decoded = '' === ($raw = $this->raw()) ? null : Json5Decoder::decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    public function binary(): array
    {
        $local = $this->projectRoot.'/vendor/bin/infection';

        return is_file($local) ? [\PHP_BINARY, $local] : ['infection'];
    }

    /**
     * empty when there is no config or it cannot be read.
     */
    private function raw(): string
    {
        return null === ($file = $this->detect()) ? '' : (@file_get_contents($this->projectRoot.'/'.$file) ?: '');
    }
}
