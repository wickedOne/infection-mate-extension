<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Parser\MutantResult;

class MutantResultTest extends TestCase
{
    public function testToArrayExposesAllFields(): void
    {
        self::assertSame([
            'bucket' => 'escaped',
            'id' => 'escaped-3',
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

        self::assertSame($original->jsonSerialize(), $restored->jsonSerialize());
    }

    public function testFromArrayCastsScalarTypes(): void
    {
        $mutant = MutantResult::fromArray([
            'bucket' => 'killed',
            'id' => 7,
            'mutator' => 'Plus',
            'file' => 'src/A.php',
            'line' => '9',
            'diff' => '',
            'processOutput' => '',
        ]);

        self::assertSame('7', $mutant->id);
        self::assertSame(9, $mutant->line);
    }

    public function testFromArrayCastsEveryFieldToItsType(): void
    {
        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            $mutant = MutantResult::fromArray([
                'bucket' => ['x'],
                'id' => ['x'],
                'mutator' => ['x'],
                'file' => ['x'],
                'line' => ['x'],
                'diff' => ['x'],
                'processOutput' => ['x'],
            ]);
        } finally {
            restore_error_handler();
        }

        self::assertSame(['Array', 'Array', 'Array', 'Array', 1, 'Array', 'Array'], array_values($mutant->jsonSerialize()));
    }

    private function mutant(): MutantResult
    {
        return new MutantResult('escaped', 'escaped-3', 'Increment', 'src/Calc.php', 42, "-a++\n+a--", 'FAILURES!');
    }
}
