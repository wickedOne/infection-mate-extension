<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Capability;

use Symfony\AI\Mate\Attribute\MateResource;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionMateExtension\Discovery\ConfigurationDiscovery;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class ConfigResource
{
    public function __construct(
        private readonly ConfigurationDiscovery $configuration,
    ) {
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    #[MateResource(
        uri: 'infection://config',
        name: 'infection_config',
        description: 'The project Infection configuration: source directories, mutators, timeouts, thresholds and logs.',
        mimeType: 'text/plain'
    )]
    public function getConfiguration(): array
    {
        try {
            $payload = ['file' => $this->configuration->detect(), 'config' => $this->configuration->read()];
        } catch (\Throwable $e) {
            $payload = ['file' => $this->configuration->detect(), 'error' => 'Could not parse config: '.$e->getMessage()];
        }

        return [
            'uri' => 'infection://config',
            'mimeType' => 'text/plain',
            'text' => ResponseEncoder::encode($payload),
        ];
    }
}
