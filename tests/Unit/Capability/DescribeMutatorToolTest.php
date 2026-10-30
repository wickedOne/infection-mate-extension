<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Capability;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use WickedOne\InfectionMateExtension\Capability\DescribeMutatorTool;
use WickedOne\InfectionMateExtension\Discovery\ConfigurationDiscovery;
use WickedOne\InfectionMateExtension\Runner\InfectionRunner;
use WickedOne\InfectionMateExtension\Runner\RunResult;
use WickedOne\InfectionMateExtension\Tests\Support\FakeProcessExecutor;

class DescribeMutatorToolTest extends TestCase
{
    public function testReturnsTrimmedDescription(): void
    {
        $executor = new FakeProcessExecutor(static fn (): RunResult => new RunResult(0, "  Increment: bumps a number\n\n", null));

        $data = $this->decode($this->tool($executor)->execute('Increment'));

        $this->assertSame(['mutator' => 'Increment', 'description' => 'Increment: bumps a number'], $data);
        $this->assertSame(['infection', 'describe', '--no-ansi', '--no-interaction', 'Increment'], $executor->lastCall()['command']);
    }

    public function testReturnsInfectionOutputAsHintWhenDescribeFails(): void
    {
        $executor = new FakeProcessExecutor(static fn (): RunResult => new RunResult(3, "no such mutator\n", null));

        $data = $this->decode($this->tool($executor)->execute('Nope'));

        $this->assertSame(['error' => 'Could not describe "Nope".', 'hint' => 'no such mutator'], $data);
    }

    public function testReturnsErrorWhenInfectionCannotBeStarted(): void
    {
        $executor = new FakeProcessExecutor(static fn (): RunResult => throw new \RuntimeException('boom'));

        $data = $this->decode($this->tool($executor)->execute('Plus'));

        $this->assertSame(['error' => 'Could not start Infection: boom', 'hint' => 'Install it with "composer require --dev infection/infection".'], $data);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMutatorProvider(): iterable
    {
        yield 'option' => ['--help'];
        yield 'space' => ['Plus Minus'];
        yield 'semicolon' => ['Plus;ls'];
        yield 'trailing newline' => ["Plus\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidMutatorProvider')]
    public function testRejectsInvalidMutatorNamesWithoutRunningInfection(string $mutator): void
    {
        $executor = new FakeProcessExecutor(static fn (): RunResult => new RunResult(0, '', null));

        $data = $this->decode($this->tool($executor)->execute($mutator));

        $this->assertSame(['error' => 'Invalid mutator name.', 'hint' => 'Use the name as shown on a mutant, for example "Increment".'], $data);
        $this->assertSame([], $executor->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validMutatorProvider(): iterable
    {
        yield 'plain' => ['GreaterThanOrEqualTo'];
        yield 'digits and underscore' => ['Mutator_1'];
        yield 'namespaced' => ['App\\Mutator\\Custom'];
    }

    #[DataProvider('validMutatorProvider')]
    public function testAcceptsValidMutatorNames(string $mutator): void
    {
        $executor = new FakeProcessExecutor(static fn (): RunResult => new RunResult(0, 'ok', null));

        $data = $this->decode($this->tool($executor)->execute($mutator));

        $this->assertSame($mutator, $data['mutator']);
    }

    private function tool(FakeProcessExecutor $executor): DescribeMutatorTool
    {
        $root = vfsStream::setup('project')->url();

        return new DescribeMutatorTool(new InfectionRunner($root, new ConfigurationDiscovery($root), $executor));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $response): array
    {
        $decoded = ResponseEncoder::decode($response);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
