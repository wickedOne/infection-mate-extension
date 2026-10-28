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

use MatesOfMate\ExampleExtension\Capability\ExampleTool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

class ExampleToolTest extends TestCase
{
    public function testReturnsDecodablePayload(): void
    {
        $tool = new ExampleTool();

        $result = $tool->execute();

        $this->assertIsArray(ResponseEncoder::decode($result));
    }

    public function testContainsExpectedKeys(): void
    {
        $tool = new ExampleTool();

        $result = ResponseEncoder::decode($tool->execute());

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testCanPersonalizeGreeting(): void
    {
        $tool = new ExampleTool();

        $result = ResponseEncoder::decode($tool->execute('Johannes'));

        $this->assertSame('Hello Johannes from MatesOfMate!', $result['message']);
    }
}
