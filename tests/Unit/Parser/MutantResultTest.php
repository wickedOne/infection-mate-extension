<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Parser\MutantResult;

class MutantResultTest extends TestCase
{
    public function testRefCombinesBucketAndIndex(): void
    {
        $this->assertSame('escaped:3', $this->mutant()->ref());
    }

    public function testToArrayExposesAllFields(): void
    {
        $this->assertSame([
            'bucket' => 'escaped',
            'index' => 3,
            'mutator' => 'Increment',
            'file' => 'src/Calc.php',
            'line' => 42,
            'diff' => "-a++\n+a--",
            'processOutput' => 'FAILURES!',
        ], $this->mutant()->jsonSerialize());
    }

    public function testFromArrayRestoresToArrayRoundtrip(): void
    {
        $original = $this->mutant();

        $restored = MutantResult::fromArray($original->jsonSerialize());

        $this->assertSame($original->jsonSerialize(), $restored->jsonSerialize());
        $this->assertSame('escaped:3', $restored->ref());
    }

    public function testFromArrayCastsScalarTypes(): void
    {
        $mutant = MutantResult::fromArray([
            'bucket' => 'killed',
            'index' => '7',
            'mutator' => 'Plus',
            'file' => 'src/A.php',
            'line' => '9',
            'diff' => '',
            'processOutput' => '',
        ]);

        $this->assertSame(7, $mutant->index);
        $this->assertSame(9, $mutant->line);
        $this->assertSame('killed:7', $mutant->ref());
    }

    public function testFromArrayCastsEveryFieldToItsType(): void
    {
        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            $mutant = MutantResult::fromArray([
                'bucket' => ['x'],
                'index' => ['x'],
                'mutator' => ['x'],
                'file' => ['x'],
                'line' => ['x'],
                'diff' => ['x'],
                'processOutput' => ['x'],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['Array', 1, 'Array', 'Array', 1, 'Array', 'Array'], array_values($mutant->jsonSerialize()));
    }

    private function mutant(): MutantResult
    {
        return new MutantResult('escaped', 3, 'Increment', 'src/Calc.php', 42, "-a++\n+a--", 'FAILURES!');
    }
}
