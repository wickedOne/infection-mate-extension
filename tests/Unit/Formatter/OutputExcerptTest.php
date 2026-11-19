<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Tests\Unit\Formatter;

use PHPUnit\Framework\TestCase;
use WickedOne\InfectionExtension\Formatter\OutputExcerpt;

class OutputExcerptTest extends TestCase
{
    public function testShortOutputIsReturnedAsIs(): void
    {
        self::assertSame("one\ntwo", OutputExcerpt::of("one\ntwo"));
    }

    public function testDropsColorCodesBlankLinesProgressBarsAndRulers(): void
    {
        $output = "\e[31mfailed\e[0m\n\n   0/39 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%\n  39/39 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%\n -----------\n ─────────────\n =====\nrest";

        self::assertSame("failed\nrest", OutputExcerpt::of($output));
    }

    public function testKeepsShortRulersAndNormalDashes(): void
    {
        self::assertSame("a - b\n----\n-x", OutputExcerpt::of("a - b\n----\n-x"));
    }

    public function testCollapsesThePaddingOfTables(): void
    {
        $output = "         Line   tests/Foo.php            \n          16     Method add() has   no   type   \n    4 spaces\n  2 spaces";

        self::assertSame("  Line tests/Foo.php\n  16 Method add() has no type\n  4 spaces\n  2 spaces", OutputExcerpt::of($output));
    }

    public function testOutputAtTheLimitIsNotCut(): void
    {
        $output = str_repeat('a', 100);

        self::assertSame($output, OutputExcerpt::of($output, null, 100));
    }

    public function testCutsTheMiddleKeepingTheHeadAndTheTail(): void
    {
        $output = str_repeat('H', 50).str_repeat('m', 300).str_repeat('T', 150);

        self::assertSame(str_repeat('H', 50)."\n[… 300 bytes left out …]\n".str_repeat('T', 150), OutputExcerpt::of($output, null, 200));
    }

    public function testTheNoteNamesTheFileWithTheFullOutput(): void
    {
        $excerpt = OutputExcerpt::of(str_repeat('a', 600), '/cache/failure.log', 200);

        self::assertSame(str_repeat('a', 50)."\n[… 400 bytes left out; full output: /cache/failure.log …]\n".str_repeat('a', 150), $excerpt);
    }

    public function testTheNoteWithoutAFileDoesNotMentionOne(): void
    {
        $excerpt = OutputExcerpt::of(str_repeat('a', 600), null, 200);

        self::assertSame(str_repeat('a', 50)."\n[… 400 bytes left out …]\n".str_repeat('a', 150), $excerpt);
    }

    public function testCutsWithoutSplittingMultibyteCharacters(): void
    {
        $excerpt = OutputExcerpt::of(str_repeat('é', 5000).'x', '/f.log');

        self::assertSame(str_repeat('é', 1000)."\n[… 2000 bytes left out; full output: /f.log …]\n".str_repeat('é', 3000).'x', $excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
    }

    public function testIsCutOnlyWhenTheCleanedOutputExceedsTheLimit(): void
    {
        self::assertFalse(OutputExcerpt::isCut(str_repeat('a', 100), 100));
        self::assertTrue(OutputExcerpt::isCut(str_repeat('a', 101), 100));
        self::assertFalse(OutputExcerpt::isCut("\n\n".str_repeat('a', 100)."\n\n", 100));
    }

    public function testIsCutUsesTheDefaultLimit(): void
    {
        self::assertFalse(OutputExcerpt::isCut(str_repeat('a', 8000)));
        self::assertTrue(OutputExcerpt::isCut(str_repeat('a', 8001)));
    }

    public function testTheDefaultLimitIsEightThousandBytes(): void
    {
        self::assertSame(str_repeat('a', 8000), OutputExcerpt::of(str_repeat('a', 8000)));
        self::assertStringContainsString('bytes left out', OutputExcerpt::of(str_repeat('a', 8001)));
    }

    public function testInvalidUtf8DoesNotBreakTheCleaning(): void
    {
        self::assertSame("ok \xff\n  indented", OutputExcerpt::of("ok \xff\n      indented"));
    }

    public function testTheHeadIsNotCutThroughAMultibyteCharacter(): void
    {
        $excerpt = OutputExcerpt::of(str_repeat('é', 200), null, 100);

        self::assertSame(str_repeat('é', 12)."\n[… 300 bytes left out …]\n".str_repeat('é', 38), $excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
    }

    public function testKeepsLinesThatOnlyContainAProgressBarCharacter(): void
    {
        self::assertSame("grade ▓ done\n█ block", OutputExcerpt::of("grade ▓ done\n█ block"));
    }

    public function testKeepsLinesWithTextAroundADashRun(): void
    {
        self::assertSame("text -----\n----- section\na=====\n──", OutputExcerpt::of("text -----\n----- section\na=====\n──"));
    }
}
