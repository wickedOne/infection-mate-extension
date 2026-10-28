<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Capability;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionMateExtension\Capability\ConfigResource;
use WickedOne\InfectionMateExtension\Config\ConfigurationDetector;

class ConfigResourceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/infection-mate-resource-test-'.bin2hex(random_bytes(6));

        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDescribesResourceWithUriAndPlainTextMimeType(): void
    {
        $result = $this->resource()->getConfiguration();

        $this->assertSame('infection://config', $result['uri']);
        $this->assertSame('text/plain', $result['mimeType']);
        $this->assertArrayHasKey('text', $result);
    }

    public function testReportsNoFileAndEmptyConfigWhenNoneExists(): void
    {
        $payload = $this->payload($this->resource());

        $this->assertNull($payload['file']);
        $this->assertSame([], $payload['config']);
        $this->assertArrayNotHasKey('error', $payload);
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

        $this->assertSame('infection.json5', $payload['file']);
        $this->assertSame(['source' => ['directories' => ['src']], 'minMsi' => 80], $payload['config']);
        $this->assertArrayNotHasKey('error', $payload);
    }

    public function testReportsParseErrorInsteadOfFailing(): void
    {
        file_put_contents($this->root.'/infection.json', '{"source": ');

        $payload = $this->payload($this->resource());

        $this->assertSame('infection.json', $payload['file']);
        $this->assertArrayNotHasKey('config', $payload);
        $this->assertStringStartsWith('Could not parse config: ', $payload['error']);
        $this->assertGreaterThan(\strlen('Could not parse config: '), \strlen($payload['error']));
        $this->assertSame(['file', 'error'], array_keys($payload));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    private function resource(): ConfigResource
    {
        return new ConfigResource(new ConfigurationDetector($this->root));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ConfigResource $resource): array
    {
        $decoded = ResponseEncoder::decode($resource->getConfiguration()['text']);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
