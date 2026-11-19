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
 * what to check before writing a test for an escaped mutant that is often equivalent or hidden by the test input.
 *
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
enum EquivalenceNoteEnum: string
{
    case KeysOnly = 'Often equivalent when this array is only used for its keys (array_diff_key, array_key_exists, isset: isset() is true for false). Not a verdict: check how the values are consumed, and write a test if any behaviour differs.';
    case KeysUnobserved = 'Often equivalent when the keys of the result are never read. Not a verdict: check whether anything observes them, and write a test if any behaviour differs.';
    case TypeKnown = 'Often equivalent when the value already has this type at this point. Not a verdict: check where it comes from, and write a test if any behaviour differs.';
    case Traversable = 'On an instanceof Traversable check the mutant is usually not equivalent but hidden by the test input: ArrayIterator also implements ArrayAccess and iterator_to_array() accepts arrays (PHP 8.2+). Pass a Generator or an IteratorAggregate that is not ArrayAccess.';

    private const string CAST_PREFIX = 'Cast';

    /**
     * the Cast mutators share one note and are matched by prefix, so the ones a newer infection adds are covered too.
     */
    public static function forMutant(MutantResult $mutant): ?self
    {
        if (str_starts_with($mutant->mutator, self::CAST_PREFIX)) {
            return self::TypeKnown;
        }

        return match ($mutant->mutator) {
            // the keys note is about a boolean array value, not a plain boolean
            'TrueValue', 'FalseValue' => $mutant->touchesArrayItem() ? self::KeysOnly : null,
            'UnwrapArrayValues' => self::KeysUnobserved,
            // these mutators mutate many things, the note only fits a Traversable check
            'InstanceOf_', 'Ternary' => str_contains($mutant->diff, 'Traversable') ? self::Traversable : null,
            default => null,
        };
    }
}
