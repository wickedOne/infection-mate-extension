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

use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * Example tool demonstrating the basic structure of a Mate tool.
 *
 * Replace this with your actual implementation.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ExampleTool
{
    /**
     * Tools are invoked as callables.
     *
     * Parameters become the tool input schema. Their types and `@param` descriptions
     * are what the AI sees, so describe every one of them.
     *
     * Use constructor injection for dependencies.
     *
     * @param string|null $name optional name to personalize the greeting
     */
    #[MateTool(name: 'example-hello', title: 'Example Hello', description: 'Return a greeting so the AI can verify the extension is wired correctly.')]
    public function execute(?string $name = null): string
    {
        return ResponseEncoder::encode([
            'message' => null === $name ? 'Hello from MatesOfMate!' : \sprintf('Hello %s from MatesOfMate!', $name),
            'hint' => 'Replace this tool with your actual implementation.',
        ]);
    }
}
