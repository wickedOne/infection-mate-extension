<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Capability;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionExtension\Capability\ConfigResource;
use WickedOne\InfectionExtension\Discovery\ConfigurationDiscovery;

class ConfigResourceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('project')->url();
    }

    protected function tearDown(): void
    {
        unset($this->root);
    }

    public function testReportsNoFileAndEmptyConfigWhenNoneExists(): void
    {
        $payload = $this->payload($this->resource());

        self::assertNull($payload['file']);
        self::assertSame([], $payload['config']);
        self::assertArrayNotHasKey('error', $payload);
    }

    public function testReturnsDetectedFileAndDecodedConfig(): void
    {
        file_put_contents($this->root.'/infection.json5', <<<'JSON5'
            {
                // mutate only the source directory
                "source": { "directories": ["src"], },
                "minMsi": 80,
            }
            JSON5);

        $payload = $this->payload($this->resource());

        self::assertSame('infection.json5', $payload['file']);
        self::assertSame(['source' => ['directories' => ['src']], 'minMsi' => 80], $payload['config']);
        self::assertArrayNotHasKey('error', $payload);
    }

    public function testReportsParseErrorInsteadOfFailing(): void
    {
        file_put_contents($this->root.'/infection.json', '{"source": ');

        $payload = $this->payload($this->resource());

        self::assertSame('infection.json', $payload['file']);
        self::assertArrayNotHasKey('config', $payload);
        self::assertStringStartsWith('Could not parse config: ', $payload['error']);
        self::assertGreaterThan(\strlen('Could not parse config: '), \strlen($payload['error']));
        self::assertSame(['file', 'error'], array_keys($payload));
    }

    private function resource(): ConfigResource
    {
        return new ConfigResource(new ConfigurationDiscovery($this->root));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ConfigResource $resource): array
    {
        $decoded = ResponseEncoder::decode($resource->getConfiguration()['text']);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
