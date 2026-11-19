<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Formatter;

/**
 * the part of a process output that is returned to the agent: cleaned of what only takes room, and cut in the
 * middle when it is still too long, because the first error and the last lines are both what matters.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class OutputExcerpt
{
    public const int LIMIT = 8000;

    /**
     * @param string|null $file where the full output is kept, named in the note about what was left out
     */
    public static function of(string $output, ?string $file = null, int $limit = self::LIMIT): string
    {
        $text = self::clean($output);

        if (\strlen($text) <= $limit) {
            return $text;
        }

        $head = mb_strcut($text, 0, intdiv($limit, 4));
        $tail = mb_strcut($text, \strlen($text) - ($limit - \strlen($head)));

        return $head.\sprintf("\n[… %d bytes left out%s …]\n", \strlen($text) - \strlen($head) - \strlen($tail), null === $file ? '' : '; full output: '.$file).$tail;
    }

    /**
     * whether `of()` leaves something out, so the full output is worth keeping.
     */
    public static function isCut(string $output, int $limit = self::LIMIT): bool
    {
        return \strlen(self::clean($output)) > $limit;
    }

    /**
     * drops colors, progress bars, rulers and blank lines, and the padding of tables.
     */
    private static function clean(string $output): string
    {
        $output = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $output) ?? $output;
        $lines = [];

        foreach (explode("\n", $output) as $line) {
            $line = rtrim($line);

            if ('' === $line || 1 === preg_match('/[▓░█]{3,}/u', $line) || 1 === preg_match('/^\s*[-─=]{5,}$/u', $line)) {
                continue;
            }

            $lines[] = preg_replace(['/^\s{3,}/', '/(?<=\S) {2,}/'], ['  ', ' '], $line) ?? $line;
        }

        return implode("\n", $lines);
    }
}
