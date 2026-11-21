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
 * the usual way to write the test that kills an escaped mutant of a mutator, for the ones without an equivalence note;
 * mutators that mutate alike share a case.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
enum RemedyEnum: string
{
    case Arithmetic = 'Use operands for which the operators give different results (a second operand other than 0, a factor other than 1) and assert the exact value.';
    case Assignment = 'Start from a value that makes the operator matter (not the initial or neutral one) and assert the value after the assignment.';
    case Counter = 'Assert the value after the increment or decrement, or what the loop or counter consumer sees, not only that the code ran.';
    case Rounding = 'Pass fractional values where round, floor and ceil differ (x.2, x.5, x.8, negatives) and assert the exact result.';
    case Bitwise = 'Assert output that depends on every combined flag or bit, not only that the call succeeds.';
    case Integer = 'Assert the exact integer: for a default argument call without it and assert the resulting value, for a boundary test the values on both sides.';
    case Float = 'Assert the exact float, not only that it is truthy, for the 0.0 or 1.0 the code returns.';
    case Negation = 'Assert the exact signed value with a non-zero input, not only its magnitude.';
    case Boundary = 'Test the boundary itself: the limit value, one below and one above it, and assert each outcome.';
    case Comparison = 'Test both sides of the comparison: an input that satisfies it and one that does not (for an empty array check, an empty and a non-empty array).';
    case LooseStrict = 'Compare values that are loosely but not strictly equal (the string 1 and the integer 1, 0 and an empty string, null and false) and assert the outcome.';
    case Logical = 'Cover each operand on its own: a case where only the first holds and a case where only the other holds.';
    case Boolean = 'This flips a plain boolean (a default, argument, flag or return value), not an array value: assert the behaviour that depends on it, calling without the argument for a default.';
    case InstanceOf = 'Pass one object that is an instance and one that is not, and assert a different outcome for each.';
    case ArrayPredicate = 'Pass an array where only some items satisfy the callback, so any and all differ, and assert the outcome.';
    case ArrayItem = 'Assert the whole array with assertSame, so a missing item fails, instead of reading single keys.';
    case Generator = 'Collect the generator with keys (iterator_to_array) and assert both keys and values, not only the values.';
    case Branch = 'Cover both outcomes of the condition and assert a different result for each.';
    case Coalesce = 'Cover the left side null and non-null, with different values on each side, and assert which one is used.';
    case NullSafe = 'Pass null for the receiver and assert the result is null (or that no error is raised).';
    case Spread = 'Pass a collection with several items and assert the whole resulting array, keys included.';
    case Catch = 'Make the guarded code throw that exception type and assert what the catch does (return value, logging, rethrow); do the same for each type of a multi-catch.';
    case Throw = 'Assert the exception is thrown (expectException with its message), not only that the code stops.';
    case Finally = 'Make the guarded code fail so the finally block runs and observe what it does (a cancel, close or release) through a collaborator you control, such as a recording subclass or stub.';
    case Loop = 'Run the loop with at least one iteration and assert the effect of the body; for empty input assert that nothing runs.';
    case LoopJump = 'Pass input with items after the break or continue point and assert which items are, and are not, processed.';
    case Nullify = 'Call with an array that holds a match and assert the found value or key, which is null once mutated.';
    case CallRemoval = 'Observe the side effect of the call through a collaborator you control (a mock expecting it once, a recording stub) or the state it changes.';
    case Clone = 'Change the clone (or the original) and assert the other one is not affected.';
    case MatchArm = 'Call with an input that selects each arm or case, including the default, and assert the result of each.';
    case ReturnValue = 'Assert the exact returned value or object (assertSame, assertInstanceOf), not only that the call succeeds.';
    case FluentReturn = 'Assert the method returns the object itself (assertSame) or chain a second call on the result.';
    case Order = 'Compare or sort inputs in both orders and assert the order of the result.';
    case RegexQuote = 'Pass a pattern with regex metacharacters (. * ( ) ? +) and assert it matches literally.';
    case RegexMatches = 'Assert the captured groups ($matches) after the match, not only that it matched.';
    case RegexAnchor = 'Match input with extra text before or after the pattern that the anchor must reject, and assert it does not match.';
    case RegexFlag = 'Match input that only matches with the flag (case, multiline, dotall, unicode) and input that must not.';
    case Visibility = 'Call the method from a context the lower visibility forbids (another class, a subclass for protected) and assert it works.';
    case Multibyte = 'Pass multibyte input (é, 日本, emoji) where byte and character results differ and assert the result.';
    case BigNumber = 'Use numbers with decimals or beyond float precision and assert the exact string result and scale.';
    case Concat = 'Assert the exact resulting string, every part in order, not only that it is non-empty or has a prefix.';
    case Trim = 'Pass input the function changes (leading or trailing whitespace or characters) and assert the result.';
    case StringTransform = 'Pass a string the function changes (mixed case, characters to replace, length beyond the cut) and assert the exact result.';
    case SetOperation = 'Pass arrays where the other arrays remove or keep items (keys and values that differ) and assert the exact result.';
    case Merge = 'Pass overlapping arrays (same keys, nested values) and assert the whole merged result, keys and order.';
    case ArrayTransform = 'Pass an array the function changes (several items, keys that matter) and assert the exact result: unwrapped, the argument comes back unchanged.';
    case Filter = 'Pass an input the filter would drop (null, empty or unknown) and assert the exact value that is passed on, for example through a stub that captures the argument.';

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
            'Plus', 'Minus', 'Multiplication', 'Division', 'Modulus', 'Exponentiation',
            'PlusEqual', 'MinusEqual', 'MulEqual', 'DivEqual', 'ModEqual', 'PowEqual' => self::Arithmetic,
            'Assignment', 'AssignmentEqual', 'AssignCoalesce' => self::Assignment,
            'Increment', 'Decrement' => self::Counter,
            'RoundingFamily' => self::Rounding,
            'BitwiseOr', 'BitwiseAnd', 'BitwiseXor', 'BitwiseNot', 'ShiftLeft', 'ShiftRight' => self::Bitwise,
            'IncrementInteger', 'DecrementInteger' => self::Integer,
            'OneZeroFloat' => self::Float,
            'IntegerNegation', 'FloatNegation' => self::Negation,
            'GreaterThan', 'GreaterThanOrEqualTo', 'LessThan', 'LessThanOrEqualTo' => self::Boundary,
            'Identical', 'NotIdentical', 'Equal', 'NotEqual',
            'GreaterThanNegotiation', 'GreaterThanOrEqualToNegotiation', 'LessThanNegotiation', 'LessThanOrEqualToNegotiation' => self::Comparison,
            'EqualIdentical', 'NotEqualNotIdentical', 'NotIdenticalNotEqual' => self::LooseStrict,
            'InstanceOf_' => self::InstanceOf,
            'ArrayAll', 'ArrayAny' => self::ArrayPredicate,
            'ArrayItemRemoval', 'ArrayItem', 'ArrayOneItem' => self::ArrayItem,
            'Yield_', 'YieldValue' => self::Generator,
            'IfNegation', 'ElseIfNegation', 'Ternary' => self::Branch,
            'Coalesce' => self::Coalesce,
            'NullSafeMethodCall', 'NullSafePropertyCall' => self::NullSafe,
            'SpreadAssignment', 'SpreadOneItem', 'SpreadRemoval' => self::Spread,
            'Catch_', 'CatchBlockRemoval' => self::Catch,
            'Throw_' => self::Throw,
            'UnwrapFinally', 'Finally_' => self::Finally,
            'DoWhile', 'For_', 'Foreach_', 'While_' => self::Loop,
            'Break_', 'Continue_' => self::LoopJump,
            'ArrayFind', 'ArrayFindKey', 'ArrayFirst', 'ArrayLast' => self::Nullify,
            'FunctionCallRemoval', 'MethodCallRemoval' => self::CallRemoval,
            'CloneRemoval' => self::Clone,
            'MatchArmRemoval', 'SharedCaseRemoval' => self::MatchArm,
            'ReturnRemoval', 'FunctionCall', 'NewObject' => self::ReturnValue,
            'This' => self::FluentReturn,
            'Spaceship' => self::Order,
            'PregQuote' => self::RegexQuote,
            'PregMatchMatches' => self::RegexMatches,
            'PregMatchRemoveCaret', 'PregMatchRemoveDollar' => self::RegexAnchor,
            'PregMatchRemoveFlags' => self::RegexFlag,
            'PublicVisibility', 'ProtectedVisibility' => self::Visibility,
            'MBString' => self::Multibyte,
            'BCMath' => self::BigNumber,
            'Concat', 'ConcatOperandRemoval' => self::Concat,
            'UnwrapTrim', 'UnwrapLtrim', 'UnwrapRtrim' => self::Trim,
            'UnwrapLcFirst', 'UnwrapUcFirst', 'UnwrapUcWords', 'UnwrapStrToLower', 'UnwrapStrToUpper', 'UnwrapStrReplace',
            'UnwrapStrIreplace', 'UnwrapStrRepeat', 'UnwrapStrRev', 'UnwrapStrShuffle', 'UnwrapSubstr' => self::StringTransform,
            'UnwrapArrayDiff', 'UnwrapArrayDiffAssoc', 'UnwrapArrayDiffKey', 'UnwrapArrayDiffUassoc', 'UnwrapArrayDiffUkey',
            'UnwrapArrayIntersect', 'UnwrapArrayIntersectAssoc', 'UnwrapArrayIntersectKey', 'UnwrapArrayIntersectUassoc', 'UnwrapArrayIntersectUkey',
            'UnwrapArrayUdiff', 'UnwrapArrayUdiffAssoc', 'UnwrapArrayUdiffUassoc',
            'UnwrapArrayUintersect', 'UnwrapArrayUintersectAssoc', 'UnwrapArrayUintersectUassoc' => self::SetOperation,
            'UnwrapArrayMerge', 'UnwrapArrayMergeRecursive', 'UnwrapArrayReplace', 'UnwrapArrayReplaceRecursive' => self::Merge,
            'UnwrapArrayChangeKeyCase', 'UnwrapArrayChunk', 'UnwrapArrayColumn', 'UnwrapArrayCombine', 'UnwrapArrayFlip', 'UnwrapArrayKeys',
            'UnwrapArrayMap', 'UnwrapArrayPad', 'UnwrapArrayReduce', 'UnwrapArrayReverse', 'UnwrapArraySlice', 'UnwrapArraySplice', 'UnwrapArrayUnique' => self::ArrayTransform,
            'UnwrapArrayFilter' => self::Filter,
            default => null,
        };
    }
}
