<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Enum;

use WickedOne\InfectionExtension\Parser\MutantResult;

/**
 * the usual way to write the test that kills an escaped mutant of a mutator, for the ones without an equivalence note.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
enum KillHintEnum: string
{
    case Integer = 'To kill it, assert the exact integer: for a default argument call without it and assert the resulting value, for a boundary test the values on both sides.';
    case Bitwise = 'To kill it, assert output that depends on every combined flag or bit, not only that the call succeeds.';
    case Concat = 'To kill it, assert the exact resulting string, every part in order, not only that it is non-empty or has a prefix.';
    case Finally = 'To kill it, make the guarded code fail so the finally block runs and observe what it does (a cancel, close or release) through a collaborator you control, such as a recording subclass or stub.';
    case Filter = 'To kill it, pass an input the filter would drop (null, empty or unknown) and assert the exact value that is passed on, for example through a stub that captures the argument.';
    case Comparison = 'To kill it, test both sides of the comparison: an input that satisfies it and one that does not (for an empty array check, an empty and a non-empty array).';
    case Logical = 'To kill it, cover each operand on its own: a case where only the first holds and a case where only the other holds.';
    case ArrayItem = 'To kill it, assert the whole array with assertSame, so a missing item fails, instead of reading single keys.';
    case Trim = 'To kill it, pass input the function changes (leading or trailing whitespace or characters) and assert the result.';
    case Boolean = 'This flips a plain boolean (a default, argument, flag or return value), not an array value: assert the behaviour that depends on it, calling without the argument for a default.';

    private const string LOGICAL_PREFIX = 'Logical';

    /**
     * the Logical mutators are matched by prefix, TrueValue and FalseValue get theirs from the shape of the diff.
     */
    public static function forMutant(MutantResult $mutant): ?self
    {
        if (str_starts_with($mutant->mutator, self::LOGICAL_PREFIX)) {
            return self::Logical;
        }

        return match ($mutant->mutator) {
            'TrueValue', 'FalseValue' => $mutant->touchesArrayItem() ? null : self::Boolean,
            'IncrementInteger', 'DecrementInteger' => self::Integer,
            'BitwiseOr', 'BitwiseAnd' => self::Bitwise,
            'Concat', 'ConcatOperandRemoval' => self::Concat,
            'UnwrapFinally' => self::Finally,
            'UnwrapArrayFilter' => self::Filter,
            'Identical', 'NotIdentical', 'Equal', 'NotEqual' => self::Comparison,
            'ArrayItemRemoval' => self::ArrayItem,
            'UnwrapTrim', 'UnwrapLtrim', 'UnwrapRtrim' => self::Trim,
            default => null,
        };
    }
}
