<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\ExampleExtension\Tests\Capability;

use MatesOfMate\ExampleExtension\Capability\ExampleResource;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

class ExampleResourceTest extends TestCase
{
    public function testReturnsValidResourceStructure(): void
    {
        $resource = new ExampleResource();

        $result = $resource->getConfiguration();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('uri', $result);
        $this->assertArrayHasKey('mimeType', $result);
        $this->assertArrayHasKey('text', $result);
    }

    public function testHasCorrectUri(): void
    {
        $resource = new ExampleResource();

        $result = $resource->getConfiguration();

        $this->assertEquals('example://config', $result['uri']);
    }

    public function testHasPlainTextMimeType(): void
    {
        $resource = new ExampleResource();

        $result = $resource->getConfiguration();

        $this->assertEquals('text/plain', $result['mimeType']);
    }

    public function testContainsDecodableEncodedText(): void
    {
        $resource = new ExampleResource();

        $result = $resource->getConfiguration();

        $decoded = ResponseEncoder::decode((string) $result['text']);
        $this->assertArrayHasKey('version', $decoded);
        $this->assertArrayHasKey('features', $decoded);
    }
}
