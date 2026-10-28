<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\ExampleExtension\Capability;

use Symfony\AI\Mate\Attribute\MateResource;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * Example resource demonstrating the basic structure of a Mate resource.
 *
 * Resources provide static context or configuration data to the AI.
 * Replace this with your actual implementation.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ExampleResource
{
    /**
     * Resources return data in a structured format with URI, MIME type, and content.
     *
     * You can accept parameters to make resources dynamic:
     * public function getConfig(string $environment): array
     *
     * Use constructor injection for dependencies.
     *
     * @return array{uri: string, mimeType: string, text: string}
     */
    #[MateResource(
        uri: 'example://config',
        name: 'example_config',
        mimeType: 'text/plain'
    )]
    public function getConfiguration(): array
    {
        return [
            'uri' => 'example://config',
            'mimeType' => 'text/plain',
            'text' => ResponseEncoder::encode([
                'version' => '1.0.0',
                'features' => [
                    'feature_a' => true,
                    'feature_b' => false,
                ],
                'hint' => 'Replace this resource with your actual configuration.',
            ]),
        ];
    }
}
